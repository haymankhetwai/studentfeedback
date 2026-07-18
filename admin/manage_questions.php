<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$filterSet = (int)($_GET['set_id'] ?? 0);

if (!$filterSet) {
    header('Location: question_sets.php');
    exit;
}

$setData = $conn->query("SELECT fqs.*, ay.year_name FROM feedback_question_sets fqs JOIN academic_years ay ON fqs.academic_year_id=ay.id WHERE fqs.id=$filterSet")->fetch_assoc();
if (!$setData) {
    setFlash('error', 'Question Set not found.');
    header('Location: question_sets.php');
    exit;
}

$module = $setData['module'];
$moduleLabel = match($module) {
    'student_affairs' => 'Student Affairs',
    'administration' => 'Administration',
    default => 'Academic',
};
$pageTitle = "$moduleLabel Questions — " . $setData['title'];
$activeMenu = 'question_sets';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $qno = (int)($_POST['question_no'] ?? 0);
        $qtext = clean($_POST['question_text'] ?? '');
        $qtype = in_array($_POST['question_type'] ?? '', ['rating', 'comment', 'survey']) ? $_POST['question_type'] : 'rating';
        if ($qno && $qtext) {
            $optionsJson = null;
            if ($qtype === 'survey') {
                $opts = [];
                for ($i = 1; $i <= 4; $i++) {
                    $opt = clean($_POST['survey_option_' . $i] ?? '');
                    if ($opt === '') {
                        setFlash('error', 'All 4 survey options are required.');
                        header("Location: manage_questions.php?set_id=$filterSet");
                        exit;
                    }
                    $opts[] = $opt;
                }
                $optionsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
            }
            $chk = $conn->prepare("SELECT 1 FROM feedback_questions WHERE question_set_id=? AND question_no=? AND question_type=?");
            $chk->bind_param('iis', $filterSet, $qno, $qtype);
            $chk->execute();
            $exists = (bool)$chk->get_result()->num_rows;
            $chk->close();
            if ($exists) {
                setFlash('error', ucfirst($qtype) . ' Question #' . $qno . ' already exists in this set.');
            } else {
                $stmt = $conn->prepare("INSERT INTO feedback_questions (question_set_id, module, question_no, question_text, question_type, options_json) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param('isisss', $filterSet, $module, $qno, $qtext, $qtype, $optionsJson);
                $stmt->execute() ? setFlash('success', 'Question added.') : setFlash('error', 'Failed to add question.');
                $stmt->close();
            }
        } else {
            setFlash('error', 'All fields required.');
        }
        header("Location: manage_questions.php?set_id=$filterSet");
        exit;
    }

    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $qno = (int)($_POST['question_no'] ?? 0);
        $qtext = clean($_POST['question_text'] ?? '');
        $qtype = in_array($_POST['question_type'] ?? '', ['rating', 'comment', 'survey']) ? $_POST['question_type'] : 'rating';
        if ($id && $qno && $qtext) {
            $optionsJson = null;
            if ($qtype === 'survey') {
                $opts = [];
                for ($i = 1; $i <= 4; $i++) {
                    $opt = clean($_POST['survey_option_' . $i] ?? '');
                    if ($opt === '') {
                        setFlash('error', 'All 4 survey options are required.');
                        header("Location: manage_questions.php?set_id=$filterSet");
                        exit;
                    }
                    $opts[] = $opt;
                }
                $optionsJson = json_encode($opts, JSON_UNESCAPED_UNICODE);
            }
            $chk = $conn->prepare("SELECT fq.id FROM feedback_questions fq WHERE fq.id=? AND fq.question_set_id=?");
            $chk->bind_param('ii', $id, $filterSet);
            $chk->execute();
            $owned = (bool)$chk->get_result()->num_rows;
            $chk->close();
            if (!$owned) {
                setFlash('error', 'Question not found in this set.');
            } else {
                $chk2 = $conn->prepare("SELECT 1 FROM feedback_questions WHERE question_set_id=? AND question_no=? AND question_type=? AND id!=?");
                $chk2->bind_param('iisi', $filterSet, $qno, $qtype, $id);
                $chk2->execute();
                $dup = (bool)$chk2->get_result()->num_rows;
                $chk2->close();
                if ($dup) {
                    setFlash('error', ucfirst($qtype) . ' Question #' . $qno . ' already exists in this set.');
                } else {
                    $stmt = $conn->prepare("UPDATE feedback_questions SET question_no=?,question_text=?,question_type=?,options_json=? WHERE id=?");
                    $stmt->bind_param('isssi', $qno, $qtext, $qtype, $optionsJson, $id);
                    $stmt->execute() ? setFlash('success', 'Question updated.') : setFlash('error', 'Update failed.');
                    $stmt->close();
                }
            }
        }
        header("Location: manage_questions.php?set_id=$filterSet");
        exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $chk = $conn->prepare("SELECT id FROM feedback_questions WHERE id=? AND question_set_id=?");
            $chk->bind_param('ii', $id, $filterSet);
            $chk->execute();
            if ($chk->get_result()->num_rows > 0) {
                $stmt = $conn->prepare("DELETE FROM feedback_questions WHERE id=? AND question_set_id=?");
                $stmt->bind_param('ii', $id, $filterSet);
                $stmt->execute() ? setFlash('success', 'Question deleted.') : setFlash('error', 'Cannot delete.');
                $stmt->close();
            } else {
                setFlash('error', 'Question not found.');
            }
            $chk->close();
        }
        header("Location: manage_questions.php?set_id=$filterSet");
        exit;
    }

    if ($action === 'move') {
        $id = (int)($_POST['id'] ?? 0);
        $dir = $_POST['direction'] ?? '';
        if ($id && in_array($dir, ['up', 'down'])) {
            $cur = $conn->query("SELECT id, question_no, question_type FROM feedback_questions WHERE id=$id AND question_set_id=$filterSet AND module='$module'")->fetch_assoc();
            if ($cur) {
                $qno = (int)$cur['question_no'];
                $qtype = $cur['question_type'];
                if ($dir === 'up') {
                    $prev = $conn->query("SELECT id, question_no FROM feedback_questions WHERE question_set_id=$filterSet AND module='$module' AND question_type='$qtype' AND question_no < $qno ORDER BY question_no DESC LIMIT 1")->fetch_assoc();
                    if ($prev) {
                        $conn->query("UPDATE feedback_questions SET question_no={$prev['question_no']} WHERE id=$id");
                        $conn->query("UPDATE feedback_questions SET question_no=$qno WHERE id={$prev['id']}");
                        setFlash('success', 'Question moved up.');
                    }
                } else {
                    $next = $conn->query("SELECT id, question_no FROM feedback_questions WHERE question_set_id=$filterSet AND module='$module' AND question_type='$qtype' AND question_no > $qno ORDER BY question_no ASC LIMIT 1")->fetch_assoc();
                    if ($next) {
                        $conn->query("UPDATE feedback_questions SET question_no={$next['question_no']} WHERE id=$id");
                        $conn->query("UPDATE feedback_questions SET question_no=$qno WHERE id={$next['id']}");
                        setFlash('success', 'Question moved down.');
                    }
                }
            }
        }
        header("Location: manage_questions.php?set_id=$filterSet");
        exit;
    }
    header("Location: manage_questions.php?set_id=$filterSet");
    exit;
}

$search = clean($_GET['search'] ?? '');
$perPage = max(10, min(100, (int)($_GET['per_page'] ?? 20)));
$page = max(1, (int)($_GET['page'] ?? 1));

$conds = ["fq.question_set_id=$filterSet"];
$params = [];
$types = '';
if ($search) { $s2 = "%$search%"; $conds[] = "fq.question_text LIKE ?"; $params[] = $s2; $types .= 's'; }
$where = 'WHERE ' . implode(' AND ', $conds);

$cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM feedback_questions fq $where");
if ($types) $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$total = (int)$cntStmt->get_result()->fetch_assoc()['c'];
$cntStmt->close();

$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];
$p2 = array_merge($params, [$perPage, $off]);
$t2 = $types . 'ii';

$dataStmt = $conn->prepare("SELECT fq.* FROM feedback_questions fq $where ORDER BY fq.question_type, fq.question_no LIMIT ? OFFSET ?");
$dataStmt->bind_param($t2, ...$p2);
$dataStmt->execute();
$rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dataStmt->close();

$maxRating = (int)$conn->query("SELECT COALESCE(MAX(question_no),0) AS m FROM feedback_questions WHERE question_set_id=$filterSet AND question_type='rating'")->fetch_assoc()['m'];
$maxComment = (int)$conn->query("SELECT COALESCE(MAX(question_no),0) AS m FROM feedback_questions WHERE question_set_id=$filterSet AND question_type='comment'")->fetch_assoc()['m'];
$maxSurvey = (int)$conn->query("SELECT COALESCE(MAX(question_no),0) AS m FROM feedback_questions WHERE question_set_id=$filterSet AND question_type='survey'")->fetch_assoc()['m'];
$nextNo = max($maxRating, $maxComment, $maxSurvey) + 1;

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<!-- Breadcrumb -->
<div class="flex items-center gap-2 text-sm text-slate-500 mb-4">
    <a href="dashboard.php" class="hover:text-indigo-600"><?= iconSvg('home', 'w-4 h-4') ?></a>
    <span>/</span>
    <a href="question_sets.php" class="hover:text-indigo-600">Question Sets</a>
    <span>/</span>
    <span class="text-slate-800 font-medium"><?= $moduleLabel ?> Questions</span>
    <span>/</span>
    <span class="text-indigo-600 font-medium"><?= e($setData['title']) ?></span>
</div>

<!-- Set Info Banner -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <h2 class="text-xl font-bold text-slate-800"><?= e($setData['title']) ?></h2>
                <?php if ($setData['status'] === 'active'): ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">Active</span>
                <?php else: ?>
                    <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">Inactive</span>
                <?php endif ?>
            </div>
            <div class="flex items-center gap-4 text-sm text-slate-500">
                <span class="flex items-center gap-1"><?= iconSvg('academic', 'w-4 h-4 text-indigo-500') ?> <?= e($setData['year_name']) ?></span>
                <span class="flex items-center gap-1"><?= moduleBadge($module) ?></span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-cyan-100 text-cyan-700"><?= $total ?> Questions</span>
            </div>
        </div>
        <div class="flex gap-2">
            <button onclick="openModal('previewModal')" class="inline-flex items-center gap-2 bg-slate-600 hover:bg-slate-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm transition-all hover:-translate-y-0.5">
                <?= iconSvg('eye', 'w-4 h-4') ?> Preview
            </button>
            <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
                <?= iconSvg('plus', 'w-4 h-4') ?> Add Question
            </button>
        </div>
    </div>
</div>
<?php renderFlash() ?>

<!-- Search -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 mb-6">
    <form method="GET" class="flex items-center gap-3">
        <input type="hidden" name="set_id" value="<?= $filterSet ?>">
        <div class="relative flex-1 max-w-md">
            <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search', 'w-4 h-4') ?></span>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search questions..."
                class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
        </div>
        <button type="submit" class="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700">Search</button>
        <?php if ($search): ?>
            <a href="manage_questions.php?set_id=<?= $filterSet ?>" class="px-4 py-2 bg-slate-100 text-slate-600 text-sm font-semibold rounded-xl hover:bg-slate-200">Clear</a>
        <?php endif ?>
    </form>
</div>

<!-- Questions Table -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 w-12 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">Question Text</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">Type</th>
                    <!-- <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold w-32">Order</th> -->
                    <th class="text-right px-5 py-3 text-slate-500 text-sm font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows): foreach ($rows as $i => $row): ?>
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-5 py-3 text-center">
                            <span class="w-7 h-7 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold flex items-center justify-center"><?= $row['question_no'] ?></span>
                        </td>
                        <td class="px-5 py-3 text-sm text-slate-700 max-w-md"><?= e($row['question_text']) ?></td>
                        <td class="px-5 py-3">
                            <?php if ($row['question_type'] === 'rating'): ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                    <?= iconSvg('star', 'w-3.5 h-3.5') ?> Rating
                                </span>
                            <?php elseif ($row['question_type'] === 'survey'): ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-violet-100 text-violet-800">
                                    <?= iconSvg('clipboard', 'w-3.5 h-3.5') ?> Survey (MCQ)
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-cyan-100 text-cyan-800">
                                    <?= iconSvg('clipboard', 'w-3.5 h-3.5') ?> Comment
                                </span>
                            <?php endif ?>
                        </td>
                        <!-- <td class="px-5 py-3">
                            <div class="flex items-center justify-center gap-1">
                                <form method="POST" class="inline"><?= csrfField() ?>
                                    <input type="hidden" name="action" value="move">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="p-1 rounded hover:bg-slate-200 text-slate-400 hover:text-slate-600" title="Move Up">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/></svg>
                                    </button>
                                </form>
                                <form method="POST" class="inline"><?= csrfField() ?>
                                    <input type="hidden" name="action" value="move">
                                    <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="p-1 rounded hover:bg-slate-200 text-slate-400 hover:text-slate-600" title="Move Down">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                                    </button>
                                </form>
                            </div>
                        </td> -->
                        <td class="px-5 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                <button onclick='openEdit(<?= json_encode($row, ENT_QUOTES) ?>)'
                                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-lg">
                                    <?= iconSvg('edit', 'w-3.5 h-3.5') ?> Edit
                                </button>
                                <button onclick="openDelete(<?= $row['id'] ?>,'Q<?= $row['question_no'] ?>')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                    <?= iconSvg('trash', 'w-3.5 h-3.5') ?> Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr>
                        <td colspan="5" class="text-center py-16 text-slate-400">
                            <?= iconSvg('question', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm">No questions found.</p>
                            <button onclick="openModal('addModal')" class="mt-3 inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-xl">
                                <?= iconSvg('plus', 'w-4 h-4') ?> Add First Question
                            </button>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100">
        <?= paginationLinks($pg, 'manage_questions.php?set_id=' . $filterSet . ($search ? '&search=' . urlencode($search) : ''), $perPage) ?>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Add <?= $moduleLabel ?> Question</h3>
            <button onclick="closeModal('addModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Question # <span class="text-red-500">*</span></label>
                        <input type="number" name="question_no" id="add_qno" value="<?= $nextNo ?>" required min="1"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Type <span class="text-red-500">*</span></label>
                        <select name="question_type" id="add_qtype" required
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none bg-white">
                            <option value="rating">Rating</option>
                            <option value="comment">Comment</option>
                            <option value="survey">Survey (MCQ)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Question Text <span class="text-red-500">*</span></label>
                    <textarea name="question_text" required rows="3" placeholder="Enter the question..."
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none resize-none"></textarea>
                </div>
                <div id="add_survey_options" class="hidden space-y-3">
                    <label class="block text-sm font-medium text-slate-700">Survey Options (4 choices) <span class="text-red-500">*</span></label>
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <input type="text" name="survey_option_<?= $i ?>" maxlength="255" placeholder="Option <?= $i ?>"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
                    <?php endfor ?>
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl">Add Question</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Edit Question</h3>
            <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Question #</label>
                        <input type="number" name="question_no" id="edit_qno" min="1" required
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Type</label>
                        <select name="question_type" id="edit_qtype"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none bg-white">
                            <option value="rating">Rating</option>
                            <option value="comment">Comment</option>
                            <option value="survey">Survey (MCQ)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Question Text</label>
                    <textarea name="question_text" id="edit_qtext" rows="3" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none resize-none"></textarea>
                </div>
                <div id="edit_survey_options" class="hidden space-y-3">
                    <label class="block text-sm font-medium text-slate-700">Survey Options (4 choices) <span class="text-red-500">*</span></label>
                    <?php for ($i = 1; $i <= 4; $i++): ?>
                        <input type="text" name="survey_option_<?= $i ?>" id="edit_survey_opt_<?= $i ?>" maxlength="255" placeholder="Option <?= $i ?>"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 outline-none">
                    <?php endfor ?>
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                <?= iconSvg('trash', 'w-7 h-7 text-red-600') ?>
            </div>
            <h3 class="text-lg font-semibold text-slate-800">Delete Question</h3>
            <p class="text-sm text-slate-500 mt-2">Delete <strong id="delete_name" class="text-slate-700"></strong>?</p>
            <p class="text-xs text-red-500 mt-2">This action cannot be undone.</p>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')" class="flex-1 px-4 py-2.5 text-sm border border-slate-200 rounded-xl">Cancel</button>
                <button type="submit" class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl">Delete</button>
            </div>
        </form>
    </div>
</div>

<!-- Preview Modal -->
<div id="previewModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl modal-box max-h-[80vh] flex flex-col">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 flex-shrink-0">
            <div>
                <h3 class="font-semibold text-slate-800">Preview Question Set</h3>
                <p class="text-xs text-slate-500 mt-0.5"><?= e($setData['year_name']) ?> / <?= $moduleLabel ?></p>
            </div>
            <button onclick="closeModal('previewModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <div class="px-6 py-5 overflow-y-auto flex-1" id="previewContent">
            <?php
            $pStmt = $conn->prepare("SELECT * FROM feedback_questions WHERE question_set_id=? ORDER BY question_type, question_no");
            $pStmt->bind_param('i', $filterSet);
            $pStmt->execute();
            $previewRows = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $pStmt->close();
            $currentType = '';
            foreach ($previewRows as $pr):
                if ($pr['question_type'] !== $currentType):
                    $currentType = $pr['question_type'];
                    $typeLabel = match($currentType) { 'rating' => 'Rating Questions', 'comment' => 'Comment Questions', 'survey' => 'Survey Questions (MCQ)', default => '' };
            ?>
                <div class="mb-4 mt-2 first:mt-0">
                    <h4 class="text-xs font-bold uppercase tracking-wider text-indigo-600 mb-2"><?= $typeLabel ?></h4>
                </div>
            <?php endif ?>
                <div class="flex items-start gap-3 py-2 border-b border-slate-50 last:border-0">
                    <span class="w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold flex items-center justify-center flex-shrink-0 mt-0.5"><?= $pr['question_no'] ?></span>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-slate-700"><?= e($pr['question_text']) ?></p>
                        <?php if ($pr['question_type'] === 'survey' && $pr['options_json']): ?>
                            <div class="mt-1.5 flex flex-wrap gap-1.5">
                                <?php foreach (json_decode($pr['options_json'], true) as $oi => $opt): ?>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-violet-50 text-violet-700 border border-violet-200"><?= ($oi + 1) . '. ' . e($opt) ?></span>
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>
                    </div>
                    <?php //if ($pr['question_type'] === 'rating'): ?>
                        <!-- <span class="text-xs text-amber-600 flex-shrink-0">1-5 stars</span> -->
                    <?php //endif ?>
                </div>
            <?php endforeach ?>
            <?php if (empty($previewRows)): ?>
                <p class="text-center text-slate-400 text-sm py-8">No questions in this set.</p>
            <?php endif ?>
        </div>
        <div class="flex justify-end px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl flex-shrink-0">
            <button onclick="closeModal('previewModal')" class="px-5 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl">Close</button>
        </div>
    </div>
</div>

<script>
var maxRating = <?= $maxRating ?>;
var maxComment = <?= $maxComment ?>;
var maxSurvey = <?= $maxSurvey ?>;

function toggleAddSurveyOpts() {
    var sel = document.getElementById('add_qtype').value;
    document.getElementById('add_survey_options').classList.toggle('hidden', sel !== 'survey');
}
function toggleEditSurveyOpts() {
    var sel = document.getElementById('edit_qtype').value;
    document.getElementById('edit_survey_options').classList.toggle('hidden', sel !== 'survey');
}

document.getElementById('add_qtype').addEventListener('change', function () {
    toggleAddSurveyOpts();
    var nextNo;
    if (this.value === 'comment') nextNo = maxComment + 1;
    else if (this.value === 'survey') nextNo = maxSurvey + 1;
    else nextNo = maxRating + 1;
    document.getElementById('add_qno').value = nextNo;
});
document.getElementById('edit_qtype').addEventListener('change', toggleEditSurveyOpts);

function openEdit(row) {
    document.getElementById('edit_id').value = row.id;
    document.getElementById('edit_qno').value = row.question_no;
    document.getElementById('edit_qtype').value = row.question_type;
    document.getElementById('edit_qtext').value = row.question_text;
    toggleEditSurveyOpts();
    if (row.question_type === 'survey' && row.options_json) {
        var opts = JSON.parse(row.options_json);
        for (var i = 0; i < opts.length && i < 4; i++) {
            document.getElementById('edit_survey_opt_' + (i + 1)).value = opts[i];
        }
    } else {
        for (var j = 1; j <= 4; j++) {
            document.getElementById('edit_survey_opt_' + j).value = '';
        }
    }
    openModal('editModal');
}
function openDelete(id, name) {
    document.getElementById('delete_id').value = id;
    document.getElementById('delete_name').textContent = name;
    openModal('deleteModal');
}
</script>
<?php include '../includes/admin_footer.php'; ?>
