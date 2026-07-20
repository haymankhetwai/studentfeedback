<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['students_title'] ?? 'Students';
$activeMenu = 'students';

$availableUsers = $conn->query("SELECT u.id, u.name, u.email FROM users u WHERE u.role='student' AND u.id NOT IN (SELECT user_id FROM students) ORDER BY u.name")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $rollno = clean($_POST['roll_no'] ?? '');
        if ($uid && $rollno) {
            $stmt = $conn->prepare("INSERT INTO students (user_id, roll_no) VALUES (?,?)");
            $stmt->bind_param('is', $uid, $rollno);
            $stmt->execute() ? setFlash('success', 'Student added.') : setFlash('error', 'Failed. Roll number may exist.');
            $stmt->close();
        } else {
            setFlash('error', 'All fields required.');
        }
    }
    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $rollno = clean($_POST['roll_no'] ?? '');
        if ($id && $rollno) {
            $stmt = $conn->prepare("UPDATE students SET roll_no=? WHERE id=?");
            $stmt->bind_param('si', $rollno, $id);
            $stmt->execute() ? setFlash('success', 'Student updated.') : setFlash('error', 'Update failed.');
            $stmt->close();
        }
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM students WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute() ? setFlash('success', 'Student removed.') : setFlash('error', 'Cannot delete.');
            $stmt->close();
        }
    }
    header('Location: students.php');
    exit;
}

$search = clean($_GET['search'] ?? '');
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 10)));
$page = max(1, (int) ($_GET['page'] ?? 1));

if ($search) {
    $s2 = "%$search%";
    $c = $conn->prepare("SELECT COUNT(*) AS c FROM students st JOIN users u ON st.user_id=u.id WHERE u.name LIKE ? OR st.roll_no LIKE ?");
    $c->bind_param('ss', $s2, $s2);
    $c->execute();
    $total = (int) $c->get_result()->fetch_assoc()['c'];
    $c->close();
} else {
    $total = (int) $conn->query("SELECT COUNT(*) AS c FROM students")->fetch_assoc()['c'];
}
$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];

if ($search) {
    $s2 = "%$search%";
    $stmt = $conn->prepare("SELECT st.*, u.name, u.email FROM students st JOIN users u ON st.user_id=u.id WHERE u.name LIKE ? OR st.roll_no LIKE ? ORDER BY st.id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('ssii', $s2, $s2, $perPage, $off);
} else {
    $stmt = $conn->prepare("SELECT st.*, u.name, u.email FROM students st JOIN users u ON st.user_id=u.id ORDER BY st.id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $perPage, $off);
}
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-800"><?= $LANG['students_title'] ?? 'Students' ?></h2>
        <p class="text-sm text-slate-500 mt-0.5"><?= $LANG['students_subtitle'] ?? 'Manage student profiles and enrollment' ?></p>
    </div>
    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus', 'w-4 h-4') ?> <?= $LANG['add_student'] ?? 'Add Student' ?>
    </button>
</div>

<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 max-w-xs">
                <span
                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search', 'w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="<?= $LANG['search_students'] ?? 'Search students...' ?>"
                    class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>
            <button type="submit"
                class="px-3 py-2 text-sm bg-indigo-600 text-white rounded-xl hover:bg-indigo-700"><?= $LANG['search'] ?? 'Search' ?></button>
            <?php if ($search): ?><a href="students.php"
                    class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-white hover:bg-red-700 bg-red-500"><?= $LANG['clear'] ?? 'Clear' ?></a><?php endif ?>
        </form>
        <span class="text-xs text-slate-400"><?= $total ?> <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span>
    </div>
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold"><?= $LANG['col_name'] ?? 'Name' ?></th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold"><?= $LANG['roll_number'] ?? 'Roll No' ?></th>
                    <th class="text-right px-5 py-3 text-slate-500 text-sm font-semibold"><?= $LANG['col_actions'] ?? 'Actions' ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows):
                    foreach ($rows as $i => $row): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-3 ">
                                <div class="flex items-center  gap-2">
                                    <div
                                        class="w-8 h-8 rounded-full bg-green-100 flex items-center justify-center text-xs font-bold text-green-700">
                                        <?= e(avatarInitials($row['name'])) ?>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-800"><?= e($row['name']) ?></p>
                                        <p class="text-xs text-slate-400"><?= e($row['email']) ?></p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3"><span
                                    class="font-mono text-xs bg-slate-100 px-2 py-1 rounded"><?= e($row['roll_no']) ?></span>
                            </td>
                            <td class="px-5 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <button
                                        onclick="openEdit(<?= $row['id'] ?>,'<?= addslashes(e($row['roll_no'])) ?>')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?> <?= $LANG['edit'] ?? 'Edit' ?>
                                    </button>
                                    <button onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['name'])) ?>')"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                        <?= iconSvg('trash', 'w-3.5 h-3.5') ?> <?= $LANG['delete'] ?? 'Delete' ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="4" class="text-center py-16 text-slate-400">
                            <?= iconSvg('users', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm"><?= $LANG['no_students_found'] ?? 'No students found.' ?></p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100">
        <?= paginationLinks($pg, 'students.php' . ($search ? '?search=' . urlencode($search) : ''), $perPage) ?>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['add_student'] ?? 'Add Student' ?></h3>
            <button onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['student_user'] ?? 'Student User' ?> <span
                            class="text-red-500">*</span></label>
                    <select name="user_id" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG['select_student_user'] ?? 'Select student user' ?></option>
                        <?php foreach ($availableUsers as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= e($u['name']) ?> — <?= e($u['email']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['roll_number'] ?? 'Roll Number' ?> <span
                            class="text-red-500">*</span></label>
                    <input type="text" name="roll_no" required placeholder="<?= $LANG['roll_number_placeholder'] ?? 'e.g. 5CS-1' ?>"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')"
                    class="px-4 py-2 text-sm font-semibold text-white bg-red-500 hover:bg-red-700 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="px-5 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['add_student_btn'] ?? 'Add Student' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['edit_student_modal'] ?? 'Edit Student' ?></h3>
            <button onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id"
                id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['roll_number'] ?? 'Roll Number' ?> <span
                            class="text-red-500">*</span></label>
                    <input type="text" name="roll_no" id="edit_roll" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')"
                    class="px-4 py-2 text-sm font-semibold text-white bg-red-500 hover:bg-red-700 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="px-5 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG['save'] ?? 'Save' ?></button>
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
            <h3 class="text-lg font-semibold text-slate-800"><?= $LANG['remove_student_modal'] ?? 'Remove Student' ?></h3>
            <p class="text-sm text-slate-500 mt-2"><?= $LANG['remove_student_confirm'] ?? 'Remove' ?> <strong id="delete_name" class="text-slate-700"></strong> <?= $LANG["from_system"] ?? "from the system?" ?></p>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id"
                id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-500 hover:bg-red-700 rounded-xl transition-colors"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG['delete'] ?? 'Remove' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEdit(id, roll) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_roll').value = roll;
        openModal('editModal');
    }
    function openDelete(id, name) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_name').textContent = name;
        openModal('deleteModal');
    }
</script>
<?php include '../includes/admin_footer.php'; ?>
