<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['adm_results_title'] ?? 'Administration Feedback Results Matrix';
$activeMenu = 'adm_results';

// Filter Inputs
$hasFormParam = array_key_exists('form_id', $_GET);
$formId = (int) ($_GET['form_id'] ?? 0);
$semesterFilter = clean($_GET['semester'] ?? '');

// Distinct semesters for filter
$semesters = [];
$semRes = $conn->query("SELECT DISTINCT semester FROM sections WHERE semester IS NOT NULL AND semester != '' ORDER BY semester");
while ($r = $semRes->fetch_assoc()) {
    $semesters[] = $r['semester'];
}

// ၁။ Dropdown အတွက် စာရင်းဆွဲထုတ်ခြင်း
$allForms = $conn->query("SELECT id, title, status FROM feedback_forms WHERE module='administration' ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);

// Form ID တိုက်ရိုက်မပါလာပါက နောက်ဆုံးဖောင်ကို Auto ရွေးပေးထားမည်
if (!$hasFormParam && !$formId && !empty($allForms)) {
    $formId = (int) $allForms[0]['id'];
    $params = ['form_id' => $formId];
    if ($semesterFilter)
        $params['semester'] = $semesterFilter;
    header('Location: adm_results.php?' . http_build_query($params));
    exit;
}

$form = null;
$questions = [];
$ratingStats = [];
$allComments = [];
$surveyStats = [];

if ($formId) {
    // ၂။ Form Metadata ဆွဲထုတ်ခြင်း
    $r = $conn->prepare("SELECT * FROM feedback_forms WHERE id = ? AND module='administration'");
    $r->bind_param('i', $formId);
    $r->execute();
    $form = $r->get_result()->fetch_assoc();
    $r->close();

    if ($form) {
        $academicYear = $form['academic_year'] ?? '';

        // ၃။ မေးခွန်းများကို module အလိုက် (shared) ဆွဲထုတ်ခြင်း
        $q = $conn->prepare("SELECT id, question_no, question_text, question_type, options_json FROM feedback_questions WHERE module='administration' ORDER BY question_no ASC");
        $q->execute();
        $questions = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        // ၄။ Rating Questions များအတွက် တွက်ချက်ခြင်း (semester filter အလိုက်)
        if ($semesterFilter) {
            $statStmt = $conn->prepare("
                SELECT fq.id AS question_id, fr.rating, COUNT(DISTINCT fr.id) AS qty
                FROM feedback_ratings fr
                JOIN feedback_questions fq ON fr.question_id = fq.id
                JOIN feedback_submissions fs ON fr.form_id = fs.form_id AND fr.created_at = fs.submitted_at
                JOIN students st ON fs.student_id = st.id
                JOIN section_assignments sa ON sa.student_id = st.id
                JOIN sections s ON sa.section_id = s.id
                WHERE fr.form_id = ? AND s.semester = ?
                GROUP BY fq.id, fr.rating
            ");
            $statStmt->bind_param('is', $formId, $semesterFilter);
        } else {
            $statStmt = $conn->prepare("
                SELECT fq.id AS question_id, fr.rating, COUNT(*) AS qty
                FROM feedback_ratings fr
                JOIN feedback_questions fq ON fr.question_id = fq.id
                WHERE fr.form_id = ?
                GROUP BY fq.id, fr.rating
            ");
            $statStmt->bind_param('i', $formId);
        }
        $statStmt->execute();
        $rawStats = $statStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $statStmt->close();

        foreach ($rawStats as $row) {
            $rKey = trim($row['rating']);

            // ဒေတာဘေ့စ်ထဲရှိ တန်ဖိုးများကို Standard English UI Key သို့ ပြောင်းလဲချိန်ညှိပါသည်
            if ($rKey == '3' || $rKey === 'ကောင်း' || $rKey === 'Good' || $rKey === 'good') {
                $rKey = 'Good';
            } elseif ($rKey == '2' || $rKey === 'သင့်' || $rKey === 'Normal' || $rKey === 'normal' || $rKey === 'Average' || $rKey === 'Fair' || $rKey === 'fair') {
                $rKey = 'Fair';
            } elseif ($rKey == '1' || $rKey === 'ညံ့' || $rKey === 'Bad' || $rKey === 'bad' || $rKey === 'Poor' || $rKey === 'poor') {
                $rKey = 'Bad';
            }

            if (!isset($ratingStats[$row['question_id']][$rKey])) {
                $ratingStats[$row['question_id']][$rKey] = 0;
            }
            $ratingStats[$row['question_id']][$rKey] += (int) $row['qty'];
        }

        // ၅။ Comments များကို မေးခွန်းအလိုက် Anonymous အဖြစ် စုစည်းထုတ်ယူခြင်း (semester filter အလိုက်)
        if ($semesterFilter) {
            $cStmt = $conn->prepare("
                SELECT DISTINCT fq.id AS question_id, fc.comment_text
                FROM feedback_comments fc
                JOIN feedback_questions fq ON fc.question_id = fq.id
                JOIN feedback_submissions fs ON fc.form_id = fs.form_id AND fc.created_at = fs.submitted_at
                JOIN students st ON fs.student_id = st.id
                JOIN section_assignments sa ON sa.student_id = st.id
                JOIN sections s ON sa.section_id = s.id
                WHERE fc.form_id = ? AND s.semester = ? AND fc.comment_text IS NOT NULL AND fc.comment_text != ''
            ");
            $cStmt->bind_param('is', $formId, $semesterFilter);
        } else {
            $cStmt = $conn->prepare("
                SELECT fq.id AS question_id, fc.comment_text
                FROM feedback_comments fc
                JOIN feedback_questions fq ON fc.question_id = fq.id
                WHERE fc.form_id = ? AND fc.comment_text IS NOT NULL AND fc.comment_text != ''
            ");
            $cStmt->bind_param('i', $formId);
        }
        $cStmt->execute();
        $rawComments = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cStmt->close();

        foreach ($rawComments as $row) {
            $allComments[$row['question_id']][] = $row['comment_text'];
        }

        // Survey stats
        $surveyQs = array_filter($questions, fn($q) => $q['question_type'] === 'survey');
        if (!empty($surveyQs)) {
            $surveyIds = array_column($surveyQs, 'id');
            $placeholders = implode(',', array_fill(0, count($surveyIds), '?'));
            $surveyStmt = $conn->prepare("
                SELECT fsa.question_id, fsa.selected_option_index, COUNT(*) AS cnt
                FROM feedback_survey_answers fsa
                JOIN feedback_submissions fsub ON fsa.submission_id = fsub.id
                WHERE fsub.form_id = ? AND fsa.question_id IN ($placeholders)
                GROUP BY fsa.question_id, fsa.selected_option_index
            ");
            $bindParams = array_merge([$formId], $surveyIds);
            $surveyStmt->bind_param('i' . str_repeat('i', count($surveyIds)), ...$bindParams);
            $surveyStmt->execute();
            $rawSurvey = $surveyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $surveyStmt->close();
            foreach ($rawSurvey as $sr) {
                $surveyStats[$sr['question_id']][(int)$sr['selected_option_index']] = (int)$sr['cnt'];
            }
        }
    }
}

// မေးခွန်းခွဲထုတ်ခြင်း
$ratingQuestions = [];
$commentQuestions = [];
$surveyQuestions = [];
foreach ($questions as $q) {
    if ($q['question_type'] === 'rating') {
        $ratingQuestions[] = $q;
    } elseif ($q['question_type'] === 'survey') {
        $surveyQuestions[] = $q;
    } else {
        $commentQuestions[] = $q;
    }
}

// Summary Statistics
$totalStudents = 0;
$completedFeedback = 0;
$pendingFeedback = 0;

if ($semesterFilter) {
    $stStmt = $conn->prepare("SELECT COUNT(DISTINCT sa.student_id) AS cnt FROM section_assignments sa JOIN sections s ON sa.section_id = s.id JOIN students st ON sa.student_id = st.id WHERE s.semester = ?");
    $stStmt->bind_param('s', $semesterFilter);
    $stStmt->execute();
    $totalStudents = (int) $stStmt->get_result()->fetch_assoc()['cnt'];
    $stStmt->close();

    if ($formId && $totalStudents > 0) {
        $compStmt = $conn->prepare("SELECT COUNT(DISTINCT afs.student_id) AS cnt FROM feedback_submissions afs JOIN students st ON afs.student_id = st.id JOIN section_assignments sa ON sa.student_id = st.id JOIN sections s ON sa.section_id = s.id WHERE afs.form_id = ? AND s.semester = ?");
        $compStmt->bind_param('is', $formId, $semesterFilter);
        $compStmt->execute();
        $completedFeedback = (int) $compStmt->get_result()->fetch_assoc()['cnt'];
        $compStmt->close();
    }
} else {
    $totalStudents = (int) $conn->query("SELECT COUNT(DISTINCT sa.student_id) AS cnt FROM section_assignments sa JOIN students st ON sa.student_id = st.id")->fetch_assoc()['cnt'];
    if ($formId) {
        $completedFeedback = (int) $conn->query("SELECT COUNT(DISTINCT student_id) AS cnt FROM feedback_submissions WHERE form_id = $formId")->fetch_assoc()['cnt'];
    }
}
$pendingFeedback = max(0, $totalStudents - $completedFeedback);

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>
<style>
    @import url('https://cdn.jsdelivr.net/css-myanmar-fonts/v1/pyidaungsu.css');

    .myanmar-font {
        font-family: 'Pyidaungsu', sans-serif;
    }

    @media print {

        *,
        *::before,
        *::after {
            overflow: visible !important;
            max-height: none !important;
            height: auto !important;
        }

        html,
        body {
            height: auto !important;
            overflow: visible !important;
            background: white !important;
        }

        body {
            position: static !important;
        }

        #sidebar,
        #sidebar-overlay,
        header,
        .no-print,
        nav,
        aside {
            display: none !important;
        }

        .flex.h-screen {
            display: block !important;
            height: auto !important;
            overflow: visible !important;
        }

        .flex-1.flex.flex-col {
            overflow: visible !important;
            width: 100% !important;
        }

        main {
            overflow: visible !important;
            padding: 0 !important;
            height: auto !important;
        }

        .bg-white.shadow-lg,
        .bg-white.rounded-2xl {
            box-shadow: none !important;
            break-inside: avoid;
        }

        @page {
            margin: 1.5cm;
            size: A4 landscape;
        }

        .mb-6 {
            margin-bottom: 1rem !important;
        }

        .mb-8 {
            margin-bottom: 1rem !important;
        }

        .mt-8 {
            margin-top: 1rem !important;
        }

        table {
            page-break-inside: auto;
        }

        thead,
        tfoot {
            display: table-header-group;
        }

        tr {
            page-break-inside: auto;
        }

        h3 {
            page-break-after: avoid;
        }

        .overflow-x-auto {
            overflow: visible !important;
        }

        .space-y-2>div:last-child {
            page-break-inside: avoid;
        }
    }
</style>

<div class="mb-6 myanmar-font flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-xl font-bold text-slate-800"><?= $LANG['adm_results_content_heading'] ?? 'Administration Feedback Matrix' ?></h2>
        <p class="text-sm text-slate-500 mt-0.5"><?= $LANG['adm_results_subtitle'] ?? 'စီမံခန့်ခွဲမှုဆိုင်ရာ စစ်တမ်းများ၏ မေးခွန်းအလိုက် စုစုပေါင်းစာရင်းဇယားရလဒ်များ' ?></p>
    </div>
    <?php if ($form): ?>
        <button onclick="window.print()"
            class="no-print inline-flex items-center gap-2 bg-slate-700 hover:bg-slate-800 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm transition-all hover:-translate-y-0.5">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
                class="w-4 h-4">
                <path stroke-linecap="round" stroke-linejoin="round"
                    d="M6.72 13.829c-.24.03-.48.062-.72.096m.72-.096a42.415 42.415 0 0110.56 0m-10.56 0L6.34 18m10.94-4.171c.24.03.48.062.72.096m-.72-.096L17.66 18m0 0l.229 2.523a1.125 1.125 0 01-1.12 1.227H7.231c-.662 0-1.18-.568-1.12-1.227L6.34 18m11.318 0h1.091A2.25 2.25 0 0021 15.75V9.456c0-1.081-.768-2.015-1.837-2.175a48.055 48.055 0 00-1.913-.247M6.34 18H5.25A2.25 2.25 0 013 15.75V9.456c0-1.081.768-2.015 1.837-2.175a48.041 48.041 0 011.913-.247m0 0a48.159 48.159 0 018.5 0m-8.5 0V6.75a2 2 0 012-2h4.5a2 2 0 012 2v1.044" />
            </svg>
            <?= $LANG['print_report'] ?? 'Print Report' ?>
        </button>
    <?php endif; ?>
</div>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6 myanmar-font no-print">
    <div class="flex flex-wrap items-end gap-4">
        <div class="flex-1 max-w-xl">
            <label class="block text-xs font-bold text-slate-500 mb-1"><?= $LANG['filter_by_semester'] ?? 'Filter by Semester ( semester ရွေးချယ်ရန်):' ?></label>
            <select
                onchange="var fp=new URLSearchParams(window.location.search); fp.set('semester',this.value); location.href='?'+fp.toString();"
                class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white font-semibold text-slate-700 shadow-sm focus:border-slate-500">
                <option value=""><?= $LANG['all_semesters'] ?? '— All Semesters —' ?></option>
                <?php foreach ($semesters as $sem): ?>
                    <option value="<?= e($sem) ?>" <?= $semesterFilter === $sem ? 'selected' : '' ?>><?= e(formatSemester($sem)) ?></option>
                <?php endforeach ?>
            </select>
        </div>
    </div>
</div>

<?php if ($semesterFilter): ?>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 myanmar-font no-print">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 text-center">
            <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1"><?= $LANG['total_students'] ?? 'စုစုပေါင်း ကျောင်းသား (Total Students)' ?></p>
            <p class="text-3xl font-black text-slate-800"><?= $totalStudents ?></p>
            <p class="text-[10px] text-slate-400 mt-1"><?= e(formatSemester($semesterFilter)) ?></p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 text-center">
            <p class="text-xs font-bold text-emerald-500 uppercase tracking-wider mb-1"><?= $LANG['completed'] ?? 'ဖြေဆိုပြီး (Completed)' ?></p>
            <p class="text-3xl font-black text-emerald-600"><?= $completedFeedback ?></p>
            <p class="text-[10px] text-slate-400 mt-1">
                <?= $totalStudents > 0 ? round(($completedFeedback / $totalStudents) * 100) : 0 ?>% <?= $LANG['response_rate'] ?? 'response rate' ?>
            </p>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 text-center">
            <p class="text-xs font-bold text-amber-500 uppercase tracking-wider mb-1"><?= $LANG['pending'] ?? 'ကျန်ရှိနေသေး (Pending)' ?></p>
            <p class="text-3xl font-black text-amber-600"><?= $pendingFeedback ?></p>
            <p class="text-[10px] text-slate-400 mt-1">
                <?= $totalStudents > 0 ? round(($pendingFeedback / $totalStudents) * 100) : 0 ?>% <?= $LANG['remaining'] ?? 'remaining' ?>
            </p>
        </div>
    </div>
<?php endif ?>

<?php if ($form): ?>
    <div class="max-w-4xl mx-auto w-full myanmar-font">
        <div class="bg-white shadow-xl rounded-xl border border-slate-200 p-6 md:p-10 mb-8">

            <div class="text-center pb-4 mb-6 border-b border-slate-100">

                <h2 class="text-lg md:text-xl font-bold text-slate-950 mb-1"><?= $LANG['university_name'] ?? 'University of Computer Studies (Hinthada)' ?></h2>
                <p class="text-md font-black text-slate-900 mb-1"><?= $LANG['academic_year_label'] ?? 'Academic Year' ?>:
                    <?= e($form['academic_year'] ?? '') ?>
                </p>
                <p class="text-md font-black text-slate-900 mt-1 tracking-wider"><?= $LANG['university_campus'] ?? 'University Campus' ?></p>
                <h3 class="text-md font-black text-slate-900 mt-1"><?= e($form['title']) ?></h3>
                <p class="text-xs text-slate-500 mt-1"><?= $LANG['feedback_period'] ?? 'Feedback Period' ?>: <?= formatDate($form['start_date']) ?> —
                    <?= formatDate($form['end_date']) ?>
                </p>
            </div>

            <?php if (!empty($ratingQuestions)): ?>
                <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3"><?= $LANG['question_stats_header'] ?? 'မေးခွန်းအလိုက် စာရင်းဇယား ရလဒ်များ' ?></h3>
                <div class="overflow-x-auto border border-slate-300 rounded-lg shadow-sm mb-8">
                    <table class="w-full text-left border-collapse min-w-[500px]">
                        <thead>
                            <tr class="bg-slate-50 border-b border-slate-300 text-slate-900 font-bold text-xs">
                                <th class="p-3 border-r border-slate-300 w-12 text-center"><?= $LANG['col_no'] ?? 'စဉ်' ?></th>
                                <th class="p-3 border-r border-slate-300"><?= $LANG['col_activities'] ?? 'လုပ်ဆောင်ချက်များ' ?></th>
                                <th class="p-3 border-r border-slate-300 w-28 text-center bg-emerald-50 text-emerald-900">
                                    <div><?= $LANG['good'] ?? 'Good' ?></div>
                                    <div class="text-[10px] font-normal"><?= $LANG['count_pct'] ?? 'COUNT / %' ?></div>
                                </th>
                                <th class="p-3 border-r border-slate-300 w-28 text-center bg-amber-50 text-amber-900">
                                    <div><?= $LANG['fair'] ?? 'Fair' ?></div>
                                    <div class="text-[10px] font-normal"><?= $LANG['count_pct'] ?? 'COUNT / %' ?></div>
                                </th>
                                <th class="p-3 w-28 text-center bg-red-50 text-red-900">
                                    <div><?= $LANG['bad'] ?? 'Bad' ?></div>
                                    <div class="text-[10px] font-normal"><?= $LANG['count_pct'] ?? 'COUNT / %' ?></div>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="text-xs divide-y divide-slate-200 text-slate-800">
                            <?php foreach ($ratingQuestions as $q):
                                $goodCount = $ratingStats[$q['id']]['Good'] ?? 0;
                                $normalCount = $ratingStats[$q['id']]['Fair'] ?? 0;
                                $badCount = $ratingStats[$q['id']]['Bad'] ?? 0;
                                $totalVotes = $goodCount + $normalCount + $badCount;

                                $goodPerc = $totalVotes > 0 ? round(($goodCount / $totalVotes) * 100) : 0;
                                $normalPerc = $totalVotes > 0 ? round(($normalCount / $totalVotes) * 100) : 0;
                                $badPerc = $totalVotes > 0 ? round(($badCount / $totalVotes) * 100) : 0;
                                ?>
                                <tr class="hover:bg-slate-50/50 transition-colors">
                                    <td class="p-3 border-r border-slate-200 text-center font-bold font-mono">
                                        <?= e($q['question_no']) ?>
                                    </td>
                                    <td class="p-3 border-r border-slate-200 font-medium leading-relaxed text-slate-900">
                                        <?= e($q['question_text']) ?>
                                    </td>
                                    <td class="p-3 border-r border-slate-200 text-center bg-emerald-50/30">
                                        <div class="text-emerald-700 font-bold text-sm mb-1"><?= $goodCount ?> <?= $LANG['persons'] ?? 'ယောက်' ?></div>
                                        <div class="text-emerald-700 font-bold text-sm">(<?= $goodPerc ?>%)</div>
                                    </td>
                                    <td class="p-3 border-r border-slate-200 text-center bg-amber-50/30">
                                        <div class="text-amber-700 font-bold text-sm mb-1"><?= $normalCount ?> <?= $LANG['persons'] ?? 'ယောက်' ?></div>
                                        <div class="text-emerald-700 font-bold text-sm">(<?= $normalPerc ?>%)</div>
                                    </td>
                                    <td class="p-3 text-center bg-red-50/30">
                                        <div class="text-red-700 font-bold text-sm mb-1"><?= $badCount ?> <?= $LANG['persons'] ?? 'ယောက်' ?> </div>
                                        <div class="text-emerald-700 font-bold text-sm">(<?= $badPerc ?>%)</div>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>

            <?php if (!empty($commentQuestions)): ?>
                <div class="space-y-6 pt-4 border-t-2 border-slate-200">
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3"><?= $LANG['comments_header'] ?? 'အကြံပြုချက်များနှင့် မှတ်ချက်များစုစည်းမှု (Comments Box)' ?></h3>
                    <?php foreach ($commentQuestions as $q):
                        $commentsForQuestion = $allComments[$q['id']] ?? [];
                        ?>
                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-slate-800 leading-relaxed">
                                (<?= e($q['question_no']) ?>)။ <?= e($q['question_text']) ?>
                                <span class="text-slate-400 font-normal"> (<?= $LANG['total_comments'] ?? 'စုစုပေါင်းမှတ်ချက်' ?> - <?= count($commentsForQuestion) ?>
                                    <?= $LANG['persons'] ?? 'ခု' ?>)</span>
                            </label>
                            <div
                                class="w-full bg-slate-50 border border-slate-200 p-4 rounded-xl space-y-2.5 max-h-[300px] overflow-y-auto">
                                <?php if (!empty($commentsForQuestion)): ?>
                                    <?php foreach ($commentsForQuestion as $index => $commentText): ?>
                                        <div class="bg-white border border-slate-100 p-3 rounded-lg shadow-2xs flex items-start gap-2">
                                            <span class="text-slate-400 font-bold">#<?= $index + 1 ?></span>
                                            <div class="text-slate-800 font-medium whitespace-pre-wrap"><?= e($commentText) ?></div>
                                        </div>
                                    <?php endforeach ?>
                                <?php else: ?>
                                    <div class="text-slate-400 italic text-center py-4">— <?= $LANG['no_comments'] ?? '(ဤမေးခွန်းအတွက် ကျောင်းသားများထံမှ မှတ်ချက်မရှိပါ)' ?> —</div>
                                <?php endif ?>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <?php if (!empty($surveyQuestions)): ?>
                <div class="space-y-6 pt-6 border-t-2 border-violet-200">
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">Survey (MCQ) Results</h3>
                    <?php foreach ($surveyQuestions as $q):
                        $opts = json_decode($q['options_json'] ?? '[]', true) ?: [];
                        $qStats = $surveyStats[$q['id']] ?? [];
                        $totalVotes = array_sum($qStats);
                        ?>
                        <div class="space-y-2">
                            <label class="block text-xs font-bold text-slate-800 leading-relaxed">
                                (<?= e($q['question_no']) ?>)။ <?= e($q['question_text']) ?>
                                <span class="text-slate-400 font-normal">(<?= $totalVotes ?> responses)</span>
                            </label>
                            <div class="space-y-2">
                                <?php foreach ($opts as $idx => $opt):
                                    $votes = $qStats[$idx] ?? 0;
                                    $pct = $totalVotes > 0 ? round(($votes / $totalVotes) * 100) : 0;
                                    ?>
                                    <div class="flex items-center gap-3 bg-slate-50 border border-slate-200 rounded-xl px-4 py-2.5">
                                        <span class="flex-1 text-xs text-slate-700 font-medium"><?= e($opt) ?></span>
                                        <span class="text-xs font-bold text-slate-500"><?= $votes ?> votes</span>
                                        <span class="text-xs font-bold text-violet-700 bg-violet-100 px-2 py-0.5 rounded-full"><?= $pct ?>%</span>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <div class="mt-8 pt-4 border-t border-slate-100 text-right text-[11px] text-slate-400 font-semibold italic">
                <?= $LANG['anonymous_note'] ?? '"ဤအစီရင်ခံစာသည် စနစ်အတွင်းရှိ ကျောင်းသားများ၏ ပေးပို့ချက်အားလုံးကို စုစည်းတွက်ချက်ထားသော စာရင်းအင်းမူရင်းမှတ်တမ်း ဖြစ်ပါသည်။"' ?>
            </div>
        </div>
    </div>
<?php endif ?>

<?php include '../includes/admin_footer.php'; ?>