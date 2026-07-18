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
$stmt      = $conn->prepare("SELECT id FROM students WHERE user_id=?");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$student   = $stmt->get_result()->fetch_assoc(); $stmt->close();
$studentId = $student['id'] ?? 0;

if (!$studentId) {
    setFlash('error', $LANG['flash_student_profile_missing'] ?? 'Student profile not found. Please contact the administrator.');
    header('Location: /studentfeedbackucsh/student/dashboard.php'); exit;
}

$formId = (int)($_GET['form_id'] ?? 0);
if (!$formId) { header('Location: my_sections.php'); exit; }

// ─── Step 1: Check enrollment only
$chk = $conn->prepare(
    "SELECT ff.*, c.course_name, c.course_code, s.section, s.academic_year, sm.semester_name AS semester,
            u.name AS teacher_name
     FROM feedback_forms ff
     JOIN sections s           ON ff.section_id = s.id
     JOIN courses c            ON s.course_id   = c.id
     JOIN teachers t           ON s.teacher_id  = t.id
     JOIN users u              ON t.user_id     = u.id
     JOIN section_assignments sa ON sa.section_id = s.id
     LEFT JOIN semesters sm    ON s.semester_id  = sm.id
     WHERE ff.id = ? AND sa.student_id = ?
     LIMIT 1"
);
$chk->bind_param('ii', $formId, $studentId);
$chk->execute();
$form = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$form) {
    setFlash('error', $LANG['flash_not_enrolled_section'] ?? 'You are not enrolled in the section for this feedback form.');
    header('Location: my_sections.php'); exit;
}

// ─── Step 2: Check if already submitted
$sub = $conn->prepare("SELECT id FROM feedback_submissions WHERE form_id=? AND student_id=?");
$sub->bind_param('ii', $formId, $studentId); $sub->execute();
$alreadySubmitted = (bool)$sub->get_result()->num_rows;
$sub->close();

// ─── Step 3: Determine form open status
$now      = date('Y-m-d H:i:s');
$today    = date('Y-m-d');
$status   = $form['status'];
$isActive = ($status === 'Active');
$canSubmit = $isActive && !$alreadySubmitted;

$statusNote = '';
if ($alreadySubmitted) { $statusNote = 'already_submitted'; } 
elseif ($status === 'Upcoming') { $statusNote = 'not_started'; } 
elseif ($status === 'Expired') { $statusNote = 'expired'; }

// ─── Step 4: Load Questions (from assigned Question Set)
$allQuestions = [];
if (!empty($form['question_set_id'])) {
    $qStmt = $conn->prepare("SELECT * FROM feedback_questions WHERE question_set_id = ? ORDER BY question_no ASC");
    $qStmt->bind_param('i', $form['question_set_id']);
    $qStmt->execute();
    $allQuestions = $qStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $qStmt->close();
}

$ratingQuestions = [];
$commentQuestions = [];
$surveyQuestions = [];
foreach ($allQuestions as $q) {
    if ($q['question_type'] === 'rating') {
        $ratingQuestions[] = $q;
    } elseif ($q['question_type'] === 'survey') {
        $surveyQuestions[] = $q;
    } else {
        $commentQuestions[] = $q;
    }
}

// ─── Step 5: Handle POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    if (!$canSubmit) {
        setFlash('error', $LANG['flash_form_not_open'] ?? 'This form is not open for submission.');
    } else {
        $hasError = false;
        foreach ($ratingQuestions as $q) {
            $rating = $_POST['rating_' . $q['id']] ?? '';
            if (!in_array($rating, ['Good', 'Fair', 'Bad'])) {
                $hasError = true; 
                break;
            }
        }
        // Comment field is optional — no validation required

        if ($hasError) {
            setFlash('error', $LANG['flash_rate_all_questions'] ?? 'Please rate all questions.');
        } else {
            $conn->begin_transaction();
            try {
                $recheck = $conn->prepare("SELECT id FROM feedback_submissions WHERE form_id=? AND student_id=? FOR UPDATE");
                $recheck->bind_param('ii', $formId, $studentId); $recheck->execute();
                if ($recheck->get_result()->num_rows > 0) {
                    $recheck->close(); $conn->rollback();
                    setFlash('error', $LANG['flash_already_submitted'] ?? 'You have already submitted this form.');
                    header('Location: my_sections.php'); exit;
                }
                $recheck->close();

                $ins = $conn->prepare("INSERT INTO feedback_submissions (form_id, student_id) VALUES (?,?)");
                $ins->bind_param('ii', $formId, $studentId); $ins->execute();
                if ($ins->affected_rows === 0) {
                    $ins->close(); $conn->rollback();
                    setFlash('error', $LANG['flash_already_submitted'] ?? 'You have already submitted this form.');
                    header('Location: my_sections.php'); exit;
                }
                $ins->close();
                $submissionId = $conn->insert_id;

                // Save dynamic ratings
                foreach ($ratingQuestions as $q) {
                    $rating = $_POST['rating_' . $q['id']] ?? '';
                    if ($rating) {
                        $ri = $conn->prepare("INSERT INTO feedback_ratings (form_id, question_id, rating) VALUES (?,?,?)");
                        $ri->bind_param('iis', $formId, $q['id'], $rating);
                        $ri->execute(); $ri->close();
                    }
                }

                // Save dynamic comments
                foreach ($commentQuestions as $q) {
                    $comment = trim($_POST['comment_' . $q['id']] ?? '');
                    if ($comment !== '') {
                        $ci = $conn->prepare("INSERT INTO feedback_comments (form_id, question_id, comment_text) VALUES (?,?,?)");
                        $ci->bind_param('iis', $formId, $q['id'], $comment);
                        $ci->execute(); $ci->close();
                    }
                }

                // Save survey answers
                foreach ($surveyQuestions as $q) {
                    $selectedOptions = $_POST['survey'][$q['id']] ?? [];
                    if (!is_array($selectedOptions)) $selectedOptions = [$selectedOptions];
                    foreach ($selectedOptions as $sel) {
                        $selected = (int)$sel;
                        if ($selected >= 0 && $selected <= 3) {
                            $si = $conn->prepare("INSERT INTO feedback_survey_answers (submission_id, question_id, selected_option_index) VALUES (?,?,?)");
                            $si->bind_param('iii', $submissionId, $q['id'], $selected);
                            $si->execute(); $si->close();
                        }
                    }
                }

                $conn->commit();
                
                // Submit လုပ်ပြီးမှသာ Flash သတ်မှတ်ပြီး my_sections.php (စာရင်းစာမျက်နှာ) ဆီသို့ ခေါ်သွားပါမည်
                setFlash('success', $LANG['flash_thank_you_participation'] ?? '🎉 Thank you for participating.');
                header('Location: my_sections.php'); 
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                setFlash('error', ($LANG['flash_submission_failed'] ?? 'Submission failed') . ': ' . $e->getMessage());
            }
        }
    }
}

$initials = avatarInitials($user['name']);
?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $LANG['course_eval_form'] ?? 'Course Evaluation Form' ?> — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: { sans: ['Pyidaungsu', 'Inter', 'sans-serif'] } } }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @import url('https://cdn.jsdelivr.net/css-myanmar-fonts/v1/pyidaungsu.css');
        body { font-family: 'Pyidaungsu', 'Inter', sans-serif; }
        body.lang-mm th { font-size: 0.8125rem; line-height: 1.6; }
        body.lang-mm td { font-size: 0.8125rem; line-height: 1.6; }
    </style>
</head>
<body class="h-full bg-slate-100 <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
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
            <a href="/studentfeedbackucsh/student/dashboard.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('home','w-4 h-4 flex-shrink-0') ?> <?= $LANG['nav_dashboard'] ?? 'Dashboard' ?></a>
            <a href="/studentfeedbackucsh/student/my_sections.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm bg-white/20 text-white font-semibold"><?= iconSvg('grid','w-4 h-4 flex-shrink-0') ?> <?= $LANG['nav_my_sections'] ?? 'My Sections' ?></a>
            <a href="/studentfeedbackucsh/student/sa_feedback.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('shield','w-4 h-4 flex-shrink-0') ?> <?= $LANG['nav_student_affairs_link'] ?? 'Student Affairs' ?></a>
            <a href="/studentfeedbackucsh/student/adm_feedback.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('office','w-4 h-4 flex-shrink-0') ?> <?= $LANG['nav_administration'] ?? 'Administration' ?></a>
            <a href="/studentfeedbackucsh/student/feedback_history.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('history','w-4 h-4 flex-shrink-0') ?> <?= $LANG['nav_history'] ?? 'History' ?></a>
            <a href="/studentfeedbackucsh/student/profile.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('user','w-4 h-4 flex-shrink-0') ?> <?= $LANG['nav_profile'] ?? 'Profile' ?></a>
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
        <header class="bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between shadow-sm z-20">
            <button onclick="openSidebar()" class="lg:hidden text-slate-600 p-1">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="text-lg font-bold text-slate-800"><?= $LANG['evaluation_form_label'] ?? 'Evaluation Form' ?></h1>
            <a href="my_sections.php" class="text-sm font-medium text-emerald-600 hover:underline">← <?= $LANG['back'] ?? 'Back' ?></a>
        </header>

        <main class="flex-1 overflow-y-auto p-4 md:p-8 mx-auto w-full">
            <?php renderFlash() ?>

            <div class="bg-white shadow-xl rounded-xl border border-slate-200 p-6 md:p-10 mb-8">
                <div class="text-center border-b-2 border-slate-800 pb-6 mb-6">
                    <h2 class="text-xl md:text-2xl font-bold text-slate-900 mb-2"><?= e($form['title'] ?? ($LANG['course_eval_form'] ?? 'Course Evaluation Form')) ?></h2>
                    <p class="text-sm text-slate-500 font-mono"><?= $LANG['student_course_feedback'] ?? 'Student Course Feedback Questionnaire' ?></p>
                    <p class="text-xs text-slate-400 mt-1"><?= $LANG['feedback_period'] ?? 'Feedback Period' ?>: <?= formatDateTime($form['start_date']) ?> — <?= formatDateTime($form['end_date']) ?></p>
                    <p class="text-xs mt-1"><?= badgeStatus($status) ?> <?php if ($status === 'Active'): ?><span class="text-slate-500"><?= getTimeRemaining($form['end_date']) ?></span><?php elseif ($status === 'Upcoming'): ?><span class="text-slate-500"><?= getTimeUntilStart($form['start_date']) ?></span><?php endif ?></p>
                </div>

                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6">
                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Form Information</h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">Academic Year</p>
                            <p class="text-sm font-bold text-slate-800"><?= e($form['academic_year'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">Semester</p>
                            <p class="text-sm font-bold text-slate-800"><?= e(semesterToRoman($form['semester'] ?? '')) ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">Course Code</p>
                            <p class="text-sm font-bold text-slate-800"><?= e($form['course_code'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">Course Name</p>
                            <p class="text-sm font-bold text-slate-800"><?= e($form['course_name'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">Section</p>
                            <p class="text-sm font-bold text-slate-800"><?= e($form['section'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">Teacher Name</p>
                            <p class="text-sm font-bold text-slate-800"><?= e($form['teacher_name'] ?? '—') ?></p>
                        </div>
                    </div>
                </div>

                <?php if ($alreadySubmitted): ?>
                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl mb-6 text-sm font-semibold">
                        ✓ <?= $LANG['survey_completed_thanks'] ?? 'Survey completed. Thank you.' ?>
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
                        <h3 class="font-bold text-slate-900 mb-3  underline  py-2 rounded-lg rounded-t-lg">
                            <?= $LANG['overall_evaluation_table'] ?? 'Overall Evaluation Table' ?>
                        </h3>
                        <div class="overflow-x-auto border border-slate-300 rounded-b-lg">
                            <table class="w-full text-left border-collapse min-w-[600px]">
                                <thead>
                                    <tr class="bg-slate-200 border-b border-slate-300 text-slate-800 font-bold text-sm">
                                        <th class="p-3 border-r border-slate-300 w-12 text-center"><?= $LANG['col_no_short'] ?? 'No' ?></th>
                                        <th class="p-3 border-r border-slate-300"><?= $LANG['col_questions'] ?? 'Questions' ?></th>
                                        <th class="p-3 border-r border-slate-300 w-24 text-center bg-emerald-200 text-emerald-900"><?= $LANG['good'] ?? 'Good' ?></th>
                                        <th class="p-3 border-r border-slate-300 w-24 text-center bg-amber-200 text-amber-900"><?= $LANG['fair'] ?? 'Fair' ?></th>
                                        <th class="p-3 w-24 text-center bg-red-200 text-red-900"><?= $LANG['bad'] ?? 'Bad' ?></th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm divide-y divide-slate-200 text-slate-800">
                                    <?php foreach ($ratingQuestions as $q): ?>
                                    <tr class="hover:bg-slate-50 transition-colors">
                                        <td class="p-3 border-r border-slate-200 text-center font-semibold font-mono"><?= e(displayQuestionNumber($q['question_no'], $_SESSION['lang'] ?? 'en')) ?></td>
                                        <td class="p-3 border-r border-slate-200 font-medium"><?= e($q['question_text']) ?></td>
                                        
                                        <td class="p-3 border-r border-slate-200 text-center bg-emerald-50/40">
                                            <input type="radio" name="rating_<?= $q['id'] ?>" value="Good" required 
                                                   <?= !$canSubmit ? 'disabled' : '' ?>
                                                   class="w-4 h-4 text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                                        </td>
                                        <td class="p-3 border-r border-slate-200 text-center bg-amber-50/40">
                                            <input type="radio" name="rating_<?= $q['id'] ?>" value="Fair" required 
                                                   <?= !$canSubmit ? 'disabled' : '' ?>
                                                   class="w-4 h-4 text-amber-500 focus:ring-amber-500 cursor-pointer">
                                        </td>
                                        <td class="p-3 text-center bg-red-50/40">
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
                    <div class="space-y-6 pt-4 border-t-2 border-slate-300">
                        <h3 class="text-base font-bold text-slate-900 mb-2"><?= $LANG['comments_suggestions'] ?? 'Comments & Suggestions' ?></h3>
                        
                        <?php foreach ($commentQuestions as $q): ?>
                        <div class="space-y-2">
                            <label class="block text-sm font-bold text-slate-800">
                                <?= displayQuestionNumber($q['question_no'], $_SESSION['lang'] ?? 'en') ?> <?= e($q['question_text']) ?>
                            </label>
                            <textarea name="comment_<?= $q['id'] ?>" rows="4" 
                                      placeholder="<?= $LANG['comment_placeholder'] ?? 'Write your comment here...' ?>"
                                      <?= !$canSubmit ? 'disabled' : '' ?>
                                      class="w-full border-2 border-slate-300 rounded-xl px-4 py-3 text-sm bg-slate-50 focus:bg-white focus:border-slate-800 outline-none resize-none disabled:opacity-60 transition-all"></textarea>
                        </div>
                        <?php endforeach ?>
                    </div>
                    <?php endif ?>

                    <?php if (!empty($surveyQuestions)): ?>
                    <div class="space-y-6 pt-4 border-t-2 border-violet-200">
                        <h3 class="text-base font-bold text-slate-900 mb-2"><?= $LANG['survey_questions_mcq'] ?? 'Survey Questions (MCQ)' ?></h3>
                        <?php foreach ($surveyQuestions as $q):
                            $opts = json_decode($q['options_json'] ?? '[]', true) ?: [];
                            ?>
                            <div class="space-y-2 survey-group" data-question-id="<?= $q['id'] ?>">
                                <label class="block text-sm font-bold text-slate-800">
                                    <?= displayQuestionNumber($q['question_no'], $_SESSION['lang'] ?? 'en') ?> <?= e($q['question_text']) ?>
                                    <span class="text-red-500 text-xs font-normal">*</span>
                                </label>
                                <div class="space-y-2 px-4">
                                    <?php foreach ($opts as $idx => $opt): ?>
                                    <label class="flex items-center gap-3 bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 cursor-pointer hover:bg-violet-50 transition-colors <?= !$canSubmit ? 'opacity-60 pointer-events-none' : '' ?>">
                                        <input type="checkbox" name="survey[<?= $q['id'] ?>][]" value="<?= $idx ?>"
                                               <?= !$canSubmit ? 'disabled' : '' ?>
                                               class="w-4 h-4 text-violet-600 focus:ring-violet-500 rounded cursor-pointer survey-cb">
                                        <span class="text-sm text-slate-700 font-medium"><?= e($opt) ?></span>
                                    </label>
                                    <?php endforeach ?>
                                </div>
                                <p class="text-xs text-red-500 hidden survey-error"><?= $LANG['survey_select_one'] ?? 'Please select at least one option.' ?></p>
                            </div>
                        <?php endforeach ?>
                    </div>
                    <?php endif ?>

                    <div class="pt-8 border-t border-slate-200 flex flex-col md:flex-row items-center justify-between gap-6">
                        <div class="text-sm font-semibold text-slate-700 italic text-center md:text-left">
                            "<?= $LANG['thank_you_participating'] ?? 'Thank you for participating in this survey.' ?>"
                        </div>
                        
                        <div class="flex items-center gap-4 w-full md:w-auto justify-end">
                            <a href="my_sections.php" class="px-5 py-2.5 text-sm border border-slate-300 rounded-xl text-slate-600 hover:bg-slate-50 font-medium"><?= $LANG['cancel'] ?? 'Cancel' ?></a>
                            
                            <?php if ($canSubmit): ?>
                            <button type="submit" id="submit-btn"
                                    class="w-full md:w-auto px-8 py-2.5 text-sm font-bold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl shadow-md transition-all">
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
                    </div>
                </form>
                <?php else: ?>
                <div class="text-center py-12 text-slate-500">
                    <p><?= $LANG['no_questions_in_form'] ?? 'No questions in this form yet.' ?></p>
                </div>
                <?php endif ?>
            </div>
        </main>
    </div>
</div>

<script>
function openSidebar()  { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full');    document.getElementById('overlay').classList.add('hidden'); }

<?php if ($canSubmit): ?>
const form = document.getElementById('feedback-form');
const btn  = document.getElementById('submit-btn');
if (form && btn) {
    form.addEventListener('submit', function (e) {
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
        if (!valid) {
            e.preventDefault();
            return;
        }
        btn.disabled = true;
        btn.className = "w-full md:w-auto px-8 py-2.5 text-sm font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed";
        btn.innerHTML = '<?= $LANG['please_wait'] ?? 'Please wait...' ?>';
    });
}
<?php endif ?>
</script>
</body>
</html>