<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Courses';
$activeMenu = 'courses';

$majorList = $conn->query("SELECT m.id, m.major_name, d.department_name FROM majors m JOIN departments d ON m.department_id=d.id ORDER BY d.department_name, m.major_name")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $major = (int)($_POST['major_id'] ?? 0);
        $code  = clean($_POST['course_code'] ?? '');
        $name  = clean($_POST['course_name'] ?? '');
        if ($major && $code && $name) {
            $stmt = $conn->prepare("INSERT INTO courses (major_id, course_code, course_name) VALUES (?,?,?)");
            $stmt->bind_param('iss',$major,$code,$name);
            $stmt->execute() ? setFlash('success','Course added.') : setFlash('error','Failed. Code may already exist.');
            $stmt->close();
        } else { setFlash('error','All fields required.'); }
    }
    if ($action === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $major = (int)($_POST['major_id'] ?? 0);
        $code  = clean($_POST['course_code'] ?? '');
        $name  = clean($_POST['course_name'] ?? '');
        if ($id && $major && $code && $name) {
            $stmt = $conn->prepare("UPDATE courses SET major_id=?,course_code=?,course_name=? WHERE id=?");
            $stmt->bind_param('issi',$major,$code,$name,$id);
            $stmt->execute() ? setFlash('success','Course updated.') : setFlash('error','Update failed.');
            $stmt->close();
        }
    }
    if ($action === 'delete') {
        $id=(int)($_POST['id']??0);
        if ($id) {
            $stmt=$conn->prepare("DELETE FROM courses WHERE id=?");
            $stmt->bind_param('i',$id);
            $stmt->execute() ? setFlash('success','Course deleted.') : setFlash('error','Cannot delete (has sections).');
            $stmt->close();
        }
    }
    header('Location: courses.php'); exit;
}

$search  = clean($_GET['search'] ?? '');
$perPage = 10;
$page    = max(1,(int)($_GET['page'] ?? 1));

if ($search) {
    $s2="%$search%";
    $c=$conn->prepare("SELECT COUNT(*) AS c FROM courses c JOIN majors m ON c.major_id=m.id WHERE c.course_name LIKE ? OR c.course_code LIKE ? OR m.major_name LIKE ?");
    $c->bind_param('sss',$s2,$s2,$s2); $c->execute();
    $total=(int)$c->get_result()->fetch_assoc()['c']; $c->close();
} else {
    $total=(int)$conn->query("SELECT COUNT(*) AS c FROM courses")->fetch_assoc()['c'];
}
$pg=paginate($total,$perPage,$page); $off=$pg['offset'];

if ($search) {
    $s2="%$search%";
    $stmt=$conn->prepare("SELECT c.*, m.major_name, d.department_name FROM courses c JOIN majors m ON c.major_id=m.id JOIN departments d ON m.department_id=d.id WHERE c.course_name LIKE ? OR c.course_code LIKE ? OR m.major_name LIKE ? ORDER BY c.id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('sssii',$s2,$s2,$s2,$perPage,$off);
} else {
    $stmt=$conn->prepare("SELECT c.*, m.major_name, d.department_name FROM courses c JOIN majors m ON c.major_id=m.id JOIN departments d ON m.department_id=d.id ORDER BY c.id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('ii',$perPage,$off);
}
$stmt->execute(); $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div><h2 class="text-xl font-bold text-slate-800">Courses</h2><p class="text-sm text-slate-500 mt-0.5">Manage course catalog</p></div>
    <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-cyan-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus','w-4 h-4') ?> Add Course
    </button>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 max-w-xs"><span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search','w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search courses..." class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>
            <button type="submit" class="px-3 py-2 text-sm bg-cyan-600 text-white rounded-xl hover:bg-cyan-700">Search</button>
            <?php if ($search): ?><a href="courses.php" class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50">Clear</a><?php endif ?>
        </form>
        <span class="text-xs text-slate-400"><?= $total ?> record<?= $total!==1?'s':'' ?></span>
    </div>
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500">#</th>
                    <th class="text-left px-5 py-3 text-slate-500">Course</th>
                    <th class="text-left px-5 py-3 text-slate-500">Code</th>
                    <th class="text-left px-5 py-3 text-slate-500">Major</th>
                    <th class="text-left px-5 py-3 text-slate-500">Department</th>
                    <th class="text-right px-5 py-3 text-slate-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if ($rows): foreach ($rows as $i => $row): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset']+$i+1 ?></td>
                    <td class="px-5 py-3 text-sm font-medium text-slate-800"><?= e($row['course_name']) ?></td>
                    <td class="px-5 py-3"><span class="font-mono text-xs bg-cyan-50 text-cyan-700 px-2 py-1 rounded border border-cyan-200"><?= e($row['course_code']) ?></span></td>
                    <td class="px-5 py-3"><span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800"><?= e($row['major_name']) ?></span></td>
                    <td class="px-5 py-3 text-sm text-slate-500"><?= e($row['department_name']) ?></td>
                    <td class="px-5 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <button onclick="openEdit(<?= $row['id'] ?>,<?= $row['major_id'] ?>,'<?= addslashes(e($row['course_code'])) ?>','<?= addslashes(e($row['course_name'])) ?>')"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg">
                                <?= iconSvg('edit','w-3.5 h-3.5') ?> Edit
                            </button>
                            <button onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['course_name'])) ?>')"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                <?= iconSvg('trash','w-3.5 h-3.5') ?> Delete
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="6" class="text-center py-16 text-slate-400"><?= iconSvg('book','w-10 h-10 mx-auto mb-3 opacity-40') ?><p class="text-sm">No courses found.</p></td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100"><?= paginationLinks($pg,'courses.php'.($search?'?search='.urlencode($search):'')) ?></div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Add Course</h3>
            <button onclick="closeModal('addModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Major <span class="text-red-500">*</span></label>
                    <select name="major_id" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="">Select Major</option>
                        <?php foreach ($majorList as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['department_name']) ?> › <?= e($m['major_name']) ?></option><?php endforeach ?>
                    </select>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Course Code <span class="text-red-500">*</span></label>
                    <input type="text" name="course_code" required placeholder="e.g. CS-301" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Course Name <span class="text-red-500">*</span></label>
                    <input type="text" name="course_name" required placeholder="e.g. Database Systems" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl">Add Course</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Edit Course</h3>
            <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Major <span class="text-red-500">*</span></label>
                    <select name="major_id" id="edit_major" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <?php foreach ($majorList as $m): ?><option value="<?= $m['id'] ?>"><?= e($m['department_name']) ?> › <?= e($m['major_name']) ?></option><?php endforeach ?>
                    </select>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Course Code</label>
                    <input type="text" name="course_code" id="edit_code" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Course Name</label>
                    <input type="text" name="course_name" id="edit_name" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
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
            <h3 class="text-lg font-semibold text-slate-800">Delete Course</h3>
            <p class="text-sm text-slate-500 mt-2">Delete <strong id="delete_name" class="text-slate-700"></strong>?</p>
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
function openEdit(id,majorId,code,name) {
    document.getElementById('edit_id').value    = id;
    document.getElementById('edit_major').value = majorId;
    document.getElementById('edit_code').value  = code;
    document.getElementById('edit_name').value  = name;
    openModal('editModal');
}
function openDelete(id,name) {
    document.getElementById('delete_id').value         = id;
    document.getElementById('delete_name').textContent = name;
    openModal('deleteModal');
}
</script>
<?php include '../includes/admin_footer.php'; ?>
