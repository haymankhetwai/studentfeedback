<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('student');

$user = getCurrentUser();
$stmt = $conn->prepare("SELECT st.id FROM students st WHERE st.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();
$studentId = $student['id'] ?? 0;

if (!$studentId) {
    setFlash('error', 'Student profile not found.');
    header('Location: index.php');
    exit;
}

$formId = (int) ($_GET['form_id'] ?? 0);

// ─── Auto-fallback with Semester/Active check ────────────────
if (!$formId) {
    $activeFormQuery = $conn->query("
        SELECT id FROM feedback_forms 
        WHERE status = 'active' AND module = 'administration'
        ORDER BY id DESC LIMIT 1
    ");
    $activeFormRes = $activeFormQuery->fetch_assoc();
    $formId = $activeFormRes['id'] ?? 0;
}

if (!$formId) {
    setFlash('error', 'လက်ရှိအချိန်တွင် ဖြည့်စွက်ရန် Administration Form မရှိသေးပါ။');
    header('Location: adm_feedback.php');
    exit;
}

// ─── Load form ──────────────────────────────────────────────
$fs = $conn->prepare("SELECT * FROM feedback_forms WHERE id=? AND module='administration'");
$fs->bind_param('i', $formId);
$fs->execute();
$form = $fs->get_result()->fetch_assoc();
$fs->close();
if (!$form) {
    setFlash('error', 'Administration form not found.');
    header('Location: adm_feedback.php');
    exit;
}

// ─── Check already submitted ──────────────────────────────────
$sub = $conn->prepare("SELECT id FROM feedback_submissions WHERE form_id=? AND student_id=?");
$sub->bind_param('ii', $formId, $studentId);
$sub->execute();
$alreadySubmitted = (bool) $sub->get_result()->num_rows;
$sub->close();

$today = date('Y-m-d');
$isActive = ($form['status'] === 'active');
$inRange = ($form['start_date'] <= $today && $form['end_date'] >= $today);
$canSubmit = $isActive && $inRange && !$alreadySubmitted;

$statusNote = '';
if ($alreadySubmitted)
    $statusNote = 'already_submitted';
elseif (!$isActive)
    $statusNote = 'inactive';
elseif ($form['start_date'] > $today)
    $statusNote = 'not_started';
elseif ($form['end_date'] < $today)
    $statusNote = 'expired';

// ─── Load Questions (per-form)
$qStmt = $conn->prepare("SELECT id, question_no, question_text, question_type FROM feedback_questions WHERE module=? ORDER BY question_no ASC");
$module = 'administration'; $qStmt->bind_param('s', $module); $qStmt->execute();
$allQuestions = $qStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$qStmt->close();

$ratingQuestions = [];
$commentQuestions = [];
foreach ($allQuestions as $q) {
    if ($q['question_type'] === 'rating') {
        $ratingQuestions[] = $q;
    } else {
        $commentQuestions[] = $q;
    }
}

// ─── Handle POST Submission ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    if (!$canSubmit) {
        setFlash('error', 'This form is not open for submission.');
    } else {
        $hasError = false;
        foreach ($ratingQuestions as $q) {
            $rating = $_POST['rating_' . $q['id']] ?? '';
            if (!in_array($rating, ['Good', 'Fair', 'Bad'])) {
                $hasError = true;
                break;
            }
        }
        if (!$hasError) {
            foreach ($commentQuestions as $q) {
                $comment = trim($_POST['comment_' . $q['id']] ?? '');
                if ($comment === '') {
                    $hasError = true;
                    break;
                }
            }
        }

        if ($hasError) {
            setFlash('error', 'ကျေးဇူးပြု၍ အကဲဖြတ်မေးခွန်းများအားလုံးနှင့် မှတ်ချက်များအားလုံးကို ဖြည့်စွက်ပေးပါ။');
        } else {
            $conn->begin_transaction();
            try {
                $recheck = $conn->prepare("SELECT id FROM feedback_submissions WHERE form_id=? AND student_id=? FOR UPDATE");
                $recheck->bind_param('ii', $formId, $studentId); $recheck->execute();
                if ($recheck->get_result()->num_rows > 0) {
                    $recheck->close(); $conn->rollback();
                    setFlash('error', 'You have already submitted this form.');
                    header('Location: adm_feedback.php'); exit;
                }
                $recheck->close();

                $ins = $conn->prepare("INSERT INTO feedback_submissions (form_id, student_id) VALUES (?,?)");
                $ins->bind_param('ii', $formId, $studentId); $ins->execute();
                if ($ins->affected_rows === 0) {
                    $ins->close(); $conn->rollback();
                    setFlash('error', 'You have already submitted this form.');
                    header('Location: adm_feedback.php'); exit;
                }
                $ins->close();

                foreach ($ratingQuestions as $q) {
                    $rating = $_POST['rating_' . $q['id']] ?? '';
                    if ($rating) {
                        $ri = $conn->prepare("INSERT INTO feedback_ratings (form_id, question_id, rating) VALUES (?,?,?)");
                        $ri->bind_param('iis', $formId, $q['id'], $rating);
                        $ri->execute();
                        $ri->close();
                    }
                }

                foreach ($commentQuestions as $q) {
                    $comment = trim($_POST['comment_' . $q['id']] ?? '');
                    if ($comment !== '') {
                        $ci = $conn->prepare("INSERT INTO feedback_comments (form_id, question_id, comment_text) VALUES (?,?,?)");
                        $ci->bind_param('iis', $formId, $q['id'], $comment);
                        $ci->execute();
                        $ci->close();
                    }
                }

                $conn->commit();
                setFlash('success', '🎉 စီမံခန့်ခွဲမှုဆိုင်ရာ စစ်တမ်းပုံစံ ဖြည့်စွက်အောင်မြင်ပါသည်။');
                header('Location: adm_feedback.php');
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                setFlash('error', 'Submission failed. Please try again.');
            }
        }
    }
}

$pageTitle = 'Administration Feedback';
$initials = avatarInitials($user['name']);
?>
<!DOCTYPE html>
<html lang="my" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config={theme:{extend:{fontFamily:{sans:['Pyidaungsu','Inter','sans-serif']}}}}</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @import url('https://cdn.jsdelivr.net/css-myanmar-fonts/v1/pyidaungsu.css');
        body { font-family: 'Pyidaungsu', 'Inter', sans-serif; }
    </style>
</head>
<body class="h-full bg-slate-50">
<div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>

<div class="flex h-screen overflow-hidden">
    <aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
        <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center font-bold text-white">S</div>
            <div><p class="text-sm font-bold">SFMS Student</p><p class="text-[10px] text-cyan-100">Student Portal</p></div>
            <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-200 hover:text-white">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <nav class="flex-1 py-4 px-3 space-y-0.5">
            <a href="/studentfeedback/student/index.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('home','w-4 h-4 flex-shrink-0') ?> Dashboard</a>
            <a href="/studentfeedback/student/my_sections.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('grid','w-4 h-4 flex-shrink-0') ?> My Sections</a>
            <a href="/studentfeedback/student/sa_feedback.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('shield','w-4 h-4 flex-shrink-0') ?> Student Affairs</a>
            <a href="/studentfeedback/student/adm_feedback.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm bg-white/20 text-white font-semibold"><?= iconSvg('office','w-4 h-4 flex-shrink-0') ?> Administration</a>
            <a href="/studentfeedback/student/feedback_history.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('history','w-4 h-4 flex-shrink-0') ?> History</a>
            <a href="/studentfeedback/student/profile.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('user','w-4 h-4 flex-shrink-0') ?> Profile</a>
        </nav>
        <div class="border-t border-cyan-500 px-4 py-4 flex items-center gap-3">
            <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold text-white"><?= e($initials) ?></div>
            <div class="flex-1 min-w-0">
                <p class="text-xs font-semibold text-white truncate"><?= e($user['name']) ?></p>
                <p class="text-[10px] text-cyan-100 truncate">Student</p>
            </div>
            <a href="/studentfeedback/auth/logout.php" class="text-cyan-200 hover:text-red-300">
                        <?= iconSvg('logout', 'w-4 h-4') ?>
                    </a>
        </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        
        <header class="bg-white border-b border-slate-200 px-6 py-4 flex items-center gap-4 shadow-sm z-20">
            <button onclick="openSidebar()" class="lg:hidden text-slate-500"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg></button>
            <h1 class="text-base font-bold text-slate-800">Administration Satisfaction Form</h1>
            <a href="adm_feedback.php" class="ml-auto text-sm font-medium text-cyan-600 hover:underline">← Back</a>
        </header>

        <main class="flex-1 overflow-y-auto p-4 md:p-8 max-w-4xl mx-auto w-full">
            <?php renderFlash() ?>

            <div class="bg-white shadow-xl rounded-xl border border-slate-200 p-6 md:p-10 mb-8">
                
                <div class="text-center pb-4 mb-6 border-b border-slate-100">
                    <h2 class="text-lg md:text-xl font-bold text-slate-950 mb-1">စီမံခန့်ခွဲမှုဆိုင်ရာသုံးသပ်ချက် (ကျောင်းသား)</h2>
                    <p class="text-xs font-bold text-slate-600 tracking-wider">University Campus</p>
                    <h3 class="text-md font-black text-slate-900 mt-2"><?= e($form['title']) ?></h3>
                    <p class="text-xs text-slate-500 mt-2">Feedback Period: <?= formatDate($form['start_date']) ?> — <?= formatDate($form['end_date']) ?></p>
                </div>

                <?php if ($alreadySubmitted): ?>
                    <div class="bg-green-50 border border-green-200 text-green-800 p-4 rounded-xl mb-6 text-sm font-semibold">
                        ✓ ဤစီမံခန့်ခွဲမှုစစ်တမ်းပုံစံအား ဖြည့်စွက်ပေးပို့ပြီးဖြစ်ပါသည်။ ပါဝင်ဆောင်ရွက်ပေးမှုအတွက် ကျေးဇူးတင်ပါသည်။
                    </div>
                <?php elseif ($form['start_date'] > $today): ?>
                    <div class="bg-blue-50 border border-blue-200 text-blue-800 p-4 rounded-xl mb-6 text-sm font-semibold">
                        ⏳ Feedback form is not available yet. It will open on <strong><?= formatDate($form['start_date']) ?></strong>.
                    </div>
                <?php elseif ($form['end_date'] < $today): ?>
                    <div class="bg-slate-100 border border-slate-300 text-slate-700 p-4 rounded-xl mb-6 text-sm font-semibold">
                        🔒 Feedback form has been closed. It ended on <strong><?= formatDate($form['end_date']) ?></strong>.
                    </div>
                <?php elseif (!$isActive): ?>
                    <div class="bg-amber-50 border border-amber-200 text-amber-800 p-4 rounded-xl mb-6 text-sm font-semibold">
                        ⚠️ This feedback form is currently inactive.
                    </div>
                <?php endif ?>

                <?php if (!empty($allQuestions)): ?>
                    <form method="POST" id="feedback-form" class="space-y-8">
                        <?= csrfField() ?>

                        <?php if (!empty($ratingQuestions)): ?>
                            <div>
                                <div class="overflow-x-auto border border-slate-300 rounded-lg shadow-sm">
                                    <table class="w-full text-left border-collapse min-w-[500px]">
                                        <thead>
                                            <tr class="bg-slate-50 border-b border-slate-300 text-slate-900 font-bold text-xs">
                                                <th class="p-3 border-r border-slate-300 w-12 text-center">စဉ်</th>
                                                <th class="p-3 border-r border-slate-300">လုပ်ဆောင်ချက်များ</th>
                                                <th class="p-3 border-r border-slate-300 w-24 text-center bg-emerald-50 text-emerald-900">Good</th>
                                                <th class="p-3 border-r border-slate-300 w-24 text-center bg-amber-50 text-amber-900">Fair</th>
                                                <th class="p-3 w-24 text-center bg-red-50 text-red-900">Bad</th>
                                            </tr>
                                        </thead>
                                        <tbody class="text-xs divide-y divide-slate-200 text-slate-800">
                                            <?php foreach ($ratingQuestions as $q): ?>
                                                <tr class="hover:bg-slate-50/50 transition-colors">
                                                    <td class="p-3 border-r border-slate-200 text-center font-bold font-mono"><?= e($q['question_no']) ?></td>
                                                    <td class="p-3 border-r border-slate-200 font-medium leading-relaxed text-slate-900"><?= e($q['question_text']) ?></td>
                                                
                                                    <td class="p-3 border-r border-slate-200 text-center bg-emerald-50/30">
                                                        <input type="radio" name="rating_<?= $q['id'] ?>" value="Good" required 
                                                               <?= !$canSubmit ? 'disabled' : '' ?>
                                                               class="w-4 h-4 text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                                                    </td>
                                                    <td class="p-3 border-r border-slate-200 text-center bg-amber-50/30">
                                                        <input type="radio" name="rating_<?= $q['id'] ?>" value="Fair" required 
                                                               <?= !$canSubmit ? 'disabled' : '' ?>
                                                               class="w-4 h-4 text-amber-500 focus:ring-amber-500 cursor-pointer">
                                                    </td>
                                                    <td class="p-3 text-center bg-red-50/30">
                                                        <input type="radio" name="rating_<?= $q['id'] ?>" value="Bad" required 
                                                               <?= !$canSubmit ? 'disabled' : '' ?>
                                                               class="w-4 h-4 text-red-600 focus:ring-red-500 cursor-pointer">
                                                    </td>
                                                </tr>
                                            <?php endforeach ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif ?>

                        <?php if (!empty($commentQuestions)): ?>
                            <div class="space-y-6 pt-4 border-t-2 border-slate-200">
                                <?php foreach ($commentQuestions as $q): ?>
                                    <div class="space-y-2">
                                        <label class="block text-xs font-bold text-slate-800 leading-relaxed">
                                            (<?= e($q['question_no']) ?>)။ <?= e($q['question_text']) ?>
                                        </label>
                                        <textarea name="comment_<?= $q['id'] ?>" rows="4" required
                                                  placeholder="ဤနေရာတွင် စာသားများရေးသားနိုင်ပါသည်..."
                                                  <?= !$canSubmit ? 'disabled' : '' ?>
                                                  class="w-full border border-slate-300 bg-slate-50 rounded-xl px-4 py-3 text-xs focus:bg-white focus:border-slate-900 outline-none resize-none transition-all disabled:opacity-60"></textarea>
                                    </div>
                                <?php endforeach ?>
                            </div>
                        <?php endif ?>

                        <div class="pt-8 border-t border-slate-200 flex items-center justify-end gap-3">
                            <a href="index.php" class="px-5 py-2 text-xs border border-slate-300 rounded-xl text-slate-600 hover:bg-slate-50 font-semibold">Cancel</a>
                        
                            <?php if ($canSubmit): ?>
                                <button type="submit" id="submit-btn"
                                        class="w-full md:w-auto px-8 py-2 text-xs font-bold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl shadow-md transition-all">
                                    Submit Feedback
                                </button>
                            <?php elseif ($form['start_date'] > $today): ?>
                                <button type="button" disabled class="w-full md:w-auto px-8 py-2 text-xs font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed">
                                    Not Yet Available
                                </button>
                            <?php elseif ($form['end_date'] < $today): ?>
                                <button type="button" disabled class="w-full md:w-auto px-8 py-2 text-xs font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed">
                                    Form Closed
                                </button>
                            <?php else: ?>
                                <button type="button" disabled class="w-full md:w-auto px-8 py-2 text-xs font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed">
                                    Locked
                                </button>
                            <?php endif ?>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="text-center py-12 text-slate-400 text-sm">
                        <p>စီမံခန့်ခွဲမှုဆိုင်ရာ မေးခွန်းများ ထည့်သွင်းထားခြင်း မရှိသေးပါ။</p>
                    </div>
                <?php endif ?>
            </div>
        </main>
    </div>
</div>

<script>
function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('overlay').classList.add('hidden'); }
<?php if ($canSubmit): ?>
    const ff = document.getElementById('feedback-form'), btn = document.getElementById('submit-btn');
    if (ff && btn) {
        ff.addEventListener('submit', function() { 
            btn.disabled = true; 
            btn.className = "w-full md:w-auto px-8 py-2 text-xs font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed";
            btn.textContent = 'Submitting...'; 
        });
    }
<?php endif ?>
</script>
</body>
</html>