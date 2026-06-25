<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = 'Section Assignments';
$activeMenu = 'assignments';

$sectionList = $conn->query("SELECT s.id, c.course_name, c.course_code, s.section, s.academic_year, s.semester FROM sections s JOIN courses c ON s.course_id=c.id ORDER BY c.course_name, s.section")->fetch_all(MYSQLI_ASSOC);
$studentList = $conn->query("SELECT st.id, u.name, st.roll_no FROM students st JOIN users u ON st.user_id=u.id ORDER BY u.name")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    // Roll Number Range အလိုက် ဘာသာရပ်အများကြီးကို တစ်ပြိုင်နက် အစုလိုက်သွင်းခြင်း (Method 2)
    if ($action === 'add_by_roll_range') {
        $roll_from = clean($_POST['roll_from'] ?? '');
        $roll_to = clean($_POST['roll_to'] ?? '');
        $sectionIds = $_POST['section_ids'] ?? []; // array from checkboxes

        if (!is_array($sectionIds))
            $sectionIds = [];
        $sectionIds = array_map('intval', array_filter($sectionIds));

        if ($roll_from && $roll_to && count($sectionIds) > 0) {
            // Roll Number အပိုင်းအခြားကြားရှိသော ကျောင်းသားများကို ရှာဖွေခြင်း
            $stStmt = $conn->prepare("SELECT id FROM students WHERE roll_no BETWEEN ? AND ?");
            $stStmt->bind_param('ss', $roll_from, $roll_to);
            $stStmt->execute();
            $matchedStudents = $stStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stStmt->close();

            if (count($matchedStudents) > 0) {
                $added = 0;
                $stmt = $conn->prepare("INSERT IGNORE INTO section_assignments (student_id, section_id) VALUES (?,?)");
                foreach ($matchedStudents as $stud) {
                    foreach ($sectionIds as $secId) {
                        $stmt->bind_param('ii', $stud['id'], $secId);
                        $stmt->execute();
                        if ($stmt->affected_rows > 0)
                            $added++;
                    }
                }
                $stmt->close();
                setFlash('success', "ကိုက်ညီသော ကျောင်းသား " . count($matchedStudents) . " ယောက်ကို ရွေးချယ်ထားသော ဘာသာရပ်များထဲသို့ အစုလိုက် အောင်မြင်စွာ ထည့်သွင်းပြီးပါပြီ။");
            } else {
                setFlash('error', 'ရိုက်ထည့်ထားသော Roll နံပါတ် အပိုင်းအခြားအတွင်း မည်သည့်ကျောင်းသားမှ မရှိပါ။');
            }
        } else {
            setFlash('error', 'Roll နံပါတ်များနှင့် ဘာသာရပ် အနည်းဆုံးတစ်ခုကို ပြည့်စုံစွာ ဖြည့်စွက်/ရွေးချယ်ပေးပါ။');
        }
    }

    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        if ($id && $sectionId) {
            $stmt = $conn->prepare("UPDATE section_assignments SET section_id=? WHERE id=?");
            $stmt->bind_param('ii', $sectionId, $id);
            if ($stmt->execute()) {
                setFlash('success', 'Assignment updated successfully.');
            } else {
                setFlash('error', 'Failed to update assignment (may already exist).');
            }
            $stmt->close();
        } else {
            setFlash('error', 'Invalid assignment or section.');
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            $stmt = $conn->prepare("DELETE FROM section_assignments WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute() ? setFlash('success', 'Assignment removed.') : setFlash('error', 'Failed.');
            $stmt->close();
        }
    }

    if ($action === 'delete_all_for_student') {
        $student = (int) ($_POST['student_id'] ?? 0);
        if ($student) {
            $stmt = $conn->prepare("DELETE FROM section_assignments WHERE student_id=?");
            $stmt->bind_param('i', $student);
            $stmt->execute();
            setFlash('success', 'All assignments for student removed.');
            $stmt->close();
        }
    }

    header('Location: section_assignments.php');
    exit;
}

// GET တန်ဖိုးများကို လက်ခံခြင်း
$search = clean($_GET['search'] ?? '');
$filter_semester = clean($_GET['filter_semester'] ?? '');
$filter_section = clean($_GET['filter_section'] ?? '');

// Dynamic WHERE Clause တည်ဆောက်ခြင်း
$whereClauses = [];
$bindParams = [];
$bindTypes = "";

if ($search) {
    // ပြင်ဆင်လိုက်သည့်နေရာ - ဘာသာရပ်အမည် (course_name) နှင့် ဘာသာရပ်ကုဒ် (course_code) ကိုပါ ရှာဖွေမှုထဲ ထည့်သွင်းခြင်း
    $whereClauses[] = "(u2.name LIKE ? OR st2.roll_no LIKE ? OR c3.course_name LIKE ? OR c3.course_code LIKE ?)";
    $s2 = "%$search%";
    $bindParams[] = $s2;
    $bindParams[] = $s2;
    $bindParams[] = $s2;
    $bindParams[] = $s2;
    $bindTypes .= "ssss";
}
if ($filter_semester) {
    $whereClauses[] = "s2.semester = ?";
    $bindParams[] = $filter_semester;
    $bindTypes .= "s";
}
if ($filter_section) {
    $whereClauses[] = "s2.section = ?";
    $bindParams[] = $filter_section;
    $bindTypes .= "s";
}

$subqueryWhere = "";
if (count($whereClauses) > 0) {
    $subqueryWhere = "WHERE " . implode(" AND ", $whereClauses);
}

// Pagination အရေအတွက်တွက်ချက်ခြင်း
if ($subqueryWhere) {
    $countQuery = "SELECT COUNT(DISTINCT sa.student_id) AS c FROM section_assignments sa WHERE sa.student_id IN (SELECT DISTINCT sa2.student_id FROM section_assignments sa2 JOIN students st2 ON sa2.student_id=st2.id JOIN users u2 ON st2.user_id=u2.id JOIN sections s2 ON sa2.section_id=s2.id JOIN courses c3 ON s2.course_id=c3.id $subqueryWhere)";
    $cStmt = $conn->prepare($countQuery);
    $cStmt->bind_param($bindTypes, ...$bindParams);
    $cStmt->execute();
    $total = (int) $cStmt->get_result()->fetch_assoc()['c'];
    $cStmt->close();
} else {
    $total = (int) $conn->query("SELECT COUNT(DISTINCT student_id) AS c FROM section_assignments")->fetch_assoc()['c'];
}

$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));
$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];

// သတ်မှတ်ထားသော Filter အားလုံးနှင့်ကိုက်ညီသည့် ကျောင်းသားများကို ဆွဲထုတ်ခြင်း
if ($subqueryWhere) {
    $query = "SELECT 
                st.id AS student_id, u.name, st.roll_no, 
                GROUP_CONCAT(sa.id ORDER BY sa.id DESC SEPARATOR '||') AS assignment_ids,
                GROUP_CONCAT(c2.course_name ORDER BY sa.id DESC SEPARATOR '||') AS course_names,
                GROUP_CONCAT(c2.course_code ORDER BY sa.id DESC SEPARATOR '||') AS course_codes,
                GROUP_CONCAT(s.section ORDER BY sa.id DESC SEPARATOR '||') AS sections,
                GROUP_CONCAT(s.id ORDER BY sa.id DESC SEPARATOR '||') AS section_ids,
                GROUP_CONCAT(CONCAT(s.academic_year, ' / ', s.semester) ORDER BY sa.id DESC SEPARATOR '||') AS semesters,
                MAX(sa.created_at) AS latest_enrolled
              FROM section_assignments sa 
              JOIN students st ON sa.student_id=st.id 
              JOIN users u ON st.user_id=u.id 
              JOIN sections s ON sa.section_id=s.id 
              JOIN courses c2 ON s.course_id=c2.id 
              WHERE sa.student_id IN (
                  SELECT DISTINCT sa2.student_id FROM section_assignments sa2 
                  JOIN students st2 ON sa2.student_id=st2.id 
                  JOIN users u2 ON st2.user_id=u2.id 
                  JOIN sections s2 ON sa2.section_id=s2.id 
                  JOIN courses c3 ON s2.course_id=c3.id
                  $subqueryWhere
              )
              GROUP BY sa.student_id 
              ORDER BY u.name ASC 
              LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    $mBindTypes = $bindTypes . "ii";
    $mBindParams = array_merge($bindParams, [$perPage, $off]);
    $stmt->bind_param($mBindTypes, ...$mBindParams);
} else {
    $query = "SELECT 
                st.id AS student_id, u.name, st.roll_no, 
                GROUP_CONCAT(sa.id ORDER BY sa.id DESC SEPARATOR '||') AS assignment_ids,
                GROUP_CONCAT(c2.course_name ORDER BY sa.id DESC SEPARATOR '||') AS course_names,
                GROUP_CONCAT(c2.course_code ORDER BY sa.id DESC SEPARATOR '||') AS course_codes,
                GROUP_CONCAT(s.section ORDER BY sa.id DESC SEPARATOR '||') AS sections,
                GROUP_CONCAT(s.id ORDER BY sa.id DESC SEPARATOR '||') AS section_ids,
                GROUP_CONCAT(CONCAT(s.academic_year, ' / ', s.semester) ORDER BY sa.id DESC SEPARATOR '||') AS semesters,
                MAX(sa.created_at) AS latest_enrolled
              FROM section_assignments sa 
              JOIN students st ON sa.student_id=st.id 
              JOIN users u ON st.user_id=u.id 
              JOIN sections s ON sa.section_id=s.id 
              JOIN courses c2 ON s.course_id=c2.id 
              GROUP BY sa.student_id 
              ORDER BY u.name ASC 
              LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
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
        <h2 class="text-xl font-bold text-slate-800">Section Assignments</h2>
        <p class="text-sm text-slate-500 mt-0.5">Enroll students into multiple course sections by Roll Number Range</p>
    </div>
    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-cyan-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('link', 'w-4 h-4') ?> Assign Students (Roll Range အလိုက်အပ်ရန်)
    </button>
</div>
<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-3 flex-1">
            <div class="relative flex-1 min-w-[220px] max-w-xs">
                <span
                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search', 'w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>"
                    placeholder="Search student, course name/code..."
                    class="w-full pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>

            <div>
                <select name="filter_semester"
                    class="border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                    <option value="">All Semesters</option>
                    <?php for ($m = 1; $m <= 10; $m++): ?>
                        <?php $semVal = $m . ($m == 1 ? 'st' : ($m == 2 ? 'nd' : ($m == 3 ? 'rd' : 'th'))) . ' Semester'; ?>
                        <option value="<?= $semVal ?>" <?= $filter_semester === $semVal ? 'selected' : '' ?>><?= $semVal ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <div>
                <select name="filter_section"
                    class="border border-slate-200 rounded-xl px-3 py-2 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                    <option value="">All Sections</option>
                    <option value="A" <?= $filter_section === 'A' ? 'selected' : '' ?>>Section A</option>
                    <option value="B" <?= $filter_section === 'B' ? 'selected' : '' ?>>Section B</option>
                    <option value="C" <?= $filter_section === 'C' ? 'selected' : '' ?>>Section C</option>
                    <option value="D" <?= $filter_section === 'D' ? 'selected' : '' ?>>Section D</option>
                </select>
            </div>

            <button type="submit"
                class="px-4 py-2 text-sm bg-cyan-600 text-white rounded-xl hover:bg-cyan-700 font-semibold shadow-sm transition-colors">Filter</button>
            <?php if ($search || $filter_semester || $filter_section): ?>
                <a href="section_assignments.php"
                    class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50 transition-colors">Clear</a>
            <?php endif ?>
        </form>
        <span class="text-xs text-slate-400 shrink-0"><?= $total ?> student<?= $total !== 1 ? 's' : '' ?> listed</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full border-collapse">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">#</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">Student</th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">Assigned Courses & Sections
                    </th>
                    <th class="text-left px-5 py-3 text-slate-500 text-sm font-semibold">Latest Enrollment</th>
                    <th class="text-right px-5 py-3 text-slate-500 text-sm font-semibold">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows):
                    foreach ($rows as $i => $row):
                        $asgnIds = $row['assignment_ids'] ? explode('||', $row['assignment_ids']) : [];
                        $cNames = $row['course_names'] ? explode('||', $row['course_names']) : [];
                        $cCodes = $row['course_codes'] ? explode('||', $row['course_codes']) : [];
                        $secs = $row['sections'] ? explode('||', $row['sections']) : [];
                        $secIds = $row['section_ids'] ? explode('||', $row['section_ids']) : [];
                        $semesters = $row['semesters'] ? explode('||', $row['semesters']) : [];

                        $hasVisibleCard = false;
                        $visibleCardCount = 0;
                        $cardsHtml = '';

                        if (!empty($asgnIds)) {
                            foreach ($asgnIds as $k => $asgnId) {
                                if (!isset($cNames[$k]))
                                    continue;

                                $matchSearch = true;
                                if ($search) {
                                    $searchLower = strtolower($search);
                                    $inStudent = strpos(strtolower($row['name']), $searchLower) !== false || strpos(strtolower($row['roll_no']), $searchLower) !== false;
                                    // ပြင်ဆင်လိုက်သည့်နေရာ - ကတ်ပြားများကို စစ်ထုတ်ပြသရာတွင် ဘာသာရပ်အမည်/ကုဒ် ကိုပါ Check လုပ်ပေးခြင်း
                                    $inCourse = strpos(strtolower($cNames[$k]), $searchLower) !== false || strpos(strtolower($cCodes[$k]), $searchLower) !== false;
                                    if (!$inStudent && !$inCourse)
                                        $matchSearch = false;
                                }
                                if ($filter_semester && strpos($semesters[$k], $filter_semester) === false)
                                    $matchSearch = false;
                                if ($filter_section && strtolower($secs[$k]) !== strtolower($filter_section))
                                    $matchSearch = false;

                                if ($matchSearch) {
                                    $hasVisibleCard = true;
                                    $visibleCardCount++;
                                    $cardsHtml .= '
                                    <div class="flex flex-wrap items-center justify-between gap-2 p-3 bg-slate-50 rounded-xl border border-slate-200/60 text-xs">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="font-semibold text-slate-800 text-sm">' . e($cNames[$k]) . '</span>
                                                <span class="font-mono text-slate-400 text-xs">(' . e($cCodes[$k]) . ')</span>
                                            </div>
                                            <div class="text-slate-500 mt-1 flex gap-2 items-center">
                                                <span class="inline-flex items-center px-2 py-0.5 font-bold rounded-md bg-cyan-50 text-cyan-700 border border-cyan-200/50">Section ' . e($secs[$k]) . '</span>
                                                <span>·</span>
                                                <span class="text-slate-400">' . e($semesters[$k]) . '</span>
                                            </div>
                                        </div>
                                        <div class="flex gap-1.5 shrink-0">
                                            <button type="button" onclick="openEdit(' . (int) $asgnId . ', ' . (int) $secIds[$k] . ', \'' . addslashes(e($row['name'])) . '\')"
                                                class="px-2.5 py-1.5 text-[11px] font-semibold text-cyan-600 bg-white hover:bg-cyan-50 border border-slate-200 rounded-lg transition-colors shadow-2xs">
                                                Edit
                                            </button>
                                            <button type="button" onclick="openDelete(' . (int) $asgnId . ', \'' . addslashes(e($row['name'])) . ' - ' . addslashes(e($cNames[$k])) . '\')"
                                                class="px-2.5 py-1.5 text-[11px] font-semibold text-red-600 bg-white hover:bg-red-50 border border-slate-200 rounded-lg transition-colors shadow-2xs">
                                                Remove
                                            </button>
                                        </div>
                                    </div>';
                                }
                            }
                        }

                        if (($search || $filter_semester || $filter_section) && !$hasVisibleCard)
                            continue;
                        ?>
                        <tr class="hover:bg-slate-50/80 transition-colors">
                            <td class="px-5 py-4 text-sm text-slate-400 align-top"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-4 align-top min-w-[160px]">
                                <p class="text-sm font-semibold text-slate-800"><?= e($row['name']) ?></p>
                                <p class="text-xs font-mono text-slate-400 mt-0.5"><?= e($row['roll_no']) ?></p>
                            </td>
                            <td class="px-5 py-4">
                                <?php if ($visibleCardCount > 0): ?>
                                    <button type="button" onclick="toggleCourses(this)"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-cyan-50 text-cyan-700 border border-cyan-200/60 rounded-lg hover:bg-cyan-100 transition-colors shadow-xs cursor-pointer">
                                        <?= $visibleCardCount ?> Course<?= $visibleCardCount !== 1 ? 's' : '' ?>
                                        <svg class="w-3.5 h-3.5 transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                    <div class="hidden mt-2 space-y-2">
                                        <?= $cardsHtml ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-xs text-slate-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-4 text-xs text-slate-400 align-top"><?= formatDate($row['latest_enrolled']) ?>
                            </td>
                            <td class="px-5 py-4 text-right align-top">
                                <button type="button"
                                    onclick="openBulkDelete(<?= $row['student_id'] ?>, '<?= addslashes(e($row['name'])) ?>')"
                                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-bold text-red-700 bg-red-50 hover:bg-red-100 border border-red-200/40 rounded-xl transition-all shadow-3xs">
                                    <?= iconSvg('trash', 'w-3.5 h-3.5') ?> Remove All
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="5" class="text-center py-16 text-slate-400">
                            <?= iconSvg('link', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm">No assignments found.</p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100">
        <?= paginationLinks($pg, 'section_assignments.php' . '?' . http_build_query(array_filter(['search' => $search, 'filter_semester' => $filter_semester, 'filter_section' => $filter_section]))) ?>
    </div>
</div>

<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl modal-box overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-slate-50">
            <div>
                <h3 class="font-bold text-slate-800 text-lg">Assign Students by Roll Number Range</h3>
                <p class="text-xs text-slate-500 mt-0.5">Roll နံပါတ် အပိုင်းအခြားအလိုက် ဘာသာရပ်များ အစုလိုက်သွင်းရန်</p>
            </div>
            <button onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>

        <form method="POST" id="rangeTab">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_by_roll_range">
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Roll Number (From) <span
                                class="text-red-500">*</span></label>
                        <input type="text" name="roll_from" required placeholder="e.g. 4CS-1"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1">Roll Number (To) <span
                                class="text-red-500">*</span></label>
                        <input type="text" name="roll_to" required placeholder="e.g. 4CS-50"
                            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-slate-700 mb-1">Select Courses / Sections (၎င်း Range
                        အတွက် တစ်ခါတည်းအပ်မည့် ဘာသာရပ်များ)</label>
                    <div
                        class="border border-slate-200 rounded-xl overflow-y-auto max-h-56 divide-y divide-slate-100 p-2 bg-slate-50/50">
                        <?php foreach ($sectionList as $sec): ?>
                            <label
                                class="flex items-center gap-3 px-3 py-1.5 hover:bg-white cursor-pointer rounded-lg transition-colors">
                                <input type="checkbox" name="section_ids[]" value="<?= $sec['id'] ?>"
                                    class="w-4 h-4 rounded border-slate-300 text-cyan-600 accent-cyan-600">
                                <div class="text-xs">
                                    <span class="font-semibold text-slate-800"><?= e($sec['course_name']) ?></span>
                                    <span
                                        class="font-bold text-cyan-600 bg-cyan-50 border border-cyan-200 px-1 rounded ml-1">Sec
                                        <?= e($sec['section']) ?></span>
                                    <span class="text-slate-400 ml-2">(<?= e($sec['semester']) ?>)</span>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50">
                <button type="button" onclick="closeModal('addModal')"
                    class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit"
                    class="px-5 py-2 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl shadow-sm">Assign
                    Range Process</button>
            </div>
        </form>
    </div>
</div>

<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <div>
                <h3 class="font-semibold text-slate-800">Edit Assignment</h3>
                <p class="text-xs text-slate-400 mt-0.5">Change the section for <strong id="edit_student_name"
                        class="text-slate-600"></strong></p>
            </div>
            <button onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">New Section <span
                            class="text-red-500">*</span></label>
                    <input type="text" id="editSectionSearch" placeholder="Filter sections..." autocomplete="off"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2 text-sm mb-2 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    <div id="editSectionList"
                        class="border border-slate-200 rounded-xl overflow-y-auto max-h-56 divide-y divide-slate-100">
                        <?php foreach ($sectionList as $sec): ?>
                            <label
                                class="edit-section-item flex items-center gap-3 px-4 py-2.5 hover:bg-cyan-50 cursor-pointer transition-colors"
                                data-label="[<?= e($sec['course_code']) ?>] <?= e($sec['course_name']) ?> - Sec <?= e($sec['section']) ?> (<?= e($sec['academic_year']) ?>)">
                                <input type="radio" name="section_id" value="<?= $sec['id'] ?>"
                                    class="w-4 h-4 border-slate-300 text-cyan-600 accent-cyan-600">
                                <div class="flex-1 min-w-0">
                                    <p class="text-xs font-semibold text-slate-800"><?= e($sec['course_name']) ?>
                                        <span
                                            class="font-mono font-normal text-slate-400">(<?= e($sec['course_code']) ?>)</span>
                                    </p>
                                    <p class="text-xs text-slate-400">Sec <?= e($sec['section']) ?> ·
                                        <?= e($sec['academic_year']) ?> · <?= e($sec['semester']) ?></p>
                                </div>
                            </label>
                        <?php endforeach ?>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')"
                    class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit"
                    class="px-5 py-2 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl shadow-sm">Save
                    Changes</button>
            </div>
        </form>
    </div>
</div>

<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div
                class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4 font-bold text-red-600">
                ⚠️</div>
            <h3 class="text-lg font-semibold text-slate-800">Remove Assignment</h3>
            <p class="text-sm text-slate-500 mt-2">Remove <strong id="delete_name" class="text-slate-700"></strong>?</p>
        </div>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden"
                name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm border border-slate-200 rounded-xl">Cancel</button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl">Remove</button>
            </div>
        </form>
    </div>
</div>

<div id="bulkDeleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div
                class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4 font-bold text-red-600">
                ⚠️</div>
            <h3 class="text-lg font-semibold text-slate-800">Remove All Assignments</h3>
            <p class="text-sm text-slate-500 mt-2">Are you sure you want to completely clear all course enrollments for
                <strong id="bulk_delete_name" class="text-slate-700"></strong>?</p>
        </div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_all_for_student">
            <input type="hidden" name="student_id" id="bulk_student_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('bulkDeleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm border border-slate-200 rounded-xl">Cancel</button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl">Remove
                    All</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEdit(id, currentSectionId, studentName) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_student_name').textContent = studentName;
        document.querySelectorAll('input[name="section_id"]').forEach(radio => {
            radio.checked = (parseInt(radio.value) === parseInt(currentSectionId));
        });
        document.getElementById('editSectionSearch').value = '';
        document.querySelectorAll('.edit-section-item').forEach(item => item.style.display = '');
        openModal('editModal');
    }

    function openDelete(id, description) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_name').textContent = description;
        openModal('deleteModal');
    }

    function openBulkDelete(studentId, name) {
        document.getElementById('bulk_student_id').value = studentId;
        document.getElementById('bulk_delete_name').textContent = name;
        openModal('bulkDeleteModal');
    }

    function toggleCourses(btn) {
        const container = btn.parentElement;
        const details = container.querySelector('.hidden.mt-2, [class*="mt-2"]');
        if (!details) return;
        const isHidden = details.classList.contains('hidden');
        details.classList.toggle('hidden');
        const arrow = btn.querySelector('svg');
        if (arrow) arrow.style.transform = isHidden ? 'rotate(180deg)' : '';
    }

    // Edit Modal filtering logic
    document.getElementById('editSectionSearch').addEventListener('input', function () {
        const q = this.value.toLowerCase();
        document.querySelectorAll('.edit-section-item').forEach(item => {
            item.style.display = item.dataset.label.toLowerCase().includes(q) ? '' : 'none';
        });
    });
</script>
<?php include '../includes/admin_footer.php'; ?>