<?php
require_once '../config/db.php';
require_once '../includes/auth.php';
require_once '../includes/functions.php';
requireRole('admin');

$pageTitle = $LANG['users_title'] ?? 'Users';
$activeMenu = 'users';

function isValidEmail($email)
{
    return preg_match('/^[a-zA-Z0-9._]+@ucsh\.edu\.mm$/', $email);
}

function isValidPassword($password)
{
    return strlen($password) >= 6 && preg_match('/^[a-zA-Z0-9@]+$/', $password);
}

function isValidName($name)
{
    $name = trim($name);
    if (strlen($name) < 2 || strlen($name) > 100)
        return false;
    return preg_match('/^(?:(?:Dr|Prof|U|Daw)\.\s)?[A-Za-z]+(?: [A-Za-z]+)*$/i', $name);
}

function isValidUsername($username)
{
    return preg_match('/^[a-z0-9_]{4,30}$/', $username);
}

function formatName($name)
{
    $name = trim($name);
    $name = ucwords(strtolower($name));
    $name = preg_replace('/\b(Dr|Prof|U|Daw)\b\.(\s)/', '$1.$2', $name);
    return $name;
}

// ─── POST Handlers ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && verifyCsrf()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $rawName = clean($_POST['name'] ?? '');
        $username = strtolower(clean($_POST['username'] ?? ''));
        $email = strtolower(clean($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        $role = in_array($_POST['role'], ['admin', 'teacher', 'student']) ? $_POST['role'] : 'student';
        $name = formatName($rawName);

        $errors = [];
        if (!$rawName || !$username || !$email || !$password || !$confirm) {
            $errors[] = 'required';
        }
        if ($rawName && !isValidName($rawName)) {
            $errors[] = 'name_invalid';
        }
        if ($username && !isValidUsername($username)) {
            $errors[] = 'username_invalid';
        }
        if ($email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'email_invalid';
            } elseif (!preg_match('/@ucsh\.edu\.mm$/', $email)) {
                $errors[] = 'email_domain';
            } elseif (!isValidEmail($email)) {
                $errors[] = 'email_invalid';
            }
        }
        if ($password && !isValidPassword($password)) {
            $errors[] = 'password';
        }
        if ($password && $password !== $confirm) {
            $errors[] = 'confirm';
        }

        $openModal = null;
        if ($errors) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_values'] = ['name' => $name, 'username' => $username, 'email' => $email, 'role' => $role];
            $openModal = 'addModal';
        } else {
            $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
            $check->bind_param('s', $email);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $_SESSION['form_errors'] = ['email_exists'];
                $_SESSION['form_values'] = ['name' => $name, 'username' => $username, 'email' => $email, 'role' => $role];
                $openModal = 'addModal';
            } else {
                $check2 = $conn->prepare("SELECT id FROM users WHERE username = ?");
                $check2->bind_param('s', $username);
                $check2->execute();
                if ($check2->get_result()->num_rows > 0) {
                    $_SESSION['form_errors'] = ['username_exists'];
                    $_SESSION['form_values'] = ['name' => $name, 'username' => $username, 'email' => $email, 'role' => $role];
                    $openModal = 'addModal';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("INSERT INTO users (name,username,email,password,role) VALUES (?,?,?,?,?)");
                    $stmt->bind_param('sssss', $name, $username, $email, $hash, $role);
                    if ($stmt->execute()) {
                        setFlash('success', 'User created successfully.');
                    } else {
                        setFlash('error', 'Failed to create user.');
                    }
                    $stmt->close();
                }
                $check2->close();
            }
            $check->close();
        }

        if ($openModal) {
            $_SESSION['reopen_modal'] = $openModal;
            header('Location: users.php');
            exit;
        }
    }

    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $rawName = clean($_POST['name'] ?? '');
        $username = strtolower(clean($_POST['username'] ?? ''));
        $email = strtolower(clean($_POST['email'] ?? ''));
        $role = in_array($_POST['role'], ['admin', 'teacher', 'student']) ? $_POST['role'] : 'student';
        $password = $_POST['password'] ?? '';
        $name = formatName($rawName);

        $errors = [];
        if (!$id || !$rawName || !$email) {
            $errors[] = 'required';
        }
        if ($rawName && !isValidName($rawName)) {
            $errors[] = 'name_invalid';
        }
        if ($username && !isValidUsername($username)) {
            $errors[] = 'username_invalid';
        }
        if ($email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'email_invalid';
            } elseif (!preg_match('/@ucsh\.edu\.mm$/', $email)) {
                $errors[] = 'email_domain';
            } elseif (!isValidEmail($email)) {
                $errors[] = 'email_invalid';
            }
        }
        if ($password && !isValidPassword($password)) {
            $errors[] = 'password';
        }

        if ($errors) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_values'] = ['id' => $id, 'name' => $name, 'username' => $username, 'email' => $email, 'role' => $role];
            $_SESSION['reopen_modal'] = 'editModal';
            header('Location: users.php');
            exit;
        } else {
            $existing = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
            $existing->bind_param('i', $id);
            $existing->execute();
            $old = $existing->get_result()->fetch_assoc();
            $existing->close();

            $uniqueErrors = [];
            if ($old && $old['email'] !== $email) {
                $chk = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                $chk->bind_param('si', $email, $id);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0)
                    $uniqueErrors[] = 'email_exists';
                $chk->close();
            }
            if ($old && $old['username'] !== $username) {
                $chk = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                $chk->bind_param('si', $username, $id);
                $chk->execute();
                if ($chk->get_result()->num_rows > 0)
                    $uniqueErrors[] = 'username_exists';
                $chk->close();
            }

            if ($uniqueErrors) {
                $_SESSION['form_errors'] = $uniqueErrors;
                $_SESSION['form_values'] = ['id' => $id, 'name' => $name, 'username' => $username, 'email' => $email, 'role' => $role];
                $_SESSION['reopen_modal'] = 'editModal';
                header('Location: users.php');
                exit;
            }

            if ($password) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET name=?,username=?,email=?,password=?,role=? WHERE id=?");
                $stmt->bind_param('sssssi', $name, $username, $email, $hash, $role, $id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET name=?,username=?,email=?,role=? WHERE id=?");
                $stmt->bind_param('ssssi', $name, $username, $email, $role, $id);
            }
            $stmt->execute() ? setFlash('success', 'User updated.') : setFlash('error', 'Failed to update.');
            $stmt->close();
            header('Location: users.php');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && $id !== (int) $_SESSION['user_id']) {
            $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
            $stmt->bind_param('i', $id);
            $stmt->execute() ? setFlash('success', 'User deleted.') : setFlash('error', 'Cannot delete.');
            $stmt->close();
        } else {
            setFlash('error', 'You cannot delete your own account.');
        }
    }
    header('Location: users.php');
    exit;
}

// ─── Read inline errors from session ──────────────────────────
$formErrors = $_SESSION['form_errors'] ?? [];
$formValues = $_SESSION['form_values'] ?? [];
$reopenModal = $_SESSION['reopen_modal'] ?? null;
unset($_SESSION['form_errors'], $_SESSION['form_values'], $_SESSION['reopen_modal']);

$nameError = in_array('name_invalid', $formErrors);
$usernameError = in_array('username_invalid', $formErrors);
$usernameExistsErr = in_array('username_exists', $formErrors);
$emailDomainErr = in_array('email_domain', $formErrors);
$emailInvalidErr = in_array('email_invalid', $formErrors);
$emailExistsErr = in_array('email_exists', $formErrors);
$passwordError = in_array('password', $formErrors);
$confirmError = in_array('confirm', $formErrors);
$requiredError = in_array('required', $formErrors);
$hasErrors = !empty($formErrors);

$addNameErr = ($reopenModal === 'addModal') && $nameError;
$addUsernameErr = ($reopenModal === 'addModal') && ($usernameError || $usernameExistsErr);
$addEmailErr = ($reopenModal === 'addModal') && ($emailDomainErr || $emailInvalidErr || $emailExistsErr);
$addPasswordErr = ($reopenModal === 'addModal') && $passwordError;
$addConfirmErr = ($reopenModal === 'addModal') && $confirmError;
$editNameErr = ($reopenModal === 'editModal') && $nameError;
$editUsernameErr = ($reopenModal === 'editModal') && ($usernameError || $usernameExistsErr);
$editEmailErr = ($reopenModal === 'editModal') && ($emailDomainErr || $emailInvalidErr || $emailExistsErr);
$editPasswordErr = ($reopenModal === 'editModal') && $passwordError;

$borderRed = 'border-red-500 focus:border-red-500 focus:ring-2 focus:ring-red-500/20';

// ─── Fetch ────────────────────────────────────────────────────
$search = clean($_GET['search'] ?? '');
$roleF = clean($_GET['role'] ?? '');
$perPage = 10;
$page = max(1, (int) ($_GET['page'] ?? 1));

$whereParts = [];
$params = [];
$types = '';

if ($search) {
    $whereParts[] = "(name LIKE ? OR email LIKE ? OR username LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s]);
    $types .= 'sss';
}
if ($roleF && in_array($roleF, ['admin', 'teacher', 'student'])) {
    $whereParts[] = "role = ?";
    $params[] = $roleF;
    $types .= 's';
}
$where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';

$cntStmt = $conn->prepare("SELECT COUNT(*) AS c FROM users $where");
if ($types)
    $cntStmt->bind_param($types, ...$params);
$cntStmt->execute();
$total = (int) $cntStmt->get_result()->fetch_assoc()['c'];
$cntStmt->close();

$pg = paginate($total, $perPage, $page);
$off = $pg['offset'];

$dataStmt = $conn->prepare("SELECT * FROM users $where ORDER BY id DESC LIMIT ? OFFSET ?");
$p2 = array_merge($params, [$perPage, $off]);
$t2 = $types . 'ii';
$dataStmt->bind_param($t2, ...$p2);
$dataStmt->execute();
$rows = $dataStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$dataStmt->close();

$qs = http_build_query(array_filter(['search' => $search, 'role' => $roleF]));

include '../includes/admin_header.php';
include '../includes/admin_sidebar.php';
?>

<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
    <div>
        <h2 class="text-xl font-bold text-slate-800"><?= $LANG['users_title'] ?? 'Users' ?></h2>
        <p class="text-sm text-slate-500 mt-0.5"><?= $LANG['users_subtitle'] ?? 'Manage all system users' ?></p>
    </div>
    <button onclick="openModal('addModal')"
        class="inline-flex items-center gap-2 bg-cyan-600 hover:bg-cyan-700 text-white text-sm font-semibold px-4 py-2.5 rounded-xl shadow-sm shadow-cyan-600/20 transition-all hover:-translate-y-0.5">
        <?= iconSvg('plus', 'w-4 h-4') ?> <?= $LANG['add_user'] ?? 'Add User' ?>
    </button>
</div>

<?php renderFlash() ?>

<div class="bg-white rounded-2xl shadow-sm border border-slate-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center gap-3">
        <form method="GET" class="flex flex-wrap items-center gap-2 flex-1">
            <div class="relative">
                <span
                    class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400"><?= iconSvg('search', 'w-4 h-4') ?></span>
                <input type="text" name="search" value="<?= e($search) ?>"
                    placeholder="<?= $LANG['search_users'] ?? 'Search users...' ?>"
                    class="pl-9 pr-4 py-2 text-sm border border-slate-200 rounded-xl w-48 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none">
            </div>
            <select name="role"
                class="border border-slate-200 rounded-xl px-3 py-2 text-sm text-slate-600 focus:border-cyan-500 outline-none bg-white">
                <option value=""><?= $LANG['all_roles'] ?? 'All Roles' ?></option>
                <option value="admin" <?= $roleF === 'admin' ? 'selected' : '' ?>><?= $LANG['admin_role'] ?? 'Admin' ?>
                </option>
                <option value="teacher" <?= $roleF === 'teacher' ? 'selected' : '' ?>>
                    <?= $LANG['teacher_role'] ?? 'Teacher' ?>
                </option>
                <option value="student" <?= $roleF === 'student' ? 'selected' : '' ?>>
                    <?= $LANG['student_role'] ?? 'Student' ?>
                </option>
            </select>
            <button type="submit"
                class="px-3 py-2 text-sm bg-cyan-600 text-white rounded-xl hover:bg-cyan-700"><?= $LANG['filter'] ?? 'Filter' ?></button>
            <?php if ($search || $roleF): ?><a href="users.php"
                    class="px-3 py-2 text-sm border border-slate-200 rounded-xl text-slate-600 hover:bg-slate-50"><?= $LANG['clear'] ?? 'Clear' ?></a><?php endif ?>
        </form>
        <span class="text-xs text-slate-400"><?= $total ?>
            <?= $total !== 1 ? ($LANG['records'] ?? 'records') : ($LANG['record'] ?? 'record') ?></span>
    </div>

    <div class="overflow-x-auto">
        <table>
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="text-left px-5 py-3 text-slate-500">#</th>
                    <th class="text-left px-5 py-3 text-slate-500"><?= $LANG['col_name'] ?? 'Name' ?></th>
                    <th class="text-left px-5 py-3 text-slate-500"><?= $LANG['col_username'] ?? 'Username' ?></th>
                    <th class="text-left px-5 py-3 text-slate-500"><?= $LANG['col_email'] ?? 'Email' ?></th>
                    <th class="text-left px-5 py-3 text-slate-500"><?= $LANG['col_role'] ?? 'Role' ?></th>
                    <th class="text-left px-5 py-3 text-slate-500"><?= $LANG['col_created'] ?? 'Created' ?></th>
                    <th class="text-right px-5 py-3 text-slate-500"><?= $LANG['col_actions'] ?? 'Actions' ?></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php if ($rows):
                    foreach ($rows as $i => $row): ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-5 py-3 text-sm text-slate-400"><?= $pg['offset'] + $i + 1 ?></td>
                            <td class="px-5 py-3">
                                <div class="flex items-center gap-2">
                                    <div
                                        class="w-7 h-7 rounded-full bg-cyan-100 flex items-center justify-center text-xs font-semibold text-cyan-700 flex-shrink-0">
                                        <?= e(avatarInitials($row['name'])) ?>
                                    </div>
                                    <span class="text-sm font-medium text-slate-800"><?= e($row['name']) ?></span>
                                </div>
                            </td>
                            <td class="px-5 py-3 text-sm text-slate-600 font-mono"><?= e($row['username']) ?></td>
                            <td class="px-5 py-3 text-sm text-slate-600"><?= e($row['email']) ?></td>
                            <td class="px-5 py-3"><?= badgeRole($row['role']) ?></td>
                            <td class="px-5 py-3 text-sm text-slate-400"><?= formatDate($row['created_at']) ?></td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button onclick="openEdit(<?= htmlspecialchars(json_encode($row), ENT_QUOTES) ?>)"
                                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg">
                                        <?= iconSvg('edit', 'w-3.5 h-3.5') ?>         <?= $LANG['edit'] ?? 'Edit' ?>
                                    </button>
                                    <?php if ($row['id'] != $_SESSION['user_id']): ?>
                                        <button onclick="openDelete(<?= $row['id'] ?>,'<?= addslashes(e($row['name'])) ?>')"
                                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-700 bg-red-50 hover:bg-red-100 rounded-lg">
                                            <?= iconSvg('trash', 'w-3.5 h-3.5') ?>             <?= $LANG['delete'] ?? 'Delete' ?>
                                        </button>
                                    <?php endif ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; else: ?>
                    <tr>
                        <td colspan="7" class="text-center py-16 text-slate-400">
                            <?= iconSvg('users', 'w-10 h-10 mx-auto mb-3 opacity-40') ?>
                            <p class="text-sm"><?= $LANG['no_users_found'] ?? 'No users found.' ?></p>
                        </td>
                    </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>
    <div class="px-5 py-4 border-t border-slate-100">
        <?= paginationLinks($pg, 'users.php' . ($qs ? "?$qs" : '')) ?>
    </div>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['add_user_modal'] ?? 'Add User' ?></h3>
            <button onclick="closeModal('addModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="add">
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['full_name'] ?? 'Full Name' ?>
                        <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="add_name" required placeholder="John Doe"
                        value="<?= e($reopenModal === 'addModal' ? ($formValues['name'] ?? '') : '') ?>"
                        class="w-full border <?= $addNameErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($addNameErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?= $LANG['val_name_invalid'] ?? 'Full Name may contain only letters, single spaces, and a period (.) for titles such as Dr. or Prof.' ?>
                        </p>
                    <?php endif ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['username'] ?? 'Username' ?>
                        <span class="text-red-500">*</span></label>
                    <input type="text" name="username" id="add_username" required placeholder="john_doe"
                        value="<?= e($reopenModal === 'addModal' ? ($formValues['username'] ?? '') : '') ?>"
                        class="w-full border <?= $addUsernameErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($addUsernameErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?php if ($usernameExistsErr): ?>
                                <?= $LANG['val_username_taken'] ?? 'This username is already taken.' ?>
                            <?php else: ?>
                                <?= $LANG['val_username_invalid'] ?? 'Username must be 4-30 characters. Only lowercase letters, numbers, and underscore allowed.' ?>
                            <?php endif ?>
                        </p>
                    <?php endif ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['role'] ?? 'Role' ?> <span
                            class="text-red-500">*</span></label>
                    <select name="role" required
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="student" <?= ($reopenModal === 'addModal' && ($formValues['role'] ?? '') === 'student') ? 'selected' : '' ?>><?= $LANG['student_role'] ?? 'Student' ?></option>
                        <option value="teacher" <?= ($reopenModal === 'addModal' && ($formValues['role'] ?? '') === 'teacher') ? 'selected' : '' ?>><?= $LANG['teacher_role'] ?? 'Teacher' ?></option>
                        <option value="admin" <?= ($reopenModal === 'addModal' && ($formValues['role'] ?? '') === 'admin') ? 'selected' : '' ?>><?= $LANG['admin_role'] ?? 'Admin' ?></option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['email'] ?? 'Email' ?> <span
                            class="text-red-500">*</span></label>
                    <input type="email" name="email" id="add_email" required placeholder="name@ucsh.edu.mm"
                        value="<?= e($reopenModal === 'addModal' ? ($formValues['email'] ?? '') : '') ?>"
                        class="w-full border <?= $addEmailErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($addEmailErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?php if ($emailExistsErr): ?>
                                <?= $LANG['val_email_taken'] ?? 'This email address is already registered.' ?>
                            <?php elseif ($emailDomainErr): ?>
                                <?= $LANG['val_email_domain'] ?? 'Only @ucsh.edu.mm email addresses are allowed.' ?>
                            <?php else: ?>
                                <?= $LANG['val_email_invalid'] ?? 'Please enter a valid email address.' ?>
                            <?php endif ?>
                        </p>
                    <?php endif ?>
                </div>
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['password_field'] ?? 'Password' ?>
                        <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="password" name="password" id="add_password" required minlength="6"
                            placeholder="Letters, numbers, or @"
                            class="w-full border <?= $addPasswordErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 pr-10 text-sm outline-none">
                        <button type="button" onclick="togglePassword('add_password',this)"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"><?= iconSvg('eye', 'w-4 h-4') ?></button>
                    </div>
                    <?php if ($addPasswordErr): ?>
                        <p class="text-red-500 text-xs mt-1.5"><?= $LANG['val_password_invalid'] ?? 'Password must be at least 6 characters. Only letters,
                            numbers, and @ are allowed.' ?></p>
                    <?php endif ?>
                </div>
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['confirm_password'] ?? 'Confirm Password' ?>
                        <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <input type="password" name="confirm_password" id="add_confirm_password" required minlength="6"
                            placeholder="Re-enter password"
                            class="w-full border <?= $addConfirmErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 pr-10 text-sm outline-none">
                        <button type="button" onclick="togglePassword('add_confirm_password',this)"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"><?= iconSvg('eye', 'w-4 h-4') ?></button>
                    </div>
                    <?php if ($addConfirmErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?= $LANG['val_passwords_mismatch'] ?? 'Passwords do not match.' ?>
                        </p>
                    <?php endif ?>
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('addModal')"
                    class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="px-5 py-2 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl"><?= $LANG['create_user'] ?? 'Create User' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg modal-box">
        <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
            <h3 class="font-semibold text-slate-800"><?= $LANG['edit_user_modal'] ?? 'Edit User' ?></h3>
            <button onclick="closeModal('editModal')"
                class="text-slate-400 hover:text-slate-600"><?= iconSvg('x', 'w-5 h-5') ?></button>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="id"
                id="edit_id" value="<?= e($reopenModal === 'editModal' ? ($formValues['id'] ?? '') : '') ?>">
            <div class="px-6 py-5 grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['full_name'] ?? 'Full Name' ?></label>
                    <input type="text" name="name" id="edit_name" required
                        value="<?= e($reopenModal === 'editModal' ? ($formValues['name'] ?? '') : '') ?>"
                        class="w-full border <?= $editNameErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($editNameErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?= $LANG['val_name_invalid'] ?? 'Full Name may contain only letters, single spaces, and a period (.) for titles such as Dr. or Prof.' ?>
                        </p>
                    <?php endif ?>
                </div>
                <div>
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['username'] ?? 'Username' ?></label>
                    <input type="text" name="username" id="edit_username" required
                        value="<?= e($reopenModal === 'editModal' ? ($formValues['username'] ?? '') : '') ?>"
                        class="w-full border <?= $editUsernameErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($editUsernameErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?php if ($usernameExistsErr): ?>
                                <?= $LANG['val_username_taken'] ?? 'This username is already taken.' ?>
                            <?php else: ?>
                                <?= $LANG['val_username_invalid'] ?? 'Username must be 4-30 characters. Only lowercase letters, numbers, and underscore allowed.' ?>
                            <?php endif ?>
                        </p>
                    <?php endif ?>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['role'] ?? 'Role' ?></label>
                    <select name="role" id="edit_role"
                        class="w-full border border-slate-200 rounded-xl px-4 py-2.5 text-sm focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 outline-none bg-white">
                        <option value="student" <?= ($reopenModal === 'editModal' && ($formValues['role'] ?? '') === 'student') ? 'selected' : '' ?>><?= $LANG['student_role'] ?? 'Student' ?></option>
                        <option value="teacher" <?= ($reopenModal === 'editModal' && ($formValues['role'] ?? '') === 'teacher') ? 'selected' : '' ?>><?= $LANG['teacher_role'] ?? 'Teacher' ?></option>
                        <option value="admin" <?= ($reopenModal === 'editModal' && ($formValues['role'] ?? '') === 'admin') ? 'selected' : '' ?>><?= $LANG['admin_role'] ?? 'Admin' ?></option>
                    </select>
                </div>
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['email'] ?? 'Email' ?></label>
                    <input type="email" name="email" id="edit_email" required
                        value="<?= e($reopenModal === 'editModal' ? ($formValues['email'] ?? '') : '') ?>"
                        class="w-full border <?= $editEmailErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 text-sm outline-none">
                    <?php if ($editEmailErr): ?>
                        <p class="text-red-500 text-xs mt-1.5">
                            <?php if ($emailExistsErr): ?>
                                <?= $LANG['val_email_taken'] ?? 'This email address is already registered.' ?>
                            <?php elseif ($emailDomainErr): ?>
                                <?= $LANG['val_email_domain'] ?? 'Only @ucsh.edu.mm email addresses are allowed.' ?>
                            <?php else: ?>
                                <?= $LANG['val_email_invalid'] ?? 'Please enter a valid email address.' ?>
                            <?php endif ?>
                        </p>
                    <?php endif ?>
                </div>
                <div class="col-span-2">
                    <label
                        class="block text-sm font-medium text-slate-700 mb-1"><?= $LANG['new_password'] ?? 'New Password' ?>
                        <span
                            class="text-xs text-slate-400">(<?= $LANG['new_password_hint'] ?? 'leave blank to keep current' ?>)</span></label>
                    <div class="relative">
                        <input type="password" name="password" id="edit_password" placeholder="Letters, numbers, or @"
                            class="w-full border <?= $editPasswordErr ? $borderRed : 'border-slate-200 focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20' ?> rounded-xl px-4 py-2.5 pr-10 text-sm outline-none">
                        <button type="button" onclick="togglePassword('edit_password',this)"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600"><?= iconSvg('eye', 'w-4 h-4') ?></button>
                    </div>
                    <?php if ($editPasswordErr): ?>
                        <p class="text-red-500 text-xs mt-1.5"><?= $LANG['val_password_invalid'] ?? 'Password must be at least 6 characters. Only letters,
                            numbers, and @ are allowed.' ?></p>
                    <?php endif ?>
                </div>
            </div>
            <div class="flex justify-end gap-3 px-6 py-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl">
                <button type="button" onclick="closeModal('editModal')"
                    class="px-4 py-2 text-sm text-slate-600 border border-slate-200 rounded-xl hover:bg-slate-100"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="px-5 py-2 text-sm font-semibold text-white bg-cyan-600 hover:bg-cyan-700 rounded-xl"><?= $LANG['save_changes_btn'] ?? 'Save Changes' ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center p-4 modal-backdrop"
    data-modal-backdrop>
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm modal-box">
        <div class="px-6 py-6 text-center">
            <div class="w-14 h-14 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
                <?= iconSvg('trash', 'w-7 h-7 text-red-600') ?>
            </div>
            <h3 class="text-lg font-semibold text-slate-800"><?= $LANG['delete_user_modal'] ?? 'Delete User' ?></h3>
            <p class="text-sm text-slate-500 mt-2"><?= $LANG['delete_user_confirm'] ?? 'Delete user' ?> <strong
                    id="delete_name" class="text-slate-700"></strong>?
                <?= $LANG['delete_user_undone'] ?? 'This cannot be undone.' ?>
            </p>
        </div>
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id"
                id="delete_id">
            <div class="flex gap-3 px-6 pb-6">
                <button type="button" onclick="closeModal('deleteModal')"
                    class="flex-1 px-4 py-2.5 text-sm border border-slate-200 rounded-xl text-slate-600"><?= $LANG['cancel'] ?? 'Cancel' ?></button>
                <button type="submit"
                    class="flex-1 px-4 py-2.5 text-sm font-semibold text-white bg-red-600 hover:bg-red-700 rounded-xl"><?= $LANG['delete'] ?? 'Delete' ?></button>
            </div>
        </form>
    </div>
</div>

<script>
    (function () { var s = document.createElement('style'); s.textContent = 'input[type="password"]::-ms-reveal,input[type="password"]::-ms-clear{display:none}'; document.head.appendChild(s) })();
    function togglePassword(id, btn) {
        var inp = document.getElementById(id);
        var show = inp.type === 'password';
        inp.type = show ? 'text' : 'password';
        btn.innerHTML = show
            ? '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178zM15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>';
    }
    var msgs = {
        name_invalid: <?= json_encode($LANG['val_name_invalid'] ?? 'Full Name may contain only letters, single spaces, and a period (.) for titles such as Dr. or Prof.') ?>,
        username_invalid: <?= json_encode($LANG['val_username_invalid'] ?? 'Username must be 4-30 characters. Only lowercase letters, numbers, and underscore allowed.') ?>,
        email_invalid: <?= json_encode($LANG['val_email_invalid'] ?? 'Please enter a valid email address.') ?>,
        email_domain: <?= json_encode($LANG['val_email_domain'] ?? 'Only @ucsh.edu.mm email addresses are allowed.') ?>
    };
    function validateName(name) {
        name = name.trim();
        if (name.length < 2 || name.length > 100) return msgs.name_invalid;
        if (!/^(?:(?:Dr|Prof|U|Daw)\.\s)?[A-Za-z]+(?: [A-Za-z]+)*$/i.test(name)) return msgs.name_invalid;
        return '';
    }
    function validateUsername(username) {
        if (!/^[a-z0-9_]{4,30}$/.test(username)) return msgs.username_invalid;
        return '';
    }
    function validateEmail(email) {
        if (!/^[a-zA-Z0-9._]+@ucsh\.edu\.mm$/.test(email)) return msgs.email_invalid;
        return '';
    }
    document.addEventListener('DOMContentLoaded', function () {
        var addForm = document.querySelector('#addModal form');
        if (addForm) {
            addForm.addEventListener('submit', function (e) {
                var name = document.getElementById('add_name');
                var username = document.getElementById('add_username');
                var email = document.getElementById('add_email');
                var err = validateName(name.value);
                if (err) { alert(err); name.focus(); e.preventDefault(); return; }
                username.value = username.value.toLowerCase();
                err = validateUsername(username.value);
                if (err) { alert(err); username.focus(); e.preventDefault(); return; }
                email.value = email.value.toLowerCase();
                err = validateEmail(email.value);
                if (err) { alert(err); email.focus(); e.preventDefault(); return; }
            });
        }
        var editForm = document.querySelector('#editModal form');
        if (editForm) {
            editForm.addEventListener('submit', function (e) {
                var name = document.getElementById('edit_name');
                var username = document.getElementById('edit_username');
                var email = document.getElementById('edit_email');
                var err = validateName(name.value);
                if (err) { alert(err); name.focus(); e.preventDefault(); return; }
                username.value = username.value.toLowerCase();
                err = validateUsername(username.value);
                if (err) { alert(err); username.focus(); e.preventDefault(); return; }
                email.value = email.value.toLowerCase();
                err = validateEmail(email.value);
                if (err) { alert(err); email.focus(); e.preventDefault(); return; }
            });
        }
    });
    function openEdit(u) {
        document.getElementById('edit_id').value = u.id;
        document.getElementById('edit_name').value = u.name;
        document.getElementById('edit_username').value = u.username;
        document.getElementById('edit_email').value = u.email;
        document.getElementById('edit_role').value = u.role;
        openModal('editModal');
    }
    function openDelete(id, name) {
        document.getElementById('delete_id').value = id;
        document.getElementById('delete_name').textContent = name;
        openModal('deleteModal');
    }
    <?php if ($reopenModal): ?>
        document.addEventListener('DOMContentLoaded', function () {
            openModal('<?= $reopenModal ?>');
        });
    <?php endif ?>
</script>

<?php include '../includes/admin_footer.php'; ?>