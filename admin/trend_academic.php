<?php
// ============================================================
// Admin — Academic Trend Analysis Page
// ============================================================
// Admins can analyze any teacher's feedback trends across
// multiple Academic Years. Filters: Teacher, Course.
// Does NOT modify any existing functionality.
// ============================================================

require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/trend_helpers.php';

requireRole('admin');

$pageTitle  = $LANG['academic_trend_analysis'] ?? 'Academic Trend Analysis';
$activeMenu = 'trend_academic';

// --- Filters ------------------------------------------------
$teacherId = (int) ($_GET['teacher_id'] ?? 0);
$courseId   = (int) ($_GET['course_id'] ?? 0);

// Dropdown data
$teachers = getTrendTeachers($conn);
$courses  = getTrendCourses($conn, $teacherId ?: null);

// --- Trend Data (only if both teacher and course selected) --
$trendData     = [];
$questionTrend = [];
$surveyTrend   = [];
$summary       = null;
$ayImprovements = [];
$hasData       = false;
$hasMultipleAY = false;

if ($teacherId && $courseId) {
    $trendData     = getAcademicRatingTrend($conn, $teacherId, $courseId ?: null);
    $questionRaw   = getAcademicQuestionTrend($conn, $teacherId, $courseId ?: null);
    $surveyRaw     = getAcademicSurveyTrend($conn, $teacherId, $courseId ?: null);

    $questionTrend = processQuestionTrend($questionRaw);
    $surveyTrend   = processSurveyTrend($surveyRaw);
    $summary       = buildTrendSummary($trendData);

    $hasData       = count($trendData) > 0;
    $hasMultipleAY = count($trendData) > 1;

    // Per-AY improvement calculations
    for ($i = 0; $i < count($trendData); $i++) {
        if ($i === 0) {
            $ayImprovements[] = null;
        } else {
            $ayImprovements[] = calcImprovement(
                (float) $trendData[$i]['avg_rating'],
                (float) $trendData[$i - 1]['avg_rating']
            );
        }
    }
}

// Get selected teacher name for display
$selectedTeacherName = '';
if ($teacherId) {
    foreach ($teachers as $t) {
        if ((int) $t['id'] === $teacherId) {
            $selectedTeacherName = $t['name'];
            break;
        }
    }
}

// --- Page Rendering -----------------------------------------
include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>

<!-- --- Page Header -------------------------------------------- -->
<div class="mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">?? <?= e($pageTitle) ?></h2>
            <p class="text-sm text-slate-500 mt-1">
                <?= $LANG['academic_trend_desc'] ?? 'Compare teacher feedback performance across Academic Years' ?>
            </p>
        </div>
    </div>
</div>

<!-- --- Filters ------------------------------------------------ -->
<div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-5 mb-6">
    <div class="flex flex-wrap items-end gap-4">
        <!-- Teacher Filter -->
        <div class="flex-1 min-w-[220px]">
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">
                <?= $LANG['teacher'] ?? 'Teacher' ?>
            </label>
            <select id="trendTeacherFilter"
                onchange="var c=document.getElementById('trendCourseFilter'); window.location.href='trend_academic.php' + (this.value ? '?teacher_id=' + this.value : '') + (c && c.value ? (this.value ? '&' : '?') + 'course_id=' + c.value : '')"
                class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all">
                <option value=""><?= $LANG['select_teacher'] ?? '— Select Teacher —' ?></option>
                <?php foreach ($teachers as $t): ?>
                    <option value="<?= (int) $t['id'] ?>" <?= $teacherId === (int) $t['id'] ? 'selected' : '' ?>>
                        <?= e($t['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- Course Filter -->
        <div class="flex-1 min-w-[220px]">
            <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wider mb-1.5">
                <?= $LANG['course'] ?? 'Course' ?>
            </label>
            <select id="trendCourseFilter"
                onchange="window.location.href='trend_academic.php' + (<?= $teacherId ? "'?teacher_id=$teacherId'" : "''" ?>) + (this.value ? '<?= $teacherId ? '&' : '?' ?>course_id=' + this.value : '')"
                class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all">
                <option value=""><?= $LANG['all_courses'] ?? 'All Courses' ?></option>
                <?php foreach ($courses as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= $courseId === (int) $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['course_code']) ?> — <?= e($c['course_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- Clear button -->
        <?php if ($teacherId || $courseId): ?>
            <a href="trend_academic.php"
                class="px-4 py-2.5 text-sm font-medium text-white bg-red-500 hover:bg-red-700 rounded-xl transition-all">
                <?= $LANG['clear'] ?? 'Clear' ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if (!$teacherId || !$courseId): ?>
    <!-- --- Select Both Filters Prompt ------------------------ -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-12 text-center">
        <div class="text-5xl mb-4">??</div>
        <h3 class="text-lg font-semibold text-slate-700 mb-2">
            <?= $LANG['select_both_prompt'] ?? 'Please select both a Teacher and a Course to view the Trend Analysis.' ?>
        </h3>
        <p class="text-sm text-slate-500">
            <?= $LANG['select_both_prompt_desc'] ?? 'Choose a teacher and a course from the dropdowns above to view their feedback trend analysis across Academic Years.' ?>
        </p>
    </div>

<?php elseif (!$hasData): ?>
    <!-- --- No Data -------------------------------------------- -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-12 text-center">
        <div class="text-5xl mb-4">??</div>
        <h3 class="text-lg font-semibold text-slate-700 mb-2">
            <?= $LANG['no_trend_data'] ?? 'No Feedback Data Available' ?>
        </h3>
        <p class="text-sm text-slate-500">
            <?= $LANG['no_trend_data_teacher'] ?? 'No feedback data found for this teacher.' ?>
        </p>
    </div>

<?php elseif (!$hasMultipleAY): ?>
    <!-- --- Single AY — No Comparison -------------------------- -->
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 mb-6">
        <div class="flex items-start gap-3">
            <span class="text-2xl">??</span>
            <div>
                <h3 class="text-base font-semibold text-amber-800">
                    <?= $LANG['no_historical_data'] ?? 'No historical data available for comparison.' ?>
                </h3>
                <p class="text-sm text-amber-700 mt-1">
                    <?= $LANG['no_historical_data_desc'] ?? 'Trend analysis requires feedback data from at least two Academic Years. Currently only data from' ?>
                    <strong><?= e($trendData[0]['year_name']) ?></strong>
                    <?= $LANG['is_available'] ?? 'is available.' ?>
                </p>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-6">
        <h3 class="text-base font-semibold text-slate-800 mb-1">
            <?= e($selectedTeacherName) ?> — <?= e($trendData[0]['year_name']) ?>
        </h3>
        <p class="text-sm text-slate-500 mb-4"><?= $LANG['feedback_summary'] ?? 'Feedback Summary' ?></p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-blue-50 rounded-xl p-4 text-center">
                <p class="text-2xl font-bold text-blue-700"><?= $trendData[0]['avg_rating'] ?></p>
                <p class="text-xs text-blue-600 mt-1"><?= $LANG['average_rating'] ?? 'Average Rating' ?> (1–5)</p>
            </div>
            <div class="bg-emerald-50 rounded-xl p-4 text-center">
                <p class="text-2xl font-bold text-emerald-700"><?= (int) $trendData[0]['good_count'] ?></p>
                <p class="text-xs text-emerald-600 mt-1"><?= $LANG['good_ratings'] ?? 'Good Ratings' ?></p>
            </div>
            <div class="bg-slate-50 rounded-xl p-4 text-center">
                <p class="text-2xl font-bold text-slate-700"><?= (int) $trendData[0]['total_ratings'] ?></p>
                <p class="text-xs text-slate-600 mt-1"><?= $LANG['total_responses'] ?? 'Total Responses' ?></p>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- --- Full Trend Analysis -------------------------------- -->

    <!-- Teacher info banner -->
    <div class="bg-indigo-50/50 border border-indigo-100 rounded-xl px-5 py-3 mb-6 flex items-center gap-3">
        <div class="w-8 h-8 rounded-full bg-indigo-600 flex items-center justify-center text-xs font-bold text-white">
            <?= e(avatarInitials($selectedTeacherName)) ?>
        </div>
        <div>
            <p class="text-sm font-semibold text-slate-800"><?= e($selectedTeacherName) ?></p>
            <p class="text-xs text-slate-500">
                <?= $LANG['trend_across'] ?? 'Trend across' ?> <?= count($trendData) ?>
                <?= $LANG['academic_years'] ?? 'Academic Years' ?>
            </p>
        </div>
    </div>

    <!-- KPI Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-5">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center text-xl">??</div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <?= $LANG['latest_avg_rating'] ?? 'Latest Avg Rating' ?>
                </p>
            </div>
            <p class="text-3xl font-bold text-slate-800"><?= $summary['latest_avg'] ?><span
                    class="text-base font-normal text-slate-400">/5</span></p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-5">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-emerald-50 flex items-center justify-center text-xl">??</div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <?= $LANG['highest_rating'] ?? 'Highest Rating' ?>
                </p>
            </div>
            <p class="text-xl font-bold text-emerald-700"><?= e($summary['best_year']) ?></p>
            <p class="text-sm text-slate-500"><?= $summary['best_avg'] ?>/5</p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-5">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-red-50 flex items-center justify-center text-xl">??</div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <?= $LANG['lowest_rating'] ?? 'Lowest Rating' ?>
                </p>
            </div>
            <p class="text-xl font-bold text-red-700"><?= e($summary['worst_year']) ?></p>
            <p class="text-sm text-slate-500"><?= $summary['worst_avg'] ?>/5</p>
        </div>
        <div
            class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-5 <?= $summary['trend_info']['bg'] ?>">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 rounded-xl bg-white/60 flex items-center justify-center text-xl">
                    <?= $summary['trend_info']['icon'] ?>
                </div>
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <?= $LANG['trend_status'] ?? 'Trend Status' ?>
                </p>
            </div>
            <p class="text-xl font-bold <?= $summary['trend_info']['color'] ?>">
                <?= e($summary['trend_info']['status']) ?>
            </p>
            <p class="text-sm <?= $summary['trend_info']['color'] ?>">
                <?= $summary['overall_change_pct'] >= 0 ? '+' : '' ?><?= $summary['overall_change_pct'] ?>%
            </p>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-4">
                <?= $LANG['overall_rating_trend'] ?? 'Overall Rating Trend' ?>
            </h3>
            <div class="relative" style="height: 300px;">
                <canvas id="trendOverallLineChart"></canvas>
            </div>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-6">
            <h3 class="text-base font-semibold text-slate-800 mb-4">
                <?= $LANG['rating_comparison'] ?? 'Rating Comparison' ?>
            </h3>
            <div class="relative" style="height: 300px;">
                <canvas id="trendOverallBarChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Per-AY Details Table -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/60 p-6 mb-6">
        <h3 class="text-base font-semibold text-slate-800 mb-4">
            <?= $LANG['yearly_breakdown'] ?? 'Year-by-Year Breakdown' ?>
        </h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200">
                        <th class="text-left py-3 px-4 text-slate-500"><?= $LANG['academic_year'] ?? 'Academic Year' ?></th>
                        <th class="text-center py-3 px-4 text-slate-500"><?= $LANG['average_rating'] ?? 'Avg Rating' ?></th>
                        <th class="text-center py-3 px-4 text-slate-500"><?= $LANG['good'] ?? 'Good' ?></th>
                        <th class="text-center py-3 px-4 text-slate-500"><?= $LANG['fair'] ?? 'Fair' ?></th>
                        <th class="text-center py-3 px-4 text-slate-500"><?= $LANG['bad'] ?? 'Bad' ?></th>
                        <th class="text-center py-3 px-4 text-slate-500"><?= $LANG['total'] ?? 'Total' ?></th>
                        <th class="text-center py-3 px-4 text-slate-500"><?= $LANG['change'] ?? 'Change' ?></th>
                        <th class="text-center py-3 px-4 text-slate-500"><?= $LANG['status'] ?? 'Status' ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($trendData as $i => $row): ?>
                        <tr class="border-b border-slate-100 hover:bg-slate-50/50 transition-colors">
                            <td class="py-3 px-4 font-medium text-slate-800"><?= e($row['year_name']) ?></td>
                            <td class="py-3 px-4 text-center">
                                <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg bg-indigo-50 text-indigo-700 font-bold text-sm">
                                    <?= $row['avg_rating'] ?>
                                </span>
                            </td>
                            <td class="py-3 px-4 text-center text-emerald-600 font-medium"><?= (int) $row['good_count'] ?></td>
                            <td class="py-3 px-4 text-center text-amber-600 font-medium"><?= (int) $row['fair_count'] ?></td>
                            <td class="py-3 px-4 text-center text-red-600 font-medium"><?= (int) $row['bad_count'] ?></td>
                            <td class="py-3 px-4 text-center text-slate-600"><?= (int) $row['total_ratings'] ?></td>
                            <td class="py-3 px-4 text-center">
                                <?php if ($ayImprovements[$i] === null): ?>
                                    <span class="text-slate-400">—</span>
                                <?php else:
                                    $imp = $ayImprovements[$i];
                                    $impColor = $imp > 2 ? 'text-emerald-600' : ($imp < -2 ? 'text-red-600' : 'text-amber-600');
                                ?>
                                    <span class="font-semibold <?= $impColor ?>">
                                        <?= $imp >= 0 ? '+' : '' ?><?= $imp ?>%
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 px-4 text-center">
                                <?php if ($ayImprovements[$i] === null): ?>
                                    <span class="text-slate-400">—</span>
                                <?php else:
                                    $info = trendStatusInfo($ayImprovements[$i]);
                                ?>
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold <?= $info['badge'] ?>">
                                        <?= $info['icon'] ?> <?= e($info['status']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- --- Chart.js Initialization -------------------------------- -->
    <script>
        const trendLabels = <?= json_encode(array_column($trendData, 'year_name')) ?>;
        const trendAvgRatings = <?= json_encode(array_map('floatval', array_column($trendData, 'avg_rating'))) ?>;
        const trendColors = <?= json_encode(trendChartColors()) ?>;

        // Overall Rating Trend — Line Chart
        new Chart(document.getElementById('trendOverallLineChart'), {
            type: 'line',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: '<?= $LANG['average_rating'] ?? 'Average Rating' ?>',
                    data: trendAvgRatings,
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99,102,241,0.08)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 6,
                    pointHoverRadius: 9,
                    pointBackgroundColor: '#6366f1',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    borderWidth: 3,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        min: 0, max: 5, ticks: { stepSize: 1 },
                        title: { display: true, text: '<?= $LANG['average_rating'] ?? 'Average Rating' ?>', font: { size: 11 } },
                        grid: { color: 'rgba(0,0,0,0.04)' }
                    },
                    x: {
                        title: { display: true, text: '<?= $LANG['academic_year'] ?? 'Academic Year' ?>', font: { size: 11 } },
                        grid: { display: false }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(15,23,42,0.9)', padding: 12, cornerRadius: 8,
                        callbacks: { label: ctx => '<?= $LANG['average_rating'] ?? 'Avg Rating' ?>: ' + ctx.parsed.y.toFixed(2) + ' / 5' }
                    }
                }
            }
        });

        // Rating Comparison — Vertical Bar Chart
        const barColors = trendLabels.map((_, i) => {
            const shades = ['#a5b4fc', '#818cf8', '#6366f1', '#4f46e5', '#4338ca', '#3730a3'];
            return shades[i % shades.length];
        });
        new Chart(document.getElementById('trendOverallBarChart'), {
            type: 'bar',
            data: {
                labels: trendLabels,
                datasets: [{
                    label: '<?= $LANG['average_rating'] ?? 'Average Rating' ?>',
                    data: trendAvgRatings,
                    backgroundColor: barColors,
                    borderRadius: 8, borderSkipped: false, maxBarThickness: 60,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { min: 0, max: 5, ticks: { stepSize: 1 }, title: { display: true, text: '<?= $LANG['average_rating'] ?? 'Rating' ?>', font: { size: 11 } }, grid: { color: 'rgba(0,0,0,0.04)' } },
                    x: { grid: { display: false } }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: { backgroundColor: 'rgba(15,23,42,0.9)', padding: 12, cornerRadius: 8, callbacks: { label: ctx => '<?= $LANG['average_rating'] ?? 'Rating' ?>: ' + ctx.parsed.y.toFixed(2) + ' / 5' } }
                }
            }
        });
    </script>
<?php endif; ?>

<?php include '../includes/admin_footer.php'; ?>
