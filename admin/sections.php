<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Sections';
$activeMenu = 'sections';

$courseList  = $conn->query("SELECT c.id, c.course_code, c.course_name FROM courses c ORDER BY c.course_name")->fetch_all(MYSQLI_ASSOC);
$teacherList = $conn->query("SELECT t.id, u.name, t.teacher_code FROM teachers t JOIN users u ON t.user_id=u.id ORDER BY u.name")->fetch_all(MYSQLI_ASSOC);
$semesters   = ['1st Semester','2nd Semester','3rd semester','4th semester', '5th semester', '6th semester', '7th semester', '8th semester', '9th semester', '10th semester'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $course   = (int)($_POST['course_id'] ?? 0);
        $teacher  = (int)($_POST['teacher_id'] ?? 0);
        $year     = clean($_POST['academic_year'] ?? '');
        $semester = clean($_POST['semester'] ?? '');
        $section  = clean($_POST['section'] ?? '');
        if ($course && $teacher && $year && $semester && $section) {
            $stmt = $conn->prepare("INSERT INTO sections (course_id,teacher_id,academic_year,semester,section) VALUES (?,?,?,?,?)");
            $stmt->bind_param('iisss',$course,$teacher,$year,$semester,$section);
            $stmt->execute() ? setFlash('success','Section added.') : setFlash('error','Failed to add section.');
            $stmt->close();
        } else { setFlash('error','All fields required.'); }
    }
    if ($action === 'edit') {
        $id      = (int)($_POST['id'] ?? 0);
        $course  = (int)($_POST['course_id'] ?? 0);
        $teacher = (int)($_POST['teacher_id'] ?? 0);
        $year    = clean($_POST['academic_year'] ?? '');
        $sem     = clean($_POST['semester'] ?? '');
        $sec     = clean($_POST['section'] ?? '');
        if ($id && $course && $teacher && $year && $sem && $sec) {
            $stmt = $conn->prepare("UPDATE sections SET course_id=?,teacher_id=?,academic_year=?,semester=?,section=? WHERE id=?");
            $stmt->bind_param('iisssi',$course,$teacher,$year,$sem,$sec,$id);
            $stmt->execute() ? setFlash('success','Section updated.') : setFlash('error','Update failed.');
            $stmt->close();
        }
    }
    if ($action === 'delete') {
        $id=(int)($_POST['id']??0);
        if ($id) {
            $stmt=$conn->prepare("DELETE FROM sections WHERE id=?");
            $stmt->bind_param('i',$id);
            $stmt->execute() ? setFlash('success','Section deleted.') : setFlash('error','Cannot delete (has assignments).');
            $stmt->close();
        }
    }
    header('Location: sections.php'); exit;
}

$search  = clean($_GET['search'] ?? '');
$perPage = 10;
$page    = max(1,(int)($_GET['page'] ?? 1));

if ($search) {
    $s2="%$search%";
    $c=$conn->prepare("SELECT COUNT(*) AS c FROM sections s JOIN courses c2 ON s.course_id=c2.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id WHERE c2.course_name LIKE ? OR s.section LIKE ? OR u.name LIKE ? OR s.academic_year LIKE ?");
    $c->bind_param('ssss',$s2,$s2,$s2,$s2); $c->execute();
    $total=(int)$c->get_result()->fetch_assoc()['c']; $c->close();
} else {
    $total=(int)$conn->query("SELECT COUNT(*) AS c FROM sections")->fetch_assoc()['c'];
}
$pg=paginate($total,$perPage,$page); $off=$pg['offset'];

if ($search) {
    $s2="%$search%";
    $stmt=$conn->prepare("SELECT s.*, c2.course_name, c2.course_code, u.name AS teacher_name, t.teacher_code FROM sections s JOIN courses c2 ON s.course_id=c2.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id WHERE c2.course_name LIKE ? OR s.section LIKE ? OR u.name LIKE ? OR s.academic_year LIKE ? ORDER BY s.id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('ssssii',$s2,$s2,$s2,$s2,$perPage,$off);
} else {
    $stmt=$conn->prepare("SELECT s.*, c2.course_name, c2.course_code, u.name AS teacher_name, t.teacher_code FROM sections s JOIN courses c2 ON s.course_id=c2.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id ORDER BY s.id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('ii',$perPage,$off);
}
$stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div><h2 class="text-xl font-bold text-slate-800">Sections</h2><p class="text-sm text-slate-500 mt-0.5">Manage course sections per semester</p></div>
    <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-cyan-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus','w-4 h-4') ?> Add Section
    </button>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 max-w-xs"><span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search','w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search sections..." class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>
            <button type="submit" class="px-3 py-2 text-sm bg-cyan-600 text-white rounded-xl hover:bg-cyan-700">Search</button>
            <?php if ($search): ?><a href="sections.php" class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-slate-600">Clear</a><?php endif ?>
        </form>
        <span class="text-xs text-slate-400"><?= $total ?> record<?= $total!==1?'s':'' ?></span>
    </div>
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500">#</th>
                    <th class="text-left px-5 py-3 text-slate-500">Course</th>
                    <th class="text-left px-5 py-3 text-slate-500">Section</th>
                    <th class="text-left px-5 py-3 text-slate-500">Teacher</th>
                    <th class="text-left px-5 py-3 text-slate-500">Year / Semester</th>
                    <th class="text-right px-5 py-3 text-slate-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if ($rows): foreach ($rows as $i => $row): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset']+$i+1 ?></td>
                    <td class="px-5 py-3"><p class="text-sm font-medium text-slate-800"><?= e($row['course_name']) ?></p><p class="text-xs text-slate-400 font-mono"><?= e($row['course_code']) ?></p></td>
                    <td class="px-5 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-cyan-100 text-cyan-800"><?= e($row['section']) ?></span></td>
                    <td class="px-5 py-3 text-sm text-slate-600"><?= e($row['teacher_name']) ?></td>
                    <td class="px-5 py-3"><p class="text-sm text-slate-700"><?= e($row['academic_year']) ?></p><p class="text-xs text-slate-400"><?= e($row['semester']) ?></p></td>
                    <td class="px-5 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <button onclick="openEdit(<?= htmlspecialchars(json_encode($row),ENT_QUOTES) ?>)"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg">
                                <?= iconSvg('edit','w-3.5 h-3.5') ?> Edit
                            </button>
                            <button onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['course_name'].' - '.$row['section'])) ?>')"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                <?= iconSvg('trash','w-3.5 h-3.5') ?> Delete
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" class="text-center py-16 text-slate-400"><?= iconSvg('grid','w-10 h-10 mx-auto mb-3 opacity-40') ?><p class="text-sm">No sections found.</p></td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100"><?= paginationLinks($pg,'sections.php'.($search?'?search='.urlencode($search):'')) ?></div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Add Section</h3>
            <button onclick="closeModal('addModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Course <span class="text-red-500">*</span></label>
                    <select name="course_id" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="">Select Course</option>
                        <?php foreach ($courseList as $c): ?><option value="<?= $c['id'] ?>">[<?= e($c['course_code']) ?>] <?= e($c['course_name']) ?></option><?php endforeach ?>
                    </select>
                </div>
                <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Teacher <span class="text-red-500">*</span></label>
                    <select name="teacher_id" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="">Select Teacher</option>
                        <?php foreach ($teacherList as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?> (<?= e($t['teacher_code']) ?>)</option><?php endforeach ?>
                    </select>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Section Name <span class="text-red-500">*</span></label>
                    <input type="text" name="section" required placeholder="e.g. A" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Academic Year <span class="text-red-500">*</span></label>
                    <input type="text" name="academic_year" required placeholder="2024-2025" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Semester <span class="text-red-500">*</span></label>
                    <select name="semester" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="">Select Semester</option>
                        <?php foreach ($semesters as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach ?>
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl">Add Section</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Edit Section</h3>
            <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Course</label>
                    <select name="course_id" id="edit_course" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <?php foreach ($courseList as $c): ?><option value="<?= $c['id'] ?>">[<?= e($c['course_code']) ?>] <?= e($c['course_name']) ?></option><?php endforeach ?>
                    </select>
                </div>
                <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Teacher</label>
                    <select name="teacher_id" id="edit_teacher" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <?php foreach ($teacherList as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?> (<?= e($t['teacher_code']) ?>)</option><?php endforeach ?>
                    </select>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Section Name</label>
                    <input type="text" name="section" id="edit_section" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Academic Year</label>
                    <input type="text" name="academic_year" id="edit_year" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Semester</label>
                    <select name="semester" id="edit_semester" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <?php foreach ($semesters as $s): ?><option value="<?= $s ?>"><?= $s ?></option><?php endforeach ?>
                    </select>
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4"><?= iconSvg('trash','w-7 h-7 text-red-600') ?></div>
            <h3 class="text-lg font-semibold text-slate-800">Delete Section</h3>
            <p class="text-sm text-slate-500 mt-2">Delete section <strong id="delete_name" class="text-slate-700"></strong>?</p>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')" class="flex-1 px-4 py-2.5 text-sm border border-slate-200 rounded-xl">Cancel</button>
                <button type="submit" class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(row) {
    document.getElementById('edit_id').value       = row.id;
    document.getElementById('edit_course').value   = row.course_id;
    document.getElementById('edit_teacher').value  = row.teacher_id;
    document.getElementById('edit_section').value  = row.section;
    document.getElementById('edit_year').value     = row.academic_year;
    document.getElementById('edit_semester').value = row.semester;
    openModal('editModal');
}
function openDelete(id,name) {
    document.getElementById('delete_id').value         = id;
    document.getElementById('delete_name').textContent = name;
    openModal('deleteModal');
}
</script>
<?php include '../includes/admin_footer.php'; ?>
