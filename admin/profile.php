<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle  = 'My Profile';
$activeMenu = 'profile';
$user       = getCurrentUser();

$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_info') {
        $name  = clean($_POST['name'] ?? '');
        $email = clean($_POST['email'] ?? '');
        if ($name && $email) {
            $stmt = $conn->prepare("UPDATE users SET name=?, email=? WHERE id=?");
            $stmt->bind_param('ssi', $name, $email, $user['id']);
            if ($stmt->execute()) {
                $_SESSION['name'] = $name; $_SESSION['email'] = $email;
                setFlash('success', 'Profile updated successfully.');
            } else {
                setFlash('error', 'Email already in use.');
            }
            $stmt->close();
        }
        header('Location: profile.php'); exit;
    }
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if ($current && $new && $confirm) {
            if ($new !== $confirm) {
                setFlash('error', 'New passwords do not match.');
            } else {
                $stmt = $conn->prepare("SELECT password FROM users WHERE id=?");
                $stmt->bind_param('i', $user['id']); $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
                if ($row && password_verify($current, $row['password'])) {
                    $hash = password_hash($new, PASSWORD_DEFAULT);
                    $stmt2 = $conn->prepare("UPDATE users SET password=? WHERE id=?");
                    $stmt2->bind_param('si', $hash, $user['id']); $stmt2->execute(); $stmt2->close();
                    setFlash('success', 'Password changed successfully.');
                } else {
                    setFlash('error', 'Current password is incorrect.');
                }
            }
        } else { setFlash('error', 'All password fields are required.'); }
        header('Location: profile.php'); exit;
    }
}

// Re-fetch fresh user data
$stmt = $conn->prepare("SELECT * FROM users WHERE id=?");
$stmt->bind_param('i', $user['id']); $stmt->execute();
$userData = $stmt->get_result()->fetch_assoc(); $stmt->close();

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="mb-6">
    <h2 class="text-xl font-bold text-slate-800">My Profile</h2>
    <p class="text-sm text-slate-500 mt-0.5">Manage your account information and password</p>
</div>

<?php renderFlash() ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Profile Card -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-100 p-6 flex flex-col items-center text-center">
        <div class="w-20 h-20 rounded-full bg-gradient-to-br from-cyan-500 to-cyan-700 flex items-center justify-center text-2xl font-bold text-white shadow-lg mb-4">
            <?= e(avatarInitials($userData['name'])) ?>
        </div>
        <h3 class="text-lg font-semibold text-slate-800"><?= e($userData['name']) ?></h3>
        <p class="text-sm text-slate-500 mt-1"><?= e($userData['email']) ?></p>
        <div class="mt-3"><?= badgeRole($userData['role']) ?></div>
        <div class="w-full border-t border-slate-100 mt-5 pt-5">
            <div class="flex justify-between text-sm"><span class="text-slate-500">Username</span><span class="font-medium text-slate-700 font-mono"><?= e($userData['username']) ?></span></div>
            <div class="flex justify-between text-sm mt-2"><span class="text-slate-500">Member Since</span><span class="font-medium text-slate-700"><?= formatDate($userData['created_at']) ?></span></div>
        </div>
    </div>

    <!-- Forms -->
    <div class="lg:col-span-2 space-y-5">
        <!-- Update Info -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800">Update Information</h3></div>
            <form method="POST" class="px-6 py-5 space-y-4">
                <?= csrfField() ?><input type="hidden" name="action" value="update_info">
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Full Name</label>
                    <input type="text" name="name" required value="<?= e($userData['name']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Email Address</label>
                    <input type="email" name="email" required value="<?= e($userData['email']) ?>" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl shadow-sm">Save Changes</button>
                </div>
            </form>
        </div>

        <!-- Change Password -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100"><h3 class="font-semibold text-slate-800">Change Password</h3></div>
            <form method="POST" class="px-6 py-5 space-y-4">
                <?= csrfField() ?><input type="hidden" name="action" value="change_password">
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Current Password</label>
                    <input type="password" name="current_password" required placeholder="••••••••" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">New Password</label>
                        <input type="password" name="new_password" required placeholder="••••••••" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    </div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Confirm Password</label>
                        <input type="password" name="confirm_password" required placeholder="••••••••" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="px-6 py-2.5 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl shadow-sm">Update Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/admin_footer.php'; ?>
