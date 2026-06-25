<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('student');

$user = getCurrentUser();
$stmt = $conn->prepare("SELECT st.id FROM students st WHERE st.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
$studentId = $student['id'] ?? 0;
$pageTitle = 'My Sections';
$activeMenu = 'sections';
$today = date('Y-m-d');

// Load sections and their forms
$sections = [];
if ($studentId) {
    $rs = $conn->prepare("SELECT s.*, c.course_name, c.course_code, u.name AS teacher_name FROM section_assignments sa JOIN sections s ON sa.section_id=s.id JOIN courses c ON s.course_id=c.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id WHERE sa.student_id=? ORDER BY s.id DESC");
    $rs->bind_param('i', $studentId);
    $rs->execute();
    $sections = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
    $rs->close();
}

// Fixed navigation array: Includes all 6 structural modules so items do not disappear
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
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { inter: ['Inter', 'sans-serif'] }
                }
            }
        }
    </script>
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
                <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-100">
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
                        <p class="text-[10px] text-cyan-100 truncate">Student
                        </p>
                    </div>
                    <a href="/studentfeedback/auth/logout.php" class="text-cyan-100 hover:text-red-300">
                        <?= iconSvg('logout', 'w-4 h-4') ?>
                    </a>
                </div>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <header
                class="bg-white border-b border-slate-200 px-4 lg:px-6 py-3.5 flex items-center gap-4 sticky top-0 z-20 shadow-sm">
                <button onclick="openSidebar()" class="lg:hidden text-slate-500">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                        stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
                <h1 class="text-base font-semibold text-slate-800"><?= e($pageTitle) ?></h1>
            </header>

            <main class="flex-1 overflow-y-auto p-4 lg:p-6">
                <div class="mb-6">
                    <h2 class="text-xl font-bold text-slate-800">My Sections & Feedback Forms</h2>
                    <p class="text-sm text-slate-500">Your enrolled sections and available feedback forms</p>
                </div>

                <?php if ($sections):
                    foreach ($sections as $sec):
                        // Get forms for this section
                        $fStmt = $conn->prepare("SELECT ff.id, ff.title, ff.status, ff.start_date, ff.end_date, (SELECT COUNT(*) FROM feedback_submissions fs WHERE fs.feedback_form_id=ff.id AND fs.student_id=?) AS submitted FROM feedback_forms ff WHERE ff.section_id=? ORDER BY ff.id DESC");
                        $fStmt->bind_param('ii', $studentId, $sec['id']);
                        $fStmt->execute();
                        $forms = $fStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        $fStmt->close();
                        ?>
                        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden mb-4">
                            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                                <div>
                                    <p class="text-sm font-semibold text-slate-800"><?= e($sec['course_name']) ?> <span
                                            class="font-mono text-xs text-slate-400">(<?= e($sec['course_code']) ?>)</span></p>
                                    <p class="text-xs text-slate-400 mt-0.5">Section <?= e($sec['section']) ?> ·
                                        <?= e($sec['academic_year']) ?> · <?= e($sec['semester']) ?> · Taught by
                                        <?= e($sec['teacher_name']) ?></p>
                                </div>
                            </div>
                            <?php if ($forms): ?>
                                <div class="divide-y divide-slate-100">
                                    <?php foreach ($forms as $f):
                                        $isActive = $f['status'] === 'active' && $f['start_date'] <= $today && $f['end_date'] >= $today;
                                        $submitted = (bool) $f['submitted'];
                                        $expired = $f['end_date'] < $today;
                                        ?>
                                        <div class="px-6 py-4 flex items-center justify-between">
                                            <div>
                                                <p class="text-sm font-medium text-slate-800"><?= e($f['title']) ?></p>
                                                <p class="text-xs text-slate-400"><?= formatDate($f['start_date']) ?> —
                                                    <?= formatDate($f['end_date']) ?></p>
                                            </div>
                                            <div class="flex items-center gap-3">
                                                <?php if ($submitted): ?>
                                                    <span
                                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-green-700 bg-green-50 border border-green-200 rounded-xl">
                                                        <?= iconSvg('check', 'w-3.5 h-3.5') ?> Submitted
                                                    </span>
                                                <?php elseif ($isActive): ?>
                                                    <span
                                                        class="inline-flex items-center px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-700">Open
                                                        until <?= formatDate($f['end_date']) ?></span>
                                                    <a href="/studentfeedback/student/feedback_form.php?form_id=<?= $f['id'] ?>"
                                                        class="inline-flex items-center gap-1 px-4 py-2 text-xs font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl transition-all hover:-translate-y-0.5">
                                                        <?= iconSvg('clipboard', 'w-3.5 h-3.5') ?> Fill Form
                                                    </a>
                                                <?php elseif ($expired): ?>
                                                    <span
                                                        class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-slate-500 bg-slate-100 rounded-xl">Expired</span>
                                                <?php else: ?>
                                                    <?= badgeStatus($f['status']) ?>
                                                <?php endif ?>
                                            </div>
                                        </div>
                                    <?php endforeach ?>
                                </div>
                            <?php else: ?>
                                <div class="px-6 py-6 text-sm text-slate-400">No feedback forms for this section.</div>
                            <?php endif ?>
                        </div>
                    <?php endforeach; else: ?>
                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 text-center py-16 text-slate-400">
                        <?= iconSvg('grid', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                        <p class="text-sm">You are not enrolled in any sections yet.</p>
                    </div>
                <?php endif ?>

            </main>
        </div>
    </div>
    <script>
        function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
        function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('overlay').classList.add('hidden'); }
    </script>
</body>

</html>