<?php
$academicKeys = ['departments', 'teachers', 'students', 'courses', 'sections', 'assignments'];
$isAcademicActive = in_array($activeMenu, $academicKeys);

$academicFeedbackKeys = ['forms', 'questions', 'results'];
$isAcademicFeedbackActive = in_array($activeMenu, $academicFeedbackKeys);

$saKeys = ['sa_forms', 'sa_questions', 'sa_results'];
$isSaActive = in_array($activeMenu, $saKeys);

$admKeys = ['adm_forms', 'adm_questions', 'adm_results'];
$isAdmActive = in_array($activeMenu, $admKeys);

$nav = [
    ['label' => 'Dashboard', 'href' => '/studentfeedback/admin/index.php', 'key' => 'dashboard', 'icon' => 'home'],
    ['label' => 'Reports & Analytics', 'href' => '/studentfeedback/admin/reports.php', 'key' => 'reports', 'icon' => 'chart'],
    ['label' => 'User Management', 'type' => 'group', 'key' => 'user_management', 'isOpen' => in_array($activeMenu, ['users'])],
    ['label' => 'Users', 'href' => '/studentfeedback/admin/users.php', 'key' => 'users', 'icon' => 'users', 'indent' => true, 'group' => 'user_management'],
    ['label' => 'Academic Management', 'type' => 'group', 'key' => 'academic', 'isOpen' => $isAcademicActive],
    ['label' => 'Departments', 'href' => '/studentfeedback/admin/departments.php', 'key' => 'departments', 'icon' => 'building', 'indent' => true, 'group' => 'academic'],
    ['label' => 'Students', 'href' => '/studentfeedback/admin/students.php', 'key' => 'students', 'icon' => 'users', 'indent' => true, 'group' => 'academic'],
    ['label' => 'Teachers', 'href' => '/studentfeedback/admin/teachers.php', 'key' => 'teachers', 'icon' => 'user', 'indent' => true, 'group' => 'academic'],
    ['label' => 'Courses', 'href' => '/studentfeedback/admin/courses.php', 'key' => 'courses', 'icon' => 'book', 'indent' => true, 'group' => 'academic'],
    ['label' => 'Sections', 'href' => '/studentfeedback/admin/sections.php', 'key' => 'sections', 'icon' => 'grid', 'indent' => true, 'group' => 'academic'],
    ['label' => 'Assignments', 'href' => '/studentfeedback/admin/section_assignments.php', 'key' => 'assignments', 'icon' => 'link', 'indent' => true, 'group' => 'academic'],
    ['label' => 'Academic Feedbacks', 'type' => 'group', 'key' => 'academic_feedback', 'isOpen' => $isAcademicFeedbackActive],
    ['label' => 'Forms', 'href' => '/studentfeedback/admin/feedback_forms.php', 'key' => 'forms', 'icon' => 'document', 'indent' => true, 'group' => 'academic_feedback'],
    ['label' => 'Questions', 'href' => '/studentfeedback/admin/feedback_questions.php', 'key' => 'questions', 'icon' => 'question', 'indent' => true, 'group' => 'academic_feedback'],
    ['label' => 'Results', 'href' => '/studentfeedback/admin/feedback_results.php', 'key' => 'results', 'icon' => 'chart', 'indent' => true, 'group' => 'academic_feedback'],
    ['label' => 'Student Affairs', 'type' => 'group', 'key' => 'student_affairs', 'isOpen' => $isSaActive],
    ['label' => 'SA Forms', 'href' => '/studentfeedback/admin/sa_forms.php', 'key' => 'sa_forms', 'icon' => 'shield', 'indent' => true, 'group' => 'student_affairs'],
    ['label' => 'SA Questions', 'href' => '/studentfeedback/admin/sa_questions.php', 'key' => 'sa_questions', 'icon' => 'question', 'indent' => true, 'group' => 'student_affairs'],
    ['label' => 'SA Results', 'href' => '/studentfeedback/admin/sa_results.php', 'key' => 'sa_results', 'icon' => 'chart', 'indent' => true, 'group' => 'student_affairs'],
    ['label' => 'Administration', 'type' => 'group', 'key' => 'administration', 'isOpen' => $isAdmActive],
    ['label' => 'Adm Forms', 'href' => '/studentfeedback/admin/adm_forms.php', 'key' => 'adm_forms', 'icon' => 'office', 'indent' => true, 'group' => 'administration'],
    ['label' => 'Adm Questions', 'href' => '/studentfeedback/admin/adm_questions.php', 'key' => 'adm_questions', 'icon' => 'question', 'indent' => true, 'group' => 'administration'],
    ['label' => 'Adm Results', 'href' => '/studentfeedback/admin/adm_results.php', 'key' => 'adm_results', 'icon' => 'chart', 'indent' => true, 'group' => 'administration'],
];
?>

<!-- ─── Sidebar ─────────────────────────────────────────────── -->
<aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40
           transform -translate-x-full transition-transform duration-300 ease-in-out
           lg:relative lg:translate-x-0 lg:flex-shrink-0">

    <!-- Brand -->
    <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
        <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shadow-lg flex-shrink-0">
            <?= iconSvg('academic', 'w-5 h-5 text-white') ?>
        </div>
        <div>
            <p class="text-sm font-bold leading-tight">SFMS Admin</p>
            <p class="text-[10px] text-cyan-100 leading-tight">Feedback Management</p>
        </div>
        <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-200 hover:text-white">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="w-5 h-5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <!-- Nav -->
    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-0.5 scrollbar-thin">
        <?php $groupActive = false; ?>
        <?php foreach ($nav as $item): ?>
            <?php if (($item['type'] ?? '') === 'section'): ?>
                <?php if ($groupActive): ?></div><?php $groupActive = false; endif; ?>
                <p class="px-3 pt-4 pb-1 text-[10px] font-semibold uppercase tracking-widest text-cyan-200">
                    <?= e($item['label']) ?>
                </p>
            <?php elseif (($item['type'] ?? '') === 'group'): ?>
                <?php if ($groupActive): ?></div><?php $groupActive = false; endif; ?>
                <?php $groupKey = $item['key']; ?>
                <div class="px-3 pt-4 pb-0">
                    <button onclick="toggleGroup('<?= $groupKey ?>')"
                        class="w-full flex items-center justify-between pb-1 text-[12px] font-semibold uppercase tracking-widest text-cyan-200 hover:text-white transition-colors border-b border-cyan-200/30">
                        <span><?= e($item['label']) ?></span>
                        <svg id="chevron-<?= $groupKey ?>" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                            stroke-width="2" stroke="currentColor"
                            class="w-3 h-3 transition-transform duration-200 <?= $item['isOpen'] ? 'rotate-90' : '' ?>">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                        </svg>
                    </button>
                </div>
                <div id="group-<?= $groupKey ?>" class="<?= $item['isOpen'] ? '' : 'hidden' ?>">
                    <?php $groupActive = true; ?>
                <?php else:
                $isActive = ($activeMenu === $item['key']);
                $indent = ($item['indent'] ?? false) ? 'pl-4' : 'pl-3';
                $activeCs = $isActive
                    ? 'bg-white/20 text-white font-semibold'
                    : 'text-cyan-100 hover:bg-white/10 hover:text-white';
                ?>
                    <a href="<?= $item['href'] ?>"
                        class="flex items-center gap-3 <?= $indent ?> pr-3 py-2.5 rounded-xl text-sm transition-all duration-150 <?= $activeCs ?>">
                        <?= iconSvg($item['icon'], 'w-4 h-4 flex-shrink-0') ?>
                        <?= e($item['label']) ?>
                        <?php if ($isActive): ?>
                            <span class="ml-auto w-1.5 h-1.5 rounded-full bg-white"></span>
                        <?php endif ?>
                    </a>
                <?php endif ?>
            <?php endforeach ?>
            <?php if ($groupActive): ?>
            </div><?php endif; ?>
    </nav>

    <!-- User Footer -->
    <div class="border-t border-cyan-500 px-4 py-4">
        <div class="flex items-center gap-3">
            <div
                class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold text-white flex-shrink-0">
                <?= e($initials) ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-white truncate"><?= e($user['name']) ?></p>
                <p class="text-[10px] text-cyan-100 truncate"><?= e($user['email']) ?></p>
            </div>
            <a href="/studentfeedback/auth/logout.php" title="Logout"
                class="text-cyan-200 hover:text-red-300 transition-colors">
                <?= iconSvg('logout', 'w-4 h-4') ?>
            </a>
        </div>
    </div>
</aside>

<script>
    function toggleGroup(key) {
        var el = document.getElementById('group-' + key);
        var chevron = document.getElementById('chevron-' + key);
        if (el) {
            el.classList.toggle('hidden');
            if (chevron) chevron.classList.toggle('rotate-90');
        }
    }
</script>

<!-- ─── Main Column ──────────────────────────────────────────── -->
<div class="flex-1 flex flex-col min-w-0 overflow-hidden">

    <!-- Top Navbar -->
    <header
        class="bg-white border-b border-slate-200 px-4 lg:px-6 py-3.5 flex items-center gap-4 flex-shrink-0 sticky top-0 z-20 shadow-sm">
        <!-- Hamburger -->
        <button onclick="openSidebar()" class="lg:hidden text-slate-500 hover:text-slate-800">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2"
                stroke="currentColor" class="w-6 h-6">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
            </svg>
        </button>

        <!-- Page Title -->
        <div>
            <h1 class="text-base font-semibold text-slate-800"><?= e($pageTitle) ?></h1>
        </div>

        <div class="ml-auto flex items-center gap-3">
            <!-- Profile -->
            <a href="/studentfeedback/admin/profile.php"
                class="flex items-center gap-2 px-3 py-1.5 rounded-xl hover:bg-slate-50 transition-colors">
                <div
                    class="w-7 h-7 rounded-full bg-cyan-600 flex items-center justify-center text-xs font-bold text-white">
                    <?= e($initials) ?>
                </div>
                <span class="hidden md:block text-sm font-medium text-slate-700"><?= e($user['name']) ?></span>
            </a>
        </div>
    </header>

    <!-- Page Content -->
    <main class="flex-1 overflow-y-auto p-4 lg:p-6">