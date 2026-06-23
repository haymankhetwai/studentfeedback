<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = 'Student Feedback Results Matrix';
$activeMenu = 'results';

// Filter Inputs
$formId = (int) ($_GET['form_id'] ?? 0);
$teacherId = (int) ($_GET['teacher_id'] ?? 0);

// Teacher dropdown data
$teacherList = $conn->query("
    SELECT t.id, u.name AS teacher_name
    FROM teachers t
    JOIN users u ON t.user_id = u.id
    ORDER BY u.name ASC
")->fetch_all(MYSQLI_ASSOC);

// ၁။ Dropdown အတွက် Form စာရင်းကို အရင်ဆွဲထုတ်ပါသည်
$formSql = "
    SELECT ff.id, c.course_name, s.section, s.semester 
    FROM feedback_forms ff 
    JOIN sections s ON ff.section_id = s.id 
    JOIN courses c ON s.course_id = c.id 
    JOIN teachers t ON s.teacher_id = t.id
";
$formParams = [];
$formTypes = '';

if ($teacherId) {
    $formSql .= " WHERE t.id = ?";
    $formParams[] = $teacherId;
    $formTypes .= 'i';
}

$formSql .= " ORDER BY s.semester DESC, c.course_name ASC";

if ($formParams) {
    $fStmt = $conn->prepare($formSql);
    $fStmt->bind_param($formTypes, ...$formParams);
    $fStmt->execute();
    $formList = $fStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $fStmt->close();
} else {
    $formList = $conn->query($formSql)->fetch_all(MYSQLI_ASSOC);
}

$form = null;
$questions = [];
$ratingStats = [];
$allComments = [];

if ($formId) {
    // ၂။ ရွေးချယ်ထားသော Form metadata ကို တစ်ကြိမ်တည်း ထုတ်ယူခြင်း
    $r = $conn->prepare("SELECT ff.*, c.course_name, c.course_code, s.section, s.academic_year, s.semester, u.name AS teacher_name FROM feedback_forms ff JOIN sections s ON ff.section_id=s.id JOIN courses c ON s.course_id=c.id JOIN teachers t ON s.teacher_id=t.id JOIN users u ON t.user_id=u.id WHERE ff.id=?");
    $r->bind_param('i', $formId);
    $r->execute();
    $form = $r->get_result()->fetch_assoc();
    $r->close();

    if ($form) {
        // ၃။ မေးခွန်းများကို အစီအစဉ်တကျ ဆွဲထုတ်ခြင်း
        $q = $conn->prepare("SELECT id, question_no, question_text, question_type FROM feedback_questions WHERE feedback_form_id=? ORDER BY question_no ASC");
        $q->bind_param('i', $formId);
        $q->execute();
        $questions = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        // ၄။ Rating Questions များအတွက် တွက်ချက်ခြင်း (Good, Fair, Bad တွဲဖတ်နိုင်ရန် Map လုပ်ပါသည်)
        $statStmt = $conn->prepare("
            SELECT question_id, rating, COUNT(*) as qty 
            FROM feedback_ratings 
            WHERE feedback_form_id = ? 
            GROUP BY question_id, rating
        ");
        $statStmt->bind_param('i', $formId);
        $statStmt->execute();
        $rawStats = $statStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $statStmt->close();

        foreach ($rawStats as $row) {
            $rKey = trim($row['rating']);

            // Database ထဲရှိ မြန်မာ/အင်္ဂလိပ် တန်ဖိုးများကို Standard English UI Key သို့ ချိန်ညှိပါသည်
            if ($rKey === '3' || $rKey === 'ကောင်း' || $rKey === 'Good' || $rKey === 'good') {
                $rKey = 'Good';
            } elseif ($rKey === '2' || $rKey === 'သင့်' || $rKey === 'Normal' || $rKey === 'normal' || $rKey === 'Average' || $rKey === 'Fair' || $rKey === 'fair') {
                $rKey = 'Fair';
            } elseif ($rKey === '1' || $rKey === 'ညံ့' || $rKey === 'Bad' || $rKey === 'bad') {
                $rKey = 'Bad';
            }

            if (!isset($ratingStats[$row['question_id']][$rKey])) {
                $ratingStats[$row['question_id']][$rKey] = 0;
            }
            $ratingStats[$row['question_id']][$rKey] += (int) $row['qty'];
        }

        // ၅။ Comments များကို မေးခွန်းအလိုက် စုစည်းထုတ်ယူခြင်း
        $cStmt = $conn->prepare("
            SELECT question_id, comment_text 
            FROM feedback_comments 
            WHERE feedback_form_id = ? AND comment_text IS NOT NULL AND comment_text != ''
        ");
        $cStmt->bind_param('i', $formId);
        $cStmt->execute();
        $rawComments = $cStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cStmt->close();

        foreach ($rawComments as $row) {
            $allComments[$row['question_id']][] = $row['comment_text'];
        }
    }
}

// Array ခွဲထုတ်ခြင်း
$ratingQuestions = [];
$commentQuestions = [];
foreach ($questions as $q) {
    if ($q['question_type'] === 'rating') {
        $ratingQuestions[] = $q;
    } else {
        $commentQuestions[] = $q;
    }
}

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>
<style>
    @import url('https://cdn.jsdelivr.net/css-myanmar-fonts/v1/pyidaungsu.css');

    .myanmar-font {
        font-family: 'Pyidaungsu', sans-serif;
    }
</style>

<div class="mb-6 myanmar-font">
    <h2 class="text-xl font-bold text-slate-800">Course Ratings & Feedback Matrix</h2>
    <p class="text-sm text-slate-500 mt-0.5">မေးခွန်းအလိုက် စုစုပေါင်းရလဒ် စာရင်းဇယားနှင့် ကျောင်းသားများ၏
        အကြံပြုချက်များ</p>
</div>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6 myanmar-font">
    <div class="flex flex-col sm:flex-row gap-4">

        <div class="flex-1 max-w-xl">
            <label class="block text-xs font-bold text-slate-500 mb-1">Select Feedback Form:</label>
            <select id="formSelect" onchange="buildFormUrl(this.value)"
                class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white font-semibold text-slate-700 shadow-sm focus:border-slate-500">
                <option value="">— Choose a Feedback Form —</option>
                <?php foreach ($formList as $f): ?>
                    <option value="<?= $f['id'] ?>" <?= $formId == $f['id'] ? 'selected' : '' ?>>
                        <?= e($f['semester']) ?> — <?= e($f['course_name']) ?> (Section <?= e($f['section']) ?>)
                    </option>
                <?php endforeach ?>
            </select>
        </div>

        <div class="flex-1 max-w-xs">
            <label class="block text-xs font-bold text-slate-500 mb-1">Filter by Teacher:</label>
            <select
                onchange="location.href='?teacher_id='+this.value+(this.value && document.getElementById('formSelect').value ? '&form_id='+document.getElementById('formSelect').value : '')"
                class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white font-semibold text-slate-700 shadow-sm focus:border-slate-500">
                <option value="">— All Teachers —</option>
                <?php foreach ($teacherList as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= $teacherId == $t['id'] ? 'selected' : '' ?>>
                        <?= e($t['teacher_name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>
    </div>
</div>
<script>
    function buildFormUrl(formVal) {
        var params = [];
        var tid = '<?= $teacherId ?>';
        if (tid) params.push('teacher_id=' + tid);
        if (formVal) params.push('form_id=' + formVal);
        location.href = '?' + params.join('&');
    }
</script>

<?php if ($form): ?>
    <div class="w-full max-w-5xl mx-auto myanmar-font">
        <div class="bg-white shadow-md rounded-xl border border-slate-200 p-6 md:p-8">

            <div class="text-center border-b-2 border-slate-800 pb-4 mb-5">
                <h2 class="text-lg md:text-xl font-bold text-slate-900 mb-1"><?= e($form['title']) ?></h2>
                <p class="text-xs text-slate-400 font-mono">Statistical Evaluation Report Matrix (Anonymous)</p>
                <p class="text-xs text-slate-400 mt-1">Feedback Period: <?= formatDate($form['start_date']) ?> — <?= formatDate($form['end_date']) ?></p>
            </div>

            <div
                class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 bg-slate-50 p-4 rounded-xl border border-slate-200 text-xs">
                <div>
                    <span class="font-bold text-slate-500">သင်တန်းအမည်:</span>
                    <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800">
                        <?= e($form['semester']) ?>
                    </div>
                </div>
                <div>
                    <span class="font-bold text-slate-500">ဘာသာရပ် (Subject Code / Course Name):</span>
                    <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800 font-mono">
                        <?= e($form['course_code']) ?> (<?= e($form['course_name']) ?>)
                    </div>
                </div>
                <div>
                    <span class="font-bold text-slate-500">ဆရာ/မအမည် (Teacher Name):</span>
                    <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800">
                        <?= e($form['teacher_name']) ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($ratingQuestions)): ?>
                <div class="mb-8">
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">၁။ မေးခွန်းအလိုက် စာရင်းဇယား
                        ရလဒ်များ</h3>
                    <div class="overflow-x-auto border border-slate-300 rounded-lg">
                        <table class="w-full text-left border-collapse min-w-[650px] text-xs">
                            <thead>
                                <tr class="bg-slate-800 text-white font-bold">
                                    <th class="p-3 w-12 text-center">QID</th>
                                    <th class="p-3">အကဲဖြတ်စစ်ဆေးချက်မေးခွန်းများ</th>
                                    <th class="p-3 w-28 text-center bg-emerald-700">Good (COUNT / %)</th>
                                    <th class="p-3 w-28 text-center bg-amber-600">Fair (COUNT / %)</th>
                                    <th class="p-3 w-28 text-center bg-red-700">Bad (COUNT / %)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-200 text-slate-800 font-medium">
                                <?php foreach ($ratingQuestions as $q):
                                    $goodCount = $ratingStats[$q['id']]['Good'] ?? 0;
                                    $normalCount = $ratingStats[$q['id']]['Fair'] ?? 0;
                                    $badCount = $ratingStats[$q['id']]['Bad'] ?? 0;
                                    $totalVotes = $goodCount + $normalCount + $badCount;

                                    $goodPerc = $totalVotes > 0 ? round(($goodCount / $totalVotes) * 100) : 0;
                                    $normalPerc = $totalVotes > 0 ? round(($normalCount / $totalVotes) * 100) : 0;
                                    $badPerc = $totalVotes > 0 ? round(($badCount / $totalVotes) * 100) : 0;
                                    ?>
                                    <tr class="hover:bg-slate-50/60 transition-colors">
                                        <td class="p-3 text-center font-bold font-mono border-r"><?= e($q['question_no']) ?></td>
                                        <td class="p-3 border-r leading-relaxed"><?= e($q['question_text']) ?></td>

                                        <td class="p-3 text-center border-r bg-emerald-50/30">
                                            <span class="text-emerald-700 font-bold block text-sm"><?= $goodCount ?> ယောက်</span>
                                            <span class="text-[10px] text-slate-500">(<?= $goodPerc ?>%)</span>
                                        </td>
                                        <td class="p-3 text-center border-r bg-amber-50/30">
                                            <span class="text-amber-700 font-bold block text-sm"><?= $normalCount ?> ယောက်</span>
                                            <span class="text-[10px] text-slate-500">(<?= $normalPerc ?>%)</span>
                                        </td>
                                        <td class="p-3 text-center bg-red-50/30">
                                            <span class="text-red-700 font-bold block text-sm"><?= $badCount ?> ယောက်</span>
                                            <span class="text-[10px] text-slate-500">(<?= $badPerc ?>%)</span>
                                        </td>
                                    </tr>
                                <?php endforeach ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif ?>

            <?php if (!empty($commentQuestions)): ?>
                <div class="space-y-6 pt-6 border-t-2 border-slate-300">
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">၂။ ရေးသားပေးပို့ထားသော အကြံပြုချက်များ
                        (Comments Box)</h3>
                    <?php foreach ($commentQuestions as $q):
                        $commentsForThisQuestion = $allComments[$q['id']] ?? [];
                        ?>
                        <div class="space-y-2 text-xs">
                            <label class="block font-bold text-slate-700 text-sm">
                                <?= e($q['question_no']) ?>။ <?= e($q['question_text']) ?>
                                <span class="text-slate-400 font-normal text-xs">(စုစုပေါင်းမှတ်ချက် -
                                    <?= count($commentsForThisQuestion) ?> ခု)</span>
                            </label>
                            <div
                                class="w-full bg-slate-50 border border-slate-200 p-4 rounded-xl space-y-2.5 max-h-[300px] overflow-y-auto">
                                <?php if (!empty($commentsForThisQuestion)): ?>
                                    <?php foreach ($commentsForThisQuestion as $index => $commentText): ?>
                                        <div class="bg-white border border-slate-100 p-3 rounded-lg shadow-2xs flex items-start gap-2">
                                            <span class="text-slate-400 font-bold">#<?= $index + 1 ?></span>
                                            <div class="text-slate-800 font-medium whitespace-pre-wrap"><?= e($commentText) ?></div>
                                        </div>
                                    <?php endforeach ?>
                                <?php else: ?>
                                    <div class="text-slate-400 italic text-center py-4">— (ဤမေးခွန်းအတွက် မှတ်ချက်ရေးသားထားခြင်းမရှိပါ)
                                        —</div>
                                <?php endif ?>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <div class="mt-8 pt-4 border-t border-slate-100 text-right text-[11px] text-slate-400 font-semibold italic">
                "ဤအစီရင်ခံစာသည် ကျောင်းသားများ၏ ကိုယ်ရေးအချက်အလက်ကို ထိန်းသိမ်းထားသော အလိုအလျောက် စာရင်းအင်းစနစ်ဖြစ်ပါသည်။"
            </div>
        </div>
    </div>
<?php endif ?>

<?php include '../includes/admin_footer.php'; ?>