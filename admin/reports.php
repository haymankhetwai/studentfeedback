<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Reports & Analytics';
$activeMenu = 'reports';

// ─── Filter Inputs ─────────────────────────────────────────────
$filterSemester = clean($_GET['semester'] ?? '');
$filterTeacher  = (int)($_GET['teacher_id'] ?? 0);
$filterCourse   = (int)($_GET['course_id'] ?? 0);

// ─── Filter Options ────────────────────────────────────────────
$semesters = $conn->query("SELECT DISTINCT semester FROM sections WHERE semester != '' ORDER BY semester DESC")->fetch_all(MYSQLI_ASSOC);
$teachers  = $conn->query("
    SELECT DISTINCT t.id, u.name AS teacher_name
    FROM sections s
    JOIN teachers t ON s.teacher_id = t.id
    JOIN users u ON t.user_id = u.id
    ORDER BY u.name ASC
")->fetch_all(MYSQLI_ASSOC);
$courses   = $conn->query("
    SELECT DISTINCT c.id, c.course_name
    FROM sections s
    JOIN courses c ON s.course_id = c.id
    ORDER BY c.course_name ASC
")->fetch_all(MYSQLI_ASSOC);

// ─── Helper: build dynamic WHERE clause for ratings ────────────
// We need: feedback_ratings → feedback_forms → sections [+ teachers/courses]
$whereParts = [];
$params     = [];
$types      = '';

if ($filterSemester !== '') {
    $whereParts[] = 'sec.semester = ?';
    $params[]     = $filterSemester;
    $types       .= 's';
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
    JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    $whereSql
";
$ratingCountResult = runFilteredQuery($conn, $ratingCountSql, $types, $params);
$totalRatings = (int) $ratingCountResult->fetch_assoc()['cnt'];

$submissionCountSql = "
    SELECT COUNT(*) AS cnt
    FROM feedback_submissions fs
    JOIN feedback_forms ff ON fs.feedback_form_id = ff.id
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
    JOIN feedback_forms ff ON fs.feedback_form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    $whereSql
";
$teacherCountResult = runFilteredQuery($conn, $teacherCountSql, $types, $params);
$totalTeachers = (int) $teacherCountResult->fetch_assoc()['cnt'];

// ─── Overall Rating Distribution ───────────────────────────────
$overallRatingSql = "
    SELECT fr.rating, COUNT(*) AS qty
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    $whereSql
    GROUP BY fr.rating
    ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor')
";
$overallRatingResult = runFilteredQuery($conn, $overallRatingSql, $types, $params);
$overallRatingData = $overallRatingResult->fetch_all(MYSQLI_ASSOC);

// Normalize to Good/Fair/Bad
$normalizedRating = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
foreach ($overallRatingData as $rd) {
    $r = trim($rd['rating']);
    if (in_array($r, ['Excellent', 'Good', 'good', '3', 'ကောင်း'])) {
        $normalizedRating['Good'] += (int)$rd['qty'];
    } elseif (in_array($r, ['Fair', 'fair', 'Normal', 'normal', 'Average', '2', 'သင့်'])) {
        $normalizedRating['Fair'] += (int)$rd['qty'];
    } elseif (in_array($r, ['Poor', 'Bad', 'bad', '1', 'ညံ့'])) {
        $normalizedRating['Bad'] += (int)$rd['qty'];
    }
}
$pieLabels = array_keys($normalizedRating);
$pieValues = array_values($normalizedRating);
$pieColors = ['#22c55e', '#f59e0b', '#ef4444'];

// ─── Average Rating (academic) ─────────────────────────────────
$avgSql = "
    SELECT AVG(CASE
        WHEN fr.rating IN ('Excellent') THEN 5
        WHEN fr.rating IN ('Good') THEN 4
        WHEN fr.rating IN ('Fair') THEN 3
        WHEN fr.rating IN ('Poor') THEN 1
        ELSE 3
    END) AS avg_rating
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    $whereSql
";
$avgResult = runFilteredQuery($conn, $avgSql, $types, $params)->fetch_assoc();
$avgRating = $avgResult['avg_rating'] ? round((float)$avgResult['avg_rating'], 2) : 0;

// ─── Per-Section Breakdown ──────────────────────────────────────
$sectionBreakdownSql = "
    SELECT sec.id AS section_id, c.course_name, sec.section, sec.semester,
           COUNT(fr.id) AS total_ratings,
           AVG(CASE WHEN fr.rating IN ('Excellent','Good') THEN 5 WHEN fr.rating = 'Fair' THEN 3 WHEN fr.rating IN ('Poor','Bad') THEN 1 ELSE 3 END) AS avg_rating
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    JOIN courses c ON sec.course_id = c.id
    $whereSql
    GROUP BY sec.id, c.course_name, sec.section, sec.semester
    ORDER BY total_ratings DESC
";
$sectionBreakdown = runFilteredQuery($conn, $sectionBreakdownSql, $types, $params)->fetch_all(MYSQLI_ASSOC);

// ─── Semester Statistics (submissions per semester) ─────────────
$semesterStatSql = "
    SELECT sec.semester, COUNT(*) AS total
    FROM feedback_submissions fs
    JOIN feedback_forms ff ON fs.feedback_form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    WHERE sec.semester IS NOT NULL AND sec.semester != ''
    " . ($filterTeacher > 0 ? "AND sec.teacher_id = ?" : "") . "
    " . ($filterCourse > 0 ? "AND sec.course_id = ?" : "") . "
    GROUP BY sec.semester
    ORDER BY sec.semester DESC
";
$semesterStatParams = [];
$semesterStatTypes  = '';
if ($filterTeacher > 0) { $semesterStatParams[] = $filterTeacher; $semesterStatTypes .= 'i'; }
if ($filterCourse > 0)  { $semesterStatParams[] = $filterCourse;  $semesterStatTypes .= 'i'; }

$semesterStatResult = runFilteredQuery($conn, $semesterStatSql, $semesterStatTypes, $semesterStatParams);
$semesterStatData   = $semesterStatResult->fetch_all(MYSQLI_ASSOC);
$semesterLabels     = array_column($semesterStatData, 'semester');
$semesterValues     = array_column($semesterStatData, 'total');

// ─── Teacher Performance (top teachers by feedback count) ──────
$teacherPerfSql = "
    SELECT u.name AS teacher_name,
           t.id AS teacher_id,
           COUNT(*) AS feedback_count
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
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
$teacherPerfLabels = array_column($teacherPerfData, 'teacher_name');
$teacherPerfValues = array_column($teacherPerfData, 'feedback_count');

// ─── Per-Teacher Rating Distribution (pie charts) ──────────────
$teacherRatingData = [];
foreach ($teacherPerfData as $tp) {
    $tid = (int)$tp['teacher_id'];
    $trSql = "
        SELECT fr.rating, COUNT(*) AS qty
        FROM feedback_ratings fr
        JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
        JOIN sections sec ON ff.section_id = sec.id
        JOIN teachers t ON sec.teacher_id = t.id
        WHERE t.id = ?
        " . ($filterSemester !== '' ? "AND sec.semester = ?" : "") . "
        " . ($filterCourse > 0 ? "AND sec.course_id = ?" : "") . "
        GROUP BY fr.rating
        ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor')
    ";
    $trTypes = 'i';
    $trParams = [$tid];
    if ($filterSemester !== '') { $trTypes .= 's'; $trParams[] = $filterSemester; }
    if ($filterCourse > 0)     { $trTypes .= 'i'; $trParams[] = $filterCourse; }
    $trResult = runFilteredQuery($conn, $trSql, $trTypes, $trParams);
    $teacherRatingData[$tp['teacher_name']] = $trResult->fetch_all(MYSQLI_ASSOC);
}

// ─── Subject Performance (top courses by feedback count) ───────
$coursePerfSql = "
    SELECT c.course_name, c.id AS course_id,
           COUNT(*) AS feedback_count
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
    JOIN sections sec ON ff.section_id = sec.id
    JOIN courses c ON sec.course_id = c.id
    $whereSql
    GROUP BY c.id, c.course_name
    ORDER BY feedback_count DESC
    LIMIT 10
";
$coursePerfResult = runFilteredQuery($conn, $coursePerfSql, $types, $params);
$coursePerfData   = $coursePerfResult->fetch_all(MYSQLI_ASSOC);
$coursePerfLabels = array_column($coursePerfData, 'course_name');
$coursePerfValues = array_column($coursePerfData, 'feedback_count');

// ─── Per-Course Rating Distribution (pie charts) ───────────────
$courseRatingData = [];
foreach ($coursePerfData as $cp) {
    $cid = (int)$cp['course_id'];
    $crSql = "
        SELECT fr.rating, COUNT(*) AS qty
        FROM feedback_ratings fr
        JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
        JOIN sections sec ON ff.section_id = sec.id
        JOIN courses c ON sec.course_id = c.id
        WHERE c.id = ?
        " . ($filterSemester !== '' ? "AND sec.semester = ?" : "") . "
        " . ($filterTeacher > 0 ? "AND sec.teacher_id = ?" : "") . "
        GROUP BY fr.rating
        ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor')
    ";
    $crTypes = 'i';
    $crParams = [$cid];
    if ($filterSemester !== '') { $crTypes .= 's'; $crParams[] = $filterSemester; }
    if ($filterTeacher > 0)    { $crTypes .= 'i'; $crParams[] = $filterTeacher; }
    $crResult = runFilteredQuery($conn, $crSql, $crTypes, $crParams);
    $courseRatingData[$cp['course_name']] = $crResult->fetch_all(MYSQLI_ASSOC);
}

// ─── Build filter query string helper ──────────────────────────
function buildFilterUrl(array $overrides = []): string {
    $params = [];
    $semester = $overrides['semester'] ?? $_GET['semester'] ?? '';
    $teacher  = $overrides['teacher_id'] ?? $_GET['teacher_id'] ?? '';
    $course   = $overrides['course_id'] ?? $_GET['course_id'] ?? '';
    if ($semester !== '') $params[] = 'semester=' . urlencode($semester);
    if ($teacher)         $params[] = 'teacher_id=' . (int)$teacher;
    if ($course)          $params[] = 'course_id=' . (int)$course;
    return '?' . implode('&', $params);
}

// ─── SA Feedback Statistics ─────────────────────────────────────
$saTotalRatings = (int) $conn->query("SELECT COUNT(*) AS cnt FROM sa_feedback_ratings")->fetch_assoc()['cnt'];
$saTotalSubmissions = (int) $conn->query("SELECT COUNT(*) AS cnt FROM sa_feedback_submissions")->fetch_assoc()['cnt'];
$saTotalForms = (int) $conn->query("SELECT COUNT(*) AS cnt FROM sa_feedback_forms")->fetch_assoc()['cnt'];

$saRatingDist = $conn->query("SELECT rating, COUNT(*) AS qty FROM sa_feedback_ratings GROUP BY rating ORDER BY FIELD(rating, 'Excellent', 'Good', 'Fair', 'Poor')")->fetch_all(MYSQLI_ASSOC);
$saNormalized = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
foreach ($saRatingDist as $rd) {
    $r = trim($rd['rating']);
    if (in_array($r, ['Excellent', 'Good'])) $saNormalized['Good'] += (int)$rd['qty'];
    elseif (in_array($r, ['Fair'])) $saNormalized['Fair'] += (int)$rd['qty'];
    elseif (in_array($r, ['Poor'])) $saNormalized['Bad'] += (int)$rd['qty'];
}

$saAvgResult = $conn->query("SELECT AVG(CASE WHEN rating='Excellent' THEN 5 WHEN rating='Good' THEN 4 WHEN rating='Fair' THEN 3 WHEN rating='Poor' THEN 1 ELSE 3 END) AS avg_rating FROM sa_feedback_ratings")->fetch_assoc();
$saAvgRating = $saAvgResult['avg_rating'] ? round((float)$saAvgResult['avg_rating'], 2) : 0;

// SA per-form breakdown
$saFormBreakdown = $conn->query("
    SELECT sf.id, sf.title, COUNT(sfr.id) AS total_ratings,
           AVG(CASE WHEN sfr.rating='Excellent' THEN 5 WHEN sfr.rating='Good' THEN 4 WHEN sfr.rating='Fair' THEN 3 WHEN sfr.rating='Poor' THEN 1 ELSE 3 END) AS avg_rating
    FROM sa_feedback_ratings sfr
    JOIN sa_feedback_forms sf ON sfr.form_id = sf.id
    GROUP BY sf.id, sf.title
    ORDER BY total_ratings DESC
")->fetch_all(MYSQLI_ASSOC);

// ─── Admin Feedback Statistics ──────────────────────────────────
$admTotalRatings = (int) $conn->query("SELECT COUNT(*) AS cnt FROM adm_feedback_ratings")->fetch_assoc()['cnt'];
$admTotalSubmissions = (int) $conn->query("SELECT COUNT(*) AS cnt FROM adm_feedback_submissions")->fetch_assoc()['cnt'];
$admTotalForms = (int) $conn->query("SELECT COUNT(*) AS cnt FROM adm_feedback_forms")->fetch_assoc()['cnt'];

$admRatingDist = $conn->query("SELECT rating, COUNT(*) AS qty FROM adm_feedback_ratings GROUP BY rating ORDER BY FIELD(rating, 'Excellent', 'Good', 'Fair', 'Poor')")->fetch_all(MYSQLI_ASSOC);
$admNormalized = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
foreach ($admRatingDist as $rd) {
    $r = trim($rd['rating']);
    if (in_array($r, ['Excellent', 'Good'])) $admNormalized['Good'] += (int)$rd['qty'];
    elseif (in_array($r, ['Fair'])) $admNormalized['Fair'] += (int)$rd['qty'];
    elseif (in_array($r, ['Poor'])) $admNormalized['Bad'] += (int)$rd['qty'];
}

$admAvgResult = $conn->query("SELECT AVG(CASE WHEN rating='Excellent' THEN 5 WHEN rating='Good' THEN 4 WHEN rating='Fair' THEN 3 WHEN rating='Poor' THEN 1 ELSE 3 END) AS avg_rating FROM adm_feedback_ratings")->fetch_assoc();
$admAvgRating = $admAvgResult['avg_rating'] ? round((float)$admAvgResult['avg_rating'], 2) : 0;

// Admin per-form breakdown
$admFormBreakdown = $conn->query("
    SELECT af.id, af.title, COUNT(afr.id) AS total_ratings,
           AVG(CASE WHEN afr.rating='Excellent' THEN 5 WHEN afr.rating='Good' THEN 4 WHEN afr.rating='Fair' THEN 3 WHEN afr.rating='Poor' THEN 1 ELSE 3 END) AS avg_rating
    FROM adm_feedback_ratings afr
    JOIN adm_feedback_forms af ON afr.form_id = af.id
    GROUP BY af.id, af.title
    ORDER BY total_ratings DESC
")->fetch_all(MYSQLI_ASSOC);

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
    <h2 class="text-2xl font-bold text-slate-800">Reports & Analytics</h2>
    <p class="text-sm text-slate-500 mt-1">Graphical and statistical analysis across all feedback modules</p>
</div>

<!-- Filters -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div class="flex-1 min-w-[180px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Semester</label>
            <select name="semester" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                <option value="">All Semesters</option>
                <?php foreach ($semesters as $s): ?>
                    <option value="<?= e($s['semester']) ?>" <?= $filterSemester === $s['semester'] ? 'selected' : '' ?>>
                        <?= e($s['semester']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[180px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Teacher</label>
            <select name="teacher_id" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                <option value="0">All Teachers</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= $filterTeacher === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= e($t['teacher_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[180px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Subject</label>
            <select name="course_id" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                <option value="0">All Subjects</option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $filterCourse === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['course_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-5 py-2 bg-cyan-600 text-white text-sm font-semibold rounded-xl hover:bg-cyan-700 transition-colors">
                Filter
            </button>
            <a href="reports.php" class="px-4 py-2 bg-slate-100 text-slate-600 text-sm font-semibold rounded-xl hover:bg-slate-200 transition-colors">
                Reset
            </a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-4 mb-8">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-cyan-600 flex items-center justify-center shadow-sm"><?= iconSvg('clipboard', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-cyan-700"><?= number_format($totalRatings) ?></p><p class="text-[10px] text-slate-500 font-medium">Total Ratings</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-blue-600 flex items-center justify-center shadow-sm"><?= iconSvg('academic', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-blue-700"><?= number_format($totalSubmissions) ?></p><p class="text-[10px] text-slate-500 font-medium">Submissions</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-indigo-600 flex items-center justify-center shadow-sm"><?= iconSvg('user', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-indigo-700"><?= number_format($totalTeachers) ?></p><p class="text-[10px] text-slate-500 font-medium">Teachers</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-emerald-600 flex items-center justify-center shadow-sm"><?= iconSvg('document', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-emerald-700"><?= number_format($totalForms) ?></p><p class="text-[10px] text-slate-500 font-medium">Forms</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-amber-500 flex items-center justify-center shadow-sm"><?= iconSvg('star', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-amber-600"><?= $avgRating ?></p><p class="text-[10px] text-slate-500 font-medium">Avg Rating</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-purple-600 flex items-center justify-center shadow-sm"><?= iconSvg('chart', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-purple-700"><?= number_format($totalRatings + $saTotalRatings + $admTotalRatings) ?></p><p class="text-[10px] text-slate-500 font-medium">All Ratings</p></div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- ACADEMIC FEEDBACK SECTION                                 -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="mb-2">
    <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
        <span class="w-2 h-2 rounded-full bg-cyan-500 inline-block"></span>
        Academic Feedback
    </h3>
</div>

<!-- Row 1: Semester Stats -->
<div class="mb-6">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-4">Semester Feedback Statistics</h3>
        <div class="relative" style="height:280px;">
            <canvas id="semesterChart"></canvas>
        </div>
    </div>
</div>

<!-- Row 2: Teacher Performance Bar + Subject Performance Bar -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-4">Teacher Performance (by Feedback Count)</h3>
        <div class="relative" style="height:280px;">
            <canvas id="teacherBarChart"></canvas>
        </div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-4">Subject Performance (by Feedback Count)</h3>
        <div class="relative" style="height:280px;">
            <canvas id="courseBarChart"></canvas>
        </div>
    </div>
</div>

<!-- Section Breakdown Table -->
<?php if (!empty($sectionBreakdown)): ?>
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden mb-6">
    <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-sm font-bold text-slate-800">Detailed Section Breakdown</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="bg-slate-50 text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <th class="px-6 py-3">Subject</th>
                    <th class="px-6 py-3">Section</th>
                    <th class="px-6 py-3">Semester</th>
                    <th class="px-6 py-3 text-center">Total Ratings</th>
                    <th class="px-6 py-3 text-center">Avg Rating</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($sectionBreakdown as $sb): ?>
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-6 py-3 font-medium text-slate-800"><?= e($sb['course_name']) ?></td>
                    <td class="px-6 py-3 text-slate-600"><?= e($sb['section']) ?></td>
                    <td class="px-6 py-3 text-slate-600"><?= e($sb['semester']) ?></td>
                    <td class="px-6 py-3 text-center font-semibold text-slate-700"><?= number_format($sb['total_ratings']) ?></td>
                    <td class="px-6 py-3 text-center">
                        <?php $avg = round((float)$sb['avg_rating'], 1); $color = $avg >= 4 ? 'text-emerald-600' : ($avg >= 3 ? 'text-amber-600' : 'text-red-600'); ?>
                        <span class="font-bold <?= $color ?>"><?= $avg ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Per-Teacher Rating Distribution Pie Charts -->
<?php if (!empty($teacherRatingData)): ?>
<div class="mb-6">
    <h3 class="text-sm font-bold text-slate-800 mb-3">Teacher Rating Distribution</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-5">
        <?php
        $colorMap = ['Excellent' => '#10b981', 'Good' => '#22c55e', 'Fair' => '#f59e0b', 'Poor' => '#ef4444'];
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
        </div>
        <?php $chartIndex++; endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- STUDENT AFFAIRS FEEDBACK SECTION                          -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="border-t-2 border-slate-200 pt-6 mb-2">
    <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
        <span class="w-2 h-2 rounded-full bg-purple-500 inline-block"></span>
        Student Affairs Feedback
    </h3>
</div>

<!-- SA Summary Cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-purple-600 flex items-center justify-center shadow-sm"><?= iconSvg('clipboard', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-purple-700"><?= number_format($saTotalRatings) ?></p><p class="text-[10px] text-slate-500 font-medium">SA Ratings</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-violet-600 flex items-center justify-center shadow-sm"><?= iconSvg('academic', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-violet-700"><?= number_format($saTotalSubmissions) ?></p><p class="text-[10px] text-slate-500 font-medium">SA Submissions</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-purple-500 flex items-center justify-center shadow-sm"><?= iconSvg('document', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-purple-600"><?= number_format($saTotalForms) ?></p><p class="text-[10px] text-slate-500 font-medium">SA Forms</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-amber-500 flex items-center justify-center shadow-sm"><?= iconSvg('star', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-amber-600"><?= $saAvgRating ?></p><p class="text-[10px] text-slate-500 font-medium">SA Avg Rating</p></div>
    </div>
</div>

<!-- SA Charts -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-4">SA Rating Distribution (Good / Fair / Bad)</h3>
        <div class="relative flex items-center justify-center" style="height:280px;">
            <canvas id="saRatingPie"></canvas>
        </div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-4">SA Form Performance</h3>
        <?php if (!empty($saFormBreakdown)): ?>
        <div class="relative" style="height:280px;">
            <canvas id="saFormBar"></canvas>
        </div>
        <?php else: ?>
        <div class="flex items-center justify-center h-[280px] text-slate-400 text-sm">No data available.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════ -->
<!-- ADMINISTRATION FEEDBACK SECTION                           -->
<!-- ═══════════════════════════════════════════════════════════ -->
<div class="border-t-2 border-slate-200 pt-6 mb-2">
    <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
        <span class="w-2 h-2 rounded-full bg-orange-500 inline-block"></span>
        Administration Feedback
    </h3>
</div>

<!-- Admin Summary Cards -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-orange-600 flex items-center justify-center shadow-sm"><?= iconSvg('clipboard', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-orange-700"><?= number_format($admTotalRatings) ?></p><p class="text-[10px] text-slate-500 font-medium">Adm Ratings</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-orange-500 flex items-center justify-center shadow-sm"><?= iconSvg('academic', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-orange-600"><?= number_format($admTotalSubmissions) ?></p><p class="text-[10px] text-slate-500 font-medium">Adm Submissions</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-orange-400 flex items-center justify-center shadow-sm"><?= iconSvg('document', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-orange-500"><?= number_format($admTotalForms) ?></p><p class="text-[10px] text-slate-500 font-medium">Adm Forms</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-11 h-11 rounded-xl bg-amber-500 flex items-center justify-center shadow-sm"><?= iconSvg('star', 'w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-amber-600"><?= $admAvgRating ?></p><p class="text-[10px] text-slate-500 font-medium">Adm Avg Rating</p></div>
    </div>
</div>

<!-- Admin Charts -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-4">Adm Rating Distribution (Good / Fair / Bad)</h3>
        <div class="relative flex items-center justify-center" style="height:280px;">
            <canvas id="admRatingPie"></canvas>
        </div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-4">Adm Form Performance</h3>
        <?php if (!empty($admFormBreakdown)): ?>
        <div class="relative" style="height:280px;">
            <canvas id="admFormBar"></canvas>
        </div>
        <?php else: ?>
        <div class="flex items-center justify-center h-[280px] text-slate-400 text-sm">No data available.</div>
        <?php endif; ?>
    </div>
</div>

<!-- ─── Chart Initialization Scripts ──────────────────────────── -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var chartDefaults = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { labels: { font: { family: 'Inter', size: 11 } } } }
    };

    // ─── Semester Bar Chart ────────────────────────────────────
    new Chart(document.getElementById('semesterChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($semesterLabels) ?>,
            datasets: [{
                label: 'Submissions',
                data: <?= json_encode($semesterValues) ?>,
                backgroundColor: 'rgba(8,145,178,0.7)',
                borderColor: '#0891b2',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: Object.assign({}, chartDefaults, {
            scales: {
                y: { beginAtZero: true, ticks: { font: { size: 11 } } },
                x: { ticks: { font: { size: 11 } } }
            },
            plugins: { legend: { display: false } }
        })
    });

    // ─── Teacher Performance Bar ───────────────────────────────
    new Chart(document.getElementById('teacherBarChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($teacherPerfLabels) ?>,
            datasets: [{
                label: 'Ratings Received',
                data: <?= json_encode($teacherPerfValues) ?>,
                backgroundColor: 'rgba(99,102,241,0.7)',
                borderColor: '#6366f1',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: Object.assign({}, chartDefaults, {
            indexAxis: 'y',
            scales: {
                x: { beginAtZero: true, ticks: { font: { size: 11 } } },
                y: { ticks: { font: { size: 10 } } }
            },
            plugins: { legend: { display: false } }
        })
    });

    // ─── Subject Performance Bar ──────────────────────────────
    new Chart(document.getElementById('courseBarChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($coursePerfLabels) ?>,
            datasets: [{
                label: 'Ratings Received',
                data: <?= json_encode($coursePerfValues) ?>,
                backgroundColor: 'rgba(14,165,233,0.7)',
                borderColor: '#0ea5e9',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: Object.assign({}, chartDefaults, {
            indexAxis: 'y',
            scales: {
                x: { beginAtZero: true, ticks: { font: { size: 11 } } },
                y: { ticks: { font: { size: 10 } } }
            },
            plugins: { legend: { display: false } }
        })
    });

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
                legend: { position: 'bottom', labels: { font: { size: 9 }, padding: 8 } }
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
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 16 } } }
        })
    });

    // ─── SA Form Bar ──────────────────────────────────────────
    <?php if (!empty($saFormBreakdown)): ?>
    new Chart(document.getElementById('saFormBar'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($saFormBreakdown, 'title')) ?>,
            datasets: [{
                label: 'Ratings',
                data: <?= json_encode(array_column($saFormBreakdown, 'total_ratings')) ?>,
                backgroundColor: 'rgba(139,92,246,0.7)',
                borderColor: '#8b5cf6',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: Object.assign({}, chartDefaults, {
            indexAxis: 'y',
            scales: {
                x: { beginAtZero: true, ticks: { font: { size: 11 } } },
                y: { ticks: { font: { size: 10 } } }
            },
            plugins: { legend: { display: false } }
        })
    });
    <?php endif; ?>

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
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 16 } } }
        })
    });

    // ─── Admin Form Bar ───────────────────────────────────────
    <?php if (!empty($admFormBreakdown)): ?>
    new Chart(document.getElementById('admFormBar'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_column($admFormBreakdown, 'title')) ?>,
            datasets: [{
                label: 'Ratings',
                data: <?= json_encode(array_column($admFormBreakdown, 'total_ratings')) ?>,
                backgroundColor: 'rgba(249,115,22,0.7)',
                borderColor: '#f97316',
                borderWidth: 1,
                borderRadius: 6
            }]
        },
        options: Object.assign({}, chartDefaults, {
            indexAxis: 'y',
            scales: {
                x: { beginAtZero: true, ticks: { font: { size: 11 } } },
                y: { ticks: { font: { size: 10 } } }
            },
            plugins: { legend: { display: false } }
        })
    });
    <?php endif; ?>
});
</script>

<?php include '../includes/admin_footer.php'; ?>
