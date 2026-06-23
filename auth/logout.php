<?php
session_start();
session_destroy();
header('Location: /studentfeedback/auth/login.php');
exit;
