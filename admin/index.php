<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Dashboard';
$activeMenu = 'dashboard';

function count_table($conn, $table, $where = '') {
    $sql = "SELECT COUNT(*) AS cnt FROM `$table`" . ($where ? " WHERE $where" : '');
    return (int) $conn->query($sql)->fetch_assoc()['cnt'];
}

// ─── All Stats ────────────────────────────────────────────────
$stats = [
    // Row 1 — People
    ['label' => 'Total Students',     'value' => count_table($conn,'students'),                               'icon' => 'users',    'color' => 'blue',   'href' => '/studentfeedback/admin/students.php'],
    ['label' => 'Total Teachers',     'value' => count_table($conn,'teachers'),                               'icon' => 'user',     'color' => 'indigo', 'href' => '/studentfeedback/admin/teachers.php'],
    ['label' => 'Departments',        'value' => count_table($conn,'departments'),                            'icon' => 'building', 'color' => 'violet', 'href' => '/studentfeedback/admin/departments.php'],
    ['label' => 'Section Assignments','value' => count_table($conn,'section_assignments'),                    'icon' => 'link',     'color' => 'cyan',   'href' => '/studentfeedback/admin/section_assignments.php'],
    // Row 2 — Academic
    ['label' => 'Courses',            'value' => count_table($conn,'courses'),                                'icon' => 'book',     'color' => 'teal',   'href' => '/studentfeedback/admin/courses.php'],
    ['label' => 'Sections',           'value' => count_table($conn,'sections'),                               'icon' => 'grid',     'color' => 'emerald','href' => '/studentfeedback/admin/sections.php'],
    ['label' => 'Active Acad. Forms', 'value' => count_table($conn,'feedback_forms',"status='active'"),       'icon' => 'document', 'color' => 'green',  'href' => '/studentfeedback/admin/feedback_forms.php'],
    ['label' => 'Academic Submissions','value'=> count_table($conn,'feedback_submissions'),                   'icon' => 'check',    'color' => 'lime',   'href' => '/studentfeedback/admin/feedback_results.php'],
    // Row 3 — SA & Admin
    ['label' => 'SA Forms',           'value' => count_table($conn,'sa_feedback_forms'),                      'icon' => 'shield',   'color' => 'purple', 'href' => '/studentfeedback/admin/sa_forms.php'],
    ['label' => 'SA Submissions',     'value' => count_table($conn,'sa_feedback_submissions'),                'icon' => 'check',    'color' => 'fuchsia','href' => '/studentfeedback/admin/sa_results.php'],
    ['label' => 'Adm Forms',          'value' => count_table($conn,'adm_feedback_forms'),                     'icon' => 'office',   'color' => 'orange', 'href' => '/studentfeedback/admin/adm_forms.php'],
    ['label' => 'Adm Submissions',    'value' => count_table($conn,'adm_feedback_submissions'),               'icon' => 'check',    'color' => 'amber',  'href' => '/studentfeedback/admin/adm_results.php'],
];

$colorMap = [
    'blue'    => ['bg' => 'bg-cyan-50',    'icon' => 'bg-cyan-600',    'text' => 'text-cyan-700'],
    'indigo'  => ['bg' => 'bg-cyan-50',  'icon' => 'bg-cyan-600',  'text' => 'text-cyan-700'],
    'violet'  => ['bg' => 'bg-violet-50',  'icon' => 'bg-violet-600',  'text' => 'text-violet-700'],
    'cyan'    => ['bg' => 'bg-cyan-50',    'icon' => 'bg-cyan-600',    'text' => 'text-cyan-700'],
    'teal'    => ['bg' => 'bg-cyan-50',    'icon' => 'bg-cyan-600',    'text' => 'text-cyan-700'],
    'emerald' => ['bg' => 'bg-emerald-50', 'icon' => 'bg-emerald-600', 'text' => 'text-emerald-700'],
    'green'   => ['bg' => 'bg-green-50',   'icon' => 'bg-green-600',   'text' => 'text-green-700'],
    'lime'    => ['bg' => 'bg-lime-50',    'icon' => 'bg-lime-600',    'text' => 'text-lime-700'],
    'purple'  => ['bg' => 'bg-purple-50',  'icon' => 'bg-purple-600',  'text' => 'text-purple-700'],
    'fuchsia' => ['bg' => 'bg-fuchsia-50', 'icon' => 'bg-fuchsia-600', 'text' => 'text-fuchsia-700'],
    'orange'  => ['bg' => 'bg-orange-50',  'icon' => 'bg-orange-600',  'text' => 'text-orange-700'],
    'amber'   => ['bg' => 'bg-amber-50',   'icon' => 'bg-amber-600',   'text' => 'text-amber-700'],
];

// ─── Recent Academic Submissions ──────────────────────────────
$recentAcademic = $conn->query("
    SELECT fs.submitted_at, u.name AS student_name, ff.title AS form_title, c.course_name, s.section
    FROM feedback_submissions fs
    JOIN students st ON fs.student_id=st.id JOIN users u ON st.user_id=u.id
    JOIN feedback_forms ff ON fs.feedback_form_id=ff.id
    JOIN sections s ON ff.section_id=s.id JOIN courses c ON s.course_id=c.id
    ORDER BY fs.submitted_at DESC LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

// ─── Recent SA Submissions ────────────────────────────────────
$recentSA = $conn->query("
    SELECT sas.submitted_at, u.name AS student_name, saf.title AS form_title
    FROM sa_feedback_submissions sas
    JOIN students st ON sas.student_id=st.id JOIN users u ON st.user_id=u.id
    JOIN sa_feedback_forms saf ON sas.form_id=saf.id
    ORDER BY sas.submitted_at DESC LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

// ─── Recent Adm Submissions ───────────────────────────────────
$recentAdm = $conn->query("
    SELECT ads.submitted_at, u.name AS student_name, adf.title AS form_title
    FROM adm_feedback_submissions ads
    JOIN students st ON ads.student_id=st.id JOIN users u ON st.user_id=u.id
    JOIN adm_feedback_forms adf ON ads.form_id=adf.id
    ORDER BY ads.submitted_at DESC LIMIT 6
")->fetch_all(MYSQLI_ASSOC);

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<!-- Page Header -->
<div class="mb-6">
    <h2 class="text-2xl font-bold text-slate-800">Welcome back, <?= e(explode(' ', getCurrentUser()['name'])[0]) ?> 👋</h2>
    <p class="text-sm text-slate-500 mt-1">University Feedback Management System — Full Overview</p>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
<?php foreach ($stats as $s):
    $c = $colorMap[$s['color']];
?>
    <a href="<?= $s['href'] ?>" class="group bg-white rounded-2xl shadow-sm hover:shadow-md border border-slate-100 p-4 flex items-center gap-3 transition-all hover:-translate-y-0.5">
        <div class="w-11 h-11 rounded-xl <?= $c['icon'] ?> flex items-center justify-center flex-shrink-0 shadow-sm">
            <?= iconSvg($s['icon'], 'w-5 h-5 text-white') ?>
        </div>
        <div>
            <p class="text-xl font-bold <?= $c['text'] ?>"><?= number_format($s['value']) ?></p>
            <p class="text-xs text-slate-500 font-medium leading-tight mt-0.5"><?= e($s['label']) ?></p>
        </div>
    </a>
<?php endforeach ?>
</div>

<!-- Three-column Recent Submissions -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">

    <!-- Academic -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-cyan-50">
            <div class="flex items-center gap-2"><?= iconSvg('academic','w-4 h-4 text-cyan-600') ?><h3 class="text-sm font-semibold text-cyan-800">Academic Feedback</h3></div>
            <a href="/studentfeedback/admin/feedback_results.php" class="text-xs text-cyan-600 hover:underline">View →</a>
        </div>
        <?php if ($recentAcademic): ?>
        <div class="divide-y divide-slate-100">
            <?php foreach ($recentAcademic as $r): ?>
            <div class="px-5 py-3 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="w-6 h-6 rounded-full bg-cyan-100 flex items-center justify-center text-[10px] font-bold text-cyan-700 flex-shrink-0"><?= e(avatarInitials($r['student_name'])) ?></div>
                    <div class="min-w-0"><p class="text-xs font-medium text-slate-700 truncate"><?= e($r['student_name']) ?></p><p class="text-[10px] text-slate-400 truncate"><?= e($r['course_name']) ?> — Sec <?= e($r['section']) ?></p></div>
                </div>
                <span class="text-[10px] text-slate-400 flex-shrink-0"><?= formatDate($r['submitted_at']) ?></span>
            </div>
            <?php endforeach ?>
        </div>
        <?php else: ?>
        <div class="text-center py-10 text-slate-400 text-xs">No submissions yet.</div>
        <?php endif ?>
    </div>

    <!-- Student Affairs -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-purple-50">
            <div class="flex items-center gap-2"><?= iconSvg('shield','w-4 h-4 text-purple-600') ?><h3 class="text-sm font-semibold text-purple-800">Student Affairs</h3></div>
            <a href="/studentfeedback/admin/sa_results.php" class="text-xs text-purple-600 hover:underline">View →</a>
        </div>
        <?php if ($recentSA): ?>
        <div class="divide-y divide-slate-100">
            <?php foreach ($recentSA as $r): ?>
            <div class="px-5 py-3 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="w-6 h-6 rounded-full bg-purple-100 flex items-center justify-center text-[10px] font-bold text-purple-700 flex-shrink-0"><?= e(avatarInitials($r['student_name'])) ?></div>
                    <div class="min-w-0"><p class="text-xs font-medium text-slate-700 truncate"><?= e($r['student_name']) ?></p><p class="text-[10px] text-slate-400 truncate"><?= e($r['form_title']) ?></p></div>
                </div>
                <span class="text-[10px] text-slate-400 flex-shrink-0"><?= formatDate($r['submitted_at']) ?></span>
            </div>
            <?php endforeach ?>
        </div>
        <?php else: ?>
        <div class="text-center py-10 text-slate-400 text-xs">No SA submissions yet.</div>
        <?php endif ?>
    </div>

    <!-- Administration -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
        <div class="flex items-center justify-between px-5 py-4 border-b border-slate-100 bg-orange-50">
            <div class="flex items-center gap-2"><?= iconSvg('office','w-4 h-4 text-orange-600') ?><h3 class="text-sm font-semibold text-orange-800">Administration</h3></div>
            <a href="/studentfeedback/admin/adm_results.php" class="text-xs text-orange-600 hover:underline">View →</a>
        </div>
        <?php if ($recentAdm): ?>
        <div class="divide-y divide-slate-100">
            <?php foreach ($recentAdm as $r): ?>
            <div class="px-5 py-3 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 min-w-0">
                    <div class="w-6 h-6 rounded-full bg-orange-100 flex items-center justify-center text-[10px] font-bold text-orange-700 flex-shrink-0"><?= e(avatarInitials($r['student_name'])) ?></div>
                    <div class="min-w-0"><p class="text-xs font-medium text-slate-700 truncate"><?= e($r['student_name']) ?></p><p class="text-[10px] text-slate-400 truncate"><?= e($r['form_title']) ?></p></div>
                </div>
                <span class="text-[10px] text-slate-400 flex-shrink-0"><?= formatDate($r['submitted_at']) ?></span>
            </div>
            <?php endforeach ?>
        </div>
        <?php else: ?>
        <div class="text-center py-10 text-slate-400 text-xs">No Adm submissions yet.</div>
        <?php endif ?>
    </div>

</div>

<!-- Quick Links -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
    <h3 class="text-sm font-semibold text-slate-800 mb-4">Quick Actions</h3>
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
        <?php
        $quickLinks = [
            ['href' => '/studentfeedback/admin/students.php',          'icon' => 'users',    'label' => 'Students',     'color' => 'blue'],
            ['href' => '/studentfeedback/admin/sections.php',          'icon' => 'grid',     'label' => 'Sections',     'color' => 'teal'],
            ['href' => '/studentfeedback/admin/section_assignments.php','icon' => 'link',     'label' => 'Assign',       'color' => 'cyan'],
            ['href' => '/studentfeedback/admin/feedback_forms.php',    'icon' => 'document', 'label' => 'Acad Forms',   'color' => 'green'],
            ['href' => '/studentfeedback/admin/sa_forms.php',          'icon' => 'shield',   'label' => 'SA Forms',     'color' => 'purple'],
            ['href' => '/studentfeedback/admin/adm_forms.php',         'icon' => 'office',   'label' => 'Adm Forms',    'color' => 'orange'],
        ];
        foreach ($quickLinks as $ql):
            $c = $colorMap[$ql['color']];
        ?>
        <a href="<?= $ql['href'] ?>" class="flex flex-col items-center gap-2 p-3 rounded-xl <?= $c['bg'] ?> hover:opacity-90 transition-opacity text-center">
            <div class="w-9 h-9 rounded-xl <?= $c['icon'] ?> flex items-center justify-center shadow-sm"><?= iconSvg($ql['icon'],'w-4.5 h-4.5 text-white') ?></div>
            <span class="text-xs font-medium <?= $c['text'] ?>"><?= $ql['label'] ?></span>
        </a>
        <?php endforeach ?>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
