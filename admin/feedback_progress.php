<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Feedback Progress';
$activeMenu = 'progress';

$filterSemester = clean($_GET['semester'] ?? '');
$filterTeacher  = (int)($_GET['teacher_id'] ?? 0);
$filterCourse   = (int)($_GET['course_id'] ?? 0);

$semesters = $conn->query("SELECT DISTINCT semester FROM sections WHERE semester != '' ORDER BY semester DESC")->fetch_all(MYSQLI_ASSOC);
$teachers  = $conn->query("SELECT DISTINCT t.id, u.name AS teacher_name FROM sections s JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id ORDER BY u.name ASC")->fetch_all(MYSQLI_ASSOC);
$courses   = $conn->query("SELECT DISTINCT c.id, c.course_name FROM sections s JOIN courses c ON s.course_id=c.id ORDER BY c.course_name ASC")->fetch_all(MYSQLI_ASSOC);

$whereParts = [];
$params     = [];
$types      = '';

if ($filterSemester !== '') { $whereParts[] = 's.semester = ?'; $params[] = $filterSemester; $types .= 's'; }
if ($filterTeacher > 0)    { $whereParts[] = 's.teacher_id = ?'; $params[] = $filterTeacher; $types .= 'i'; }
if ($filterCourse > 0)     { $whereParts[] = 's.course_id = ?'; $params[] = $filterCourse; $types .= 'i'; }

$whereSql = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

function runQ($conn, $sql, $types, $params) {
    if ($types === '') return $conn->query($sql);
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

$progressSql = "
    SELECT ff.id AS form_id, ff.title, ff.start_date, ff.end_date, ff.status,
           c.course_name, c.course_code, s.section, s.semester, s.academic_year,
           u.name AS teacher_name,
           (SELECT COUNT(*) FROM section_assignments sa WHERE sa.section_id = s.id) AS total_students,
           (SELECT COUNT(*) FROM feedback_submissions fs WHERE fs.feedback_form_id = ff.id) AS submitted_count
    FROM feedback_forms ff
    JOIN sections s ON ff.section_id = s.id
    JOIN courses c ON s.course_id = c.id
    JOIN teachers t ON s.teacher_id = t.id
    JOIN users u ON t.user_id = u.id
    $whereSql
    ORDER BY s.semester DESC, ff.end_date DESC
";
$progressData = runQ($conn, $progressSql, $types, $params)->fetch_all(MYSQLI_ASSOC);

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="mb-6">
    <h2 class="text-2xl font-bold text-slate-800">Feedback Progress Tracking</h2>
    <p class="text-sm text-slate-500 mt-1">Monitor submission progress across all feedback forms</p>
</div>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6">
    <form method="GET" class="flex flex-wrap items-end gap-4">
        <div class="flex-1 min-w-[170px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Semester</label>
            <select name="semester" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 bg-white">
                <option value="">All Semesters</option>
                <?php foreach ($semesters as $s): ?>
                    <option value="<?= e($s['semester']) ?>" <?= $filterSemester === $s['semester'] ? 'selected' : '' ?>><?= e($s['semester']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[170px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Teacher</label>
            <select name="teacher_id" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 bg-white">
                <option value="0">All Teachers</option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= $filterTeacher === (int)$t['id'] ? 'selected' : '' ?>><?= e($t['teacher_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex-1 min-w-[170px]">
            <label class="block text-xs font-semibold text-slate-500 mb-1">Subject</label>
            <select name="course_id" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 bg-white">
                <option value="0">All Subjects</option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $filterCourse === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['course_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-5 py-2 bg-cyan-600 text-white text-sm font-semibold rounded-xl hover:bg-cyan-700 transition-colors">Filter</button>
            <a href="feedback_progress.php" class="px-4 py-2 bg-slate-100 text-slate-600 text-sm font-semibold rounded-xl hover:bg-slate-200 transition-colors">Reset</a>
        </div>
    </form>
</div>

<?php if (!empty($progressData)): ?>
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead>
                <tr class="bg-slate-50 text-xs font-semibold text-slate-500 uppercase tracking-wider border-b border-slate-200">
                    <th class="px-5 py-3">Form Title</th>
                    <th class="px-5 py-3">Subject</th>
                    <th class="px-5 py-3">Section</th>
                    <th class="px-5 py-3">Teacher</th>
                    <th class="px-5 py-3">Semester</th>
                    <th class="px-5 py-3 text-center">Start Date</th>
                    <th class="px-5 py-3 text-center">End Date</th>
                    <th class="px-5 py-3 text-center">Status</th>
                    <th class="px-5 py-3 text-center">Total</th>
                    <th class="px-5 py-3 text-center">Submitted</th>
                    <th class="px-5 py-3 text-center">Not Submitted</th>
                    <th class="px-5 py-3 text-center">Progress</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php
                $today = date('Y-m-d');
                foreach ($progressData as $row):
                    $total = (int)$row['total_students'];
                    $submitted = (int)$row['submitted_count'];
                    $notSubmitted = max(0, $total - $submitted);
                    $percent = $total > 0 ? round(($submitted / $total) * 100) : 0;

                    $formStatus = $row['status'];
                    if ($formStatus === 'active' && $row['start_date'] <= $today && $row['end_date'] >= $today) {
                        $statusLabel = 'Active';
                        $statusColor = 'bg-green-100 text-green-700';
                    } elseif ($row['end_date'] < $today) {
                        $statusLabel = 'Expired';
                        $statusColor = 'bg-slate-100 text-slate-500';
                    } elseif ($row['start_date'] > $today) {
                        $statusLabel = 'Upcoming';
                        $statusColor = 'bg-amber-100 text-amber-700';
                    } else {
                        $statusLabel = ucfirst($formStatus);
                        $statusColor = 'bg-slate-100 text-slate-500';
                    }

                    $barColor = $percent >= 80 ? 'bg-green-500' : ($percent >= 50 ? 'bg-amber-500' : 'bg-red-500');
                ?>
                <tr class="hover:bg-slate-50/50 transition-colors">
                    <td class="px-5 py-3 font-medium text-slate-800"><?= e($row['title']) ?></td>
                    <td class="px-5 py-3 text-slate-600"><?= e($row['course_name']) ?></td>
                    <td class="px-5 py-3"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-cyan-100 text-cyan-700"><?= e($row['section']) ?></span></td>
                    <td class="px-5 py-3 text-slate-600"><?= e($row['teacher_name']) ?></td>
                    <td class="px-5 py-3 text-slate-600"><?= e($row['semester']) ?></td>
                    <td class="px-5 py-3 text-center text-xs text-slate-500"><?= e($row['start_date']) ?></td>
                    <td class="px-5 py-3 text-center text-xs text-slate-500"><?= e($row['end_date']) ?></td>
                    <td class="px-5 py-3 text-center"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?= $statusColor ?>"><?= $statusLabel ?></span></td>
                    <td class="px-5 py-3 text-center font-bold text-slate-700"><?= $total ?></td>
                    <td class="px-5 py-3 text-center font-bold text-green-600"><?= $submitted ?></td>
                    <td class="px-5 py-3 text-center font-bold text-red-500"><?= $notSubmitted ?></td>
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-2">
                            <div class="flex-1 h-2 bg-slate-100 rounded-full overflow-hidden">
                                <div class="h-full <?= $barColor ?> rounded-full transition-all" style="width: <?= $percent ?>%"></div>
                            </div>
                            <span class="text-xs font-bold text-slate-600 w-10 text-right"><?= $percent ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="bg-white rounded-2xl shadow-sm border border-slate-100 text-center py-16 text-slate-400">
    <p class="text-sm">No feedback forms found matching your filters.</p>
</div>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>
