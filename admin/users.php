<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle  = 'Users';
$activeMenu = 'users';

// ─── POST Handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name     = clean($_POST['name'] ?? '');
        $username = clean($_POST['username'] ?? '');
        $email    = clean($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = in_array($_POST['role'],['admin','teacher','student']) ? $_POST['role'] : 'student';

        if ($name && $username && $email && $password) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (name,username,email,password,role) VALUES (?,?,?,?,?)");
            $stmt->bind_param('sssss',$name,$username,$email,$hash,$role);
            if ($stmt->execute()) {
                setFlash('success','User created successfully.');
            } else {
                setFlash('error','Failed: email or username already exists.');
            }
            $stmt->close();
        } else { setFlash('error','All fields are required.'); }
    }

    if ($action === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = clean($_POST['name'] ?? '');
        $username = clean($_POST['username'] ?? '');
        $email    = clean($_POST['email'] ?? '');
        $role     = in_array($_POST['role'],['admin','teacher','student']) ? $_POST['role'] : 'student';
        $password = $_POST['password'] ?? '';

        if ($id && $name && $email) {
            if ($password) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET name=?,username=?,email=?,password=?,role=? WHERE id=?");
                $stmt->bind_param('sssssi',$name,$username,$email,$hash,$role,$id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name=?,username=?,email=?,role=? WHERE id=?");
                $stmt->bind_param('ssssi',$name,$username,$email,$role,$id);
            }
            $stmt->execute() ? setFlash('success','User updated.') : setFlash('error','Failed to update.');
            $stmt->close();
        }
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id && $id !== (int)$_SESSION['user_id']) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param('i',$id);
            $stmt->execute() ? setFlash('success','User deleted.') : setFlash('error','Cannot delete.');
            $stmt->close();
        } else { setFlash('error','You cannot delete your own account.'); }
    }
    header('Location: users.php'); exit;
}

// ─── Fetch ────────────────────────────────────────────────────
$search  = clean($_GET['search'] ?? '');
$roleF   = clean($_GET['role'] ?? '');
$perPage = 10;
$page    = max(1,(int)($_GET['page'] ?? 1));

$whereParts = [];
$params     = [];
$types      = '';

if ($search) { $whereParts[] = "(name LIKE ? OR email LIKE ? OR username LIKE ?)"; $s = "%$search%"; $params = array_merge($params,[$s,$s,$s]); $types .= 'sss'; }
if ($roleF && in_array($roleF,['admin','teacher','student'])) { $whereParts[] = "role = ?"; $params[] = $roleF; $types .= 's'; }
$where = $whereParts ? 'WHERE '.implode(' AND ',$whereParts) : '';

$cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM users $where");
if ($types) $cntStmt->bind_param($types,...$params);
$cntStmt->execute();
$total = (int)$cntStmt->get_result()->fetch_assoc()['c'];
$cntStmt->close();

$pg  = paginate($total,$perPage,$page);
$off = $pg['offset'];

$dataStmt = $conn->prepare("SELECT * FROM users $where ORDER BY id DESC LIMIT ? OFFSET ?");
$p2 = array_merge($params,[$perPage,$off]); $t2 = $types.'ii';
$dataStmt->bind_param($t2,...$p2);
$dataStmt->execute();
$rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dataStmt->close();

$qs = http_build_query(array_filter(['search'=>$search,'role'=>$roleF]));

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-800">Users</h2>
        <p class="text-sm text-slate-500 mt-0.5">Manage all system users</p>
    </div>
    <button onclick="openModal('addModal')" class="inline-flex items-center gap-2 bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-cyan-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus','w-4 h-4') ?> Add User
    </button>
</div>

<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-2 flex-1">
            <div class="relative">
                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search','w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search users..."
                    class="pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl w-48 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>
            <select name="role" class="border border-slate-200 rounded-xl px-3 py-2 text-sm text-slate-600 focus:border-cyan-500 outline-none bg-white">
                <option value="">All Roles</option>
                <option value="admin"   <?= $roleF==='admin'   ?'selected':'' ?>>Admin</option>
                <option value="teacher" <?= $roleF==='teacher' ?'selected':'' ?>>Teacher</option>
                <option value="student" <?= $roleF==='student' ?'selected':'' ?>>Student</option>
            </select>
            <button type="submit" class="px-3 py-2 text-sm bg-cyan-600 text-white rounded-xl hover:bg-cyan-700">Filter</button>
            <?php if ($search||$roleF): ?><a href="users.php" class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50">Clear</a><?php endif ?>
        </form>
        <span class="text-xs text-slate-400"><?= $total ?> users</span>
    </div>

    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500 w-12">#</th>
                    <th class="text-left px-5 py-3 text-slate-500">Name</th>
                    <th class="text-left px-5 py-3 text-slate-500">Username</th>
                    <th class="text-left px-5 py-3 text-slate-500">Email</th>
                    <th class="text-left px-5 py-3 text-slate-500">Role</th>
                    <th class="text-left px-5 py-3 text-slate-500">Created</th>
                    <th class="text-right px-5 py-3 text-slate-500">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php if ($rows): foreach ($rows as $i => $row): ?>
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset']+$i+1 ?></td>
                    <td class="px-5 py-3">
                        <div class="flex items-center gap-2">
                            <div class="w-7 h-7 rounded-full bg-cyan-100 flex items-center justify-center text-xs font-semibold text-cyan-700 flex-shrink-0"><?= e(avatarInitials($row['name'])) ?></div>
                            <span class="text-sm font-medium text-slate-800"><?= e($row['name']) ?></span>
                        </div>
                    </td>
                    <td class="px-5 py-3 text-sm text-slate-600 font-mono"><?= e($row['username']) ?></td>
                    <td class="px-5 py-3 text-sm text-slate-600"><?= e($row['email']) ?></td>
                    <td class="px-5 py-3"><?= badgeRole($row['role']) ?></td>
                    <td class="px-5 py-3 text-sm text-slate-400"><?= formatDate($row['created_at']) ?></td>
                    <td class="px-5 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <button onclick="openEdit(<?= htmlspecialchars(json_encode($row),ENT_QUOTES) ?>)"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg">
                                <?= iconSvg('edit','w-3.5 h-3.5') ?> Edit
                            </button>
                            <?php if ($row['id'] != $_SESSION['user_id']): ?>
                            <button onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['name'])) ?>')"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                <?= iconSvg('trash','w-3.5 h-3.5') ?> Delete
                            </button>
                            <?php endif ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; else: ?>
                <tr><td colspan="7" class="text-center py-16 text-slate-400">
                    <?= iconSvg('users','w-10 h-10 mx-auto mb-3 opacity-40') ?>
                    <p class="text-sm">No users found.</p>
                </td></tr>
            <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100">
        <?= paginationLinks($pg,'users.php'.($qs?"?$qs":'')) ?>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Add User</h3>
            <button onclick="closeModal('addModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Full Name <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required placeholder="John Doe" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Username <span class="text-red-500">*</span></label>
                    <input type="text" name="username" required placeholder="john.doe" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Role <span class="text-red-500">*</span></label>
                    <select name="role" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email <span class="text-red-500">*</span></label>
                    <input type="email" name="email" required placeholder="john@example.com" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Password <span class="text-red-500">*</span></label>
                    <input type="password" name="password" required placeholder="Min 6 characters" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800">Edit User</h3>
            <button onclick="closeModal('editModal')" class="text-slate-400 hover:text-slate-600"><?= iconSvg('x','w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id" id="edit_id">
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Full Name</label>
                    <input type="text" name="name" id="edit_name" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Username</label>
                    <input type="text" name="username" id="edit_username" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Role</label>
                    <select name="role" id="edit_role" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                    <input type="email" name="email" id="edit_email" required class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">New Password <span class="text-xs text-slate-400">(leave blank to keep current)</span></label>
                    <input type="password" name="password" placeholder="••••••••" class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')" class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100">Cancel</button>
                <button type="submit" class="px-5 py-2 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop" data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4"><?= iconSvg('trash','w-7 h-7 text-red-600') ?></div>
            <h3 class="text-lg font-semibold text-slate-800">Delete User</h3>
            <p class="text-sm text-slate-500 mt-2">Delete user <strong id="delete_name" class="text-slate-700"></strong>? This cannot be undone.</p>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')" class="flex-1 px-4 py-2.5 text-sm border border-slate-200 rounded-xl text-slate-600">Cancel</button>
                <button type="submit" class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl">Delete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(u) {
    document.getElementById('edit_id').value       = u.id;
    document.getElementById('edit_name').value     = u.name;
    document.getElementById('edit_username').value = u.username;
    document.getElementById('edit_email').value    = u.email;
    document.getElementById('edit_role').value     = u.role;
    openModal('editModal');
}
function openDelete(id,name) {
    document.getElementById('delete_id').value         = id;
    document.getElementById('delete_name').textContent = name;
    openModal('deleteModal');
}
</script>

<?php include '../includes/admin_footer.php'; ?>
