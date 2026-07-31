<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$rawSetId = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['set_id'] ?? null)
    : ($_GET['set_id'] ?? null);

if ($rawSetId === null || $rawSetId === '') {
    header('Location: question_sets.php');
    exit;
}

$validatedSetId = filter_var(
    $rawSetId,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
if ($validatedSetId === false) {
    setFlash('error', 'Invalid Question Set selection.');
    header('Location: question_sets.php');
    exit;
}
$setId = (int) $validatedSetId;

$setStmt = $conn->prepare(
    "SELECT fqs.*, COALESCE(ay.year_name, '') AS year_name
     FROM feedback_question_sets fqs
     LEFT JOIN academic_years ay ON ay.id = fqs.academic_year_id
     WHERE fqs.id = ?"
);
$setStmt->bind_param('i', $setId);
$setStmt->execute();
$questionSet = $setStmt->get_result()->fetch_assoc();
$setStmt->close();
if (!$questionSet) {
    setFlash('error', 'This Question Set was deleted or is no longer available.');
    header('Location: question_sets.php');
    exit;
}

function surveyOptionsFromPost(): ?string
{
    $postedOptions = $_POST['options'] ?? [];
    if (!is_array($postedOptions)) {
        return null;
    }

    if (count($postedOptions) !== 3) {
        return null;
    }

    $labels = [];
    foreach ($postedOptions as $value) {
        if (!is_scalar($value)) {
            return null;
        }
        $option = clean((string) $value);
        if ($option === '') {
            return null;
        }
        $labels[] = $option;
    }

    $normalizedOptions = array_map(
        static fn($value) => function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value),
        $labels
    );

    if (count(array_unique($normalizedOptions)) !== 3) {
        return null;
    }

    $categories = ['Good', 'Fair', 'Bad'];
    $options = array_map(
        static fn($label, $index) => [
            'label' => $label,
            'category' => $categories[$index],
        ],
        $labels,
        array_keys($labels)
    );
    return json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        setFlash('error', 'Invalid request token.');
        header("Location: manage_questions.php?set_id=$setId");
        exit;
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'add' || $action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $questionNo = (int) ($_POST['question_no'] ?? 0);
        $questionText = clean($_POST['question_text'] ?? '');
        $optionsJson = surveyOptionsFromPost();

        if ($action === 'edit' && $id < 1) {
            setFlash('error', 'Invalid Survey question.');
        } elseif ($questionNo < 1 || $questionText === '' || $optionsJson === null) {
            setFlash('error', 'Enter a question number, question text, and exactly three distinct Survey options.');
        } else {
            if ($action === 'edit') {
                $owned = $conn->prepare(
                    "SELECT id FROM feedback_questions WHERE id = ? AND question_set_id = ?"
                );
                $owned->bind_param('ii', $id, $setId);
                $owned->execute();
                $isOwned = $owned->get_result()->num_rows === 1;
                $owned->close();

                if (!$isOwned) {
                    setFlash('error', 'Survey question not found in this question set.');
                    header("Location: manage_questions.php?set_id=$setId");
                    exit;
                }

                $legacyAnswer = $conn->prepare(
                    "SELECT 1 FROM feedback_survey_answers
                     WHERE question_id = ? AND selected_option_index >= 3
                     LIMIT 1"
                );
                $legacyAnswer->bind_param('i', $id);
                $legacyAnswer->execute();
                $hasLegacyFourthOptionAnswer =
                    $legacyAnswer->get_result()->num_rows > 0;
                $legacyAnswer->close();
                if ($hasLegacyFourthOptionAnswer) {
                    setFlash(
                        'error',
                        'This historical question has answers for an additional option and cannot be reduced to three options.'
                    );
                    header("Location: manage_questions.php?set_id=$setId");
                    exit;
                }
            }

            $duplicate = $conn->prepare(
                "SELECT id FROM feedback_questions
                 WHERE question_set_id = ? AND question_no = ? AND id <> ?"
            );
            $duplicate->bind_param('iii', $setId, $questionNo, $id);
            $duplicate->execute();
            $exists = $duplicate->get_result()->num_rows > 0;
            $duplicate->close();

            if ($exists) {
                setFlash('error', 'That question number already exists in this set.');
            } elseif ($action === 'add') {
                $stmt = $conn->prepare(
                    "INSERT INTO feedback_questions
                     (question_set_id, question_no, question_text, options_json)
                     VALUES (?,?,?,?)"
                );
                $stmt->bind_param(
                    'iiss',
                    $setId,
                    $questionNo,
                    $questionText,
                    $optionsJson
                );
                $saved = $stmt->execute();
                $stmt->close();
                setFlash($saved ? 'success' : 'error', $saved ? 'Survey question added.' : 'Unable to add Survey question.');
            } else {
                $stmt = $conn->prepare(
                    "UPDATE feedback_questions
                     SET question_no = ?, question_text = ?, options_json = ?
                     WHERE id = ? AND question_set_id = ?"
                );
                $stmt->bind_param('issii', $questionNo, $questionText, $optionsJson, $id, $setId);
                $saved = $stmt->execute();
                $stmt->close();
                setFlash($saved ? 'success' : 'error', $saved ? 'Survey question updated.' : 'Unable to update Survey question.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id < 1) {
            setFlash('error', 'Invalid Survey question.');
        } else {
            $stmt = $conn->prepare(
                "DELETE FROM feedback_questions WHERE id = ? AND question_set_id = ?"
            );
            $stmt->bind_param('ii', $id, $setId);
            $deleted = $stmt->execute() && $stmt->affected_rows === 1;
            $stmt->close();
            setFlash($deleted ? 'success' : 'error', $deleted ? 'Survey question deleted.' : 'Survey question not found.');
        }
    } else {
        setFlash('error', 'Unsupported question action.');
    }
    header("Location: manage_questions.php?set_id=$setId");
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 10);
if (!in_array($perPage, [10, 25, 50, 100], true)) {
    $perPage = 10;
}

$countStmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM feedback_questions
     WHERE question_set_id = ?"
);
$countStmt->bind_param('i', $setId);
$countStmt->execute();
$totalQuestions = (int) $countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$pg = paginate($totalQuestions, $perPage, $page);
$page = $pg['current'];
$offset = $pg['offset'];

$questionsStmt = $conn->prepare(
    "SELECT id, question_no, question_text, options_json
     FROM feedback_questions
     WHERE question_set_id = ?
     ORDER BY question_no
     LIMIT ? OFFSET ?"
);
$questionsStmt->bind_param('iii', $setId, $perPage, $offset);
$questionsStmt->execute();
$questions = $questionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$questionsStmt->close();

$nextStmt = $conn->prepare(
    "SELECT COALESCE(MAX(question_no), 0) + 1 AS next_no
     FROM feedback_questions
     WHERE question_set_id = ?"
);
$nextStmt->bind_param('i', $setId);
$nextStmt->execute();
$nextNo = (int) $nextStmt->get_result()->fetch_assoc()['next_no'];
$nextStmt->close();

$paginationParams = $_GET;
$paginationParams['set_id'] = $setId;
unset($paginationParams['page'], $paginationParams['per_page']);
$paginationUrl = 'manage_questions.php?' . http_build_query($paginationParams);

$pageTitle = 'Survey Questions';
include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>
<main class="flex-1 overflow-y-auto p-4 md:p-8">
    <div class="max-w-6xl mx-auto">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
            <div>
                <a href="question_sets.php" class="text-md font-bold text-indigo-600 hover:underline">← Question Sets</a>
                <h1 class="text-2xl font-bold text-slate-900 mt-2"><?= e($questionSet['title']) ?></h1>
                <p class="text-sm text-slate-500">
                    <?= e($questionSet['year_name']) ?> · <?= moduleBadge($questionSet['module']) ?>
                    · Survey questions only
                </p>
            </div>
            <button type="button" onclick="openModal('addModal')"
                class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
                <?= iconSvg('plus', 'w-4 h-4') ?>
                Add Survey Question
            </button>
        </div>

        <?php renderFlash(); ?>

        <div class="bg-white border border-slate-200 rounded-2xl shadow-sm overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-slate-600">
                    <tr>
                        <th class="text-left px-5 py-3 w-24">No.</th>
                        <th class="text-left px-5 py-3">Question and options</th>
                        <th class="text-right px-5 py-3 w-40">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($questions as $question):
                        $options = normalizeSurveyOptions($question['options_json']);
                        ?>
                        <tr>
                            <td class="px-5 py-4 font-semibold"><?= (int) $question['question_no'] ?></td>
                            <td class="px-5 py-4">
                                <p class="font-medium text-slate-900"><?= e($question['question_text']) ?></p>
                                <div class="flex flex-wrap gap-2 mt-2">
                                    <?php foreach ($options as $option): ?>
                                        <span
                                            class="px-2.5 py-1 rounded-full bg-violet-50 text-violet-700 text-xs"><?= e($option['label']) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td class="px-5 py-4 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button"
                                        data-question="<?= e(json_encode($question, JSON_UNESCAPED_UNICODE)) ?>"
                                        onclick="openEdit(this)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?>
                                        <?= $LANG["edit"] ?? "Edit" ?>
                                    </button>

                                    <button type="button" onclick="openDelete(<?= (int) $question['id'] ?>)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                        <?= iconSvg('trash', 'w-3.5 h-3.5') ?>
                                        <?= $LANG["delete"] ?? "Delete" ?>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$questions): ?>
                        <tr>
                            <td colspan="3" class="px-5 py-12 text-center text-slate-500">No Survey questions yet.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= paginationLinks($pg, $paginationUrl, $perPage) ?>
    </div>
</main>

<?php
function renderQuestionFields(int $number, string $prefix): void
{
    ?>
    <div class="space-y-4">
        <div>
            <label class="block text-sm font-medium mb-1">Question number</label>
            <input type="number" min="1" required name="question_no" id="<?= $prefix ?>_number" value="<?= $number ?>"
                class="w-full border rounded-xl px-4 py-2.5">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Question text</label>
            <textarea required name="question_text" id="<?= $prefix ?>_text" rows="3"
                class="w-full border rounded-xl px-4 py-2.5"></textarea>
        </div>
        <div>
            <label class="block text-sm font-medium mb-2">Survey options</label>
            <div class="grid md:grid-cols-2 gap-3">
                <?php for ($i = 0; $i < 3; $i++): ?>
                    <input name="options[]" id="<?= $prefix ?>_option_<?= $i ?>" required placeholder="Option <?= $i + 1 ?>"
                        class="w-full border rounded-xl px-4 py-2.5">
                <?php endfor; ?>
            </div>
            <p class="text-xs text-slate-500 mt-2">Exactly three distinct options are required.</p>
        </div>
    </div>
    <?php
}
?>

<div id="addModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-2xl w-full max-w-2xl p-6">
        <h2 class="text-xl font-bold mb-5">Add Survey Question</h2>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="set_id" value="<?= $setId ?>">
            <?php renderQuestionFields($nextNo, 'add'); ?>
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="closeModal('addModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG["cancel"] ?? "Cancel" ?></button>
                <button
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl">
                    <?= $LANG["save"] ?? "Save" ?>
                </button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="bg-white rounded-2xl w-full max-w-2xl p-6">
        <h2 class="text-xl font-bold mb-5">Edit Survey Question</h2>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="set_id" value="<?= $setId ?>">
            <input type="hidden" name="id" id="edit_id">
            <?php renderQuestionFields(1, 'edit'); ?>
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="closeModal('editModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG["cancel"] ?? "Cancel" ?></button>
                <button
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG["save"] ?? "Save" ?></button>
            </div>
        </form>
    </div>
</div>

<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <form method="post" class="bg-white rounded-2xl w-full max-w-md p-6">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="set_id" value="<?= $setId ?>">
        <input type="hidden" name="id" id="delete_id">
        <h2 class="text-xl font-bold">Delete Survey Question?</h2>
        <p class="text-sm text-slate-500 mt-2">Its Survey answers will also be deleted.</p>
        <div class="flex justify-end gap-3 mt-6">
            <button type="button" onclick="closeModal('deleteModal')"
                class="flex-1 px-4 py-2.5 text-sm font-semibold bg-slate-500 text-white hover:bg-slate-600 rounded-xl transition-colors"><?= $LANG["cancel"] ?? "Cancel" ?></button>
            <button class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl">
                <?= $LANG["delete"] ?? "Delete" ?>
            </button>
        </div>
    </form>
</div>

<script>
    function showModal(id) {
        var modal = document.getElementById(id);
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function hideModal(id) {
        var modal = document.getElementById(id);
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
    function openEdit(button) {
        var question = JSON.parse(button.dataset.question);
        document.getElementById('edit_id').value = question.id;
        document.getElementById('edit_number').value = question.question_no;
        document.getElementById('edit_text').value = question.question_text;
        var options = JSON.parse(question.options_json || '[]');
        for (var i = 0; i < 3; i++) {
            var option = options[i] || '';
            document.getElementById('edit_option_' + i).value =
                typeof option === 'object' ? (option.label || '') : option;
        }
        showModal('editModal');
    }
    function openDelete(id) {
        document.getElementById('delete_id').value = id;
        showModal('deleteModal');
    }
    window.openModal = showModal;
    window.closeModal = hideModal;
</script>
<?php include '../includes/admin_footer.php'; ?>
