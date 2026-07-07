<?php
session_start();
require_once 'includes/functions.php';

if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    switch ($_SESSION['role']) {
        case 'admin':
            header("Location: admin/dashboard.php");
            exit;
        case 'teacher':
            header("Location: teacher/dashboard.php");
            exit;
        case 'student':
            header("Location: student/dashboard.php");
            exit;
    }
}

header("Location: student/");
exit;
