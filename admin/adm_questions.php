<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Administration Feedback Questions';
$activeMenu = 'adm_questions';

$module = 'administration';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $qno   = (int)($_POST['question_no'] ?? 0);
        $qtext = clean($_POST['question_text'] ?? '');
        $qtype = in_array($_POST['question_type'], ['rating','comment']) ? $_POST['question_type'] : 'rating';
        if ($qno && $qtext) {
            $stmt = $conn->prepare("INSERT INTO feedback_questions (module, question_no, question_text, question_type) VALUES (?,?,?,?)");
            $stmt->bind_param('siss', $module, $qno, $qtext, $qtype);
            $stmt->execute() ? setFlash('success','Question added.') : setFlash('error','Failed.');
            $stmt->close();
        } else { setFlash('error','All fields required.'); }
    }
    if ($action === 'edit') {
        $id    = (int)($_POST['id'] ?? 0);
        $qno   = (int)($_POST['question_no'] ?? 0);
        $qtext = clean($_POST['question_text'] ?? '');
        $qtype = in_array($_POST['question_type'], ['rating','comment']) ? $_POST['question_type'] : 'rating';
        if ($id && $qno && $qtext) {
            $stmt = $conn->prepare("UPDATE feedback_questions SET question_no=?,question_text=?,question_type=? WHERE id=? AND module=?");
            $stmt->bind_param('issis', $qno, $qtext, $qtype, $id, $module);
            $stmt->execute() ? setFlash('success','Question updated.') : setFlash('error','Update failed.');
            $stmt->close();
        }
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM feedback_questions WHERE id=? AND module=?");
            $stmt->bind_param('si', $id, $module);
            $stmt->execute() ? setFlash('success','Question deleted.') : setFlash('error','Cannot delete.');
            $stmt->close();
        }
    }
    header('Location: adm_questions.php'); exit;
}

$search  = clean($_GET['search'] ?? '');
$perPage = 20;
$page    = max(1, (int)($_GET['page'] ?? 1));

$conds = ['fq.module=?'];
$params = [$module];
$types  = 's';
if ($search) { $s2 = "%$search%"; $conds[] = "fq.question_text LIKE ?"; $params[] = $s2; $types .= 's'; }
$where = 'WHERE ' . implode(' AND ', $conds);

$cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM feedback_questions fq $where");
$cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$total = (int)$cntStmt->get_result()->fetch_assoc()['c'];
$cntStmt->close();

$pg = paginate($total, $perPage, $page); $off = $pg['offset'];
$p2 = array_merge($params, [$perPage, $off]); $t2 = $types . 'ii';

$dataStmt = $conn->prepare("SELECT fq.* FROM feedback_questions fq $where ORDER BY fq.question_no LIMIT ? OFFSET ?");
$dataStmt->bind_param($t2, ...$p2);
$dataStmt->execute(); $rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC); $dataStmt->close();

$nextNo = $total + 1;

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <div class="flex items-center gap-2 mb-1">
            <?= iconSvg('office','w-5 h-5 text-orange-600') ?>
            <h2 class="text-xl font-bold text-slate-800">Administration Feedback Questions</h2>
        </div>
        <p class="text-sm text-slate-500 mt-0.5">Shared questions used by all Administration feedback forms across every semester</p>
    </div>
    <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 bg-orange-600 hover:bg-orange-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus','w-4 h-4') ?> Add Question
    </button>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex items-center gap-2 flex-1">
            <div class="relative flex-1 max-w-xs"><span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search','w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search questions..." class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none">
            </div>
            <button type="submit" class="px-3 py-2 text-sm bg-orange-600 text-white rounded-xl hover:bg-orange-700">Search</button>
            <?php if ($search): ?><a href="adm_questions.php" class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-slate-600">Clear</a><?php endif ?>
        </form>
        <span class="text-xs text-slate-400"><?= $total ?> question<?= $total !== 1 ? 's' : '' ?></span>
    </div>
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 w-12">Q#</th>
                    <th class="text-left px-5 py-3 text-slate-500">Question</th>
                    <th class="text-left px-5 py-3 text-slate-500">Type</th>
                    <th class="text-right px-5 py-3 text-slate-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if ($rows): foreach ($rows as $row): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3 text-center">
                        <span class="w-7 h-7 rounded-full bg-orange-100 text-orange-700 text-xs font-bold flex items-center justify-center"><?= $row['question_no'] ?></span>
                    </td>
                    <td class="px-5 py-3 text-sm text-slate-700 max-w-xs"><?= e($row['question_text']) ?></td>
                    <td class="px-5 py-3">
                        <?php if ($row['question_type'] === 'rating'): ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                            <?= iconSvg('star','w-3.5 h-3.5') ?> Rating
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-cyan-100 text-cyan-800">
                            <?= iconSvg('clipboard','w-3.5 h-3.5') ?> Comment
                        </span>
                        <?php endif ?>
                    </td>
                    <td class="px-5 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <button onclick="openEdit(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-orange-700 bg-orange-50 hover:bg-orange-100 rounded-lg">
                                <?= iconSvg('edit','w-3.5 h-3.5') ?> Edit
                            </button>
                            <button onclick="openDelete(<?= $row['id'] ?>,'Q<?= $row['question_no'] ?>')"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                <?= iconSvg('trash','w-3.5 h-3.5') ?> Delete
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-center py-16 text-slate-400"><?= iconSvg('question','w-10 h-10 mx-auto mb-3 opacity-40') ?><p class="text-sm">No questions yet. Add your first question.</p></td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100"><?= paginationLinks($pg, 'adm_questions.php' . ($search ? '?search=' . urlencode($search) : '')) ?></div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Add Administration Question</h3>
            <button onclick="closeModal('addModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Question # <span class="text-red-500">*</span></label>
                        <input type="number" name="question_no" value="<?= $nextNo ?>" required min="1" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none">
                    </div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Type <span class="text-red-500">*</span></label>
                        <select name="question_type" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none bg-white">
                            <option value="rating">Rating</option>
                            <option value="comment">Comment</option>
                        </select>
                    </div>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Question Text <span class="text-red-500">*</span></label>
                    <textarea name="question_text" required rows="3" placeholder="Enter the question..." class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none resize-none"></textarea>
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-orange-600 hover:bg-orange-700 rounded-xl">Add Question</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Edit Administration Question</h3>
            <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Question #</label>
                        <input type="number" name="question_no" id="edit_qno" min="1" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none">
                    </div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Type</label>
                        <select name="question_type" id="edit_qtype" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none bg-white">
                            <option value="rating">Rating</option><option value="comment">Comment</option>
                        </select>
                    </div>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Question Text</label>
                    <textarea name="question_text" id="edit_qtext" rows="3" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-orange-500 focus:ring-2 focus:ring-orange-500/20 outline-none resize-none"></textarea>
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-orange-600 hover:bg-orange-700 rounded-xl">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4"><?= iconSvg('trash','w-7 h-7 text-red-600') ?></div>
            <h3 class="text-lg font-semibold text-slate-800">Delete Administration Question</h3>
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
function openEdit(row) {
    document.getElementById('edit_id').value     = row.id;
    document.getElementById('edit_qno').value    = row.question_no;
    document.getElementById('edit_qtype').value  = row.question_type;
    document.getElementById('edit_qtext').value  = row.question_text;
    openModal('editModal');
}
function openDelete(id, name) {
    document.getElementById('delete_id').value         = id;
    document.getElementById('delete_name').textContent  = name;
    openModal('deleteModal');
}
</script>
<?php include '../includes/admin_footer.php'; ?>
