<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

updateAllFeedbackStatuses($conn);

// AJAX endpoint for student lists
if (isset($_GET['ajax_students']) && $_GET['ajax_students'] === '1') {
    header('Content-Type: application/json');
    $ajaxFormId = (int) ($_GET['form_id'] ?? 0);
    $ajaxType = $_GET['type'] ?? '';
    if (!$ajaxFormId || !in_array($ajaxType, ['all', 'completed', 'pending'])) {
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }
    $af = $conn->prepare("SELECT * FROM feedback_forms WHERE id=?");
    $af->bind_param('i', $ajaxFormId);
    $af->execute();
    $afRow = $af->get_result()->fetch_assoc();
    $af->close();
    if (!$afRow) {
        echo json_encode(['error' => 'Form not found']);
        exit;
    }

    $aMod = $afRow['module'];
    $allList = [];
    $doneList = [];

    if ($aMod === 'academic' && !empty($afRow['section_id'])) {
        $getSectionId = $afRow['section_id'];
        $q1 = $conn->prepare("SELECT st.id, u.name, st.roll_no FROM students st JOIN users u ON st.user_id=u.id JOIN section_assignments sa ON sa.student_id=st.id WHERE sa.section_id=?");
        $q1->bind_param('i', $getSectionId);
        $q1->execute();
        $allList = $q1->get_result()->fetch_all(MYSQLI_ASSOC);
        $q1->close();

        $q2 = $conn->prepare("SELECT st.id, u.name, st.roll_no, fs.submitted_at FROM students st JOIN users u ON st.user_id=u.id JOIN section_assignments sa ON sa.student_id=st.id JOIN feedback_submissions fs ON fs.student_id=st.id WHERE sa.section_id=? AND fs.form_id=?");
        $q2->bind_param('ii', $getSectionId, $ajaxFormId);
        $q2->execute();
        $doneList = $q2->get_result()->fetch_all(MYSQLI_ASSOC);
        $q2->close();
    } else {
        $q1 = $conn->prepare("SELECT DISTINCT st.id, u.name, st.roll_no FROM students st JOIN users u ON st.user_id=u.id JOIN section_assignments sa ON sa.student_id=st.id JOIN sections sec ON sa.section_id=sec.id WHERE sec.academic_year_id=? AND sec.semester_id=?");
        $q1->bind_param('ii', $afRow['academic_year_id'], $afRow['semester_id']);
        $q1->execute();
        $allList = $q1->get_result()->fetch_all(MYSQLI_ASSOC);
        $q1->close();

        $q2 = $conn->prepare("SELECT DISTINCT st.id, u.name, st.roll_no, fs.submitted_at FROM students st JOIN users u ON st.user_id=u.id JOIN feedback_submissions fs ON fs.student_id=st.id WHERE fs.form_id=?");
        $q2->bind_param('i', $ajaxFormId);
        $q2->execute();
        $doneList = $q2->get_result()->fetch_all(MYSQLI_ASSOC);
        $q2->close();
    }

    // Sort by CS first, then CT, then others; within each group sort by numeric suffix
    usort($allList, function ($a, $b) {
        $ra = strtoupper($a['roll_no'] ?? '');
        $rb = strtoupper($b['roll_no'] ?? '');
        $ga = str_contains($ra, 'CS') ? 0 : (str_contains($ra, 'CT') ? 1 : 2);
        $gb = str_contains($rb, 'CS') ? 0 : (str_contains($rb, 'CT') ? 1 : 2);
        if ($ga !== $gb)
            return $ga - $gb;
        $na = (int) substr($a['roll_no'], strrpos($a['roll_no'], '-') + 1);
        $nb = (int) substr($b['roll_no'], strrpos($b['roll_no'], '-') + 1);
        return $na - $nb;
    });
    usort($doneList, function ($a, $b) {
        $ra = strtoupper($a['roll_no'] ?? '');
        $rb = strtoupper($b['roll_no'] ?? '');
        $ga = str_contains($ra, 'CS') ? 0 : (str_contains($ra, 'CT') ? 1 : 2);
        $gb = str_contains($rb, 'CS') ? 0 : (str_contains($rb, 'CT') ? 1 : 2);
        if ($ga !== $gb)
            return $ga - $gb;
        $na = (int) substr($a['roll_no'], strrpos($a['roll_no'], '-') + 1);
        $nb = (int) substr($b['roll_no'], strrpos($b['roll_no'], '-') + 1);
        return $na - $nb;
    });

    $doneIds = array_column($doneList, 'id');
    $pendList = [];
    foreach ($allList as $s) {
        if (!in_array($s['id'], $doneIds))
            $pendList[] = $s;
    }

    $result = match ($ajaxType) {
        'all' => $allList,
        'completed' => $doneList,
        'pending' => $pendList,
    };
    echo json_encode($result);
    exit;
}

// AJAX endpoint for fetching forms by AY/Sem
if (isset($_GET['ajax_forms']) && $_GET['ajax_forms'] === '1') {
    header('Content-Type: application/json');
    $ajaxAY = (int) ($_GET['ay_id'] ?? 0);
    $ajaxSem = (int) ($_GET['sem_id'] ?? 0);

    $ajaxFormConds = [];
    $ajaxFormTypes = '';
    if ($ajaxAY) {
        $ajaxFormConds[] = "ff.academic_year_id=?";
        $ajaxFormTypes .= 'i';
    }
    if ($ajaxSem) {
        $ajaxFormConds[] = "ff.semester_id=?";
        $ajaxFormTypes .= 'i';
    }
    $ajaxFormWhere = $ajaxFormConds ? 'WHERE ' . implode(' AND ', $ajaxFormConds) : '';
    $ajaxFormSql = "SELECT ff.id, ff.title, ff.module, ff.section_id, ff.academic_year_id, ff.semester_id,
        ay.year_name AS academic_year_name, sm.semester_name,
        c.course_code, c.course_name, sec.section AS section_name
        FROM feedback_forms ff
        LEFT JOIN academic_years ay ON ff.academic_year_id = ay.id
        LEFT JOIN semesters sm ON ff.semester_id = sm.id
        LEFT JOIN sections sec ON ff.section_id = sec.id
        LEFT JOIN courses c ON sec.course_id = c.id
        $ajaxFormWhere
        ORDER BY ff.module ASC, ay.year_name DESC, ff.id DESC";

    if ($ajaxFormTypes) {
        $ajaxFormStmt = $conn->prepare($ajaxFormSql);
        $ajaxFormBind = [];
        if ($ajaxAY) $ajaxFormBind[] = $ajaxAY;
        if ($ajaxSem) $ajaxFormBind[] = $ajaxSem;
        $ajaxFormStmt->bind_param($ajaxFormTypes, ...$ajaxFormBind);
        $ajaxFormStmt->execute();
        $ajaxFormList = $ajaxFormStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ajaxFormStmt->close();
    } else {
        $ajaxFormList = $conn->query($ajaxFormSql)->fetch_all(MYSQLI_ASSOC);
    }

    $formattedForms = [];
    foreach ($ajaxFormList as $f) {
        if ($f['module'] === 'academic' && !empty($f['course_code'])) {
            $formLabel = ($f['course_code'] ?? '') . ' - ' . ($f['course_name'] ?? '') . ' - Section ' . ($f['section_name'] ?? '');
        } else {
            $formLabel = ($f['academic_year_name'] ?? '') . ' - ' . ($f['title'] ?? '');
        }
        $formattedForms[] = [
            'id' => (int) $f['id'],
            'label' => $formLabel,
            'module' => $f['module'],
        ];
    }

    echo json_encode(['forms' => $formattedForms]);
    exit;
}

$pageTitle = $LANG['all_feedback_results'] ?? 'All Feedback Results';
$activeMenu = 'results';

$formId = (int) ($_GET['form_id'] ?? 0);
$filterMod = clean($_GET['module'] ?? '');
$filterAY = (int) ($_GET['ay_id'] ?? 0);
$filterSem = (int) ($_GET['sem_id'] ?? 0);
$hasActiveFilters = ($filterAY > 0) || ($filterSem > 0) || ($formId > 0);

$academicYears = $conn->query("SELECT id, year_name FROM academic_years WHERE status='active' ORDER BY year_name DESC")->fetch_all(MYSQLI_ASSOC);
$semesters = $conn->query("SELECT id, semester_name FROM semesters ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

// Form list — filtered by AY/Sem when selected, with section/course info for academic forms
$formConds = [];
$formParams = '';
$formTypes = '';
if ($filterAY) {
    $formConds[] = "ff.academic_year_id=?";
    $formParams .= 'i';
    $formTypes .= 'i';
}
if ($filterSem) {
    $formConds[] = "ff.semester_id=?";
    $formParams .= 'i';
    $formTypes .= 'i';
}
$formWhere = $formConds ? 'WHERE ' . implode(' AND ', $formConds) : '';
$formSql = "SELECT ff.id, ff.title, ff.module, ff.section_id, ff.academic_year_id, ff.semester_id, ff.university_name, ff.university_campus,
    ay.year_name AS academic_year_name, sm.semester_name,
    c.course_code, c.course_name, sec.section AS section_name, u.name AS teacher_name
    FROM feedback_forms ff
    LEFT JOIN academic_years ay ON ff.academic_year_id = ay.id
    LEFT JOIN semesters sm ON ff.semester_id = sm.id
    LEFT JOIN sections sec ON ff.section_id = sec.id
    LEFT JOIN courses c ON sec.course_id = c.id
    LEFT JOIN teachers t ON sec.teacher_id = t.id
    LEFT JOIN users u ON t.user_id = u.id
    $formWhere
    ORDER BY ff.module ASC, ay.year_name DESC, ff.id DESC";
if ($formTypes) {
    $formStmt = $conn->prepare($formSql);
    $formBind = [];
    if ($filterAY)
        $formBind[] = $filterAY;
    if ($filterSem)
        $formBind[] = $filterSem;
    $formStmt->bind_param($formTypes, ...$formBind);
    $formStmt->execute();
    $formList = $formStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $formStmt->close();
} else {
    $formList = $conn->query($formSql)->fetch_all(MYSQLI_ASSOC);
}

// Determine which form to load based on filters
$loadForm = false;
if (!$hasActiveFilters) {
    // No filters selected — auto-load the latest submitted form
    $latestQ = $conn->prepare("SELECT ff.id FROM feedback_forms ff JOIN feedback_submissions fs ON fs.form_id = ff.id ORDER BY ff.id DESC LIMIT 1");
    $latestQ->execute();
    $latestRow = $latestQ->get_result()->fetch_assoc();
    $latestQ->close();
    if ($latestRow) {
        $formId = (int) $latestRow['id'];
        $loadForm = true;
    }
} elseif ($formId > 0) {
    // User explicitly selected a form — load it if it exists in the filtered list
    $formExists = false;
    foreach ($formList as $fl) {
        if ((int) $fl['id'] === $formId) {
            $formExists = true;
            break;
        }
    }
    if ($formExists) {
        $loadForm = true;
    }
    // If formId was provided but doesn't exist in filtered list, $loadForm stays false (empty state)
} elseif ($filterAY > 0 || $filterSem > 0) {
    // Filters are set but no form was explicitly selected — try to auto-load the latest matching form
    $autoQ = $conn->prepare("SELECT ff.id FROM feedback_forms ff JOIN feedback_submissions fs ON fs.form_id = ff.id
        LEFT JOIN academic_years ay ON ff.academic_year_id = ay.id
        LEFT JOIN semesters sm ON ff.semester_id = sm.id
        " . ($filterAY > 0 ? "WHERE ff.academic_year_id = ?" : "") .
        ($filterSem > 0 ? ($filterAY > 0 ? " AND " : "WHERE ") . "ff.semester_id = ?" : "") .
        " ORDER BY ff.id DESC LIMIT 1");
    if ($filterAY > 0 && $filterSem > 0) {
        $autoQ->bind_param('ii', $filterAY, $filterSem);
    } elseif ($filterAY > 0) {
        $autoQ->bind_param('i', $filterAY);
    } elseif ($filterSem > 0) {
        $autoQ->bind_param('i', $filterSem);
    }
    $autoQ->execute();
    $autoRow = $autoQ->get_result()->fetch_assoc();
    $autoQ->close();
    if ($autoRow) {
        $formId = (int) $autoRow['id'];
        $loadForm = true;
    }
}

$form = null;
$questions = [];
$ratingStats = [];
$allComments = [];
$surveyStats = [];
$totalStudents = 0;
$completedCount = 0;
$pendingCount = 0;

if ($loadForm && $formId) {
    $r = $conn->prepare("SELECT ff.*, c.course_name, c.course_code, s.section, u.name AS teacher_name, ay.year_name AS academic_year_name, sm.semester_name FROM feedback_forms ff LEFT JOIN sections s ON ff.section_id=s.id LEFT JOIN courses c ON s.course_id=c.id LEFT JOIN teachers t ON s.teacher_id=t.id LEFT JOIN users u ON t.user_id=u.id LEFT JOIN academic_years ay ON COALESCE(s.academic_year_id, ff.academic_year_id) = ay.id LEFT JOIN semesters sm ON COALESCE(s.semester_id, ff.semester_id) = sm.id WHERE ff.id=?");
    $r->bind_param('i', $formId);
    $r->execute();
    $form = $r->get_result()->fetch_assoc();
    $r->close();

    if ($form) {
        $module = $form['module'];

        // Load questions from question_set_id
        if (!empty($form['question_set_id'])) {
            $q = $conn->prepare("SELECT id, question_no, question_text, question_type, options_json FROM feedback_questions WHERE question_set_id = ? ORDER BY question_no ASC");
            $q->bind_param('i', $form['question_set_id']);
            $q->execute();
            $questions = $q->get_result()->fetch_all(MYSQLI_ASSOC);
            $q->close();
        }

        // Module-specific metadata
        $formMeta = [];
        if ($module === 'academic') {
            $formMeta = [
                'academic_year' => $form['academic_year_name'] ?? '',
                'semester' => $form['semester_name'] ?? '',
                'course_code' => $form['course_code'] ?? '',
                'course_name' => $form['course_name'] ?? '',
                'section' => $form['section'] ?? '',
                'teacher_name' => $form['teacher_name'] ?? '',
            ];
        } elseif ($module === 'student_affairs' || $module === 'administration') {
            $formMeta = [
                'university_name' => $form['university_name'] ?? '',
                'university_campus' => $form['university_campus'] ?? '',
                'academic_year' => $form['academic_year_name'] ?? '',
                'semester' => $form['semester_name'] ?? '',
                'module' => $module,
            ];
        }

        // Completed count
        if ($module === 'academic' && !empty($form['section_id'])) {
            $cs = $conn->prepare("SELECT COUNT(DISTINCT st.id) AS cnt FROM students st JOIN section_assignments sa ON sa.student_id = st.id JOIN feedback_submissions fs ON fs.student_id = st.id WHERE sa.section_id = ? AND fs.form_id = ?");
            $cs->bind_param('ii', $form['section_id'], $formId);
        } else {
            $cs = $conn->prepare("SELECT COUNT(DISTINCT student_id) AS cnt FROM feedback_submissions WHERE form_id = ?");
            $cs->bind_param('i', $formId);
        }
        $cs->execute();
        $completedCount = (int) $cs->get_result()->fetch_assoc()['cnt'];
        $cs->close();

        // Total students count
        $totalStudents = 0;
        if ($module === 'academic' && !empty($form['section_id'])) {
            $ts = $conn->prepare("SELECT COUNT(sa.student_id) AS cnt FROM section_assignments sa WHERE sa.section_id = ?");
            $ts->bind_param('i', $form['section_id']);
            $ts->execute();
            $totalStudents = (int) $ts->get_result()->fetch_assoc()['cnt'];
            $ts->close();
        } else {
            $ts = $conn->prepare("SELECT COUNT(DISTINCT st.id) AS cnt FROM students st JOIN users u ON st.user_id = u.id JOIN section_assignments sa ON sa.student_id = st.id JOIN sections sec ON sa.section_id = sec.id WHERE sec.academic_year_id = ? AND sec.semester_id = ?");
            $ts->bind_param('ii', $form['academic_year_id'], $form['semester_id']);
            $ts->execute();
            $totalStudents = (int) $ts->get_result()->fetch_assoc()['cnt'];
            $ts->close();
        }
        $pendingCount = max(0, $totalStudents - $completedCount);

        // Rating stats
        $statStmt = $conn->prepare("SELECT fq.id AS question_id, fr.rating, COUNT(*) AS qty FROM feedback_ratings fr JOIN feedback_questions fq ON fr.question_id = fq.id WHERE fr.form_id = ? GROUP BY fq.id, fr.rating");
        $statStmt->bind_param('i', $formId);
        $statStmt->execute();
        $rawStats = $statStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $statStmt->close();
        foreach ($rawStats as $row) {
            $rKey = trim($row['rating']);
            if ($rKey == '3' || $rKey === 'ကောင်း' || $rKey === 'Good' || $rKey === 'good')
                $rKey = 'Good';
            elseif ($rKey == '2' || $rKey === 'သင့်' || $rKey === 'Normal' || $rKey === 'normal' || $rKey === 'Average' || $rKey === 'Fair' || $rKey === 'fair')
                $rKey = 'Fair';
            elseif ($rKey == '1' || $rKey === 'ညံ့' || $rKey === 'Bad' || $rKey === 'bad' || $rKey === 'Poor' || $rKey === 'poor')
                $rKey = 'Bad';
            if (!isset($ratingStats[$row['question_id']][$rKey]))
                $ratingStats[$row['question_id']][$rKey] = 0;
            $ratingStats[$row['question_id']][$rKey] += (int) $row['qty'];
        }

        // Comments
        $cStmt = $conn->prepare("SELECT fq.id AS question_id, fc.comment_text FROM feedback_comments fc JOIN feedback_questions fq ON fc.question_id = fq.id WHERE fc.form_id = ? AND fc.comment_text IS NOT NULL AND fc.comment_text != '' ORDER BY fc.comment_text DESC");
        $cStmt->bind_param('i', $formId);
        $cStmt->execute();
        $rawComments = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cStmt->close();
        foreach ($rawComments as $row) {
            $allComments[$row['question_id']][] = $row['comment_text'];
        }

        // Survey stats
        $surveyQs = array_filter($questions, fn($q) => $q['question_type'] === 'survey');
        if (!empty($surveyQs)) {
            $surveyIds = array_column($surveyQs, 'id');
            $placeholders = implode(',', array_fill(0, count($surveyIds), '?'));
            $surveyStmt = $conn->prepare("SELECT fsa.question_id, fsa.selected_option_index, COUNT(*) AS cnt FROM feedback_survey_answers fsa JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id WHERE fsub.form_id = ? AND fsa.question_id IN ($placeholders) GROUP BY fsa.question_id, fsa.selected_option_index");
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

$ratingQuestions = array_filter($questions, fn($q) => $q['question_type'] === 'rating');
$commentQuestions = array_filter($questions, fn($q) => $q['question_type'] === 'comment');
$surveyQuestions = array_filter($questions, fn($q) => $q['question_type'] === 'survey');

// Overall rating calculation
$completedCount = $completedCount ?? 0;
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

$aggGoodPct = $totalRatingResponses > 0 ? round(($totalGood / $totalRatingResponses) * 100, 1) : 0;
$aggFairPct = $totalRatingResponses > 0 ? round(($totalFair / $totalRatingResponses) * 100, 1) : 0;
$aggBadPct = $totalRatingResponses > 0 ? round(($totalBad / $totalRatingResponses) * 100, 1) : 0;

$circleRadius = 54;
$circleCircumference = 2 * M_PI * $circleRadius;
$circleOffset = $circleCircumference - ($overallPct / 100) * $circleCircumference;

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

    body.lang-mm th {
        font-size: 0.8125rem;
        line-height: 1.6;
    }

    body.lang-mm td {
        font-size: 0.8125rem;
        line-height: 1.6;
    }

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

    .progress-bar-fill {
        transition: width 1.2s cubic-bezier(0.4, 0, 0.2, 1);
    }

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

    .most-selected {
        border-color: #8b5cf6 !important;
        background: linear-gradient(135deg, #f5f3ff 0%, #ede9fe 100%) !important;
        box-shadow: 0 0 0 2px rgba(139, 92, 246, 0.15);
    }

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

        .mb-6,
        .mb-8,
        .mt-8 {
            margin-bottom: 1rem !important;
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

        /* 🖨️ Printable PDF Header Styles */
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

<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-xl font-bold text-slate-800"><?= $LANG['all_feedback_results'] ?? "All Feedback Results" ?></h2>
        <p class="text-sm text-slate-500 mt-0.5"><?= $LANG['feedback_results_subtitle'] ?? "View results for all modules — Academic, Student Affairs, and
            Administration." ?></p>
    </div>
    <?php if ($form): ?>
        <button onclick="setTimeout(function(){ window.print(); }, 500);"
            class="no-print inline-flex items-center gap-2 bg-slate-700 hover:bg-slate-800 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm transition-all hover:-translate-y-0.5">
            <?= iconSvg('document', 'w-4 h-4') ?> Print Report
        </button>
    <?php endif ?>
</div>

<!-- Filters -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6 no-print">
    <form method="GET" class="space-y-3">
        <div class="flex flex-col sm:flex-row gap-4 items-end">
            <div class="flex-1 min-w-[160px]">
                <label
                    class="block text-xs font-bold text-slate-500 mb-1"><?= $LANG["academic_year"] ?? "Academic Year" ?></label>
                <select name="ay_id" id="aySelect"
                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white font-semibold text-slate-700 shadow-sm focus:border-slate-500">
                    <option value=""><?= $LANG["all_academic_years"] ?? "All Academic Years" ?></option>
                    <?php foreach ($academicYears as $ay): ?>
                        <option value="<?= $ay['id'] ?>" <?= $filterAY == $ay['id'] ? 'selected' : '' ?>>
                            <?= e($ay['year_name']) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="flex-1 min-w-[160px]">
                <label
                    class="block text-xs font-bold text-slate-500 mb-1"><?= $LANG["semester_filter"] ?? "Semester" ?></label>
                <select name="sem_id" id="semSelect"
                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white font-semibold text-slate-700 shadow-sm focus:border-slate-500">
                    <option value=""><?= $LANG['all_semesters'] ?? 'All Semesters' ?></option>
                    <?php foreach ($semesters as $sm): ?>
                        <option value="<?= $sm['id'] ?>" <?= $filterSem == $sm['id'] ? 'selected' : '' ?>>
                            <?= e(semesterToRoman($sm['semester_name'])) ?>
                        </option>
                    <?php endforeach ?>
                </select>
            </div>
            <div class="flex-1 max-w-xl">
                <label
                    class="block text-xs font-bold text-slate-500 mb-1"><?= $LANG["choose_form"] ?? "Choose a Feedback Form" ?></label>
                <select name="form_id" id="formSelect"
                    class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white font-semibold text-slate-700 shadow-sm focus:border-slate-500">
                    <option value="">
                        <?= $LANG["choose_form_placeholder"] ?? "— Choose a Feedback Form —" ?>
                    </option>
                    <?php
                    $currentMod = '';
                    foreach ($formList as $f):
                        if ($f['module'] !== $currentMod):
                            if ($currentMod !== '')
                                echo '</optgroup>';
                            $currentMod = $f['module'];
                            $modLabel = match ($currentMod) { 'academic' => 'Academic', 'student_affairs' => 'Student Affairs', 'administration' => 'Administration', default => $currentMod};
                            echo '<optgroup label="' . e($modLabel) . '">';
                        endif;
                        if ($f['module'] === 'academic' && !empty($f['course_code'])) {
                            $formLabel = e($f['course_code']) . ' - ' . e($f['course_name']) . ' - Section ' . e($f['section_name']);
                        } else {
                            $formLabel = e($f['academic_year_name'] ?? '') . ' - ' . e($f['title']);
                        }
                        ?>
                        <option value="<?= $f['id'] ?>" <?= $formId == $f['id'] ? 'selected' : '' ?>>
                            <?= $formLabel ?>
                        </option>
                    <?php endforeach;
                    if ($currentMod !== '')
                        echo '</optgroup>'; ?>
                </select>
            </div>
            <div class="flex gap-2 shrink-0">
                <button type="submit"
                    class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-sm transition-all h-[42px]"><?= $LANG["search"] ?? "Search" ?></button>
                <a href="results_all.php"
                    class="px-5 py-2.5 btn-reset text-sm font-semibold rounded-xl transition-all h-[42px] inline-flex items-center"><?= $LANG["reset"] ?? "Reset" ?></a>
            </div>
        </div>
    </form>
</div>

<?php if ($hasActiveFilters && !$loadForm): ?>
    <div id="noFormsMsg" class="bg-amber-50 border border-amber-200 rounded-2xl px-5 py-4 mb-6 flex items-center gap-3">
        <div class="w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center shrink-0">
            <?= iconSvg('question', 'w-5 h-5 text-amber-600') ?>
        </div>
        <div>
            <p class="text-sm font-semibold text-amber-800">No feedback forms for this section</p>
        </div>
    </div>
<?php endif; ?>

<?php if ($form): ?>
    <!-- Progress Stats -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 mt-6 myanmar-font no-print">
        <div onclick="openStudentModal('all')"
            class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 text-center cursor-pointer hover:shadow-md hover:border-blue-300 transition-all">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">
                <?= $LANG['total_students_label'] ?? 'Total Students' ?>
            </p>
            <p class="text-3xl font-black text-slate-800"><?= $totalStudents ?></p>
        </div>
        <div onclick="openStudentModal('completed')"
            class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 text-center cursor-pointer hover:shadow-md hover:border-emerald-300 transition-all">
            <p class="text-xs font-bold text-emerald-500 uppercase tracking-wider mb-1">
                <?= $LANG['completed_label'] ?? 'Completed' ?>
            </p>
            <p class="text-3xl font-black text-emerald-600"><?= $completedCount ?></p>
            <p class="text-[10px] text-slate-400 mt-1">
                <?= $totalStudents > 0 ? round(($completedCount / $totalStudents) * 100) : 0 ?>%
                <?= $LANG['response_rate'] ?? 'response rate' ?>
            </p>
        </div>
        <div onclick="openStudentModal('pending')"
            class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 text-center cursor-pointer hover:shadow-md hover:border-amber-300 transition-all">
            <p class="text-xs font-bold text-amber-500 uppercase tracking-wider mb-1">
                <?= $LANG['pending_label'] ?? 'Pending' ?>
            </p>
            <p class="text-3xl font-black text-amber-600"><?= $pendingCount ?></p>
            <p class="text-[10px] text-slate-400 mt-1">
                <?= $totalStudents > 0 ? round(($pendingCount / $totalStudents) * 100) : 0 ?>% remaining
            </p>
        </div>
    </div>

    <!-- Overall Rating Card -->
    <?php if (!empty($ratingQuestions)): ?>
        <div class="mb-6 no-print">
            <div
                class="bg-gradient-to-br from-blue-500 via-purple-700 to-indigo-700 rounded-2xl shadow-xl border border-slate-700 p-6 md:p-8 text-white relative overflow-hidden">
                <div class="relative z-10">
                    <div class="flex items-center gap-2 mb-5">
                        <?= iconSvg('star', 'w-5 h-5 text-indigo-300') ?>
                        <h3 class="text-sm font-bold uppercase tracking-wider text-indigo-200">Overall Rating Score</h3>
                        <span class="ml-auto text-[10px] text-slate-400 bg-slate-700/50 px-2 py-0.5 rounded-full">Rating
                            Questions Only</span>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-6 items-center">
                        <div class="md:col-span-3 flex flex-col items-center justify-center">
                            <div class="relative" style="width:160px;height:160px;">
                                <canvas id="overallRatingPieChart" data-type="overall" width="160" height="160"></canvas>
                                <div class="absolute inset-0 flex flex-col items-center justify-center pointer-events-none">
                                    <span class="text-xl font-black text-white" id="ratingPctDisplay">0%</span>
                                    <span class="text-[9px] text-slate-300 font-bold uppercase">Score Matrix</span>
                                </div>
                            </div>
                        </div>
                        <div class="md:col-span-4 space-y-4">
                            <div class="flex items-center gap-3">
                                <span class="text-2xl"><?= $gradeIcon ?></span>
                                <div>
                                    <span
                                        class="grade-badge inline-block px-4 py-1.5 rounded-lg text-sm font-extrabold <?= match ($gradeColor) { 'emerald' => 'bg-emerald-500/20 text-emerald-300 border border-emerald-400/30', 'blue' => 'bg-blue-500/20 text-blue-300 border border-blue-400/30', 'cyan' => 'bg-cyan-500/20 text-cyan-300 border border-cyan-400/30', 'amber' => 'bg-amber-500/20 text-amber-300 border border-amber-400/30', 'red' => 'bg-red-500/20 text-red-300 border border-red-400/30', default => 'bg-slate-500/20 text-slate-300 border border-slate-400/30'} ?>"><?= $grade ?></span>
                                    <p class="text-[10px] text-slate-400 mt-1">Performance Grade</p>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-3 text-xs">
                                <div class="bg-white/5 backdrop-blur rounded-lg p-3 border border-white/10">
                                    <p class="text-slate-400 font-semibold mb-0.5">
                                        <?= $LANG["total_responses"] ?? "Total Responses" ?>
                                    </p>
                                    <p class="text-lg font-black text-white"><?= $completedCount ?></p>
                                </div>
                                <div class="bg-white/5 backdrop-blur rounded-lg p-3 border border-white/10">
                                    <p class="text-slate-400 font-semibold mb-0.5">Rating Questions</p>
                                    <p class="text-lg font-black text-white"><?= $numRatingQuestions ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="md:col-span-5 space-y-3">
                            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Rating Distribution
                            </p>
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-semibold text-emerald-300 flex items-center gap-1.5"><span
                                            class="w-2 h-2 rounded-full bg-emerald-400 inline-block"></span> Good</span>
                                    <span class="text-slate-300 font-bold"><?= $totalGood ?> <span
                                            class="text-slate-500 font-normal">(<?= $aggGoodPct ?>%)</span></span>
                                </div>
                                <div class="w-full bg-white/10 rounded-full h-2.5 overflow-hidden">
                                    <div class="progress-bar-fill bg-gradient-to-r from-emerald-500 to-emerald-400 h-full rounded-full"
                                        style="width: 0%" data-target-width="<?= $aggGoodPct ?>%"></div>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-semibold text-amber-300 flex items-center gap-1.5"><span
                                            class="w-2 h-2 rounded-full bg-amber-400 inline-block"></span> Fair</span>
                                    <span class="text-slate-300 font-bold"><?= $totalFair ?> <span
                                            class="text-slate-500 font-normal">(<?= $aggFairPct ?>%)</span></span>
                                </div>
                                <div class="w-full bg-white/10 rounded-full h-2.5 overflow-hidden">
                                    <div class="progress-bar-fill bg-gradient-to-r from-amber-500 to-amber-400 h-full rounded-full"
                                        style="width: 0%" data-target-width="<?= $aggFairPct ?>%"></div>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="font-semibold text-red-300 flex items-center gap-1.5"><span
                                            class="w-2 h-2 rounded-full bg-red-400 inline-block"></span> Bad</span>
                                    <span class="text-slate-300 font-bold"><?= $totalBad ?> <span
                                            class="text-slate-500 font-normal">(<?= $aggBadPct ?>%)</span></span>
                                </div>
                                <div class="w-full bg-white/10 rounded-full h-2.5 overflow-hidden">
                                    <div class="progress-bar-fill bg-gradient-to-r from-red-500 to-red-400 h-full rounded-full"
                                        style="width: 0%" data-target-width="<?= $aggBadPct ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php if ($completedCount === 0): ?>
        <div class="bg-blue-50 border border-blue-200 rounded-2xl px-5 py-4 mb-6 flex items-center gap-3 no-print">
            <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center shrink-0">
                <?= iconSvg('question', 'w-5 h-5 text-blue-600') ?>
            </div>
            <div>
                <p class="text-sm font-semibold text-blue-800">No responses have been submitted yet.</p>
                <p class="text-xs text-blue-600 mt-0.5">Results will appear here once students submit their feedback.</p>
            </div>
        </div>
    <?php endif; ?>

    <!-- Results Table -->
    <div class="w-full mx-auto no-print">
        <div class="bg-white shadow-md rounded-xl border border-slate-200 p-6 md:p-8 mb-8">
            <div class="text-center border-b-2 border-slate-800 pb-4 mb-5">
                <h2 class="text-lg md:text-xl font-bold text-slate-900 mb-1"><?= e($form['title']) ?></h2>
                <p class="text-xs text-slate-400 font-mono"><?= moduleBadge($form['module']) ?> Statistical Evaluation
                    Report</p>
                <p class="text-xs text-slate-400 mt-1">Period: <?= formatDateTime($form['start_date']) ?> —
                    <?= formatDateTime($form['end_date']) ?>
                </p>
            </div>

            <?php if ($module === 'academic' && !empty($formMeta)): ?>
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6">
                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Form Information</h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                <?= $LANG["academic_year"] ?? "Academic Year" ?>
                            </p>
                            <p class="text-sm font-bold text-slate-800"><?= e($formMeta['academic_year'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                <?= $LANG["semester_filter"] ?? "Semester" ?>
                            </p>
                            <p class="text-sm font-bold text-slate-800"><?= e(semesterToRoman($formMeta['semester'] ?? '')) ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                <?= $LANG["course_code"] ?? "Course Code" ?>
                            </p>
                            <p class="text-sm font-bold text-slate-800"><?= e($formMeta['course_code'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                <?= $LANG["course_name"] ?? "Course Name" ?>
                            </p>
                            <p class="text-sm font-bold text-slate-800"><?= e($formMeta['course_name'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                <?= $LANG["section_name"] ?? "Section" ?>
                            </p>
                            <p class="text-sm font-bold text-slate-800"><?= e($formMeta['section'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                <?= $LANG["teacher_name"] ?? "Teacher Name" ?>
                            </p>
                            <p class="text-sm font-bold text-slate-800"><?= e($formMeta['teacher_name'] ?? '—') ?></p>
                        </div>
                    </div>
                </div>
            <?php elseif (($module === 'student_affairs' || $module === 'administration') && !empty($formMeta)): ?>
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6">
                    <h3 class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">Form Information</h3>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                <?= $LANG["university_name"] ?? "University Name" ?>
                            </p>
                            <p class="text-sm font-bold text-slate-800"><?= e($formMeta['university_name'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                <?= $LANG["university_campus"] ?? "Campus" ?>
                            </p>
                            <p class="text-sm font-bold text-slate-800"><?= e($formMeta['university_campus'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                <?= $LANG["academic_year"] ?? "Academic Year" ?>
                            </p>
                            <p class="text-sm font-bold text-slate-800"><?= e($formMeta['academic_year'] ?? '—') ?></p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">
                                <?= $LANG["semester_filter"] ?? "Semester" ?>
                            </p>
                            <p class="text-sm font-bold text-slate-800"><?= e(semesterToRoman($formMeta['semester'] ?? '')) ?>
                            </p>
                        </div>
                        <div>
                            <p class="text-[11px] font-semibold text-slate-400 uppercase">Form Type</p>
                            <p class="text-sm font-bold text-slate-800"><?= moduleBadge($module) ?></p>
                        </div>
                    </div>
                </div>
            <?php endif ?>

            <?php if (!empty($ratingQuestions)): ?>
                <div class="mb-8">
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">
                        <?= $LANG['question_stats_header'] ?? 'Question-wise Statistical Results' ?></h3>
                    <div class="overflow-x-auto border border-slate-300 rounded-lg">
                        <table class="w-full text-left border-collapse min-w-[850px] text-xs">
                            <!-- <thead>
                                <tr class="text-white font-bold">
                                    <th class="bg-blue-300 p-3 w-12 text-center text-lg font-semibold">No.</th>
                                    <th class="p-3 bg-blue-500 text-lg font-semibold">Evaluation Questions</th>
                                    <th class="p-3 w-28 text-center bg-emerald-700 text-lg font-semibold">
                                        <div>Good</div>
                                        <div class="text-[10px] font-normal">COUNT / %</div>
                                    </th>
                                    <th class="p-3 w-28 text-center bg-amber-600 text-sm font-semibold">
                                        <div>Fair</div>
                                        <div class="text-[10px] font-normal">COUNT / %</div>
                                    </th>
                                    <th class="p-3 w-28 text-center bg-red-700 text-sm font-semibold">
                                        <div>Bad</div>
                                        <div class="text-[10px] font-normal">COUNT / %</div>
                                    </th>
                                </tr>
                            </thead> -->
                            <thead>
                                <tr class=" text-white font-bold">
                                    <th class="p-3 w-12 text-center bg-blue-300 text-lg"><?= $LANG['col_no'] ?? 'စဉ်' ?></th>
                                    <th class="p-3 bg-blue-500/80 text-lg">
                                        <?= $LANG['eval_questions_header'] ?? 'Evaluation Questions' ?>
                                    </th>
                                    <th class="p-3 w-28 text-center bg-emerald-700/80">
                                        <div class="text-lg"><?= $LANG['good'] ?? 'Good' ?></div>
                                        <div class="text-[10px] font-normal"><?= $LANG['count_pct'] ?? 'COUNT / %' ?>
                                        </div>
                                    </th>
                                    <th class="p-3 w-28 text-center bg-amber-600/80">
                                        <div class="text-lg"><?= $LANG['fair'] ?? 'Fair' ?></div>
                                        <div class="text-[10px] font-normal"><?= $LANG['count_pct'] ?? 'COUNT / %' ?>
                                        </div>
                                    </th>
                                    <th class="p-3 w-28 text-center bg-red-700/70">
                                        <div class="text-lg"><?= $LANG['bad'] ?? 'Bad' ?></div>
                                        <div class="text-[10px] font-normal"><?= $LANG['count_pct'] ?? 'COUNT / %' ?>
                                        </div>
                                    </th>
                                </tr>
                            </thead>


                            <tbody class="divide-y divide-slate-200 text-slate-800 font-medium">
                                <?php foreach ($ratingQuestions as $q):
                                    $gc = $ratingStats[$q['id']]['Good'] ?? 0;
                                    $fc = $ratingStats[$q['id']]['Fair'] ?? 0;
                                    $bc = $ratingStats[$q['id']]['Bad'] ?? 0;
                                    $tv = $gc + $fc + $bc;
                                    $gp = $tv > 0 ? round(($gc / $tv) * 100) : 0;
                                    $fp = $tv > 0 ? round(($fc / $tv) * 100) : 0;
                                    $bp = $tv > 0 ? round(($bc / $tv) * 100) : 0;
                                    ?>
                                    <tr class="hover:bg-slate-50/60 transition-colors">
                                        <td class="p-3 text-center font-bold font-mono border-r text-lg">
                                            <?= e(displayQuestionNumber($q['question_no'], $_SESSION['lang'] ?? 'en')) ?>
                                        </td>
                                        <td class="p-3 border-r leading-relaxed text-lg"><?= e($q['question_text']) ?></td>
                                        <td class="p-3 text-center border-r bg-emerald-50/30"><span
                                                class="text-emerald-700 font-bold block text-sm"><?= $gc ?> persons</span><span
                                                class="text-[10px] text-slate-500">(<?= $gp ?>%)</span></td>
                                        <td class="p-3 text-center border-r bg-amber-50/30"><span
                                                class="text-amber-700 font-bold block text-sm"><?= $fc ?> persons</span><span
                                                class="text-[10px] text-slate-500">(<?= $fp ?>%)</span></td>
                                        <td class="p-3 text-center bg-red-50/30"><span
                                                class="text-red-700 font-bold block text-sm"><?= $bc ?> persons</span><span
                                                class="text-[10px] text-slate-500">(<?= $bp ?>%)</span></td>
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
                        <?= $LANG['comments_header'] ?? 'Comments' ?>
                    </h3>
                    <?php foreach ($commentQuestions as $cq):
                        $commentsForQ = $allComments[$cq['id']] ?? [];
                        ?>
                        <div class="space-y-2 text-lg">
                            <label class="block font-bold text-slate-700 text-lg">
                                <?= displayQuestionNumber($cq['question_no'], $_SESSION['lang'] ?? 'en') ?>
                                <?= e($cq['question_text']) ?>
                                <span class="text-slate-400 font-normal">(<?= count($commentsForQ) ?>
                                    comments)</span></label>
                            <div
                                class="w-full bg-slate-50 border border-slate-200 p-4 rounded-xl space-y-2.5 max-h-[300px] overflow-y-auto">
                                <?php if (!empty($commentsForQ)): ?>
                                    <?php foreach ($commentsForQ as $idx => $commentText): ?>
                                        <div class="bg-white border border-slate-100 p-3 rounded-lg shadow-2xs flex items-start gap-2">
                                            <span class="text-slate-400 font-bold">#<?= $idx + 1 ?></span>
                                            <div class="text-slate-800 font-medium whitespace-pre-wrap"><?= e($commentText) ?></div>
                                        </div>
                                    <?php endforeach ?>
                                <?php else: ?>
                                    <div class="text-slate-400 italic text-center py-4">No comments for this question.</div>
                                <?php endif ?>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <?php if (!empty($surveyQuestions)): ?>
                <div class="space-y-6 pt-6 border-t-2 border-slate-300">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">Survey Results (MCQ)</h3>
                        <span class="text-[10px] text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full font-semibold">Not
                            included in Overall Rating</span>
                    </div>
                    <?php foreach ($surveyQuestions as $q):
                        $opts = json_decode($q['options_json'] ?? '[]', true) ?: [];
                        $qStats = $surveyStats[$q['id']] ?? [];
                        $mostSelected = getMostSelectedSurveyOptions($qStats);
                        $totalVotes = $mostSelected['total'];
                        $doughnutColors = ['#7c3aed', '#2563eb', '#059669', '#d97706', '#dc2626', '#8b5cf6', '#0891b2', '#c026d3'];
                        $chartLabels = $chartData = $chartColors = [];
                        foreach ($opts as $idx => $opt) {
                            $chartLabels[] = $opt;
                            $chartData[] = $qStats[$idx] ?? 0;
                            $chartColors[] = $doughnutColors[$idx % count($doughnutColors)];
                        }
                        ?>
                        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm"
                            data-qid="<?= $q['id'] ?>">
                            <div class="px-6 pt-5 pb-3 border-b border-slate-100">
                                <div class="flex items-start gap-3">
                                    <span
                                        class="text-lg font-bold text-violet-600 bg-violet-50 px-2 py-1 rounded-lg mt-0.5 shrink-0"><?= e(displayQuestionNumber($q['question_no'], $_SESSION['lang'] ?? 'en')) ?></span>
                                    <div class="flex-1 min-w-0">
                                        <h4 class="text-lg font-bold text-slate-800 leading-snug"><?= e($q['question_text']) ?></h4>
                                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-1 text-[11px] text-slate-400">
                                            <span><?= $totalVotes > 0 ? $totalVotes . ' responses' : 'No responses yet' ?></span>
                                            <!-- <?php if ($totalVotes > 0 && !empty($mostSelected['indices'])): ?>
                                                <span class="inline-flex items-center gap-1 text-violet-700 bg-violet-50 px-2 py-0.5 rounded-md font-semibold">
                                                    🔥 အများဆုံးရွေးချယ်မှု: 
                                                    <?php
                                                    $mostSelectedLabels = [];
                                                    foreach ($mostSelected['indices'] as $msIndex) {
                                                        if (isset($opts[$msIndex])) {
                                                            $mostSelectedLabels[] = $opts[$msIndex];
                                                        }
                                                    }
                                                    echo e(implode(' / ', $mostSelectedLabels));
                                                    ?>
                                                </span>
                                            <?php endif; ?> -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php if ($totalVotes > 0): ?>
                                <div class="p-6">
                                    <div class="flex flex-col md:flex-row gap-6 items-start">
                                        <div class="w-full md:w-5/12 flex justify-center">
                                            <div class="relative" style="width:260px;height:260px;">
                                                <canvas id="surveyChart_<?= $q['id'] ?>" data-type="survey" width="260"
                                                    height="260"></canvas>
                                            </div>
                                        </div>
                                        <div class="w-full md:w-7/12 space-y-2">
                                            <?php foreach ($opts as $idx => $opt):
                                                $votes = $chartData[$idx];
                                                $pct = $totalVotes > 0 ? round(($votes / $totalVotes) * 100) : 0;
                                                $isMostSelected = in_array($idx, $mostSelected['indices']);
                                                ?>
                                                <div class="flex items-center gap-3">
                                                    <span class="w-3 h-3 rounded-full shrink-0 ring-2 ring-white shadow-sm"
                                                        style="background-color: <?= $chartColors[$idx] ?>"></span>
                                                    <div class="flex-1 min-w-0">
                                                        <div class="flex items-center justify-between gap-2">
                                                            <span
                                                                class="text-sm font-semibold text-slate-700 truncate"><?= e($opt) ?></span>
                                                            <div class="flex items-center gap-2 shrink-0">
                                                                <span class="text-[11px] text-slate-400 font-medium"><?= $votes ?>
                                                                    votes</span>
                                                                <?php if ($isMostSelected): ?>
                                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                                                        fill="currentColor" class="w-4 h-4 text-violet-600">
                                                                        <path fill-rule="evenodd"
                                                                            d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z"
                                                                            clip-rule="evenodd" />
                                                                    </svg>
                                                                <?php endif ?>
                                                            </div>
                                                        </div>
                                                        <div class="w-full bg-slate-100 rounded-full h-1.5 mt-1.5 overflow-hidden">
                                                            <div class="h-full rounded-full transition-all duration-700"
                                                                style="width: <?= $pct ?>%; background-color: <?= $chartColors[$idx] ?>">
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if (!empty($mostSelected['indices'])): ?>
                                    <div
                                        class="mx-6 mb-6 p-4 bg-gradient-to-br from-violet-50 to-purple-50 border border-violet-200 rounded-xl">
                                        <p class="text-xs font-bold text-violet-600 uppercase tracking-wider mb-2">Most Selected
                                            Answer<?= count($mostSelected['indices']) > 1 ? 's' : '' ?></p>
                                        <?php foreach ($mostSelected['indices'] as $msIdx):
                                            $msLabel = $opts[$msIdx] ?? '';
                                            $msCount = $mostSelected['max_votes'];
                                            $msPct = $totalVotes > 0 ? round(($msCount / $totalVotes) * 100, 1) : 0;
                                            ?>
                                            <div class="flex items-center gap-3 mb-2 last:mb-0">
                                                <span class="text-lg">⭐</span>
                                                <div>
                                                    <p class="text-sm font-bold text-slate-800"><?= e($msLabel) ?></p>
                                                    <p class="text-xs text-slate-500">
                                                        <span class="inline-flex items-center gap-1">👥 <?= $msCount ?> Students</span>
                                                        <span class="mx-1.5">·</span>
                                                        <span class="inline-flex items-center gap-1">📊 <?= $msPct ?>%</span>
                                                    </p>
                                                </div>
                                            </div>
                                        <?php endforeach ?>
                                    </div>
                                <?php endif ?>
                            <?php else: ?>
                                <div class="p-6">
                                    <p class="text-sm text-slate-400 italic text-center py-4">No responses yet.</p>
                                    <div class="space-y-2">
                                        <?php foreach ($opts as $idx => $opt): ?>
                                            <div class="flex items-center gap-3">
                                                <span class="w-3 h-3 rounded-full shrink-0 ring-2 ring-white shadow-sm"
                                                    style="background-color: <?= $chartColors[$idx] ?>"></span>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center justify-between gap-2">
                                                        <span class="text-sm font-semibold text-slate-700 truncate"><?= e($opt) ?></span>
                                                        <span class="text-[11px] text-slate-400 font-medium">0 votes</span>
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
                            <?php endif ?>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <div class="mt-8 pt-4 border-t border-slate-100 text-center text-[11px] text-blue-500 font-semibold italic">
                This is an automated anonymous statistical report.
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
            <div class="print-section-title">
                <?= ($module === 'academic') ? (($LANG['teacher_label'] ?? 'Teacher') . ' & ' . ($LANG['subject_filter'] ?? 'Subject')) : 'Form' ?>
                Information
            </div>
            <dl class="print-info-grid">
                <dt>Academic Year:</dt>
                <dd><?= e($formMeta['academic_year'] ?? '') ?></dd>
                <dt>Semester:</dt>
                <dd><?= e(semesterToRoman($formMeta['semester'] ?? '')) ?></dd>
                <?php if ($module === 'academic'): ?>
                    <dt>Subject:</dt>
                    <dd><?= e($formMeta['course_code'] ?? '') ?> — <?= e($formMeta['course_name'] ?? '') ?></dd>
                    <dt>Teacher:</dt>
                    <dd><?= e($formMeta['teacher_name'] ?? '') ?></dd>
                    <dt>Section:</dt>
                    <dd><?= e($formMeta['section'] ?? '') ?></dd>
                <?php else: ?>
                    <dt>Module:</dt>
                    <dd><?= moduleBadge($module) ?></dd>
                    <?php if (!empty($formMeta['university_name'])): ?>
                        <dt>University:</dt>
                        <dd><?= e($formMeta['university_name'] ?? '') ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($formMeta['university_campus'])): ?>
                        <dt>Campus:</dt>
                        <dd><?= e($formMeta['university_campus'] ?? '') ?></dd>
                    <?php endif; ?>
                <?php endif; ?>
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
                <div class="print-section-title"><?= $LANG['overall_teacher_rating'] ?? 'Overall Teacher Rating' ?> — Total
                </div>
                <div class="print-rating-summary">
                    <div class="print-rating-item">
                        <div class="value"><?= $overallPct ?>%</div>
                        <div class="label"><?= $LANG["overall_rating"] ?? "Overall Rating" ?></div>
                    </div>
                    <div class="print-rating-item">
                        <div class="value"><span class="print-grade-badge"><?= $grade ?></span></div>
                        <div class="label" style="margin-top:6px;"><?= $LANG['performance_grade'] ?? 'Performance Grade' ?>
                        </div>
                    </div>
                </div>
                <div style="display:flex; justify-content:center; gap:24px; font-size:9pt; margin-top:8px; color:#475569;">
                    <span>🟢 <?= $LANG['good'] ?? 'Good' ?>: <?= $totalGood ?> (<?= $aggGoodPct ?>%)</span>
                    <span>🟡 <?= $LANG['fair'] ?? 'Fair' ?>: <?= $totalFair ?> (<?= $aggFairPct ?>%)</span>
                    <span>🔴 <?= $LANG['bad'] ?? 'Bad' ?>: <?= $totalBad ?> (<?= $aggBadPct ?>%)</span>
                </div>
                <div style="font-size:8pt; color:#94a3b8; text-align:center; margin-top:4px;">
                    <?= $LANG['good'] ?? 'Good' ?> = 5 pts · <?= $LANG['fair'] ?? 'Fair' ?> = 3 pts ·
                    <?= $LANG['bad'] ?? 'Bad' ?> = 1 pt &nbsp;|&nbsp; <?= $LANG['rating_questions'] ?? 'Rating Questions' ?>:
                    <?= $numRatingQuestions ?> &nbsp;|&nbsp; Survey questions excluded from rating
                </div>
            </div>
        <?php endif ?>

        <!-- Rating Questions Result Table -->
        <?php if (!empty($ratingQuestions)): ?>
            <div class="print-section">
                <div class="print-section-title"><?= $LANG['rating_questions'] ?? 'Rating Questions' ?> — Detailed Results</div>
                <table class="print-table">
                    <thead>
                        <tr>
                            <th style="width:40px;"><?= $LANG['col_no'] ?? 'စဉ်' ?></th>
                            <th style="text-align:left;"><?= $LANG['eval_questions_header'] ?? 'Evaluation Questions' ?></th>
                            <th style="width:80px;"><?= $LANG['good'] ?? 'Good' ?></th>
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
                                <td style="text-align:center; font-weight:700;">
                                    <?= e(displayQuestionNumber($q['question_no'], $_SESSION['lang'] ?? 'en')) ?>
                                </td>
                                <td style="text-align:left;"><?= e($q['question_text']) ?></td>
                                <td style="text-align:center;"><?= $gc ?> (<?= $gp ?>%)</td>
                                <td style="text-align:center;"><?= $fc ?> (<?= $fp ?>%)</td>
                                <td style="text-align:center;"><?= $bc ?> (<?= $bp ?>%)</td>
                                <td style="text-align:center; font-weight:700;"><?= $tv ?></td>
                            </tr>
                        <?php endforeach ?>
                        <tr
                            style="font-weight:700; background:#e2e8f0 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact;">
                            <td colspan="2" style="text-align:right; padding-right:12px;"><?= $LANG['total'] ?? 'TOTALS' ?>:
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

        <!-- Survey Results with Doughnut Charts -->
        <?php if (!empty($surveyQuestions)): ?>
            <div class="print-section">
                <div class="print-section-title"><?= $LANG['survey_results'] ?? 'Survey Results' ?></div>
                <div style="font-size:8pt; color:#64748b; margin-bottom:10px;">
                    <?= $LANG['not_in_overall'] ?? 'Not included in Overall Rating' ?> calculation.
                </div>

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
                        $pValues[] = (int) ($qStats[$idx] ?? 0);
                    }
                    ?>
                    <div style="margin-bottom:16px; page-break-inside:avoid;">
                        <div style="display:flex; align-items:flex-start; gap:12px;">
                            <div style="width:120px; height:120px; flex-shrink:0; position:relative;">
                                <canvas id="printChart_<?= $q['id'] ?>" width="120" height="120"></canvas>
                            </div>
                            <div style="flex:1; min-width:0;">
                                <div style="font-size:9.5pt; font-weight:700; color:#0f172a; margin-bottom:4px;">
                                    <?= e(displayQuestionNumber($q['question_no'], $_SESSION['lang'] ?? 'en')) ?>
                                    <?= e($q['question_text']) ?>
                                    <?php if ($totalVotes > 0 && !empty($mostSelected['indices'])): ?>
                                        <span style="font-size:8pt; color:#6d28d9; margin-left:8px; font-weight:bold;">
                                            (အများဆုံးရွေးချယ်မှု: <?php
                                            $printMostLabels = [];
                                            foreach ($mostSelected['indices'] as $msIdx) {
                                                if (isset($opts[$msIdx]))
                                                    $printMostLabels[] = $opts[$msIdx];
                                            }
                                            echo e(implode(' / ', $printMostLabels));
                                            ?>)
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php foreach ($opts as $idx => $opt):
                                    $votes = $qStats[$idx] ?? 0;
                                    $isMost = in_array($idx, $mostSelected['indices']);
                                    ?>
                                    <div style="display:flex; align-items:center; gap:6px; margin-bottom:3px; font-size:8.5pt;">
                                        <span
                                            style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?= $pColors[$idx] ?>; flex-shrink:0;"></span>
                                        <span
                                            style="flex:1; color:#334155;<?= $isMost ? 'font-weight:700;' : '' ?>"><?= e($opt) ?><?= $isMost ? ' ✓' : '' ?></span>
                                        <span style="color:#64748b; font-size:8pt; white-space:nowrap;"><?= $votes ?>
                                            <?= $votes != 1 ? ($LANG['votes'] ?? 'votes') : ($LANG['vote'] ?? 'vote') ?></span>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach ?>
            </div>
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
                                <?= e(displayQuestionNumber($cq['question_no'], $_SESSION['lang'] ?? 'en')) ?>
                                <?= e($cq['question_text']) ?>

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
            <div class="print-section-title"><?= $LANG['col_actions'] ?? 'Conclusion' ?> &
                <?= $LANG['col_actions'] ?? 'Recommendation' ?>
            </div>
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
<?php endif; ?>
<?php endif; ?>


<script>
    var currentFormId = <?= (int) $formId ?>;
    var LANG = <?= json_encode([
        'loading' => $LANG['loading'] ?? 'Loading...',
        'completed' => $LANG['completed'] ?? 'Completed',
        'pending' => $LANG['pending'] ?? 'Pending',
        'total_responses' => $LANG['total_responses'] ?? 'Total Responses',
        'no_data' => $LANG['no_data_yet'] ?? 'No data yet',
        'all_assigned_students' => $LANG['all_assigned_students'] ?? 'All Assigned Students',
    ]) ?>;

    function openStudentModal(type) {
        var modal = document.getElementById('studentModal');
        var title = document.getElementById('modalTitle');
        var body = document.getElementById('modalBody');
        if (type === 'all') title.textContent = LANG.all_assigned_students;
        if (type === 'completed') title.textContent = LANG.completed;
        if (type === 'pending') title.textContent = LANG.pending;
        body.innerHTML = '<p class="text-center text-slate-400 py-8 text-sm">' + LANG.loading + '</p>';
        modal.classList.remove('hidden');
        fetch('results_all.php?ajax_students=1&form_id=' + currentFormId + '&type=' + type)
            .then(function (r) { return r.json(); })
            .then(function (students) {
                if (students.error) { body.innerHTML = '<p class="text-center text-red-400 py-8 text-sm">' + students.error + '</p>'; return; }
                title.textContent = title.textContent + ' (' + students.length + ')';
                if (students.length === 0) { body.innerHTML = '<p class="text-center text-slate-400 py-8 text-sm">No students found.</p>'; return; }
                var html = '<div class="overflow-x-auto"><table class="w-full text-xs"><thead><tr class="border-b border-slate-200 bg-slate-50">';
                html += '<th class="text-left py-2.5 px-3 text-slate-500 font-semibold w-10">#</th>';
                html += '<th class="text-left py-2.5 px-3 text-slate-500 font-semibold">Roll No</th>';
                html += '<th class="text-left py-2.5 px-3 text-slate-500 font-semibold">Student Name</th>';
                html += (type === 'completed') ? '<th class="text-left py-2.5 px-3 text-slate-500 font-semibold">Submitted</th>' : '<th class="text-left py-2.5 px-3 text-slate-500 font-semibold"></th>';
                html += '</tr></thead><tbody class="divide-y divide-slate-100">';
                for (var i = 0; i < students.length; i++) {
                    var s = students[i];
                    html += '<tr class="hover:bg-slate-50">';
                    html += '<td class="py-2 px-3 text-slate-400 font-medium">' + (i + 1) + '</td>';
                    html += '<td class="py-2 px-3 font-mono font-semibold text-slate-700">' + escHtml(s.roll_no || '—') + '</td>';
                    html += '<td class="py-2 px-3 font-medium text-slate-800">' + escHtml(s.name) + '</td>';
                    if (type === 'completed') {
                        html += '<td class="py-2 px-3 text-slate-500">' + (s.submitted_at || '—') + '</td>';
                    }
                    html += '</tr>';
                }
                html += '</tbody></table></div>';
                body.innerHTML = html;
            })
            .catch(function () { body.innerHTML = '<p class="text-center text-red-400 py-8 text-sm">Failed to load student list.</p>'; });
    }
    function closeStudentModal() { document.getElementById('studentModal').classList.add('hidden'); }
    function escHtml(t) { var d = document.createElement('div'); d.appendChild(document.createTextNode(t || '')); return d.innerHTML; }
</script>

<div id="studentModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4"
    onclick="if(event.target===this)closeStudentModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 shrink-0">
            <h3 id="modalTitle" class="font-semibold text-slate-800">Student List</h3>
            <button onclick="closeStudentModal()"
                class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition-colors"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <div id="modalBody" class="px-6 py-4 overflow-y-auto flex-1"></div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var aySelect = document.getElementById('aySelect');
        var semSelect = document.getElementById('semSelect');
        var formSelect = document.getElementById('formSelect');
        var noFormsMsg = document.getElementById('noFormsMsg');

        if (!aySelect || !semSelect || !formSelect) return;

        function fetchForms() {
            var ayId = aySelect.value;
            var semId = semSelect.value;

            var url = 'results_all.php?ajax_forms=1&ay_id=' + encodeURIComponent(ayId) + '&sem_id=' + encodeURIComponent(semId);

            fetch(url)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    updateFormDropdown(data.forms || []);
                })
                .catch(function () {
                    // On error, keep current dropdown state
                });
        }

        function updateFormDropdown(forms) {
            var currentVal = formSelect.value;

            // Remove all options except the placeholder
            while (formSelect.options.length > 1) {
                formSelect.remove(1);
            }

            // Remove any existing optgroups
            var existingGroups = formSelect.querySelectorAll('optgroup');
            for (var g = 0; g < existingGroups.length; g++) {
                existingGroups[g].remove();
            }

            if (forms.length === 0) {
                // No forms found — just clear dropdown, don't touch results/message
                formSelect.value = '';
            } else {
                // Forms found — populate dropdown

                var currentModule = '';
                var currentOptgroup = null;
                var modLabels = {
                    'academic': 'Academic',
                    'student_affairs': 'Student Affairs',
                    'administration': 'Administration'
                };

                for (var i = 0; i < forms.length; i++) {
                    var f = forms[i];
                    if (f.module !== currentModule) {
                        currentModule = f.module;
                        currentOptgroup = document.createElement('optgroup');
                        currentOptgroup.label = modLabels[f.module] || f.module;
                        formSelect.appendChild(currentOptgroup);
                    }
                    var option = document.createElement('option');
                    option.value = f.id;
                    option.textContent = f.label;
                    currentOptgroup.appendChild(option);
                }

                // Try to restore previous selection
                if (currentVal) {
                    var restoreOption = formSelect.querySelector('option[value="' + currentVal + '"]');
                    if (restoreOption) {
                        formSelect.value = currentVal;
                    }
                }
            }
        }

        // Add event listeners for dynamic form loading
        aySelect.addEventListener('change', fetchForms);
        semSelect.addEventListener('change', fetchForms);
    });
</script>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script
    src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        const chartInstances = {};

        // Animate progress bars
        document.querySelectorAll(".progress-bar-fill[data-target-width]").forEach(function (bar) {
            setTimeout(function () {
                bar.style.width = bar.dataset.targetWidth;
            }, 400);
        });

        // Custom plugin: draw center text inside doughnut chart
        const centerTextPlugin = {
            id: 'centerText',
            afterDraw: function (chart) {
                const centerConfig = chart.options.plugins.centerText;
                if (!centerConfig || !centerConfig.display) return;
                const { ctx, chartArea } = chart;
                if (!chartArea) return;
                const centerX = (chartArea.left + chartArea.right) / 2;
                const centerY = (chartArea.top + chartArea.bottom) / 2;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                if (centerConfig.mainText) {
                    ctx.font = 'bold 22px Inter, sans-serif';
                    ctx.fillStyle = centerConfig.mainColor || '#1e293b';
                    ctx.fillText(centerConfig.mainText, centerX, centerY - 8);
                }
                if (centerConfig.subText) {
                    ctx.font = '600 9px Inter, sans-serif';
                    ctx.fillStyle = centerConfig.subColor || '#94a3b8';
                    ctx.fillText(centerConfig.subText, centerX, centerY + 12);
                }
                ctx.restore();
            }
        };
        Chart.register(centerTextPlugin);

        function createChart(canvasId, labels, data, colors, showLegend = false, centerText) {
            const canvas = document.getElementById(canvasId);
            if (!canvas) return;

            if (chartInstances[canvasId]) { chartInstances[canvasId].destroy(); }

            const isSurvey = canvas.getAttribute('data-type') === 'survey';
            const isOverall = canvas.getAttribute('data-type') === 'overall';

            const chartPlugins = [];
            if (isSurvey && typeof ChartDataLabels !== 'undefined') {
                chartPlugins.push(ChartDataLabels);
            }

            chartInstances[canvasId] = new Chart(canvas.getContext('2d'), {
                type: "doughnut",
                data: {
                    labels: labels,
                    datasets: [{
                        data: data,
                        backgroundColor: colors,
                        borderColor: isOverall ? "transparent" : "#ffffff",
                        borderWidth: isOverall ? 0 : 3,
                        hoverOffset: isOverall ? 0 : 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    cutout: isOverall ? "80%" : "55%",
                    layout: { padding: 0 },
                    plugins: {
                        legend: {
                            //display: showLegend && !isOverall,
                            display: false,
                            position: "bottom",
                            labels: { usePointStyle: true, color: "#475569", padding: 12, font: { size: 11 } }
                        },
                        tooltip: {
                            enabled: !isOverall,
                            backgroundColor: "rgba(15,23,42,.95)",
                            padding: 10,
                            titleFont: { size: 12, weight: 'bold' },
                            bodyFont: { size: 12 },
                            callbacks: {
                                title: function () { if (isSurvey) return ""; return null; },
                                label: function (ctx) {
                                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                    const pct = total ? ((ctx.raw / total) * 100).toFixed(1) : 0;
                                    if (isSurvey) return pct + "%";
                                    return ctx.label + ": " + ctx.raw + " (" + pct + "%)";
                                }
                            }
                        },
                        datalabels: false,
                        centerText: centerText || { display: false }
                    },
                    animation: { animateRotate: true, animateScale: true, duration: 900 }
                },
                plugins: chartPlugins
            });
        }

        // ==========================================
        // 🏆 Overall Score ခြုံငုံသုံးသပ်ချက် ဝိုင်းကွင်း
        // ==========================================
        <?php if (!empty($ratingQuestions)): ?>
            const scorePct = <?= (float) $overallPct ?>;
            const remainder = 100 - scorePct;
            const ringColor = "<?= match ($gradeColor) { 'emerald' => '#10b981', 'blue' => '#3b82f6', 'cyan' => '#06b6d4', 'amber' => '#f59e0b', 'red' => '#ef4444', default => '#6366f1'} ?>";

            createChart("overallRatingPieChart", ["Earned Score", "Remaining"], [scorePct, remainder], [ringColor, "rgba(255, 255, 255, 0.12)"], false);

            // Count-up animation for overall text percentage
            const pctDisplay = document.getElementById('ratingPctDisplay');
            if (pctDisplay) {
                let current = 0;
                const duration = 1200;
                const startTime = performance.now();
                function animateNumber(currentTime) {
                    const elapsed = currentTime - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    const eased = 1 - Math.pow(1 - progress, 3);
                    current = (eased * scorePct).toFixed(1);
                    pctDisplay.textContent = current + '%';
                    if (progress < 1) requestAnimationFrame(animateNumber);
                }
                requestAnimationFrame(animateNumber);
            }
        <?php endif; ?>

        // ===========================
        // Survey Charts (Screen UI)
        // ===========================
        <?php
        $surveyChartData = [];
        foreach ($surveyQuestions as $q) {
            $options = json_decode($q['options_json'] ?? '[]', true) ?: [];
            $stats = $surveyStats[$q['id']] ?? [];
            $colors = ["#7c3aed", "#2563eb", "#059669", "#d97706", "#dc2626", "#0891b2", "#8b5cf6", "#ec4899"];
            $labels = [];
            $data = [];
            $bg = [];
            foreach ($options as $i => $option) {
                $labels[] = $option;
                $data[] = (int) ($stats[$i] ?? 0);
                $bg[] = $colors[$i % count($colors)];
            }
            $surveyChartData[$q['id']] = ['labels' => $labels, 'data' => $data, 'colors' => $bg];
        }
        ?>

        const surveys = <?= json_encode($surveyChartData) ?>;
        Object.keys(surveys).forEach(function (id) {
            var total = surveys[id].data.reduce(function (a, b) { return a + b; }, 0);
            createChart("surveyChart_" + id, surveys[id].labels, surveys[id].data, surveys[id].colors, true, {
                display: true,
                mainText: total.toString(),
                subText: 'Total',
                mainColor: '#1e293b',
                subColor: '#94a3b8'
            });
        });

        // ===================================================
        // 🖨️ Survey Doughnut Charts Generation (Print Mode Only)
        // ===================================================
        <?php if (!empty($surveyQuestions)): ?>
            Object.keys(surveys).forEach(function (qid) {
                var cfg = surveys[qid];
                var printCanvas = document.getElementById('printChart_' + qid);
                if (!printCanvas) return;
                var total = cfg.data.reduce(function (a, b) { return a + b; }, 0);
                new Chart(printCanvas.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: cfg.labels,
                        datasets: [{ data: cfg.data, backgroundColor: cfg.colors, borderColor: '#fff', borderWidth: 2 }]
                    },
                    options: {
                        responsive: false, maintainAspectRatio: false, cutout: '62%',
                        layout: { padding: 0 },
                        plugins: {
                            legend: { display: false },
                            tooltip: { enabled: false },
                            // datalabels: {
                            //     color: '#fff', font: { weight: 'bold', size: 9 },
                            //     formatter: function (value, ctx) {
                            //         var sum = ctx.dataset.data.reduce(function (a, b) { return a + b; }, 0);
                            //         var pct = sum > 0 ? ((value / sum) * 100).toFixed(1) : 0;
                            //         if (pct === '0.0' || pct === 0) return '';
                            //         return pct + '%';
                            //     },
                            //     display: function (ctx) { return ctx.dataset.data[ctx.dataIndex] > 0; }
                            // },
                            datalabels: false,
                            //centerText: centerText || { display: false }
                            centerText: {
                                display: true,
                                // mainText: total.toString(),
                                // subText: 'Total',
                                // mainColor: '#0f172a',
                                // subColor: '#64748b'
                                mainText: total.toString(),
                                subText: 'Total',
                                mainColor: '#1e293b',
                                subColor: '#94a3b8'
                            }
                        },
                        animation: false
                    },
                    plugins: [ChartDataLabels]
                });
            });
        <?php endif; ?>
    });
</script>

<?php include '../includes/admin_footer.php'; ?>