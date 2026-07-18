<?php
session_start();

// Block direct URL access — must come through index.php → portal.php flow
if (!isset($_SESSION['entry_allowed']) || !isset($_SESSION['selected_role']) || $_SESSION['selected_role'] !== 'teacher') {
    header('Location: /studentfeedbackucsh/index.php');
    exit;
}

$loginType = 'teacher';
include '../includes/landing.php';
