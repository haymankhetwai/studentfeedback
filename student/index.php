<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('student');

$user = getCurrentUser();
$stmt = $conn->prepare("SELECT st.id, m.major_name FROM students st JOIN majors m ON st.major_id=m.id WHERE st.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
$studentId = $student['id'] ?? 0;

$pageTitle = 'Student Dashboard';
$activeMenu = 'dashboard';
$today = date('Y-m-d');

// ─── Stats ────────────────────────────────────────────────────
$sectionCount = $studentId ? (int) $conn->query("SELECT COUNT(*) AS c FROM section_assignments WHERE student_id=$studentId")->fetch_assoc()['c'] : 0;
$submittedCount = 0;
$pendingCount = 0;
$saPendingCount = 0;
$admPendingCount = 0;

if ($studentId) {
    // Academic pending
    $pAcad = $conn->query("SELECT COUNT(*) AS c FROM feedback_forms ff JOIN section_assignments sa ON ff.section_id=sa.section_id WHERE sa.student_id=$studentId GROUP BY ff.id AND ff.status='active' AND ff.start_date<='$today' AND ff.end_date>='$today' AND ff.id NOT IN (SELECT feedback_form_id FROM feedback_submissions WHERE student_id=$studentId)");
    $pendingCount = $pAcad ? (int) $pAcad->num_rows : 0;

    // Submitted count (all 3 modules)
    $sAcad = (int) $conn->query("SELECT COUNT(*) AS c FROM feedback_submissions WHERE student_id=$studentId")->fetch_assoc()['c'];
    $sSA = (int) $conn->query("SELECT COUNT(*) AS c FROM sa_feedback_submissions WHERE student_id=$studentId")->fetch_assoc()['c'];
    $sAdm = (int) $conn->query("SELECT COUNT(*) AS c FROM adm_feedback_submissions WHERE student_id=$studentId")->fetch_assoc()['c'];
    $submittedCount = $sAcad + $sSA + $sAdm;

    // SA pending
    $pSA = $conn->query("SELECT COUNT(*) AS c FROM sa_feedback_forms WHERE status='active' AND start_date<='$today' AND end_date>='$today' AND id NOT IN (SELECT form_id FROM sa_feedback_submissions WHERE student_id=$studentId)");
    $saPendingCount = (int) $pSA->fetch_assoc()['c'];

    // Adm pending
    $pAdm = $conn->query("SELECT COUNT(*) AS c FROM adm_feedback_forms WHERE status='active' AND start_date<='$today' AND end_date>='$today' AND id NOT IN (SELECT form_id FROM adm_feedback_submissions WHERE student_id=$studentId)");
    $admPendingCount = (int) $pAdm->fetch_assoc()['c'];
}

// ─── Academic Pending Forms ───────────────────────────────────
$pendingForms = [];
if ($studentId) {
    $rs = $conn->query("SELECT ff.id AS form_id, ff.title, ff.end_date, c.course_name, s.section, u.name AS teacher_name FROM feedback_forms ff JOIN sections s ON ff.section_id=s.id JOIN courses c ON s.course_id=c.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id JOIN section_assignments sa ON sa.section_id=s.id WHERE sa.student_id=$studentId AND ff.status='active' AND ff.start_date<='$today' AND ff.end_date>='$today' AND ff.id NOT IN (SELECT feedback_form_id FROM feedback_submissions WHERE student_id=$studentId) ORDER BY ff.end_date ASC LIMIT 4");
    $pendingForms = $rs->fetch_all(MYSQLI_ASSOC);
}

// ─── SA Pending Forms (ပြင်ဆင်ချက်- ID များကို form_id ဟု Alias ပေးထားပါသည်) ───
$saPendingForms = [];
if ($studentId) {
    $rs = $conn->query("SELECT id AS form_id, title, end_date FROM sa_feedback_forms WHERE status='active' AND start_date<='$today' AND end_date>='$today' AND id NOT IN (SELECT form_id FROM sa_feedback_submissions WHERE student_id=$studentId) ORDER BY end_date ASC LIMIT 3");
    $saPendingForms = $rs->fetch_all(MYSQLI_ASSOC);
}

// ─── Adm Pending Forms (ပြင်ဆင်ချက်- ID များကို form_id ဟု Alias ပေးထားပါသည်) ───
$admPendingForms = [];
if ($studentId) {
    $rs = $conn->query("SELECT id AS form_id, title, end_date FROM adm_feedback_forms WHERE status='active' AND start_date<='$today' AND end_date>='$today' AND id NOT IN (SELECT form_id FROM adm_feedback_submissions WHERE student_id=$studentId) ORDER BY end_date ASC LIMIT 3");
    $admPendingForms = $rs->fetch_all(MYSQLI_ASSOC);
}

$navItems = [
    ['label' => 'Dashboard', 'href' => '/studentfeedback/student/index.php', 'key' => 'dashboard', 'icon' => 'home'],
    ['label' => 'My Sections', 'href' => '/studentfeedback/student/my_sections.php', 'key' => 'sections', 'icon' => 'grid'],
    ['label' => 'Student Affairs', 'href' => '/studentfeedback/student/sa_feedback.php', 'key' => 'sa', 'icon' => 'shield'],
    ['label' => 'Administration', 'href' => '/studentfeedback/student/adm_feedback.php', 'key' => 'adm', 'icon' => 'office'],
    ['label' => 'History', 'href' => '/studentfeedback/student/feedback_history.php', 'key' => 'history', 'icon' => 'history'],
    ['label' => 'Profile', 'href' => '/studentfeedback/student/profile.php', 'key' => 'profile', 'icon' => 'user'],
];
$initials = avatarInitials($user['name']);
?>
<!DOCTYPE html>
<html lang="en" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { inter: ['Inter', 'sans-serif'] } } } }</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/studentfeedback/assets/css/custom.css">
</head>

<body class="h-full bg-slate-50 font-inter">
    <div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
    <div class="flex h-screen overflow-hidden">

        <aside id="sidebar"
            class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
            <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
                <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center font-bold text-white">S
                </div>
                <div>
                    <p class="text-sm font-bold">SFMS Student</p>
                    <p class="text-[10px] text-cyan-100">Student Portal</p>
                </div>
                <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-200">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 py-4 px-3 space-y-0.5 overflow-y-auto scrollbar-thin">
                <?php foreach ($navItems as $n):
                    $a = $activeMenu === $n['key']; ?>
                    <a href="<?= $n['href'] ?>"
                        class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm transition-all duration-150 <?= $a ? 'bg-white/20 text-white font-semibold' : 'text-cyan-100 hover:bg-white/10 hover:text-white' ?>">
                        <?= iconSvg($n['icon'], 'w-4 h-4 flex-shrink-0') ?>
                        <?= e($n['label']) ?>
                        <?php if ($a): ?><span class="ml-auto w-1.5 h-1.5 rounded-full bg-white"></span><?php endif ?>
                    </a>
                <?php endforeach ?>
            </nav>

            <div class="border-t border-cyan-500 px-4 py-4">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold">
                        <?= e($initials) ?></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-white truncate"><?= e($user['name']) ?></p>
                        <p class="text-[10px] text-cyan-100 truncate"><?= $student['major_name'] ?? 'Student' ?>
                        </p>
                    </div>
                    <a href="/studentfeedback/auth/logout.php" class="text-cyan-200 hover:text-red-300">
                        <?= iconSvg('logout', 'w-4 h-4') ?>
                    </a>
                </div>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <header
                class="bg-white border-b border-slate-200 px-4 lg:px-6 py-3.5 flex items-center gap-4 sticky top-0 z-20 shadow-sm">
                <button onclick="openSidebar()" class="lg:hidden text-slate-500"><svg xmlns="http://www.w3.org/2000/svg"
                        fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg></button>
                <h1 class="text-base font-semibold text-slate-800"><?= e($pageTitle) ?></h1>
            </header>
            <main class="flex-1 overflow-y-auto p-4 lg:p-6">

                <?php renderFlash() ?>

                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-slate-800">Welcome, <?= e(explode(' ', $user['name'])[0]) ?> 👋
                    </h2>
                    <p class="text-sm text-slate-500 mt-1">Here's your feedback overview across all modules.</p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-8">
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div>
                            <p class="text-xl font-bold text-cyan-700"><?= $sectionCount ?></p>
                            <p class="text-xs text-slate-500">Enrolled Sections</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div>
                            <p class="text-xl font-bold text-amber-600"><?= $pendingCount ?></p>
                            <p class="text-xs text-slate-500">Academic Pending</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div>
                            <p class="text-xl font-bold text-purple-700"><?= $saPendingCount ?></p>
                            <p class="text-xs text-slate-500">SA Pending</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div>
                            <p class="text-xl font-bold text-orange-700"><?= $admPendingCount ?></p>
                            <p class="text-xs text-slate-500">Adm Pending</p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div>
                            <p class="text-xl font-bold text-green-700"><?= $submittedCount ?></p>
                            <p class="text-xs text-slate-500">Total Submitted</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">

                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-cyan-50">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-cyan-800">Academic Feedback</h3>
                            </div>
                            <a href="/studentfeedback/student/my_sections.php"
                                class="text-xs text-cyan-600 hover:underline">View →</a>
                        </div>
                        <?php if ($pendingForms): ?>
                            <div class="divide-y divide-slate-100">
                                <?php foreach ($pendingForms as $f): ?>
                                    <div class="px-5 py-3.5 flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-800 truncate"><?= e($f['title']) ?></p>
                                            <p class="text-[11px] text-slate-400 truncate"><?= e($f['course_name']) ?> — Sec
                                                <?= e($f['section']) ?></p>
                                        </div>
                                        <a href="/studentfeedback/student/feedback_form.php?form_id=<?= $f['form_id'] ?>"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-lg flex-shrink-0">Fill</a>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-slate-400">
                                <p class="text-xs">All caught up!</p>
                            </div>
                        <?php endif ?>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-purple-50">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-purple-800">Student Affairs</h3>
                            </div>
                            <a href="/studentfeedback/student/sa_feedback.php"
                                class="text-xs text-purple-600 hover:underline">View →</a>
                        </div>
                        <?php if ($saPendingForms): ?>
                            <div class="divide-y divide-slate-100">
                                <?php foreach ($saPendingForms as $f): ?>
                                    <div class="px-5 py-3.5 flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-800 truncate"><?= e($f['title']) ?></p>
                                            <p class="text-[11px] text-slate-400">Due: <?= formatDate($f['end_date']) ?></p>
                                        </div>
                                        <a href="/studentfeedback/student/sa_feedback_form.php?form_id=<?= $f['form_id'] ?>"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white bg-purple-600 hover:bg-purple-700 rounded-lg flex-shrink-0">Fill</a>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-slate-400">
                                <p class="text-xs">No pending SA forms.</p>
                            </div>
                        <?php endif ?>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-orange-50">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-orange-800">Administration</h3>
                            </div>
                            <a href="/studentfeedback/student/adm_feedback.php"
                                class="text-xs text-orange-600 hover:underline">View →</a>
                        </div>
                        <?php if ($admPendingForms): ?>
                            <div class="divide-y divide-slate-100">
                                <?php foreach ($admPendingForms as $f): ?>
                                    <div class="px-5 py-3.5 flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-800 truncate"><?= e($f['title']) ?></p>
                                            <p class="text-[11px] text-slate-400">Due: <?= formatDate($f['end_date']) ?></p>
                                        </div>
                                        <a href="/studentfeedback/student/adm_feedback_form.php?form_id=<?= $f['form_id'] ?>"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white bg-orange-600 hover:bg-orange-700 rounded-lg flex-shrink-0">Fill</a>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-slate-400">
                                <p class="text-xs">No pending Adm forms.</p>
                            </div>
                        <?php endif ?>
                    </div>

                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <a href="/studentfeedback/student/my_sections.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md transition-all hover:-translate-y-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">My Sections</p>
                            <p class="text-xs text-slate-500">Academic feedback</p>
                        </div>
                    </a>
                    <a href="/studentfeedback/student/sa_feedback.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md transition-all hover:-translate-y-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">Student Affairs</p>
                            <p class="text-xs text-slate-500">SA feedback forms</p>
                        </div>
                    </a>
                    <a href="/studentfeedback/student/adm_feedback.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md transition-all hover:-translate-y-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">Administration</p>
                            <p class="text-xs text-slate-500">Admin feedback forms</p>
                        </div>
                    </a>
                    <a href="/studentfeedback/student/feedback_history.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md transition-all hover:-translate-y-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">History</p>
                            <p class="text-xs text-slate-500">All submissions</p>
                        </div>
                    </a>
                </div>

            </main>
        </div>
    </div>
    <script>
        function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
        function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('overlay').classList.add('hidden'); }
    </script>
</body>

</html>