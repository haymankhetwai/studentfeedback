<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Prevent direct URL access — must come through index.php portal flow
if (!isset($_SESSION['entry_allowed']) || $_SESSION['selected_role'] !== 'student') {
    header('Location: /studentfeedbackucsh/index.php');
    exit;
}

requireRole('student');

$user = getCurrentUser();
$stmt = $conn->prepare("SELECT st.id FROM students st WHERE st.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
$studentId = $student['id'] ?? 0;

$pageTitle = $LANG['feedback_history_title'] ?? 'Feedback History';
$activeMenu = 'history';

// ─── Academic submissions ─────────────────────────────────────
$academicHistory = [];
if ($studentId) {
    $rs = $conn->prepare("
        SELECT fs.submitted_at,
       ff.title AS form_title,
       c.course_name,
       c.course_code,
       s.section,
       COALESCE(ay.year_name, s.academic_year) AS display_year,
       sm.semester_name AS display_semester,
       ff.start_date,
       ff.end_date
FROM feedback_submissions fs
JOIN feedback_forms ff ON fs.form_id = ff.id
JOIN sections s ON ff.section_id = s.id
JOIN courses c ON s.course_id = c.id
LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
LEFT JOIN semesters sm ON s.semester_id = sm.id
WHERE fs.student_id = ?
ORDER BY fs.submitted_at DESC
    ");
    $rs->bind_param('i', $studentId);
    $rs->execute();
    $academicHistory = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
    $rs->close();
}

// ─── Student Affairs submissions ──────────────────────────────
$saHistory = [];
if ($studentId) {
    $rs = $conn->prepare("
        SELECT fs.submitted_at, ff.title AS form_title, ff.start_date, ff.end_date
        FROM feedback_submissions fs
        JOIN feedback_forms ff ON fs.form_id=ff.id
        WHERE ff.module='student_affairs' AND fs.student_id=?
        ORDER BY fs.submitted_at DESC
    ");
    $rs->bind_param('i', $studentId);
    $rs->execute();
    $saHistory = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
    $rs->close();
}

// ─── Administration submissions ───────────────────────────────
$admHistory = [];
if ($studentId) {
    $rs = $conn->prepare("
        SELECT fs.submitted_at, ff.title AS form_title, ff.start_date, ff.end_date
        FROM feedback_submissions fs
        JOIN feedback_forms ff ON fs.form_id=ff.id
        WHERE ff.module='administration' AND fs.student_id=?
        ORDER BY fs.submitted_at DESC
    ");
    $rs->bind_param('i', $studentId);
    $rs->execute();
    $admHistory = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
    $rs->close();
}

// Merge & sort all by date for the combined view
$allHistory = [];
foreach ($academicHistory as $r) {
    $allHistory[] = [
        'module' => 'academic',
        'submitted_at' => $r['submitted_at'],
        'form_title' => $r['form_title'],
        'detail' => e($r['course_name']) . ' (' . e($r['course_code']) . ') — Sec ' . e($r['section']) . ' · ' . e($r['display_year']) . ' ' . e(semesterToRoman($r['display_semester'])) . ' · ' . formatDateTime($r['start_date']) . ' – ' . formatDateTime($r['end_date'])
    ];
}
foreach ($saHistory as $r) {
    $allHistory[] = [
        'module' => 'student_affairs',
        'submitted_at' => $r['submitted_at'],
        'form_title' => $r['form_title'],
        'detail' => 'Student Affairs · ' . formatDateTime($r['start_date']) . ' – ' . formatDateTime($r['end_date'])
    ];
}
foreach ($admHistory as $r) {
    $allHistory[] = [
        'module' => 'administration',
        'submitted_at' => $r['submitted_at'],
        'form_title' => $r['form_title'],
        'detail' => 'Administration · ' . formatDateTime($r['start_date']) . ' – ' . formatDateTime($r['end_date'])
    ];
}
usort($allHistory, fn($a, $b) => strtotime($b['submitted_at']) - strtotime($a['submitted_at']));

$navItems = [
    ['label' => $LANG['nav_dashboard'] ?? 'Dashboard', 'href' => '/studentfeedbackucsh/student/dashboard.php', 'key' => 'dashboard', 'icon' => 'home', 'iconColor' => 'text-yellow-300'],
    ['label' => $LANG['nav_my_sections'] ?? 'My Sections', 'href' => '/studentfeedbackucsh/student/my_sections.php', 'key' => 'sections', 'icon' => 'grid', 'iconColor' => 'text-blue-300'],
    ['label' => $LANG['nav_student_affairs'] ?? 'Student Affairs', 'href' => '/studentfeedbackucsh/student/sa_feedback.php', 'key' => 'sa', 'icon' => 'shield', 'iconColor' => 'text-purple-300'],
    ['label' => $LANG['nav_administration'] ?? 'Administration', 'href' => '/studentfeedbackucsh/student/adm_feedback.php', 'key' => 'adm', 'icon' => 'office', 'iconColor' => 'text-orange-300'],
    ['label' => $LANG['nav_history'] ?? 'History', 'href' => '/studentfeedbackucsh/student/feedback_history.php', 'key' => 'history', 'icon' => 'history', 'iconColor' => 'text-teal-300'],
    ['label' => $LANG['nav_profile'] ?? 'Profile', 'href' => '/studentfeedbackucsh/student/profile.php', 'key' => 'profile', 'icon' => 'user', 'iconColor' => 'text-rose-300'],
];
$initials = avatarInitials($user['name']);
?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { inter: ['Inter', 'sans-serif'] } } } }</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/studentfeedbackucsh/assets/css/custom.css">
</head>

<body class="h-full bg-slate-50 font-inter <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
    <div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
    <div class="flex h-screen overflow-hidden">

        <!-- Sidebar -->
        <aside id="sidebar"
            class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
            <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
                <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center">
                    <?= iconSvg('academic', 'w-5 h-5 text-white') ?>
                </div>
                <div>
                    <p class="text-sm font-bold">
                        <?= $LANG['student_portal'] ?? 'SFMS Student' ?>
                    </p>
                    <p class="text-[10px] text-cyan-100">
                        <?= $LANG['student_portal_sub'] ?? 'Student Portal' ?>
                    </p>
                </div>
                <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-100 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <nav class="flex-1 py-4 px-3 space-y-0.5 overflow-y-auto">
                <?php foreach ($navItems as $n):
                    $a = $activeMenu === $n['key']; ?>
                    <a href="<?= $n['href'] ?>"
                        class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm <?= $a ? 'bg-white/20 text-white font-semibold' : 'text-cyan-100 hover:bg-white/10 hover:text-white' ?>">
                        <?= iconSvg($n['icon'], 'w-5 h-5 ' . ($n['iconColor'] ?? 'text-white/80')) ?>     <?= e($n['label']) ?>
                        <?php if ($a): ?><span class="ml-auto w-1.5 h-1.5 rounded-full bg-white"></span><?php endif ?>
                    </a>
                <?php endforeach ?>
            </nav>
            <a href="/studentfeedbackucsh/auth/logout.php" title="<?= $LANG['logout'] ?? 'Logout' ?>"
                class="block border-t border-white/15 bg-red-500/80 text-gray-50 hover:text-gray-200 transition-colors px-4 py-4 cursor-pointer">
                <div class="flex items-center justify-center gap-3">

                    <div class="min-w-0 ">
                        <p class="text-xl h-8"><?= $LANG['logout'] ?? 'Logout' ?></p>
                    </div>
                    <?= iconSvg('logout', 'w-6 h-6') ?>
                </div>
            </a>
        </aside>

        <!-- Main -->
        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <?php include '../includes/student_header.php'; ?>
            <main class="flex-1 overflow-y-auto p-4 lg:p-6">

                <div class="mb-6">
                    <h2 class="text-xl font-bold text-slate-800">
                        <?= $LANG['my_feedback_history'] ?? 'My Feedback History' ?>
                    </h2>
                    <p class="text-sm text-slate-500 mt-1">
                        <?= $LANG['feedback_history_desc'] ?? 'All feedback you have submitted across all modules' ?>
                    </p>
                </div>

                <!-- Summary -->
                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center flex-shrink-0">
                            <?= iconSvg('academic', 'w-5 h-5 text-white') ?>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-cyan-700"><?= count($academicHistory) ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['academic_feedback_section'] ?? 'Academic' ?>
                            </p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-indigo-600 flex items-center justify-center flex-shrink-0">
                            <?= iconSvg('shield', 'w-5 h-5 text-white') ?>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-purple-700"><?= count($saHistory) ?></p>
                            <p class="text-xs text-slate-500">
                                <?= $LANG['student_affairs_section'] ?? 'Student Affairs' ?>
                            </p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-orange-600 flex items-center justify-center flex-shrink-0">
                            <?= iconSvg('office', 'w-5 h-5 text-white') ?>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-orange-700"><?= count($admHistory) ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['administration_section'] ?? 'Administration' ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Combined History Table -->
                <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                    <div class="px-6 py-4 border-b border-slate-100">
                        <h3 class="text-base font-semibold text-slate-800">
                            <?= $LANG['all_submissions'] ?? 'All Submissions' ?>
                        </h3>
                        <p class="text-xs text-slate-400 mt-0.5"><?= count($allHistory) ?>
                            <?= count($allHistory) !== 1 ? ($LANG['total_submissions'] ?? 'total submissions') : ($LANG['total_submission'] ?? 'total submission') ?>
                        </p>
                    </div>
                    <?php if ($allHistory): ?>
                        <div class="overflow-x-auto">
                            <table>
                                <thead class="bg-slate-200 border-b border-slate-200">
                                    <tr>
                                        <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">#</th>
                                        <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                                            <?= $LANG['module_label'] ?? 'Module' ?>
                                        </th>
                                        <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                                            <?= $LANG['form_course_label'] ?? 'Form / Course' ?>
                                        </th>
                                        <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                                            <?= $LANG['details_label'] ?? 'Details' ?>
                                        </th>
                                        <th class="text-left px-7 py-3 text-slate-500 text-sm font-semibold">
                                            <?= $LANG['submitted_label'] ?? 'Submitted' ?>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <?php foreach ($allHistory as $i => $r): ?>
                                        <tr class="hover:bg-slate-50 transition-colors">
                                            <td class="px-5 py-3 text-sm text-slate-400"><?= $i + 1 ?></td>
                                            <td class="px-5 py-3"><?= moduleBadge($r['module']) ?></td>
                                            <td class="px-5 py-3 text-sm font-medium text-slate-800"><?= e($r['form_title']) ?>
                                            </td>
                                            <td class="px-5 py-3 text-xs text-slate-500"><?= $r['detail'] ?></td>
                                            <td class="px-5 py-3 text-xs text-slate-400"><?= formatDateTime($r['submitted_at']) ?>
                                            </td>
                                        </tr>
                                    <?php endforeach ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-16 text-slate-400">
                            <?= iconSvg('history', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm font-medium text-slate-600">
                                <?= $LANG['no_submissions_yet'] ?? 'No submissions yet.' ?>
                            </p>
                            <p class="text-xs mt-1">
                                <?= $LANG['complete_first_feedback'] ?? 'Complete your first feedback form and it will appear here.' ?>
                            </p>
                        </div>
                    <?php endif ?>
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
