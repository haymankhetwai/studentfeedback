<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('teacher');

$user      = getCurrentUser();
$stmt      = $conn->prepare("SELECT t.id FROM teachers t WHERE t.user_id=?");
$stmt->bind_param('i',$user['id']); $stmt->execute();
$teacher   = $stmt->get_result()->fetch_assoc(); $stmt->close();
$teacherId = $teacher['id'] ?? 0;

$pageTitle  = 'My Sections';
$activeMenu = 'sections';

$search  = clean($_GET['search'] ?? '');
$perPage = 10;
$page    = max(1,(int)($_GET['page'] ?? 1));

if ($teacherId) {
    if ($search) {
        $s2="%$search%";
        $c=$conn->prepare("SELECT COUNT(*) AS c FROM sections s JOIN courses c2 ON s.course_id=c2.id WHERE s.teacher_id=? AND (c2.course_name LIKE ? OR s.section LIKE ? OR s.academic_year LIKE ?)");
        $c->bind_param('isss',$teacherId,$s2,$s2,$s2); $c->execute();
        $total=(int)$c->get_result()->fetch_assoc()['c']; $c->close();
    } else {
        $c=$conn->prepare("SELECT COUNT(*) AS c FROM sections WHERE teacher_id=?");
        $c->bind_param('i',$teacherId); $c->execute();
        $total=(int)$c->get_result()->fetch_assoc()['c']; $c->close();
    }
} else { $total = 0; }

$pg=paginate($total,$perPage,$page); $off=$pg['offset'];

if ($teacherId) {
    if ($search) {
        $s2="%$search%";
        $stmt=$conn->prepare("SELECT s.*, c2.course_name, c2.course_code, (SELECT COUNT(*) FROM section_assignments sa WHERE sa.section_id=s.id) AS student_count, (SELECT COUNT(*) FROM feedback_forms ff WHERE ff.section_id=s.id) AS form_count FROM sections s JOIN courses c2 ON s.course_id=c2.id WHERE s.teacher_id=? AND (c2.course_name LIKE ? OR s.section LIKE ? OR s.academic_year LIKE ?) ORDER BY s.id DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('isssii',$teacherId,$s2,$s2,$s2,$perPage,$off);
    } else {
        $stmt=$conn->prepare("SELECT s.*, c2.course_name, c2.course_code, (SELECT COUNT(*) FROM section_assignments sa WHERE sa.section_id=s.id) AS student_count, (SELECT COUNT(*) FROM feedback_forms ff WHERE ff.section_id=s.id) AS form_count FROM sections s JOIN courses c2 ON s.course_id=c2.id WHERE s.teacher_id=? ORDER BY s.id DESC LIMIT ? OFFSET ?");
        $stmt->bind_param('iii',$teacherId,$perPage,$off);
    }
    $stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();
} else { $rows = []; }

// Build teacher layout inline
$navItems=[['label'=>'Dashboard','href'=>'/studentfeedback/teacher/index.php','key'=>'dashboard','icon'=>'home'],['label'=>'My Sections','href'=>'/studentfeedback/teacher/my_sections.php','key'=>'sections','icon'=>'grid'],['label'=>'Feedback Results','href'=>'/studentfeedback/teacher/feedback_results.php','key'=>'results','icon'=>'chart'],['label'=>'Analytics','href'=>'/studentfeedback/teacher/analytics.php','key'=>'analytics','icon'=>'report'],['label'=>'Profile','href'=>'/studentfeedback/teacher/profile.php','key'=>'profile','icon'=>'user']];
$initials=avatarInitials($user['name']);
?>
<!DOCTYPE html><html lang="en" class="h-full"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($pageTitle) ?> — SFMS</title><script src="https://cdn.tailwindcss.com"></script><script>tailwind.config={theme:{extend:{fontFamily:{inter:['Inter','sans-serif']}}}}</script><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/studentfeedback/assets/css/custom.css"></head>
<body class="h-full bg-slate-50 font-inter"><div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
<div class="flex h-screen overflow-hidden">
<aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
    <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500"><div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center"><?= iconSvg('user','w-5 h-5 text-white') ?></div><div><p class="text-sm font-bold">SFMS Teacher</p><p class="text-[10px] text-cyan-100">Faculty Portal</p></div><button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-100 hover:text-white"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
    <nav class="flex-1 py-4 px-3 space-y-0.5"><?php foreach($navItems as $n): $a=$activeMenu===$n['key']; ?><a href="<?= $n['href'] ?>" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm <?= $a?'bg-white/20 text-white font-semibold':'text-cyan-100 hover:bg-white/10 hover:text-white' ?>"><?= iconSvg($n['icon'],'w-4 h-4') ?> <?= e($n['label']) ?><?php if($a): ?><span class="ml-auto w-1.5 h-1.5 rounded-full bg-white"></span><?php endif ?></a><?php endforeach ?></nav>
    <div class="border-t border-cyan-500 px-4 py-4"><div class="flex items-center gap-3"><div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold"><?= e($initials) ?></div><div class="flex-1 min-w-0"><p class="text-xs font-semibold text-white truncate"><?= e($user['name']) ?></p><p class="text-[10px] text-cyan-100 truncate"><?= e($user['email']) ?></p></div><a href="/studentfeedback/auth/logout.php" class="text-cyan-100 hover:text-red-300"><?= iconSvg('logout','w-4 h-4') ?></a></div></div>
</aside>
<div class="flex-1 flex flex-col min-w-0 overflow-hidden">
<header class="bg-white border-b border-slate-200 px-4 lg:px-6 py-3.5 flex items-center gap-4 sticky top-0 z-20 shadow-sm"><button onclick="openSidebar()" class="lg:hidden text-slate-500"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg></button><h1 class="text-base font-semibold text-slate-800"><?= e($pageTitle) ?></h1><div class="ml-auto"><a href="/studentfeedback/teacher/profile.php" class="flex items-center gap-2 px-3 py-1.5 rounded-xl hover:bg-slate-50"><div class="w-7 h-7 rounded-full bg-cyan-600 flex items-center justify-center text-xs font-bold text-white"><?= e($initials) ?></div><span class="hidden md:block text-sm font-medium text-slate-700"><?= e($user['name']) ?></span></a></div></header>
<main class="flex-1 overflow-y-auto p-4 lg:p-6">

<div class="mb-6"><h2 class="text-xl font-bold text-slate-800">My Sections</h2><p class="text-sm text-slate-500">Your assigned course sections</p></div>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 max-w-xs"><span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search','w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search sections..." class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>
            <button class="px-3 py-2 text-sm bg-cyan-600 text-white rounded-xl hover:bg-cyan-700">Search</button>
            <?php if($search): ?><a href="my_sections.php" class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-slate-600">Clear</a><?php endif ?>
        </form>
        <span class="text-xs text-slate-400"><?= $total ?> section<?= $total!==1?'s':'' ?></span>
    </div>
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-50 border-b border-slate-200"><tr>
                <th class="text-left px-5 py-3 text-slate-500">#</th>
                <th class="text-left px-5 py-3 text-slate-500">Course</th>
                <th class="text-left px-5 py-3 text-slate-500">Section</th>
                <th class="text-left px-5 py-3 text-slate-500">Year / Semester</th>
                <th class="text-left px-5 py-3 text-slate-500">Students</th>
                <th class="text-left px-5 py-3 text-slate-500">Forms</th>
                <th class="text-right px-5 py-3 text-slate-500">Actions</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
            <?php if($rows): foreach($rows as $i => $row): ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset']+$i+1 ?></td>
                    <td class="px-5 py-3"><p class="text-sm font-medium text-slate-800"><?= e($row['course_name']) ?></p><p class="text-xs text-slate-400 font-mono"><?= e($row['course_code']) ?></p></td>
                    <td class="px-5 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-cyan-100 text-cyan-800"><?= e($row['section']) ?></span></td>
                    <td class="px-5 py-3 text-sm text-slate-500"><?= e($row['academic_year']) ?><br><span class="text-xs text-slate-400"><?= e($row['semester']) ?></span></td>
                    <td class="px-5 py-3"><span class="text-sm font-bold text-slate-700"><?= $row['student_count'] ?></span></td>
                    <td class="px-5 py-3"><span class="text-sm font-bold text-emerald-600"><?= $row['form_count'] ?></span></td>
                    <td class="px-5 py-3 text-right">
                        <a href="/studentfeedback/teacher/feedback_results.php?section_id=<?= $row['id'] ?>" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg">
                            <?= iconSvg('chart','w-3.5 h-3.5') ?> Results
                        </a>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="text-center py-16 text-slate-400"><?= iconSvg('grid','w-10 h-10 mx-auto mb-3 opacity-40') ?><p class="text-sm">No sections assigned.</p></td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100"><?= paginationLinks($pg,'my_sections.php'.($search?'?search='.urlencode($search):'')) ?></div>
</div>

</main></div></div>
<script>
function openSidebar(){document.getElementById('sidebar').classList.remove('-translate-x-full');document.getElementById('overlay').classList.remove('hidden');}
function closeSidebar(){document.getElementById('sidebar').classList.add('-translate-x-full');document.getElementById('overlay').classList.add('hidden');}
</script>
</body></html>
