<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['sections_title'] ?? 'Sections';
$activeMenu = 'sections';

$courseList = $conn->query("SELECT c.id, c.course_code, c.course_name FROM courses c ORDER BY c.course_name")->fetch_all(MYSQLI_ASSOC);
$teacherList = $conn->query("SELECT t.id, u.name FROM teachers t JOIN users u ON t.user_id=u.id ORDER BY u.name")->fetch_all(MYSQLI_ASSOC);
$academicYears = $conn->query("SELECT id, year_name FROM academic_years WHERE status='active' ORDER BY year_name DESC")->fetch_all(MYSQLI_ASSOC);
$semesterList = $conn->query("SELECT id, semester_name FROM semesters ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $course = (int) ($_POST['course_id'] ?? 0);
        $teacher = (int) ($_POST['teacher_id'] ?? 0);
        $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $section = clean($_POST['section'] ?? '');
        if ($course && $teacher && $academicYearId && $semesterId && $section) {
            $stmt = $conn->prepare("INSERT INTO sections (course_id,teacher_id,academic_year_id,semester_id,section) VALUES (?,?,?,?,?)");
            $stmt->bind_param('iiiis', $course, $teacher, $academicYearId, $semesterId, $section);
            $stmt->execute() ? setFlash('success', $LANG['flash_section_added'] ?? 'Section added.') : setFlash('error', $LANG['flash_section_add_failed'] ?? 'Failed to add section.');
            $stmt->close();
        } else {
            setFlash('error', $LANG['flash_all_fields_required'] ?? 'All fields required.');
        }
    }
    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $course = (int) ($_POST['course_id'] ?? 0);
        $teacher = (int) ($_POST['teacher_id'] ?? 0);
        $academicYearId = (int) ($_POST['academic_year_id'] ?? 0);
        $semesterId = (int) ($_POST['semester_id'] ?? 0);
        $sec = clean($_POST['section'] ?? '');
        if ($id && $course && $teacher && $academicYearId && $semesterId && $sec) {
            $stmt = $conn->prepare("UPDATE sections SET course_id=?,teacher_id=?,academic_year_id=?,semester_id=?,section=? WHERE id=?");
            $stmt->bind_param('iiiisi', $course, $teacher, $academicYearId, $semesterId, $sec, $id);
            $stmt->execute() ? setFlash('success', $LANG['flash_section_updated'] ?? 'Section updated.') : setFlash('error', $LANG['flash_update_failed'] ?? 'Update failed.');
            $stmt->close();
        }
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM sections WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute() ? setFlash('success', $LANG['flash_section_deleted'] ?? 'Section deleted.') : setFlash('error', $LANG['flash_has_assignments'] ?? 'Cannot delete (has assignments).');
            $stmt->close();
        }
    }
    header('Location: sections.php');
    exit;
}

$search = clean($_GET['search'] ?? '');
$filterYear = (int) ($_GET['academic_year_id'] ?? 0);
$filterSem = (int) ($_GET['semester_id'] ?? 0);
$filterSec = clean($_GET['section'] ?? '');
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 10)));
$page = max(1, (int) ($_GET['page'] ?? 1));

$baseJoin = "FROM sections s JOIN courses c2 ON s.course_id=c2.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id LEFT JOIN semesters sm ON s.semester_id=sm.id";

$conditions = [];
$params = [];
$types = '';

if ($search) {
    $conditions[] = "(c2.course_name LIKE ? OR s.section LIKE ? OR u.name LIKE ? OR ay.year_name LIKE ? OR sm.semester_name LIKE ?)";
    $s2 = "%$search%";
    $params = array_merge($params, [$s2, $s2, $s2, $s2, $s2]);
    $types .= 'sssss';
}
if ($filterYear) {
    $conditions[] = "s.academic_year_id = ?";
    $params[] = $filterYear;
    $types .= 'i';
}
if ($filterSem) {
    $conditions[] = "s.semester_id = ?";
    $params[] = $filterSem;
    $types .= 'i';
}
if ($filterSec && preg_match('/^[A-Z]$/i', $filterSec)) {
    $conditions[] = "s.section = ?";
    $params[] = strtoupper($filterSec);
    $types .= 's';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$countSql = "SELECT COUNT(*) AS c $baseJoin $where";
$c = $conn->prepare($countSql);
if ($params) {
    $c->bind_param($types, ...$params);
}
$c->execute();
$total = (int) $c->get_result()->fetch_assoc()['c'];
$c->close();

$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];

$dataSql = "SELECT s.*, c2.course_name, c2.course_code, u.name AS teacher_name, ay.year_name, sm.semester_name $baseJoin $where ORDER BY s.id DESC LIMIT ? OFFSET ?";
$params[] = $perPage;
$params[] = $off;
$types .= 'ii';

$stmt = $conn->prepare($dataSql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$hasFilter = $search || $filterYear || $filterSem || $filterSec;
$buildQuery = function ($overrides = []) use ($search, $filterYear, $filterSem, $filterSec) {
    $params = array_merge(['search' => $search, 'academic_year_id' => $filterYear, 'semester_id' => $filterSem, 'section' => $filterSec], $overrides);
    $params = array_filter($params);
    return 'sections.php' . ($params ? '?' . http_build_query($params) : '');
};

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-800"><?= $LANG['sections_title'] ?? 'Sections' ?></h2>
        <p class="text-sm text-slate-500 mt-0.5">
            <?= $LANG['sections_subtitle'] ?? 'Manage course sections per semester' ?>
        </p>
    </div>
    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus', 'w-4 h-4') ?> <?= $LANG['add_section'] ?? 'Add Section' ?>
    </button>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1 flex-wrap">
            <div class="relative flex-1 min-w-[200px] max-w-xs"><span
                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search', 'w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>"
                    placeholder="<?= $LANG['search_sections'] ?? 'Search sections...' ?>"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>
            <select name="academic_year_id"
                class="px-3 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                <option value=""><?= $LANG["all_academic_years"] ?? "All Academic Years" ?></option>
                <?php foreach ($academicYears as $ay): ?>
                    <option value="<?= $ay['id'] ?>" <?= $filterYear == $ay['id'] ? ' selected' : '' ?>>
                        <?= e($ay['year_name']) ?></option>
                <?php endforeach ?>
            </select>
            <select name="semester_id"
                class="px-3 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                <option value=""><?= $LANG['all_semesters'] ?? 'All Semesters' ?></option>
                <?php foreach ($semesterList as $sm): ?>
                    <option value="<?= $sm['id'] ?>" <?= $filterSem == $sm['id'] ? ' selected' : '' ?>>
                        <?= e(semesterToRoman($sm['semester_name'])) ?></option>
                <?php endforeach ?>
            </select>
            <select name="section"
                class="px-3 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                <option value=""><?= $LANG['all_sections'] ?? 'All Sections' ?></option>
                <?php foreach (['A', 'B', 'C'] as $secLetter): ?>
                    <option value="<?= $secLetter ?>" <?= strtoupper($filterSec) === $secLetter ? ' selected' : '' ?>>
                        <?= $LANG['section_label'] ?? 'Section' ?>
                        <?= $secLetter ?>
                    </option>
                <?php endforeach ?>
            </select>
            <button type="submit"
                class="px-3 py-2 text-sm bg-indigo-600 text-white rounded-xl hover:bg-indigo-700"><?= $LANG['search'] ?? 'Search' ?></button>
            <?php if ($hasFilter): ?><a href="sections.php"
                    class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-white hover:bg-red-700 bg-red-500"><?= $LANG['clear'] ?? 'Clear' ?></a><?php endif ?>
        </form>
        <span class="text-xs text-slate-400"><?= $total ?>
            <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span>
    </div>
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['select_course'] ?? 'Course' ?></th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['section_name'] ?? 'Section' ?></th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['select_teacher'] ?? 'Teacher' ?></th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['year_semester'] ?? 'Year / Semester' ?>
                    </th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">
                        <?= $LANG['col_actions'] ?? 'Actions' ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows):
                    foreach ($rows as $i => $row): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-3">
                                <p class="text-sm font-medium text-slate-800"><?= e($row['course_name']) ?></p>
                                <p class="text-xs text-slate-400 font-mono"><?= e($row['course_code']) ?></p>
                            </td>
                            <td class="px-5 py-3"><span
                                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-cyan-100 text-cyan-800"><?= e($row['section']) ?></span>
                            </td>
                            <td class="px-5 py-3 text-sm text-slate-600"><?= e($row['teacher_name']) ?></td>
                            <td class="px-5 py-3">
                                <p class="text-sm text-slate-700"><?= e($row['year_name'] ?? '') ?></p>
                                <p class="text-xs text-slate-400"><?= e(semesterToRoman($row['semester_name'] ?? '')) ?></p>
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button onclick="openEdit(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?>         <?= $LANG['edit'] ?? 'Edit' ?>
                                    </button>
                                    <button
                                        onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['course_name'] . ' - ' . $row['section'])) ?>')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                        <?= iconSvg('trash', 'w-3.5 h-3.5') ?>         <?= $LANG['delete'] ?? 'Delete' ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="6" class="text-center py-16 text-slate-400">
                            <?= iconSvg('grid', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm"><?= $LANG['no_sections_found'] ?? 'No sections found.' ?></p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100"><?= paginationLinks($pg, $buildQuery(), $perPage) ?></div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['add_section'] ?? 'Add Section' ?></h3>
            <button onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2"><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['select_course'] ?? 'Course' ?>
                        <span class="text-red-500">*</span></label>
                    <select name="course_id" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG['select_course'] ?? 'Select Course' ?></option>
                        <?php foreach ($courseList as $c): ?>
                            <option value="<?= $c['id'] ?>">[<?= e($c['course_code']) ?>] <?= e($c['course_name']) ?>
                            </option><?php endforeach ?>
                    </select>
                </div>
                <div class="col-span-2"><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['select_teacher'] ?? 'Teacher' ?>
                        <span class="text-red-500">*</span></label>
                    <select name="teacher_id" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG['select_teacher'] ?? 'Select Teacher' ?></option>
                        <?php foreach ($teacherList as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach ?>
                    </select>
                </div>
                <div><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['section_name'] ?? 'Section Name' ?>
                        <span class="text-red-500">*</span></label>
                    <select name="section" required
                        class="w-full px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="">
                            <?= $LANG['all_sections'] ?? 'All Sections' ?>
                        </option>
                        <?php foreach (['A', 'B', 'C'] as $secLetter): ?>
                            <option value="<?= $secLetter ?>">
                                <?= $LANG['section_label'] ?? 'Section' ?>
                                <?= $secLetter ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>


                <div><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['academic_year'] ?? 'Academic Year' ?>
                        <span class="text-red-500">*</span></label>
                    <select name="academic_year_id" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG['select_year'] ?? 'Select Academic Year' ?></option>
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>"><?= e($ay['year_name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="col-span-2"><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['semester'] ?? 'Semester' ?>
                        <span class="text-red-500">*</span></label>
                    <select name="semester_id" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG['select_semester'] ?? 'Select Semester' ?></option>
                        <?php foreach ($semesterList as $sm): ?>
                            <option value="<?= $sm['id'] ?>"><?= e(semesterToRoman($sm['semester_name'])) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold btn-cancel rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['add_section'] ?? 'Add Section' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['edit_section_modal'] ?? 'Edit Section' ?></h3>
            <button onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden"
                name="id" id="edit_id">
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2"><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['select_course'] ?? 'Course' ?></label>
                    <select name="course_id" id="edit_course" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <?php foreach ($courseList as $c): ?>
                            <option value="<?= $c['id'] ?>">[<?= e($c['course_code']) ?>] <?= e($c['course_name']) ?>
                            </option><?php endforeach ?>
                    </select>
                </div>
                <div class="col-span-2"><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['select_teacher'] ?? 'Teacher' ?></label>
                    <select name="teacher_id" id="edit_teacher" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <?php foreach ($teacherList as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach ?>
                    </select>
                </div>

                <div><label class="block text-sm font-medium text-slate-700 mb-1">
                        <?= $LANG['section_name'] ?? 'Section Name' ?>
                        <span class="text-red-500">*</span>
                    </label>
                    <select name="section" id="edit_section" required
                        class="w-full px-4 py-2.5 text-sm font-medium border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="">
                            <?= $LANG['all_sections'] ?? 'All Sections' ?>
                        </option>
                        <?php foreach (['A', 'B', 'C'] as $secLetter): ?>
                            <option value="<?= $secLetter ?>">
                                <?= $LANG['section_label'] ?? 'Section' ?>
                                <?= $secLetter ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>


                <div><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['academic_year'] ?? 'Academic Year' ?></label>
                    <select name="academic_year_id" id="edit_academic_year_id" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG['select_year'] ?? 'Select Academic Year' ?></option>
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>"><?= e($ay['year_name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div class="col-span-2"><label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['semester'] ?? 'Semester' ?></label>
                    <select name="semester_id" id="edit_semester_id" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG['select_semester'] ?? 'Select Semester' ?></option>
                        <?php foreach ($semesterList as $sm): ?>
                            <option value="<?= $sm['id'] ?>"><?= e(semesterToRoman($sm['semester_name'])) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold btn-cancel rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['save'] ?? 'Save' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                <?= iconSvg('trash', 'w-7 h-7 text-red-600') ?>
            </div>
            <h3 class="text-lg font-semibold text-slate-800"><?= $LANG['delete_section_modal'] ?? 'Delete Section' ?>
            </h3>
            <p class="text-sm text-slate-500 mt-2"><?= $LANG['delete'] ?? 'Delete' ?> <strong id="delete_name"
                    class="text-slate-700"></strong>?</p>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden"
                name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold btn-cancel rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG['delete'] ?? 'Delete' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEdit(row) {
        document.getElementById('edit_id').value = row.id;
        document.getElementById('edit_course').value = row.course_id;
        document.getElementById('edit_teacher').value = row.teacher_id;
        document.getElementById('edit_section').value = row.section;
        document.getElementById('edit_academic_year_id').value = row.academic_year_id || '';
        document.getElementById('edit_semester_id').value = row.semester_id || '';
        openModal('editModal');
    }
    function openDelete(id, name) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_name').textContent = name;
        openModal('deleteModal');
    }
</script>
<?php include '../includes/admin_footer.php'; ?>