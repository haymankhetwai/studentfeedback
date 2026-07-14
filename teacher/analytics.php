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

$pageTitle = $LANG['analytics_title'] ?? 'Feedback Analytics';
$activeMenu = 'analytics';

// ─── Filter Inputs ─────────────────────────────────────────────
$filterSemester = clean($_GET['semester'] ?? '');
$filterSection = (int) ($_GET['section_id'] ?? 0);
$filterCourse = (int) ($_GET['course_id'] ?? 0);

// ─── Filter Options (only this teacher's data) ─────────────────
$semesters = [];
$sections = [];
$courses = [];
if ($teacherId) {
    $rs = $conn->query("SELECT DISTINCT s.semester FROM sections s WHERE s.teacher_id=$teacherId AND s.semester IS NOT NULL AND s.semester != '' ORDER BY s.semester DESC");
    while ($r = $rs->fetch_assoc())
        $semesters[] = $r['semester'];

    $rs = $conn->query("SELECT s.id, c.course_name, s.section, s.semester FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.teacher_id=$teacherId ORDER BY s.semester DESC, c.course_name ASC");
    $sections = $rs->fetch_all(MYSQLI_ASSOC);

    $rs = $conn->query("SELECT DISTINCT c.id, c.course_name FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.teacher_id=$teacherId ORDER BY c.course_name ASC");
    $courses = $rs->fetch_all(MYSQLI_ASSOC);
}

// ─── Build WHERE clause ────────────────────────────────────────
$whereParts = ['s.teacher_id = ?'];
$params = [$teacherId];
$types = 'i';

if ($filterSemester !== '') {
    $whereParts[] = 's.semester = ?';
    $params[] = $filterSemester;
    $types .= 's';
}
if ($filterSection > 0) {
    $whereParts[] = 's.id = ?';
    $params[] = $filterSection;
    $types .= 'i';
}
if ($filterCourse > 0) {
    $whereParts[] = 's.course_id = ?';
    $params[] = $filterCourse;
    $types .= 'i';
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

function runQuery($conn, $sql, $types, $params)
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

// ─── Summary Stats ─────────────────────────────────────────────
$totalFeedbackSql = "
    SELECT COUNT(*) AS cnt
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.form_id = ff.id
    JOIN sections s ON ff.section_id = s.id
    $whereSql
";
$totalFeedback = (int) runQuery($conn, $totalFeedbackSql, $types, $params)->fetch_assoc()['cnt'];

$totalSubmissionsSql = "
    SELECT COUNT(*) AS cnt
    FROM feedback_submissions fs
    JOIN feedback_forms ff ON fs.form_id = ff.id
    JOIN sections s ON ff.section_id = s.id
    $whereSql
";
$totalSubmissions = (int) runQuery($conn, $totalSubmissionsSql, $types, $params)->fetch_assoc()['cnt'];

$totalFormsSql = "
    SELECT COUNT(DISTINCT ff.id) AS cnt
    FROM feedback_forms ff
    JOIN sections s ON ff.section_id = s.id
    $whereSql
";
$totalForms = (int) runQuery($conn, $totalFormsSql, $types, $params)->fetch_assoc()['cnt'];

// ─── Average Rating (normalized 1–5 scale) ─────────────────────
$avgSql = "
    SELECT AVG(
        CASE
            WHEN fr.rating IN ('Excellent') THEN 5
            WHEN fr.rating IN ('Good') THEN 4
            WHEN fr.rating IN ('Fair') THEN 3
            WHEN fr.rating IN ('Poor') THEN 1
            ELSE 3
        END
    ) AS avg_rating
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.form_id = ff.id
    JOIN sections s ON ff.section_id = s.id
    $whereSql
";
$avgResult = runQuery($conn, $avgSql, $types, $params)->fetch_assoc();
$avgRating = $avgResult['avg_rating'] ? round((float) $avgResult['avg_rating'], 2) : 0;

// ─── Rating Distribution (Good / Fair / Bad) ───────────────────
$ratingDistSql = "
    SELECT fr.rating, COUNT(*) AS qty
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.form_id = ff.id
    JOIN sections s ON ff.section_id = s.id
    $whereSql
    GROUP BY fr.rating
    ORDER BY FIELD(fr.rating, 'Excellent', 'Good', 'Fair', 'Poor')
";
$ratingDistResult = runQuery($conn, $ratingDistSql, $types, $params);
$ratingDistRaw = $ratingDistResult->fetch_all(MYSQLI_ASSOC);

// Normalize to Good/Fair/Bad
$ratingData = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
foreach ($ratingDistRaw as $rd) {
    $r = trim($rd['rating']);
    if (in_array($r, ['Excellent', 'Good', 'good', '3', 'ကောင်း'])) {
        $ratingData['Good'] += (int) $rd['qty'];
    } elseif (in_array($r, ['Fair', 'fair', 'Normal', 'normal', 'Average', '2', 'သင့်'])) {
        $ratingData['Fair'] += (int) $rd['qty'];
    } elseif (in_array($r, ['Poor', 'Bad', 'bad', '1', 'ညံ့'])) {
        $ratingData['Bad'] += (int) $rd['qty'];
    }
}

$pieLabels = array_keys($ratingData);
$pieValues = array_values($ratingData);
$pieColors = ['#22c55e', '#f59e0b', '#ef4444'];

// ─── Per-Section Breakdown ──────────────────────────────────────
$sectionBreakdownSql = "
    SELECT s.id AS section_id, c.course_name, s.section, s.semester,
           COUNT(fr.id) AS total_ratings,
           AVG(CASE WHEN fr.rating IN ('Excellent','Good') THEN 5 WHEN fr.rating = 'Fair' THEN 3 WHEN fr.rating IN ('Poor','Bad') THEN 1 ELSE 3 END) AS avg_rating
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.form_id = ff.id
    JOIN sections s ON ff.section_id = s.id
    JOIN courses c ON s.course_id = c.id
    $whereSql
    GROUP BY s.id, c.course_name, s.section, s.semester
    ORDER BY total_ratings DESC
";
$sectionBreakdown = runQuery($conn, $sectionBreakdownSql, $types, $params)->fetch_all(MYSQLI_ASSOC);

?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { inter: ['Inter', 'sans-serif'] } } } }</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/studentfeedbackucsh/assets/css/custom.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
</head>

<body class="h-full bg-gradient-to-br from-slate-50 via-blue-50 to-sky-50 font-inter antialiased <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
    <?php require_once '../includes/teacher_sidebar.php'; ?>

    <!-- Page Header -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-slate-800"><?= $LANG['analytics_title'] ?? 'Feedback Analytics' ?></h2>
        <p class="text-sm text-slate-500 mt-1"><?= $LANG['analytics_subtitle'] ?? 'View your feedback performance with graphical and statistical analysis' ?>
        </p>
    </div>

    <!-- Filters -->
    <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-4">
            
            <div class="flex-1 max-w-xl">
                <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG['section_filter'] ?? 'Section' ?></label>
                <select name="section_id"
                    class="w-full rounded-xl border border-blue-200/50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-400/20 focus:border-blue-400 bg-white/80">
                    <option value="0"><?= $LANG['all_sections'] ?? 'All Sections' ?></option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?= (int) $sec['id'] ?>" <?= $filterSection === (int) $sec['id'] ? 'selected' : '' ?>>
                            <?= e($sec['course_name']) ?> — Sec <?= e($sec['section']) ?> (<?= e(formatSemester($sec['semester'])) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
           
            <div class="flex gap-2">
                <button type="submit"
                    class="px-5 py-2 bg-blue-600 text-white text-sm font-semibold rounded-xl hover:bg-blue-700 transition-colors"><?= $LANG['filter'] ?? 'Filter' ?></button>
                <a href="analytics.php"
                    class="px-4 py-2 bg-blue-50/50 text-blue-600 text-sm font-semibold rounded-xl hover:bg-blue-100/50 transition-colors"><?= $LANG['reset'] ?? 'Reset' ?></a>
            </div>
        </form>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
        <div
            class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-blue-600 flex items-center justify-center shadow">
                <?= iconSvg('clipboard', 'w-6 h-6 text-white') ?></div>
            <div>
                <p class="text-2xl font-bold text-blue-700"><?= number_format($totalFeedback) ?></p>
                <p class="text-xs text-slate-500"><?= $LANG['total_feedback_responses'] ?? 'Total Feedback Responses' ?></p>
            </div>
        </div>

        <div
            class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 flex items-center gap-4">
            <div class="w-12 h-12 rounded-xl bg-emerald-600 flex items-center justify-center shadow">
                <?= iconSvg('document', 'w-6 h-6 text-white') ?></div>
            <div>
                <p class="text-2xl font-bold text-emerald-700"><?= number_format($totalForms) ?></p>
                <p class="text-xs text-slate-500"><?= $LANG['feedback_forms'] ?? 'Feedback Forms' ?></p>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <!-- Rating Distribution Pie -->
        <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5">
            <h3 class="text-sm font-bold text-slate-800 mb-4"><?= $LANG['rating_distribution'] ?? 'Rating Distribution (Good / Fair / Bad)' ?></h3>
            <div class="relative flex items-center justify-center" style="height:300px;">
                <canvas id="ratingPieChart"></canvas>
            </div>
            <?php
            $totalRatings = array_sum($ratingData);
            $pctGood = $totalRatings > 0 ? round(($ratingData['Good'] / $totalRatings) * 100) : 0;
            $pctFair = $totalRatings > 0 ? round(($ratingData['Fair'] / $totalRatings) * 100) : 0;
            $pctBad = $totalRatings > 0 ? round(($ratingData['Bad'] / $totalRatings) * 100) : 0;
            ?>
            <div class="grid grid-cols-3 gap-3 mt-4">
                <div class="text-center p-3 rounded-xl bg-emerald-50 border border-emerald-200">
                    <p class="text-2xl font-bold text-emerald-600"><?= $pctGood ?>%</p>
                    <p class="text-xs font-semibold text-emerald-700"><?= $LANG['good'] ?? 'Good' ?></p>
                    <p class="text-[10px] text-slate-500"><?= number_format($ratingData['Good']) ?> <?= $LANG['ratings'] ?? 'ratings' ?></p>
                </div>
                <div class="text-center p-3 rounded-xl bg-amber-50 border border-amber-200">
                    <p class="text-2xl font-bold text-amber-600"><?= $pctFair ?>%</p>
                    <p class="text-xs font-semibold text-amber-700"><?= $LANG['fair'] ?? 'Fair' ?></p>
                    <p class="text-[10px] text-slate-500"><?= number_format($ratingData['Fair']) ?> <?= $LANG['ratings'] ?? 'ratings' ?></p>
                </div>
                <div class="text-center p-3 rounded-xl bg-red-50 border border-red-200">
                    <p class="text-2xl font-bold text-red-600"><?= $pctBad ?>%</p>
                    <p class="text-xs font-semibold text-red-700"><?= $LANG['bad'] ?? 'Bad' ?></p>
                    <p class="text-[10px] text-slate-500"><?= number_format($ratingData['Bad']) ?> <?= $LANG['ratings'] ?? 'ratings' ?></p>
                </div>
            </div>
        </div>


    <!-- Per-Section Breakdown Table -->
    <?php if (!empty($sectionBreakdown)): ?>
        <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 overflow-hidden mb-6">
            <div class="px-6 py-4 border-b border-blue-100/50">
                <h3 class="text-sm font-bold text-slate-800"><?= $LANG['detailed_section_breakdown'] ?? 'Detailed Section Breakdown' ?></h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="bg-blue-200 text-sm font-semibold text-blue-500 uppercase tracking-wider">
                            <th class="px-6 py-3 text-sm font-semibold"><?= $LANG['col_course'] ?? 'Subject' ?></th>
                            <th class="px-6 py-3 text-sm font-semibold"><?= $LANG['col_section'] ?? 'Section' ?></th>
                            <th class="px-6 py-3 text-sm font-semibold"><?= $LANG['col_semester'] ?? 'Semester' ?></th>
                            <th class="px-6 py-3 text-sm font-semibold text-center"><?= $LANG['col_total_ratings'] ?? 'Total Ratings' ?></th>
                            <!-- <th class="px-6 py-3 text-center">Avg Rating</th> -->
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-blue-100/40">
                        <?php foreach ($sectionBreakdown as $sb): ?>
                            <tr class="hover:bg-blue-50/30 transition-colors">
                                <td class="px-6 py-3 font-medium text-slate-800"><?= e($sb['course_name']) ?></td>
                                <td class="px-6 py-3 text-slate-600"><?= e($sb['section']) ?></td>
                                <td class="px-6 py-3 text-slate-600"><?= e(formatSemester($sb['semester'])) ?></td>
                                <td class="px-6 py-3 text-center font-semibold text-slate-700">
                                    <?= number_format($sb['total_ratings']) ?></td>
                                <!-- <td class="px-6 py-3 text-center">
                        <?php
                        //$avg = round((float)$sb['avg_rating'], 1);
                        //$color = $avg >= 4 ? 'text-emerald-600' : ($avg >= 3 ? 'text-amber-600' : 'text-red-600');
                        ?>
                        <span class="font-bold <?= $color ?>"><?= $avg ?></span>
                    </td> -->
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <!-- Chart Scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Rating Pie Chart
            var pieCtx = document.getElementById('ratingPieChart');
            if (pieCtx) {
                new Chart(pieCtx, {
                    type: 'doughnut',
                    data: {
                        labels: <?= json_encode($pieLabels) ?>,
                        datasets: [{
                            data: <?= json_encode($pieValues) ?>,
                            backgroundColor: <?= json_encode($pieColors) ?>,
                            borderWidth: 2,
                            borderColor: '#fff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '55%',
                        plugins: {
                            legend: { position: 'bottom', labels: { font: { family: 'Inter', size: 12 }, padding: 16 } },
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
                    }
                });
            }

        });
    </script>

    <?php require_once '../includes/teacher_footer.php'; ?>