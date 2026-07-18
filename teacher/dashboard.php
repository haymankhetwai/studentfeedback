<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Prevent direct URL access — must come through index.php portal flow
if (!isset($_SESSION['entry_allowed']) || $_SESSION['selected_role'] !== 'teacher') {
    header('Location: /studentfeedbackucsh/index.php');
    exit;
}

requireRole('teacher');

updateAllFeedbackStatuses($conn);

$pageTitle = 'Teacher Dashboard';
$activeMenu = 'dashboard';
$user = getCurrentUser();

// Get teacher record
$stmt = $conn->prepare("SELECT t.id FROM teachers t WHERE t.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();
$teacherId = $teacher['id'] ?? 0;

$sectionCount = $teacherId ? (int) $conn->query("SELECT COUNT(*) AS c FROM sections WHERE teacher_id=$teacherId")->fetch_assoc()['c'] : 0;
$formCount = $teacherId ? (int) $conn->query("SELECT COUNT(*) AS c FROM feedback_forms ff JOIN sections s ON ff.section_id=s.id WHERE s.teacher_id=$teacherId AND ff.start_date<=NOW() AND ff.end_date>=NOW()")->fetch_assoc()['c'] : 0;
$submissionCount = $teacherId ? (int) $conn->query("SELECT COUNT(*) AS c FROM feedback_submissions fs JOIN feedback_forms ff ON fs.form_id=ff.id JOIN sections s ON ff.section_id=s.id WHERE s.teacher_id=$teacherId")->fetch_assoc()['c'] : 0;

// My sections
$sections = [];
if ($teacherId) {
    $rs = $conn->query("SELECT s.*, c.course_name, c.course_code, COALESCE(ay.year_name, s.academic_year) AS display_year, sm.semester_name AS display_semester, (SELECT COUNT(*) FROM section_assignments sa WHERE sa.section_id=s.id) AS student_count, (SELECT COUNT(*) FROM feedback_forms ff WHERE ff.section_id=s.id AND ff.start_date<=NOW() AND ff.end_date>=NOW()) AS active_forms FROM sections s JOIN courses c ON s.course_id=c.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id WHERE s.teacher_id=$teacherId ORDER BY s.id DESC LIMIT 5");
    $sections = $rs->fetch_all(MYSQLI_ASSOC);
}

// Teacher header/sidebar will use the same includes but with teacher_ variables
// We reuse admin includes — just change the sidebar nav
?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { inter: ['Inter', 'sans-serif'] } } } }</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/studentfeedbackucsh/assets/css/custom.css">
</head>
<body class="h-full bg-gradient-to-br from-slate-50 via-blue-50 to-sky-50 font-inter antialiased <?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'lang-mm' : '' ?>">
<?php require_once '../includes/teacher_sidebar.php'; ?>

                <!-- Content -->
                <div class="mb-6">
                    <h2 class="text-2xl font-bold text-slate-800"><?= $LANG['teacher_welcome'] ?? 'Welcome to' ?>, <?= e($user['name']) ?> 👋
                    </h2>
                    <p class="text-sm text-slate-500 mt-1"><?= $LANG['teacher_overview'] ?? "Here's your teaching overview." ?></p>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
                    <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-blue-700 flex items-center justify-center shadow">
                            <?= iconSvg('grid', 'w-6 h-6 text-white') ?></div>
                        <div>
                            <p class="text-2xl font-bold text-blue-800"><?= $sectionCount ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['my_sections_stat'] ?? 'My Sections' ?></p>
                        </div>
                    </div>
                    <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-emerald-100/50 p-5 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-emerald-600 flex items-center justify-center shadow">
                            <?= iconSvg('document', 'w-6 h-6 text-white') ?></div>
                        <div>
                            <p class="text-2xl font-bold text-emerald-700"><?= $formCount ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['active_forms_stat'] ?? 'Active Forms' ?></p>
                        </div>
                    </div>
                    <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 p-5 flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-blue-700 flex items-center justify-center shadow">
                            <?= iconSvg('check', 'w-6 h-6 text-white') ?></div>
                        <div>
                            <p class="text-2xl font-bold text-blue-800"><?= $submissionCount ?></p>
                            <p class="text-xs text-slate-500"><?= $LANG['total_submissions_stat'] ?? 'Total Submissions' ?></p>
                        </div>
                    </div>
                </div>

                <!-- My Sections Preview -->
                <div class="bg-white/90 backdrop-blur-sm rounded-2xl shadow-sm border border-blue-100/50 overflow-hidden">
                    <div class="flex items-center justify-between px-6 py-4 border-b border-blue-100/50">
                        <h3 class="text-base font-semibold text-slate-800"><?= $LANG['my_sections_title'] ?? 'My Sections' ?></h3>
                        <a href="/studentfeedbackucsh/teacher/my_sections.php"
                            class="text-xs text-blue-600 hover:underline font-medium"><?= $LANG['view_all'] ?? 'View All' ?> →</a>
                    </div>
                    <?php if ($sections): ?>
                        <div class="divide-y divide-blue-100/50">
                            <?php foreach ($sections as $s): ?>
                                <div class="px-6 py-4 flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-slate-800"><?= e($s['course_name']) ?></p>
                                        <p class="text-xs text-slate-400"><?= e(semesterToRoman($s['display_semester'])) ?> · Sec <?= e($s['section']) ?>
                                            · <?= e($s['display_year']) ?></p>
                                    </div>
                                    <div class="flex items-center gap-4 text-right">
                                        <div>
                                            <p class="text-sm font-bold text-slate-700"><?= $s['student_count'] ?></p>
                                            <p class="text-xs text-slate-400"><?= $LANG['students_label'] ?? 'Students' ?></p>
                                        </div>
                                        <div>
                                            <p class="text-sm font-bold text-emerald-600"><?= $s['active_forms'] ?></p>
                                            <p class="text-xs text-slate-400"><?= $LANG['forms'] ?? 'Forms' ?></p>
                                        </div>
                                        <a href="/studentfeedbackucsh/teacher/feedback_results.php?section_id=<?= $s['id'] ?>"
                                            class="text-xs text-blue-600 hover:underline font-medium"><?= $LANG['results_link'] ?? 'Results' ?></a>
                                    </div>
                                </div>
                            <?php endforeach ?>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-12 text-slate-400">
                            <?= iconSvg('grid', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm"><?= $LANG['no_sections_assigned'] ?? 'No sections assigned yet.' ?></p>
                        </div>
                    <?php endif ?>
                </div>

<?php require_once '../includes/teacher_footer.php'; ?>
