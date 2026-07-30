<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

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
$studentSemIds = getStudentSemesterIds($conn, $studentId);

$pageTitle = 'Student Dashboard';
$activeMenu = 'dashboard';
$now = date('Y-m-d H:i:s');
$today = date('Y-m-d');

// ─── Load ALL forms and compute stats ──────────────────────────────
$allForms = [];
$totalCompletedCount = 0;
$totalAvailableCount = 0;

if ($studentId) {
    // Academic
    $acadFormsStmt = $conn->prepare(
        "SELECT ff.id, ff.title, ff.start_date, ff.end_date, ff.status, ff.module,
                c.course_name, c.course_code, sm_sec.section_name AS section_name,
                COALESCE(ay.year_name, '') AS display_year,
                sm.semester_name AS display_semester,
                u.name AS teacher_name,
                (SELECT COUNT(*) FROM feedback_submissions fs WHERE fs.form_id=ff.id AND fs.student_id=?) AS submitted
         FROM feedback_forms ff
         JOIN section_assignments sa ON ff.section_id = sa.section_id
         JOIN sections s ON ff.section_id = s.id
         JOIN courses c ON s.course_id = c.id
         JOIN teachers t ON s.teacher_id = t.id
         JOIN users u ON t.user_id = u.id
         LEFT JOIN section_master sm_sec ON s.section_id = sm_sec.id
         LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
         LEFT JOIN semesters sm ON s.semester_id = sm.id
         WHERE sa.student_id = ? AND ff.module = 'academic'
         ORDER BY ff.end_date ASC"
    );
    $acadFormsStmt->bind_param('ii', $studentId, $studentId);
    $acadFormsStmt->execute();
    $acadRows = $acadFormsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $acadFormsStmt->close();
    foreach ($acadRows as $r) {
        if ($r['status'] === 'Active' || (int)$r['submitted'] > 0) {
            $allForms[] = $r;
            $totalAvailableCount++;
            if ((int)$r['submitted'] > 0) $totalCompletedCount++;
        }
    }

    // SA + Admin
    if (!empty($studentYearIds) && !empty($studentSemIds)) {
        foreach (['student_affairs', 'administration'] as $module) {
            $yrPH = implode(',', array_fill(0, count($studentYearIds), '?'));
            $yrBT = str_repeat('i', count($studentYearIds));
            $smPH = implode(',', array_fill(0, count($studentSemIds), '?'));
            $smBT = str_repeat('i', count($studentSemIds));

            $modStmt = $conn->prepare(
                "SELECT f.id, f.title, f.start_date, f.end_date, f.status, f.module,
                        COALESCE(ay.year_name, '') AS display_year,
                        sm.semester_name AS display_semester,
                        (SELECT COUNT(*) FROM feedback_submissions s WHERE s.form_id=f.id AND s.student_id=?) AS submitted
                 FROM feedback_forms f
                 LEFT JOIN academic_years ay ON f.academic_year_id = ay.id
                 LEFT JOIN semesters sm ON f.semester_id = sm.id
                 WHERE f.module=? AND f.academic_year_id IN ($yrPH) AND f.semester_id IN ($smPH)
                 ORDER BY f.end_date ASC"
            );
            $modStmt->bind_param('is' . $yrBT . $smBT, ...array_merge([$studentId, $module], $studentYearIds, $studentSemIds));
            $modStmt->execute();
            $modRows = $modStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $modStmt->close();
            foreach ($modRows as $r) {
                if ($r['status'] === 'Active' || (int)$r['submitted'] > 0) {
                    $allForms[] = $r;
                    $totalAvailableCount++;
                    if ((int)$r['submitted'] > 0) $totalCompletedCount++;
                }
            }
        }
    }
}

$totalPendingCount = $totalAvailableCount - $totalCompletedCount;
$progressPercent = $totalAvailableCount > 0 ? round(($totalCompletedCount / $totalAvailableCount) * 100) : 0;
$allCompleted = $totalAvailableCount > 0 && $totalPendingCount === 0;

// ─── Group forms by module for cards ──────────────────────────────
$acadPendingForms = [];
$acadCompletedCount = 0;
$saPendingForms = [];
$saCompletedCount = 0;
$admPendingForms = [];
$admCompletedCount = 0;

foreach ($allForms as $f) {
    $isSubmitted = (int)$f['submitted'] > 0;
    if ($f['module'] === 'academic') {
        if ($isSubmitted) {
            $acadCompletedCount++;
        } else {
            $acadPendingForms[] = $f;
        }
    } elseif ($f['module'] === 'student_affairs') {
        if ($isSubmitted) {
            $saCompletedCount++;
        } else {
            $saPendingForms[] = $f;
        }
    } elseif ($f['module'] === 'administration') {
        if ($isSubmitted) {
            $admCompletedCount++;
        } else {
            $admPendingForms[] = $f;
        }
    }
}

// First uncompleted form for CTA
$firstUncompletedId = null;
$sortedAll = $allForms;
$moduleOrder = ['academic' => 0, 'student_affairs' => 1, 'administration' => 2];
usort($sortedAll, function ($a, $b) use ($moduleOrder) {
    $aSub = (int)$a['submitted'] > 0;
    $bSub = (int)$b['submitted'] > 0;
    if ($aSub !== $bSub) return $aSub ? 1 : -1;
    $aMod = $moduleOrder[$a['module']] ?? 99;
    $bMod = $moduleOrder[$b['module']] ?? 99;
    if ($aMod !== $bMod) return $aMod - $bMod;
    return strtotime($a['end_date']) - strtotime($b['end_date']);
});
foreach ($sortedAll as $f) {
    if ((int)$f['submitted'] === 0 && $f['status'] === 'Active') {
        $firstUncompletedId = $f['id'];
        break;
    }
}
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
    <style>
        .card-body { max-height: 0; overflow: hidden; transition: max-height 0.4s ease, padding 0.3s ease; padding-top: 0; padding-bottom: 0; }
        .card-body.expanded { max-height: 1000px; padding-top: 0; }
        .more-btn { transition: all 0.2s ease; }
        .more-btn:hover { background: rgba(0,0,0,0.05); }
        .progress-ring { transition: stroke-dashoffset 0.6s ease; }
    </style>
</head>

<body
    class="h-full bg-gradient-to-br from-slate-50 to-cyan-50/30 font-inter <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
    <div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
    <div class="flex h-screen overflow-hidden">

        <aside id="sidebar"
            class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
            <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
                <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 overflow-hidden">
                    <img src="/studentfeedbackucsh/assets/uploads/profiles/image.png" alt="UCSH Logo"
                        class="w-full h-full object-contain rounded-xl">
                </div>
                <div>
                    <p class="text-lg font-bold"><?= $LANG['student_portal'] ?? 'SFMS Student' ?></p>
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
                <?php
                $navItems = [
                    ['label' => $LANG['nav_dashboard'] ?? 'Dashboard', 'href' => '/studentfeedbackucsh/student/dashboard.php', 'key' => 'dashboard', 'icon' => 'home', 'iconColor' => 'text-yellow-300'],
                    ['label' => $LANG['nav_feedback_forms'] ?? 'Feedback Forms', 'href' => '/studentfeedbackucsh/student/feedback_forms.php', 'key' => 'feedback_forms', 'icon' => 'clipboard', 'iconColor' => 'text-emerald-300'],
                    ['label' => $LANG['nav_history'] ?? 'Submission History', 'href' => '/studentfeedbackucsh/student/feedback_history.php', 'key' => 'history', 'icon' => 'history', 'iconColor' => 'text-teal-300'],
                    ['label' => $LANG['nav_profile'] ?? 'Profile', 'href' => '/studentfeedbackucsh/student/profile.php', 'key' => 'profile', 'icon' => 'user', 'iconColor' => 'text-rose-300'],
                ];
                foreach ($navItems as $n):
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
                    <div class="min-w-0">
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

                <!-- Overall Progress Card -->
                <!-- <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 mb-6">
                    <div class="flex flex-col md:flex-row items-center gap-6">
                        <div class="relative flex-shrink-0">
                            <svg class="w-24 h-24 transform -rotate-90" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="42" fill="none" stroke="#e2e8f0" stroke-width="8" />
                                <circle cx="50" cy="50" r="42" fill="none" stroke="#06b6d4" stroke-width="8"
                                    stroke-linecap="round"
                                    stroke-dasharray="<?= 2 * M_PI * 42 ?>"
                                    stroke-dashoffset="<?= 2 * M_PI * 42 * (1 - $progressPercent / 100) ?>"
                                    class="progress-ring" />
                            </svg>
                            <div class="absolute inset-0 flex items-center justify-center">
                                <span class="text-lg font-bold text-cyan-700"><?= $progressPercent ?>%</span>
                            </div>
                        </div>
                        <div class="flex-1 text-center md:text-left">
                            <h3 class="text-lg font-bold text-slate-800 mb-1"><?= $LANG['overall_progress'] ?? 'Overall Progress' ?></h3>
                            <p class="text-sm text-slate-500 mb-3">
                                <?= $totalCompletedCount ?> <?= $LANG['of'] ?? 'of' ?> <?= $totalAvailableCount ?> <?= $LANG['forms_completed'] ?? 'forms completed' ?>
                            </p>
                            <div class="w-full bg-slate-100 rounded-full h-3 mb-2">
                                <div class="bg-gradient-to-r from-cyan-500 to-cyan-600 h-3 rounded-full transition-all duration-500 ease-out"
                                    style="width: <?= $progressPercent ?>%"></div>
                            </div>
                            <div class="flex items-center gap-4 text-xs text-slate-500">
                                <span class="flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-green-500"></span>
                                    <?= $totalCompletedCount ?> <?= $LANG['completed'] ?? 'Completed' ?>
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                                    <?= $totalPendingCount ?> <?= $LANG['pending'] ?? 'Pending' ?>
                                </span>
                                <span class="flex items-center gap-1">
                                    <span class="w-2 h-2 rounded-full bg-slate-300"></span>
                                    <?= $totalAvailableCount ?> <?= $LANG['total'] ?? 'Total' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div> -->

                <!-- Continue Feedback -->
                <?php if (!$allCompleted && $firstUncompletedId): ?>
                <a href="/studentfeedbackucsh/student/feedback_forms.php" class="block bg-gradient-to-r from-cyan-600 to-cyan-700 rounded-2xl p-5 mb-6 text-white shadow-md hover:shadow-lg transition-all hover:-translate-y-0.5">
                    <div class="flex flex-col sm:flex-row items-center gap-4">
                        <div class="flex items-center gap-3 flex-1">
                            <div class="w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                                <?= iconSvg('clipboard', 'w-6 h-6 text-white') ?>
                            </div>
                            <div>
                                <p class="text-sm font-semibold opacity-90"><?= $LANG['continue_feedback'] ?? 'Continue Feedback' ?></p>
                                <p class="text-xs opacity-75">
                                    <?= $totalPendingCount ?> <?= $LANG['forms_remaining'] ?? 'forms remaining' ?>
                                </p>
                            </div>
                        </div>
                        <span class="px-5 py-2.5 bg-white text-cyan-700 font-semibold text-sm rounded-xl shadow-sm">
                            <?= $LANG['start_now'] ?? 'Start Now' ?> →
                        </span>
                    </div>
                </a>
                <?php elseif ($allCompleted): ?>
                <div class="bg-green-50 border border-green-200 rounded-2xl p-5 mb-6 flex items-center gap-4">
                    <div class="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center flex-shrink-0">
                        <?= iconSvg('check', 'w-6 h-6 text-green-600') ?>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-green-800"><?= $LANG['all_done'] ?? 'All Done!' ?></p>
                        <p class="text-xs text-green-600"><?= $LANG['all_forms_completed'] ?? 'You have completed all feedback forms. Thank you!' ?></p>
                    </div>
                </div>
                <?php endif ?>

                <!-- Module Cards -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">

                    <!-- Academic Card -->
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-cyan-50">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-cyan-800">
                                    <?= $LANG['academic_feedback_section'] ?? 'Academic Feedback' ?>
                                </h3>
                                <span class="text-[11px] text-cyan-600 font-medium">(<?= count($acadPendingForms) ?>
                                    <?= $LANG['pending'] ?? 'pending' ?>, <?= $acadCompletedCount ?>
                                    <?= $LANG['completed'] ?? 'completed' ?>)</span>
                            </div>
                        </div>
                        <?php if ($acadPendingForms): ?>
                            <div class="divide-y divide-slate-100">
                                <?php
                                $acadShowLimit = 3;
                                $acadShowAll = false;
                                foreach ($acadPendingForms as $idx => $f): ?>
                                    <div class="px-5 py-3.5 flex items-center justify-between gap-3 <?= ($idx >= $acadShowLimit) ? 'acad-extra hidden' : '' ?>">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-800 truncate"><?= e($f['title']) ?></p>
                                            <p class="text-[11px] text-slate-400 truncate"><?= e($f['course_name'] ?? '') ?> <?= $f['course_name'] ? '— Sec ' . e($f['section_name'] ?? '') : '' ?> · <?= e($f['display_year'] ?? '') ?> · <?= e(semesterToRoman($f['display_semester'] ?? '')) ?></p>
                                        </div>
                                        <a href="/studentfeedbackucsh/student/feedback_forms.php"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg flex-shrink-0"><?= $LANG['fill'] ?? 'Fill' ?></a>
                                    </div>
                                <?php endforeach ?>
                            </div>
                            <?php if (count($acadPendingForms) > $acadShowLimit): ?>
                            <button onclick="toggleMore(this, 'acad-extra')" class="more-btn w-full px-5 py-2.5 text-md font-semibold text-cyan-600 border-t border-slate-100">
                                <?= $LANG['more'] ?? 'More' ?> ↓
                            </button>
                            <?php endif ?>
                        <?php else: ?>
                            <div class="text-center py-8 text-slate-400">
                                <p class="text-xs"><?= $LANG['all_caught_up'] ?? 'All caught up!' ?></p>
                            </div>
                        <?php endif ?>
                    </div>

                    <!-- Student Affairs Card -->
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-purple-50">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-purple-800">
                                    <?= $LANG['student_affairs_section'] ?? 'Student Affairs' ?>
                                </h3>
                                <span class="text-[11px] text-purple-600 font-medium">(<?= count($saPendingForms) ?>
                                    <?= $LANG['pending'] ?? 'pending' ?>, <?= $saCompletedCount ?>
                                    <?= $LANG['completed'] ?? 'completed' ?>)</span>
                            </div>
                        </div>
                        <?php if ($saPendingForms): ?>
                            <div class="divide-y divide-slate-100">
                                <?php
                                $saShowLimit = 3;
                                foreach ($saPendingForms as $idx => $f): ?>
                                    <div class="px-5 py-3.5 flex items-center justify-between gap-3 <?= ($idx >= $saShowLimit) ? 'sa-extra hidden' : '' ?>">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-800 truncate"><?= e($f['title']) ?></p>
                                            <p class="text-[11px] text-slate-400">Due: <?= formatDateTime($f['end_date']) ?></p>
                                        </div>
                                        <a href="/studentfeedbackucsh/student/feedback_forms.php"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg flex-shrink-0"><?= $LANG['fill'] ?? 'Fill' ?></a>
                                    </div>
                                <?php endforeach ?>
                            </div>
                            <?php if (count($saPendingForms) > $saShowLimit): ?>
                            <button onclick="toggleMore(this, 'sa-extra')" class="more-btn w-full px-5 py-2.5 text-xs font-semibold text-purple-600 border-t border-slate-100">
                                <?= $LANG['more'] ?? 'More' ?> ↓
                            </button>
                            <?php endif ?>
                        <?php else: ?>
                            <div class="text-center py-8 text-slate-400">
                                <p class="text-xs"><?= $LANG['no_pending_sa'] ?? 'No pending SA forms.' ?></p>
                            </div>
                        <?php endif ?>
                    </div>

                    <!-- Administration Card -->
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-orange-50">
                            <div class="flex items-center gap-2">
                                <h3 class="text-sm font-semibold text-orange-800">
                                    <?= $LANG['administration_section'] ?? 'Administration' ?>
                                </h3>
                                <span class="text-[11px] text-orange-600 font-medium">(<?= count($admPendingForms) ?>
                                    <?= $LANG['pending'] ?? 'pending' ?>, <?= $admCompletedCount ?>
                                    <?= $LANG['completed'] ?? 'completed' ?>)</span>
                            </div>
                        </div>
                        <?php if ($admPendingForms): ?>
                            <div class="divide-y divide-slate-100">
                                <?php
                                $admShowLimit = 3;
                                foreach ($admPendingForms as $idx => $f): ?>
                                    <div class="px-5 py-3.5 flex items-center justify-between gap-3 <?= ($idx >= $admShowLimit) ? 'adm-extra hidden' : '' ?>">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium text-slate-800 truncate"><?= e($f['title']) ?></p>
                                            <p class="text-[11px] text-slate-400">Due: <?= formatDateTime($f['end_date']) ?></p>
                                        </div>
                                        <a href="/studentfeedbackucsh/student/feedback_forms.php"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-white bg-orange-600 hover:bg-orange-700 rounded-lg flex-shrink-0"><?= $LANG['fill'] ?? 'Fill' ?></a>
                                    </div>
                                <?php endforeach ?>
                            </div>
                            <?php if (count($admPendingForms) > $admShowLimit): ?>
                            <button onclick="toggleMore(this, 'adm-extra')" class="more-btn w-full px-5 py-2.5 text-xs font-semibold text-orange-600 border-t border-slate-100">
                                <?= $LANG['more'] ?? 'More' ?> ↓
                            </button>
                            <?php endif ?>
                        <?php else: ?>
                            <div class="text-center py-8 text-slate-400">
                                <p class="text-xs"><?= $LANG['no_pending_adm'] ?? 'No pending Adm forms.' ?></p>
                            </div>
                        <?php endif ?>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                    <a href="/studentfeedbackucsh/student/feedback_forms.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md hover:border-cyan-200/50 transition-all hover:-translate-y-0.5">
                        <div class="w-10 h-10 rounded-xl bg-emerald-100 flex items-center justify-center flex-shrink-0">
                            <?= iconSvg('clipboard', 'w-5 h-5 text-emerald-600') ?>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-slate-800">
                                <?= $LANG['nav_feedback_forms'] ?? 'Feedback Forms' ?>
                            </p>
                            <p class="text-xs text-slate-500">
                                <?= $totalPendingCount ?> <?= $LANG['pending'] ?? 'pending' ?>
                            </p>
                        </div>
                    </a>
                    <a href="/studentfeedbackucsh/student/feedback_history.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md hover:border-slate-300 transition-all hover:-translate-y-0.5">
                        <div class="w-10 h-10 rounded-xl bg-teal-100 flex items-center justify-center flex-shrink-0">
                            <?= iconSvg('history', 'w-5 h-5 text-teal-600') ?>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-slate-800"><?= $LANG['nav_history'] ?? 'History' ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['all_submissions'] ?? 'All submissions' ?></p>
                        </div>
                    </a>
                    <a href="/studentfeedbackucsh/student/profile.php"
                        class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 flex items-center gap-3 hover:shadow-md hover:border-rose-200/50 transition-all hover:-translate-y-0.5">
                        <div class="w-10 h-10 rounded-xl bg-rose-100 flex items-center justify-center flex-shrink-0">
                            <?= iconSvg('user', 'w-5 h-5 text-rose-600') ?>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-slate-800"><?= $LANG['nav_profile'] ?? 'Profile' ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['profile_subtitle'] ?? 'Account settings' ?></p>
                        </div>
                    </a>
                </div>

            </main>
        </div>
    </div>
    <script>
        function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
        function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('overlay').classList.add('hidden'); }

        function toggleMore(btn, extraClass) {
            var items = document.querySelectorAll('.' + extraClass);
            var isExpanded = btn.dataset.expanded === 'true';
            for (var i = 0; i < items.length; i++) {
                if (isExpanded) {
                    items[i].classList.add('hidden');
                } else {
                    items[i].classList.remove('hidden');
                }
            }
            btn.dataset.expanded = isExpanded ? 'false' : 'true';
            btn.innerHTML = isExpanded
                ? '<?= $LANG["more"] ?? "More" ?> ↓'
                : '<?= $LANG["less"] ?? "Less" ?> ↑';
        }

        document.addEventListener('DOMContentLoaded', function() {
            var ring = document.querySelector('.progress-ring');
            if (ring) {
                var target = ring.getAttribute('stroke-dashoffset');
                ring.style.strokeDashoffset = ring.getAttribute('stroke-dasharray');
                setTimeout(function() { ring.style.strokeDashoffset = target; }, 100);
            }
        });
    </script>
</body>

</html>
