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
    ['label' => 'Total Students',     'value' => count_table($conn,'students'),                    'icon' => 'users',    'color' => 'blue',   'href' => '/studentfeedback/admin/students.php'],
    ['label' => 'Total Teachers',     'value' => count_table($conn,'teachers'),                    'icon' => 'user',     'color' => 'indigo', 'href' => '/studentfeedback/admin/teachers.php'],
    ['label' => 'Departments',        'value' => count_table($conn,'departments'),                 'icon' => 'building', 'color' => 'violet', 'href' => '/studentfeedback/admin/departments.php'],
    ['label' => 'Section Assignments','value' => count_table($conn,'section_assignments'),         'icon' => 'link',     'color' => 'cyan',   'href' => '/studentfeedback/admin/section_assignments.php'],
    ['label' => 'Courses',            'value' => count_table($conn,'courses'),                     'icon' => 'book',     'color' => 'teal',   'href' => '/studentfeedback/admin/courses.php'],
    ['label' => 'Sections',           'value' => count_table($conn,'sections'),                    'icon' => 'grid',     'color' => 'emerald','href' => '/studentfeedback/admin/sections.php'],
];

$colorMap = [
    'blue'    => ['bg' => 'bg-cyan-50',    'icon' => 'bg-cyan-600',    'text' => 'text-cyan-700'],
    'indigo'  => ['bg' => 'bg-cyan-50',    'icon' => 'bg-cyan-600',    'text' => 'text-cyan-700'],
    'violet'  => ['bg' => 'bg-violet-50',  'icon' => 'bg-violet-600',  'text' => 'text-violet-700'],
    'cyan'    => ['bg' => 'bg-cyan-50',    'icon' => 'bg-cyan-600',    'text' => 'text-cyan-700'],
    'teal'    => ['bg' => 'bg-cyan-50',    'icon' => 'bg-cyan-600',    'text' => 'text-cyan-700'],
    'emerald' => ['bg' => 'bg-emerald-50', 'icon' => 'bg-emerald-600', 'text' => 'text-emerald-700'],
];

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<!-- Page Header -->
<div class="mb-6">
    <h2 class="text-2xl font-bold text-slate-800">Welcome back, <?= e(explode(' ', getCurrentUser()['name'])[0]) ?> 👋</h2>
    <p class="text-sm text-slate-500 mt-1">University Feedback Management System — Full Overview</p>
</div>

<!-- Stats Grid -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
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

<!-- Quick Links -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5">
    <h3 class="text-sm font-semibold text-slate-800 mb-4">Quick Actions</h3>
    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
        <?php
        $quickLinks = [
            ['href' => '/studentfeedback/admin/students.php',            'icon' => 'users',    'label' => 'Students',     'color' => 'blue'],
            ['href' => '/studentfeedback/admin/sections.php',            'icon' => 'grid',     'label' => 'Sections',     'color' => 'teal'],
            ['href' => '/studentfeedback/admin/section_assignments.php', 'icon' => 'link',     'label' => 'Assign',       'color' => 'cyan'],
            ['href' => '/studentfeedback/admin/feedback_forms.php',      'icon' => 'document', 'label' => 'Acad Forms',   'color' => 'green'],
            ['href' => '/studentfeedback/admin/sa_forms.php',            'icon' => 'shield',   'label' => 'SA Forms',     'color' => 'purple'],
            ['href' => '/studentfeedback/admin/adm_forms.php',           'icon' => 'office',   'label' => 'Adm Forms',    'color' => 'orange'],
        ];
        foreach ($quickLinks as $ql):
            $c = $colorMap[$ql['color']] ?? ['bg' => 'bg-slate-50', 'icon' => 'bg-slate-600', 'text' => 'text-slate-700'];
        ?>
        <a href="<?= $ql['href'] ?>" class="flex flex-col items-center gap-2 p-3 rounded-xl <?= $c['bg'] ?> hover:opacity-90 transition-opacity text-center">
            <div class="w-9 h-9 rounded-xl <?= $c['icon'] ?> flex items-center justify-center shadow-sm"><?= iconSvg($ql['icon'],'w-4.5 h-4.5 text-white') ?></div>
            <span class="text-xs font-medium <?= $c['text'] ?>"><?= $ql['label'] ?></span>
        </a>
        <?php endforeach ?>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
