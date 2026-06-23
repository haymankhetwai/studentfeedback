<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$formId  = (int)($_GET['form_id'] ?? 0);
$allForms = $conn->query("SELECT * FROM sa_feedback_forms ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);

// ── No form selected: show picker ─────────────────────────────
if (!$formId) {
    $pageTitle  = 'SA Questions — Select Form';
    $activeMenu = 'sa_questions';
    include '../includes/admin_header.php';
    include '../includes/admin_sidebar.php';
    ?>
    <div class="mb-6">
        <div class="flex items-center gap-2 mb-1"><?= iconSvg('shield','w-5 h-5 text-purple-600') ?><h2 class="text-xl font-bold text-slate-800">SA Questions</h2></div>
        <p class="text-sm text-slate-500">Select a Student Affairs form to manage its questions</p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
    <?php if ($allForms): foreach ($allForms as $f):
        $qCount = (int)$conn->query("SELECT COUNT(*) AS c FROM sa_feedback_questions WHERE form_id={$f['id']}")->fetch_assoc()['c'];
    ?>
        <a href="sa_questions.php?form_id=<?= $f['id'] ?>" class="bg-white rounded-2xl border border-slate-100 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all p-5 flex flex-col gap-3">
            <div class="flex items-start justify-between gap-2">
                <div class="w-10 h-10 rounded-xl bg-purple-100 flex items-center justify-center flex-shrink-0"><?= iconSvg('shield','w-5 h-5 text-purple-600') ?></div>
                <?= badgeStatus($f['status']) ?>
            </div>
            <div>
                <p class="text-sm font-semibold text-slate-800"><?= e($f['title']) ?></p>
                <p class="text-xs text-slate-400 mt-0.5"><?= formatDate($f['start_date']) ?> – <?= formatDate($f['end_date']) ?></p>
            </div>
            <div class="flex items-center justify-between">
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-700"><?= $qCount ?> question<?= $qCount !== 1 ? 's' : '' ?></span>
                <span class="text-xs font-semibold text-purple-600">Manage →</span>
            </div>
        </a>
    <?php endforeach; else: ?>
        <div class="col-span-3 bg-white rounded-2xl border border-slate-100 shadow-sm text-center py-16 text-slate-400">
            <?= iconSvg('shield','w-10 h-10 mx-auto mb-3 opacity-30') ?>
            <p class="text-sm">No SA forms yet. <a href="sa_forms.php" class="text-purple-600 hover:underline">Create one first →</a></p>
        </div>
    <?php endif ?>
    </div>
    <?php
    include '../includes/admin_footer.php';
    exit;
}

// ── Form found — proceed normally ─────────────────────────────
$formRow = $conn->query("SELECT * FROM sa_feedback_forms WHERE id=$formId")->fetch_assoc();
if (!$formRow) { setFlash('error','SA form not found.'); header('Location: sa_questions.php'); exit; }

$pageTitle  = 'SA Questions — ' . $formRow['title'];
$activeMenu = 'sa_questions';

// ─── POST ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $qno   = (int)($_POST['question_no'] ?? 1);
        $text  = clean($_POST['question_text'] ?? '');
        $type  = in_array($_POST['question_type'], ['rating','comment']) ? $_POST['question_type'] : 'rating';
        if ($text) {
            $stmt = $conn->prepare("INSERT INTO sa_feedback_questions (form_id,question_no,question_text,question_type) VALUES (?,?,?,?)");
            $stmt->bind_param('iiss', $formId, $qno, $text, $type);
            $stmt->execute() ? setFlash('success','Question added.') : setFlash('error','Failed to add question.');
            $stmt->close();
        } else { setFlash('error','Question text is required.'); }
    }

    if ($action === 'edit') {
        $id   = (int)($_POST['id'] ?? 0);
        $qno  = (int)($_POST['question_no'] ?? 1);
        $text = clean($_POST['question_text'] ?? '');
        $type = in_array($_POST['question_type'], ['rating','comment']) ? $_POST['question_type'] : 'rating';
        if ($id && $text) {
            $stmt = $conn->prepare("UPDATE sa_feedback_questions SET question_no=?,question_text=?,question_type=? WHERE id=? AND form_id=?");
            $stmt->bind_param('issii', $qno, $text, $type, $id, $formId);
            $stmt->execute() ? setFlash('success','Question updated.') : setFlash('error','Update failed.');
            $stmt->close();
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM sa_feedback_questions WHERE id=? AND form_id=?");
            $stmt->bind_param('ii', $id, $formId);
            $stmt->execute() ? setFlash('success','Question deleted.') : setFlash('error','Failed.');
            $stmt->close();
        }
    }
    header("Location: sa_questions.php?form_id=$formId"); exit;
}

$questions = $conn->query("SELECT * FROM sa_feedback_questions WHERE form_id=$formId ORDER BY question_no")->fetch_all(MYSQLI_ASSOC);
$nextNo    = count($questions) + 1;

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="mb-6">
    <a href="sa_forms.php" class="inline-flex items-center gap-1 text-sm text-slate-500 hover:text-purple-600 mb-3 transition-colors">
        ← Back to SA Forms
    </a>
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1"><?= iconSvg('shield','w-5 h-5 text-purple-600') ?>
                <h2 class="text-xl font-bold text-slate-800">SA Questions</h2>
            </div>
            <p class="text-sm text-slate-500 ml-7"><?= e($formRow['title']) ?> · <?= formatDate($formRow['start_date']) ?> – <?= formatDate($formRow['end_date']) ?></p>
        </div>
        <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 bg-purple-600 hover:bg-purple-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm transition-all hover:-translate-y-0.5">
            <?= iconSvg('plus','w-4 h-4') ?> Add Question
        </button>
    </div>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500">#</th>
                    <th class="text-left px-5 py-3 text-slate-500">Question Text</th>
                    <th class="text-left px-5 py-3 text-slate-500">Type</th>
                    <th class="text-right px-5 py-3 text-slate-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if ($questions): foreach ($questions as $q): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3 text-sm font-bold text-slate-500"><?= $q['question_no'] ?></td>
                    <td class="px-5 py-4 text-sm text-slate-800 max-w-xl"><?= e($q['question_text']) ?></td>
                    <td class="px-5 py-3">
                        <?php if ($q['question_type'] === 'rating'): ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">★ Rating</span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-cyan-100 text-cyan-700">✎ Comment</span>
                        <?php endif ?>
                    </td>
                    <td class="px-5 py-3 text-right">
                        <div class="flex items-center justify-end gap-1.5">
                            <button onclick='openEdit(<?= htmlspecialchars(json_encode($q), ENT_QUOTES) ?>)' class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg"><?= iconSvg('edit','w-3.5 h-3.5') ?> Edit</button>
                            <button onclick="openDelete(<?= $q['id'] ?>,'Q<?= $q['question_no'] ?>')" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg"><?= iconSvg('trash','w-3.5 h-3.5') ?> Delete</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="4" class="text-center py-14 text-slate-400"><?= iconSvg('question','w-10 h-10 mx-auto mb-3 opacity-40') ?><p class="text-sm">No questions yet. Add your first question!</p></td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Add SA Question</h3>
            <button onclick="closeModal('addModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-3 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Q No.</label>
                        <input type="number" name="question_no" value="<?= $nextNo ?>" min="1" class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-sm focus:border-purple-500 outline-none"></div>
                    <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Type</label>
                        <select name="question_type" class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-sm focus:border-purple-500 outline-none bg-white">
                            <option value="rating">★ Rating</option><option value="comment">✎ Comment</option>
                        </select></div>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Question Text <span class="text-red-500">*</span></label>
                    <textarea name="question_text" required rows="3" placeholder="Enter your question..." class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 outline-none resize-none"></textarea></div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-purple-600 hover:bg-purple-700 rounded-xl">Add Question</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Edit SA Question</h3>
            <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-3 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Q No.</label>
                        <input type="number" name="question_no" id="edit_no" min="1" class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-sm focus:border-purple-500 outline-none"></div>
                    <div class="col-span-2"><label class="block text-sm font-medium text-slate-700 mb-1">Type</label>
                        <select name="question_type" id="edit_type" class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-sm focus:border-purple-500 outline-none bg-white">
                            <option value="rating">★ Rating</option><option value="comment">✎ Comment</option>
                        </select></div>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Question Text</label>
                    <textarea name="question_text" id="edit_text" required rows="3" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20 outline-none resize-none"></textarea></div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-purple-600 hover:bg-purple-700 rounded-xl">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4"><?= iconSvg('trash','w-7 h-7 text-red-600') ?></div>
            <h3 class="text-lg font-semibold text-slate-800">Delete Question</h3>
            <p class="text-sm text-slate-500 mt-2">Delete <strong id="delete_name" class="text-slate-700"></strong>? All saved responses for this question will also be lost.</p>
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
function openEdit(q) {
    document.getElementById('edit_id').value   = q.id;
    document.getElementById('edit_no').value   = q.question_no;
    document.getElementById('edit_text').value = q.question_text;
    document.getElementById('edit_type').value = q.question_type;
    openModal('editModal');
}
function openDelete(id, name) {
    document.getElementById('delete_id').value         = id;
    document.getElementById('delete_name').textContent = name;
    openModal('deleteModal');
}
</script>
<?php include '../includes/admin_footer.php'; ?>
