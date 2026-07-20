<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Prevent direct URL access — must come through index.php portal flow
if (!isset($_SESSION['entry_allowed']) || $_SESSION['selected_role'] !== 'student') {
    header('Location: /studentfeedbackucsh/index.php');
    exit;
}

requireRole('student');

updateAllFeedbackStatuses($conn);

$user      = getCurrentUser();
$stmt      = $conn->prepare("SELECT st.id FROM students st WHERE st.user_id=?");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$student   = $stmt->get_result()->fetch_assoc(); $stmt->close();
$studentId = $student['id'] ?? 0;
$studentYearIds = getStudentAcademicYearIds($conn, $studentId);

if (!$studentId) { 
    setFlash('error',$LANG['flash_student_profile_missing'] ?? 'Student profile not found.'); 
    header('Location: index.php'); 
    exit; 
}

$formId = (int)($_GET['form_id'] ?? 0);

if (!$formId) {
    $activeFormQuery = $conn->query("SELECT id FROM feedback_forms WHERE module='student_affairs' ORDER BY id DESC LIMIT 1");
    $activeFormRes = $activeFormQuery->fetch_assoc();
    $formId = $activeFormRes['id'] ?? 0;
}

if (!$formId) { 
    setFlash('error',$LANG['flash_no_sa_form_available'] ?? 'No Student Affairs form available right now.'); 
    header('Location: /studentfeedbackucsh/student/dashboard.php'); 
    exit; 
}

// ─── Load form ──────────────────────────────────────────────
$fs = $conn->prepare("SELECT ff.*, sm.semester_name AS semester, ay.year_name AS academic_year_name FROM feedback_forms ff LEFT JOIN semesters sm ON ff.semester_id=sm.id LEFT JOIN academic_years ay ON ff.academic_year_id=ay.id WHERE ff.id=? AND module='student_affairs'");
$fs->bind_param('i', $formId); $fs->execute();
$form = $fs->get_result()->fetch_assoc(); $fs->close();

if (!$form) { 
    setFlash('error',$LANG['flash_sa_form_not_found'] ?? 'SA Form not found.'); 
    header('Location: /studentfeedbackucsh/student/dashboard.php'); 
    exit; 
}

// Verify form belongs to student's academic year
if (empty($studentYearIds) || !in_array((int)$form['academic_year_id'], $studentYearIds)) {
    setFlash('error', $LANG['flash_form_not_available'] ?? 'This feedback form is not available for your academic year.');
    header('Location: /studentfeedbackucsh/student/sa_feedback.php');
    exit;
}

// ─── Check already submitted ──────────────────────────────────
$sub = $conn->prepare("SELECT id FROM feedback_submissions WHERE form_id=? AND student_id=?");
$sub->bind_param('ii', $formId, $studentId); $sub->execute();
$alreadySubmitted = (bool)$sub->get_result()->num_rows; $sub->close();

$now       = date('Y-m-d H:i:s');
$today     = date('Y-m-d');
$status    = $form['status'];
$isActive  = ($status === 'Active');
$canSubmit = $isActive && !$alreadySubmitted;

$statusNote = '';
if ($alreadySubmitted)     $statusNote = 'already_submitted';
elseif ($status === 'Upcoming') $statusNote = 'not_started';
elseif ($status === 'Expired')  $statusNote = 'expired';

// ─── Load Questions (by question_set_id) ──
$allQuestions = [];
$questionSetId = $form['question_set_id'] ?? null;
if ($questionSetId) {
    $qStmt = $conn->prepare("SELECT * FROM feedback_questions WHERE question_set_id=? ORDER BY question_no ASC");
    $qStmt->bind_param('i', $questionSetId);
    $qStmt->execute();
    $allQuestions = $qStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $qStmt->close();
}

$ratingQuestions = [];
$commentQuestions = [];
$surveyQuestions = [];
foreach ($allQuestions as $q) {
    if ($q['question_type'] === 'rating') { $ratingQuestions[] = $q; }
    elseif ($q['question_type'] === 'survey') { $surveyQuestions[] = $q; }
    else { $commentQuestions[] = $q; }
}

// ─── Handle POST Submission ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    if (!$canSubmit) {
        setFlash('error',$LANG['flash_form_not_open'] ?? 'This form is not open for submission.');
    } else {
        $hasError = false;
        $commentRequired = false;
        foreach ($ratingQuestions as $q) {
            $rating = $_POST['rating_' . $q['id']] ?? '';
            if (!in_array($rating, ['Good', 'Fair', 'Bad'])) {
                $hasError = true;
                break;
            }
            if ($rating !== 'Good') {
                $commentRequired = true;
            }
        }

        $commentError = false;
        if (!$hasError && $commentRequired) {
            $hasComment = false;
            foreach ($commentQuestions as $q) {
                $comment = trim($_POST['comment_' . $q['id']] ?? '');
                if ($comment !== '') {
                    $hasComment = true;
                    break;
                }
            }
            if (!$hasComment) {
                $commentError = true;
                $hasError = true;
            }
        }

        if ($hasError) {
            if ($commentError) {
                setFlash('error', $LANG['flash_comment_required'] ?? 'Please provide a comment because you selected a rating below the highest.');
            } else {
                setFlash('error',$LANG['flash_fill_all_eval'] ?? 'Please fill all evaluation questions and comments.');
            }
        } else {
            $conn->begin_transaction();
            try {
                $recheck = $conn->prepare("SELECT id FROM feedback_submissions WHERE form_id=? AND student_id=? FOR UPDATE");
                $recheck->bind_param('ii', $formId, $studentId); $recheck->execute();
                if ($recheck->get_result()->num_rows > 0) {
                    $recheck->close(); $conn->rollback();
                    setFlash('error', $LANG['flash_already_submitted'] ?? 'You have already submitted this form.');
                    header('Location: sa_feedback.php'); exit;
                }
                $recheck->close();

                $ins = $conn->prepare("INSERT INTO feedback_submissions (form_id, student_id) VALUES (?,?)");
                $ins->bind_param('ii', $formId, $studentId); $ins->execute();
                if ($ins->affected_rows === 0) {
                    $ins->close(); $conn->rollback();
                    setFlash('error', $LANG['flash_already_submitted'] ?? 'You have already submitted this form.');
                    header('Location: sa_feedback.php'); exit;
                }
                $ins->close();
                $submissionId = $conn->insert_id;

                foreach ($ratingQuestions as $q) {
                    $rating = $_POST['rating_' . $q['id']] ?? '';
                    if ($rating) {
                        $ri = $conn->prepare("INSERT INTO feedback_ratings (form_id, question_id, rating) VALUES (?,?,?)");
                        $ri->bind_param('iis', $formId, $q['id'], $rating); $ri->execute(); $ri->close();
                    }
                }

                foreach ($commentQuestions as $q) {
                    $comment = trim($_POST['comment_' . $q['id']] ?? '');
                    if ($comment !== '') {
                        $ci = $conn->prepare("INSERT INTO feedback_comments (form_id, question_id, comment_text) VALUES (?,?,?)");
                        $ci->bind_param('iis', $formId, $q['id'], $comment); $ci->execute(); $ci->close();
                    }
                }

                foreach ($surveyQuestions as $q) {
                    $selectedOptions = $_POST['survey'][$q['id']] ?? [];
                    if (!is_array($selectedOptions)) $selectedOptions = [$selectedOptions];
                    foreach ($selectedOptions as $sel) {
                        $selected = (int)$sel;
                        if ($selected >= 0 && $selected <= 3) {
                            $si = $conn->prepare("INSERT INTO feedback_survey_answers (submission_id, question_id, selected_option_index) VALUES (?,?,?)");
                            $si->bind_param('iii', $submissionId, $q['id'], $selected); $si->execute(); $si->close();
                        }
                    }
                }

                $conn->commit();
                setFlash('success', $LANG['flash_thank_you_participation'] ?? '🎉 Thank you for participating.');
                header('Location: sa_feedback.php'); exit;
            } catch (Exception $e) {
                $conn->rollback();
                setFlash('error',$LANG['flash_submission_failed_try'] ?? 'Submission failed. Please try again.');
            }
        }
    }
}

$pageTitle = $LANG['sa_satisfaction_form'] ?? 'Student Affairs Satisfaction Form';
$initials = avatarInitials($user['name']);
?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={theme:{extend:{fontFamily:{sans:['Pyidaungsu','Inter','sans-serif']}}}}</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @import url('https://cdn.jsdelivr.net/css-myanmar-fonts/v1/pyidaungsu.css');
        body { font-family: 'Pyidaungsu', 'Inter', sans-serif; }
        body.lang-mm th { font-size: 0.8125rem; line-height: 1.6; }
        body.lang-mm td { font-size: 0.8125rem; line-height: 1.6; }
    </style>
</head>
<body class="h-full bg-slate-50 <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
<div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>

<div class="flex h-screen overflow-hidden">
    <aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
        <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center font-bold text-white">S</div>
            <div>
                    <p class="text-sm font-bold"><?= $LANG['student_portal'] ?? 'SFMS Student' ?></p>
                    <p class="text-[10px] text-cyan-100"><?= $LANG['student_portal_sub'] ?? 'Student Portal' ?></p>
                </div>
            <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-200 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <nav class="flex-1 py-4 px-3 space-y-0.5">
            <a href="/studentfeedbackucsh/student/dashboard.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('home','w-4 h-4 flex-shrink-0 text-yellow-300') ?> <?= $LANG['nav_dashboard'] ?? 'Dashboard' ?></a>
            <a href="/studentfeedbackucsh/student/my_sections.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('grid','w-4 h-4 flex-shrink-0 text-blue-300') ?> <?= $LANG['nav_my_sections'] ?? 'My Sections' ?></a>
            <a href="/studentfeedbackucsh/student/sa_feedback.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm bg-white/20 text-white font-semibold"><?= iconSvg('shield','w-4 h-4 flex-shrink-0 text-purple-300') ?> <?= $LANG['nav_student_affairs_link'] ?? 'Student Affairs' ?></a>
            <a href="/studentfeedbackucsh/student/adm_feedback.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('office','w-4 h-4 flex-shrink-0 text-orange-300') ?> <?= $LANG['nav_administration'] ?? 'Administration' ?></a>
            <a href="/studentfeedbackucsh/student/feedback_history.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('history','w-4 h-4 flex-shrink-0 text-teal-300') ?> <?= $LANG['nav_history'] ?? 'History' ?></a>
            <a href="/studentfeedbackucsh/student/profile.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('user','w-4 h-4 flex-shrink-0 text-rose-300') ?> <?= $LANG['nav_profile'] ?? 'Profile' ?></a>
        </nav>
       <a href="/studentfeedbackucsh/auth/logout.php" title="<?= $LANG['logout'] ?? 'Logout' ?>"
                class="block border-t border-white/15 bg-red-500/80 text-gray-50 hover:text-gray-200 transition-colors px-4 py-4 cursor-pointer">
                <div class="flex items-center justify-center gap-3">

                    <div class="min-w-0 ">
                        <p class="text-xl h-8"><?= $LANG['logout'] ?? 'Logout' ?></p>
                    </div>
                    <?= iconSvg('logout', 'w-6 h-6') ?>
                </div>
            </a>
    </aside>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header class="bg-white border-b border-slate-200 px-6 py-4 flex items-center gap-4 shadow-sm z-20">
            <button onclick="openSidebar()" class="lg:hidden text-slate-500"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg></button>
            <h1 class="text-base font-bold text-slate-800"><?= $LANG['sa_feedback_page_title'] ?? 'Student Affairs Satisfaction Form' ?></h1>
            <a href="sa_feedback.php" class="ml-auto text-sm font-medium text-cyan-600 hover:underline">← <?= $LANG['back'] ?? 'Back' ?></a>
        </header>

        <main class="flex-1 overflow-y-auto p-4 md:p-8 mx-auto w-full">
            <?php renderFlash() ?>

            <div class="bg-white shadow-xl rounded-xl border border-slate-200 p-6 md:p-10 mb-8">
                
                <div class="text-center pb-4 mb-6 border-b border-slate-100">
                    
                    <h2 class="text-lg md:text-xl font-bold text-slate-950 mb-1"><?= e($form['university_name'] ?? $LANG['university_name'] ?? 'University of Computer Studies (Hinthada)') ?></h2>
                    <p class="text-md font-black text-slate-900 mb-1"><?= $LANG['academic_year_label'] ?? 'Academic Year' ?>: <?= e($form['academic_year'] ?? $form['academic_year_name'] ?? '') ?></p>
                    <p class="text-md font-black text-slate-900 mt-1 tracking-wider"><?= e($form['university_campus'] ?? $LANG['university_campus'] ?? 'University Campus') ?></p>
                    <h3 class="text-md font-black text-slate-900 mt-1"><?= e($form['title']) ?></h3>
                    <p class="text-xs text-slate-500 mt-1"><?= $LANG['feedback_period'] ?? 'Feedback Period' ?>: <?= formatDateTime($form['start_date']) ?> — <?= formatDateTime($form['end_date']) ?></p>
                    <p class="text-xs mt-1"><?= badgeStatus($status) ?> <?php if ($status === 'Active'): ?><span class="text-slate-500"><?= getTimeRemaining($form['end_date']) ?></span><?php elseif ($status === 'Upcoming'): ?><span class="text-slate-500"><?= getTimeUntilStart($form['start_date']) ?></span><?php endif ?></p>
                </div>

                <!-- <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6">
                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Form Information</h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">Academic Year</p>
                            <p class="text-sm font-bold text-slate-800"><?= e($form['academic_year'] ?? $form['academic_year_name'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">Semester</p>
                            <p class="text-sm font-bold text-slate-800"><?= e(semesterToRoman($form['semester'] ?? '')) ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">Form Type</p>
                            <p class="text-sm font-bold text-slate-800"><?= moduleBadge($form['module'] ?? '') ?></p>
                        </div>
                    </div>
                </div> -->

                <?php if ($alreadySubmitted): ?>
                <div class="bg-green-50 border border-green-200 text-green-800 p-4 rounded-xl mb-6 text-sm font-semibold">
                    ✓ <?= $LANG['thank_you_participating'] ?? 'This form has been submitted. Thank you for your participation.' ?>
                </div>
                <?php elseif ($status === 'Upcoming'): ?>
                <div class="bg-blue-50 border border-blue-200 text-blue-800 p-4 rounded-xl mb-6 text-sm font-semibold">
                    ⏳ <?= $LANG['form_not_available_yet'] ?? 'Feedback form is not available yet. It will open on' ?> <strong><?= formatDateTime($form['start_date']) ?></strong>.
                    <br><span class="text-xs mt-1 block"><?= getTimeUntilStart($form['start_date']) ?></span>
                </div>
                <?php elseif ($status === 'Expired'): ?>
                <div class="bg-slate-100 border border-slate-300 text-slate-700 p-4 rounded-xl mb-6 text-sm font-semibold">
                    🔒 <?= $LANG['form_closed_ended'] ?? 'Feedback form has been closed. It ended on' ?> <strong><?= formatDateTime($form['end_date']) ?></strong>.
                </div>
                <?php endif ?>

                <?php if (!empty($allQuestions)): ?>
                <form method="POST" id="feedback-form" class="space-y-8">
                    <?= csrfField() ?>

                    <?php if (!empty($ratingQuestions)): ?>
                    <div>
                        <div class="overflow-x-auto border border-slate-300 rounded-lg shadow-sm">
                            <table class="w-full text-left border-collapse min-w-[500px]">
                                <thead>
                                    <tr class="bg-slate-200 border-b border-slate-300 text-slate-900 font-bold text-xs">
                                        <th class="p-3 border-r border-slate-300 w-12 text-center"><?= $LANG['col_no_short'] ?? 'No' ?></th>
                                        <th class="p-3 border-r border-slate-300"><?= $LANG['col_activities'] ?? 'Activities' ?></th>
                                        <th class="p-3 border-r border-slate-300 w-24 text-center bg-emerald-200 text-emerald-900"><?= $LANG['good'] ?? 'Good' ?></th>
                                        <th class="p-3 border-r border-slate-300 w-24 text-center bg-amber-200 text-amber-900"><?= $LANG['fair'] ?? 'Fair' ?></th>
                                        <th class="p-3 w-24 text-center bg-red-200 text-red-900"><?= $LANG['bad'] ?? 'Bad' ?></th>
                                    </tr>
                                </thead>
                                <tbody class="text-xs divide-y divide-slate-200 text-slate-800">
                                    <?php foreach ($ratingQuestions as $q): ?>
                                    <tr class="hover:bg-slate-50/50 transition-colors">
                                        <td class="p-3 border-r border-slate-200 text-center font-bold font-mono"><?= e(displayQuestionNumber($q['question_no'], $_SESSION['lang'] ?? 'en')) ?></td>
                                        <td class="p-3 border-r border-slate-200 font-medium leading-relaxed text-slate-900"><?= e($q['question_text']) ?></td>
                                        
                                        <td class="p-3 border-r border-slate-200 text-center bg-emerald-50/30">
                                            <input type="radio" name="rating_<?= $q['id'] ?>" value="Good" required 
                                                   <?= !$canSubmit ? 'disabled' : '' ?>
                                                   class="w-4 h-4 text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                                        </td>
                                        <td class="p-3 border-r border-slate-200 text-center bg-amber-50/30">
                                            <input type="radio" name="rating_<?= $q['id'] ?>" value="Fair" required 
                                                   <?= !$canSubmit ? 'disabled' : '' ?>
                                                   class="w-4 h-4 text-amber-500 focus:ring-amber-500 cursor-pointer">
                                        </td>
                                        <td class="p-3 text-center bg-red-50/30">
                                            <input type="radio" name="rating_<?= $q['id'] ?>" value="Bad" required 
                                                   <?= !$canSubmit ? 'disabled' : '' ?>
                                                   class="w-4 h-4 text-red-600 focus:ring-red-500 cursor-pointer">
                                        </td>
                                    </tr>
                                    <?php endforeach ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif ?>

                    <?php if (!empty($commentQuestions)): ?>
                    <div class="space-y-6 pt-4 border-t-2 border-slate-200">
                        <?php foreach ($commentQuestions as $q): ?>
                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-slate-800 leading-relaxed">
                                (<?= displayQuestionNumber($q['question_no'], $_SESSION['lang'] ?? 'en') ?>) <?= e($q['question_text']) ?>
                            </label>
                            <textarea name="comment_<?= $q['id'] ?>" rows="4"
                                      placeholder="<?= $LANG['comment_placeholder'] ?? 'Write your comment here...' ?>"
                                      disabled
                                      class="comment-textarea w-full border border-slate-300 bg-slate-50 rounded-xl px-4 py-3 text-xs focus:bg-white focus:border-slate-900 outline-none resize-none transition-all disabled:opacity-60"></textarea>
                        </div>
                        <?php endforeach ?>
                        <p id="comment-error" class="text-xs text-red-500 hidden mt-2"><?= $LANG['comment_required_hint'] ?? 'Please provide a comment because you selected a rating below the highest.' ?></p>
                    </div>
                    <?php endif ?>

                    <?php if (!empty($surveyQuestions)): ?>
                    <div class="space-y-6 pt-4 border-t-2 border-violet-200">
                        <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider"><?= $LANG['survey_questions_mcq'] ?? 'Survey Questions (MCQ)' ?></h3>
                        <?php foreach ($surveyQuestions as $q):
                            $opts = json_decode($q['options_json'] ?? '[]', true) ?: [];
                            ?>
                            <div class="space-y-2 survey-group" data-question-id="<?= $q['id'] ?>">
                                <label class="block text-xs font-bold text-slate-800 leading-relaxed">
                                    (<?= displayQuestionNumber($q['question_no'], $_SESSION['lang'] ?? 'en') ?>) <?= e($q['question_text']) ?>
                                    <span class="text-red-500 text-[10px] font-normal">*</span>
                                </label>
                                <div class="space-y-2">
                                    <?php foreach ($opts as $idx => $opt): ?>
                                    <label class="flex items-center gap-3 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 cursor-pointer hover:bg-violet-50 transition-colors <?= !$canSubmit ? 'opacity-60 pointer-events-none' : '' ?>">
                                        <input type="checkbox" name="survey[<?= $q['id'] ?>][]" value="<?= $idx ?>"
                                               <?= !$canSubmit ? 'disabled' : '' ?>
                                               class="w-4 h-4 text-violet-600 focus:ring-violet-500 rounded cursor-pointer survey-cb">
                                        <span class="text-xs text-slate-700 font-medium"><?= e($opt) ?></span>
                                    </label>
                                    <?php endforeach ?>
                                </div>
                                <p class="text-xs text-red-500 hidden survey-error"><?= $LANG['survey_select_one'] ?? 'Please select at least one option.' ?></p>
                            </div>
                        <?php endforeach ?>
                    </div>
                    <?php endif ?>

                    <div class="pt-8 border-t border-slate-200 flex items-center justify-end gap-3">
                        <a href="index.php" class="px-5 py-2 text-xs font-semibold text-white bg-red-500 hover:bg-red-700 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></a>
                        
                        <?php if ($canSubmit): ?>
                        <button type="submit" id="submit-btn"
                                class="w-full md:w-auto px-8 py-2.5 text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow-md transition-all">
                            <?= $LANG['submit_form'] ?? 'Submit Form' ?>
                        </button>
                        <?php elseif ($status === 'Upcoming'): ?>
                        <button type="button" disabled class="w-full md:w-auto px-8 py-2.5 text-sm font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed">
                            <?= $LANG['not_yet_available'] ?? 'Not Yet Available' ?>
                        </button>
                        <?php elseif ($status === 'Expired'): ?>
                        <button type="button" disabled class="w-full md:w-auto px-8 py-2.5 text-sm font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed">
                            <?= $LANG['form_closed'] ?? 'Feedback Closed' ?>
                        </button>
                        <?php else: ?>
                        <button type="button" disabled class="w-full md:w-auto px-8 py-2.5 text-sm font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed">
                            <?= $LANG['submissions_locked'] ?? 'Submissions Locked' ?>
                        </button>
                        <?php endif ?>
                    </div>
                </form>
                <?php else: ?>
                <div class="text-center py-12 text-slate-400 text-sm">
                    <p><?= $LANG['no_questions_in_form'] ?? 'No questions in this form yet.' ?></p>
                </div>
                <?php endif ?>
            </div>
        </main>
    </div>
</div>

<script>
function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('overlay').classList.add('hidden'); }

<?php if ($canSubmit): ?>
var ff = document.getElementById('feedback-form'), btn = document.getElementById('submit-btn');
if (ff && btn) {
    function checkCommentState() {
        var commentRequired = false;
        var checkedRatings = document.querySelectorAll('input[type="radio"][name^="rating_"]:checked');
        for (var r = 0; r < checkedRatings.length; r++) {
            if (checkedRatings[r].value !== 'Good') { commentRequired = true; break; }
        }
        var tas = document.querySelectorAll('.comment-textarea');
        var commentErr = document.getElementById('comment-error');
        if (commentRequired) {
            for (var t = 0; t < tas.length; t++) {
                tas[t].disabled = false;
            }
        } else {
            for (var t = 0; t < tas.length; t++) {
                tas[t].value = '';
                tas[t].disabled = true;
                tas[t].classList.remove('border-red-500', 'bg-red-50');
            }
            if (commentErr) commentErr.classList.add('hidden');
        }
    }

    var ratingRadios = document.querySelectorAll('input[type="radio"][name^="rating_"]');
    for (var r = 0; r < ratingRadios.length; r++) {
        ratingRadios[r].addEventListener('change', checkCommentState);
    }

    checkCommentState();

    ff.addEventListener('submit', function(e) {
        var groups = document.querySelectorAll('.survey-group');
        var valid = true;
        for (var g = 0; g < groups.length; g++) {
            var cbs = groups[g].querySelectorAll('.survey-cb');
            var err = groups[g].querySelector('.survey-error');
            var checked = false;
            for (var c = 0; c < cbs.length; c++) {
                if (cbs[c].checked) { checked = true; break; }
            }
            if (err) err.classList.toggle('hidden', checked);
            if (!checked) valid = false;
        }
        if (!valid) { e.preventDefault(); return; }

        var commentRequired = false;
        var checkedRatings = document.querySelectorAll('input[type="radio"][name^="rating_"]:checked');
        for (var r = 0; r < checkedRatings.length; r++) {
            if (checkedRatings[r].value !== 'Good') { commentRequired = true; break; }
        }
        var commentErr = document.getElementById('comment-error');
        var tas = document.querySelectorAll('.comment-textarea');
        if (commentRequired) {
            var hasComment = false;
            for (var t = 0; t < tas.length; t++) {
                if (tas[t].value.trim() !== '') { hasComment = true; break; }
            }
            if (!hasComment) {
                if (commentErr) commentErr.classList.remove('hidden');
                for (var t = 0; t < tas.length; t++) {
                    tas[t].classList.add('border-red-500', 'bg-red-50');
                }
                e.preventDefault();
                return;
            }
        }
        if (commentErr) commentErr.classList.add('hidden');
        for (var t = 0; t < tas.length; t++) {
            tas[t].classList.remove('border-red-500', 'bg-red-50');
        }

        btn.disabled = true;
        btn.className = "w-full md:w-auto px-8 py-2.5 text-sm font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed";
        btn.innerHTML = '<?= $LANG['please_wait'] ?? 'Please wait...' ?>';
    });
    var tas = document.querySelectorAll('.comment-textarea');
    for (var t = 0; t < tas.length; t++) {
        tas[t].addEventListener('input', function() {
            this.classList.remove('border-red-500', 'bg-red-50');
            var ce = document.getElementById('comment-error');
            if (ce) ce.classList.add('hidden');
        });
    }
}
<?php endif ?>
</script>
</body>
</html>