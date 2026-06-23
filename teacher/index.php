<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('teacher');

$pageTitle  = 'Teacher Dashboard';
$activeMenu = 'dashboard';
$user       = getCurrentUser();

// Get teacher record
$stmt = $conn->prepare("SELECT t.id FROM teachers t WHERE t.user_id=?");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc(); $stmt->close();
$teacherId = $teacher['id'] ?? 0;

$sectionCount   = $teacherId ? (int)$conn->query("SELECT COUNT(*) AS c FROM sections WHERE teacher_id=$teacherId")->fetch_assoc()['c'] : 0;
$formCount      = $teacherId ? (int)$conn->query("SELECT COUNT(*) AS c FROM feedback_forms ff JOIN sections s ON ff.section_id=s.id WHERE s.teacher_id=$teacherId AND ff.status='active'")->fetch_assoc()['c'] : 0;
$submissionCount = $teacherId ? (int)$conn->query("SELECT COUNT(*) AS c FROM feedback_submissions fs JOIN feedback_forms ff ON fs.feedback_form_id=ff.id JOIN sections s ON ff.section_id=s.id WHERE s.teacher_id=$teacherId")->fetch_assoc()['c'] : 0;

// My sections
$sections = [];
if ($teacherId) {
    $rs = $conn->query("SELECT s.*, c.course_name, c.course_code, (SELECT COUNT(*) FROM section_assignments sa WHERE sa.section_id=s.id) AS student_count, (SELECT COUNT(*) FROM feedback_forms ff WHERE ff.section_id=s.id AND ff.status='active') AS active_forms FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.teacher_id=$teacherId ORDER BY s.id DESC LIMIT 5");
    $sections = $rs->fetch_all(MYSQLI_ASSOC);
}

// Teacher header/sidebar will use the same includes but with teacher_ variables
// We reuse admin includes — just change the sidebar nav
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

<!-- Content -->
<div class="mb-6">
    <h2 class="text-2xl font-bold text-slate-800">Welcome, <?= e(explode(' ',$user['name'])[0]) ?> 👋</h2>
    <p class="text-sm text-slate-500 mt-1">Here's your teaching overview.</p>
</div>

<!-- Stats -->
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-cyan-600 flex items-center justify-center shadow"><?= iconSvg('grid','w-6 h-6 text-white') ?></div>
        <div><p class="text-2xl font-bold text-cyan-700"><?= $sectionCount ?></p><p class="text-xs text-slate-500">My Sections</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-emerald-600 flex items-center justify-center shadow"><?= iconSvg('document','w-6 h-6 text-white') ?></div>
        <div><p class="text-2xl font-bold text-emerald-700"><?= $formCount ?></p><p class="text-xs text-slate-500">Active Forms</p></div>
    </div>
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 flex items-center gap-4">
        <div class="w-12 h-12 rounded-xl bg-cyan-600 flex items-center justify-center shadow"><?= iconSvg('check','w-6 h-6 text-white') ?></div>
        <div><p class="text-2xl font-bold text-cyan-700"><?= $submissionCount ?></p><p class="text-xs text-slate-500">Total Submissions</p></div>
    </div>
</div>

<!-- My Sections Preview -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h3 class="text-base font-semibold text-slate-800">My Sections</h3>
        <a href="/studentfeedback/teacher/my_sections.php" class="text-xs text-cyan-600 hover:underline">View All →</a>
    </div>
    <?php if ($sections): ?>
    <div class="divide-y divide-slate-100">
    <?php foreach ($sections as $s): ?>
        <div class="px-6 py-4 flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-slate-800"><?= e($s['course_name']) ?></p>
                <p class="text-xs text-slate-400"><?= e($s['semester']) ?> · Sec <?= e($s['section']) ?> · <?= e($s['academic_year']) ?></p>
            </div>
            <div class="flex items-center gap-4 text-right">
                <div><p class="text-sm font-bold text-slate-700"><?= $s['student_count'] ?></p><p class="text-xs text-slate-400">Students</p></div>
                <div><p class="text-sm font-bold text-emerald-600"><?= $s['active_forms'] ?></p><p class="text-xs text-slate-400">Forms</p></div>
                <a href="/studentfeedback/teacher/feedback_results.php?section_id=<?= $s['id'] ?>" class="text-xs text-cyan-600 hover:underline">Results</a>
            </div>
        </div>
    <?php endforeach ?>
    </div>
    <?php else: ?>
    <div class="text-center py-12 text-slate-400"><?= iconSvg('grid','w-10 h-10 mx-auto mb-3 opacity-40') ?><p class="text-sm">No sections assigned yet.</p></div>
    <?php endif ?>
</div>

    </main>
</div>
</div>
<script>
function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('sidebar-overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('sidebar-overlay').classList.add('hidden'); }
</script>
</body>
</html>
