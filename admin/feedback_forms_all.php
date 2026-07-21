<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['all_feedback_forms'] ?? 'All Feedback Forms';
$activeMenu = 'forms';

updateAllFeedbackStatuses($conn);

$academicYears = $conn->query("SELECT id, year_name FROM academic_years WHERE status='active' ORDER BY year_name DESC")->fetch_all(MYSQLI_ASSOC);
$semesters = $conn->query("SELECT id, semester_name FROM semesters ORDER BY id ASC")->fetch_all(MYSQLI_ASSOC);
$sectionList = $conn->query("SELECT s.id, c.course_name, c.course_code, s.section, COALESCE(ay.year_name, '') AS academic_year, s.academic_year_id, s.semester_id, u.name AS teacher_name FROM sections s JOIN courses c ON s.course_id=c.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id LEFT JOIN academic_years ay ON s.academic_year_id=ay.id ORDER BY c.course_name, s.section")->fetch_all(MYSQLI_ASSOC);

if (isset($_GET['ajax_question_sets'])) {
    header('Content-Type: application/json');
    $ayId = (int) ($_GET['academic_year_id'] ?? 0);
    $mod = clean($_GET['module'] ?? 'academic');
    if ($ayId) {
        $stmt = $conn->prepare("SELECT fqs.id, fqs.title, (SELECT COUNT(*) FROM feedback_questions WHERE question_set_id = fqs.id) AS question_count FROM feedback_question_sets fqs WHERE fqs.academic_year_id=? AND fqs.module=? AND fqs.status='active' ORDER BY fqs.title ASC");
        $stmt->bind_param('is', $ayId, $mod);
        $stmt->execute();
        $sets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        echo json_encode(['sets' => $sets]);
    } else {
        echo json_encode(['sets' => []]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $module = in_array($_POST['module'] ?? '', ['academic', 'student_affairs', 'administration']) ? $_POST['module'] : 'academic';
        $ayId = (int) ($_POST['academic_year_id'] ?? 0);
        $semId = (int) ($_POST['semester_id'] ?? 0);
        $title = clean($_POST['title'] ?? '');
        $start = clean($_POST['start_date'] ?? '');
        $end = clean($_POST['end_date'] ?? '');
        $sec = (int) ($_POST['section_id'] ?? 0);
        $universityName = clean($_POST['university_name'] ?? '');
        $universityCampus = clean($_POST['university_campus'] ?? '');

        // Auto-detect question set
        $qsStmt = $conn->prepare("SELECT fqs.id FROM feedback_question_sets fqs WHERE fqs.academic_year_id=? AND fqs.module=? AND fqs.status='active' LIMIT 1");
        $qsStmt->bind_param('is', $ayId, $module);
        $qsStmt->execute();
        $qsRow = $qsStmt->get_result()->fetch_assoc();
        $qsStmt->close();
        $questionSetId = $qsRow ? (int) $qsRow['id'] : 0;

        if (!$ayId || !$semId || !$questionSetId || !$title || !$start || !$end) {
            setFlash('error', 'All fields are required. Make sure a Question Set exists for the selected Year + Module.');
            header('Location: feedback_forms_all.php');
            exit;
        }
        if ($module === 'academic' && !$sec) {
            setFlash('error', 'Section is required for Academic forms.');
            header('Location: feedback_forms_all.php');
            exit;
        }

        $nowDateTime = date('Y-m-d H:i');
        $startDateTime = date('Y-m-d H:i', strtotime($start));
        $endDateTime = date('Y-m-d H:i', strtotime($end));
        if ($startDateTime < $nowDateTime) {
            setFlash('error', 'Start date cannot be before now.');
            header('Location: feedback_forms_all.php');
            exit;
        }
        if ($endDateTime < $startDateTime) {
            setFlash('error', 'End date must be after start date.');
            header('Location: feedback_forms_all.php');
            exit;
        }

        $formStatus = calculateFormStatus($start, $end);

        if ($module === 'academic') {
            $stmt = $conn->prepare("INSERT INTO feedback_forms (module,academic_year_id,semester_id,question_set_id,section_id,title,start_date,end_date,status) VALUES ('academic',?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iiiissss', $ayId, $semId, $questionSetId, $sec, $title, $start, $end, $formStatus);
        } else {
            $stmt = $conn->prepare("INSERT INTO feedback_forms (module,academic_year_id,semester_id,question_set_id,title,start_date,end_date,status,university_name,university_campus) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('siiissssss', $module, $ayId, $semId, $questionSetId, $title, $start, $end, $formStatus, $universityName, $universityCampus);
        }
        $stmt->execute() ? setFlash('success', 'Feedback form created.') : setFlash('error', 'Failed.');
        $stmt->close();
    }
    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $editModule = clean($_POST['module'] ?? '');
        $editAyId = (int) ($_POST['academic_year_id'] ?? 0);
        $editSemId = (int) ($_POST['semester_id'] ?? 0);
        $editQsId = (int) ($_POST['question_set_id'] ?? 0);
        $sec = (int) ($_POST['section_id'] ?? 0);
        $title = clean($_POST['title'] ?? '');
        $start = clean($_POST['start_date'] ?? '');
        $end = clean($_POST['end_date'] ?? '');
        $universityName = clean($_POST['university_name'] ?? '');
        $universityCampus = clean($_POST['university_campus'] ?? '');
        if ($id && $title) {
            if ($start && $end) {
                $nowDateTime = date('Y-m-d H:i');
                $startDateTime = date('Y-m-d H:i', strtotime($start));
                $endDateTime = date('Y-m-d H:i', strtotime($end));
                if ($startDateTime < $nowDateTime) {
                    setFlash('error', 'Start date cannot be before now.');
                    header('Location: feedback_forms_all.php');
                    exit;
                }
                if ($endDateTime < $startDateTime) {
                    setFlash('error', 'End date must be after start date.');
                    header('Location: feedback_forms_all.php');
                    exit;
                }
            }
            $formStatus = calculateFormStatus($start, $end);
            if ($editModule === 'academic') {
                $stmt = $conn->prepare("UPDATE feedback_forms SET section_id=?,title=?,start_date=?,end_date=?,status=?,academic_year_id=?,semester_id=?,question_set_id=? WHERE id=?");
                $stmt->bind_param('isssiiiii', $sec, $title, $start, $end, $formStatus, $editAyId, $editSemId, $editQsId, $id);
            } else {
                $stmt = $conn->prepare("UPDATE feedback_forms SET title=?,start_date=?,end_date=?,status=?,academic_year_id=?,semester_id=?,question_set_id=?,university_name=?,university_campus=? WHERE id=?");
                $stmt->bind_param('ssssiiissi', $title, $start, $end, $formStatus, $editAyId, $editSemId, $editQsId, $universityName, $universityCampus, $id);
            }
            $stmt->execute() ? setFlash('success', 'Form updated.') : setFlash('error', 'Update failed.');
            $stmt->close();
        }
    }
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM feedback_forms WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute() ? setFlash('success', 'Form deleted.') : setFlash('error', 'Cannot delete.');
            $stmt->close();
        }
    }
    header('Location: feedback_forms_all.php');
    exit;
}

$search = clean($_GET['search'] ?? '');
$filterMod = clean($_GET['module'] ?? '');
$filterAY = (int) ($_GET['ay_id'] ?? 0);
$perPage = max(10, min(100, (int) ($_GET['per_page'] ?? 15)));
$page = max(1, (int) ($_GET['page'] ?? 1));

$conds = [];
$params = '';
$types = '';
if ($filterMod) {
    $conds[] = "ff.module=?";
    $params .= 's';
    $types .= 's';
}
if ($filterAY) {
    $conds[] = "ff.academic_year_id=?";
    $params .= 'i';
    $types .= 'i';
}
if ($search) {
    $s2 = "%$search%";
    $conds[] = "(ff.title LIKE ? OR ff.module LIKE ?)";
    $params .= 'ss';
    $types .= 'ss';
}
$where = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$countSql = "SELECT COUNT(*) AS c FROM feedback_forms ff $where";
if ($types) {
    $c = $conn->prepare($countSql);
    $bindVals = [];
    if ($filterMod)
        $bindVals[] = $filterMod;
    if ($filterAY)
        $bindVals[] = $filterAY;
    if ($search) {
        $bindVals[] = $search;
        $bindVals[] = $search;
    }
    $c->bind_param($types, ...$bindVals);
    $c->execute();
    $total = (int) $c->get_result()->fetch_assoc()['c'];
    $c->close();
} else {
    $total = (int) $conn->query($countSql)->fetch_assoc()['c'];
}

$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];

$sql = "SELECT ff.*, ay.year_name, sm.semester_name, fqs.title AS question_set_title,
    (SELECT COUNT(*) FROM feedback_submissions fs WHERE fs.form_id=ff.id) AS submission_count,
    (SELECT COUNT(*) FROM feedback_questions fq WHERE fq.question_set_id = ff.question_set_id) AS question_count
    FROM feedback_forms ff
    LEFT JOIN academic_years ay ON ff.academic_year_id=ay.id
    LEFT JOIN semesters sm ON ff.semester_id=sm.id
    LEFT JOIN feedback_question_sets fqs ON ff.question_set_id=fqs.id
    $where
    ORDER BY ff.id DESC
    LIMIT ? OFFSET ?";

if ($types) {
    $stmt = $conn->prepare($sql);
    $allBind = [];
    if ($filterMod)
        $allBind[] = $filterMod;
    if ($filterAY)
        $allBind[] = $filterAY;
    if ($search) {
        $allBind[] = $search;
        $allBind[] = $search;
    }
    $allBind[] = $perPage;
    $allBind[] = $off;
    $stmt->bind_param($types . 'ii', ...$allBind);
} else {
    $stmt = $conn->prepare($sql);
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
        <h2 class="text-xl font-bold text-slate-800"><?= $LANG["all_feedback_forms"] ?? "All Feedback Forms" ?></h2>
        <p class="text-sm text-slate-500 mt-0.5"><?= $LANG["manage_feedback_forms_subtitle"] ?? "Manage feedback forms for all modules � Academic, Student Affairs, and Administration." ?></p>
    </div>
    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-indigo-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus', 'w-4 h-4') ?><?= $LANG["create_form"] ?? "Create Form" ?></button>
</div>
<?php renderFlash() ?>

<!-- Filters -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-4 mb-6">
    <form method="GET" class="flex items-end gap-3 flex-wrap">
        <div class="flex-1 min-w-[160px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG["module"] ?? "Module" ?></label>
            <select name="module"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 bg-white">
                <option value=""><?= $LANG["all_modules"] ?? "All Modules" ?></option>
                <option value="academic" <?= $filterMod === 'academic' ? 'selected' : '' ?>><?= $LANG["academic"] ?? "Academic" ?></option>
                <option value="student_affairs" <?= $filterMod === 'student_affairs' ? 'selected' : '' ?>><?= $LANG["student_affairs"] ?? "Student Affairs" ?></option>
                <option value="administration" <?= $filterMod === 'administration' ? 'selected' : '' ?>><?= $LANG["administration"] ?? "Administration" ?></option>
            </select>
        </div>
        <div class="flex-1 min-w-[160px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG["academic_year"] ?? "Academic Year" ?></label>
            <select name="ay_id"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 bg-white">
                <option value=""><?= $LANG["all_years"] ?? "All Years" ?></option>
                <?php foreach ($academicYears as $ay): ?>
                    <option value="<?= $ay['id'] ?>" <?= $filterAY == $ay['id'] ? 'selected' : '' ?>><?= e($ay['year_name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <div class="flex-1 min-w-[200px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1"><?= $LANG["search"] ?? "Search" ?></label>
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="<?= $LANG["search_forms_placeholder"] ?? "Search forms..." ?>"
                class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500">
        </div>
        <div class="flex gap-2">
            <button type="submit"
                class="px-4 py-2 bg-indigo-600 text-white text-sm font-semibold rounded-xl hover:bg-indigo-700"><?= $LANG["search"] ?? "Search" ?></button>
            <?php if ($filterMod || $search || $filterAY): ?>
                <a href="feedback_forms_all.php"
                    class="px-4 py-2 btn-reset text-sm font-semibold rounded-xl"><?= $LANG["reset"] ?? "Reset" ?></a>
            <?php endif ?>
        </div>
    </form>
</div>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-200 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 w-12 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold"><?= $LANG["module"] ?? "Module" ?></th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold"><?= $LANG["form_title"] ?? "Form Title" ?></th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold"><?= $LANG["year_semester"] ?? "Year / Semester" ?></th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold"><?= $LANG["question_set"] ?? "Question Set" ?></th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold"><?= $LANG["col_duration"] ?? "Duration" ?></th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">Q</th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold">Sub</th>
                    <th class="text-center px-5 py-3 text-slate-500 text-sm font-semibold"><?= $LANG["col_actions"] ?? "Actions" ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows):
                    foreach ($rows as $i => $row): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-3 text-xs text-slate-800 font-medium capitalize"><?= e($row['module']) ?></td>
                            <td class="px-5 py-3 text-sm font-medium text-slate-800"><?= e($row['title']) ?></td>
                            <td class="px-5 py-3">
                                <p class="text-sm text-slate-700 font-semibold"><?= e($row['year_name'] ?? '�') ?></p>
                                <p class="text-xs text-slate-400"><?= e(semesterToRoman($row['semester_name'] ?? '')) ?></p>
                            </td>
                            <td class="px-5 py-3">
                                <?php if ($row['question_set_title']): ?>
                                    <span
                                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700"><?= e($row['question_set_title']) ?></span>
                                <?php else: ?>
                                    <span class="text-xs text-red-400 italic"><?= $LANG["no_set"] ?? "No set" ?></span>
                                <?php endif ?>
                            </td>
                            <td class="px-5 py-3 text-xs text-slate-500">
                                <?= formatDateTime($row['start_date']) ?><br><?= formatDateTime($row['end_date']) ?><br>
                                <span class="text-[10px] font-semibold"><?= badgeStatus($row['status']) ?></span>
                            </td>
                            <td class="px-5 py-3 text-center"><span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-cyan-100 text-cyan-700"><?= $row['question_count'] ?></span>
                            </td>
                            <td class="px-5 py-3 text-center"><span
                                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-700"><?= $row['submission_count'] ?></span>
                            </td>
                            <td class="px-5 py-3 text-center">
                                <div class="flex items-center justify-center gap-1.5">
                                    <a href="results_all.php?form_id=<?= $row['id'] ?>"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-100 hover:bg-cyan-200 rounded-lg">
                                        <?= iconSvg('chart', 'w-3.5 h-3.5') ?><?= $LANG["results_link"] ?? "Results" ?></a>
                                    <button onclick="openEdit(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-100 hover:bg-indigo-200 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?><?= $LANG["edit"] ?? "Edit" ?></button>
                                    <button onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['title'])) ?>')"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                        <?= iconSvg('trash', 'w-3.5 h-3.5') ?><?= $LANG["delete"] ?? "Delete" ?></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="9" class="text-center py-16 text-slate-400">
                            <?= iconSvg('document', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm"><?= $LANG["no_feedback_forms_found"] ?? "No feedback forms found." ?></p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100"><?php
    $pgParams = [];
    if ($filterMod)
        $pgParams['module'] = $filterMod;
    if ($filterAY)
        $pgParams['ay_id'] = $filterAY;
    if ($search)
        $pgParams['search'] = $search;
    $pgBase = 'feedback_forms_all.php' . ($pgParams ? '?' . http_build_query($pgParams) : '');
    echo paginationLinks($pg, $pgBase, $perPage);
    ?></div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG["create_feedback_form"] ?? "Create Feedback Form" ?></h3>
            <button onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST" id="createForm"><?= csrfField() ?><input type="hidden" name="action" value="add">
            <input type="hidden" name="question_set_id" id="add_question_set_id" value="0">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">
                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">1</span>
                        Module <span class="text-red-500">*</span>
                    </label>
                    <select name="module" id="add_module" required onchange="onModuleChange(this.value)"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG["select_module"] ?? "� Select Module �" ?></option>
                        <option value="academic"><?= $LANG["academic"] ?? "Academic" ?></option>
                        <option value="student_affairs"><?= $LANG["student_affairs"] ?? "Student Affairs" ?></option>
                        <option value="administration"><?= $LANG["administration"] ?? "Administration" ?></option>
                    </select>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">
                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">2</span>
                        Academic Year <span class="text-red-500">*</span>
                    </label>
                    <select name="academic_year_id" id="add_academic_year_id" required onchange="loadQuestionSets()"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG["select_academic_year"] ?? "� Select Academic Year �" ?></option>
                        <?php foreach ($academicYears as $ay): ?>
                            <option value="<?= $ay['id'] ?>"><?= e($ay['year_name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">
                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">3</span>
                        Semester <span class="text-red-500">*</span>
                    </label>
                    <select name="semester_id" id="add_semester_id" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG["select_semester"] ?? "� Select Semester �" ?></option>
                        <?php foreach ($semesters as $sm): ?>
                            <option value="<?= $sm['id'] ?>"><?= e(semesterToRoman($sm['semester_name'])) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div id="add_section_wrapper">
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">
                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">4</span>
                        Section <span class="text-red-500">*</span>
                    </label>
                    <select name="section_id" id="add_section_id"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value=""><?= $LANG["select_section"] ?? "� Select Section �" ?></option>
                        <?php foreach ($sectionList as $s): ?>
                            <option value="<?= $s['id'] ?>">[<?= e($s['course_code']) ?>] <?= e($s['course_name']) ?> � Sec
                                <?= e($s['section']) ?> (<?= e($s['academic_year']) ?>)
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div id="qs_info">
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["question_set_auto_detected"] ?? "Question Set (Auto-detected)" ?></label>
                    <div id="qs_loading" class="hidden text-xs text-slate-400 py-2"><?= $LANG["loading_question_sets"] ?? "Loading question sets..." ?></div>
                    <div id="qs_empty" class="hidden">
                        <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-700">
                            <?= iconSvg('question', 'w-4 h-4 inline mr-1') ?>
                            <strong><?= $LANG["no_question_set_found"] ?? "No Question Set found" ?></strong> for the selected Year + Module.
                            <a href="question_sets.php" class="underline ml-1"><?= $LANG["create_one_first"] ?? "Create one first" ?></a>.
                        </div>
                    </div>
                    <div id="qs_found" class="hidden">
                        <div class="bg-indigo-50 border border-indigo-200 rounded-xl px-4 py-3 text-xs text-indigo-700">
                            <?= iconSvg('check', 'w-4 h-4 inline mr-1') ?>
                            <strong id="qs_found_title"></strong> � <span id="qs_found_count"></span> questions
                        </div>
                    </div>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">
                        <span
                            class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">5</span>
                        Form Title <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="title" required placeholder="<?= $LANG["form_title_placeholder"] ?? "e.g. Midterm Evaluation" ?>"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div id="university_fields_wrapper" class="hidden space-y-4">
                    <div>
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">
                            <span
                                class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">6</span>
                            University Name
                        </label>
                        <input type="text" name="university_name" id="add_university_name"
                            placeholder="e.g. University of Computer Studies (Hinthada)"
                            value="University of Computer Studies (Hinthada)"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    </div>
                    <div>
                        <label class="flex items-center gap-2 text-sm font-medium text-slate-700 mb-1">
                            <span
                                class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-cyan-100 text-cyan-700 text-[10px] font-bold">7</span>
                            University Campus
                        </label>
                        <input type="text" name="university_campus" id="add_university_campus"
                            placeholder="e.g. Main Campus" value="Main Campus"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["start_date_time"] ?? "Start Date & Time" ?> <span
                                class="text-red-500">*</span></label>
                        <input type="datetime-local" name="start_date" id="add_start_date" required
                            min="<?= date('Y-m-d\TH:i') ?>"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["end_date_time"] ?? "End Date & Time" ?> <span
                                class="text-red-500">*</span></label>
                        <input type="datetime-local" name="end_date" id="add_end_date" required
                            min="<?= date('Y-m-d\TH:i') ?>"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    </div>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold btn-cancel rounded-xl transition-colors"><?= $LANG["cancel"] ?? "Cancel" ?></button>
                <button type="submit" id="addSubmitBtn" disabled
                    class="px-5 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl disabled:opacity-50 disabled:cursor-not-allowed">Create
                    Form</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG["edit_feedback_form"] ?? "Edit Feedback Form" ?></h3>
            <button onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden"
                name="id" id="edit_id">
            <input type="hidden" name="module" id="edit_module" value="">
            <input type="hidden" name="question_set_id" id="edit_question_set_id" value="0">
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["academic_year"] ?? "Academic Year" ?></label>
                        <input type="hidden" name="academic_year_id" id="hidden_edit_academic_year_id" value="">
                        <select id="edit_academic_year_id" disabled
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-slate-100 text-slate-500 cursor-not-allowed">
                            <option value=""><?= $LANG["select_academic_year"] ?? "� Select Academic Year �" ?></option>
                            <?php foreach ($academicYears as $ay): ?>
                                <option value="<?= $ay['id'] ?>"><?= e($ay['year_name']) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["semester"] ?? "Semester" ?></label>
                        <select name="semester_id" id="edit_semester_id"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white">
                            <option value=""><?= $LANG["select_semester"] ?? "� Select Semester �" ?></option>
                            <?php foreach ($semesters as $sm): ?>
                                <option value="<?= $sm['id'] ?>"><?= e(semesterToRoman($sm['semester_name'])) ?></option>
                            <?php endforeach ?>
                        </select>
                    </div>
                </div>
                <div id="edit_section_wrapper">
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["section_name"] ?? "Section" ?></label>
                    <select name="section_id" id="edit_section"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white">
                        <option value="0"><?= $LANG["no_section"] ?? "� No Section �" ?></option>
                        <?php foreach ($sectionList as $s): ?>
                            <option value="<?= $s['id'] ?>">[<?= e($s['course_code']) ?>] <?= e($s['course_name']) ?> � Sec
                                <?= e($s['section']) ?>
                            </option><?php endforeach ?>
                    </select>
                </div>
                <div id="edit_qs_info">
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["question_set"] ?? "Question Set" ?></label>
                    <div id="edit_qs_loading" class="hidden text-xs text-slate-400 py-2"><?= $LANG["loading_question_sets"] ?? "Loading question sets..." ?></div>
                    <div id="edit_qs_empty" class="hidden">
                        <div class="bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-xs text-amber-700">
                            <?= iconSvg('question', 'w-4 h-4 inline mr-1') ?>
                            <strong><?= $LANG["no_question_set_found"] ?? "No Question Set found" ?></strong> for the selected Year + Module.
                        </div>
                    </div>
                    <div id="edit_qs_found" class="hidden">
                        <div class="bg-indigo-50 border border-indigo-200 rounded-xl px-4 py-3 text-xs text-indigo-700">
                            <?= iconSvg('check', 'w-4 h-4 inline mr-1') ?>
                            <strong id="edit_qs_found_title"></strong> � <span id="edit_qs_found_count"></span>
                            questions
                        </div>
                    </div>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["title"] ?? "Title" ?></label>
                    <input type="text" name="title" id="edit_title" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Start Date & Time</label><input
                            type="datetime-local" name="start_date" id="edit_start"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none"></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">End Date & Time</label><input
                            type="datetime-local" name="end_date" id="edit_end"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none"></div>
                </div>
                <div id="edit_university_fields" class="hidden space-y-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["university_name"] ?? "University Name" ?></label>
                        <input type="text" name="university_name" id="edit_university_name"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none">
                    </div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG["university_campus"] ?? "University Campus" ?></label>
                        <input type="text" name="university_campus" id="edit_university_campus"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none">
                    </div>
                </div>
            </div>
            <div class="flex gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold btn-cancel rounded-xl transition-colors"><?= $LANG["cancel"] ?? "Cancel" ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl"><?= $LANG["save"] ?? "Save" ?></button>
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
            <h3 class="text-lg font-semibold text-slate-800"><?= $LANG["delete_form"] ?? "Delete Form" ?></h3>
            <p class="text-sm text-slate-500 mt-2">Delete <strong id="delete_name" class="text-slate-700"></strong>? All
                submissions will be lost.</p>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden"
                name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold btn-cancel rounded-xl transition-colors"><?= $LANG["cancel"] ?? "Cancel" ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG["delete"] ?? "Delete" ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    var LANG = <?= json_encode([
        'val_question_set_required' => $LANG['val_question_set_required'] ?? 'Please ensure a Question Set is available for the selected Year + Module.',
        'loading_question_sets' => $LANG['loading_question_sets'] ?? 'Loading question sets...',
    ]) ?>;
    function onModuleChange(val) {
        const sectionWrapper = document.getElementById('add_section_wrapper');
        const uniWrapper = document.getElementById('university_fields_wrapper');
        if (val === 'academic') {
            sectionWrapper.style.display = '';
            document.getElementById('add_section_id').setAttribute('required', 'required');
            uniWrapper.classList.add('hidden');
        } else if (val === 'student_affairs' || val === 'administration') {
            sectionWrapper.style.display = 'none';
            document.getElementById('add_section_id').removeAttribute('required');
            document.getElementById('add_section_id').value = '';
            uniWrapper.classList.remove('hidden');
        } else {
            sectionWrapper.style.display = 'none';
            document.getElementById('add_section_id').removeAttribute('required');
            document.getElementById('add_section_id').value = '';
            uniWrapper.classList.add('hidden');
        }
        loadQuestionSets();
    }

    function loadQuestionSets() {
        const module = document.getElementById('add_module').value;
        const academicYear = document.getElementById('add_academic_year_id').value;
        const qsLoading = document.getElementById('qs_loading');
        const qsEmpty = document.getElementById('qs_empty');
        const qsFound = document.getElementById('qs_found');
        const submitBtn = document.getElementById('addSubmitBtn');
        const hiddenQsId = document.getElementById('add_question_set_id');

        if (!module || !academicYear) {
            qsLoading.classList.add('hidden');
            qsEmpty.classList.add('hidden');
            qsFound.classList.add('hidden');
            submitBtn.disabled = true;
            hiddenQsId.value = 0;
            return;
        }

        qsLoading.classList.remove('hidden');
        qsEmpty.classList.add('hidden');
        qsFound.classList.add('hidden');
        submitBtn.disabled = true;
        hiddenQsId.value = 0;

        fetch('feedback_forms_all.php?ajax_question_sets=1&academic_year_id=' + academicYear + '&module=' + module)
            .then(r => r.json())
            .then(data => {
                qsLoading.classList.add('hidden');
                if (data.sets && data.sets.length > 0) {
                    const qs = data.sets[0];
                    if (qs.question_count > 0) {
                        hiddenQsId.value = qs.id;
                        document.getElementById('qs_found_title').textContent = qs.title;
                        document.getElementById('qs_found_count').textContent = qs.question_count;
                        qsFound.classList.remove('hidden');
                        submitBtn.disabled = false;
                    } else {
                        qsEmpty.classList.remove('hidden');
                        qsEmpty.querySelector('div').innerHTML = '<strong>No questions found</strong> in the Question Set. <a href="question_sets.php" class="underline ml-1">Add questions first</a>.';
                    }
                } else {
                    qsEmpty.classList.remove('hidden');
                }
            })
            .catch(() => {
                qsLoading.classList.add('hidden');
                qsEmpty.classList.remove('hidden');
            });
    }

    (function () {
        function getNow() {
            const d = new Date();
            return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0') + 'T' + String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
        }
        const addStart = document.getElementById('add_start_date');
        const addEnd = document.getElementById('add_end_date');
        const editStart = document.getElementById('edit_start');
        const editEnd = document.getElementById('edit_end');

        const addModal = document.getElementById('addModal');
        if (addModal) {
            const obs = new MutationObserver(function () {
                if (addStart) addStart.min = getNow();
                if (addEnd) addEnd.min = (addStart && addStart.value) ? addStart.value : getNow();
            });
            obs.observe(addModal, { attributes: true, attributeFilter: ['class', 'style'] });
        }
        if (addStart && addEnd) {
            addStart.addEventListener('change', function () {
                addEnd.min = this.value || getNow();
                if (addEnd.value && addEnd.value < this.value) addEnd.value = this.value;
            });
        }
        if (editStart && editEnd) {
            editStart.addEventListener('change', function () {
                editEnd.min = this.value || getNow();
                if (editEnd.value && editEnd.value < this.value) editEnd.value = this.value;
            });
        }
        const createForm = document.getElementById('createForm');
        if (createForm) {
            createForm.addEventListener('submit', function (e) {
                const qsId = document.getElementById('add_question_set_id').value;
                if (!qsId || qsId === '0') {
                    e.preventDefault();
                    alert(LANG.val_question_set_required);
                }
            });
        }
    })();

    function openEdit(row) {
        document.getElementById('edit_id').value = row.id;

        document.getElementById('edit_module').value = row.module || '';
        document.getElementById('edit_question_set_id').value = row.question_set_id || 0;
        document.getElementById('edit_academic_year_id').value = row.academic_year_id || '';
        document.getElementById('hidden_edit_academic_year_id').value = row.academic_year_id || '';
        document.getElementById('edit_semester_id').value = row.semester_id || '';
        document.getElementById('edit_section').value = row.section_id || 0;
        document.getElementById('edit_title').value = row.title;
        document.getElementById('edit_start').value = row.start_date.replace(' ', 'T').substring(0, 16);
        document.getElementById('edit_end').value = row.end_date.replace(' ', 'T').substring(0, 16);
        var uniFields = document.getElementById('edit_university_fields');
        var secWrapper = document.getElementById('edit_section_wrapper');
        if (row.module === 'student_affairs' || row.module === 'administration') {
            uniFields.classList.remove('hidden');
            document.getElementById('edit_university_name').value = row.university_name || '';
            document.getElementById('edit_university_campus').value = row.university_campus || '';
        } else {
            uniFields.classList.add('hidden');
        }
        if (row.module === 'academic') {
            secWrapper.style.display = '';
        } else {
            secWrapper.style.display = 'none';
        }
        // Show existing question set info
        var qsFound = document.getElementById('edit_qs_found');
        var qsEmpty = document.getElementById('edit_qs_empty');
        if (row.question_set_id && row.question_set_id > 0) {
            qsFound.classList.remove('hidden');
            qsEmpty.classList.add('hidden');
            document.getElementById('edit_qs_found_title').textContent = row.question_set_title || 'Question Set';
            document.getElementById('edit_qs_found_count').textContent = row.question_count || '?';
        } else {
            qsFound.classList.add('hidden');
            qsEmpty.classList.remove('hidden');
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