<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('teacher');

$user      = getCurrentUser();
$pageTitle = 'My Profile';
$activeMenu= 'profile';
$navItems  = [['label'=>'Dashboard','href'=>'/studentfeedback/teacher/index.php','key'=>'dashboard','icon'=>'home'],['label'=>'My Sections','href'=>'/studentfeedback/teacher/my_sections.php','key'=>'sections','icon'=>'grid'],['label'=>'Feedback Results','href'=>'/studentfeedback/teacher/feedback_results.php','key'=>'results','icon'=>'chart'],['label'=>'Analytics','href'=>'/studentfeedback/teacher/analytics.php','key'=>'analytics','icon'=>'report'],['label'=>'Progress','href'=>'/studentfeedback/teacher/feedback_progress.php','key'=>'progress','icon'=>'clipboard'],['label'=>'Profile','href'=>'/studentfeedback/teacher/profile.php','key'=>'profile','icon'=>'user']];
$initials  = avatarInitials($user['name']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_info') {
        $name  = clean($_POST['name'] ?? '');
        $email = clean($_POST['email'] ?? '');
        if ($name && $email) {
            $stmt = $conn->prepare("UPDATE users SET name=?,email=? WHERE id=?");
            $stmt->bind_param('ssi',$name,$email,$user['id']);
            if ($stmt->execute()) { $_SESSION['name']=$name; $_SESSION['email']=$email; setFlash('success','Profile updated.'); }
            else { setFlash('error','Email already in use.'); }
            $stmt->close();
        }
    }
    if ($action === 'change_password') {
        $cur=$_POST['current_password']??''; $new=$_POST['new_password']??''; $con=$_POST['confirm_password']??'';
        if($new!==$con){setFlash('error','Passwords do not match.');}
        elseif($cur&&$new){
            $stmt=$conn->prepare("SELECT password FROM users WHERE id=?"); $stmt->bind_param('i',$user['id']); $stmt->execute();
            $row=$stmt->get_result()->fetch_assoc(); $stmt->close();
            if($row && password_verify($cur,$row['password'])){
                $hash=password_hash($new,PASSWORD_DEFAULT);
                $stmt2=$conn->prepare("UPDATE users SET password=? WHERE id=?"); $stmt2->bind_param('si',$hash,$user['id']); $stmt2->execute(); $stmt2->close();
                setFlash('success','Password changed.');
            } else { setFlash('error','Current password incorrect.'); }
        }
    }
    header('Location: profile.php'); exit;
}

$stmt=$conn->prepare("SELECT * FROM users WHERE id=?"); $stmt->bind_param('i',$user['id']); $stmt->execute();
$userData=$stmt->get_result()->fetch_assoc(); $stmt->close();
?>
<!DOCTYPE html><html lang="en" class="h-full"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($pageTitle) ?> — SFMS</title><script src="https://cdn.tailwindcss.com"></script><script>tailwind.config={theme:{extend:{fontFamily:{inter:['Inter','sans-serif']}}}}</script><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet"><link rel="stylesheet" href="/studentfeedback/assets/css/custom.css"></head>
<body class="h-full bg-slate-50 font-inter"><div id="overlay" class="fixed inset-0 bg-black/40 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>
<div class="flex h-screen overflow-hidden">
<aside id="sidebar" class="fixed inset-y-0 left-0 w-64 bg-gradient-to-b from-cyan-600 to-cyan-700 text-white flex flex-col z-40 transform -translate-x-full transition-transform duration-300 lg:relative lg:translate-x-0 lg:flex-shrink-0">
    <div class="flex items-center gap-3 px-5 py-5 border-b border-cyan-500"><div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center"><?= iconSvg('user','w-5 h-5 text-white') ?></div><div><p class="text-sm font-bold">SFMS Teacher</p></div><button onclick="closeSidebar()" class="ml-auto lg:hidden text-cyan-100"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg></button></div>
    <nav class="flex-1 py-4 px-3 space-y-0.5"><?php foreach($navItems as $n): $a=$activeMenu===$n['key']; ?><a href="<?= $n['href'] ?>" class="flex items-center gap-3 pl-3 pr-3 py-2.5 rounded-xl text-sm <?= $a?'bg-white/20 text-white font-semibold':'text-cyan-100 hover:bg-white/10 hover:text-white' ?>"><?= iconSvg($n['icon'],'w-4 h-4') ?> <?= e($n['label']) ?></a><?php endforeach ?></nav>
    <div class="border-t border-cyan-500 px-4 py-4"><div class="flex items-center gap-3"><div class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center text-xs font-bold flex-shrink-0"><?= e($initials) ?></div><div class="flex-1 min-w-0"><p class="text-xs font-semibold text-white truncate"><?= e($user['name']) ?></p><p class="text-[10px] text-cyan-100 truncate"><?= e($user['email']) ?></p></div><a href="/studentfeedback/auth/logout.php" class="text-cyan-200 hover:text-red-300"><?= iconSvg('logout','w-4 h-4') ?></a></div></div>
</aside>
<div class="flex-1 flex flex-col min-w-0 overflow-hidden">
<header class="bg-white border-b border-slate-200 px-4 lg:px-6 py-3.5 flex items-center gap-4 flex-shrink-0 sticky top-0 z-20 shadow-sm"><button onclick="openSidebar()" class="lg:hidden text-slate-500 hover:text-slate-800"><svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg></button><h1 class="text-base font-semibold text-slate-800"><?= e($pageTitle) ?></h1><div class="ml-auto flex items-center gap-3"><a href="/studentfeedback/teacher/profile.php" class="flex items-center gap-2 px-3 py-1.5 rounded-xl hover:bg-slate-50"><div class="w-7 h-7 rounded-full bg-cyan-600 flex items-center justify-center text-xs font-bold text-white"><?= e($initials) ?></div><span class="hidden md:block text-sm font-medium text-slate-700"><?= e($user['name']) ?></span></a></div></header>
<main class="flex-1 overflow-y-auto p-4 lg:p-6">
<div class="mb-6"><h2 class="text-xl font-bold text-slate-800">My Profile</h2></div>
<?php renderFlash() ?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 flex flex-col items-center text-center">
        <div class="w-20 h-20 rounded-full bg-gradient-to-br from-cyan-500 to-cyan-700 flex items-center justify-center text-2xl font-bold text-white shadow-lg mb-4"><?= e(avatarInitials($userData['name'])) ?></div>
        <h3 class="text-lg font-semibold text-slate-800"><?= e($userData['name']) ?></h3>
        <p class="text-sm text-slate-500 mt-1"><?= e($userData['email']) ?></p>
        <div class="mt-3"><?= badgeRole($userData['role']) ?></div>
    </div>
    <div class="lg:col-span-2 space-y-5">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800">Update Information</h3></div>
            <form method="POST" class="px-6 py-5 space-y-4"><?= csrfField() ?><input type="hidden" name="action" value="update_info">
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Full Name</label><input type="text" name="name" required value="<?= e($userData['name']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none"></div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Email</label><input type="email" name="email" required value="<?= e($userData['email']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none"></div>
                <div class="flex justify-end"><button type="submit" class="px-6 py-2.5 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl">Save</button></div>
            </form>
        </div>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800">Change Password</h3></div>
            <form method="POST" class="px-6 py-5 space-y-4"><?= csrfField() ?><input type="hidden" name="action" value="change_password">
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Current Password</label><input type="password" name="current_password" required placeholder="••••••••" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">New Password</label><input type="password" name="new_password" required placeholder="••••••••" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none border border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Confirm</label><input type="password" name="confirm_password" required placeholder="••••••••" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20"></div>
                </div>
                <div class="flex justify-end"><button type="submit" class="px-6 py-2.5 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl">Update Password</button></div>
            </form>
        </div>
    </div>
</div>
</main></div></div>
<script>function openSidebar(){document.getElementById('sidebar').classList.remove('-translate-x-full');document.getElementById('overlay').classList.remove('hidden');}function closeSidebar(){document.getElementById('sidebar').classList.add('-translate-x-full');document.getElementById('overlay').classList.add('hidden');}<?php renderFlash() ?></script>
</body></html>
