<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('teacher');

$user = getCurrentUser();
$stmt = $conn->prepare("SELECT t.id FROM teachers t WHERE t.user_id=?");
$stmt->bind_param('i', $user['id']);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();
$teacherId = $teacher['id'] ?? 0;

$pageTitle = 'Feedback Results';
$activeMenu = 'results';

// Parse query strings accurately
$sectionId = (int) ($_GET['section_id'] ?? 0);
$formId = (int) ($_GET['form_id'] ?? 0);
$semesterFilter = clean($_GET['semester'] ?? '');

// My sections list
$mySections = [];
if ($teacherId) {
    $rs = $conn->query("SELECT s.id, c.course_name, c.course_code, s.section, s.academic_year, s.semester FROM sections s JOIN courses c ON s.course_id=c.id WHERE s.teacher_id=$teacherId ORDER BY s.id DESC");
    $mySections = $rs->fetch_all(MYSQLI_ASSOC);
}

// Distinct semesters for filter
$semesters = [];
if ($teacherId) {
    $rs = $conn->query("SELECT DISTINCT s.semester FROM sections s WHERE s.teacher_id=$teacherId AND s.semester IS NOT NULL AND s.semester != '' ORDER BY s.semester");
    while ($r = $rs->fetch_assoc())
        $semesters[] = $r['semester'];
}

// Filter sections by semester configuration
if ($semesterFilter && !empty($mySections)) {
    $mySections = array_filter($mySections, fn($s) => $s['semester'] === $semesterFilter);
}

// Automatically deduce section_id if form_id was passed without a section context
if ($formId && !$sectionId) {
    $secStmt = $conn->prepare("SELECT section_id FROM feedback_forms WHERE id = ?");
    $secStmt->bind_param('i', $formId);
    $secStmt->execute();
    $resSec = $secStmt->get_result()->fetch_assoc();
    if ($resSec) {
        $sectionId = (int)$resSec['section_id'];
    }
    $secStmt->close();
}

// Forms list matching current targeted section context
$sectionForms = [];
if ($sectionId && $teacherId) {
    $chk = $conn->prepare("SELECT id FROM sections WHERE id=? AND teacher_id=?");
    $chk->bind_param('ii', $sectionId, $teacherId);
    $chk->execute();
    if ($chk->get_result()->num_rows) {
        $rf = $conn->prepare("SELECT ff.id, ff.title, ff.status, (SELECT COUNT(*) FROM feedback_submissions fs WHERE fs.feedback_form_id=ff.id) AS submissions FROM feedback_forms ff WHERE ff.section_id=? ORDER BY ff.id DESC");
        $rf->bind_param('i', $sectionId);
        $rf->execute();
        $sectionForms = $rf->get_result()->fetch_all(MYSQLI_ASSOC);
        $rf->close();
    }
    $chk->close();
}

// Fallback auto-selection layer
$autoFormId = 0;
if ($sectionId && !$formId && !empty($sectionForms)) {
    $formId = (int) $sectionForms[0]['id'];
    $autoFormId = $formId;
}

// Core Data fetching block for selected feedback form
$form = null;
$questions = [];
$ratingResults = [];
$comments = [];
$submissionCount = 0;

if ($formId && $teacherId) {
    // FIXED: Ensured soft match criteria so variations in table linkages do not cause zero rows returned
    $rf = $conn->prepare("SELECT ff.*, c.course_name, c.course_code, s.section, s.academic_year FROM feedback_forms ff JOIN sections s ON ff.section_id=s.id JOIN courses c ON s.course_id=c.id WHERE ff.id=?");
    $rf->bind_param('i', $formId);
    $rf->execute();
    $form = $rf->get_result()->fetch_assoc();
    $rf->close();
    
    if ($form) {
        $q = $conn->prepare("SELECT * FROM global_feedback_questions ORDER BY question_no ASC");
        $q->execute();
        $questions = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        $q->close();

        $sc = $conn->prepare("SELECT COUNT(*) AS c FROM feedback_submissions fs JOIN students st ON fs.student_id = st.id WHERE fs.feedback_form_id=?");
        $sc->bind_param('i', $formId);
        $sc->execute();
        $submissionCount = (int) $sc->get_result()->fetch_assoc()['c'];
        $sc->close();

        // Feedback Progress Stats
        $totalStudentsStmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM section_assignments sa JOIN students st ON sa.student_id = st.id WHERE sa.section_id = ?");
        $totalStudentsStmt->bind_param('i', $form['section_id']);
        $totalStudentsStmt->execute();
        $totalStudents = (int) $totalStudentsStmt->get_result()->fetch_assoc()['cnt'];
        $totalStudentsStmt->close();

        $completedCount = $submissionCount;
        $pendingCount = max(0, $totalStudents - $completedCount);

        foreach ($questions as $quest) {
            if ($quest['question_type'] === 'rating') {
                $rs = $conn->prepare("SELECT rating, COUNT(*) AS cnt FROM feedback_ratings WHERE question_id=? AND feedback_form_id=? GROUP BY rating");
                $rs->bind_param('ii', $quest['id'], $formId);
                $rs->execute();
                $rawR = $rs->get_result()->fetch_all(MYSQLI_ASSOC);
                $rs->close();

                $bd = ['Good' => 0, 'Fair' => 0, 'Bad' => 0];
                $tot = 0;
                foreach ($rawR as $rr) {
                    $rKey = trim($rr['rating']);
                    if ($rKey == '3' || $rKey === 'ကောင်း' || $rKey === 'Good' || $rKey === 'good') {
                        $rKey = 'Good';
                    } elseif ($rKey == '2' || $rKey === 'သင့်' || $rKey === 'Normal' || $rKey === 'normal' || $rKey === 'Average' || $rKey === 'Fair' || $rKey === 'fair') {
                        $rKey = 'Fair';
                    } elseif ($rKey == '1' || $rKey === 'ညံ့' || $rKey === 'Bad' || $rKey === 'bad') {
                        $rKey = 'Bad';
                    }

                    if (array_key_exists($rKey, $bd)) {
                        $bd[$rKey] += (int) $rr['cnt'];
                        $tot += $rr['cnt'];
                    }
                }
                $ratingResults[$quest['id']] = ['breakdown' => $bd, 'total' => $tot];
            } else {
                $cs = $conn->prepare("SELECT comment_text FROM feedback_comments WHERE question_id=? AND feedback_form_id=? ORDER BY id DESC");
                $cs->bind_param('ii', $quest['id'], $formId);
                $cs->execute();
                $comments[$quest['id']] = $cs->get_result()->fetch_all(MYSQLI_ASSOC);
                $cs->close();
            }
        }
    }
}

$ratingQuestions = [];
$commentQuestions = [];
foreach ($questions as $q) {
    if ($q['question_type'] === 'rating') {
        $ratingQuestions[] = $q;
    } else {
        $commentQuestions[] = $q;
    }
}

$navItems = [['label' => 'Dashboard', 'href' => '/studentfeedback/teacher/index.php', 'key' => 'dashboard', 'icon' => 'home'], ['label' => 'My Sections', 'href' => '/studentfeedback/teacher/my_sections.php', 'key' => 'sections', 'icon' => 'grid'], ['label' => 'Feedback Results', 'href' => '/studentfeedback/teacher/feedback_results.php', 'key' => 'results', 'icon' => 'chart'], ['label' => 'Analytics', 'href' => '/studentfeedback/teacher/analytics.php', 'key' => 'analytics', 'icon' => 'report'], ['label' => 'Profile', 'href' => '/studentfeedback/teacher/profile.php', 'key' => 'profile', 'icon' => 'user']];
$initials = avatarInitials($user['name']);
?>
<!DOCTYPE html>
<html lang="my" class="h-full">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= e($pageTitle) ?> — SFMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>tailwind.config = { theme: { extend: { fontFamily: { sans: ['Pyidaungsu', 'Inter', 'sans-serif'] } } } }</script>
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
                <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center"><?= iconSvg('user', 'w-5 h-5 text-white') ?></div>
                <div>
                    <p class="text-sm font-bold">SFMS Teacher</p>
                    <p class="text-[10px] text-cyan-100">Faculty Portal</p>
                </div>
                <button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-200 hover:text-white">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <nav class="flex-1 py-4 px-3 space-y-0.5">
                <?php foreach ($navItems as $n): $a = $activeMenu === $n['key']; ?>
                    <a href="<?= $n['href'] ?>" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm <?= $a ? 'bg-white/20 text-white font-semibold' : 'text-cyan-100 hover:bg-white/10 hover:text-white' ?>">
                        <?= iconSvg($n['icon'], 'w-4 h-4') ?>     <?= e($n['label']) ?>
                        <?php if ($a): ?><span class="ml-auto w-1.5 h-1.5 rounded-full bg-white"></span><?php endif ?>
                    </a>
                <?php endforeach ?>
            </nav>
            <div class="border-t border-cyan-500 px-4 py-4">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold flex-shrink-0"><?= e($initials) ?></div>
                    <div class="flex-1 min-w-0"><p class="text-xs font-semibold text-white truncate"><?= e($user['name']) ?></p><p class="text-[10px] text-cyan-100 truncate"><?= e($user['email']) ?></p></div>
                    <a href="/studentfeedback/auth/logout.php" class="text-cyan-200 hover:text-red-300"><?= iconSvg('logout','w-4 h-4') ?></a>
                </div>
            </div>
        </aside>

        <div class="flex-1 flex flex-col min-w-0 overflow-hidden">
            <header class="bg-white border-b border-slate-200 px-4 lg:px-6 py-3.5 flex items-center gap-4 flex-shrink-0 sticky top-0 z-20 shadow-sm">
                <button onclick="openSidebar()" class="lg:hidden text-slate-500 hover:text-slate-800">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                    </svg>
                </button>
                <h1 class="text-base font-semibold text-slate-800"><?= e($pageTitle) ?></h1>
                <div class="ml-auto flex items-center gap-3">
                    <a href="/studentfeedback/teacher/profile.php" class="flex items-center gap-2 px-3 py-1.5 rounded-xl hover:bg-slate-50">
                        <div class="w-7 h-7 rounded-full bg-cyan-600 flex items-center justify-center text-xs font-bold text-white"><?= e($initials) ?></div>
                        <span class="hidden md:block text-sm font-medium text-slate-700"><?= e($user['name']) ?></span>
                    </a>
                </div>
            </header>

            <main class="flex-1 overflow-y-auto p-4 lg:p-6 max-w-6xl w-full mx-auto">
                <div class="mb-6">
                    <h2 class="text-xl font-bold text-slate-800">Feedback Results (တုံ့ပြန်မှုရလဒ်များ)</h2>
                    <p class="text-sm text-slate-500">ကျောင်းသားများမှ ပေးပို့ထားသော စစ်တမ်းရလဒ်များကို အမည်မသိစနစ် (Anonymous) ဖြင့်ကြည့်ရှုခြင်း</p>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 items-start">
                    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-4">
                        <p class="text-xs font-bold text-slate-400 uppercase mb-3 tracking-wider">Filter</p>
                        <div class="mb-3">
                            <label class="block text-[10px] font-semibold text-slate-500 mb-1">Semester</label>
                            <select id="semesterFilter" onchange="filterBySemester()" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-xs focus:border-cyan-500 outline-none bg-white">
                                <option value="">All Semesters</option>
                                <?php foreach ($semesters as $sem): ?>
                                    <option value="<?= e($sem) ?>" <?= $semesterFilter === $sem ? 'selected' : '' ?>><?= e($sem) ?></option>
                                <?php endforeach ?>
                            </select>
                        </div>
                        <p class="text-xs font-bold text-slate-400 uppercase mb-3 tracking-wider">Select Section</p>
                        <div class="space-y-1">
                            <?php if (!empty($mySections)): foreach ($mySections as $sec): ?>
                                <button type="button" onclick="selectSection(<?= $sec['id'] ?>)" class="w-full text-left block px-3 py-2.5 rounded-xl text-xs transition-all border <?= $sectionId == $sec['id'] ? 'bg-cyan-600 border-cyan-600 text-white font-bold shadow-sm' : 'border-transparent text-slate-600 hover:bg-slate-50' ?>">
                                    <p class="truncate"><?= e($sec['course_name']) ?></p>
                                    <p class="text-[10px] mt-0.5 opacity-80"><?= e($sec['semester']) ?> · Section <?= e($sec['section']) ?></p>
                                </button>
                            <?php endforeach; else: ?>
                                <p class="text-xs text-slate-400 italic text-center py-4">No sections assigned.</p>
                            <?php endif ?>
                        </div>
                    </div>

                    <div class="lg:col-span-3 space-y-6">
                        <?php if ($sectionId && !empty($sectionForms)): ?>
                            

                            <?php if ($form): ?>
                                <!-- Feedback Progress Stats (outside form) -->
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6 myanmar-font no-print">
                                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 text-center">
                                        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">စုစုပေါင်း ကျောင်းသား (Total Students)</p>
                                        <p class="text-3xl font-black text-slate-800"><?= $totalStudents ?></p>
                                    </div>
                                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 text-center">
                                        <p class="text-xs font-bold text-emerald-500 uppercase tracking-wider mb-1">ဖြေဆိုပြီး (Completed)</p>
                                        <p class="text-3xl font-black text-emerald-600"><?= $completedCount ?></p>
                                        <p class="text-[10px] text-slate-400 mt-1"><?= $totalStudents > 0 ? round(($completedCount / $totalStudents) * 100) : 0 ?>% response rate</p>
                                    </div>
                                    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-5 text-center">
                                        <p class="text-xs font-bold text-amber-500 uppercase tracking-wider mb-1">ကျန်ရှိနေသေး (Pending)</p>
                                        <p class="text-3xl font-black text-amber-600"><?= $pendingCount ?></p>
                                        <p class="text-[10px] text-slate-400 mt-1"><?= $totalStudents > 0 ? round(($pendingCount / $totalStudents) * 100) : 0 ?>% remaining</p>
                                    </div>
                                </div>

                                <div class="bg-white shadow-md rounded-xl border border-slate-200 p-6 md:p-8">

                                    <div class="text-center border-b-2 border-slate-800 pb-4 mb-5">
                                        <h2 class="text-lg md:text-xl font-bold text-slate-900 mb-1"><?= e($form['title']) ?></h2>
                                        <p class="text-xs text-slate-400 font-mono">Statistical Evaluation Report Matrix (Anonymous)</p>
                                        <p class="text-xs text-slate-400 mt-1">Feedback Period: <?= formatDate($form['start_date']) ?> — <?= formatDate($form['end_date']) ?></p>
                                    </div>

                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 bg-slate-50 p-4 rounded-xl border border-slate-200 text-xs">
                                        <div>
                                            <span class="font-bold text-slate-500">ဘာသာရပ် (Course):</span>
                                            <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800">
                                                <?= e($form['course_name']) ?>
                                            </div>
                                        </div>
                                        <div>
                                            <span class="font-bold text-slate-500">အတန်း (Section):</span>
                                            <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800 font-mono">
                                                Section <?= e($form['section']) ?> (<?= e($form['academic_year']) ?>)
                                            </div>
                                        </div>
                                        <div>
                                            <span class="font-bold text-slate-500">စုစုပေါင်း ဖြေဆိုသူ (Submissions):</span>
                                            <div class="border-b border-dashed border-slate-300 py-1 font-semibold text-slate-800">
                                                <?= $submissionCount ?> ယောက်
                                            </div>
                                        </div>
                                    </div>

                                    <?php if (!empty($ratingQuestions)): ?>
                                        <div class="mb-8">
                                            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">၁။ မေးခွန်းအလိုက် စာရင်းဇယား ရလဒ်များ</h3>
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
                                                            $res = $ratingResults[$q['id']] ?? ['breakdown' => ['Good' => 0, 'Fair' => 0, 'Bad' => 0], 'total' => 0];
                                                            $goodCount = $res['breakdown']['Good'] ?? 0;
                                                            $normalCount = $res['breakdown']['Fair'] ?? 0;
                                                            $badCount = $res['breakdown']['Bad'] ?? 0;
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
                                            <h3 class="text-xs font-bold text-slate-900 uppercase tracking-wider">၂။ ရေးသားပေးပို့ထားသော အကြံပြုချက်များ (Comments Box)</h3>
                                            <?php foreach ($commentQuestions as $q): $commentsForThisQuestion = $comments[$q['id']] ?? []; ?>
                                                <div class="space-y-2 text-xs">
                                                    <label class="block font-bold text-slate-700 text-sm">
                                                        <?= e($q['question_no']) ?>။ <?= e($q['question_text']) ?>
                                                        <span class="text-slate-400 font-normal text-xs">(စုစုပေါင်းမှတ်ချက် - <?= count($commentsForThisQuestion) ?> ခု)</span>
                                                    </label>
                                                    <div class="w-full bg-slate-50 border border-slate-200 p-4 rounded-xl space-y-2.5 max-h-[300px] overflow-y-auto">
                                                        <?php if (!empty($commentsForThisQuestion)): foreach ($commentsForThisQuestion as $index => $cm): ?>
                                                                <div class="bg-white border border-slate-100 p-3 rounded-lg shadow-2xs flex items-start gap-2">
                                                                    <span class="text-slate-400 font-bold">#<?= $index + 1 ?></span>
                                                                    <div class="text-slate-800 font-medium whitespace-pre-wrap"><?= e($cm['comment_text']) ?></div>
                                                                </div>
                                                            <?php endforeach; else: ?>
                                                            <div class="text-slate-400 italic text-center py-4">— (ဤမေးခွန်းအတွက် မှတ်ချက်ရေးသားထားခြင်းမရှိပါ) —</div>
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
                            <?php endif ?>
                        <?php elseif ($sectionId): ?>
                            <div class="bg-white rounded-2xl border border-slate-200 text-center py-20 text-slate-400">
                                <span class="text-2xl block mb-2">📄</span>
                                <p class="text-sm font-semibold">No feedback forms for this section.</p>
                            </div>
                        <?php else: ?>
                            <div class="bg-white rounded-2xl border border-slate-200 text-center py-20 text-slate-400">
                                <span class="text-2xl block mb-2">📊</span>
                                <p class="text-sm font-semibold">Select a section to view results.</p>
                                <p class="text-xs mt-1">တုံ့ပြန်မှုရလဒ်ဇယားများကြည့်ရန် ဘယ်ဘက်မှ အတန်းတစ်ခုကို အရင်ရွေးချယ်ပေးပါ။</p>
                            </div>
                        <?php endif ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        function openSidebar() { document.getElementById('sidebar').classList.remove('-translate-x-full'); document.getElementById('overlay').classList.remove('hidden'); }
        function closeSidebar() { document.getElementById('sidebar').classList.add('-translate-x-full'); document.getElementById('overlay').classList.add('hidden'); }

        <?php if ($autoFormId): ?>
        (function() {
            var url = new URL(window.location);
            url.searchParams.set('form_id', '<?= $autoFormId ?>');
            window.location.href = url.href;
        })();
        <?php endif ?>

        function filterBySemester() {
            var sem = document.getElementById('semesterFilter').value;
            var url = new URL(window.location);
            url.searchParams.set('semester', sem);
            url.searchParams.delete('section_id');
            url.searchParams.delete('form_id');
            window.location.href = url.href;
        }

        function selectSection(sectionId) {
            var url = new URL(window.location);
            url.searchParams.set('section_id', sectionId);
            url.searchParams.delete('form_id');
            window.location.href = url.href;
        }

        function selectForm(formId) {
            var url = new URL(window.location);
            url.searchParams.set('form_id', formId);
            window.location.href = url.href;
        }
    </script>
</body>
</html>