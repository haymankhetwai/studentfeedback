<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('student');

$user      = getCurrentUser();
$stmt      = $conn->prepare("SELECT id FROM students WHERE user_id=?");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$student   = $stmt->get_result()->fetch_assoc(); $stmt->close();
$studentId = $student['id'] ?? 0;

if (!$studentId) {
    setFlash('error', 'Student profile not found. Please contact the administrator.');
    header('Location: /studentfeedback/student/index.php'); exit;
}

$formId = (int)($_GET['form_id'] ?? 0);
if (!$formId) { header('Location: my_sections.php'); exit; }

// ─── Step 1: Check enrollment only
$chk = $conn->prepare(
    "SELECT ff.*, c.course_name, c.course_code, s.section, s.academic_year, s.semester,
            u.name AS teacher_name
     FROM feedback_forms ff
     JOIN sections s           ON ff.section_id = s.id
     JOIN courses c            ON s.course_id   = c.id
     JOIN teachers t           ON s.teacher_id  = t.id
     JOIN users u              ON t.user_id     = u.id
     JOIN section_assignments sa ON sa.section_id = s.id
     WHERE ff.id = ? AND sa.student_id = ?
     LIMIT 1"
);
$chk->bind_param('ii', $formId, $studentId);
$chk->execute();
$form = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$form) {
    setFlash('error', 'You are not enrolled in the section for this feedback form.');
    header('Location: my_sections.php'); exit;
}

// ─── Step 2: Check if already submitted
$sub = $conn->prepare("SELECT id FROM feedback_submissions WHERE form_id=? AND student_id=?");
$sub->bind_param('ii', $formId, $studentId); $sub->execute();
$alreadySubmitted = (bool)$sub->get_result()->num_rows;
$sub->close();

// ─── Step 3: Determine form open status
$today     = date('Y-m-d');
$isActive  = ($form['status'] === 'active');
$inDateRange = ($form['start_date'] <= $today && $form['end_date'] >= $today);
$canSubmit = $isActive && $inDateRange && !$alreadySubmitted;

$statusNote = '';
if ($alreadySubmitted) { $statusNote = 'already_submitted'; } 
elseif (!$isActive)    { $statusNote = 'inactive'; } 
elseif ($form['start_date'] > $today) { $statusNote = 'not_started'; } 
elseif ($form['end_date'] < $today)   { $statusNote = 'expired'; }

// ─── Step 4: Load Questions (per-form)
$qStmt = $conn->prepare("SELECT * FROM feedback_questions WHERE module=? ORDER BY question_no ASC");
$qStmt->bind_param('s', $form['module']); $qStmt->execute();
$allQuestions = $qStmt->get_result()->fetch_all(MYSQLI_ASSOC); $qStmt->close();

$ratingQuestions = [];
$commentQuestions = [];
foreach ($allQuestions as $q) {
    if ($q['question_type'] === 'rating') {
        $ratingQuestions[] = $q;
    } else {
        $commentQuestions[] = $q;
    }
}

// ─── Step 5: Handle POST submission
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
            setFlash('error', 'ကျေးဇူးပြု၍ မေးခွန်းအားလုံးကို အကဲဖြတ်ပေးပါ။');
        } else {
            $conn->begin_transaction();
            try {
                $recheck = $conn->prepare("SELECT id FROM feedback_submissions WHERE form_id=? AND student_id=? FOR UPDATE");
                $recheck->bind_param('ii', $formId, $studentId); $recheck->execute();
                if ($recheck->get_result()->num_rows > 0) {
                    $recheck->close(); $conn->rollback();
                    setFlash('error', 'You have already submitted this form.');
                    header('Location: my_sections.php'); exit;
                }
                $recheck->close();

                $ins = $conn->prepare("INSERT INTO feedback_submissions (form_id, student_id) VALUES (?,?)");
                $ins->bind_param('ii', $formId, $studentId); $ins->execute();
                if ($ins->affected_rows === 0) {
                    $ins->close(); $conn->rollback();
                    setFlash('error', 'You have already submitted this form.');
                    header('Location: my_sections.php'); exit;
                }
                $ins->close();

                // Save dynamic ratings
                foreach ($ratingQuestions as $q) {
                    $rating = $_POST['rating_' . $q['id']] ?? '';
                    if ($rating) {
                        $ri = $conn->prepare("INSERT INTO feedback_ratings (form_id, question_id, rating) VALUES (?,?,?)");
                        $ri->bind_param('iis', $formId, $q['id'], $rating);
                        $ri->execute(); $ri->close();
                    }
                }

                // Save dynamic comments
                foreach ($commentQuestions as $q) {
                    $comment = trim($_POST['comment_' . $q['id']] ?? '');
                    if ($comment !== '') {
                        $ci = $conn->prepare("INSERT INTO feedback_comments (form_id, question_id, comment_text) VALUES (?,?,?)");
                        $ci->bind_param('iis', $formId, $q['id'], $comment);
                        $ci->execute(); $ci->close();
                    }
                }

                $conn->commit();
                
                // Submit လုပ်ပြီးမှသာ Flash သတ်မှတ်ပြီး my_sections.php (စာရင်းစာမျက်နှာ) ဆီသို့ ခေါ်သွားပါမည်
                setFlash('success', '🎉 ပါဝင်ဖြေဆိုမှုအတွက် ကျေးဇူးတင်ပါသည်။');
                header('Location: my_sections.php'); 
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                setFlash('error', 'Submission failed: ' . $e->getMessage());
            }
        }
    }
}

$initials = avatarInitials($user['name']);
?>
<!DOCTYPE html>
<html lang="my" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ဘာသာရပ်အလိုက် အကဲဖြတ်မှုစစ်တမ်းပုံစံ — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: { sans: ['Pyidaungsu', 'Inter', 'sans-serif'] } } }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        @import url('https://cdn.jsdelivr.net/css-myanmar-fonts/v1/pyidaungsu.css');
        body { font-family: 'Pyidaungsu', 'Inter', sans-serif; }
    </style>
</head>
<body class="h-full bg-slate-100">
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
            <a href="/studentfeedback/student/my_sections.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm bg-white/20 text-white font-semibold"><?= iconSvg('grid','w-4 h-4 flex-shrink-0') ?> My Sections</a>
            <a href="/studentfeedback/student/sa_feedback.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('shield','w-4 h-4 flex-shrink-0') ?> Student Affairs</a>
            <a href="/studentfeedback/student/adm_feedback.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('office','w-4 h-4 flex-shrink-0') ?> Administration</a>
            <a href="/studentfeedback/student/feedback_history.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('history','w-4 h-4 flex-shrink-0') ?> History</a>
            <a href="/studentfeedback/student/profile.php" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm text-cyan-100 hover:bg-white/10 hover:text-white"><?= iconSvg('user','w-4 h-4 flex-shrink-0') ?> Profile</a>
        </nav>
       

        <div class="border-t border-cyan-500 px-4 py-4">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold">
                        <?= e($initials) ?></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-white truncate"><?= e($user['name']) ?></p>
                        <p class="text-[10px] text-cyan-100 truncate">Student
                        </p>
                    </div>
                    <a href="/studentfeedback/auth/logout.php" class="text-cyan-100 hover:text-red-300">
                        <?= iconSvg('logout', 'w-4 h-4') ?>
                    </a>
                </div>
            </div>
    </aside>

    <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
        <header class="bg-white border-b border-slate-200 px-6 py-4 flex items-center justify-between shadow-sm z-20">
            <button onclick="openSidebar()" class="lg:hidden text-slate-600 p-1">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="text-lg font-bold text-slate-800">အကဲဖြတ်မှုစစ်တမ်းပုံစံ (Evaluation Form)</h1>
            <a href="my_sections.php" class="text-sm font-medium text-emerald-600 hover:underline">← Back</a>
        </header>

        <main class="flex-1 overflow-y-auto p-4 md:p-8 max-w-5xl mx-auto w-full">
            <?php renderFlash() ?>

            <div class="bg-white shadow-xl rounded-xl border border-slate-200 p-6 md:p-10 mb-8">
                <div class="text-center border-b-2 border-slate-800 pb-6 mb-6">
                    <h2 class="text-xl md:text-2xl font-bold text-slate-900 mb-2"><?= e($form['title'] ?? 'ဘာသာရပ်အလိုက် အကဲဖြတ်မှုစစ်တမ်းပုံစံ') ?></h2>
                    <p class="text-sm text-slate-500 font-mono">Student Course Feedback Questionnaire</p>
                    <p class="text-xs text-slate-400 mt-1">Feedback Period: <?= formatDate($form['start_date']) ?> — <?= formatDate($form['end_date']) ?></p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8 bg-slate-50 p-4 rounded-xl border border-slate-200 text-sm">
                    <div>
                        <span class="font-semibold text-slate-500">သင်တန်းအမည် </span>
                        <div class="border-b border-dashed border-slate-400 py-1 font-semibold text-slate-900"><?= e($form['semester']) ?></div>
                    </div>
                    <div>
                        <span class="font-semibold text-slate-500">ဘာသာရပ် (Subject Code/Course Name):</span>
                        <div class="border-b border-dashed border-slate-400 py-1 font-semibold text-slate-900 font-mono"><?= e($form['course_code']) ?> (<?= e($form['course_name']) ?>)</div>
                    </div>
                    <div>
                        <span class="font-semibold text-slate-500">ဆရာ/မအမည် (Teacher Name):</span>
                        <div class="border-b border-dashed border-slate-400 py-1 font-semibold text-slate-900"><?= e($form['teacher_name']) ?></div>
                    </div>
                </div>

                <?php if ($alreadySubmitted): ?>
                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-800 p-4 rounded-xl mb-6 text-sm font-semibold">
                        ✓ ဤဘာသာရပ်အတွက် စစ်တမ်းပုံစံ ဖြည့်စွက်ပြီးဖြစ်ပါသည်။ ကျေးဇူးတင်ပါသည်။
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
                        <h3 class="text-base font-bold text-slate-900 mb-3 bg-cyan-600 text-white px-4 py-2 rounded-lg rounded-t-lg">
                            ဘာသာရပ် တစ်ခုလုံးအပေါ် သုံးသပ်ချက်မေးခွန်းများ (Overall Matrix Table)
                        </h3>
                        <div class="overflow-x-auto border border-slate-300 rounded-b-lg">
                            <table class="w-full text-left border-collapse min-w-[600px]">
                                <thead>
                                    <tr class="bg-slate-100 border-b border-slate-300 text-slate-800 font-bold text-sm">
                                        <th class="p-3 border-r border-slate-300 w-12 text-center">စဉ်</th>
                                        <th class="p-3 border-r border-slate-300">မေးခွန်းများ</th>
                                        <th class="p-3 border-r border-slate-300 w-24 text-center bg-emerald-50 text-emerald-900">Good</th>
                                        <th class="p-3 border-r border-slate-300 w-24 text-center bg-amber-50 text-amber-900">Fair</th>
                                        <th class="p-3 w-24 text-center bg-red-50 text-red-900">Bad</th>
                                    </tr>
                                </thead>
                                <tbody class="text-sm divide-y divide-slate-200 text-slate-800">
                                    <?php foreach ($ratingQuestions as $q): ?>
                                    <tr class="hover:bg-slate-50 transition-colors">
                                        <td class="p-3 border-r border-slate-200 text-center font-semibold font-mono"><?= e($q['question_no']) ?></td>
                                        <td class="p-3 border-r border-slate-200 font-medium"><?= e($q['question_text']) ?></td>
                                        
                                        <td class="p-3 border-r border-slate-200 text-center bg-emerald-50/40">
                                            <input type="radio" name="rating_<?= $q['id'] ?>" value="Good" required 
                                                   <?= !$canSubmit ? 'disabled' : '' ?>
                                                   class="w-4 h-4 text-emerald-600 focus:ring-emerald-500 cursor-pointer">
                                        </td>
                                        <td class="p-3 border-r border-slate-200 text-center bg-amber-50/40">
                                            <input type="radio" name="rating_<?= $q['id'] ?>" value="Fair" required 
                                                   <?= !$canSubmit ? 'disabled' : '' ?>
                                                   class="w-4 h-4 text-amber-500 focus:ring-amber-500 cursor-pointer">
                                        </td>
                                        <td class="p-3 text-center bg-red-50/40">
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
                    <div class="space-y-6 pt-4 border-t-2 border-slate-300">
                        <h3 class="text-base font-bold text-slate-900 mb-2">အကြံပြုချက်များ (Comments & Suggestions)</h3>
                        
                        <?php foreach ($commentQuestions as $q): ?>
                        <div class="space-y-2">
                            <label class="block text-sm font-bold text-slate-800">
                                <?= e($q['question_no']) ?>။ <?= e($q['question_text']) ?>
                            </label>
                            <textarea name="comment_<?= $q['id'] ?>" rows="4" required
                                      placeholder="ဤနေရာတွင် စာရေးသားထည့်သွင်းပါ (စိတ်ကြိုက်)..."
                                      <?= !$canSubmit ? 'disabled' : '' ?>
                                      class="w-full border-2 border-slate-300 rounded-xl px-4 py-3 text-sm bg-slate-50 focus:bg-white focus:border-slate-800 outline-none resize-none disabled:opacity-60 transition-all"></textarea>
                        </div>
                        <?php endforeach ?>
                    </div>
                    <?php endif ?>

                    <div class="pt-8 border-t border-slate-200 flex flex-col md:flex-row items-center justify-between gap-6">
                        <div class="text-sm font-semibold text-slate-700 italic text-center md:text-left">
                            "ဤစစ်တမ်းမေးခွန်းများပေါ်တွင် ပါဝင်ဆောင်ရွက်မှုအတွက် ကျေးဇူးတင်ပါသည်။"
                        </div>
                        
                        <div class="flex items-center gap-4 w-full md:w-auto justify-end">
                            <a href="my_sections.php" class="px-5 py-2.5 text-sm border border-slate-300 rounded-xl text-slate-600 hover:bg-slate-50 font-medium">Cancel</a>
                            
                            <?php if ($canSubmit): ?>
                            <button type="submit" id="submit-btn"
                                    class="w-full md:w-auto px-8 py-2.5 text-sm font-bold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl shadow-md transition-all">
                                Submit Form (ပေးပို့မည်)
                            </button>
                            <?php elseif ($form['start_date'] > $today): ?>
                            <button type="button" disabled class="w-full md:w-auto px-8 py-2.5 text-sm font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed">
                                Not Yet Available
                            </button>
                            <?php elseif ($form['end_date'] < $today): ?>
                            <button type="button" disabled class="w-full md:w-auto px-8 py-2.5 text-sm font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed">
                                Form Closed
                            </button>
                            <?php else: ?>
                            <button type="button" disabled class="w-full md:w-auto px-8 py-2.5 text-sm font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed">
                                Submissions Locked
                            </button>
                            <?php endif ?>
                        </div>
                    </div>
                </form>
                <?php else: ?>
                <div class="text-center py-12 text-slate-500">
                    <p>ဤပုံစံတွင် မေးခွန်းများ ထည့်သွင်းထားခြင်း မရှိသေးပါ။</p>
                </div>
                <?php endif ?>
            </div>
        </main>
    </div>
</div>

<script>
function openSidebar()  { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full');    document.getElementById('overlay').classList.add('hidden'); }

<?php if ($canSubmit): ?>
const form = document.getElementById('feedback-form');
const btn  = document.getElementById('submit-btn');
if (form && btn) {
    form.addEventListener('submit', function () {
        btn.disabled = true;
        btn.className = "w-full md:w-auto px-8 py-2.5 text-sm font-bold text-slate-400 bg-slate-200 rounded-xl cursor-not-allowed";
        btn.innerHTML = 'ခေတ္တစောင့်ဆိုင်းပါ...';
    });
}
<?php endif ?>
</script>
</body>
</html>