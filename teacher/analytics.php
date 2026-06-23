<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('teacher');

$user = getCurrentUser();
$stmt = $conn->prepare("SELECT t.id FROM teachers t WHERE t.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();
$teacherId = $teacher['id'] ?? 0;

$pageTitle  = 'Feedback Analytics';
$activeMenu = 'analytics';

// ─── Filter Inputs ─────────────────────────────────────────────
$filterSemester = clean($_GET['semester'] ?? '');
$filterSection  = (int)($_GET['section_id'] ?? 0);
$filterCourse   = (int)($_GET['course_id'] ?? 0);

// ─── Filter Options (only this teacher's data) ─────────────────
$semesters = [];
$sections  = [];
$courses   = [];
if ($teacherId) {
    $rs = $conn->query("SELECT DISTINCT s.semester FROM sections s WHERE s.teacher_id=$teacherId AND s.semester IS NOT NULL AND s.semester != '' ORDER BY s.semester DESC");
    while ($r = $rs->fetch_assoc()) $semesters[] = $r['semester'];

    $rs = $conn->query("SELECT s.id, c.course_name, s.section, s.semester FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.teacher_id=$teacherId ORDER BY s.semester DESC, c.course_name ASC");
    $sections = $rs->fetch_all(MYSQLI_ASSOC);

    $rs = $conn->query("SELECT DISTINCT c.id, c.course_name FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.teacher_id=$teacherId ORDER BY c.course_name ASC");
    $courses = $rs->fetch_all(MYSQLI_ASSOC);
}

// ─── Build WHERE clause ────────────────────────────────────────
$whereParts = ['s.teacher_id = ?'];
$params     = [$teacherId];
$types      = 'i';

if ($filterSemester !== '') {
    $whereParts[] = 's.semester = ?';
    $params[]     = $filterSemester;
    $types       .= 's';
}
if ($filterSection > 0) {
    $whereParts[] = 's.id = ?';
    $params[]     = $filterSection;
    $types       .= 'i';
}
if ($filterCourse > 0) {
    $whereParts[] = 's.course_id = ?';
    $params[]     = $filterCourse;
    $types       .= 'i';
}

$whereSql = 'WHERE ' . implode(' AND ', $whereParts);

function runQuery($conn, $sql, $types, $params) {
    if ($types === '') return $conn->query($sql);
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
    JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
    JOIN sections s ON ff.section_id = s.id
    $whereSql
";
$totalFeedback = (int) runQuery($conn, $totalFeedbackSql, $types, $params)->fetch_assoc()['cnt'];

$totalSubmissionsSql = "
    SELECT COUNT(*) AS cnt
    FROM feedback_submissions fs
    JOIN feedback_forms ff ON fs.feedback_form_id = ff.id
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
    JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
    JOIN sections s ON ff.section_id = s.id
    $whereSql
";
$avgResult = runQuery($conn, $avgSql, $types, $params)->fetch_assoc();
$avgRating = $avgResult['avg_rating'] ? round((float)$avgResult['avg_rating'], 2) : 0;

// ─── Rating Distribution (Good / Fair / Bad) ───────────────────
$ratingDistSql = "
    SELECT fr.rating, COUNT(*) AS qty
    FROM feedback_ratings fr
    JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
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
        $ratingData['Good'] += (int)$rd['qty'];
    } elseif (in_array($r, ['Fair', 'fair', 'Normal', 'normal', 'Average', '2', 'သင့်'])) {
        $ratingData['Fair'] += (int)$rd['qty'];
    } elseif (in_array($r, ['Poor', 'Bad', 'bad', '1', 'ညံ့'])) {
        $ratingData['Bad'] += (int)$rd['qty'];
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
    JOIN feedback_forms ff ON fr.feedback_form_id = ff.id
    JOIN sections s ON ff.section_id = s.id
    JOIN courses c ON s.course_id = c.id
    $whereSql
    GROUP BY s.id, c.course_name, s.section, s.semester
    ORDER BY total_ratings DESC
";
$sectionBreakdown = runQuery($conn, $sectionBreakdownSql, $types, $params)->fetch_all(MYSQLI_ASSOC);

$navItems = [
    ['label' => 'Dashboard',        'href' => '/studentfeedback/teacher/index.php',            'key' => 'dashboard', 'icon' => 'home'],
    ['label' => 'My Sections',      'href' => '/studentfeedback/teacher/my_sections.php',       'key' => 'sections',  'icon' => 'grid'],
    ['label' => 'Feedback Results', 'href' => '/studentfeedback/teacher/feedback_results.php',  'key' => 'results',   'icon' => 'chart'],
    ['label' => 'Analytics',        'href' => '/studentfeedback/teacher/analytics.php',         'key' => 'analytics', 'icon' => 'report'],
    ['label' => 'Progress',         'href' => '/studentfeedback/teacher/feedback_progress.php', 'key' => 'progress',  'icon' => 'clipboard'],
    ['label' => 'Profile',          'href' => '/studentfeedback/teacher/profile.php',           'key' => 'profile',   'icon' => 'user'],
];
$initials = avatarInitials($user['name']);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={theme:{extend:{fontFamily:{inter:['Inter','sans-serif']}}}}</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/studentfeedback/assets/css/custom.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
</head>
<body class="h-full bg-slate-50 font-inter antialiased">
<div id="sidebar-overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
<div class="flex h-screen overflow-hidden">

<!-- Sidebar -->
<aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
    <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
        <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shadow-lg"><?= iconSvg('user','w-5 h-5 text-white') ?></div>
        <div><p class="text-sm font-bold">SFMS Teacher</p><p class="text-[10px] text-cyan-100">Faculty Portal</p></div>
        <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-200 hover:text-white">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-0.5">
        <?php foreach ($navItems as $item):
            $active = $activeMenu === $item['key'];
            $cls = $active ? 'bg-white/20 text-white font-semibold' : 'text-cyan-100 hover:bg-white/10 hover:text-white';
        ?>
        <a href="<?= $item['href'] ?>" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm transition-all <?= $cls ?>">
            <?= iconSvg($item['icon'],'w-4 h-4 flex-shrink-0') ?> <?= e($item['label']) ?>
            <?php if ($active): ?><span class="ml-auto w-1.5 h-1.5 rounded-full bg-white"></span><?php endif ?>
        </a>
        <?php endforeach ?>
    </nav>
    <div class="border-t border-cyan-500 px-4 py-4">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold flex-shrink-0"><?= e($initials) ?></div>
            <div class="flex-1 min-w-0"><p class="text-xs font-semibold text-white truncate"><?= e($user['name']) ?></p><p class="text-[10px] text-cyan-100 truncate"><?= e($user['email']) ?></p></div>
            <a href="/studentfeedback/auth/logout.php" class="text-cyan-200 hover:text-red-300"><?= iconSvg('logout','w-4 h-4') ?></a>
        </div>
    </div>
</aside>

<!-- Main -->
<div class="flex-1 flex flex-col min-w-0 overflow-hidden">
    <header class="bg-white border-b border-slate-200 px-4 lg:px-6 py-3.5 flex items-center gap-4 flex-shrink-0 sticky top-0 z-20 shadow-sm">
        <button onclick="openSidebar()" class="lg:hidden text-slate-500 hover:text-slate-800">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
        </button>
        <h1 class="text-base font-semibold text-slate-800"><?= e($pageTitle) ?></h1>
        <div class="ml-auto flex items-center gap-3">
            <a href="/studentfeedback/teacher/profile.php" class="flex items-center gap-2 px-3 py-1.5 rounded-xl hover:bg-slate-50">
                <div class="w-7 h-7 rounded-full bg-cyan-600 flex items-center justify-center text-xs font-bold text-white"><?= e($initials) ?></div>
                <span class="hidden md:block text-sm font-medium text-slate-700"><?= e($user['name']) ?></span>
            </a>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto p-4 lg:p-6">

<!-- Page Header -->
<div class="mb-6">
    <h2 class="text-2xl font-bold text-slate-800">Feedback Analytics</h2>
    <p class="text-sm text-slate-500 mt-1">View your feedback performance with graphical and statistical analysis</p>
</div>

<!-- Filters -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div class="flex-1 min-w-[170px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Semester</label>
            <select name="semester" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 bg-white">
                <option value="">All Semesters</option>
                <?php foreach ($semesters as $s): ?>
                    <option value="<?= e($s) ?>" <?= $filterSemester === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[170px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Section</label>
            <select name="section_id" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 bg-white">
                <option value="0">All Sections</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?= (int)$sec['id'] ?>" <?= $filterSection === (int)$sec['id'] ? 'selected' : '' ?>>
                        <?= e($sec['course_name']) ?> — Sec <?= e($sec['section']) ?> (<?= e($sec['semester']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[170px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Subject</label>
            <select name="course_id" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 bg-white">
                <option value="0">All Subjects</option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $filterCourse === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['course_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-5 py-2 bg-cyan-600 text-white text-sm font-semibold rounded-xl hover:bg-cyan-700 transition-colors">Filter</button>
            <a href="analytics.php" class="px-4 py-2 bg-slate-100 text-slate-600 text-sm font-semibold rounded-xl hover:bg-slate-200 transition-colors">Reset</a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-cyan-600 flex items-center justify-center shadow"><?= iconSvg('clipboard','w-6 h-6 text-white') ?></div>
        <div><p class="text-2xl font-bold text-cyan-700"><?= number_format($totalFeedback) ?></p><p class="text-xs text-slate-500">Total Feedback Responses</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-amber-500 flex items-center justify-center shadow"><?= iconSvg('star','w-6 h-6 text-white') ?></div>
        <div><p class="text-2xl font-bold text-amber-600"><?= $avgRating ?></p><p class="text-xs text-slate-500">Average Rating (out of 5)</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-emerald-600 flex items-center justify-center shadow"><?= iconSvg('document','w-6 h-6 text-white') ?></div>
        <div><p class="text-2xl font-bold text-emerald-700"><?= number_format($totalForms) ?></p><p class="text-xs text-slate-500">Feedback Forms</p></div>
    </div>
</div>

<!-- Charts Row -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
    <!-- Rating Distribution Pie -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-4">Rating Distribution (Good / Fair / Bad)</h3>
        <div class="relative flex items-center justify-center" style="height:300px;">
            <canvas id="ratingPieChart"></canvas>
        </div>
    </div>

    <!-- Per-Section Performance -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
        <h3 class="text-sm font-bold text-slate-800 mb-4">Performance by Section</h3>
        <?php if (!empty($sectionBreakdown)): ?>
        <div class="relative" style="height:300px;">
            <canvas id="sectionBarChart"></canvas>
        </div>
        <?php else: ?>
        <div class="flex items-center justify-center h-[300px] text-slate-400 text-sm">No data available.</div>
        <?php endif; ?>
    </div>
</div>

<!-- Per-Section Breakdown Table -->
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
                        <?php
                        $avg = round((float)$sb['avg_rating'], 1);
                        $color = $avg >= 4 ? 'text-emerald-600' : ($avg >= 3 ? 'text-amber-600' : 'text-red-600');
                        ?>
                        <span class="font-bold <?= $color ?>"><?= $avg ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Chart Scripts -->
<script>
document.addEventListener('DOMContentLoaded', function() {
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
                    legend: { position: 'bottom', labels: { font: { family: 'Inter', size: 12 }, padding: 16 } }
                }
            }
        });
    }

    // Section Bar Chart
    var barCtx = document.getElementById('sectionBarChart');
    if (barCtx) {
        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_map(fn($s) => $s['course_name'] . ' Sec ' . $s['section'], $sectionBreakdown)) ?>,
                datasets: [{
                    label: 'Ratings',
                    data: <?= json_encode(array_column($sectionBreakdown, 'total_ratings')) ?>,
                    backgroundColor: 'rgba(8,145,178,0.7)',
                    borderColor: '#0891b2',
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                scales: {
                    x: { beginAtZero: true, ticks: { font: { size: 11 } } },
                    y: { ticks: { font: { size: 10 } } }
                },
                plugins: { legend: { display: false } }
            }
        });
    }
});
</script>

    </main>
</div>
</div>

<script>
function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('sidebar-overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('sidebar-overlay').classList.add('hidden'); }
</script>
</body>
</html>
