<?php
session_start();

$role = $_GET['role'] ?? '';

if ($role === 'teacher' || $role === 'student') {
    $_SESSION['entry_allowed'] = true;
    $_SESSION['selected_role'] = $role;
    header('Location: ' . $role . '/');
    exit;
}

header('Location: index.php');
exit;
