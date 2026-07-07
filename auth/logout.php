<?php
session_start();

$role = $_SESSION['role'] ?? '';

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

session_destroy();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

$redirect = match ($role) {
    'admin'   => '/studentfeedbackucsh/admin/',
    'teacher' => '/studentfeedbackucsh/teacher/',
    'student' => '/studentfeedbackucsh/student/',
    default   => '/studentfeedbackucsh/student/',
};

header("Location: $redirect");
exit;
