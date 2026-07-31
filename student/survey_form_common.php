<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['entry_allowed']) || ($_SESSION['selected_role'] ?? '') !== 'student') {
    header('Location: /studentfeedbackucsh/index.php');
    exit;
}
requireRole('student');
updateAllFeedbackStatuses($conn);

$module = $surveyModule ?? 'academic';
$isEmbedded = ($_GET['embed'] ?? '') === '1';
$validModules = ['academic', 'student_affairs', 'administration'];
if (!in_array($module, $validModules, true)) {
    http_response_code(400);
    exit('Invalid feedback module.');
}

$user = getCurrentUser();
$studentStmt = $conn->prepare("SELECT id FROM students WHERE user_id = ?");
$studentStmt->bind_param('i', $user['id']);
$studentStmt->execute();
$studentId = (int) ($studentStmt->get_result()->fetch_assoc()['id'] ?? 0);
$studentStmt->close();
if (!$studentId) {
    setFlash('error', $LANG['flash_student_profile_missing'] ?? 'Student profile not found.');
    header('Location: feedback_forms.php');
    exit;
}

$formId = (int) ($_GET['form_id'] ?? 0);
if (!$formId) {
    header('Location: feedback_forms.php');
    exit;
}

if ($module === 'academic') {
    $formStmt = $conn->prepare(
        "SELECT ff.*, c.course_code, c.course_name, u.name AS teacher_name,
                ay.year_name, sm.semester_name, secm.section_name
         FROM feedback_forms ff
         JOIN sections sec ON sec.id = ff.section_id
         JOIN section_assignments sa ON sa.section_id = sec.id AND sa.student_id = ?
         JOIN courses c ON c.id = sec.course_id
         JOIN teachers t ON t.id = sec.teacher_id
         JOIN users u ON u.id = t.user_id
         LEFT JOIN academic_years ay ON ay.id = ff.academic_year_id
         LEFT JOIN semesters sm ON sm.id = ff.semester_id
         LEFT JOIN section_master secm ON secm.id = sec.section_id
         WHERE ff.id = ? AND ff.module = 'academic'
         LIMIT 1"
    );
    $formStmt->bind_param('ii', $studentId, $formId);
} else {
    $formStmt = $conn->prepare(
        "SELECT ff.*, ay.year_name, sm.semester_name
         FROM feedback_forms ff
         JOIN section_assignments sa
         JOIN sections sec ON sec.id = sa.section_id
              AND sec.academic_year_id = ff.academic_year_id
              AND sec.semester_id = ff.semester_id
         LEFT JOIN academic_years ay ON ay.id = ff.academic_year_id
         LEFT JOIN semesters sm ON sm.id = ff.semester_id
         WHERE ff.id = ? AND ff.module = ? AND sa.student_id = ?
         LIMIT 1"
    );
    $formStmt->bind_param('isi', $formId, $module, $studentId);
}
$formStmt->execute();
$form = $formStmt->get_result()->fetch_assoc();
$formStmt->close();
if (!$form) {
    setFlash('error', 'This Survey is not available for your assigned class.');
    header('Location: feedback_forms.php');
    exit;
}

$submittedStmt = $conn->prepare(
    "SELECT id FROM feedback_submissions WHERE form_id = ? AND student_id = ?"
);
$submittedStmt->bind_param('ii', $formId, $studentId);
$submittedStmt->execute();
$alreadySubmitted = $submittedStmt->get_result()->num_rows > 0;
$submittedStmt->close();
$canSubmit = $form['status'] === 'Active' && !$alreadySubmitted;

$questions = [];
if (!empty($form['question_set_id'])) {
    $questionStmt = $conn->prepare(
        "SELECT id, question_no, question_text, options_json
         FROM feedback_questions
         WHERE question_set_id = ?
         ORDER BY question_no"
    );
    $questionStmt->bind_param('i', $form['question_set_id']);
    $questionStmt->execute();
    $questions = $questionStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $questionStmt->close();
}

$submissionError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf()) {
        $submissionError = 'Invalid request token.';
    } elseif (!$canSubmit) {
        $submissionError = $LANG['flash_form_not_open'] ?? 'This Survey is not open for submission.';
    } elseif (!$questions) {
        $submissionError = 'This Survey has no questions.';
    } else {
        $answers = [];
        foreach ($questions as $question) {
            $options = normalizeSurveyOptions($question['options_json']);
            $selected = filter_input(
                INPUT_POST,
                'survey_' . $question['id'],
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]]
            );
            if (
                count($options) < 3 ||
                $selected === false || $selected === null || !array_key_exists($selected, $options)
            ) {
                $answers = [];
                break;
            }
            $answers[(int) $question['id']] = (int) $selected;
        }

        if (count($answers) !== count($questions)) {
            $submissionError = 'Please answer every Survey question.';
        } else {
            $conn->begin_transaction();
            try {
                $insertSubmission = $conn->prepare(
                    "INSERT INTO feedback_submissions (form_id, student_id) VALUES (?, ?)"
                );
                $insertSubmission->bind_param('ii', $formId, $studentId);
                if (!$insertSubmission->execute()) {
                    throw new RuntimeException('Unable to create Survey submission.');
                }
                $submissionId = $conn->insert_id;
                $insertSubmission->close();
                if ($submissionId < 1) {
                    throw new RuntimeException('Survey submission ID was not created.');
                }

                $insertAnswer = $conn->prepare(
                    "INSERT INTO feedback_survey_answers
                     (submission_id, question_id, selected_option_index)
                     VALUES (?, ?, ?)"
                );
                foreach ($answers as $questionId => $selectedIndex) {
                    $insertAnswer->bind_param('iii', $submissionId, $questionId, $selectedIndex);
                    if (!$insertAnswer->execute() || $insertAnswer->affected_rows !== 1) {
                        throw new RuntimeException('Unable to save a Survey answer.');
                    }
                }
                $insertAnswer->close();
                $conn->commit();
                if ($isEmbedded) {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => $LANG['survey_submitted_successfully'] ?? 'Survey submitted successfully.',
                        'form_id' => $formId,
                    ]);
                    exit;
                }
                setFlash('success', 'Survey submitted successfully.');
                header('Location: feedback_forms.php');
                exit;
            } catch (Throwable $error) {
                $conn->rollback();
                $submissionError = $error instanceof mysqli_sql_exception && $error->getCode() === 1062
                    ? 'You have already submitted this Survey.'
                    : 'Survey submission failed.';
            }
        }
    }

    if ($isEmbedded) {
        header('Content-Type: application/json');
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => $submissionError ?: 'Survey submission failed.',
        ]);
        exit;
    }

    if ($submissionError !== '') {
        setFlash('error', $submissionError);
    }
}

$pageTitle = $form['title'] ?? 'Survey';
?>
<?php if (!$isEmbedded): ?>
<!DOCTYPE html>
<html lang="<?= ($_SESSION['lang'] ?? 'en') === 'mm' ? 'my' : 'en' ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="/studentfeedbackucsh/assets/css/custom.css">
</head>

<body class="bg-slate-100 min-h-screen">
    <header class="bg-cyan-700 text-white">
        <div class="max-w-5xl mx-auto px-4 py-4 flex items-center justify-between">
            <div>
                <p class="font-bold">SFMS Survey</p>
                <p class="text-xs text-cyan-100"><?= e($user['name']) ?></p>
            </div>
            <a href="feedback_forms.php" class="text-sm hover:underline">Back to Surveys</a>
        </div>
    </header>
    <main class="max-w-5xl mx-auto px-4 py-8">
        <?php renderFlash(); ?>
<?php else: ?>
    <div class="survey-inline-content" data-form-id="<?= $formId ?>" data-module="<?= e($module) ?>">
        <div class="sticky top-0 z-10 flex justify-end py-2 mb-2 bg-slate-50/95 backdrop-blur-sm">
            <a href="/studentfeedbackucsh/student/feedback_forms.php"
                class="inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-cyan-700 bg-white border border-cyan-200 rounded-xl shadow-sm hover:bg-cyan-50 hover:border-cyan-300 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                    stroke-width="2" stroke="currentColor" class="w-4 h-4" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" />
                </svg>
                <?= e($LANG['back_to_feedback_forms'] ?? 'Back to Feedback Forms') ?>
            </a>
        </div>
<?php endif; ?>
        <section class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 mb-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-wider text-violet-600">Survey</p>
                    <h1 class="text-2xl font-bold text-slate-900 mt-1"><?= e($form['title']) ?></h1>
                    <div class="grid sm:grid-cols-2 gap-x-8 gap-y-2 mt-4 text-sm">
                        <div>
                            <span
                                class="font-semibold text-slate-700"><?= e($LANG['academic_year'] ?? 'Academic Year') ?>:</span>
                            <span class="text-slate-500"><?= e($form['year_name'] ?? '-') ?></span>
                        </div>
                        <div>
                            <span class="font-semibold text-slate-700"><?= e($LANG['semester'] ?? 'Semester') ?>:</span>
                            <span class="text-slate-500"><?= e($form['semester_name'] ?? '-') ?></span>
                        </div>

                        <?php if (($form['module'] ?? $module) === 'academic'): ?>
                            <div>
                                <span class="font-semibold text-slate-700"><?= e($LANG['section'] ?? 'Section') ?>:</span>
                                <span class="text-slate-500"><?= e($form['section_name'] ?? '-') ?></span>
                            </div>
                            <div>
                                <span class="font-semibold text-slate-700"><?= e($LANG['course'] ?? 'Course') ?>:</span>
                                <span class="text-slate-500">
                                    <?= e(trim(($form['course_code'] ?? '') . ' ' . ($form['course_name'] ?? '')) ?: '-') ?>
                                </span>
                            </div>
                            <div class="sm:col-span-2">
                                <span class="font-semibold text-slate-700"><?= e($LANG['teacher'] ?? 'Teacher') ?>:</span>
                                <span class="text-slate-500"><?= e($form['teacher_name'] ?? '-') ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?= badgeStatus($form['status']) ?>
            </div>
        </section>

        <?php if ($alreadySubmitted): ?>
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-800">
                You have already submitted this Survey.
            </div>
        <?php elseif (!$canSubmit): ?>
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-amber-800">
                This Survey is not currently open.
            </div>
        <?php elseif (!$questions): ?>
            <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-slate-500">
                This Survey has no questions.
            </div>
        <?php else: ?>
            <form method="post"
                action="<?= e(basename($_SERVER['PHP_SELF'])) ?>?form_id=<?= $formId ?><?= $isEmbedded ? '&amp;embed=1' : '' ?>"
                class="space-y-5" id="survey-form">
                <?= csrfField() ?>
                <?php foreach ($questions as $index => $question):
                    $options = normalizeSurveyOptions($question['options_json']);
                    ?>
                    <fieldset class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6">
                        <legend class="sr-only"><?= e($question['question_text']) ?></legend>
                        <p class="font-semibold text-slate-900">
                            <?= e(displayQuestionNumber($index + 1, $_SESSION['lang'] ?? 'en')) ?> <?= e($question['question_text']) ?>
                        </p>
                        <div class="grid md:grid-cols-2 gap-3 mt-4">
                            <?php foreach ($options as $optionIndex => $option): ?>
                                <label
                                    class="flex items-center gap-3 rounded-xl border border-slate-200 p-4 cursor-pointer hover:border-violet-400 hover:bg-violet-50">
                                    <input type="radio" name="survey_<?= (int) $question['id'] ?>" value="<?= (int) $optionIndex ?>"
                                        required class="w-4 h-4 text-violet-600">
                                    <span class="text-sm"><?= e($option['label']) ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>
                <!-- <button type="submit"
                class="w-full md:w-auto px-8 py-3 rounded-xl bg-violet-600 text-white font-semibold hover:bg-violet-700">
                Submit Survey
            </button> -->
                <div class="flex justify-end">
                    <button type="submit"
                        class="w-full md:w-auto px-8 py-3 rounded-xl bg-violet-600 text-white font-semibold hover:bg-violet-700">
                        <?= $LANG['submit_survey'] ?? 'Submit Survey' ?>
                    </button>
                </div>
            </form>
        <?php endif; ?>
<?php if (!$isEmbedded): ?>
    </main>
</body>

</html>
<?php else: ?>
    </div>
<?php endif; ?>
