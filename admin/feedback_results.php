<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

updateAllFeedbackStatuses($conn);

$pageTitle = $LANG['results_title'] ?? 'Student Feedback Results Matrix';
$activeMenu = 'results';

// Filter Inputs
$formId = (int) ($_GET['form_id'] ?? 0);

// Form list dropdown — all academic forms
$formSql = "
    SELECT ff.id, c.course_name, s.section, s.semester 
    FROM feedback_forms ff 
    JOIN sections s ON ff.section_id = s.id 
    JOIN courses c ON s.course_id = c.id 
    WHERE ff.module = 'academic'
    ORDER BY s.semester DESC, c.course_name ASC
";
$formList = $conn->query($formSql)->fetch_all(MYSQLI_ASSOC);

$form = null;
$questions = [];
$ratingStats = [];
$allComments = [];
$surveyStats = [];

if ($formId) {
    // ၂။ ရွေးချယ်ထားသော Form metadata ကို တစ်ကြိမ်တည်း ထုတ်ယူခြင်း
    $r = $conn->prepare("SELECT ff.*, c.course_name, c.course_code, s.section, s.academic_year, s.semester, u.name AS teacher_name FROM feedback_forms ff JOIN sections s ON ff.section_id=s.id JOIN courses c ON s.course_id=c.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id WHERE ff.id=?");
    $r->bind_param('i', $formId);
    $r->execute();
    $form = $r->get_result()->fetch_assoc();
    $r->close();

    if ($form) {
        // ၃။ မေးခွန်းများကို module အလိုက် (shared) ဆွဲထုတ်ခြင်း
        $q = $conn->prepare("SELECT id, question_no, question_text, question_type, options_json FROM feedback_questions WHERE module='academic' ORDER BY question_no ASC");
        $q->execute();
        $questions = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        // Feedback Progress Stats — Total Students: count all students in this section from students table
        $totalStudentsStmt = $conn->prepare("SELECT COUNT(sa.student_id) AS cnt FROM section_assignments sa WHERE sa.section_id = ?");
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

        // ၄။ Rating Questions များအတွက် တွက်ချက်ခြင်း (form_id ဖြင့် filter)
        $statStmt = $conn->prepare("
            SELECT fq.id AS question_id, fr.rating, COUNT(*) AS qty
            FROM feedback_ratings fr
            JOIN feedback_questions fq ON fr.question_id = fq.id
            WHERE fr.form_id = ?
            GROUP BY fq.id, fr.rating
        ");
        $statStmt->bind_param('i', $formId);
        $statStmt->execute();
        $rawStats = $statStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $statStmt->close();

        foreach ($rawStats as $row) {
            $rKey = trim($row['rating']);

            // Database ထဲရှိ မြန်မာ/အင်္ဂလိပ် တန်ဖိုးများကို Standard English UI Key သို့ ချိန်ညှိပါသည်
            if ($rKey === '3' || $rKey === 'ကောင်း' || $rKey === 'Good' || $rKey === 'good') {
                $rKey = 'Good';
            } elseif ($rKey === '2' || $rKey === 'သင့်' || $rKey === 'Normal' || $rKey === 'normal' || $rKey === 'Average' || $rKey === 'Fair' || $rKey === 'fair') {
                $rKey = 'Fair';
            } elseif ($rKey === '1' || $rKey === 'ညံ့' || $rKey === 'Bad' || $rKey === 'bad' || $rKey === 'Poor' || $rKey === 'poor') {
                $rKey = 'Bad';
            }

            if (!isset($ratingStats[$row['question_id']][$rKey])) {
                $ratingStats[$row['question_id']][$rKey] = 0;
            }
            $ratingStats[$row['question_id']][$rKey] += (int) $row['qty'];
        }

        // ၅။ Comments များကို မေးခွန်းအလိုက် စုစည်းထုတ်ယူခြင်း (form_id ဖြင့် filter)
        $cStmt = $conn->prepare("
            SELECT fq.id AS question_id, fc.comment_text
            FROM feedback_comments fc
            JOIN feedback_questions fq ON fc.question_id = fq.id
            WHERE fc.form_id = ? AND fc.comment_text IS NOT NULL AND fc.comment_text != ''
        ");
        $cStmt->bind_param('i', $formId);
        $cStmt->execute();
        $rawComments = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cStmt->close();

        foreach ($rawComments as $row) {
            $allComments[$row['question_id']][] = $row['comment_text'];
        }

        // ၆။ Survey Questions များအတွက် aggregate counts
        $surveyQs = array_filter($questions, fn($q) => $q['question_type'] === 'survey');
        if (!empty($surveyQs)) {
            $surveyIds = array_column($surveyQs, 'id');
            $placeholders = implode(',', array_fill(0, count($surveyIds), '?'));
            $surveyStmt = $conn->prepare("
                SELECT fsa.question_id, fsa.selected_option_index, COUNT(*) AS cnt
                FROM feedback_survey_answers fsa
                JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
                WHERE fsub.form_id = ? AND fsa.question_id IN ($placeholders)
                GROUP BY fsa.question_id, fsa.selected_option_index
            ");
            $bindParams = array_merge([$formId], $surveyIds);
            $surveyStmt->bind_param('i' . str_repeat('i', count($surveyIds)), ...$bindParams);
            $surveyStmt->execute();
            $rawSurvey = $surveyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $surveyStmt->close();
            foreach ($rawSurvey as $sr) {
                $surveyStats[$sr['question_id']][(int) $sr['selected_option_index']] = (int) $sr['cnt'];
            }
        }
    }
}

// Array ခွဲထုတ်ခြင်း
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

// ═══════════════════════════════════════════════════════════════
// Overall Teacher Rating Calculation (Rating Questions Only)
// ═══════════════════════════════════════════════════════════════
$completedCount = $completedCount ?? 0;
$totalStudents = $totalStudents ?? 0;
$pendingCount = $pendingCount ?? 0;
$totalGood = $totalFair = $totalBad = 0;
foreach ($ratingStats as $qId => $counts) {
    $totalGood += $counts['Good'] ?? 0;
    $totalFair += $counts['Fair'] ?? 0;
    $totalBad += $counts['Bad'] ?? 0;
}
$totalRatingResponses = $totalGood + $totalFair + $totalBad;
$numRatingQuestions = count($ratingQuestions);
$earnedScore = ($totalGood * 5) + ($totalFair * 3) + ($totalBad * 1);
$maxScore = $completedCount * $numRatingQuestions * 5;
$overallPct = $maxScore > 0 ? round(($earnedScore / $maxScore) * 100, 1) : 0;

// Grade assignment
if ($overallPct >= 90) {
    $grade = 'Excellent';
    $gradeColor = 'emerald';
    $gradeIcon = '🏆';
} elseif ($overallPct >= 80) {
    $grade = 'Very Good';
    $gradeColor = 'blue';
    $gradeIcon = '⭐';
} elseif ($overallPct >= 70) {
    $grade = 'Good';
    $gradeColor = 'cyan';
    $gradeIcon = '👍';
} elseif ($overallPct >= 60) {
    $grade = 'Fair';
    $gradeColor = 'amber';
    $gradeIcon = '📋';
} else {
    $grade = 'Needs Improvement';
    $gradeColor = 'red';
    $gradeIcon = '⚠️';
}

// Aggregate percentages for Good / Fair / Bad across all rating questions
$aggGoodPct = $totalRatingResponses > 0 ? round(($totalGood / $totalRatingResponses) * 100, 1) : 0;
$aggFairPct = $totalRatingResponses > 0 ? round(($totalFair / $totalRatingResponses) * 100, 1) : 0;
$aggBadPct = $totalRatingResponses > 0 ? round(($totalBad / $totalRatingResponses) * 100, 1) : 0;

// SVG circular progress values (radius=54, circumference=339.292)
$circleRadius = 54;
$circleCircumference = 2 * M_PI * $circleRadius;
$circleOffset = $circleCircumference - ($overallPct / 100) * $circleCircumference;

// Print conclusion based on grade
$conclusions = [
    'Excellent' => 'The teacher has demonstrated outstanding performance based on student feedback. Students are highly satisfied with the teaching quality, methodology, and classroom engagement. It is recommended to recognize and commend this performance.',
    'Very Good' => 'The teacher has shown very good performance with high student satisfaction. Minor areas for improvement may exist, but overall teaching quality is well above expectations.',
    'Good' => 'The teacher has achieved a good performance rating. There are opportunities for further improvement in certain areas, but the overall feedback is positive.',
    'Fair' => 'The teacher\'s performance is at an acceptable level. There are notable areas requiring improvement. It is recommended to provide constructive feedback and professional development opportunities.',
    'Needs Improvement' => 'The teacher\'s performance falls below the expected standard. Significant improvement is needed in teaching methodology, student engagement, and overall classroom effectiveness. Immediate attention and support are recommended.',
];
$conclusionText = $conclusions[$grade] ?? '';

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<style>
    @import url('https://cdn.jsdelivr.net/css-myanmar-fonts/v1/pyidaungsu.css');

    .myanmar-font {
        font-family: 'Pyidaungsu', sans-serif;
    }

    body.lang-mm th { font-size: 0.8125rem; line-height: 1.6; }
    body.lang-mm td { font-size: 0.8125rem; line-height: 1.6; }

    /* Circular Progress Ring Animation */
    .rating-ring {
        transform: rotate(-90deg);
        transform-origin: 50% 50%;
    }

    .rating-ring-circle {
        transition: stroke-dashoffset 1.5s ease-in-out;
    }

    .rating-ring-bg {
        opacity: 0.15;
    }

    /* Progress bar animation */
    .progress-bar-fill {
        transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

    /* Grade badge pulse */
    .grade-badge {
        animation: gradePulse 2s ease-in-out infinite;
    }

    @keyframes gradePulse {

        0%,
        100% {
            box-shadow: 0 0 0 0 rgba(99, 102, 241, 0.3);
        }

        50% {
            box-shadow: 0 0 0 8px rgba(99, 102, 241, 0);
        }
    }

    /* Survey most-selected glow */
    .most-selected {
        border-color: #8b5cf6 !important;
        background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%) !important;
        box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.15);
    }

    /* Print-only class */
    .print-only {
        display: none !important;
    }

    @media print {

        *,
        *::before,
        *::after {
            overflow: visible !important;
            max-height: none !important;
            height: auto !important;
        }

        html,
        body {
            height: auto !important;
            overflow: visible !important;
            background: white !important;
            font-size: 11pt !important;
        }

        body {
            position: static !important;
        }

        #sidebar,
        #sidebar-overlay,
        header,
        .no-print,
        nav,
        aside {
            display: none !important;
        }

        .print-only {
            display: block !important;
        }

        .flex.h-screen {
            display: block !important;
            height: auto !important;
            overflow: visible !important;
        }

        .flex-1.flex.flex-col {
            overflow: visible !important;
            width: 100% !important;
        }

        main {
            overflow: visible !important;
            padding: 0 !important;
            height: auto !important;
        }

        .bg-white.shadow-md,
        .bg-white.shadow-lg,
        .bg-white.rounded-2xl {
            box-shadow: none !important;
            break-inside: avoid;
        }

        @page {
            margin: 1.2cm;
            size: A4 portrait;
        }

        .mb-6 {
            margin-bottom: 1rem !important;
        }

        .mb-8 {
            margin-bottom: 1rem !important;
        }

        .mt-8 {
            margin-top: 1rem !important;
        }

        table {
            page-break-inside: auto;
        }

        tr {
            page-break-inside: avoid;
        }

        h3 {
            page-break-after: avoid;
        }

        .overflow-x-auto {
            overflow: visible !important;
        }

        /* Print report styles */
        .print-report-header {
            text-align: center;
            border-bottom: 3px double #1e293b;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }

        .print-report-header h1 {
            font-size: 16pt;
            font-weight: 800;
            color: #0f172a;
            margin: 0;
            letter-spacing: 1px;
        }

        .print-report-header h2 {
            font-size: 13pt;
            font-weight: 700;
            color: #334155;
            margin: 4px 0;
        }

        .print-report-header p {
            font-size: 9pt;
            color: #64748b;
            margin: 2px 0;
        }

        .print-section {
            margin-bottom: 14px;
            break-inside: avoid;
        }

        .print-section-title {
            font-size: 11pt;
            font-weight: 700;
            color: #0f172a;
            border-bottom: 1.5px solid #cbd5e1;
            padding-bottom: 4px;
            margin-bottom: 8px;
        }

        .print-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px 24px;
            font-size: 10pt;
        }

        .print-info-grid dt {
            font-weight: 600;
            color: #475569;
        }

        .print-info-grid dd {
            color: #0f172a;
            font-weight: 500;
            border-bottom: 1px dotted #cbd5e1;
            padding-bottom: 2px;
        }

        .print-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }

        .print-table th {
            background: #1e293b !important;
            color: white !important;
            padding: 6px 8px;
            text-align: center;
            font-weight: 700;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .print-table td {
            padding: 5px 8px;
            border: 1px solid #e2e8f0;
        }

        .print-table tbody tr:nth-child(even) {
            background: #f8fafc !important;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        .print-rating-summary {
            display: flex;
            justify-content: center;
            gap: 32px;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            margin: 8px 0;
        }

        .print-rating-item {
            text-align: center;
        }

        .print-rating-item .value {
            font-size: 22pt;
            font-weight: 800;
            color: #0f172a;
        }

        .print-rating-item .label {
            font-size: 8pt;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .print-grade-badge {
            display: inline-block;
            padding: 2px 12px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 10pt;
            border: 1.5px solid #334155;
        }

        .print-conclusion {
            border-left: 4px solid #334155;
            padding: 10px 14px;
            background: #f8fafc;
            font-size: 10pt;
            line-height: 1.6;
            color: #334155;
            margin-top: 8px;
        }

        .print-footer {
            text-align: center;
            font-size: 8pt;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
            padding-top: 8px;
            margin-top: 20px;
        }
    }
</style>

<div class="mb-6 myanmar-font flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-xl font-bold text-slate-800">
            <?= $LANG['results_content_heading'] ?? 'Course Ratings & Feedback Results' ?>
        </h2>
        <p class="text-sm text-slate-500 mt-0.5">
            <?= $LANG['results_subtitle_question'] ?? 'မေးခွန်းအလိုက် စုစုပေါင်းရလဒ် စာရင်းဇယားနှင့် ကျောင်းသားများ၏ အကြံပြုချက်များ' ?>
        </p>
    </div>
    <?php if ($form): ?>
        <button onclick="window.print()"
            class="no-print inline-flex items-center gap-2 bg-slate-700 hover:bg-slate-800 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm transition-all hover:-translate-y-0.5">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m0 0a48.159 48.159 0 018.5 0m-8.5 0V6.75a2 2 0 012-2h4.5a2 2 0 012 2v1.044" />
            </svg>
            <?= $LANG['print_report'] ?? 'Print Report' ?>
        </button>
    <?php endif; ?>
</div>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6 myanmar-font no-print">
    <div class="flex flex-col sm:flex-row gap-4">

        <div class="flex-1 max-w-xl">
            <label
                class="block text-xs font-bold text-slate-500 mb-1"><?= $LANG['choose_form'] ?? 'Choose a Feedback Form:' ?></label>
            <select id="formSelect" onchange="buildFormUrl(this.value)"
                class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white font-semibold text-slate-700 shadow-sm focus:border-slate-500">
                <option value=""><?= $LANG['choose_form_placeholder'] ?? '— Choose a Feedback Form —' ?></option>
                <?php foreach ($formList as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $formId == $f['id'] ? 'selected' : '' ?>>
                        <?= e(formatSemester($f['semester'])) ?> — <?= e($f['course_name']) ?> (Section
                        <?= e($f['section']) ?>)
                    </option>
                <?php endforeach ?>
            </select>
        </div>
    </div>
</div>
<script>
    function buildFormUrl(formVal) {
        if (formVal) {
            location.href = '?form_id=' + formVal;
        } else {
            location.href = '?';
        }
    }
</script>

<?php if ($form): ?>
    <!-- Feedback Progress Stats (outside form) -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 myanmar-font no-print">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 text-center">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">
                <?= $LANG['total_students'] ?? 'စုစုပေါင်း ကျောင်းသား (Total Students)' ?>
            </p>
            <p class="text-3xl font-black text-slate-800"><?= $totalStudents ?></p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 text-center">
            <p class="text-xs font-bold text-emerald-500 uppercase tracking-wider mb-1">
                <?= $LANG['completed'] ?? 'ဖြေဆိုပြီး (Completed)' ?>
            </p>
            <p class="text-3xl font-black text-emerald-600"><?= $completedCount ?></p>
            <p class="text-[10px] text-slate-400 mt-1">
                <?= $totalStudents > 0 ? round(($completedCount / $totalStudents) * 100) : 0 ?>%
                <?= $LANG['response_rate'] ?? 'response rate' ?>
            </p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 text-center">
            <p class="text-xs font-bold text-amber-500 uppercase tracking-wider mb-1">
                <?= $LANG['pending'] ?? 'ကျန်ရှိနေသေး (Pending)' ?>
            </p>
            <p class="text-3xl font-black text-amber-600"><?= $pendingCount ?></p>
            <p class="text-[10px] text-slate-400 mt-1">
                <?= $totalStudents > 0 ? round(($pendingCount / $totalStudents) * 100) : 0 ?>% remaining
            </p>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════ -->
    <!-- OVERALL TEACHER RATING SUMMARY CARD -->
    <!-- ═══════════════════════════════════════════════════════════════ -->
    <?php if (!empty($ratingQuestions) && $completedCount > 0): ?>
        <div class="mb-6 myanmar-font no-print">
            <div
                class="bg-gradient-to-br from-blue-500 via-purple-700 to-indigo-700 rounded-2xl shadow-xl border border-slate-700 p-6 md:p-8 text-white relative overflow-hidden">
                <!-- Decorative background elements -->
                <div
                    class="absolute top-0 right-0 w-64 h-64 bg-gradient-to-bl from-indigo-500/10 to-transparent rounded-full -translate-y-1/2 translate-x-1/2">
                </div>
                <div
                    class="absolute bottom-0 left-0 w-48 h-48 bg-gradient-to-tr from-emerald-500/10 to-transparent rounded-full translate-y-1/2 -translate-x-1/2">
                </div>

                <div class="relative z-10">
                    <div class="flex items-center gap-2 mb-5">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5"
                            stroke="currentColor" class="w-5 h-5 text-indigo-300">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
                        </svg>
                        <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-200"><?= $LANG['overall_teacher_rating'] ?? 'Overall Teacher Rating' ?></h3>
                        <span class="ml-auto text-[10px] text-slate-400 bg-slate-700/50 px-2 py-0.5 rounded-full"><?= $LANG['based_on_rating_only'] ?? 'Based on Rating Questions Only' ?></span>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center">
                        <!-- Circular Progress Ring -->
                        <div class="md:col-span-3 flex flex-col items-center justify-center">
                            <div class="relative w-36 h-36">
                                <svg class="rating-ring w-36 h-36" viewBox="0 0 120 120">
                                    <!-- Background circle -->
                                    <circle class="rating-ring-bg" cx="60" cy="60" r="<?= $circleRadius ?>" fill="none"
                                        stroke="white" stroke-width="10" />
                                    <!-- Progress circle -->
                                    <circle class="rating-ring-circle" cx="60" cy="60" r="<?= $circleRadius ?>" fill="none"
                                        stroke="<?php
                                        if ($overallPct >= 90)
                                            echo '#10b981';
                                        elseif ($overallPct >= 80)
                                            echo '#3b82f6';
                                        elseif ($overallPct >= 70)
                                            echo '#06b6d4';
                                        elseif ($overallPct >= 60)
                                            echo '#f59e0b';
                                        else
                                            echo '#ef4444';
                                        ?>" stroke-width="10" stroke-linecap="round"
                                        stroke-dasharray="<?= $circleCircumference ?>"
                                        stroke-dashoffset="<?= $circleCircumference ?>"
                                        data-target-offset="<?= $circleOffset ?>" />
                                </svg>
                                <!-- Center text -->
                                <div class="absolute inset-0 flex flex-col items-center justify-center">
                                    <span class="text-3xl font-black tracking-tight" id="ratingPctDisplay">0</span>
                                    <span
                                        class="text-[10px] text-slate-300 font-semibold uppercase tracking-wider">Rating</span>
                                </div>
                            </div>
                        </div>

                        <!-- Grade & Core Stats -->
                        <div class="md:col-span-4 space-y-4">
                            <!-- Grade Badge -->
                            <div class="flex items-center gap-3">
                                <span class="text-2xl"><?= $gradeIcon ?></span>
                                <div>
                                    <span class="grade-badge inline-block px-4 py-1.5 rounded-lg text-sm font-extrabold
                                    <?php
                                    echo match ($gradeColor) {
                                        'emerald' => 'bg-emerald-500/20 text-emerald-300 border border-emerald-400/30',
                                        'blue' => 'bg-blue-500/20 text-blue-300 border border-blue-400/30',
                                        'cyan' => 'bg-cyan-500/20 text-cyan-300 border border-cyan-400/30',
                                        'amber' => 'bg-amber-500/20 text-amber-300 border border-amber-400/30',
                                        'red' => 'bg-red-500/20 text-red-300 border border-red-400/30',
                                        default => 'bg-slate-500/20 text-slate-300 border border-slate-400/30',
                                    };
                                    ?>
                                "><?= $grade ?></span>
                                    <p class="text-[10px] text-slate-400 mt-1"><?= $LANG['performance_grade'] ?? 'Performance Grade' ?></p>
                                </div>
                            </div>

                            <!-- Score breakdown -->
                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div class="bg-white/5 backdrop-blur rounded-lg p-3 border border-white/10">
                                    <p class="text-slate-400 font-semibold mb-0.5"><?= $LANG['total_responses'] ?? 'Total Responses' ?></p>
                                    <p class="text-lg font-black text-white"><?= $completedCount ?></p>
                                </div>
                                <div class="bg-white/5 backdrop-blur rounded-lg p-3 border border-white/10">
                                    <p class="text-slate-400 font-semibold mb-0.5"><?= $LANG['rating_questions'] ?? 'Rating Questions' ?></p>
                                    <p class="text-lg font-black text-white"><?= $numRatingQuestions ?></p>
                                </div>
                                <div class="bg-white/5 backdrop-blur rounded-lg p-3 border border-white/10 col-span-2">
                                    <p class="text-slate-400 font-semibold mb-0.5"><?= $LANG['scoring_scale'] ?? 'Scoring Scale' ?></p>
                                    <p class="text-[10px] text-slate-300 leading-relaxed"><?= $LANG['good'] ?? 'Good' ?> = 5
                                        points · <?= $LANG['fair'] ?? 'Fair' ?> = 3 points · <?= $LANG['bad'] ?? 'Bad' ?> = 1
                                        point</p>
                                </div>
                            </div>
                        </div>

                        <!-- <?= $LANG['rating_distribution'] ?? 'Rating Distribution' ?> Bars -->
                        <div class="md:col-span-5 space-y-3">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1"><?= $LANG['rating_distribution'] ?? 'Rating Distribution' ?>
                            </p>

                            <!-- Good -->
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-semibold text-emerald-300 flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span>
                                        <?= $LANG['good'] ?? 'Good' ?>
                                    </span>
                                    <span class="text-slate-300 font-bold"><?= $totalGood ?> <span
                                            class="text-slate-500 font-normal">(<?= $aggGoodPct ?>%)</span></span>
                                </div>
                                <div class="w-full bg-white/10 rounded-full h-2.5 overflow-hidden">
                                    <div class="progress-bar-fill bg-gradient-to-r from-emerald-500 to-emerald-400 h-full rounded-full"
                                        style="width: 0%" data-target-width="<?= $aggGoodPct ?>%"></div>
                                </div>
                            </div>

                            <!-- Fair -->
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-semibold text-amber-300 flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full bg-amber-400 inline-block"></span>
                                        <?= $LANG['fair'] ?? 'Fair' ?>
                                    </span>
                                    <span class="text-slate-300 font-bold"><?= $totalFair ?> <span
                                            class="text-slate-500 font-normal">(<?= $aggFairPct ?>%)</span></span>
                                </div>
                                <div class="w-full bg-white/10 rounded-full h-2.5 overflow-hidden">
                                    <div class="progress-bar-fill bg-gradient-to-r from-amber-500 to-amber-400 h-full rounded-full"
                                        style="width: 0%" data-target-width="<?= $aggFairPct ?>%"></div>
                                </div>
                            </div>

                            <!-- Poor/Bad -->
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-semibold text-red-300 flex items-center gap-1.5">
                                        <span class="w-2 h-2 rounded-full bg-red-400 inline-block"></span>
                                        <?= $LANG['bad'] ?? 'Bad' ?>
                                    </span>
                                    <span class="text-slate-300 font-bold"><?= $totalBad ?> <span
                                            class="text-slate-500 font-normal">(<?= $aggBadPct ?>%)</span></span>
                                </div>
                                <div class="w-full bg-white/10 rounded-full h-2.5 overflow-hidden">
                                    <div class="progress-bar-fill bg-gradient-to-r from-red-500 to-red-400 h-full rounded-full"
                                        style="width: 0%" data-target-width="<?= $aggBadPct ?>%"></div>
                                </div>
                            </div>

                            <!-- Scale legend -->
                            <div class="flex items-center gap-1 mt-2 pt-2 border-t border-white/10">
                                <div
                                    class="flex-1 h-1.5 rounded-full bg-gradient-to-r from-red-500 via-amber-500 to-emerald-500">
                                </div>
                                <div class="flex justify-between w-full text-[9px] text-slate-500 mt-0.5">
                                    <span>0%</span>
                                    <span>50%</span>
                                    <span>100%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="w-full mx-auto myanmar-font">
        <div class="bg-white shadow-md rounded-xl border border-slate-200 p-6 md:p-8 no-print">

            <div class="text-center border-b-2 border-slate-800 pb-4 mb-5">
                <h2 class="text-lg md:text-xl font-bold text-slate-900 mb-1"><?= e($form['title']) ?></h2>
                <p class="text-xs text-slate-400 font-mono">
                    <?= $LANG['statistical_report'] ?? 'Statistical Evaluation Report Matrix (Anonymous)' ?>
                </p>
                <p class="text-xs text-slate-400 mt-1"><?= $LANG['feedback_period'] ?? 'Feedback Period' ?>:
                    <?= formatDateTime($form['start_date']) ?> —
                    <?= formatDateTime($form['end_date']) ?>
                </p>
            </div>

            <div
                class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6 bg-slate-50 p-4 rounded-xl border border-slate-200 text-xs">
                <div>
                    <span class="font-bold text-slate-500"><?= $LANG['academic_year_label'] ?? 'Academic Year:' ?></span>
                    <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800">
                        <?= e($form['academic_year']) ?>
                    </div>
                </div>
                <div>
                    <span class="font-bold text-slate-500"><?= $LANG['semester_label'] ?? 'Semester:' ?></span>
                    <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800">
                        <?= e(formatSemester($form['semester'])) ?>
                    </div>
                </div>
                <div>
                    <span class="font-bold text-slate-500"><?= $LANG['course_label_subject'] ?? 'Subject:' ?></span>
                    <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800 font-mono">
                        <?= e($form['course_code']) ?> (<?= e($form['course_name']) ?>)
                    </div>
                </div>
                <div>
                    <span class="font-bold text-slate-500"><?= $LANG['teacher_label_name'] ?? 'Teacher:' ?></span>
                    <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800">
                        <?= e($form['teacher_name']) ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($ratingQuestions)): ?>
                <div class="mb-8">
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">
                        <?= $LANG['question_stats_header'] ?? 'မေးခွန်းအလိုက် စာရင်းဇယား ရလဒ်များ' ?>
                    </h3>
                    <div class="overflow-x-auto border border-slate-300 rounded-lg">
                        <table class="w-full text-left border-collapse min-w-[650px] text-xs">
                            <thead>
                                <tr class=" text-white font-bold">
                                    <th class="bg-blue-300 p-3 w-12 text-center text-sm font-semibold"><?= $LANG['col_no'] ?? 'စဉ်' ?></th>
                                    <th class="p-3 bg-blue-500 text-sm font-semibold"><?= $LANG['eval_questions_header'] ?? 'Evaluation Questions' ?></th>
                                    <th class="p-3 w-28 text-center bg-emerald-700 text-sm font-semibold">
                                        <div><?= $LANG['good'] ?? 'Good' ?></div>
                                        <div class="text-[10px] font-normal"><?= $LANG['count_pct_header'] ?? 'COUNT / %' ?>
                                        </div>
                                    </th>
                                    <th class="p-3 w-28 text-center bg-amber-600 text-sm font-semibold">
                                        <div><?= $LANG['fair'] ?? 'Fair' ?></div>
                                        <div class="text-[10px] font-normal"><?= $LANG['count_pct_header'] ?? 'COUNT / %' ?>
                                        </div>
                                    </th>
                                    <th class="p-3 w-28 text-center bg-red-700 text-sm font-semibold">
                                        <div><?= $LANG['bad'] ?? 'Bad' ?></div>
                                        <div class="text-[10px] font-normal"><?= $LANG['count_pct_header'] ?? 'COUNT / %' ?>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 text-slate-800 font-medium">
                                <?php foreach ($ratingQuestions as $q):
                                    $goodCount = $ratingStats[$q['id']]['Good'] ?? 0;
                                    $normalCount = $ratingStats[$q['id']]['Fair'] ?? 0;
                                    $badCount = $ratingStats[$q['id']]['Bad'] ?? 0;
                                    $totalVotes = $goodCount + $normalCount + $badCount;

                                    $goodPerc = $totalVotes > 0 ? round(($goodCount / $totalVotes) * 100) : 0;
                                    $normalPerc = $totalVotes > 0 ? round(($normalCount / $totalVotes) * 100) : 0;
                                    $badPerc = $totalVotes > 0 ? round(($badCount / $totalVotes) * 100) : 0;
                                    ?>
                                    <tr class="hover:bg-slate-50/60 transition-colors">
                                        <td class="p-3 text-center font-bold font-mono border-r"><?= e($q['question_no']) ?></td>
                                        <td class="p-3 border-r leading-relaxed"><?= e($q['question_text']) ?></td>

                                        <td class="p-3 text-center border-r bg-emerald-50/30">
                                            <span class="text-emerald-700 font-bold block text-sm"><?= $goodCount ?>
                                                <?= $LANG['persons'] ?? 'persons' ?></span>
                                            <span class="text-[10px] text-slate-500">(<?= $goodPerc ?>%)</span>
                                        </td>
                                        <td class="p-3 text-center border-r bg-amber-50/30">
                                            <span class="text-amber-700 font-bold block text-sm"><?= $normalCount ?>
                                                <?= $LANG['persons'] ?? 'persons' ?></span>
                                            <span class="text-[10px] text-slate-500">(<?= $normalPerc ?>%)</span>
                                        </td>
                                        <td class="p-3 text-center bg-red-50/30">
                                            <span class="text-red-700 font-bold block text-sm"><?= $badCount ?>
                                                <?= $LANG['persons'] ?? 'persons' ?></span>
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
                <div class="space-y-6 pt-6 border-t-2 border-slate-300">
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">
                        <?= $LANG['comments_header'] ?? 'အကြံပြုချက်များနှင့် မှတ်ချက်များစုစည်းမှု (Comments Box)' ?>
                    </h3>
                    <?php foreach ($commentQuestions as $q):
                        $commentsForThisQuestion = $allComments[$q['id']] ?? [];
                        ?>
                        <div class="space-y-2 text-xs">
                            <label class="block font-bold text-slate-700 text-sm">
                                <?= e($q['question_no']) ?>။ <?= e($q['question_text']) ?>
                                <span
                                    class="text-slate-400 font-normal text-xs">(<?= $LANG['total_comments_label'] ?? 'Total comments' ?>
                                    -
                                    <?= count($commentsForThisQuestion) ?> ခု)</span>
                            </label>
                            <div
                                class="w-full bg-slate-50 border border-slate-200 p-4 rounded-xl space-y-2.5 max-h-[300px] overflow-y-auto">
                                <?php if (!empty($commentsForThisQuestion)): ?>
                                    <?php foreach ($commentsForThisQuestion as $index => $commentText): ?>
                                        <div class="bg-white border border-slate-100 p-3 rounded-lg shadow-2xs flex items-start gap-2">
                                            <span class="text-slate-400 font-bold">#<?= $index + 1 ?></span>
                                            <div class="text-slate-800 font-medium whitespace-pre-wrap"><?= e($commentText) ?></div>
                                        </div>
                                    <?php endforeach ?>
                                <?php else: ?>
                                    <div class="text-slate-400 italic text-center py-4">—
                                        <?= $LANG['no_comments'] ?? 'No comments written for this question' ?>
                                        —
                                    </div>
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
                        $qStats = $surveyStats[$q['id']] ?? [];
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

            <div class="mt-8 pt-4 border-t border-slate-100 text-center text-[11px] text-blue-500 font-semibold italic">
                "ဤအစီရင်ခံစာသည် ကျောင်းသားများ၏ ကိုယ်ရေးအချက်အလက်ကို ထိန်းသိမ်းထားသော အလိုအလျောက် စာရင်းအင်းစနစ်ဖြစ်ပါသည်။"
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <!-- PROFESSIONAL PRINTABLE REPORT (print-only, hidden on screen) -->
    <!-- ═══════════════════════════════════════════════════════════════════════ -->
    <div class="print-only myanmar-font" style="max-width: 100%;">
        <!-- University Header -->
        <div class="print-report-header">
            <h1>University of Computer Studies(Hinthada)</h1>
            <h2>Student Feedback Evaluation Report</h2>
            <p>Confidential — For Internal Academic Use Only</p>
        </div>

        <!-- Teacher & Subject Information -->
        <div class="print-section">
            <div class="print-section-title"><?= $LANG['teacher_label'] ?? 'Teacher' ?> & <?= $LANG['subject_filter'] ?? 'Subject' ?> <?= $LANG['col_actions'] ?? 'Information' ?></div>
            <dl class="print-info-grid">
                <dt>Academic Year:</dt>
                <dd><?= e($form['academic_year']) ?></dd>
                <dt>Semester:</dt>
                <dd><?= e(formatSemester($form['semester'])) ?></dd>
                <dt>Subject:</dt>
                <dd><?= e($form['course_code']) ?> — <?= e($form['course_name']) ?></dd>
                <dt>Teacher:</dt>
                <dd><?= e($form['teacher_name']) ?></dd>
                <dt>Section:</dt>
                <dd><?= e($form['section']) ?></dd>
                <dt>Feedback Period:</dt>
                <dd><?= formatDateTime($form['start_date']) ?> — <?= formatDateTime($form['end_date']) ?></dd>
                <dt>Total Students:</dt>
                <dd><?= $totalStudents ?></dd>
                <dt><?= $LANG['total_responses'] ?? 'Total Responses' ?>:</dt>
                <dd><?= $completedCount ?> (<?= $totalStudents > 0 ? round(($completedCount / $totalStudents) * 100) : 0 ?>%
                    response rate)</dd>
            </dl>
        </div>

        <!-- Overall Teacher Rating Summary -->
        <?php if (!empty($ratingQuestions) && $completedCount > 0): ?>
            <div class="print-section">
                <div class="print-section-title"><?= $LANG['overall_teacher_rating'] ?? 'Overall Teacher Rating' ?> — <?= $LANG['total'] ?? 'Total' ?></div>
                <div class="print-rating-summary">
                    <div class="print-rating-item">
                        <div class="value"><?= $overallPct ?>%</div>
                        <div class="label">Overall Rating</div>
                    </div>
                    <div class="print-rating-item">
                        <div class="value"><span class="print-grade-badge"><?= $grade ?></span></div>
                        <div class="label" style="margin-top:6px;"><?= $LANG['performance_grade'] ?? 'Performance Grade' ?></div>
                    </div>

                </div>
                <div style="display:flex; justify-content:center; gap:24px; font-size:9pt; margin-top:8px; color:#475569;">
                    <span>🟢 <?= $LANG['good'] ?? 'Good' ?>: <?= $totalGood ?> (<?= $aggGoodPct ?>%)</span>
                    <span>🟡 <?= $LANG['fair'] ?? 'Fair' ?>: <?= $totalFair ?> (<?= $aggFairPct ?>%)</span>
                    <span>🔴 <?= $LANG['bad'] ?? 'Bad' ?>: <?= $totalBad ?> (<?= $aggBadPct ?>%)</span>
                </div>
                <div style="font-size:8pt; color:#94a3b8; text-align:center; margin-top:4px;">
                    <?= $LANG['good'] ?? 'Good' ?> = 5 pts · <?= $LANG['fair'] ?? 'Fair' ?> = 3 pts ·
                    <?= $LANG['bad'] ?? 'Bad' ?> = 1 pt &nbsp;|&nbsp; <?= $LANG['rating_questions'] ?? 'Rating Questions' ?>: <?= $numRatingQuestions ?> &nbsp;|&nbsp;
                    Survey questions excluded from rating
                </div>
            </div>
        <?php endif ?>

        <!-- <?= $LANG['rating_questions'] ?? 'Rating Questions' ?> Result Table -->
        <?php if (!empty($ratingQuestions)): ?>
            <div class="print-section">
                <div class="print-section-title"><?= $LANG['rating_questions'] ?? 'Rating Questions' ?> — Detailed Results</div>
                <table class="print-table">
                    <thead>
                        <tr>
                            <th style="width:40px;"><?= $LANG['col_no'] ?? 'စဉ်' ?></th>
                            <!-- <th style="text-align:left;">Evaluation Question</th> -->
                            <th style="text-align:left;"><?= $LANG['eval_questions_header'] ?? 'Evaluation Questions' ?></th>
                            <th style=" width:80px;"><?= $LANG['good'] ?? 'Good' ?></th>
                            <th style="width:80px;"><?= $LANG['fair'] ?? 'Fair' ?></th>
                            <th style="width:80px;"><?= $LANG['bad'] ?? 'Bad' ?></th>
                            <th style="width:60px;"><?= $LANG['total'] ?? 'Total' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ratingQuestions as $q):
                            $gc = $ratingStats[$q['id']]['Good'] ?? 0;
                            $fc = $ratingStats[$q['id']]['Fair'] ?? 0;
                            $bc = $ratingStats[$q['id']]['Bad'] ?? 0;
                            $tv = $gc + $fc + $bc;
                            $gp = $tv > 0 ? round(($gc / $tv) * 100) : 0;
                            $fp = $tv > 0 ? round(($fc / $tv) * 100) : 0;
                            $bp = $tv > 0 ? round(($bc / $tv) * 100) : 0;
                            ?>
                            <tr>
                                <td style="text-align:center; font-weight:700;"><?= e($q['question_no']) ?></td>
                                <td style="text-align:left;"><?= e($q['question_text']) ?></td>
                                <td style="text-align:center;"><?= $gc ?> (<?= $gp ?>%)</td>
                                <td style="text-align:center;"><?= $fc ?> (<?= $fp ?>%)</td>
                                <td style="text-align:center;"><?= $bc ?> (<?= $bp ?>%)</td>
                                <td style="text-align:center; font-weight:700;"><?= $tv ?></td>
                            </tr>
                        <?php endforeach ?>
                        <tr
                            style="font-weight:700; background:#e2e8f0 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                            <td colspan="2" style="text-align:right; padding-right:12px;"><?= $LANG['total'] ?? 'TOTALS:' ?>:
                            </td>
                            <td style="text-align:center;"><?= $totalGood ?> (<?= $aggGoodPct ?>%)</td>
                            <td style="text-align:center;"><?= $totalFair ?> (<?= $aggFairPct ?>%)</td>
                            <td style="text-align:center;"><?= $totalBad ?> (<?= $aggBadPct ?>%)</td>
                            <td style="text-align:center;"><?= $totalRatingResponses ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif ?>

        <!-- <?= $LANG['survey_results'] ?? 'Survey Results' ?> with Doughnut Charts -->
        <?php if (!empty($surveyQuestions)): ?>
            <div class="print-section">
                <div class="print-section-title"><?= $LANG['survey_results'] ?? 'Survey Results' ?></div>
                <div style="font-size:8pt; color:#64748b; margin-bottom:10px;"><?= $LANG['not_in_overall'] ?? 'Not included in Overall Rating' ?> calculation.</div>

                <?php foreach ($surveyQuestions as $q):
                    $opts = json_decode($q['options_json'] ?? '[]', true) ?: [];
                    $qStats = $surveyStats[$q['id']] ?? [];
                    $mostSelected = getMostSelectedSurveyOptions($qStats);
                    $totalVotes = $mostSelected['total'];
                    $doughnutColors = ['#7c3aed', '#2563eb', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#0891b2', '#c026d3'];
                    $pColors = [];
                    $pLabels = [];
                    $pValues = [];
                    foreach ($opts as $idx => $opt) {
                        $pColors[] = $doughnutColors[$idx % count($doughnutColors)];
                        $pLabels[] = addslashes($opt);
                        $pValues[] = (int)($qStats[$idx] ?? 0);
                    }
                    ?>
                    <div style="margin-bottom:16px; page-break-inside:avoid;">
                        <div style="display:flex; align-items:flex-start; gap:12px;">
                            <!-- Doughnut chart -->
                            <div style="width:120px; height:120px; flex-shrink:0; position:relative;">
                                <canvas id="printChart_<?= $q['id'] ?>" width="120" height="120"></canvas>
                                <div style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center;">
                                    <span style="font-size:16pt; font-weight:800; color:#0f172a; line-height:1;"><?= $totalVotes ?></span>
                                    <span style="font-size:6pt; color:#64748b; text-transform:uppercase; font-weight:600;">Total</span>
                                </div>
                            </div>
                            <!-- Question + options -->
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:9.5pt; font-weight:700; color:#0f172a; margin-bottom:4px;">
                                    Q<?= e($q['question_no']) ?>. <?= e($q['question_text']) ?>
                                    <span style="font-weight:400; color:#64748b; font-size:7.5pt;">(<?= $totalVotes ?> <?= $totalVotes === 1 ? ($LANG['response'] ?? 'response') : ($LANG['responses'] ?? 'responses') ?>)</span>
                                </div>
                                <?php foreach ($opts as $idx => $opt):
                                    $votes = $qStats[$idx] ?? 0;
                                    $pct = $totalVotes > 0 ? round(($votes / $totalVotes) * 100) : 0;
                                    $isMost = in_array($idx, $mostSelected['indices']);
                                    ?>
                                    <div style="display:flex; align-items:center; gap:6px; margin-bottom:3px; font-size:8.5pt;">
                                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?= $pColors[$idx] ?>; flex-shrink:0;"></span>
                                        <span style="flex:1; color:#334155;<?= $isMost ? 'font-weight:700;' : '' ?>"><?= e($opt) ?><?= $isMost ? ' ✓' : '' ?></span>
                                        <span style="color:#64748b; font-size:8pt; white-space:nowrap;"><?= $votes ?> <?= $votes !== 1 ? ($LANG['votes'] ?? 'votes') : ($LANG['vote'] ?? 'vote') ?></span>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>

            <script>
            document.addEventListener('DOMContentLoaded', function() {
                <?php foreach ($surveyQuestions as $q):
                    $opts = json_decode($q['options_json'] ?? '[]', true) ?: [];
                    $qStats = $surveyStats[$q['id']] ?? [];
                    $doughnutColors = ['#7c3aed', '#2563eb', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#0891b2', '#c026d3'];
                    $pLabels = [];
                    $pValues = [];
                    $pColors = [];
                    foreach ($opts as $idx => $opt) {
                        $pLabels[] = addslashes($opt);
                        $pValues[] = (int)($qStats[$idx] ?? 0);
                        $pColors[] = $doughnutColors[$idx % count($doughnutColors)];
                    }
                ?>
                (function() {
                    var c = document.getElementById('printChart_<?= $q['id'] ?>');
                    if (!c) return;
                    new Chart(c.getContext('2d'), {
                        type: 'doughnut',
                        data: {
                            labels: <?= json_encode($pLabels) ?>,
                            datasets: [{ data: <?= json_encode($pValues) ?>, backgroundColor: <?= json_encode($pColors) ?>, borderColor: '#fff', borderWidth: 2 }]
                        },
                        options: { responsive: false, maintainAspectRatio: false, cutout: '62%', plugins: { legend: { display: false }, tooltip: { enabled: false } }, animation: false }
                    });
                })();
                <?php endforeach; ?>
            });
            </script>
        <?php endif ?>

        <!-- Student Comments -->
        <?php if (!empty($commentQuestions)): ?>
            <?php
            $hasAnyComments = false;
            foreach ($commentQuestions as $cq) {
                if (!empty($allComments[$cq['id']])) {
                    $hasAnyComments = true;
                    break;
                }
            }
            ?>
            <div class="print-section">
                <div class="print-section-title"><?= $LANG['comments_suggestions'] ?? 'Student Comments' ?></div>
                <?php if ($hasAnyComments): ?>
                    <?php foreach ($commentQuestions as $cq):
                        $commentsForQ = $allComments[$cq['id']] ?? [];
                        if (empty($commentsForQ))
                            continue;
                        ?>
                        <div style="margin-bottom:10px; page-break-inside:avoid;">
                            <div style="font-size:9pt; font-weight:700; color:#0f172a; margin-bottom:4px;">
                                Q<?= e($cq['question_no']) ?>. <?= e($cq['question_text']) ?>
                                <span style="font-weight:400; color:#64748b; font-size:7.5pt;">(<?= count($commentsForQ) ?>
                                    comments)</span>
                            </div>
                            <?php foreach ($commentsForQ as $idx => $commentText): ?>
                                <div
                                    style="font-size:8.5pt; color:#334155; padding:4px 0 4px 12px; border-left:2px solid #e2e8f0; margin-bottom:4px;">
                                    <span style="color:#94a3b8; font-weight:600;">#<?= $idx + 1 ?></span> <?= e($commentText) ?>
                                </div>
                            <?php endforeach ?>
                        </div>
                    <?php endforeach ?>
                <?php else: ?>
                    <p style="font-size:9pt; color:#64748b; font-style:italic;">No comments submitted.</p>
                <?php endif ?>
            </div>
        <?php endif ?>

        <!-- Conclusion / Recommendation -->
        <div class="print-section">
            <div class="print-section-title"><?= $LANG['col_actions'] ?? 'Conclusion' ?> & <?= $LANG['col_actions'] ?? 'Recommendation' ?></div>
            <div class="print-conclusion">
                <strong>Grade: <?= $grade ?> (<?= $overallPct ?>%)</strong><br><br>
                <?= $conclusionText ?>
            </div>
        </div>

        <!-- Signature Lines -->
        <div style="display:flex; justify-content:space-between; margin-top:40px; font-size:9pt; color:#334155;">
            <div style="text-align:center; width:200px;">
                <div style="border-top:1px solid #334155; padding-top:4px;">Prepared By</div>
            </div>
            <div style="text-align:center; width:200px;">
                <div style="border-top:1px solid #334155; padding-top:4px;">Head of Department</div>
            </div>
            <div style="text-align:center; width:220px;">
                <div style="border-top:1px solid #334155; padding-top:4px;">Vice Rector</div>
                <div style="font-size:7.5pt; color:#64748b; margin-top:2px;">University of Computer Studies (Hinthada)</div>
            </div>
        </div>

        <!-- Footer -->
        <div class="print-footer">
            Generated by Student Feedback Management System (SFMS) — University of Computer Studies(Hinthada) —
            <?= date('F d, Y') ?>
        </div>
    </div>
<?php endif ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Animate circular progress ring
        const circle = document.querySelector('.rating-ring-circle');
        if (circle) {
            const targetOffset = parseFloat(circle.getAttribute('data-target-offset'));
            setTimeout(() => {
                circle.style.strokeDashoffset = targetOffset;
            }, 300);
        }

        // Animate rating percentage number
        const pctDisplay = document.getElementById('ratingPctDisplay');
        if (pctDisplay) {
            const targetPct = <?= json_encode($overallPct) ?>;
            let current = 0;
            const duration = 1500;
            const startTime = performance.now();

            function animateNumber(currentTime) {
                const elapsed = currentTime - startTime;
                const progress = Math.min(elapsed / duration, 1);
                const eased = 1 - Math.pow(1 - progress, 3);
                current = (eased * targetPct).toFixed(1);
                pctDisplay.textContent = current + '%';
                if (progress < 1) {
                    requestAnimationFrame(animateNumber);
                }
            }
            setTimeout(() => requestAnimationFrame(animateNumber), 300);
        }

        // Animate progress bars
        document.querySelectorAll('.progress-bar-fill[data-target-width]').forEach(bar => {
            const targetWidth = bar.getAttribute('data-target-width');
            setTimeout(() => {
                bar.style.width = targetWidth;
            }, 500);
        });

        // Survey doughnut charts (Microsoft Forms style)
        <?php if (!empty($surveyQuestions)): ?>
        const chartDefaults = {
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
        $surveyChartData = [];
        foreach ($surveyQuestions as $q) {
            $opts = json_decode($q['options_json'] ?? '[]', true) ?: [];
            $qStats = $surveyStats[$q['id']] ?? [];
            $doughnutColors = ['#7c3aed', '#2563eb', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#0891b2', '#c026d3'];
            $labels = [];
            $data = [];
            $colors = [];
            foreach ($opts as $idx => $opt) {
                $labels[] = addslashes($opt);
                $data[] = (int)($qStats[$idx] ?? 0);
                $colors[] = $doughnutColors[$idx % count($doughnutColors)];
            }
            $surveyChartData[$q['id']] = [
                'labels' => $labels,
                'data' => $data,
                'colors' => $colors,
            ];
        }
        ?>
        var surveyCharts = <?= json_encode($surveyChartData) ?>;

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

<?php include '../includes/admin_footer.php'; ?>