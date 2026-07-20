<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

updateAllFeedbackStatuses($conn);

$pageTitle  = $LANG['reports_title'] ?? 'Reports & Analytics';
$activeMenu = 'reports';

// ─── Filter Inputs ─────────────────────────────────────────────
$filterAcademicYear = (int)($_GET['academic_year'] ?? 0);
$filterSemester     = (int)($_GET['semester'] ?? 0);
$filterTeacher      = (int)($_GET['teacher_id'] ?? 0);
$filterCourse       = (int)($_GET['course_id'] ?? 0);
$filterSection      = clean($_GET['section'] ?? '');

$saFilterAcademicYear = (int)($_GET['sa_academic_year'] ?? 0);
$saFilterSemester     = (int)($_GET['sa_semester'] ?? 0);

$admFilterAcademicYear = (int)($_GET['adm_academic_year'] ?? 0);
$admFilterSemester     = (int)($_GET['adm_semester'] ?? 0);

// ─── Filter Options ────────────────────────────────────────────
$academicYears = $conn->query("SELECT id, year_name, status FROM academic_years ORDER BY year_name DESC")->fetch_all(MYSQLI_ASSOC);
$semesters     = $conn->query("SELECT id, semester_name FROM semesters ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

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

$saSemesters = $conn->query("
    SELECT DISTINCT sem.id, sem.semester_name
    FROM feedback_submissions fs
    JOIN feedback_forms ff ON fs.form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    JOIN semesters sem ON sec.semester_id = sem.id
    WHERE ff.module='student_affairs'
    ORDER BY sem.id DESC
")->fetch_all(MYSQLI_ASSOC);

$admSemesters = $conn->query("
    SELECT DISTINCT sem.id, sem.semester_name
    FROM feedback_submissions fs
    JOIN feedback_forms ff ON fs.form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    JOIN semesters sem ON sec.semester_id = sem.id
    WHERE ff.module='administration'
    ORDER BY sem.id DESC
")->fetch_all(MYSQLI_ASSOC);

// ─── Helper: build dynamic WHERE clause for ratings ────────────
$whereParts = [];
$params     = [];
$types      = '';

if ($filterAcademicYear > 0) {
    $whereParts[] = 'sec.academic_year_id = ?';
    $params[]     = $filterAcademicYear;
    $types       .= 'i';
}
if ($filterSemester > 0) {
    $whereParts[] = 'sec.semester_id = ?';
    $params[]     = $filterSemester;
    $types       .= 'i';
}
if ($filterTeacher > 0) {
    $whereParts[] = 'sec.teacher_id = ?';
    $params[]     = $filterTeacher;
    $types       .= 'i';
}
if ($filterCourse > 0) {
    $whereParts[] = 'sec.course_id = ?';
    $params[]     = $filterCourse;
    $types       .= 'i';
}
if ($filterSection !== '') {
    $whereParts[] = 'sec.section = ?';
    $params[]     = $filterSection;
    $types       .= 's';
}

$whereSql = '';
if ($whereParts) {
    $whereSql = 'WHERE ' . implode(' AND ', $whereParts);
}

function runFilteredQuery($conn, $sql, $types, $params) {
    if ($types === '') return $conn->query($sql);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

// ─── Summary Cards ─────────────────────────────────────────────
$ratingCountSql = "
    SELECT COUNT(*) AS cnt
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    $whereSql
";
$ratingCountResult = runFilteredQuery($conn, $ratingCountSql, $types, $params);
$totalRatings = (int) $ratingCountResult->fetch_assoc()['cnt'];

$submissionCountSql = "
    SELECT COUNT(*) AS cnt
    FROM feedback_submissions fs
    JOIN feedback_forms ff ON fs.form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    $whereSql
";
$submissionCountResult = runFilteredQuery($conn, $submissionCountSql, $types, $params);
$totalSubmissions = (int) $submissionCountResult->fetch_assoc()['cnt'];

$formCountSql = "
    SELECT COUNT(DISTINCT ff.id) AS cnt
    FROM feedback_forms ff
    JOIN sections sec ON ff.section_id = sec.id
    $whereSql
";
$formCountResult = runFilteredQuery($conn, $formCountSql, $types, $params);
$totalForms = (int) $formCountResult->fetch_assoc()['cnt'];

$teacherCountSql = "
    SELECT COUNT(DISTINCT sec.teacher_id) AS cnt
    FROM feedback_submissions fs
    JOIN feedback_forms ff ON fs.form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    $whereSql
";
$teacherCountResult = runFilteredQuery($conn, $teacherCountSql, $types, $params);
$totalTeachers = (int) $teacherCountResult->fetch_assoc()['cnt'];

// ─── Teacher Performance (top teachers by feedback count) ──────
$teacherPerfSql = "
    SELECT u.name AS teacher_name,
           t.id AS teacher_id,
           COUNT(*) AS feedback_count
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    JOIN teachers t ON sec.teacher_id = t.id
    JOIN users u ON t.user_id = u.id
    $whereSql
    GROUP BY t.id, u.name
    ORDER BY feedback_count DESC
    LIMIT 10
";
$teacherPerfResult = runFilteredQuery($conn, $teacherPerfSql, $types, $params);
$teacherPerfData   = $teacherPerfResult->fetch_all(MYSQLI_ASSOC);

// ─── Per-Teacher Rating Distribution (pie charts) ──────────────
$teacherRatingData = [];
foreach ($teacherPerfData as $tp) {
    $tid = (int)$tp['teacher_id'];
    $trSql = "
        SELECT fr.rating, COUNT(*) AS qty
        FROM feedback_ratings fr
        JOIN feedback_forms ff ON fr.form_id = ff.id
        JOIN sections sec ON ff.section_id = sec.id
        JOIN teachers t ON sec.teacher_id = t.id
        WHERE t.id = ?
        " . ($filterAcademicYear > 0 ? "AND sec.academic_year_id = ?" : "") . "
        " . ($filterSemester > 0 ? "AND sec.semester_id = ?" : "") . "
        " . ($filterCourse > 0 ? "AND sec.course_id = ?" : "") . "
        " . ($filterSection !== '' ? "AND sec.section = ?" : "") . "
        GROUP BY fr.rating
        ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor')
    ";
    $trTypes = 'i';
    $trParams = [$tid];
    if ($filterAcademicYear > 0) { $trTypes .= 'i'; $trParams[] = $filterAcademicYear; }
    if ($filterSemester > 0)     { $trTypes .= 'i'; $trParams[] = $filterSemester; }
    if ($filterCourse > 0)       { $trTypes .= 'i'; $trParams[] = $filterCourse; }
    if ($filterSection !== '')   { $trTypes .= 's'; $trParams[] = $filterSection; }
    $trResult = runFilteredQuery($conn, $trSql, $trTypes, $trParams);
    $rawRatings = $trResult->fetch_all(MYSQLI_ASSOC);

    // Normalize: merge Excellent→Good, Poor→Bad
    $normalized = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
    foreach ($rawRatings as $rd) {
        $r = trim($rd['rating']);
        if (in_array($r, ['Excellent', 'Good', 'good', '3', 'ကောင်း'])) {
            $normalized['Good'] += (int)$rd['qty'];
        } elseif (in_array($r, ['Fair', 'fair', 'Normal', 'normal', 'Average', '2', 'သင့်'])) {
            $normalized['Fair'] += (int)$rd['qty'];
        } elseif (in_array($r, ['Poor', 'Bad', 'bad', '1', 'ညံ့'])) {
            $normalized['Bad'] += (int)$rd['qty'];
        }
    }
    $teacherRatingData[$tp['teacher_name']] = [
        ['rating' => 'Good', 'qty' => $normalized['Good']],
        ['rating' => 'Fair', 'qty' => $normalized['Fair']],
        ['rating' => 'Bad',  'qty' => $normalized['Bad']],
    ];
}

// ─── Build filter query string helper ──────────────────────────
function buildFilterUrl(array $overrides = []): string {
    $params = [];
    $academicYear = $overrides['academic_year'] ?? $_GET['academic_year'] ?? '';
    $semester     = $overrides['semester'] ?? $_GET['semester'] ?? '';
    $teacher      = $overrides['teacher_id'] ?? $_GET['teacher_id'] ?? '';
    $course       = $overrides['course_id'] ?? $_GET['course_id'] ?? '';
    $section      = $overrides['section'] ?? $_GET['section'] ?? '';
    if ($academicYear)         $params[] = 'academic_year=' . (int)$academicYear;
    if ($semester)             $params[] = 'semester=' . (int)$semester;
    if ($teacher)              $params[] = 'teacher_id=' . (int)$teacher;
    if ($course)               $params[] = 'course_id=' . (int)$course;
    if ($section !== '')       $params[] = 'section=' . urlencode($section);
    return '?' . implode('&', $params);
}

// ─── SA Feedback Statistics ─────────────────────────────────────
$saWhereParts = [];
$saParams     = [];
$saTypes      = '';

if ($saFilterAcademicYear > 0) {
    $saWhereParts[] = 'sec.academic_year_id = ?';
    $saParams[]     = $saFilterAcademicYear;
    $saTypes       .= 'i';
}
if ($saFilterSemester > 0) {
    $saWhereParts[] = 'sec.semester_id = ?';
    $saParams[]     = $saFilterSemester;
    $saTypes       .= 'i';
}

$saWhereSql = '';
if ($saWhereParts) {
    $saWhereSql = 'AND ' . implode(' AND ', $saWhereParts);
}

$saRatingsJoin = "
    JOIN feedback_submissions fs ON fr.form_id = fs.form_id AND fr.created_at = fs.submitted_at
    JOIN students st ON fs.student_id = st.id
    JOIN section_assignments sa ON sa.student_id = st.id
    JOIN sections sec ON sa.section_id = sec.id
";
$saSubsJoin = "
    JOIN students st ON fs.student_id = st.id
    JOIN section_assignments sa ON sa.student_id = st.id
    JOIN sections sec ON sa.section_id = sec.id
";
$saFormSubquery = "
    SELECT DISTINCT fs.form_id FROM feedback_submissions fs
    JOIN students st ON fs.student_id = st.id
    JOIN section_assignments sa ON sa.student_id = st.id
    JOIN sections sec ON sa.section_id = sec.id
    WHERE 1=1 $saWhereSql
";
$saFormWhere = "AND ff.id IN ($saFormSubquery)";

if ($saTypes !== '') {
    $saStmt = $conn->prepare("SELECT COUNT(DISTINCT fr.id) AS cnt FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id $saRatingsJoin WHERE ff.module='student_affairs' $saWhereSql");
    $saStmt->bind_param($saTypes, ...$saParams);
    $saStmt->execute();
    $saTotalRatings = (int) $saStmt->get_result()->fetch_assoc()['cnt'];
    $saStmt->close();

    $saStmt2 = $conn->prepare("SELECT COUNT(DISTINCT fs.id) AS cnt FROM feedback_submissions fs JOIN feedback_forms ff ON fs.form_id=ff.id $saSubsJoin WHERE ff.module='student_affairs' $saWhereSql");
    $saStmt2->bind_param($saTypes, ...$saParams);
    $saStmt2->execute();
    $saTotalSubmissions = (int) $saStmt2->get_result()->fetch_assoc()['cnt'];
    $saStmt2->close();

    $saStmt3 = $conn->prepare("SELECT COUNT(DISTINCT ff.id) AS cnt FROM feedback_forms ff WHERE ff.module='student_affairs' $saFormWhere");
    $saStmt3->bind_param($saTypes, ...$saParams);
    $saStmt3->execute();
    $saTotalForms = (int) $saStmt3->get_result()->fetch_assoc()['cnt'];
    $saStmt3->close();

    $saStmt4 = $conn->prepare("SELECT fr.rating, COUNT(DISTINCT fr.id) AS qty FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id $saRatingsJoin WHERE ff.module='student_affairs' $saWhereSql GROUP BY fr.rating ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor', 'Bad')");
    $saStmt4->bind_param($saTypes, ...$saParams);
    $saStmt4->execute();
    $saRatingDist = $saStmt4->get_result()->fetch_all(MYSQLI_ASSOC);
    $saStmt4->close();

    $saStmt5 = $conn->prepare("SELECT AVG(CASE WHEN fr.rating='Good' THEN 4 WHEN fr.rating='Fair' THEN 3 WHEN fr.rating IN ('Poor','Bad') THEN 1 ELSE 3 END) AS avg_rating FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id $saRatingsJoin WHERE ff.module='student_affairs' $saWhereSql");
    $saStmt5->bind_param($saTypes, ...$saParams);
    $saStmt5->execute();
    $saAvgResult = $saStmt5->get_result()->fetch_assoc();
    $saStmt5->close();
} else {
    $saTotalRatings    = (int) $conn->query("SELECT COUNT(DISTINCT fr.id) AS cnt FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id WHERE ff.module='student_affairs'")->fetch_assoc()['cnt'];
    $saTotalSubmissions = (int) $conn->query("SELECT COUNT(DISTINCT fs.id) AS cnt FROM feedback_submissions fs JOIN feedback_forms ff ON fs.form_id=ff.id WHERE ff.module='student_affairs'")->fetch_assoc()['cnt'];
    $saTotalForms      = (int) $conn->query("SELECT COUNT(DISTINCT ff.id) AS cnt FROM feedback_forms ff WHERE ff.module='student_affairs'")->fetch_assoc()['cnt'];
    $saRatingDist      = $conn->query("SELECT fr.rating, COUNT(DISTINCT fr.id) AS qty FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id WHERE ff.module='student_affairs' GROUP BY fr.rating ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor', 'Bad')")->fetch_all(MYSQLI_ASSOC);
    $saAvgResult       = $conn->query("SELECT AVG(CASE WHEN fr.rating='Good' THEN 4 WHEN fr.rating='Fair' THEN 3 WHEN fr.rating IN ('Poor','Bad') THEN 1 ELSE 3 END) AS avg_rating FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id WHERE ff.module='student_affairs'")->fetch_assoc();
}

$saAvgRating = $saAvgResult['avg_rating'] ? round((float)$saAvgResult['avg_rating'], 2) : 0;

$saNormalized = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
foreach ($saRatingDist as $rd) {
    $r = trim($rd['rating']);
    if (in_array($r, ['Excellent', 'Good'])) $saNormalized['Good'] += (int)$rd['qty'];
    elseif (in_array($r, ['Fair'])) $saNormalized['Fair'] += (int)$rd['qty'];
    elseif (in_array($r, ['Poor', 'Bad'])) $saNormalized['Bad'] += (int)$rd['qty'];
}

// ─── Admin Feedback Statistics ──────────────────────────────────
$admWhereParts = [];
$admParams     = [];
$admTypes      = '';

if ($admFilterAcademicYear > 0) {
    $admWhereParts[] = 'sec.academic_year_id = ?';
    $admParams[]     = $admFilterAcademicYear;
    $admTypes       .= 'i';
}
if ($admFilterSemester > 0) {
    $admWhereParts[] = 'sec.semester_id = ?';
    $admParams[]     = $admFilterSemester;
    $admTypes       .= 'i';
}

$admWhereSql = '';
if ($admWhereParts) {
    $admWhereSql = 'AND ' . implode(' AND ', $admWhereParts);
}

$admRatingsJoin = "
    JOIN feedback_submissions fs ON fr.form_id = fs.form_id AND fr.created_at = fs.submitted_at
    JOIN students st ON fs.student_id = st.id
    JOIN section_assignments sa ON sa.student_id = st.id
    JOIN sections sec ON sa.section_id = sec.id
";
$admSubsJoin = "
    JOIN students st ON fs.student_id = st.id
    JOIN section_assignments sa ON sa.student_id = st.id
    JOIN sections sec ON sa.section_id = sec.id
";
$admFormSubquery = "
    SELECT DISTINCT fs.form_id FROM feedback_submissions fs
    JOIN students st ON fs.student_id = st.id
    JOIN section_assignments sa ON sa.student_id = st.id
    JOIN sections sec ON sa.section_id = sec.id
    WHERE 1=1 $admWhereSql
";
$admFormWhere = "AND ff.id IN ($admFormSubquery)";

if ($admTypes !== '') {
    $admStmt = $conn->prepare("SELECT COUNT(DISTINCT fr.id) AS cnt FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id $admRatingsJoin WHERE ff.module='administration' $admWhereSql");
    $admStmt->bind_param($admTypes, ...$admParams);
    $admStmt->execute();
    $admTotalRatings = (int) $admStmt->get_result()->fetch_assoc()['cnt'];
    $admStmt->close();

    $admStmt2 = $conn->prepare("SELECT COUNT(DISTINCT fs.id) AS cnt FROM feedback_submissions fs JOIN feedback_forms ff ON fs.form_id=ff.id $admSubsJoin WHERE ff.module='administration' $admWhereSql");
    $admStmt2->bind_param($admTypes, ...$admParams);
    $admStmt2->execute();
    $admTotalSubmissions = (int) $admStmt2->get_result()->fetch_assoc()['cnt'];
    $admStmt2->close();

    $admStmt3 = $conn->prepare("SELECT COUNT(DISTINCT ff.id) AS cnt FROM feedback_forms ff WHERE ff.module='administration' $admFormWhere");
    $admStmt3->bind_param($admTypes, ...$admParams);
    $admStmt3->execute();
    $admTotalForms = (int) $admStmt3->get_result()->fetch_assoc()['cnt'];
    $admStmt3->close();

    $admStmt4 = $conn->prepare("SELECT fr.rating, COUNT(DISTINCT fr.id) AS qty FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id $admRatingsJoin WHERE ff.module='administration' $admWhereSql GROUP BY fr.rating ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor', 'Bad')");
    $admStmt4->bind_param($admTypes, ...$admParams);
    $admStmt4->execute();
    $admRatingDist = $admStmt4->get_result()->fetch_all(MYSQLI_ASSOC);
    $admStmt4->close();

    $admStmt5 = $conn->prepare("SELECT AVG(CASE WHEN fr.rating='Good' THEN 4 WHEN fr.rating='Fair' THEN 3 WHEN fr.rating IN ('Poor','Bad') THEN 1 ELSE 3 END) AS avg_rating FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id $admRatingsJoin WHERE ff.module='administration' $admWhereSql");
    $admStmt5->bind_param($admTypes, ...$admParams);
    $admStmt5->execute();
    $admAvgResult = $admStmt5->get_result()->fetch_assoc();
    $admStmt5->close();
} else {
    $admTotalRatings    = (int) $conn->query("SELECT COUNT(DISTINCT fr.id) AS cnt FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id WHERE ff.module='administration'")->fetch_assoc()['cnt'];
    $admTotalSubmissions = (int) $conn->query("SELECT COUNT(DISTINCT fs.id) AS cnt FROM feedback_submissions fs JOIN feedback_forms ff ON fs.form_id=ff.id WHERE ff.module='administration'")->fetch_assoc()['cnt'];
    $admTotalForms      = (int) $conn->query("SELECT COUNT(DISTINCT ff.id) AS cnt FROM feedback_forms ff WHERE ff.module='administration'")->fetch_assoc()['cnt'];
    $admRatingDist      = $conn->query("SELECT fr.rating, COUNT(DISTINCT fr.id) AS qty FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id WHERE ff.module='administration' GROUP BY fr.rating ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor', 'Bad')")->fetch_all(MYSQLI_ASSOC);
    $admAvgResult       = $conn->query("SELECT AVG(CASE WHEN fr.rating='Good' THEN 4 WHEN fr.rating='Fair' THEN 3 WHEN fr.rating IN ('Poor','Bad') THEN 1 ELSE 3 END) AS avg_rating FROM feedback_ratings fr JOIN feedback_forms ff ON fr.form_id = ff.id WHERE ff.module='administration'")->fetch_assoc();
}

$admAvgRating = $admAvgResult['avg_rating'] ? round((float)$admAvgResult['avg_rating'], 2) : 0;

$admNormalized = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
foreach ($admRatingDist as $rd) {
    $r = trim($rd['rating']);
    if (in_array($r, ['Excellent', 'Good'])) $admNormalized['Good'] += (int)$rd['qty'];
    elseif (in_array($r, ['Fair'])) $admNormalized['Fair'] += (int)$rd['qty'];
    elseif (in_array($r, ['Poor', 'Bad'])) $admNormalized['Bad'] += (int)$rd['qty'];
}

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<style>
    .filter-active { border-color: #0891b2 !important; box-shadow: 0 0 0 1px #0891b2; }
</style>

<!-- Page Header -->
<div class="mb-6">
    <h2 class="text-2xl font-bold text-slate-800"><?= $LANG['reports_title'] ?? 'Reports & Analytics' ?></h2>
    <p class="text-sm text-slate-500 mt-1"><?= $LANG['reports_subtitle'] ?? 'Graphical and statistical analysis across all feedback modules' ?></p>
</div>

<!-- Filters -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6">
    <form method="GET" action="#academic-feedback" class="flex flex-wrap items-end gap-4">
        <div class="flex-1 min-w-[180px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG['academic_year_filter'] ?? 'Academic Year' ?></label>
            <select name="academic_year" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                <option value="0"><?= $LANG['all_academic_years'] ?? 'All Academic Years' ?></option>
                <?php foreach ($academicYears as $ay): ?>
                    <option value="<?= (int)$ay['id'] ?>" <?= $filterAcademicYear === (int)$ay['id'] ? 'selected' : '' ?>>
                        <?= e($ay['year_name']) ?><?= $ay['status'] === 'active' ? ' (Active)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[180px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG['semester_filter'] ?? 'Semester' ?></label>
            <select name="semester" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                <option value="0"><?= $LANG['all_semesters'] ?? 'All Semesters' ?></option>
                <?php foreach ($semesters as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $filterSemester === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= e(semesterToRoman($s['semester_name'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[180px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG['teacher_filter'] ?? 'Teacher' ?></label>
            <select name="teacher_id" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                <option value="0"><?= $LANG['all_teachers'] ?? 'All Teachers' ?></option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= $filterTeacher === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= e($t['teacher_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[180px]" x-data="{
            open: false,
            selected: <?= (int) $filterCourse ?>,
            selectedText: '<?= $filterCourse ? e($courses[array_search($filterCourse, array_column($courses, 'id'))]['course_name'] ?? '') : ($LANG['all_subjects'] ?? 'All Subjects') ?>',
            options: [
                { value: 0, text: '<?= $LANG['all_subjects'] ?? 'All Subjects' ?>' },
                <?php foreach ($courses as $c): ?>
                { value: <?= (int) $c['id'] ?>, text: '<?= e($c['course_name']) ?>' },
                <?php endforeach; ?>
            ],
            select(val, text) {
                this.selected = val;
                this.selectedText = text;
                this.open = false;
                this.$refs.courseInput.value = val;
            }
        }" @click.outside="open = false">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG['subject_filter'] ?? 'Subject' ?></label>
            <input type="hidden" name="course_id" x-ref="courseInput" :value="selected">
            <button type="button" @click="open = !open"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                <span x-text="selectedText" class="truncate"></span>
                <svg class="w-4 h-4 text-slate-400 flex-shrink-0 ml-1 transition-transform inline-block align-middle" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-1"
                class="absolute z-50 mt-1 w-full max-h-60 overflow-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                <template x-for="opt in options" :key="opt.value">
                    <button type="button" @click="select(opt.value, opt.text)"
                        class="w-full text-left px-3 py-2 text-sm hover:bg-cyan-50 transition-colors flex items-center gap-2"
                        :class="selected === opt.value ? 'bg-cyan-50 text-cyan-700 font-semibold' : 'text-slate-700'">
                        <span x-show="selected === opt.value" class="text-cyan-600">&#10003;</span>
                        <span x-text="opt.text" class="truncate"></span>
                    </button>
                </template>
            </div>
        </div>
        <div class="flex-1 min-w-[180px]" x-data="{
            open: false,
            selected: '<?= e($filterSection) ?>',
            selectedText: '<?= $filterSection ? e($filterSection) : ($LANG['all_sections'] ?? 'All Sections') ?>',
            options: [
                { value: '', text: '<?= $LANG['all_sections'] ?? 'All Sections' ?>' },
                <?php foreach ($sections as $sec): ?>
                { value: '<?= e($sec['section']) ?>', text: '<?= e($sec['section']) ?>' },
                <?php endforeach; ?>
            ],
            select(val, text) {
                this.selected = val;
                this.selectedText = text;
                this.open = false;
                this.$refs.sectionInput.value = val;
            }
        }" @click.outside="open = false">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG['section_filter'] ?? 'Section' ?></label>
            <input type="hidden" name="section" x-ref="sectionInput" :value="selected">
            <button type="button" @click="open = !open"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                <span x-text="selectedText" class="truncate"></span>
                <svg class="w-4 h-4 text-slate-400 flex-shrink-0 ml-1 transition-transform inline-block align-middle" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open" x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-1"
                class="absolute z-50 mt-1 w-full max-h-60 overflow-auto rounded-xl border border-slate-200 bg-white shadow-lg">
                <template x-for="opt in options" :key="opt.value">
                    <button type="button" @click="select(opt.value, opt.text)"
                        class="w-full text-left px-3 py-2 text-sm hover:bg-cyan-50 transition-colors flex items-center gap-2"
                        :class="selected === opt.value ? 'bg-cyan-50 text-cyan-700 font-semibold' : 'text-slate-700'">
                        <span x-show="selected === opt.value" class="text-cyan-600">&#10003;</span>
                        <span x-text="opt.text" class="truncate"></span>
                    </button>
                </template>
            </div>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-5 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 transition-colors">
                <?= $LANG['filter'] ?? 'Filter' ?>
            </button>
            <a href="reports.php#academic-feedback" class="px-4 py-2 bg-slate-100 text-slate-600 text-sm font-semibold rounded-xl hover:bg-slate-200 transition-colors">
                <?= $LANG['reset'] ?? 'Reset' ?>
            </a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">
    
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-indigo-600 flex items-center justify-center shadow-sm"><?= iconSvg('academic', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-blue-700"><?= number_format($totalSubmissions) ?></p><p class="text-[10px] text-slate-500 font-medium"><?= $LANG['submissions_stat'] ?? 'Submissions' ?></p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-indigo-600 flex items-center justify-center shadow-sm"><?= iconSvg('user', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-indigo-700"><?= number_format($totalTeachers) ?></p><p class="text-[10px] text-slate-500 font-medium"><?= $LANG['teachers_stat'] ?? 'Teachers' ?></p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-indigo-600 flex items-center justify-center shadow-sm"><?= iconSvg('document', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-emerald-700"><?= number_format($totalForms) ?></p><p class="text-[10px] text-slate-500 font-medium"><?= $LANG['forms_stat'] ?? 'Forms' ?></p></div>
    </div>
    
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

<!-- Per-Teacher Rating Distribution Pie Charts -->
<?php if (!empty($teacherRatingData)): ?>
<div class="mb-6">
    <h3 class="text-sm font-bold text-slate-800 mb-3"><?= $LANG['teacher_rating_dist'] ?? 'Teacher Rating Distribution' ?></h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
        <?php
        $colorMap = ['Good' => '#22c55e', 'Fair' => '#f59e0b', 'Bad' => '#ef4444'];
        $chartIndex = 0;
        foreach ($teacherRatingData as $tName => $tRatings):
            if (empty($tRatings)) continue;
            $tLabels = array_column($tRatings, 'rating');
            $tValues = array_column($tRatings, 'qty');
            $tColors = array_map(fn($l) => $colorMap[$l] ?? '#6366f1', $tLabels);
        ?>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4">
            <h4 class="text-xs font-bold text-slate-700 mb-3 truncate" title="<?= e($tName) ?>"><?= e($tName) ?></h4>
            <div class="relative flex items-center justify-center" style="height:180px;">
                <canvas id="teacherPie<?= $chartIndex ?>"></canvas>
            </div>
            <?php
            $tGood = 0; $tFair = 0; $tBad = 0;
            foreach ($tRatings as $tr) {
                $r = trim($tr['rating']);
                if (in_array($r, ['Excellent', 'Good', 'good', '3', 'ကောင်း'])) $tGood += (int)$tr['qty'];
                elseif (in_array($r, ['Fair', 'fair', 'Normal', 'normal', 'Average', '2', 'သင့်'])) $tFair += (int)$tr['qty'];
                elseif (in_array($r, ['Poor', 'Bad', 'bad', '1', 'ညံ့'])) $tBad += (int)$tr['qty'];
            }
            $tTotal = $tGood + $tFair + $tBad;
            $tPctGood = $tTotal > 0 ? round(($tGood / $tTotal) * 100) : 0;
            $tPctFair = $tTotal > 0 ? round(($tFair / $tTotal) * 100) : 0;
            $tPctBad  = $tTotal > 0 ? round(($tBad / $tTotal) * 100) : 0;
            ?>
            <div class="grid grid-cols-3 gap-2 mt-3">
                <div class="text-center p-2 rounded-lg bg-emerald-50 border border-emerald-100">
                    <p class="text-lg font-bold text-emerald-600"><?= $tPctGood ?>%</p>
                    <p class="text-[10px] font-semibold text-emerald-700"><?= $LANG['good'] ?? 'Good' ?></p>
                </div>
                <div class="text-center p-2 rounded-lg bg-amber-50 border border-amber-100">
                    <p class="text-lg font-bold text-amber-600"><?= $tPctFair ?>%</p>
                    <p class="text-[10px] font-semibold text-amber-700"><?= $LANG['fair'] ?? 'Fair' ?></p>
                </div>
                <div class="text-center p-2 rounded-lg bg-red-50 border border-red-100">
                    <p class="text-lg font-bold text-red-600"><?= $tPctBad ?>%</p>
                    <p class="text-[10px] font-semibold text-red-700"><?= $LANG['bad'] ?? 'Bad' ?></p>
                </div>
            </div>
        </div>
        <?php $chartIndex++; endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- STUDENT AFFAIRS FEEDBACK SECTION                          -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="sa-feedback" class="border-t-2 border-slate-200 pt-6 mb-2">
    <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
        <span class="w-2 h-2 rounded-full bg-purple-500 inline-block"></span>
        <?= $LANG['student_affairs_feedback'] ?? 'Student Affairs Feedback' ?>
    </h3>
</div>

<!-- SA Filters -->
<form method="GET" action="#sa-feedback" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 mb-4">
    <div class="flex flex-wrap items-end gap-4">
        <div class="min-w-[180px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG['academic_year_filter'] ?? 'Academic Year' ?></label>
            <select name="sa_academic_year" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                <option value="0"><?= $LANG['all_academic_years'] ?? 'All Academic Years' ?></option>
                <?php foreach ($academicYears as $ay): ?>
                    <option value="<?= (int)$ay['id'] ?>" <?= $saFilterAcademicYear === (int)$ay['id'] ? 'selected' : '' ?>>
                        <?= e($ay['year_name']) ?><?= $ay['status'] === 'active' ? ' (Active)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-[180px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG['semester_filter'] ?? 'Semester' ?></label>
            <select name="sa_semester" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500">
                <option value="0"><?= $LANG['all_semesters'] ?? 'All Semesters' ?></option>
                <?php foreach ($saSemesters as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $saFilterSemester === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= e(semesterToRoman($s['semester_name'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-5 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700 transition-colors">
                <?= $LANG['filter'] ?? 'Filter' ?>
            </button>
            <a href="reports.php#sa-feedback" class="px-4 py-2 bg-slate-100 text-slate-600 text-sm font-semibold rounded-xl hover:bg-slate-200 transition-colors">
                <?= $LANG['reset'] ?? 'Reset' ?>
            </a>
        </div>
    </div>
</form>

<!-- SA Summary Cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
   
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-violet-600 flex items-center justify-center shadow-sm"><?= iconSvg('academic', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-violet-700"><?= number_format($saTotalSubmissions) ?></p><p class="text-[10px] text-slate-500 font-medium"><?= $LANG['sa_submissions'] ?? 'SA Submissions' ?></p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-purple-500 flex items-center justify-center shadow-sm"><?= iconSvg('document', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-purple-600"><?= number_format($saTotalForms) ?></p><p class="text-[10px] text-slate-500 font-medium"><?= $LANG['sa_forms_stat'] ?? 'SA Forms' ?></p></div>
    </div>
    
</div>

<!-- SA Charts -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-4"><?= $LANG['sa_rating_dist'] ?? 'SA Rating Distribution (Good / Fair / Bad)' ?></h3>
        <div class="relative flex items-center justify-center" style="height:280px;">
            <canvas id="saRatingPie"></canvas>
        </div>
        <?php
        $saTotal = array_sum($saNormalized);
        $saPctGood = $saTotal > 0 ? round(($saNormalized['Good'] / $saTotal) * 100) : 0;
        $saPctFair = $saTotal > 0 ? round(($saNormalized['Fair'] / $saTotal) * 100) : 0;
        $saPctBad  = $saTotal > 0 ? round(($saNormalized['Bad'] / $saTotal) * 100) : 0;
        ?>
            <div class="grid grid-cols-3 gap-3 mt-4">
            <div class="text-center p-3 rounded-xl bg-emerald-50 border border-emerald-200">
                <p class="text-2xl font-bold text-emerald-600"><?= $saPctGood ?>%</p>
                <p class="text-xs font-semibold text-emerald-700"><?= $LANG['good'] ?? 'Good' ?></p>
                <p class="text-[10px] text-slate-500"><?= number_format($saNormalized['Good']) ?> <?= $LANG['ratings'] ?? 'ratings' ?></p>
            </div>
            <div class="text-center p-3 rounded-xl bg-amber-50 border border-amber-200">
                <p class="text-2xl font-bold text-amber-600"><?= $saPctFair ?>%</p>
                <p class="text-xs font-semibold text-amber-700"><?= $LANG['fair'] ?? 'Fair' ?></p>
                <p class="text-[10px] text-slate-500"><?= number_format($saNormalized['Fair']) ?> <?= $LANG['ratings'] ?? 'ratings' ?></p>
            </div>
            <div class="text-center p-3 rounded-xl bg-red-50 border border-red-200">
                <p class="text-2xl font-bold text-red-600"><?= $saPctBad ?>%</p>
                <p class="text-xs font-semibold text-red-700"><?= $LANG['bad'] ?? 'Bad' ?></p>
                <p class="text-[10px] text-slate-500"><?= number_format($saNormalized['Bad']) ?> <?= $LANG['ratings'] ?? 'ratings' ?></p>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- ADMINISTRATION FEEDBACK SECTION                           -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div id="admin-feedback" class="border-t-2 border-slate-200 pt-6 mb-2">
    <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
        <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
        <?= $LANG['administration_feedback'] ?? 'Administration Feedback' ?>
    </h3>
</div>

<!-- Admin Filters -->
<form method="GET" action="#admin-feedback" class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 mb-4">
    <div class="flex flex-wrap items-end gap-4">
        <div class="min-w-[180px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG['academic_year_filter'] ?? 'Academic Year' ?></label>
            <select name="adm_academic_year" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                <option value="0"><?= $LANG['all_academic_years'] ?? 'All Academic Years' ?></option>
                <?php foreach ($academicYears as $ay): ?>
                    <option value="<?= (int)$ay['id'] ?>" <?= $admFilterAcademicYear === (int)$ay['id'] ? 'selected' : '' ?>>
                        <?= e($ay['year_name']) ?><?= $ay['status'] === 'active' ? ' (Active)' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="min-w-[180px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG['semester_filter'] ?? 'Semester' ?></label>
            <select name="adm_semester" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500">
                <option value="0"><?= $LANG['all_semesters'] ?? 'All Semesters' ?></option>
                <?php foreach ($admSemesters as $s): ?>
                    <option value="<?= (int)$s['id'] ?>" <?= $admFilterSemester === (int)$s['id'] ? 'selected' : '' ?>>
                        <?= e(semesterToRoman($s['semester_name'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-5 py-2 bg-orange-600 text-white text-sm font-semibold rounded-xl hover:bg-orange-700 transition-colors">
                <?= $LANG['filter'] ?? 'Filter' ?>
            </button>
            <a href="reports.php#admin-feedback" class="px-4 py-2 bg-slate-100 text-slate-600 text-sm font-semibold rounded-xl hover:bg-slate-200 transition-colors">
                <?= $LANG['reset'] ?? 'Reset' ?>
            </a>
        </div>
    </div>
</form>

<!-- Admin Summary Cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
   
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-orange-500 flex items-center justify-center shadow-sm"><?= iconSvg('academic', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-orange-600"><?= number_format($admTotalSubmissions) ?></p><p class="text-[10px] text-slate-500 font-medium"><?= $LANG['adm_submissions'] ?? 'Adm Submissions' ?></p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-orange-400 flex items-center justify-center shadow-sm"><?= iconSvg('document', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-orange-500"><?= number_format($admTotalForms) ?></p><p class="text-[10px] text-slate-500 font-medium"><?= $LANG['adm_forms_stat'] ?? 'Adm Forms' ?></p></div>
    </div>
    
</div>

<!-- Admin Charts -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-4"><?= $LANG['adm_rating_dist'] ?? 'Adm Rating Distribution (Good / Fair / Bad)' ?></h3>
        <div class="relative flex items-center justify-center" style="height:280px;">
            <canvas id="admRatingPie"></canvas>
        </div>
        <?php
        $admTotal = array_sum($admNormalized);
        $admPctGood = $admTotal > 0 ? round(($admNormalized['Good'] / $admTotal) * 100) : 0;
        $admPctFair = $admTotal > 0 ? round(($admNormalized['Fair'] / $admTotal) * 100) : 0;
        $admPctBad  = $admTotal > 0 ? round(($admNormalized['Bad'] / $admTotal) * 100) : 0;
        ?>
            <div class="grid grid-cols-3 gap-3 mt-4">
            <div class="text-center p-3 rounded-xl bg-emerald-50 border border-emerald-200">
                <p class="text-2xl font-bold text-emerald-600"><?= $admPctGood ?>%</p>
                <p class="text-xs font-semibold text-emerald-700"><?= $LANG['good'] ?? 'Good' ?></p>
                <p class="text-[10px] text-slate-500"><?= number_format($admNormalized['Good']) ?> <?= $LANG['ratings'] ?? 'ratings' ?></p>
            </div>
            <div class="text-center p-3 rounded-xl bg-amber-50 border border-amber-200">
                <p class="text-2xl font-bold text-amber-600"><?= $admPctFair ?>%</p>
                <p class="text-xs font-semibold text-amber-700"><?= $LANG['fair'] ?? 'Fair' ?></p>
                <p class="text-[10px] text-slate-500"><?= number_format($admNormalized['Fair']) ?> <?= $LANG['ratings'] ?? 'ratings' ?></p>
            </div>
            <div class="text-center p-3 rounded-xl bg-red-50 border border-red-200">
                <p class="text-2xl font-bold text-red-600"><?= $admPctBad ?>%</p>
                <p class="text-xs font-semibold text-red-700"><?= $LANG['bad'] ?? 'Bad' ?></p>
                <p class="text-[10px] text-slate-500"><?= number_format($admNormalized['Bad']) ?> <?= $LANG['ratings'] ?? 'ratings' ?></p>
            </div>
        </div>
    </div>
</div>

<!-- ─── Chart Initialization Scripts ──────────────────────────── -->
<script>
document.addEventListener('DOMContentLoaded', function() {
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
                        return pct + '%';
                    }
                }
            }
        }
    };

    // ─── Per-Teacher Pie Charts ────────────────────────────────
    <?php
    $pIndex = 0;
    foreach ($teacherRatingData as $tName => $tRatings):
        if (empty($tRatings)) continue;
        $tLabels = array_column($tRatings, 'rating');
        $tValues = array_column($tRatings, 'qty');
        $tColors = array_map(fn($l) => $colorMap[$l] ?? '#6366f1', $tLabels);
    ?>
    new Chart(document.getElementById('teacherPie<?= $pIndex ?>'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($tLabels) ?>,
            datasets: [{
                data: <?= json_encode($tValues) ?>,
                backgroundColor: <?= json_encode($tColors) ?>,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: Object.assign({}, chartDefaults, {
            cutout: '50%',
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 9 }, padding: 8 } },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                            var pct = total > 0 ? Math.round((ctx.raw / total) * 100) : 0;
                            return pct + '%';
                        }
                    }
                }
            }
        })
    });
    <?php $pIndex++; endforeach; ?>

    // ─── SA Rating Pie ────────────────────────────────────────
    new Chart(document.getElementById('saRatingPie'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_keys($saNormalized)) ?>,
            datasets: [{
                data: <?= json_encode(array_values($saNormalized)) ?>,
                backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: Object.assign({}, chartDefaults, {
            cutout: '55%',
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 16 } },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                            var pct = total > 0 ? Math.round((ctx.raw / total) * 100) : 0;
                            return pct + '%';
                        }
                    }
                }
            }
        })
    });

    // ─── Admin Rating Pie ─────────────────────────────────────
    new Chart(document.getElementById('admRatingPie'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode(array_keys($admNormalized)) ?>,
            datasets: [{
                data: <?= json_encode(array_values($admNormalized)) ?>,
                backgroundColor: ['#22c55e', '#f59e0b', '#ef4444'],
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: Object.assign({}, chartDefaults, {
            cutout: '55%',
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 16 } },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var total = ctx.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                            var pct = total > 0 ? Math.round((ctx.raw / total) * 100) : 0;
                            return pct + '%';
                        }
                    }
                }
            }
        })
    });

});
</script>

<?php include '../includes/admin_footer.php'; ?>
