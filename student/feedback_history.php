<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('student');

$user      = getCurrentUser();
$stmt      = $conn->prepare("SELECT st.id FROM students st WHERE st.user_id=?");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$student   = $stmt->get_result()->fetch_assoc(); $stmt->close();
$studentId = $student['id'] ?? 0;

$pageTitle  = 'Feedback History';
$activeMenu = 'history';

// ─── Academic submissions ─────────────────────────────────────
$academicHistory = [];
if ($studentId) {
    $rs = $conn->prepare("
        SELECT fs.submitted_at, ff.title AS form_title, c.course_name, c.course_code, s.section, s.academic_year, s.semester
        FROM feedback_submissions fs
        JOIN feedback_forms ff ON fs.feedback_form_id=ff.id
        JOIN sections s ON ff.section_id=s.id
        JOIN courses c ON s.course_id=c.id
        WHERE fs.student_id=?
        ORDER BY fs.submitted_at DESC
    ");
    $rs->bind_param('i', $studentId); $rs->execute();
    $academicHistory = $rs->get_result()->fetch_all(MYSQLI_ASSOC); $rs->close();
}

// ─── Student Affairs submissions ──────────────────────────────
$saHistory = [];
if ($studentId) {
    $rs = $conn->prepare("
        SELECT sas.submitted_at, saf.title AS form_title, saf.start_date, saf.end_date
        FROM sa_feedback_submissions sas
        JOIN sa_feedback_forms saf ON sas.form_id=saf.id
        WHERE sas.student_id=?
        ORDER BY sas.submitted_at DESC
    ");
    $rs->bind_param('i', $studentId); $rs->execute();
    $saHistory = $rs->get_result()->fetch_all(MYSQLI_ASSOC); $rs->close();
}

// ─── Administration submissions ───────────────────────────────
$admHistory = [];
if ($studentId) {
    $rs = $conn->prepare("
        SELECT ads.submitted_at, adf.title AS form_title, adf.start_date, adf.end_date
        FROM adm_feedback_submissions ads
        JOIN adm_feedback_forms adf ON ads.form_id=adf.id
        WHERE ads.student_id=?
        ORDER BY ads.submitted_at DESC
    ");
    $rs->bind_param('i', $studentId); $rs->execute();
    $admHistory = $rs->get_result()->fetch_all(MYSQLI_ASSOC); $rs->close();
}

// Merge & sort all by date for the combined view
$allHistory = [];
foreach ($academicHistory as $r) {
    $allHistory[] = ['module' => 'academic', 'submitted_at' => $r['submitted_at'], 'form_title' => $r['form_title'],
        'detail' => e($r['course_name']) . ' (' . e($r['course_code']) . ') — Sec ' . e($r['section']) . ' · ' . e($r['academic_year']) . ' ' . e($r['semester'])];
}
foreach ($saHistory as $r) {
    $allHistory[] = ['module' => 'student_affairs', 'submitted_at' => $r['submitted_at'], 'form_title' => $r['form_title'],
        'detail' => 'Student Affairs · ' . formatDate($r['start_date']) . ' – ' . formatDate($r['end_date'])];
}
foreach ($admHistory as $r) {
    $allHistory[] = ['module' => 'administration', 'submitted_at' => $r['submitted_at'], 'form_title' => $r['form_title'],
        'detail' => 'Administration · ' . formatDate($r['start_date']) . ' – ' . formatDate($r['end_date'])];
}
usort($allHistory, fn($a, $b) => strtotime($b['submitted_at']) - strtotime($a['submitted_at']));

$navItems = [
    ['label' => 'Dashboard',      'href' => '/studentfeedback/student/index.php',          'key' => 'dashboard', 'icon' => 'home'],
    ['label' => 'My Sections',    'href' => '/studentfeedback/student/my_sections.php',     'key' => 'sections',  'icon' => 'grid'],
    ['label' => 'Student Affairs','href' => '/studentfeedback/student/sa_feedback.php',     'key' => 'sa',        'icon' => 'shield'],
    ['label' => 'Administration', 'href' => '/studentfeedback/student/adm_feedback.php',    'key' => 'adm',       'icon' => 'office'],
    ['label' => 'History',        'href' => '/studentfeedback/student/feedback_history.php','key' => 'history',   'icon' => 'history'],
    ['label' => 'Profile',        'href' => '/studentfeedback/student/profile.php',         'key' => 'profile',   'icon' => 'user'],
];
$initials = avatarInitials($user['name']);
?>
<!DOCTYPE html><html lang="en" class="h-full"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?> — SFMS</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{fontFamily:{inter:['Inter','sans-serif']}}}}</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/studentfeedback/assets/css/custom.css">
</head>
<body class="h-full bg-slate-50 font-inter">
<div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
<div class="flex h-screen overflow-hidden">

<!-- Sidebar -->
<aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
    <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
        <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center"><?= iconSvg('academic','w-5 h-5 text-white') ?></div>
        <div><p class="text-sm font-bold">SFMS Student</p><p class="text-[10px] text-cyan-100">Student Portal</p></div>
        <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-100 hover:text-white">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <nav class="flex-1 py-4 px-3 space-y-0.5 overflow-y-auto">
        <?php foreach ($navItems as $n): $a = $activeMenu === $n['key']; ?>
        <a href="<?= $n['href'] ?>" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm <?= $a ? 'bg-white/20 text-white font-semibold' : 'text-cyan-100 hover:bg-white/10 hover:text-white' ?>">
            <?= iconSvg($n['icon'],'w-4 h-4') ?> <?= e($n['label']) ?>
            <?php if ($a): ?><span class="ml-auto w-1.5 h-1.5 rounded-full bg-white"></span><?php endif ?>
        </a>
        <?php endforeach ?>
    </nav>
    <div class="border-t border-cyan-500 px-4 py-4">
        <div class="flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold"><?= e($initials) ?></div>
            <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-white truncate"><?= e($user['name']) ?></p>
                <p class="text-[10px] text-cyan-100 truncate">Student</p>
            </div>
            <a href="/studentfeedback/auth/logout.php" class="text-cyan-100 hover:text-red-300"><?= iconSvg('logout','w-4 h-4') ?></a>
        </div>
    </div>
</aside>

<!-- Main -->
<div class="flex-1 flex flex-col min-w-0 overflow-hidden">
<header class="bg-white border-b border-slate-200 px-4 lg:px-6 py-3.5 flex items-center gap-4 sticky top-0 z-20 shadow-sm">
    <button onclick="openSidebar()" class="lg:hidden text-slate-500">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
    </button>
    <h1 class="text-base font-semibold text-slate-800"><?= e($pageTitle) ?></h1>
    <div class="ml-auto">
        <a href="/studentfeedback/student/profile.php" class="flex items-center gap-2 px-3 py-1.5 rounded-xl hover:bg-slate-50">
            <div class="w-7 h-7 rounded-full bg-cyan-600 flex items-center justify-center text-xs font-bold text-white"><?= e($initials) ?></div>
            <span class="hidden md:block text-sm font-medium text-slate-700"><?= e($user['name']) ?></span>
        </a>
    </div>
</header>
<main class="flex-1 overflow-y-auto p-4 lg:p-6">

<div class="mb-6">
    <h2 class="text-xl font-bold text-slate-800">My Feedback History</h2>
    <p class="text-sm text-slate-500 mt-1">All feedback you have submitted across all modules</p>
</div>

<!-- Summary -->
<div class="grid grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-cyan-600 flex items-center justify-center flex-shrink-0"><?= iconSvg('academic','w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-cyan-700"><?= count($academicHistory) ?></p><p class="text-xs text-slate-500">Academic</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-purple-600 flex items-center justify-center flex-shrink-0"><?= iconSvg('shield','w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-purple-700"><?= count($saHistory) ?></p><p class="text-xs text-slate-500">Student Affairs</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-orange-600 flex items-center justify-center flex-shrink-0"><?= iconSvg('office','w-5 h-5 text-white') ?></div>
        <div><p class="text-xl font-bold text-orange-700"><?= count($admHistory) ?></p><p class="text-xs text-slate-500">Administration</p></div>
    </div>
</div>

<!-- Combined History Table -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100">
        <h3 class="text-base font-semibold text-slate-800">All Submissions</h3>
        <p class="text-xs text-slate-400 mt-0.5"><?= count($allHistory) ?> total submission<?= count($allHistory) !== 1 ? 's' : '' ?></p>
    </div>
    <?php if ($allHistory): ?>
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500">#</th>
                    <th class="text-left px-5 py-3 text-slate-500">Module</th>
                    <th class="text-left px-5 py-3 text-slate-500">Form / Course</th>
                    <th class="text-left px-5 py-3 text-slate-500">Details</th>
                    <th class="text-left px-5 py-3 text-slate-500">Submitted</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($allHistory as $i => $r): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3 text-sm text-slate-400"><?= $i + 1 ?></td>
                    <td class="px-5 py-3"><?= moduleBadge($r['module']) ?></td>
                    <td class="px-5 py-3 text-sm font-medium text-slate-800"><?= e($r['form_title']) ?></td>
                    <td class="px-5 py-3 text-xs text-slate-500"><?= $r['detail'] ?></td>
                    <td class="px-5 py-3 text-xs text-slate-400"><?= formatDate($r['submitted_at']) ?></td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="text-center py-16 text-slate-400">
        <?= iconSvg('history','w-10 h-10 mx-auto mb-3 opacity-40') ?>
        <p class="text-sm font-medium text-slate-600">No submissions yet.</p>
        <p class="text-xs mt-1">Complete your first feedback form and it will appear here.</p>
    </div>
    <?php endif ?>
</div>

</main></div></div>
<script>
function openSidebar()  { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full');    document.getElementById('overlay').classList.add('hidden'); }
</script>
</body></html>
