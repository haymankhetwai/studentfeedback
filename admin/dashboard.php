<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

updateAllFeedbackStatuses($conn);

$pageTitle = $LANG['nav_dashboard'] ?? 'Dashboard';
$activeMenu = 'dashboard';

// AJAX endpoint for fetching academic forms by AY/Sem
if (isset($_GET['ajax_forms']) && $_GET['ajax_forms'] === '1') {
    header('Content-Type: application/json');
    $ajaxAY = (int) ($_GET['ay_id'] ?? 0);
    $ajaxSem = (int) ($_GET['sem_id'] ?? 0);

    $ajaxFormConds = ["ff.module = 'academic'"];
    $ajaxFormTypes = '';
    if ($ajaxAY) {
        $ajaxFormConds[] = "ff.academic_year_id=?";
        $ajaxFormTypes .= 'i';
    }
    if ($ajaxSem) {
        $ajaxFormConds[] = "ff.semester_id=?";
        $ajaxFormTypes .= 'i';
    }
    $ajaxFormWhere = 'WHERE ' . implode(' AND ', $ajaxFormConds);
    $ajaxFormSql = "SELECT ff.id, ff.title, ff.section_id, ff.academic_year_id, ff.semester_id,
        ay.year_name AS academic_year_name, sm.semester_name,
        c.course_code, c.course_name, sec.section AS section_name
        FROM feedback_forms ff
        LEFT JOIN academic_years ay ON ff.academic_year_id = ay.id
        LEFT JOIN semesters sm ON ff.semester_id = sm.id
        LEFT JOIN sections sec ON ff.section_id = sec.id
        LEFT JOIN courses c ON sec.course_id = c.id
        $ajaxFormWhere
        ORDER BY ay.year_name DESC, ff.id DESC";

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
        if (!empty($f['course_code'])) {
            $formLabel = ($f['course_code'] ?? '') . ' - ' . ($f['course_name'] ?? '') . ' - Section ' . ($f['section_name'] ?? '');
        } else {
            $formLabel = ($f['academic_year_name'] ?? '') . ' - ' . ($f['title'] ?? '');
        }
        $formattedForms[] = [
            'id' => (int) $f['id'],
            'label' => $formLabel,
        ];
    }

    echo json_encode(['forms' => $formattedForms]);
    exit;
}

function count_table($conn, $table, $where = '')
{
    $sql = "SELECT COUNT(*) AS cnt FROM `$table`" . ($where ? " WHERE $where" : '');
    return (int) $conn->query($sql)->fetch_assoc()['cnt'];
}

// ─── All Stats ────────────────────────────────────────────────
$stats = [
    ['label' => $LANG['total_students_stat'] ?? 'Total Students', 'value' => count_table($conn, 'students'), 'icon' => 'users', 'color' => 'blue', 'href' => '/studentfeedbackucsh/admin/students.php'],
    ['label' => $LANG['total_teachers_stat'] ?? 'Total Teachers', 'value' => count_table($conn, 'teachers'), 'icon' => 'user', 'color' => 'yellow', 'href' => '/studentfeedbackucsh/admin/teachers.php'],
    ['label' => $LANG['departments_stat'] ?? 'Departments', 'value' => count_table($conn, 'departments'), 'icon' => 'building', 'color' => 'red', 'href' => '/studentfeedbackucsh/admin/departments.php'],
    ['label' => $LANG['section_assignments_stat'] ?? 'Section Assignments', 'value' => count_table($conn, 'section_assignments'), 'icon' => 'link', 'color' => 'cyan', 'href' => '/studentfeedbackucsh/admin/section_assignments.php'],
    ['label' => $LANG['courses_stat'] ?? 'Courses', 'value' => count_table($conn, 'courses'), 'icon' => 'book', 'color' => 'teal', 'href' => '/studentfeedbackucsh/admin/courses.php'],
    ['label' => $LANG['sections_stat'] ?? 'Sections', 'value' => count_table($conn, 'sections'), 'icon' => 'grid', 'color' => 'purple', 'href' => '/studentfeedbackucsh/admin/sections.php'],
    ['label' => $LANG['total_academic_years_stat'] ?? 'Total Academic Years', 'value' => count_table($conn, 'academic_years'), 'icon' => 'academic', 'color' => 'blue', 'href' => '/studentfeedbackucsh/admin/academic_years.php'],
    ['label' => $LANG['total_question_sets_stat'] ?? 'Total Question Sets', 'value' => count_table($conn, 'feedback_question_sets'), 'icon' => 'clipboard', 'color' => 'yellow', 'href' => '/studentfeedbackucsh/admin/question_sets.php'],
];

$colorMap = [
    'blue' => ['bg' => 'bg-indigo-50', 'icon' => 'bg-indigo-600', 'text' => 'text-indigo-700'],
    'yellow' => ['bg' => 'bg-yellow-100', 'icon' => 'bg-yellow-400', 'text' => 'text-yellow-400'],
    'red' => ['bg' => 'bg-red-50', 'icon' => 'bg-red-600', 'text' => 'text-red-700'],
    'cyan' => ['bg' => 'bg-teal-50', 'icon' => 'bg-teal-400', 'text' => 'text-teal-700'],
    'teal' => ['bg' => 'bg-teal-50', 'icon' => 'bg-teal-600', 'text' => 'text-teal-700'],
    'purple' => ['bg' => 'bg-purple-50', 'icon' => 'bg-purple-600', 'text' => 'text-purple-700'],
];

// ─── Reports: Filter Inputs ───────────────────────────────────
$filterSem = (int) ($_GET['sem_id'] ?? 0);
$filterSemester = '';
if ($filterSem > 0) {
    $semLookup = $conn->query("SELECT semester_name FROM semesters WHERE id = " . (int) $filterSem);
    if ($semLookup && $semLookupRow = $semLookup->fetch_assoc()) {
        $filterSemester = $semLookupRow['semester_name'];
    }
}
$filterTeacher = (int) ($_GET['teacher_id'] ?? 0);
$filterCourse = (int) ($_GET['course_id'] ?? 0);
$filterSection = clean($_GET['section'] ?? '');
$filterAY = (int) ($_GET['ay_id'] ?? 0);
$filterFormId = (int) ($_GET['form_id'] ?? 0);
$saFilterSemester = clean($_GET['sa_semester'] ?? '');
$saFilterAY = (int) ($_GET['sa_ay_id'] ?? 0);
$admFilterSemester = clean($_GET['adm_semester'] ?? '');
$admFilterAY = (int) ($_GET['adm_ay_id'] ?? 0);

// ─── Reports: Filter Options ──────────────────────────────────
$allAcademicYears = $conn->query("SELECT id, year_name FROM academic_years WHERE status='active' ORDER BY year_name DESC")->fetch_all(MYSQLI_ASSOC);
$allSemesters = $conn->query("SELECT id, semester_name FROM semesters ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$semesters = $conn->query("SELECT DISTINCT sm.id, sm.semester_name FROM sections s LEFT JOIN semesters sm ON s.semester_id=sm.id WHERE sm.semester_name IS NOT NULL AND sm.semester_name != '' ORDER BY sm.id ASC")->fetch_all(MYSQLI_ASSOC);
$teachers = $conn->query("
    SELECT DISTINCT t.id, u.name AS teacher_name
    FROM feedback_submissions fs
    JOIN feedback_forms ff ON fs.form_id = ff.id
    JOIN sections s ON ff.section_id = s.id
    JOIN teachers t ON s.teacher_id = t.id
    JOIN users u ON t.user_id = u.id
    WHERE ff.module = 'academic'
    ORDER BY u.name ASC
")->fetch_all(MYSQLI_ASSOC);
$courses = $conn->query("
    SELECT DISTINCT c.id, c.course_name
    FROM sections s
    JOIN courses c ON s.course_id = c.id
    ORDER BY c.course_name ASC
")->fetch_all(MYSQLI_ASSOC);
$sections = $conn->query("
    SELECT DISTINCT s.section
    FROM sections s
    WHERE s.section != ''
    ORDER BY s.section ASC
")->fetch_all(MYSQLI_ASSOC);

// Academic feedback forms list (filtered by AY/Sem)
$afConds = ["ff.module = 'academic'"];
$afParams = '';
$afTypes = '';
if ($filterAY > 0) {
    $afConds[] = "ff.academic_year_id=?";
    $afParams .= 'i';
    $afTypes .= 'i';
}
if ($filterSemester !== '') {
    $afConds[] = "sm.semester_name=?";
    $afParams .= 's';
    $afTypes .= 's';
}
$afWhere = 'WHERE ' . implode(' AND ', $afConds);
$afSql = "SELECT ff.id, ff.title, c.course_code, c.course_name, sec.section AS section_name,
    ay.year_name AS academic_year_name, sm.semester_name
    FROM feedback_forms ff
    LEFT JOIN academic_years ay ON ff.academic_year_id = ay.id
    LEFT JOIN semesters sm ON ff.semester_id = sm.id
    LEFT JOIN sections sec ON ff.section_id = sec.id
    LEFT JOIN courses c ON sec.course_id = c.id
    $afWhere
    ORDER BY ay.year_name DESC, ff.id DESC";
if ($afTypes) {
    $afStmt = $conn->prepare($afSql);
    $afBind = [];
    if ($filterAY > 0) $afBind[] = $filterAY;
    if ($filterSemester !== '') $afBind[] = $filterSemester;
    $afStmt->bind_param($afTypes, ...$afBind);
    $afStmt->execute();
    $academicForms = $afStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $afStmt->close();
} else {
    $academicForms = $conn->query($afSql)->fetch_all(MYSQLI_ASSOC);
}

$saSemesters = $allSemesters;
$admSemesters = $allSemesters;

// ─── Reports: Helper: build dynamic WHERE clause for ratings ──
$whereParts = [];
$params = [];
$types = '';

if ($filterSemester !== '') {
    $whereParts[] = 'sm.semester_name = ?';
    $params[] = $filterSemester;
    $types .= 's';
}
if ($filterTeacher > 0) {
    $whereParts[] = 'sec.teacher_id = ?';
    $params[] = $filterTeacher;
    $types .= 'i';
}
if ($filterCourse > 0) {
    $whereParts[] = 'sec.course_id = ?';
    $params[] = $filterCourse;
    $types .= 'i';
}
if ($filterSection !== '') {
    $whereParts[] = 'sec.section = ?';
    $params[] = $filterSection;
    $types .= 's';
}
if ($filterAY > 0) {
    $whereParts[] = 'ff.academic_year_id = ?';
    $params[] = $filterAY;
    $types .= 'i';
}
if ($filterFormId > 0) {
    $whereParts[] = 'ff.id = ?';
    $params[] = $filterFormId;
    $types .= 'i';
}

$whereSql = '';
if ($whereParts) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereParts);
}

function runFilteredQuery($conn, $sql, $types, $params)
{
    if ($types === '')
        return $conn->query($sql);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

// ─── Reports: Summary Cards ───────────────────────────────────
$ratingCountSql = "
    SELECT COUNT(*) AS cnt
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    LEFT JOIN semesters sm ON sec.semester_id = sm.id
    $whereSql
";
$ratingCountResult = runFilteredQuery($conn, $ratingCountSql, $types, $params);
$totalRatings = (int) $ratingCountResult->fetch_assoc()['cnt'];

$submissionCountSql = "
    SELECT COUNT(DISTINCT fs.id) AS cnt
    FROM feedback_submissions fs
    JOIN feedback_forms ff ON fs.form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    LEFT JOIN semesters sm ON sec.semester_id = sm.id
    $whereSql
";
$submissionCountResult = runFilteredQuery($conn, $submissionCountSql, $types, $params);
$totalSubmissions = (int) $submissionCountResult->fetch_assoc()['cnt'];

$formCountSql = "
    SELECT COUNT(DISTINCT ff.id) AS cnt
    FROM feedback_forms ff
    JOIN sections sec ON ff.section_id = sec.id
    LEFT JOIN semesters sm ON sec.semester_id = sm.id
    $whereSql
";
$formCountResult = runFilteredQuery($conn, $formCountSql, $types, $params);
$totalForms = (int) $formCountResult->fetch_assoc()['cnt'];

$teacherCountSql = "
    SELECT COUNT(DISTINCT sec.teacher_id) AS cnt
    FROM feedback_submissions fs
    JOIN feedback_forms ff ON fs.form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    LEFT JOIN semesters sm ON sec.semester_id = sm.id
    $whereSql
";
$teacherCountResult = runFilteredQuery($conn, $teacherCountSql, $types, $params);
$totalTeachers = (int) $teacherCountResult->fetch_assoc()['cnt'];

// ─── Reports: Teacher Performance (Top 3 by positive feedback %) ──
$teacherPerfSql = "
    SELECT teacher_name, teacher_id, feedback_count,
           CASE WHEN feedback_count > 0
                THEN ROUND((good_count / feedback_count) * 100, 1)
                ELSE 0 END AS positive_pct
    FROM (
        SELECT u.name AS teacher_name,
               t.id AS teacher_id,
               COUNT(*) AS feedback_count,
               SUM(CASE WHEN fr.rating IN ('Excellent','Good','good','3','ကောင်း') THEN 1 ELSE 0 END) AS good_count
        FROM feedback_ratings fr
        JOIN feedback_forms ff ON fr.form_id = ff.id
        JOIN sections sec ON ff.section_id = sec.id
        LEFT JOIN semesters sm ON sec.semester_id = sm.id
        JOIN teachers t ON sec.teacher_id = t.id
        JOIN users u ON t.user_id = u.id
        $whereSql
        GROUP BY t.id, u.name
    ) AS teacher_stats
    ORDER BY positive_pct DESC, feedback_count DESC
    LIMIT 3
";
$teacherPerfResult = runFilteredQuery($conn, $teacherPerfSql, $types, $params);
$teacherPerfData = $teacherPerfResult->fetch_all(MYSQLI_ASSOC);

// ─── Reports: Per-Teacher Rating Distribution (Top 3 only) ───
$teacherRatingData = [];
foreach ($teacherPerfData as $tp) {
    $tid = (int) $tp['teacher_id'];
    $trSql = "
        SELECT fr.rating, COUNT(*) AS qty
        FROM feedback_ratings fr
        JOIN feedback_forms ff ON fr.form_id = ff.id
        JOIN sections sec ON ff.section_id = sec.id
        LEFT JOIN semesters sm ON sec.semester_id = sm.id
        JOIN teachers t ON sec.teacher_id = t.id
        WHERE t.id = ?
        " . ($filterSemester !== '' ? "AND sm.semester_name = ?" : "") . "
        " . ($filterCourse > 0 ? "AND sec.course_id = ?" : "") . "
        " . ($filterSection !== '' ? "AND sec.section = ?" : "") . "
        GROUP BY fr.rating
        ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor')
    ";
    $trTypes = 'i';
    $trParams = [$tid];
    if ($filterSemester !== '') {
        $trTypes .= 's';
        $trParams[] = $filterSemester;
    }
    if ($filterCourse > 0) {
        $trTypes .= 'i';
        $trParams[] = $filterCourse;
    }
    if ($filterSection !== '') {
        $trTypes .= 's';
        $trParams[] = $filterSection;
    }
    $trResult = runFilteredQuery($conn, $trSql, $trTypes, $trParams);
    $rawRatings = $trResult->fetch_all(MYSQLI_ASSOC);

    $normalized = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
    foreach ($rawRatings as $rd) {
        $r = trim($rd['rating']);
        if (in_array($r, ['Excellent', 'Good', 'good', '3', 'ကောင်း'])) {
            $normalized['Good'] += (int) $rd['qty'];
        } elseif (in_array($r, ['Fair', 'fair', 'Normal', 'normal', 'Average', '2', 'သင့်'])) {
            $normalized['Fair'] += (int) $rd['qty'];
        } elseif (in_array($r, ['Poor', 'Bad', 'bad', '1', 'ညံ့'])) {
            $normalized['Bad'] += (int) $rd['qty'];
        }
    }
    $totalForTeacher = $normalized['Good'] + $normalized['Fair'] + $normalized['Bad'];
    $positivePct = $tp['positive_pct'] ?? ($totalForTeacher > 0 ? round(($normalized['Good'] / $totalForTeacher) * 100, 1) : 0);

    $teacherRatingData[$tp['teacher_name']] = [
        'ratings' => [
            ['rating' => 'Good', 'qty' => $normalized['Good']],
            ['rating' => 'Fair', 'qty' => $normalized['Fair']],
            ['rating' => 'Bad', 'qty' => $normalized['Bad']],
        ],
        'positive_pct' => $positivePct,
        'feedback_count' => $totalForTeacher,
        'teacher_id' => $tid,
    ];
}

// ─── Reports: Build filter query string helper ────────────────
function buildFilterUrl(array $overrides = []): string
{
    $params = [];
    $semester = $overrides['semester'] ?? $_GET['semester'] ?? '';
    $teacher = $overrides['teacher_id'] ?? $_GET['teacher_id'] ?? '';
    $course = $overrides['course_id'] ?? $_GET['course_id'] ?? '';
    $section = $overrides['section'] ?? $_GET['section'] ?? '';
    if ($semester !== '')
        $params[] = 'semester=' . urlencode($semester);
    if ($teacher)
        $params[] = 'teacher_id=' . (int) $teacher;
    if ($course)
        $params[] = 'course_id=' . (int) $course;
    if ($section !== '')
        $params[] = 'section=' . urlencode($section);
    return '?' . implode('&', $params);
}

// ─── Reports: SA Feedback Statistics ──────────────────────────
// Default auto-load: latest semester with SA feedback
if ($saFilterSemester === '' && $saFilterAY === 0) {
    $latestSaQ = $conn->query("SELECT sm.semester_name FROM feedback_submissions fs JOIN feedback_forms ff ON fs.form_id = ff.id JOIN students st ON fs.student_id = st.id JOIN section_assignments sa ON sa.student_id = st.id JOIN sections s ON sa.section_id = s.id JOIN semesters sm ON s.semester_id = sm.id WHERE ff.module = 'student_affairs' ORDER BY sm.id DESC LIMIT 1");
    if ($latestSaQ && $latestSaRow = $latestSaQ->fetch_assoc()) {
        $saFilterSemester = $latestSaRow['semester_name'];
    }
}
$saAyWhere = $saFilterAY > 0 ? " AND ff.academic_year_id = $saFilterAY" : '';
if ($saFilterSemester !== '') {
    $semEsc = $conn->real_escape_string($saFilterSemester);
    $saRatingsJoin = " JOIN feedback_submissions fs ON fr.form_id = fs.form_id AND fr.created_at = fs.submitted_at JOIN students st ON fs.student_id = st.id JOIN section_assignments sa ON sa.student_id = st.id JOIN sections s ON sa.section_id = s.id JOIN semesters sm ON s.semester_id = sm.id";
    $saRatingsWhere = "AND sm.semester_name = '$semEsc'$saAyWhere";
    $saSubsJoin = " JOIN students st ON fs.student_id = st.id JOIN section_assignments sa ON sa.student_id = st.id JOIN sections s ON sa.section_id = s.id JOIN semesters sm ON s.semester_id = sm.id";
    $saSubsWhere = "AND sm.semester_name = '$semEsc'$saAyWhere";
    $saFormSubquery = "(SELECT DISTINCT fs.form_id FROM feedback_submissions fs JOIN students st ON fs.student_id = st.id JOIN section_assignments sa ON sa.student_id = st.id JOIN sections s ON sa.section_id = s.id JOIN semesters sm ON s.semester_id = sm.id WHERE sm.semester_name = '$semEsc')";
    $saFormWhere = "AND ff.id IN $saFormSubquery$saAyWhere";
} else {
    $saRatingsJoin = $saSubsJoin = '';
    $saRatingsWhere = $saAyWhere;
    $saSubsWhere = $saAyWhere;
    $saFormWhere = $saAyWhere;
}
$saTotalRatings = (int) $conn->query("SELECT COUNT(DISTINCT fr.id) AS cnt FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id$saRatingsJoin WHERE ff.module='student_affairs' $saRatingsWhere")->fetch_assoc()['cnt'];
$saTotalSubmissions = (int) $conn->query("SELECT COUNT(DISTINCT fs.id) AS cnt FROM feedback_submissions fs JOIN feedback_forms ff ON fs.form_id=ff.id$saSubsJoin WHERE ff.module='student_affairs' $saSubsWhere")->fetch_assoc()['cnt'];
$saTotalForms = (int) $conn->query("SELECT COUNT(DISTINCT ff.id) AS cnt FROM feedback_forms ff WHERE ff.module='student_affairs' $saFormWhere")->fetch_assoc()['cnt'];

$saRatingDist = $conn->query("SELECT fr.rating, COUNT(DISTINCT fr.id) AS qty FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id$saRatingsJoin WHERE ff.module='student_affairs' $saRatingsWhere GROUP BY fr.rating ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor', 'Bad')")->fetch_all(MYSQLI_ASSOC);
$saNormalized = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
foreach ($saRatingDist as $rd) {
    $r = trim($rd['rating']);
    if (in_array($r, ['Excellent', 'Good']))
        $saNormalized['Good'] += (int) $rd['qty'];
    elseif (in_array($r, ['Fair']))
        $saNormalized['Fair'] += (int) $rd['qty'];
    elseif (in_array($r, ['Poor', 'Bad']))
        $saNormalized['Bad'] += (int) $rd['qty'];
}

$saAvgResult = $conn->query("SELECT AVG(CASE WHEN fr.rating='Good' THEN 4 WHEN fr.rating='Fair' THEN 3 WHEN fr.rating IN ('Poor','Bad') THEN 1 ELSE 3 END) AS avg_rating FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id$saRatingsJoin WHERE ff.module='student_affairs' $saRatingsWhere")->fetch_assoc();
$saAvgRating = $saAvgResult['avg_rating'] ? round((float) $saAvgResult['avg_rating'], 2) : 0;

// ─── Reports: Admin Feedback Statistics ───────────────────────
// Default auto-load: latest semester with Admin feedback
if ($admFilterSemester === '' && $admFilterAY === 0) {
    $latestAdmQ = $conn->query("SELECT sm.semester_name FROM feedback_submissions fs JOIN feedback_forms ff ON fs.form_id = ff.id JOIN students st ON fs.student_id = st.id JOIN section_assignments sa ON sa.student_id = st.id JOIN sections s ON sa.section_id = s.id JOIN semesters sm ON s.semester_id = sm.id WHERE ff.module = 'administration' ORDER BY sm.id DESC LIMIT 1");
    if ($latestAdmQ && $latestAdmRow = $latestAdmQ->fetch_assoc()) {
        $admFilterSemester = $latestAdmRow['semester_name'];
    }
}
$admAyWhere = $admFilterAY > 0 ? " AND ff.academic_year_id = $admFilterAY" : '';
if ($admFilterSemester !== '') {
    $semEscAdm = $conn->real_escape_string($admFilterSemester);
    $admRatingsJoin = " JOIN feedback_submissions fs ON fr.form_id = fs.form_id AND fr.created_at = fs.submitted_at JOIN students st ON fs.student_id = st.id JOIN section_assignments sa ON sa.student_id = st.id JOIN sections s ON sa.section_id = s.id JOIN semesters sm ON s.semester_id = sm.id";
    $admRatingsWhere = "AND sm.semester_name = '$semEscAdm'$admAyWhere";
    $admSubsJoin = " JOIN students st ON fs.student_id = st.id JOIN section_assignments sa ON sa.student_id = st.id JOIN sections s ON sa.section_id = s.id JOIN semesters sm ON s.semester_id = sm.id";
    $admSubsWhere = "AND sm.semester_name = '$semEscAdm'$admAyWhere";
    $admFormSubquery = "(SELECT DISTINCT fs.form_id FROM feedback_submissions fs JOIN students st ON fs.student_id = st.id JOIN section_assignments sa ON sa.student_id = st.id JOIN sections s ON sa.section_id = s.id JOIN semesters sm ON s.semester_id = sm.id WHERE sm.semester_name = '$semEscAdm')";
    $admFormWhere = "AND ff.id IN $admFormSubquery$admAyWhere";
} else {
    $admRatingsJoin = $admSubsJoin = '';
    $admRatingsWhere = $admAyWhere;
    $admSubsWhere = $admAyWhere;
    $admFormWhere = $admAyWhere;
}
$admTotalRatings = (int) $conn->query("SELECT COUNT(DISTINCT fr.id) AS cnt FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id$admRatingsJoin WHERE ff.module='administration' $admRatingsWhere")->fetch_assoc()['cnt'];
$admTotalSubmissions = (int) $conn->query("SELECT COUNT(DISTINCT fs.id) AS cnt FROM feedback_submissions fs JOIN feedback_forms ff ON fs.form_id=ff.id$admSubsJoin WHERE ff.module='administration' $admSubsWhere")->fetch_assoc()['cnt'];
$admTotalForms = (int) $conn->query("SELECT COUNT(DISTINCT ff.id) AS cnt FROM feedback_forms ff WHERE ff.module='administration' $admFormWhere")->fetch_assoc()['cnt'];

$admRatingDist = $conn->query("SELECT fr.rating, COUNT(DISTINCT fr.id) AS qty FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id$admRatingsJoin WHERE ff.module='administration' $admRatingsWhere GROUP BY fr.rating ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor', 'Bad')")->fetch_all(MYSQLI_ASSOC);
$admNormalized = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
foreach ($admRatingDist as $rd) {
    $r = trim($rd['rating']);
    if (in_array($r, ['Excellent', 'Good']))
        $admNormalized['Good'] += (int) $rd['qty'];
    elseif (in_array($r, ['Fair']))
        $admNormalized['Fair'] += (int) $rd['qty'];
    elseif (in_array($r, ['Poor', 'Bad']))
        $admNormalized['Bad'] += (int) $rd['qty'];
}

$admAvgResult = $conn->query("SELECT AVG(CASE WHEN fr.rating='Good' THEN 4 WHEN fr.rating='Fair' THEN 3 WHEN fr.rating IN ('Poor','Bad') THEN 1 ELSE 3 END) AS avg_rating FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id$admRatingsJoin WHERE ff.module='administration' $admRatingsWhere")->fetch_assoc();
$admAvgRating = $admAvgResult['avg_rating'] ? round((float) $admAvgResult['avg_rating'], 2) : 0;

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<style>
    .filter-active {
        border-color: #0891b2 !important;
        box-shadow: 0 0 0 1px #0891b2;
    }
</style>

<!-- Page Header -->
<div class="mb-6">
    <h2 class="text-2xl font-bold text-slate-800"><?= $LANG['admin_welcome'] ?? 'Welcome back' ?>,
        <?= e($user['name']) ?> 👋
    </h2>
    <p class="text-sm text-slate-500 mt-1">
        <?= $LANG['admin_overview'] ?? 'University Feedback Management System — Full Overview' ?>
    </p>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-4 mb-8">
    <?php foreach ($stats as $s):
        $c = $colorMap[$s['color']];
        ?>
        <a href="<?= $s['href'] ?>"
            class="group bg-white rounded-2xl shadow-sm hover:shadow-md border border-slate-100 p-4 flex items-center gap-3 transition-all hover:-translate-y-0.5">
            <div class="w-11 h-11 rounded-xl <?= $c['icon'] ?> flex items-center justify-center flex-shrink-0 shadow-sm">
                <?= iconSvg($s['icon'], 'w-5 h-5 text-white') ?>
            </div>
            <div>
                <p class="text-xl font-bold <?= $c['text'] ?>"><?= number_format($s['value']) ?></p>
                <p class="text-xs text-slate-500 font-medium leading-tight mt-0.5"><?= e($s['label']) ?></p>
            </div>
        </a>
    <?php endforeach ?>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- ACADEMIC FEEDBACK SECTION                                 -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="academic-feedback" class="mb-2">
    <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
        <span class="w-2 h-2 rounded-full bg-cyan-500 inline-block"></span>
        <?= $LANG['academic_feedback'] ?? 'Academic Feedback' ?>
    </h3>
</div>

<!-- Filters -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6">
    <form method="GET" action="#academic-feedback" class="flex flex-col sm:flex-row gap-4 items-end">
        <div class="flex-1 min-w-[160px]">
            <label class="block text-xs font-bold text-slate-500 mb-1"><?= $LANG["academic_year"] ?? "Academic Year" ?></label>
            <select name="ay_id" id="aySelect"
                class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white font-semibold text-slate-700 shadow-sm focus:border-slate-500">
                <option value=""><?= $LANG["all_academic_years"] ?? "All Academic Years" ?></option>
                <?php foreach ($allAcademicYears as $ay): ?>
                    <option value="<?= $ay['id'] ?>" <?= $filterAY == $ay['id'] ? 'selected' : '' ?>>
                        <?= e($ay['year_name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="flex-1 min-w-[160px]">
            <label class="block text-xs font-bold text-slate-500 mb-1"><?= $LANG["semester_filter"] ?? "Semester" ?></label>
            <select name="sem_id" id="semSelect"
                class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white font-semibold text-slate-700 shadow-sm focus:border-slate-500">
                <option value=""><?= $LANG['all_semesters'] ?? 'All Semesters' ?></option>
                <?php foreach ($allSemesters as $sm): ?>
                    <option value="<?= $sm['id'] ?>" <?= $filterSem == $sm['id'] ? 'selected' : '' ?>>
                        <?= e(semesterToRoman($sm['semester_name'])) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="flex-1 max-w-xl">
            <label class="block text-xs font-bold text-slate-500 mb-1"><?= $LANG["choose_form"] ?? "Choose a Feedback Form" ?></label>
            <select name="form_id" id="formSelect"
                class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white font-semibold text-slate-700 shadow-sm focus:border-slate-500">
                <option value=""><?= $LANG["choose_form_placeholder"] ?? "— Choose a Feedback Form —" ?></option>
                <?php foreach ($academicForms as $af):
                    if (!empty($af['course_code'])) {
                        $afLabel = e($af['course_code']) . ' - ' . e($af['course_name']) . ' - Section ' . e($af['section_name']);
                    } else {
                        $afLabel = e($af['academic_year_name'] ?? '') . ' - ' . e($af['title']);
                    }
                ?>
                    <option value="<?= $af['id'] ?>" <?= $filterFormId == $af['id'] ? 'selected' : '' ?>>
                        <?= $afLabel ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="flex gap-2 shrink-0">
            <button type="submit"
                class="px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-xl shadow-sm transition-all h-[42px]"><?= $LANG["search"] ?? "Search" ?></button>
            <a href="dashboard.php#academic-feedback"
                class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-600 text-sm font-semibold rounded-xl transition-all h-[42px] inline-flex items-center"><?= $LANG["reset"] ?? "Reset" ?></a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-indigo-600 flex items-center justify-center shadow-sm">
            <?= iconSvg('academic', 'w-5 h-5 text-white') ?>
        </div>
        <div>
            <p class="text-xl font-bold text-blue-700"><?= number_format($totalSubmissions) ?></p>
            <p class="text-[10px] text-slate-500 font-medium"><?= $LANG['submissions_stat'] ?? 'Submissions' ?></p>
        </div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-yellow-400 flex items-center justify-center shadow-sm">
            <?= iconSvg('user', 'w-5 h-5 text-white') ?>
        </div>
        <div>
            <p class="text-xl font-bold text-yellow-400"><?= number_format($totalTeachers) ?></p>
            <p class="text-[10px] text-slate-500 font-medium"><?= $LANG['teachers_stat'] ?? 'Teachers' ?></p>
        </div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-indigo-600 flex items-center justify-center shadow-sm">
            <?= iconSvg('document', 'w-5 h-5 text-white') ?>
        </div>
        <div>
            <p class="text-xl font-bold text-emerald-700"><?= number_format($totalForms) ?></p>
            <p class="text-[10px] text-slate-500 font-medium"><?= $LANG['forms_stat'] ?? 'Forms' ?></p>
        </div>
    </div>

</div>



<!-- Top 3 Teachers Rating Distribution -->
<?php if (!empty($teacherRatingData)): ?>
    <div class="mb-6">
        <h3 class="text-sm font-bold text-slate-800 mb-3">
            <?= $LANG['top3_teacher_rating'] ?? 'Top 3 Teachers — Rating Distribution' ?>
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
            <?php
            $chartColorMap = ['Good' => '#22c55e', 'Fair' => '#f59e0b', 'Bad' => '#ef4444'];
            $rankBadges = [
                1 => ['bg' => 'bg-yellow-400', 'label' => '🥇', 'text' => 'text-yellow-800', 'border' => 'border-yellow-300'],
                2 => ['bg' => 'bg-slate-300', 'label' => '🥈', 'text' => 'text-slate-700', 'border' => 'border-slate-300'],
                3 => ['bg' => 'bg-amber-600', 'label' => '🥉', 'text' => 'text-amber-800', 'border' => 'border-amber-400'],
            ];
            $chartIndex = 0;
            foreach ($teacherRatingData as $tName => $tData):
                if (empty($tData['ratings']))
                    continue;
                $rank = $chartIndex + 1;
                $badge = $rankBadges[$rank] ?? $rankBadges[3];
                $tLabels = array_column($tData['ratings'], 'rating');
                $tValues = array_column($tData['ratings'], 'qty');
                $tColors = array_map(fn($l) => $chartColorMap[$l] ?? '#6366f1', $tLabels);

                $tGood = $tData['ratings'][0]['qty'] ?? 0;
                $tFair = $tData['ratings'][1]['qty'] ?? 0;
                $tBad = $tData['ratings'][2]['qty'] ?? 0;
                $tTotal = $tGood + $tFair + $tBad;
                $tPctGood = $tTotal > 0 ? round(($tGood / $tTotal) * 100) : 0;
                $tPctFair = $tTotal > 0 ? round(($tFair / $tTotal) * 100) : 0;
                $tPctBad = $tTotal > 0 ? round(($tBad / $tTotal) * 100) : 0;
                ?>
                <div class="bg-white rounded-2xl shadow-sm border <?= $badge['border'] ?> p-4 relative overflow-hidden">
                    <!-- Rank badge -->
                    <div
                        class="absolute top-3 right-3 w-8 h-8 rounded-full <?= $badge['bg'] ?> flex items-center justify-center text-sm shadow-sm">
                        <?= $badge['label'] ?>
                    </div>

                    <div class="flex items-center gap-3 mb-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center flex-shrink-0 shadow-sm">
                            <?= iconSvg('user', 'w-5 h-5 text-white') ?>
                        </div>
                        <div class="min-w-0">
                            <h4 class="text-xs font-bold text-slate-700 truncate" title="<?= e($tName) ?>"><?= e($tName) ?></h4>
                            <p class="text-[10px] text-slate-500 font-medium">
                                <?= number_format($tTotal) ?>         <?= $LANG['ratings'] ?? 'ratings' ?>
                                · <span class="text-cyan-600 font-bold"><?= $tData['positive_pct'] ?>%</span>
                                <?= $LANG['positive'] ?? 'positive' ?>
                            </p>
                        </div>
                    </div>

                    <div class="relative flex items-center justify-center" style="height:180px;">
                        <canvas id="teacherBar<?= $chartIndex ?>"></canvas>
                    </div>

                    <div class="grid grid-cols-3 gap-2 mt-3">
                        <div class="text-center p-2 rounded-lg bg-emerald-50 border border-emerald-100">
                            <p class="text-lg font-bold text-emerald-600"><?= $tPctGood ?>%</p>
                            <p class="text-[10px] font-semibold text-emerald-700"><?= $LANG['good'] ?? 'Good' ?></p>
                            <p class="text-[9px] text-slate-400"><?= number_format($tGood) ?>
                                <?= $LANG['ratings'] ?? 'ratings' ?></p>
                        </div>
                        <div class="text-center p-2 rounded-lg bg-amber-50 border border-amber-100">
                            <p class="text-lg font-bold text-amber-600"><?= $tPctFair ?>%</p>
                            <p class="text-[10px] font-semibold text-amber-700"><?= $LANG['fair'] ?? 'Fair' ?></p>
                            <p class="text-[9px] text-slate-400"><?= number_format($tFair) ?>
                                <?= $LANG['ratings'] ?? 'ratings' ?></p>
                        </div>
                        <div class="text-center p-2 rounded-lg bg-red-50 border border-red-100">
                            <p class="text-lg font-bold text-red-600"><?= $tPctBad ?>%</p>
                            <p class="text-[10px] font-semibold text-red-700"><?= $LANG['bad'] ?? 'Bad' ?></p>
                            <p class="text-[9px] text-slate-400"><?= number_format($tBad) ?>
                                <?= $LANG['ratings'] ?? 'ratings' ?></p>
                        </div>
                    </div>
                </div>
                <?php $chartIndex++; endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- SA & ADMIN FEEDBACK — Side by Side                         -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 border-t-2 border-slate-200 pt-6 mb-6">

    <!-- ─── STUDENT AFFAIRS FEEDBACK ──────────────────────────── -->
    <div id="sa-feedback">
        <h3 class="text-base font-bold text-slate-800 flex items-center gap-2 mb-3">
            <span class="w-2 h-2 rounded-full bg-purple-500 inline-block"></span>
            <?= $LANG['student_affairs_feedback'] ?? 'Student Affairs Feedback' ?>
        </h3>

        <!-- SA Semester Filter -->
        <form method="GET" action="#sa-feedback"
            class="bg-white rounded-xl shadow-sm border border-slate-100 p-3 mb-3">
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-semibold text-slate-500 mb-1"><?= $LANG["academic_year"] ?? "Academic Year" ?></label>
                    <select name="sa_ay_id"
                        class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        <option value=""><?= $LANG["all_academic_years"] ?? "All Academic Years" ?></option>
                        <?php foreach ($allAcademicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= $saFilterAY == $ay['id'] ? 'selected' : '' ?>>
                                <?= e($ay['year_name']) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-semibold text-slate-500 mb-1"><?= $LANG['semester_filter'] ?? 'Semester' ?></label>
                    <select name="sa_semester"
                        class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                        <option value=""><?= $LANG['all_semesters'] ?? 'All Semesters' ?></option>
                        <?php foreach ($allSemesters as $s): ?>
                            <option value="<?= e($s['semester_name']) ?>" <?= $saFilterSemester === $s['semester_name'] ? 'selected' : '' ?>>
                                <?= e(semesterToRoman($s['semester_name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-1.5">
                    <button type="submit"
                        class="px-3 py-1.5 bg-indigo-600 text-white text-xs font-semibold rounded-lg hover:bg-indigo-700 transition-colors">
                        <?= $LANG['filter'] ?? 'Filter' ?>
                    </button>
                    <a href="dashboard.php#sa-feedback"
                        class="px-3 py-1.5 bg-slate-100 text-slate-600 text-xs font-semibold rounded-lg hover:bg-slate-200 transition-colors">
                        <?= $LANG['reset'] ?? 'Reset' ?>
                    </a>
                </div>
            </div>
        </form>

        <!-- SA Summary Cards -->
        <div class="grid grid-cols-2 gap-3 mb-3">
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-3 flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-violet-600 flex items-center justify-center shadow-sm flex-shrink-0">
                    <?= iconSvg('academic', 'w-4 h-4 text-white') ?>
                </div>
                <div>
                    <p class="text-base font-bold text-violet-700"><?= number_format($saTotalSubmissions) ?></p>
                    <p class="text-[9px] text-slate-500 font-medium"><?= $LANG['sa_submissions'] ?? 'SA Submissions' ?>
                    </p>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-3 flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-purple-500 flex items-center justify-center shadow-sm flex-shrink-0">
                    <?= iconSvg('document', 'w-4 h-4 text-white') ?>
                </div>
                <div>
                    <p class="text-base font-bold text-purple-600"><?= number_format($saTotalForms) ?></p>
                    <p class="text-[9px] text-slate-500 font-medium"><?= $LANG['sa_forms_stat'] ?? 'SA Forms' ?></p>
                </div>
            </div>
        </div>

        <!-- SA Chart -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4">
            <h4 class="text-xs font-bold text-slate-800 mb-2">
                <?= $LANG['sa_rating_dist'] ?? 'SA Rating Distribution (Good / Fair / Bad)' ?>
            </h4>
            <div class="relative flex items-center justify-center" style="height:200px;">
                <canvas id="saRatingBar"></canvas>
            </div>
            <?php
            $saTotal = array_sum($saNormalized);
            $saPctGood = $saTotal > 0 ? round(($saNormalized['Good'] / $saTotal) * 100) : 0;
            $saPctFair = $saTotal > 0 ? round(($saNormalized['Fair'] / $saTotal) * 100) : 0;
            $saPctBad = $saTotal > 0 ? round(($saNormalized['Bad'] / $saTotal) * 100) : 0;
            ?>
            <div class="grid grid-cols-3 gap-2 mt-3">
                <div class="text-center p-2 rounded-lg bg-emerald-50 border border-emerald-200">
                    <p class="text-lg font-bold text-emerald-600"><?= $saPctGood ?>%</p>
                    <p class="text-[10px] font-semibold text-emerald-700"><?= $LANG['good'] ?? 'Good' ?></p>
                    <p class="text-[9px] text-slate-500"><?= number_format($saNormalized['Good']) ?>
                        <?= $LANG['ratings'] ?? 'ratings' ?>
                    </p>
                </div>
                <div class="text-center p-2 rounded-lg bg-amber-50 border border-amber-200">
                    <p class="text-lg font-bold text-amber-600"><?= $saPctFair ?>%</p>
                    <p class="text-[10px] font-semibold text-amber-700"><?= $LANG['fair'] ?? 'Fair' ?></p>
                    <p class="text-[9px] text-slate-500"><?= number_format($saNormalized['Fair']) ?>
                        <?= $LANG['ratings'] ?? 'ratings' ?>
                    </p>
                </div>
                <div class="text-center p-2 rounded-lg bg-red-50 border border-red-200">
                    <p class="text-lg font-bold text-red-600"><?= $saPctBad ?>%</p>
                    <p class="text-[10px] font-semibold text-red-700"><?= $LANG['bad'] ?? 'Bad' ?></p>
                    <p class="text-[9px] text-slate-500"><?= number_format($saNormalized['Bad']) ?>
                        <?= $LANG['ratings'] ?? 'ratings' ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- ─── ADMINISTRATION FEEDBACK ───────────────────────────── -->
    <div id="admin-feedback">
        <h3 class="text-base font-bold text-slate-800 flex items-center gap-2 mb-3">
            <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
            <?= $LANG['administration_feedback'] ?? 'Administration Feedback' ?>
        </h3>

        <!-- Admin Semester Filter -->
        <form method="GET" action="#admin-feedback"
            class="bg-white rounded-xl shadow-sm border border-slate-100 p-3 mb-3">
            <div class="flex flex-wrap items-end gap-3">
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-semibold text-slate-500 mb-1"><?= $LANG["academic_year"] ?? "Academic Year" ?></label>
                    <select name="adm_ay_id"
                        class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                        <option value=""><?= $LANG["all_academic_years"] ?? "All Academic Years" ?></option>
                        <?php foreach ($allAcademicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>" <?= $admFilterAY == $ay['id'] ? 'selected' : '' ?>>
                                <?= e($ay['year_name']) ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="flex-1 min-w-[140px]">
                    <label class="block text-[10px] font-semibold text-slate-500 mb-1"><?= $LANG['semester_filter'] ?? 'Semester' ?></label>
                    <select name="adm_semester"
                        class="w-full rounded-lg border border-slate-200 px-2 py-1.5 text-xs focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                        <option value=""><?= $LANG['all_semesters'] ?? 'All Semesters' ?></option>
                        <?php foreach ($allSemesters as $s): ?>
                            <option value="<?= e($s['semester_name']) ?>" <?= $admFilterSemester === $s['semester_name'] ? 'selected' : '' ?>>
                                <?= e(semesterToRoman($s['semester_name'])) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex gap-1.5">
                    <button type="submit"
                        class="px-3 py-1.5 bg-orange-600 text-white text-xs font-semibold rounded-lg hover:bg-orange-700 transition-colors">
                        <?= $LANG['filter'] ?? 'Filter' ?>
                    </button>
                    <a href="dashboard.php#admin-feedback"
                        class="px-3 py-1.5 bg-slate-100 text-slate-600 text-xs font-semibold rounded-lg hover:bg-slate-200 transition-colors">
                        <?= $LANG['reset'] ?? 'Reset' ?>
                    </a>
                </div>
            </div>
        </form>

        <!-- Admin Summary Cards -->
        <div class="grid grid-cols-2 gap-3 mb-3">
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-3 flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-orange-500 flex items-center justify-center shadow-sm flex-shrink-0">
                    <?= iconSvg('academic', 'w-4 h-4 text-white') ?>
                </div>
                <div>
                    <p class="text-base font-bold text-orange-600"><?= number_format($admTotalSubmissions) ?></p>
                    <p class="text-[9px] text-slate-500 font-medium">
                        <?= $LANG['adm_submissions'] ?? 'Adm Submissions' ?></p>
                </div>
            </div>
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-3 flex items-center gap-2">
                <div class="w-8 h-8 rounded-lg bg-orange-400 flex items-center justify-center shadow-sm flex-shrink-0">
                    <?= iconSvg('document', 'w-4 h-4 text-white') ?>
                </div>
                <div>
                    <p class="text-base font-bold text-orange-500"><?= number_format($admTotalForms) ?></p>
                    <p class="text-[9px] text-slate-500 font-medium"><?= $LANG['adm_forms_stat'] ?? 'Adm Forms' ?></p>
                </div>
            </div>
        </div>

        <!-- Admin Chart -->
        <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-4">
            <h4 class="text-xs font-bold text-slate-800 mb-2">
                <?= $LANG['adm_rating_dist'] ?? 'Adm Rating Distribution (Good / Fair / Bad)' ?>
            </h4>
            <div class="relative flex items-center justify-center" style="height:200px;">
                <canvas id="admRatingBar"></canvas>
            </div>
            <?php
            $admTotal = array_sum($admNormalized);
            $admPctGood = $admTotal > 0 ? round(($admNormalized['Good'] / $admTotal) * 100) : 0;
            $admPctFair = $admTotal > 0 ? round(($admNormalized['Fair'] / $admTotal) * 100) : 0;
            $admPctBad = $admTotal > 0 ? round(($admNormalized['Bad'] / $admTotal) * 100) : 0;
            ?>
            <div class="grid grid-cols-3 gap-2 mt-3">
                <div class="text-center p-2 rounded-lg bg-emerald-50 border border-emerald-200">
                    <p class="text-lg font-bold text-emerald-600"><?= $admPctGood ?>%</p>
                    <p class="text-[10px] font-semibold text-emerald-700"><?= $LANG['good'] ?? 'Good' ?></p>
                    <p class="text-[9px] text-slate-500"><?= number_format($admNormalized['Good']) ?>
                        <?= $LANG['ratings'] ?? 'ratings' ?>
                    </p>
                </div>
                <div class="text-center p-2 rounded-lg bg-amber-50 border border-amber-200">
                    <p class="text-lg font-bold text-amber-600"><?= $admPctFair ?>%</p>
                    <p class="text-[10px] font-semibold text-amber-700"><?= $LANG['fair'] ?? 'Fair' ?></p>
                    <p class="text-[9px] text-slate-500"><?= number_format($admNormalized['Fair']) ?>
                        <?= $LANG['ratings'] ?? 'ratings' ?>
                    </p>
                </div>
                <div class="text-center p-2 rounded-lg bg-red-50 border border-red-200">
                    <p class="text-lg font-bold text-red-600"><?= $admPctBad ?>%</p>
                    <p class="text-[10px] font-semibold text-red-700"><?= $LANG['bad'] ?? 'Bad' ?></p>
                    <p class="text-[9px] text-slate-500"><?= number_format($admNormalized['Bad']) ?>
                        <?= $LANG['ratings'] ?? 'ratings' ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        var aySelect = document.getElementById('aySelect');
        var semSelect = document.getElementById('semSelect');
        var formSelect = document.getElementById('formSelect');

        if (!aySelect || !semSelect || !formSelect) return;

        function fetchForms() {
            var ayId = aySelect.value;
            var semId = semSelect.value;

            var url = 'dashboard.php?ajax_forms=1&ay_id=' + encodeURIComponent(ayId) + '&sem_id=' + encodeURIComponent(semId);

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

            if (forms.length === 0) {
                formSelect.value = '';
            } else {
                for (var i = 0; i < forms.length; i++) {
                    var f = forms[i];
                    var option = document.createElement('option');
                    option.value = f.id;
                    option.textContent = f.label;
                    formSelect.appendChild(option);
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

        aySelect.addEventListener('change', fetchForms);
        semSelect.addEventListener('change', fetchForms);
    });
</script>

<!-- ─── Chart Initialization Scripts ──────────────────────────── -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var chartDefaults = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { labels: { font: { family: 'Inter', size: 11 } } },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                            var pct = total > 0 ? Math.round((ctx.raw / total) * 100) : 0;
                            return ctx.raw + ' (' + pct + '%)';
                        }
                    }
                }
            }
        };

        // ─── Top 3 Per-Teacher Bar Charts ─────────────────────────
        <?php
        $pIndex = 0;
        foreach ($teacherRatingData as $tName => $tData):
            if (empty($tData['ratings']))
                continue;
            $tLabels = array_column($tData['ratings'], 'rating');
            $tValues = array_column($tData['ratings'], 'qty');
            $tColors = array_map(fn($l) => $chartColorMap[$l] ?? '#6366f1', $tLabels);
            ?>
            new Chart(document.getElementById('teacherBar<?= $pIndex ?>'), {
                type: 'bar',
                data: {
                    labels: <?= json_encode($tLabels) ?>,
                    datasets: [{
                        data: <?= json_encode($tValues) ?>,
                        backgroundColor: <?= json_encode($tColors) ?>,
                        borderWidth: 0,
                        borderRadius: 6,
                        barPercentage: 0.6
                    }]
                },
                options: Object.assign({}, chartDefaults, {
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function(ctx) {
                                    var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                    var pct = total > 0 ? Math.round((ctx.raw / total) * 100) : 0;
                                    return ctx.label + ': ' + ctx.raw + ' (' + pct + '%)';
                                }
                            }
                        }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                        y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, stepSize: 1 } }
                    }
                })
            });
            <?php $pIndex++; endforeach; ?>

        // ─── SA Rating Bar ────────────────────────────────────────
        new Chart(document.getElementById('saRatingBar'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($saNormalized)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($saNormalized)) ?>,
                    backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
                    borderWidth: 0,
                    borderRadius: 6,
                    barPercentage: 0.55
                }]
            },
            options: Object.assign({}, chartDefaults, {
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                var pct = total > 0 ? Math.round((ctx.raw / total) * 100) : 0;
                                return ctx.label + ': ' + ctx.raw + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, stepSize: 1 } }
                }
            })
        });

        // ─── Admin Rating Bar ─────────────────────────────────────
        new Chart(document.getElementById('admRatingBar'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($admNormalized)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($admNormalized)) ?>,
                    backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
                    borderWidth: 0,
                    borderRadius: 6,
                    barPercentage: 0.55
                }]
            },
            options: Object.assign({}, chartDefaults, {
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                                var pct = total > 0 ? Math.round((ctx.raw / total) * 100) : 0;
                                return ctx.label + ': ' + ctx.raw + ' (' + pct + '%)';
                            }
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, stepSize: 1 } }
                }
            })
        });

    });
</script>

<?php include '../includes/admin_footer.php'; ?>