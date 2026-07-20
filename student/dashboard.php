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

updateAllFeedbackStatuses($conn);

$user = getCurrentUser();
$stmt = $conn->prepare("SELECT st.id FROM students st WHERE st.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
$studentId = $student['id'] ?? 0;
$studentYearIds = getStudentAcademicYearIds($conn, $studentId);

$pageTitle = 'Student Dashboard';
$activeMenu = 'dashboard';
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');

// ─── Stats ────────────────────────────────────────────────────
$sectionCount = $studentId ? (int) $conn->query("SELECT COUNT(*) AS c FROM section_assignments WHERE student_id=$studentId")->fetch_assoc()['c'] : 0;

$acadAvailableCount = 0;
$acadPendingCount   = 0;
$acadCompletedCount = 0;
$saAvailableCount   = 0;
$saPendingCount     = 0;
$saCompletedCount   = 0;
$admAvailableCount  = 0;
$admPendingCount    = 0;
$admCompletedCount  = 0;
$totalCompletedCount = 0;

if ($studentId) {
    // ── Academic: load all forms the student can access (same as my_sections.php) ──
    $acadFormsStmt = $conn->prepare(
        "SELECT ff.id, ff.start_date, ff.end_date,
                (SELECT COUNT(*) FROM feedback_submissions fs WHERE fs.form_id=ff.id AND fs.student_id=?) AS submitted
         FROM feedback_forms ff
         JOIN section_assignments sa ON ff.section_id = sa.section_id
         WHERE sa.student_id = ? AND ff.module = 'academic'"
    );
    $acadFormsStmt->bind_param('ii', $studentId, $studentId);
    $acadFormsStmt->execute();
    $acadRows = $acadFormsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $acadFormsStmt->close();
    foreach ($acadRows as $r) {
        $acadAvailableCount++;
        if ((int)$r['submitted'] > 0) {
            $acadCompletedCount++;
        } else {
            $acadPendingCount++;
        }
    }

    // ── Year filter for SA and Admin (same as sa_feedback.php / adm_feedback.php) ──
    $yrPH = '';
    $yrBT = '';
    if (!empty($studentYearIds)) {
        $yrPH = implode(',', array_fill(0, count($studentYearIds), '?'));
        $yrBT = str_repeat('i', count($studentYearIds));
    }

    // ── SA: load all forms (same query as sa_feedback.php — no date filter) ──
    if (!empty($yrPH)) {
        $saStmt = $conn->prepare(
            "SELECT f.id, f.start_date, f.end_date,
                    (SELECT COUNT(*) FROM feedback_submissions s WHERE s.form_id=f.id AND s.student_id=?) AS submitted
             FROM feedback_forms f
             WHERE f.module='student_affairs' AND f.academic_year_id IN ($yrPH)"
        );
        $saStmt->bind_param('i' . $yrBT, ...array_merge([$studentId], $studentYearIds));
        $saStmt->execute();
        $saRows = $saStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $saStmt->close();
        foreach ($saRows as $r) {
            $saAvailableCount++;
            if ((int)$r['submitted'] > 0) {
                $saCompletedCount++;
            } else {
                $saPendingCount++;
            }
        }
    }

    // ── Admin: load all forms (same query as adm_feedback.php — no date filter) ──
    if (!empty($yrPH)) {
        $admStmt = $conn->prepare(
            "SELECT f.id, f.start_date, f.end_date,
                    (SELECT COUNT(*) FROM feedback_submissions s WHERE s.form_id=f.id AND s.student_id=?) AS submitted
             FROM feedback_forms f
             WHERE f.module='administration' AND f.academic_year_id IN ($yrPH)"
        );
        $admStmt->bind_param('i' . $yrBT, ...array_merge([$studentId], $studentYearIds));
        $admStmt->execute();
        $admRows = $admStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $admStmt->close();
        foreach ($admRows as $r) {
            $admAvailableCount++;
            if ((int)$r['submitted'] > 0) {
                $admCompletedCount++;
            } else {
                $admPendingCount++;
            }
        }
    }

    $totalCompletedCount = $acadCompletedCount + $saCompletedCount + $admCompletedCount;
}

// ─── Academic Pending Forms (active, not submitted — for card listing) ─────────
$pendingForms = [];
if ($studentId) {
    $rs = $conn->query("SELECT ff.id AS form_id, ff.title, ff.end_date, c.course_name, s.section, u.name AS teacher_name, COALESCE(ay.year_name, s.academic_year) AS display_year, sm.semester_name AS display_semester FROM feedback_forms ff JOIN sections s ON ff.section_id=s.id JOIN courses c ON s.course_id=c.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id JOIN section_assignments sa ON sa.section_id=s.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id WHERE sa.student_id=$studentId AND ff.module='academic' AND ff.id NOT IN (SELECT form_id FROM feedback_submissions WHERE student_id=$studentId) ORDER BY ff.end_date ASC LIMIT 4");
    $pendingForms = $rs->fetch_all(MYSQLI_ASSOC);
}

// ─── SA Pending Forms (active, not submitted — same eligibility as sa_feedback.php) ───
$saPendingForms = [];
if ($studentId && !empty($studentYearIds)) {
    $yrList = implode(',', $studentYearIds);
    $rs = $conn->query("SELECT id AS form_id, title, end_date FROM feedback_forms WHERE module='student_affairs' AND academic_year_id IN ($yrList) AND id NOT IN (SELECT form_id FROM feedback_submissions WHERE student_id=$studentId) ORDER BY end_date ASC LIMIT 3");
    $saPendingForms = $rs->fetch_all(MYSQLI_ASSOC);
}

// ─── Admin Pending Forms (active, not submitted — same eligibility as adm_feedback.php) ───
$admPendingForms = [];
if ($studentId && !empty($studentYearIds)) {
    $yrList = implode(',', $studentYearIds);
    $rs = $conn->query("SELECT id AS form_id, title, end_date FROM feedback_forms WHERE module='administration' AND academic_year_id IN ($yrList) AND id NOT IN (SELECT form_id FROM feedback_submissions WHERE student_id=$studentId) ORDER BY end_date ASC LIMIT 3");
    $admPendingForms = $rs->fetch_all(MYSQLI_ASSOC);
}

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

<body
    class="h-full bg-gradient-to-br from-slate-50 to-cyan-50/30 font-inter <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
    <div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
    <div class="flex h-screen overflow-hidden">

        <aside id="sidebar"
            class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
            <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
                <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center font-bold text-white">S
                </div>
                <div>
                    <p class="text-sm font-bold"><?= $LANG['student_portal'] ?? 'SFMS Student' ?></p>
                    <p class="text-[10px] text-cyan-100"><?= $LANG['student_portal_sub'] ?? 'Student Portal' ?></p>
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
                        <?= iconSvg($n['icon'], 'w-5 h-5 flex-shrink-0 ' . ($n['iconColor'] ?? 'text-white/80')) ?>
                        <?= e($n['label']) ?>
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

        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <?php include '../includes/student_header.php'; ?>
            <main class="flex-1 overflow-y-auto p-4 lg:p-6">

                <?php renderFlash() ?>

                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-slate-800"><?= $LANG['student_welcome'] ?? 'Welcome' ?>,
                        <?= e($user['name']) ?> 👋
                    </h2>
                    <p class="text-sm text-slate-500 mt-1">
                        <?= $LANG['student_overview'] ?? "Here's your feedback overview across all modules." ?>
                    </p>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-5 gap-4 mb-8">
                    <div
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md transition-all">
                        <div>
                            <p class="text-xl font-bold text-cyan-700"><?= $sectionCount ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['enrolled_sections'] ?? 'Enrolled Sections' ?>
                            </p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div>
                            <p class="text-xl font-bold text-amber-600"><?= $acadPendingCount ?> <span class="text-xs font-normal text-slate-400">/ <?= $acadAvailableCount ?></span></p>
                            <p class="text-xs text-slate-500"><?= $LANG['academic_pending'] ?? 'Academic Pending' ?></p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div>
                            <p class="text-xl font-bold text-purple-700"><?= $saPendingCount ?> <span class="text-xs font-normal text-slate-400">/ <?= $saAvailableCount ?></span></p>
                            <p class="text-xs text-slate-500"><?= $LANG['sa_pending'] ?? 'SA Pending' ?></p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div>
                            <p class="text-xl font-bold text-orange-700"><?= $admPendingCount ?> <span class="text-xs font-normal text-slate-400">/ <?= $admAvailableCount ?></span></p>
                            <p class="text-xs text-slate-500"><?= $LANG['adm_pending'] ?? 'Adm Pending' ?></p>
                        </div>
                    </div>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3">
                        <div>
                            <p class="text-xl font-bold text-green-700"><?= $totalCompletedCount ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['total_completed'] ?? 'Total Completed' ?></p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">

                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-cyan-50">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-cyan-800">
                                    <?= $LANG['academic_feedback_section'] ?? 'Academic Feedback' ?>
                                </h3>
                                <span class="text-[11px] text-cyan-600 font-medium">(<?= $acadPendingCount ?> <?= $LANG['pending'] ?? 'pending' ?>, <?= $acadCompletedCount ?> <?= $LANG['completed'] ?? 'completed' ?>)</span>
                            </div>
                            <a href="/studentfeedbackucsh/student/my_sections.php"
                                class="text-xs text-cyan-600 hover:underline"><?= $LANG['view_all'] ?? 'View' ?> →</a>
                        </div>
                        <?php if ($pendingForms): ?>
                            <div class="divide-y divide-slate-100">
                                <?php foreach ($pendingForms as $f): ?>
                                    <div class="px-5 py-3.5 flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-800 truncate"><?= e($f['title']) ?></p>
                                            <p class="text-[11px] text-slate-400 truncate"><?= e($f['course_name']) ?> — Sec
                                                <?= e($f['section']) ?> · <?= e($f['display_year']) ?> · <?= e(semesterToRoman($f['display_semester'])) ?>
                                            </p>
                                        </div>
                                        <a href="/studentfeedbackucsh/student/feedback_form.php?form_id=<?= $f['form_id'] ?>"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg flex-shrink-0"><?= $LANG['fill'] ?? 'Fill' ?></a>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-slate-400">
                                <p class="text-xs"><?= $LANG['all_caught_up'] ?? 'All caught up!' ?></p>
                            </div>
                        <?php endif ?>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-purple-50">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-purple-800">
                                    <?= $LANG['student_affairs_section'] ?? 'Student Affairs' ?>
                                </h3>
                                <span class="text-[11px] text-purple-600 font-medium">(<?= $saPendingCount ?> <?= $LANG['pending'] ?? 'pending' ?>, <?= $saCompletedCount ?> <?= $LANG['completed'] ?? 'completed' ?>)</span>
                            </div>
                            <a href="/studentfeedbackucsh/student/sa_feedback.php"
                                class="text-xs text-purple-600 hover:underline"><?= $LANG['view_all'] ?? 'View' ?> →</a>
                        </div>
                        <?php if ($saPendingForms): ?>
                            <div class="divide-y divide-slate-100">
                                <?php foreach ($saPendingForms as $f): ?>
                                    <div class="px-5 py-3.5 flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-800 truncate"><?= e($f['title']) ?></p>
                                            <p class="text-[11px] text-slate-400">Due: <?= formatDateTime($f['end_date']) ?></p>
                                        </div>
                                        <a href="/studentfeedbackucsh/student/sa_feedback_form.php?form_id=<?= $f['form_id'] ?>"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg flex-shrink-0"><?= $LANG['fill'] ?? 'Fill' ?></a>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-slate-400">
                                <p class="text-xs"><?= $LANG['no_pending_sa'] ?? 'No pending SA forms.' ?></p>
                            </div>
                        <?php endif ?>
                    </div>

                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-orange-50">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-orange-800">
                                    <?= $LANG['administration_section'] ?? 'Administration' ?>
                                </h3>
                                <span class="text-[11px] text-orange-600 font-medium">(<?= $admPendingCount ?> <?= $LANG['pending'] ?? 'pending' ?>, <?= $admCompletedCount ?> <?= $LANG['completed'] ?? 'completed' ?>)</span>
                            </div>
                            <a href="/studentfeedbackucsh/student/adm_feedback.php"
                                class="text-xs text-orange-600 hover:underline"><?= $LANG['view_all'] ?? 'View' ?> →</a>
                        </div>
                        <?php if ($admPendingForms): ?>
                            <div class="divide-y divide-slate-100">
                                <?php foreach ($admPendingForms as $f): ?>
                                    <div class="px-5 py-3.5 flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-800 truncate"><?= e($f['title']) ?></p>
                                            <p class="text-[11px] text-slate-400">Due: <?= formatDateTime($f['end_date']) ?></p>
                                        </div>
                                        <a href="/studentfeedbackucsh/student/adm_feedback_form.php?form_id=<?= $f['form_id'] ?>"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white bg-orange-600 hover:bg-orange-700 rounded-lg flex-shrink-0"><?= $LANG['fill'] ?? 'Fill' ?></a>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-8 text-slate-400">
                                <p class="text-xs"><?= $LANG['no_pending_adm'] ?? 'No pending Adm forms.' ?></p>
                            </div>
                        <?php endif ?>
                    </div>

                </div>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <a href="/studentfeedbackucsh/student/my_sections.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md hover:border-cyan-200/50 transition-all hover:-translate-y-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">
                                <?= $LANG['nav_my_sections'] ?? 'My Sections' ?>
                            </p>
                            <p class="text-xs text-slate-500">
                                <?= $LANG['academic_feedback_link'] ?? 'Academic feedback' ?>
                            </p>
                        </div>
                    </a>
                    <a href="/studentfeedbackucsh/student/sa_feedback.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md hover:border-purple-200/50 transition-all hover:-translate-y-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">
                                <?= $LANG['nav_student_affairs'] ?? 'Student Affairs' ?>
                            </p>
                            <p class="text-xs text-slate-500"><?= $LANG['sa_feedback_forms'] ?? 'SA feedback forms' ?>
                            </p>
                        </div>
                    </a>
                    <a href="/studentfeedbackucsh/student/adm_feedback.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md hover:border-orange-200/50 transition-all hover:-translate-y-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800">
                                <?= $LANG['nav_administration'] ?? 'Administration' ?>
                            </p>
                            <p class="text-xs text-slate-500">
                                <?= $LANG['admin_feedback_forms'] ?? 'Admin feedback forms' ?>
                            </p>
                        </div>
                    </a>
                    <a href="/studentfeedbackucsh/student/feedback_history.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md hover:border-slate-300 transition-all hover:-translate-y-0.5">
                        <div>
                            <p class="text-sm font-semibold text-slate-800"><?= $LANG['nav_history'] ?? 'History' ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['all_submissions'] ?? 'All submissions' ?></p>
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
