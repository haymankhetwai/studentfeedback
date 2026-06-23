<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = 'Student Affairs Feedback Results Matrix';
$activeMenu = 'sa_results';

// Filter Inputs
$formId = (int) ($_GET['form_id'] ?? 0);

// ၁။ Dropdown အတွက် စာရင်းဆွဲထုတ်ခြင်း
$allForms = $conn->query("SELECT id, title, status FROM sa_feedback_forms ORDER BY id DESC")->fetch_all(MYSQLI_ASSOC);

// Form ID တိုက်ရိုက်မပါလာပါက နောက်ဆုံးဖောင်ကို Auto ရွေးပေးထားမည်
if (!$formId && !empty($allForms)) {
    $formId = (int) $allForms[0]['id'];
}

$form = null;
$questions = [];
$ratingStats = [];
$allComments = [];

if ($formId) {
    // ၂။ Form Metadata ဆွဲထုတ်ခြင်း
    $r = $conn->prepare("SELECT * FROM sa_feedback_forms WHERE id = ?");
    $r->bind_param('i', $formId);
    $r->execute();
    $form = $r->get_result()->fetch_assoc();
    $r->close();

    if ($form) {
        // ၃။ မေးခွန်းများကို အစီအစဉ်တကျ ဆွဲထုတ်ခြင်း
        $q = $conn->prepare("SELECT id, question_no, question_text, question_type FROM sa_feedback_questions WHERE form_id=? ORDER BY question_no ASC");
        $q->bind_param('i', $formId);
        $q->execute();
        $questions = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        // ၄။ Rating Questions များအတွက် တွက်ချက်ခြင်း (Good, Fair, Bad အခြေခံ၍ တွက်ချက်ပါသည်)
        $statStmt = $conn->prepare("
            SELECT question_id, rating, COUNT(*) as qty 
            FROM sa_feedback_ratings 
            WHERE form_id = ? 
            GROUP BY question_id, rating
        ");
        $statStmt->bind_param('i', $formId);
        $statStmt->execute();
        $rawStats = $statStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $statStmt->close();

        foreach ($rawStats as $row) {
            $rKey = trim($row['rating']);
            
            // Database ထဲရှိ 3, 2, 1 ဂဏန်းများနှင့် မြန်မာစာသားများကိုပါ အလိုအလျောက် သန့်စင်ပေးပါသည်
            if ($rKey == '3' || $rKey === 'ကောင်း' || $rKey === 'Good' || $rKey === 'good') {
                $rKey = 'Good';
            } elseif ($rKey == '2' || $rKey === 'သင့်' || $rKey === 'Normal' || $rKey === 'normal' || $rKey === 'Average' || $rKey === 'Fair' || $rKey === 'fair') {
                $rKey = 'Fair';
            } elseif ($rKey == '1' || $rKey === 'ညံ့' || $rKey === 'Bad' || $rKey === 'bad') {
                $rKey = 'Bad';
            }

            if (!isset($ratingStats[$row['question_id']][$rKey])) {
                $ratingStats[$row['question_id']][$rKey] = 0;
            }
            $ratingStats[$row['question_id']][$rKey] += (int) $row['qty'];
        }

        // ၅။ Comments များကို မေးခွန်းအလိုက် Anonymous အဖြစ် စုစည်းထုတ်ယူခြင်း
        $cStmt = $conn->prepare("
            SELECT question_id, comment_text 
            FROM sa_feedback_comments 
            WHERE form_id = ? AND comment_text IS NOT NULL AND comment_text != ''
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

// မေးခွန်းခွဲထုတ်ခြင်း
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
    .myanmar-font { font-family: 'Pyidaungsu', sans-serif; }
</style>

<div class="mb-6 myanmar-font">
    <h2 class="text-xl font-bold text-slate-800">Student Affairs Feedback Matrix</h2>
    <p class="text-sm text-slate-500 mt-0.5">ကျောင်းသားရေးရာဌာန စစ်တမ်းများ၏ မေးခွန်းအလိုက် စုစုပေါင်းစာရင်းဇယားရလဒ်များ</p>
</div>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 mb-6 myanmar-font">
    <div class="flex-1 max-w-2xl">
        <label class="block text-xs font-bold text-slate-500 mb-1">Select Student Affairs Form (စစ်တမ်းပုံစံ ရွေးချယ်ရန်):</label>
        <select onchange="location.href='?form_id='+this.value"
            class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none bg-white font-semibold text-slate-700 shadow-sm focus:border-slate-500">
            <option value="">— Choose a Form —</option>
            <?php foreach ($allForms as $f): ?>
                <option value="<?= $f['id'] ?>" <?= $formId == $f['id'] ? 'selected' : '' ?>>
                    <?= e($f['title']) ?> — [<?= e($f['status']) ?>]
                </option>
            <?php endforeach ?>
        </select>
    </div>
</div>

<?php if ($form): ?>
    <div class="w-full max-w-5xl mx-auto myanmar-font">
        <div class="bg-white shadow-lg rounded-xl border border-slate-200 p-6 md:p-8">

            <div class="text-center border-b-2 border-slate-800 pb-4 mb-5">
                <h2 class="text-lg md:text-xl font-bold text-slate-900 mb-1">ကျောင်းသားရေးရာဌာန သုံးသပ်ချက် ရလဒ်များ</h2>
                <p class="text-xs text-purple-600 font-mono">Student Affairs Office — Statistical Evaluation Matrix (Anonymous)</p>
            </div>

            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 text-xs mb-6 grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <span class="font-bold text-slate-500 block">စစ်တမ်းခေါင်းစဉ် (Form Title):</span>
                    <div class="font-bold text-slate-800 text-sm mt-0.5"><?= e($form['title']) ?></div>
                </div>
                <div class="text-right md:text-left">
                    <span class="font-bold text-slate-500 block">သက်တမ်းကာလ (Date Range):</span>
                    <div class="font-medium text-slate-700 mt-0.5 font-mono"><?= formatDate($form['start_date']) ?> - <?= formatDate($form['end_date']) ?></div>
                </div>
            </div>

            <?php if (!empty($ratingQuestions)): ?>
                <div class="mb-8">
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">၁။ လုပ်ဆောင်ချက်များအလိုက် စုစုပေါင်းအကဲဖြတ်မှု စာရင်းဇယား</h3>
                    <div class="overflow-x-auto border border-slate-300 rounded-lg">
                        <table class="w-full text-left border-collapse min-w-[600px] text-xs">
                            <thead>
                                <tr class="bg-slate-800 text-white font-bold">
                                    <th class="p-3 w-12 text-center">စဉ်</th>
                                    <th class="p-3">လုပ်ဆောင်ချက်များ</th>
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
                    <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">၂။ အကြံပြုချက်များနှင့် မှတ်ချက်များစုစည်းမှု (Comments Box)</h3>
                    <?php foreach ($commentQuestions as $q):
                        $commentsForQuestion = $allComments[$q['id']] ?? [];
                        ?>
                        <div class="space-y-2 text-xs">
                            <label class="block font-bold text-slate-700 text-sm">
                                <?= e($q['question_no']) ?>။ <?= e($q['question_text']) ?>
                                <span class="text-slate-400 font-normal text-xs">(စုစုပေါင်းမှတ်ချက် - <?= count($commentsForQuestion) ?> ခု)</span>
                            </label>
                            <div class="w-full bg-slate-50 border border-slate-200 p-4 rounded-xl space-y-2.5 max-h-[300px] overflow-y-auto">
                                <?php if (!empty($commentsForQuestion)): ?>
                                    <?php foreach ($commentsForQuestion as $index => $commentText): ?>
                                        <div class="bg-white border border-slate-100 p-3 rounded-lg shadow-2xs flex items-start gap-2">
                                            <span class="text-slate-400 font-bold">#<?= $index + 1 ?></span>
                                            <div class="text-slate-800 font-medium whitespace-pre-wrap"><?= e($commentText) ?></div>
                                        </div>
                                    <?php endforeach ?>
                                <?php else: ?>
                                    <div class="text-slate-400 italic text-center py-4">— (ဤမေးခွန်းအတွက် ကျောင်းသားများထံမှ မှတ်ချက်မရှိပါ) —</div>
                                <?php endif ?>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
            <?php endif ?>

            <div class="mt-8 pt-4 border-t border-slate-100 text-right text-[11px] text-slate-400 font-semibold italic">
                "ဤအစီရင်ခံစာသည် စနစ်အတွင်းရှိ ကျောင်းသားများ၏ ပေးပို့ချက်အားလုံးကို စုစည်းတွက်ချက်ထားသော စာရင်းအင်းမူရင်းမှတ်တမ်း ဖြစ်ပါသည်။"
            </div>
        </div>
    </div>
<?php endif ?>

<?php include '../includes/admin_footer.php'; ?>