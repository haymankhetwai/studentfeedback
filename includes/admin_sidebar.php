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
    ['label' => $LANG['nav_dashboard'] ?? 'Dashboard', 'href' => '/studentfeedbackucsh/admin/dashboard.php', 'key' => 'dashboard', 'icon' => 'home'],
    ['label' => $LANG['nav_user_management'] ?? 'User Management', 'type' => 'group', 'key' => 'user_management', 'isOpen' => in_array($activeMenu, ['users'])],
    ['label' => $LANG['nav_users'] ?? 'Users', 'href' => '/studentfeedbackucsh/admin/users.php', 'key' => 'users', 'icon' => 'users', 'indent' => true, 'group' => 'user_management'],
    ['label' => $LANG['nav_academic_management'] ?? 'Academic Management', 'type' => 'group', 'key' => 'academic', 'isOpen' => $isAcademicActive],
    ['label' => $LANG['nav_departments'] ?? 'Departments', 'href' => '/studentfeedbackucsh/admin/departments.php', 'key' => 'departments', 'icon' => 'building', 'indent' => true, 'group' => 'academic'],
    ['label' => $LANG['nav_students'] ?? 'Students', 'href' => '/studentfeedbackucsh/admin/students.php', 'key' => 'students', 'icon' => 'users', 'indent' => true, 'group' => 'academic'],
    ['label' => $LANG['nav_teachers'] ?? 'Teachers', 'href' => '/studentfeedbackucsh/admin/teachers.php', 'key' => 'teachers', 'icon' => 'user', 'indent' => true, 'group' => 'academic'],
    ['label' => $LANG['nav_courses'] ?? 'Courses', 'href' => '/studentfeedbackucsh/admin/courses.php', 'key' => 'courses', 'icon' => 'book', 'indent' => true, 'group' => 'academic'],
    ['label' => $LANG['nav_sections'] ?? 'Sections', 'href' => '/studentfeedbackucsh/admin/sections.php', 'key' => 'sections', 'icon' => 'grid', 'indent' => true, 'group' => 'academic'],
    ['label' => $LANG['nav_assignments'] ?? 'Assignments', 'href' => '/studentfeedbackucsh/admin/section_assignments.php', 'key' => 'assignments', 'icon' => 'link', 'indent' => true, 'group' => 'academic'],
    ['label' => $LANG['nav_academic_feedbacks'] ?? 'Academic Feedbacks', 'type' => 'group', 'key' => 'academic_feedback', 'isOpen' => $isAcademicFeedbackActive],
    ['label' => $LANG['nav_forms'] ?? 'Forms', 'href' => '/studentfeedbackucsh/admin/feedback_forms.php', 'key' => 'forms', 'icon' => 'document', 'indent' => true, 'group' => 'academic_feedback'],
    ['label' => $LANG['nav_questions'] ?? 'Questions', 'href' => '/studentfeedbackucsh/admin/feedback_questions.php', 'key' => 'questions', 'icon' => 'question', 'indent' => true, 'group' => 'academic_feedback'],
    ['label' => $LANG['nav_results'] ?? 'Results', 'href' => '/studentfeedbackucsh/admin/feedback_results.php', 'key' => 'results', 'icon' => 'chart', 'indent' => true, 'group' => 'academic_feedback'],
    ['label' => $LANG['nav_student_affairs'] ?? 'Student Affairs', 'type' => 'group', 'key' => 'student_affairs', 'isOpen' => $isSaActive],
    ['label' => $LANG['nav_sa_forms'] ?? 'Student Affairs Forms', 'href' => '/studentfeedbackucsh/admin/sa_forms.php', 'key' => 'sa_forms', 'icon' => 'shield', 'indent' => true, 'group' => 'student_affairs'],
    ['label' => $LANG['nav_sa_questions'] ?? 'Student Affairs Questions', 'href' => '/studentfeedbackucsh/admin/sa_questions.php', 'key' => 'sa_questions', 'icon' => 'question', 'indent' => true, 'group' => 'student_affairs'],
    ['label' => $LANG['nav_sa_results'] ?? 'Student Affairs Results', 'href' => '/studentfeedbackucsh/admin/sa_results.php', 'key' => 'sa_results', 'icon' => 'chart', 'indent' => true, 'group' => 'student_affairs'],
    ['label' => $LANG['nav_administration'] ?? 'Administration', 'type' => 'group', 'key' => 'administration', 'isOpen' => $isAdmActive],
    ['label' => $LANG['nav_adm_forms'] ?? 'Administration Forms', 'href' => '/studentfeedbackucsh/admin/adm_forms.php', 'key' => 'adm_forms', 'icon' => 'office', 'indent' => true, 'group' => 'administration'],
    ['label' => $LANG['nav_adm_questions'] ?? 'Administration Questions', 'href' => '/studentfeedbackucsh/admin/adm_questions.php', 'key' => 'adm_questions', 'icon' => 'question', 'indent' => true, 'group' => 'administration'],
    ['label' => $LANG['nav_adm_results'] ?? 'Administration Results', 'href' => '/studentfeedbackucsh/admin/adm_results.php', 'key' => 'adm_results', 'icon' => 'chart', 'indent' => true, 'group' => 'administration'],
];
?>

<!-- ─── Sidebar ─────────────────────────────────────────────── -->
<aside id="sidebar" class="fixed inset-y-0 left-0 w-64 text-white flex flex-col z-40
           transform -translate-x-full transition-transform duration-300 ease-in-out
           lg:relative lg:translate-x-0 lg:flex-shrink-0"
    style="background: linear-gradient(180deg, #6366F1, #818CF8);">

    <!-- Brand -->
    <div class="flex items-center gap-3 px-5 py-5 border-b border-white/15">
        <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shadow-lg flex-shrink-0">
            <?= iconSvg('academic', 'w-5 h-5 text-white') ?>
        </div>
        <div>
            <p class="text-sm font-bold leading-tight"><?= $LANG['admin_portal'] ?? 'SFMS Admin' ?></p>
            <p class="text-[12px] text-white/70 leading-tight"><?= $LANG['admin_portal_sub'] ?? 'Feedback Management' ?>
            </p>
        </div>
        <button onclick="closeSidebar()" class="ml-auto lg:hidden text-white/60 hover:text-white">
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
                <p class="px-3 pt-4 pb-1 text-[12px] font-semibold uppercase tracking-widest text-white/60">
                    <?= e($item['label']) ?>
                </p>
            <?php elseif (($item['type'] ?? '') === 'group'): ?>
                <?php if ($groupActive): ?></div><?php $groupActive = false; endif; ?>
                <?php $groupKey = $item['key']; ?>
                <div class="px-3 pt-4 pb-0">
                    <button onclick="toggleGroup('<?= $groupKey ?>')"
                        class="w-full flex items-center justify-between pb-4 text-[14px]  uppercase text-white/70 hover:text-white transition-colors border-b border-white/20">
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
                    : 'text-white/80 hover:bg-white/10 hover:text-white';
                ?>
                    <a href="<?= $item['href'] ?>"
                        class="flex items-center gap-3 <?= $indent ?> pr-3 py-2.5 rounded-xl text-[16px] transition-all duration-150 <?= $activeCs ?>">
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


    <a href="/studentfeedbackucsh/auth/logout.php" title="<?= $LANG['logout'] ?? 'Logout' ?>"
        class="block border-t border-white/15 bg-red-500 text-gray-50 hover:text-gray-200 transition-colors px-4 py-4 cursor-pointer">
        <div class="flex items-center gap-3">

            <div class="min-w-0 ">
                <p class="text-xl h-8"><?= $LANG['logout'] ?? 'Logout' ?></p>
            </div>
            <?= iconSvg('logout', 'w-6 h-6') ?>
        </div>
    </a>



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
            <!-- Language Switcher -->
            <?php $currentLang = $_SESSION['lang'] ?? 'en'; ?>
            <div
                class="flex items-center gap-0.5 bg-slate-100 rounded-lg p-0.5 text-xs font-semibold border border-slate-200 shadow-sm">
                <a href="?lang=en"
                    class="px-3 py-1 rounded-md transition-all <?= $currentLang === 'en' ? 'bg-white shadow text-indigo-700 font-bold' : 'text-slate-400 hover:text-slate-600' ?>">
                    ENG
                </a>
                <a href="?lang=mm"
                    class="px-3 py-1 rounded-md transition-all <?= $currentLang === 'mm' ? 'bg-white shadow text-indigo-700 font-bold' : 'text-slate-400 hover:text-slate-600' ?>">
                    မြန်မာ
                </a>
            </div>
            <!-- Profile -->
            <a href="/studentfeedbackucsh/admin/profile.php"
                class="flex items-center gap-2 px-3 py-1.5 rounded-xl hover:bg-slate-50 transition-colors">
                <div
                    class="w-7 h-7 rounded-full bg-indigo-600 flex items-center justify-center text-xs font-bold text-white">
                    <?= e($initials) ?>
                </div>
                <span class="hidden md:block text-sm font-medium text-slate-700"><?= e($user['name']) ?></span>
            </a>
        </div>
    </header>

    <!-- Page Content -->
    <main class="flex-1 overflow-y-auto p-4 lg:p-6">