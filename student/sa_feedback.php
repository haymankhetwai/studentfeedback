<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('student');

$user      = getCurrentUser();
$stmt      = $conn->prepare("SELECT st.id, m.major_name FROM students st JOIN majors m ON st.major_id=m.id WHERE st.user_id=?");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$student   = $stmt->get_result()->fetch_assoc(); $stmt->close();
$studentId = $student['id'] ?? 0;

$pageTitle  = 'Student Affairs Feedback';
$activeMenu = 'sa';
$today      = date('Y-m-d');

// Load all active SA forms and submission status
$forms = [];
if ($studentId) {
    // Check if table exists first
    $tableCheck = $conn->query("SHOW TABLES LIKE 'sa_feedback_forms'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $rs = $conn->prepare("
            SELECT f.*, (SELECT COUNT(*) FROM sa_feedback_submissions s WHERE s.form_id=f.id AND s.student_id=?) AS submitted
            FROM sa_feedback_forms f
            WHERE f.status='active'
            ORDER BY f.end_date ASC, f.id DESC
        ");
        $rs->bind_param('i', $studentId); $rs->execute();
        $forms = $rs->get_result()->fetch_all(MYSQLI_ASSOC); $rs->close();
    }
}

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
<aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
    <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
        <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center"><?= iconSvg('academic','w-5 h-5 text-white') ?></div>
        <div><p class="text-sm font-bold">SFMS Student</p><p class="text-[10px] text-cyan-100">Student Portal</p></div>
        <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-100 hover:text-white"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button>
    </div>
    <nav class="flex-1 py-4 px-3 space-y-0.5">
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
            <div class="flex-1 min-w-0"><p class="text-xs font-semibold text-white truncate"><?= e($user['name']) ?></p><p class="text-[10px] text-cyan-100"><?= $student['major_name'] ?? '' ?></p></div>
            <a href="/studentfeedback/auth/logout.php" class="text-cyan-200 hover:text-red-300">
                        <?= iconSvg('logout', 'w-4 h-4') ?>
                    </a>
        </div>
    </div>
</aside>
<div class="flex-1 flex flex-col min-w-0 overflow-hidden">
<header class="bg-white border-b border-slate-200 px-4 lg:px-6 py-3.5 flex items-center gap-4 sticky top-0 z-20 shadow-sm">
    <button onclick="openSidebar()" class="lg:hidden text-slate-500"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg></button>
    <h1 class="text-base font-semibold text-slate-800"><?= e($pageTitle) ?></h1>
</header>
<main class="flex-1 overflow-y-auto p-4 lg:p-6">

<div class="mb-6">
    <div class="flex items-center gap-2 mb-1"><?= iconSvg('shield','w-5 h-5 text-purple-600') ?>
        <h2 class="text-xl font-bold text-slate-800">Student Affairs Feedback</h2></div>
    <p class="text-sm text-slate-500 ml-7">Rate and review the Student Affairs office services</p>
</div>
<?php renderFlash() ?>

<?php if ($forms): ?>
<div class="space-y-4">
<?php foreach ($forms as $f):
    $isInRange  = $f['start_date'] <= $today && $f['end_date'] >= $today;
    $submitted  = (bool)$f['submitted'];
    $canSubmit  = $isInRange && !$submitted;
    $expired    = $f['end_date'] < $today;
?>
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-6 py-5 flex items-center justify-between gap-4">
        <div class="flex-1 min-w-0">
            <div class="flex items-center gap-2 mb-1"><?= iconSvg('shield','w-4 h-4 text-purple-500') ?>
                <p class="text-sm font-semibold text-slate-800"><?= e($f['title']) ?></p></div>
            <?php if ($f['description']): ?><p class="text-xs text-slate-500 ml-6 mb-1"><?= e($f['description']) ?></p><?php endif ?>
            <p class="text-xs text-slate-400 ml-6"><?= formatDate($f['start_date']) ?> — <?= formatDate($f['end_date']) ?></p>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0">
            <?php if ($submitted): ?>
                <span class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-green-700 bg-green-50 border border-green-200 rounded-xl"><?= iconSvg('check','w-3.5 h-3.5') ?> Submitted</span>
            <?php elseif ($canSubmit): ?>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Due: <?= formatDate($f['end_date']) ?></span>
                <a href="sa_feedback_form.php?form_id=<?= $f['id'] ?>" class="inline-flex items-center gap-1 px-4 py-2 text-xs font-semibold text-white bg-purple-600 hover:bg-purple-700 rounded-xl transition-all hover:-translate-y-0.5">
                    <?= iconSvg('clipboard','w-3.5 h-3.5') ?> Fill Form
                </a>
            <?php elseif ($expired): ?>
                <span class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-slate-500 bg-slate-100 rounded-xl">Expired</span>
            <?php else: ?>
                <span class="text-xs text-slate-400">Starts <?= formatDate($f['start_date']) ?></span>
            <?php endif ?>
        </div>
    </div>
</div>
<?php endforeach ?>
</div>
<?php else: ?>
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 text-center py-16 text-slate-400">
    <?= iconSvg('shield','w-10 h-10 mx-auto mb-3 opacity-40') ?>
    <p class="text-sm font-medium text-slate-600">No Student Affairs forms available right now.</p>
    <p class="text-xs mt-1">Check back later or contact your administrator.</p>
</div>
<?php endif ?>

</main></div></div>
<script>
function openSidebar()  { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full');    document.getElementById('overlay').classList.add('hidden'); }
</script>
</body></html>
