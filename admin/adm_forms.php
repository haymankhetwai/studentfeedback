<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Administration Feedback Forms';
$activeMenu = 'adm_forms';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $title  = clean($_POST['title'] ?? '');
        $start  = clean($_POST['start_date'] ?? '');
        $end    = clean($_POST['end_date'] ?? '');
        $status = in_array($_POST['status'], ['active','inactive']) ? $_POST['status'] : 'active';
        if ($title && $start && $end) {
            $stmt = $conn->prepare("INSERT INTO feedback_forms (module,title,start_date,end_date,status) VALUES ('administration',?,?,?,?)");
            $stmt->bind_param('ssss', $title, $start, $end, $status);
            $stmt->execute() ? setFlash('success','Administration form created.') : setFlash('error','Failed.');
            $stmt->close();
        } else { setFlash('error','Title, start date, and end date are required.'); }
    }
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $title  = clean($_POST['title'] ?? '');
        $start  = clean($_POST['start_date'] ?? '');
        $end    = clean($_POST['end_date'] ?? '');
        $status = in_array($_POST['status'], ['active','inactive']) ? $_POST['status'] : 'active';
        if ($id && $title) {
            $stmt = $conn->prepare("UPDATE feedback_forms SET title=?,start_date=?,end_date=?,status=? WHERE id=? AND module='administration'");
            $stmt->bind_param('ssssi', $title, $start, $end, $status, $id);
            $stmt->execute() ? setFlash('success','Form updated.') : setFlash('error','Update failed.');
            $stmt->close();
        }
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM feedback_forms WHERE id=? AND module='administration'");
            $stmt->bind_param('i', $id);
            $stmt->execute() ? setFlash('success','Form deleted.') : setFlash('error','Cannot delete.');
            $stmt->close();
        }
    }
    header('Location: adm_forms.php'); exit;
}

$search  = clean($_GET['search'] ?? '');
$perPage = 10;
$page    = max(1, (int)($_GET['page'] ?? 1));
if ($search) {
    $s2 = "%$search%";
    $c = $conn->prepare("SELECT COUNT(*) AS c FROM feedback_forms WHERE module='administration' AND title LIKE ?");
    $c->bind_param('s', $s2); $c->execute();
    $total = (int)$c->get_result()->fetch_assoc()['c']; $c->close();
} else {
    $total = (int)$conn->query("SELECT COUNT(*) AS c FROM feedback_forms WHERE module='administration'")->fetch_assoc()['c'];
}
$pg = paginate($total, $perPage, $page); $off = $pg['offset'];
if ($search) {
    $s2 = "%$search%";
    $stmt = $conn->prepare("SELECT f.*, (SELECT COUNT(*) FROM feedback_questions fq WHERE fq.module='administration') AS q_count, (SELECT COUNT(*) FROM feedback_submissions WHERE form_id=f.id) AS s_count FROM feedback_forms f WHERE f.module='administration' AND f.title LIKE ? ORDER BY f.id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('sii', $s2, $perPage, $off);
} else {
    $stmt = $conn->prepare("SELECT f.*, (SELECT COUNT(*) FROM feedback_questions fq WHERE fq.module='administration') AS q_count, (SELECT COUNT(*) FROM feedback_submissions WHERE form_id=f.id) AS s_count FROM feedback_forms f WHERE f.module='administration' ORDER BY f.id DESC LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $perPage, $off);
}
$stmt->execute(); $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC); $stmt->close();

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-2 mb-1"><?= iconSvg('office','w-5 h-5 text-orange-600') ?>
            <h2 class="text-xl font-bold text-slate-800">Administration Feedback Forms</h2></div>
        <p class="text-sm text-slate-500">Manage feedback forms for administration office evaluation</p>
    </div>
    <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 bg-orange-600 hover:bg-orange-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus','w-4 h-4') ?> Create Adm Form
    </button>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 max-w-xs"><span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search','w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search forms..." class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none"></div>
            <button type="submit" class="px-3 py-2 text-sm bg-orange-600 text-white rounded-xl hover:bg-orange-700">Search</button>
            <?php if ($search): ?><a href="adm_forms.php" class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-slate-600">Clear</a><?php endif ?>
        </form>
        <span class="text-xs text-slate-400"><?= $total ?> form<?= $total !== 1 ? 's' : '' ?></span>
    </div>
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500">#</th>
                    <th class="text-left px-5 py-3 text-slate-500">Form Title</th>
                    <th class="text-left px-5 py-3 text-slate-500">Duration</th>
                    <th class="text-left px-5 py-3 text-slate-500">Questions</th>
                    <th class="text-left px-5 py-3 text-slate-500">Submissions</th>
                    <th class="text-left px-5 py-3 text-slate-500">Status</th>
                    <th class="text-right px-5 py-3 text-slate-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if ($rows): foreach ($rows as $i => $row): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                    <td class="px-5 py-3"><p class="text-sm font-medium text-slate-800"><?= e($row['title']) ?></p></td>
                    <td class="px-5 py-3 text-xs text-slate-500"><?= formatDate($row['start_date']) ?><br><?= formatDate($row['end_date']) ?></td>
                    <td class="px-5 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700"><?= $row['q_count'] ?> Q</span></td>
                    <td class="px-5 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700"><?= $row['s_count'] ?> submitted</span></td>
                    <td class="px-5 py-3"><?= badgeStatus($row['status']) ?></td>
                    <td class="px-5 py-3 text-right">
                        <div class="flex items-center justify-end gap-1.5">
                            <a href="adm_questions.php" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-orange-700 bg-orange-50 hover:bg-orange-100 rounded-lg"><?= iconSvg('question','w-3.5 h-3.5') ?> Questions</a>
                            <a href="adm_results.php?form_id=<?= $row['id'] ?>" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg"><?= iconSvg('chart','w-3.5 h-3.5') ?> Results</a>
                            <button onclick="openEdit(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg"><?= iconSvg('edit','w-3.5 h-3.5') ?> Edit</button>
                            <button onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['title'])) ?>')" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg"><?= iconSvg('trash','w-3.5 h-3.5') ?> Delete</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="text-center py-16 text-slate-400"><?= iconSvg('office','w-10 h-10 mx-auto mb-3 opacity-40') ?><p class="text-sm">No administration feedback forms yet.</p></td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100"><?= paginationLinks($pg, 'adm_forms.php'.($search?'?search='.urlencode($search):'')) ?></div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div class="flex items-center gap-2"><?= iconSvg('office','w-5 h-5 text-orange-600') ?><h3 class="font-semibold text-slate-800">Create Administration Form</h3></div>
            <button onclick="closeModal('addModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Title <span class="text-red-500">*</span></label>
                    <input type="text" name="title" required placeholder="e.g. Administration Satisfaction Survey" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Start Date <span class="text-red-500">*</span></label>
                        <input type="date" name="start_date" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-orange-500 outline-none"></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">End Date <span class="text-red-500">*</span></label>
                        <input type="date" name="end_date" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-orange-500 outline-none"></div>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                    <select name="status" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white">
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select></div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-orange-600 hover:bg-orange-700 rounded-xl">Create Form</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Edit Administration Form</h3>
            <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Title</label>
                    <input type="text" name="title" id="edit_title" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-orange-500 outline-none"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Start Date</label><input type="date" name="start_date" id="edit_start" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none"></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">End Date</label><input type="date" name="end_date" id="edit_end" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none"></div>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                    <select name="status" id="edit_status" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white">
                        <option value="active">Active</option><option value="inactive">Inactive</option>
                    </select></div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 text-sm text-slate600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-orange-600 hover:bg-orange-700 rounded-xl">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4"><?= iconSvg('trash','w-7 h-7 text-red-600') ?></div>
            <h3 class="text-lg font-semibold text-slate-800">Delete Adm Form</h3>
            <p class="text-sm text-slate-500 mt-2">Delete <strong id="delete_name" class="text-slate-700"></strong>? All submissions will be lost.</p>
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
    document.getElementById('edit_id').value     = row.id;
    document.getElementById('edit_title').value  = row.title;
    document.getElementById('edit_start').value  = row.start_date;
    document.getElementById('edit_end').value    = row.end_date;
    document.getElementById('edit_status').value = row.status;
    openModal('editModal');
}
function openDelete(id, name) {
    document.getElementById('delete_id').value         = id;
    document.getElementById('delete_name').textContent = name;
    openModal('deleteModal');
}
</script>
<?php include '../includes/admin_footer.php'; ?>
