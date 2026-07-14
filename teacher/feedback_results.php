<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('teacher');

updateAllFeedbackStatuses($conn);

$user = getCurrentUser();
$stmt = $conn->prepare("SELECT t.id FROM teachers t WHERE t.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();
$teacherId = $teacher['id'] ?? 0;

$pageTitle = $LANG['teacher_results_title'] ?? 'Feedback Results';
$activeMenu = 'results';

// Parse query strings accurately
$sectionId = (int) ($_GET['section_id'] ?? 0);
$formId = (int) ($_GET['form_id'] ?? 0);
$semesterFilter = clean($_GET['semester'] ?? '');

// My sections list
$mySections = [];
if ($teacherId) {
    $rs = $conn->query("SELECT s.id, c.course_name, c.course_code, s.section, s.academic_year, s.semester FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.teacher_id=$teacherId ORDER BY s.id DESC");
    $mySections = $rs->fetch_all(MYSQLI_ASSOC);
}

// Distinct semesters for filter
$semesters = [];
if ($teacherId) {
    $rs = $conn->query("SELECT DISTINCT s.semester FROM sections s WHERE s.teacher_id=$teacherId AND s.semester IS NOT NULL AND s.semester != '' ORDER BY s.semester");
    while ($r = $rs->fetch_assoc())
        $semesters[] = $r['semester'];
}

// Filter sections by semester configuration
if ($semesterFilter && !empty($mySections)) {
    $mySections = array_filter($mySections, fn($s) => $s['semester'] === $semesterFilter);
    // If the currently selected section doesn't belong to the filtered semester, clear it
    if ($sectionId) {
        $validIds = array_map(fn($s) => (int)$s['id'], $mySections);
        if (!in_array($sectionId, $validIds)) {
            $sectionId = 0;
            $formId = 0;
        }
    }
}

// Automatically deduce section_id if form_id was passed without a section context
if ($formId && !$sectionId) {
    $secStmt = $conn->prepare("SELECT ff.section_id FROM feedback_forms ff JOIN sections s ON ff.section_id = s.id WHERE ff.id = ? AND s.teacher_id = ?");
    $secStmt->bind_param('ii', $formId, $teacherId);
    $secStmt->execute();
    $resSec = $secStmt->get_result()->fetch_assoc();
    if ($resSec) {
        $sectionId = (int)$resSec['section_id'];
    }
    $secStmt->close();
}

// Forms list matching current targeted section context
$sectionForms = [];
if ($sectionId && $teacherId) {
    $chk = $conn->prepare("SELECT id FROM sections WHERE id=? AND teacher_id=?");
    $chk->bind_param('ii', $sectionId, $teacherId);
    $chk->execute();
    if ($chk->get_result()->num_rows) {
        $rf = $conn->prepare("SELECT ff.id, ff.title, ff.status, (SELECT COUNT(*) FROM feedback_submissions fs WHERE fs.form_id=ff.id) AS submissions FROM feedback_forms ff WHERE ff.section_id=? ORDER BY ff.id DESC");
        $rf->bind_param('i', $sectionId);
        $rf->execute();
        $sectionForms = $rf->get_result()->fetch_all(MYSQLI_ASSOC);
        $rf->close();
    }
    $chk->close();
}

// Fallback auto-selection layer
$autoFormId = 0;
if ($sectionId && !$formId && !empty($sectionForms)) {
    $formId = (int) $sectionForms[0]['id'];
    $autoFormId = $formId;
}

// Core Data fetching block for selected feedback form
$form = null;
$questions = [];
$ratingResults = [];
$comments = [];
$surveyResults = [];
$submissionCount = 0;

if ($formId && $teacherId) {
    // FIXED: Ensured soft match criteria so variations in table linkages do not cause zero rows returned
    $rf = $conn->prepare("SELECT ff.*, c.course_name, c.course_code, s.section, s.academic_year, s.semester, u.name AS teacher_name FROM feedback_forms ff JOIN sections s ON ff.section_id=s.id JOIN courses c ON s.course_id=c.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id WHERE ff.id=? AND s.teacher_id=?");
    $rf->bind_param('ii', $formId, $teacherId);
    $rf->execute();
    $form = $rf->get_result()->fetch_assoc();
    $rf->close();
    
    if ($form) {
        $q = $conn->prepare("SELECT * FROM feedback_questions WHERE module='academic' ORDER BY question_no ASC");
        $q->execute();
        $questions = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        // Total Students from students table via Semester + Section
        $totalStudentsStmt = $conn->prepare("SELECT COUNT(DISTINCT st.id) AS cnt FROM students st JOIN section_assignments sa ON sa.student_id = st.id WHERE sa.section_id = ?");
        $totalStudentsStmt->bind_param('i', $form['section_id']);
        $totalStudentsStmt->execute();
        $totalStudents = (int) $totalStudentsStmt->get_result()->fetch_assoc()['cnt'];
        $totalStudentsStmt->close();

        // Submitted = COUNT(DISTINCT student_id) for this form's section
        $completedStmt = $conn->prepare("SELECT COUNT(DISTINCT st.id) AS cnt FROM students st JOIN section_assignments sa ON sa.student_id = st.id JOIN feedback_submissions fs ON fs.student_id = st.id WHERE sa.section_id = ? AND fs.form_id = ?");
        $completedStmt->bind_param('ii', $form['section_id'], $formId);
        $completedStmt->execute();
        $completedCount = (int) $completedStmt->get_result()->fetch_assoc()['cnt'];
        $completedStmt->close();

        $pendingCount = max(0, $totalStudents - $completedCount);

        foreach ($questions as $quest) {
            if ($quest['question_type'] === 'rating') {
                $rs = $conn->prepare("SELECT rating, COUNT(*) AS cnt FROM feedback_ratings WHERE question_id=? AND form_id=? GROUP BY rating");
                $rs->bind_param('ii', $quest['id'], $formId);
                $rs->execute();
                $rawR = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
                $rs->close();

                $bd = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
                $tot = 0;
                foreach ($rawR as $rr) {
                    $rKey = trim($rr['rating']);
                    if ($rKey == '3' || $rKey === 'ကောင်း' || $rKey === 'Good' || $rKey === 'good') {
                        $rKey = 'Good';
                    } elseif ($rKey == '2' || $rKey === 'သင့်' || $rKey === 'Normal' || $rKey === 'normal' || $rKey === 'Average' || $rKey === 'Fair' || $rKey === 'fair') {
                        $rKey = 'Fair';
                    } elseif ($rKey == '1' || $rKey === 'ညံ့' || $rKey === 'Bad' || $rKey === 'bad') {
                        $rKey = 'Bad';
                    }

                    if (array_key_exists($rKey, $bd)) {
                        $bd[$rKey] += (int) $rr['cnt'];
                        $tot += $rr['cnt'];
                    }
                }
                $ratingResults[$quest['id']] = ['breakdown' => $bd, 'total' => $tot];
            } else {
                $cs = $conn->prepare("SELECT comment_text FROM feedback_comments WHERE question_id=? AND form_id=? ORDER BY id DESC");
                $cs->bind_param('ii', $quest['id'], $formId);
                $cs->execute();
                $comments[$quest['id']] = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
                $cs->close();
            }
        }

        // Survey questions stats
        foreach ($questions as $quest) {
            if ($quest['question_type'] === 'survey') {
                $ss = $conn->prepare("
                    SELECT fsa.selected_option_index, COUNT(*) AS cnt
                    FROM feedback_survey_answers fsa
                    JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
                    WHERE fsub.form_id = ? AND fsa.question_id = ?
                    GROUP BY fsa.selected_option_index
                ");
                $ss->bind_param('ii', $formId, $quest['id']);
                $ss->execute();
                $rawS = $ss->get_result()->fetch_all(MYSQLI_ASSOC);
                $ss->close();
                $surveyResults[$quest['id']] = [];
                foreach ($rawS as $sr) {
                    $surveyResults[$quest['id']][(int)$sr['selected_option_index']] = (int)$sr['cnt'];
                }
            }
        }
    }
}

$ratingQuestions = [];
$commentQuestions = [];
$surveyQuestions = [];
foreach ($questions as $q) {
    if ($q['question_type'] === 'rating') {
        $ratingQuestions[] = $q;
    } elseif ($q['question_type'] === 'survey') {
        $surveyQuestions[] = $q;
    } else {
        $commentQuestions[] = $q;
    }
}

?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Pyidaungsu', 'Inter', 'sans-serif'] } } } }</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @import url('https://cdn.jsdelivr.net/css-myanmar-fonts/v1/pyidaungsu.css');
        body { font-family: 'Pyidaungsu', 'Inter', sans-serif; }
        body.lang-mm th { font-size: 0.8125rem; line-height: 1.6; }
        body.lang-mm td { font-size: 0.8125rem; line-height: 1.6; }
    </style>
</head>
<body class="h-full bg-gradient-to-br from-slate-50 via-blue-50 to-sky-50 font-inter antialiased <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
<?php require_once '../includes/teacher_sidebar.php'; ?>
                <div class="mb-6">
                    <h2 class="text-xl font-bold text-slate-800"><?= $LANG['teacher_results_title'] ?? 'Feedback Results' ?></h2>
                    <p class="text-sm text-slate-500"><?= $LANG['teacher_results_subtitle'] ?? 'View student feedback results anonymously' ?></p>
                </div>

                <div class="gap-6 items-start">
                    <div class="bg-white/90 backdrop-blur-sm rounded-2xl border border-blue-100/50 shadow-sm p-4">
                        <p class="text-xs font-bold text-slate-400 uppercase mb-3 tracking-wider"><?= $LANG['filter'] ?? 'Filter' ?></p>
                        <div class="mb-3">
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1"><?= $LANG['semester_filter'] ?? 'Semester' ?></label>
                            <select id="semesterFilter" onchange="filterBySemester()" class="w-full border border-blue-200/50 rounded-lg px-3 py-2 text-xs focus:border-blue-400 focus:ring-2 focus:ring-blue-400/20 outline-none bg-white/80">
                                <option value=""><?= $LANG['all_semesters'] ?? 'All Semesters' ?></option>
                                <?php foreach ($semesters as $sem): ?>
                                    <option value="<?= e($sem) ?>" <?= $semesterFilter === $sem ? 'selected' : '' ?>><?= e(formatSemester($sem)) ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>
                        <p class="text-xs font-bold text-slate-400 uppercase mb-3 tracking-wider"><?= $LANG['select_section'] ?? 'Select Section' ?></p>
                        <div class="space-y-2">
                            <?php if (!empty($mySections)): foreach ($mySections as $sec): 
                                $isSelected = $sectionId == $sec['id'];
                            ?>
                                <label onclick="toggleSection(<?= $sec['id'] ?>)" class="flex items-start gap-3 w-full px-3 py-2.5 rounded-xl text-xs transition-all border cursor-pointer select-none <?= $isSelected ? 'bg-blue-600 border-blue-600 text-white font-bold shadow-sm' : 'border-blue-200/50 text-slate-600 hover:bg-blue-50/50 hover:border-blue-300' ?>">
                                    <input type="checkbox" <?= $isSelected ? 'checked' : '' ?> class="mt-0.5 accent-blue-600 pointer-events-none">
                                    <div class="flex-1 min-w-0">
                                        <p class="truncate"><?= e($sec['course_name']) ?></p>
                                        <p class="text-[10px] mt-0.5 <?= $isSelected ? 'text-blue-100' : 'text-slate-400' ?>"><?= e(formatSemester($sec['semester'])) ?> · Section <?= e($sec['section']) ?></p>
                                    </div>
                                </label>
                            <?php endforeach; else: ?>
                                <p class="text-xs text-slate-400 italic text-center py-4"><?= $LANG['no_sections_assigned'] ?? 'No sections assigned.' ?></p>
                            <?php endif ?>
                        </div>
                    </div>

                    <div class="lg:col-span-3 space-y-6">
                        <?php if ($sectionId && !empty($sectionForms)): ?>
                            

                            <?php if ($form): ?>
                                <!-- Feedback Progress Stats (outside form) -->
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 mt-6 myanmar-font no-print">
                                    <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 text-center">
                                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1"><?= $LANG['total_students_label'] ?? 'Total Students' ?></p>
                                        <p class="text-3xl font-black text-slate-800"><?= $totalStudents ?></p>
                                    </div>
                                    <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 text-center">
                                        <p class="text-xs font-bold text-emerald-500 uppercase tracking-wider mb-1"><?= $LANG['completed_label'] ?? 'Completed' ?></p>
                                        <p class="text-3xl font-black text-emerald-600"><?= $completedCount ?></p>
                                        <p class="text-[10px] text-slate-400 mt-1"><?= $totalStudents > 0 ? round(($completedCount / $totalStudents) * 100) : 0 ?>% <?= $LANG['response_rate'] ?? 'response rate' ?></p>
                                    </div>
                                    <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 text-center">
                                        <p class="text-xs font-bold text-amber-500 uppercase tracking-wider mb-1"><?= $LANG['pending_label'] ?? 'Pending' ?></p>
                                        <p class="text-3xl font-black text-amber-600"><?= $pendingCount ?></p>
                                        <p class="text-[10px] text-slate-400 mt-1"><?= $totalStudents > 0 ? round(($pendingCount / $totalStudents) * 100) : 0 ?>% <?= $LANG['remaining'] ?? 'remaining' ?></p>
                                    </div>
                                </div>

                                <div class="bg-white/90 backdrop-blur-sm shadow-md rounded-xl border border-blue-100/50 p-6 md:p-8">

                                    <div class="text-center border-b-2 border-slate-800 pb-4 mb-5">
                                        <h2 class="text-lg md:text-xl font-bold text-slate-900 mb-1"><?= e($form['title']) ?></h2>
                                        <p class="text-xs text-slate-400 font-mono"><?= $LANG['statistical_report'] ?? 'Statistical Evaluation Report Matrix (Anonymous)' ?></p>
                                        <p class="text-xs text-slate-400 mt-1"><?= $LANG['feedback_period'] ?? 'Feedback Period' ?>: <?= formatDateTime($form['start_date']) ?> — <?= formatDateTime($form['end_date']) ?></p>
                                    <p class="text-xs mt-1"><?= badgeStatus($form['status']) ?> <?php if ($form['status'] === 'Active'): ?><span class="text-slate-500"><?= getTimeRemaining($form['end_date']) ?></span><?php elseif ($form['status'] === 'Upcoming'): ?><span class="text-slate-500"><?= getTimeUntilStart($form['start_date']) ?></span><?php endif ?></p>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-4  gap-4 mb-6 bg-blue-50/50 backdrop-blur-sm p-4 rounded-xl border border-blue-100/50 text-xs">
                                        
                                        <div>
                                            <span class="font-bold text-slate-500 text-[10px]"><?= $LANG['academic_year_label'] ?? 'Academic Year' ?>:</span>
                                            <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800">
                                                <?= e($form['academic_year'] ?? '') ?>
                                            </div>
                                        </div>
                                    
                                        <div>
                                            <span class="font-bold text-slate-500 text-[10px]"><?= $LANG['semester_label'] ?? 'Semester' ?>:</span>
                                            <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800">
                                                <?= e(formatSemester($form['semester'] ?? '')) ?>
                                            </div>
                                        </div>
                                        <div>
                                            <span class="font-bold text-slate-500 text-[10px]"><?= $LANG['course_label_long'] ?? 'Course' ?>:</span>
                                            <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800 font-mono">
                                                <?= e($form['course_code']) ?> (<?= e($form['course_name']) ?>)
                                            </div>
                                        </div>
                                        <div>
                                            <span class="font-bold text-slate-500 text-[10px]"><?= $LANG['teacher_label'] ?? 'Teacher' ?>:</span>
                                            <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800">
                                               <?= e($form['teacher_name'] ?? '') ?> 
                                            </div>
                                        </div>
                                    </div>

                                    <?php if (!empty($ratingQuestions)): ?>
                                        <div class="mb-8">
                                            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3"><?= $LANG['question_stats_header'] ?? 'Question-wise Statistical Results' ?></h3>
                                            <div class="overflow-x-auto border border-blue-200/50 rounded-lg">
                                                <table class="w-full text-left border-collapse min-w-[650px] text-xs">
                                                    <thead>
                                                        <tr class=" text-white font-bold">
                                                            <th class="p-3 w-12 text-center bg-blue-300"><?= $LANG['col_no'] ?? 'စဉ်' ?></th>
                                                            <th class="p-3 bg-blue-500"><?= $LANG['eval_questions_header'] ?? 'Evaluation Questions' ?></th>
                                                            <th class="p-3 w-28 text-center bg-emerald-700"><div><?= $LANG['good'] ?? 'Good' ?></div><div class="text-[10px] font-normal"><?= $LANG['count_pct'] ?? 'COUNT / %' ?></div></th>
                                                            <th class="p-3 w-28 text-center bg-amber-600"><div><?= $LANG['fair'] ?? 'Fair' ?></div><div class="text-[10px] font-normal"><?= $LANG['count_pct'] ?? 'COUNT / %' ?></div></th>
                                                            <th class="p-3 w-28 text-center bg-red-700"><div><?= $LANG['bad'] ?? 'Bad' ?></div><div class="text-[10px] font-normal"><?= $LANG['count_pct'] ?? 'COUNT / %' ?></div></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody class="divide-y divide-blue-200/40 text-slate-800 font-medium">
                                                        <?php foreach ($ratingQuestions as $q):
                                                            $res = $ratingResults[$q['id']] ?? ['breakdown' => ['Good' => 0, 'Fair' => 0, 'Bad' => 0], 'total' => 0];
                                                            $goodCount = $res['breakdown']['Good'] ?? 0;
                                                            $normalCount = $res['breakdown']['Fair'] ?? 0;
                                                            $badCount = $res['breakdown']['Bad'] ?? 0;
                                                            $totalVotes = $goodCount + $normalCount + $badCount;
                                                            $goodPerc = $totalVotes > 0 ? round(($goodCount / $totalVotes) * 100) : 0;
                                                            $normalPerc = $totalVotes > 0 ? round(($normalCount / $totalVotes) * 100) : 0;
                                                            $badPerc = $totalVotes > 0 ? round(($badCount / $totalVotes) * 100) : 0;
                                                        ?>
                                                            <tr class="hover:bg-blue-50/30 transition-colors">
                                                                <td class="p-3 text-center font-bold font-mono border-r"><?= e($q['question_no']) ?></td>
                                                                <td class="p-3 border-r leading-relaxed"><?= e($q['question_text']) ?></td>
                                                                <td class="p-3 text-center border-r bg-emerald-50/30">
                                                                    <span class="text-emerald-700 font-bold block text-sm"><?= $goodCount ?> <?= $LANG['persons'] ?? 'persons' ?></span>
                                                                    <span class="text-[10px] text-slate-500">(<?= $goodPerc ?>%)</span>
                                                                </td>
                                                                <td class="p-3 text-center border-r bg-amber-50/30">
                                                                    <span class="text-amber-700 font-bold block text-sm"><?= $normalCount ?> <?= $LANG['persons'] ?? 'persons' ?></span>
                                                                    <span class="text-[10px] text-slate-500">(<?= $normalPerc ?>%)</span>
                                                                </td>
                                                                <td class="p-3 text-center bg-red-50/30">
                                                                    <span class="text-red-700 font-bold block text-sm"><?= $badCount ?> <?= $LANG['persons'] ?? 'persons' ?></span>
                                                                    <span class="text-[10px] text-slate-500">(<?= $badPerc ?>%)</span>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    <?php endif ?>

                                    <?php if (!empty($commentQuestions)): ?>
                                        <div class="space-y-6 pt-6 border-t-2 border-blue-200/50">
                                            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider"><?= $LANG['comments_header'] ?? 'Comments' ?></h3>
                                            <?php foreach ($commentQuestions as $q): $commentsForThisQuestion = $comments[$q['id']] ?? []; ?>
                                                <div class="space-y-2 text-xs">
                                                    <label class="block font-bold text-slate-700 text-sm">
                                                        <?= e($q['question_no']) ?>။ <?= e($q['question_text']) ?>
                                                        <span class="text-slate-400 font-normal text-xs">(<?= $LANG['total_comments'] ?? 'Total comments' ?> - <?= count($commentsForThisQuestion) ?> <?= $LANG['comments_box'] ?? 'comments' ?>)</span>
                                                    </label>
                                                    <div class="w-full bg-blue-50/50 backdrop-blur-sm border border-blue-100/50 p-4 rounded-xl space-y-2.5 max-h-[300px] overflow-y-auto">
                                                        <?php if (!empty($commentsForThisQuestion)): foreach ($commentsForThisQuestion as $index => $cm): ?>
                                                                    <div class="bg-white border border-blue-100/40 p-3 rounded-lg shadow-2xs flex items-start gap-2">
                                                                    <span class="text-slate-400 font-bold">#<?= $index + 1 ?></span>
                                                                    <div class="text-slate-800 font-medium whitespace-pre-wrap"><?= e($cm['comment_text']) ?></div>
                                                                </div>
                                                            <?php endforeach; else: ?>
                                                            <div class="text-slate-400 italic text-center py-4">— <?= $LANG['no_comments'] ?? 'No comments for this question' ?> —</div>
                                    <?php endif ?>
                                </div>
                            </div>
                        <?php endforeach ?>
                    </div>
                <?php endif ?>

               


                <!-- ═══════════════════════════════════════════════════════════════ -->
            <!-- MICROSOFT FORMS-STYLE SURVEY (MCQ) RESULTS -->
            <!-- ═══════════════════════════════════════════════════════════════ -->
            <?php if (!empty($surveyQuestions)): ?>
                <div class="space-y-6 pt-6 border-t-2 border-slate-300">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-2">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                                stroke="currentColor" class="w-4 h-4 text-violet-500">
                                <path stroke-linecap="round" stroke-linejoin="round"
                                    d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12" />
                            </svg>
                            <?= $LANG['survey_results'] ?? 'Survey Results' ?>
                        </h3>
                        <span class="text-[10px] text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full font-semibold">Not
                            included in Overall Rating</span>
                    </div>

                    <?php foreach ($surveyQuestions as $q):
                        $opts = json_decode($q['options_json'] ?? '[]', true) ?: [];
                        $qStats = $surveyResults[$q['id']] ?? [];
                        $mostSelected = getMostSelectedSurveyOptions($qStats);
                        $totalVotes = $mostSelected['total'];

                        $doughnutColors = ['#7c3aed', '#2563eb', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#0891b2', '#c026d3'];
                        $chartLabels = [];
                        $chartData = [];
                        $chartColors = [];
                        foreach ($opts as $idx => $opt) {
                            $chartLabels[] = $opt;
                            $chartData[] = $qStats[$idx] ?? 0;
                            $chartColors[] = $doughnutColors[$idx % count($doughnutColors)];
                        }
                        $firstMostIdx = !empty($mostSelected['indices']) ? $mostSelected['indices'][0] : -1;
                        $respondentPct = $totalVotes > 0 ? round(($chartData[$firstMostIdx] ?? 0) / $totalVotes * 100) : 0;
                        ?>
                        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm survey-question-card" data-qid="<?= $q['id'] ?>">
                            <!-- Question Header -->
                            <div class="px-6 pt-5 pb-3 border-b border-slate-100">
                                <div class="flex items-start gap-3">
                                    <span class="text-xs font-bold text-violet-600 bg-violet-50 px-2 py-1 rounded-lg mt-0.5 shrink-0">
                                        <?= e($q['question_no']) ?>
                                    </span>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-sm font-bold text-slate-800 leading-snug">
                                            <?= e($q['question_text']) ?>
                                        </h4>
                                        <p class="text-[11px] text-slate-400 mt-1">
                                            <?= $totalVotes > 0 ? $totalVotes . ' ' . ($LANG['responses'] ?? 'responses') : ($LANG['no_responses'] ?? 'No responses yet') ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <?php if ($totalVotes > 0): ?>
                                <!-- Chart + Options -->
                                <div class="p-6">
                                    <!-- Respondent Summary -->
                                    <div class="text-center mb-5">
                                        <p class="text-2xl font-black text-violet-600"><?= $respondentPct ?>%</p>
                                        <p class="text-[11px] text-slate-400 font-medium">
                                            <?= $LANG['of_respondents_selected'] ?? 'of respondents selected' ?>
                                            <span class="font-bold text-slate-700">"<?= e(implode('" & "', array_map(fn($i) => $chartLabels[$i] ?? '', $mostSelected['indices']))) ?>"</span>
                                        </p>
                                    </div>

                                    <div class="flex flex-col md:flex-row gap-6 items-start">
                                        <!-- Doughnut Chart -->
                                        <div class="w-full md:w-1/3 flex justify-center">
                                            <div class="relative" style="width:220px;height:220px;">
                                                <canvas id="surveyChart_<?= $q['id'] ?>" width="220" height="220"></canvas>
                                                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                                                    <span class="text-lg font-black text-slate-800"><?= $totalVotes ?></span>
                                                    <span class="text-[10px] text-slate-400 font-semibold uppercase">Total</span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Options List -->
                                        <div class="w-full md:w-2/3 space-y-2">
                                            <?php foreach ($opts as $idx => $opt):
                                                $votes = $chartData[$idx];
                                                $pct = $totalVotes > 0 ? round(($votes / $totalVotes) * 100) : 0;
                                                $isMostSelected = in_array($idx, $mostSelected['indices']);
                                                ?>
                                                <div class="flex items-center gap-3 group">
                                                    <!-- Color dot -->
                                                    <span class="w-3 h-3 rounded-full shrink-0 ring-2 ring-white shadow-sm"
                                                          style="background-color: <?= $chartColors[$idx] ?>"></span>
                                                    <!-- Option label + votes -->
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <span class="text-sm font-semibold text-slate-700 truncate">
                                                                <?= e($opt) ?>
                                                            </span>
                                                            <div class="flex items-center gap-2 shrink-0">
                                                                <span class="text-[11px] text-slate-400 font-medium"><?= $votes ?> <?= $votes === 1 ? ($LANG['vote'] ?? 'vote') : ($LANG['votes'] ?? 'votes') ?></span>
                                                                <?php if ($isMostSelected): ?>
                                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-4 h-4 text-violet-600">
                                                                        <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                                                    </svg>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <!-- Progress bar -->
                                                        <div class="w-full bg-slate-100 rounded-full h-1.5 mt-1.5 overflow-hidden">
                                                            <div class="h-full rounded-full transition-all duration-700"
                                                                 style="width: <?= $pct ?>%; background-color: <?= $chartColors[$idx] ?>"></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <!-- No Responses — still show all options with <?= $LANG['zero_votes'] ?? '0 votes' ?> -->
                                <div class="p-6">
                                    <div class="text-center mb-5">
                                        <p class="text-sm font-semibold text-slate-400">
                                            <?= $LANG['no_responses'] ?? 'No responses yet' ?>
                                        </p>
                                    </div>
                                    <div class="max-w-2xl mx-auto space-y-2">
                                        <?php foreach ($opts as $idx => $opt): ?>
                                            <div class="flex items-center gap-3">
                                                <span class="w-3 h-3 rounded-full shrink-0 ring-2 ring-white shadow-sm"
                                                      style="background-color: <?= $chartColors[$idx] ?>"></span>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="text-sm font-semibold text-slate-700 truncate">
                                                            <?= e($opt) ?>
                                                        </span>
                                                        <span class="text-[11px] text-slate-400 font-medium shrink-0"><?= $LANG['zero_votes'] ?? '0 votes' ?></span>
                                                    </div>
                                                    <div class="w-full bg-slate-100 rounded-full h-1.5 mt-1.5 overflow-hidden">
                                                        <div class="h-full rounded-full transition-all duration-700"
                                                             style="width: 0%; background-color: <?= $chartColors[$idx] ?>"></div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

                                    <div class="mt-8 pt-4 border-t border-blue-100/50 text-center text-[11px] text-blue-500 font-semibold italic">
                                        "<?= $LANG['anonymous_note'] ?? 'This report is an automatic statistical system that maintains student privacy.' ?>"
                                    </div>
                                </div>
                            <?php endif ?>
                        <?php elseif ($semesterFilter && empty($mySections)): ?>
                            <div class="bg-white/90 backdrop-blur-sm rounded-2xl border border-blue-100/50 text-center py-20 text-slate-400">
                                <span class="text-2xl block mb-2">🔍</span>
                                <p class="text-sm font-semibold"><?= $LANG['no_feedback_semester'] ?? 'No feedback available for the selected semester.' ?></p>
                                <p class="text-xs mt-1"><?= $LANG['no_feedback_results_semester'] ?? 'No feedback results for the selected semester.' ?></p>
                            </div>
                        <?php elseif ($sectionId): ?>
                            <div class="bg-white/90 backdrop-blur-sm rounded-2xl border border-blue-100/50 text-center py-20 text-slate-400">
                                <span class="text-2xl block mb-2">📄</span>
                                <p class="text-sm font-semibold"><?= $LANG['no_feedback_forms_for_section'] ?? 'No feedback forms for this section.' ?></p>
                            </div>
                        <?php else: ?>
                            <div class="bg-white/90 backdrop-blur-sm rounded-2xl border border-blue-100/50 text-center py-20 text-slate-400 mt-4">
                                <span class="text-2xl block mb-2">📊</span>
                                <p class="text-sm font-semibold"><?= $LANG['select_section_first'] ?? 'Select a section to view results.' ?></p>
                                <p class="text-xs mt-1"><?= $LANG['select_section_first'] ?? 'Select a section from the above to view results.' ?></p>
                            </div>
                        <?php endif ?>
                    </div>
                </div>
    <script>
        function filterBySemester() {
            var semester = document.getElementById('semesterFilter').value;
            var url = new URL(window.location);
            if (semester) {
                url.searchParams.set('semester', semester);
            } else {
                url.searchParams.delete('semester');
            }
            url.searchParams.delete('section_id');
            url.searchParams.delete('form_id');
            window.location.href = url.href;
        }
        function toggleSection(id) {
            var url = new URL(window.location);
            var current = url.searchParams.get('section_id');
            if (current == id) {
                url.searchParams.delete('section_id');
            } else {
                url.searchParams.set('section_id', id);
            }
            url.searchParams.delete('form_id');
            window.location.href = url.href;
        }
        <?php if ($autoFormId): ?>
        (function() {
            var url = new URL(window.location);
            url.searchParams.set('form_id', '<?= $autoFormId ?>');
            window.location.href = url.href;
        })();
        <?php endif ?>
    </script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        <?php if (!empty($surveyQuestions)): ?>
        var chartDefaults = {
            responsive: false,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(15,23,42,0.9)',
                    titleFont: { size: 12, weight: '600' },
                    bodyFont: { size: 13, weight: '700' },
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        title: function() { return ''; },
                        label: function(ctx) {
                            var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                            var pct = total > 0 ? Math.round((ctx.raw / total) * 100) : 0;
                            return pct + '%';
                        }
                    }
                }
            },
            animation: {
                animateRotate: true,
                animateScale: true,
                duration: 900,
                easing: 'easeOutQuart'
            }
        };

        <?php
        $tSurveyChartData = [];
        foreach ($surveyQuestions as $q) {
            $opts = json_decode($q['options_json'] ?? '[]', true) ?: [];
            $qStats = $surveyResults[$q['id']] ?? [];
            $doughnutColors = ['#7c3aed', '#2563eb', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#0891b2', '#c026d3'];
            $labels = [];
            $data = [];
            $colors = [];
            foreach ($opts as $idx => $opt) {
                $labels[] = addslashes($opt);
                $data[] = (int)($qStats[$idx] ?? 0);
                $colors[] = $doughnutColors[$idx % count($doughnutColors)];
            }
            $tSurveyChartData[$q['id']] = [
                'labels' => $labels,
                'data' => $data,
                'colors' => $colors,
            ];
        }
        ?>
        var surveyCharts = <?= json_encode($tSurveyChartData) ?>;

        Object.keys(surveyCharts).forEach(function(qid) {
            var cfg = surveyCharts[qid];
            var canvas = document.getElementById('surveyChart_' + qid);
            if (!canvas) return;
            new Chart(canvas.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: cfg.labels,
                    datasets: [{
                        data: cfg.data,
                        backgroundColor: cfg.colors,
                        borderColor: '#ffffff',
                        borderWidth: 3,
                        hoverBorderWidth: 0,
                        hoverOffset: 6
                    }]
                },
                options: chartDefaults
            });
        });
        <?php endif; ?>
    });
</script>
<?php require_once '../includes/teacher_footer.php'; ?>