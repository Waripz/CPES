<?php
require_once 'config.php';
adminSecureSessionStart();

// Remove Admin-specific session vars
unset($_SESSION['AdminID']);
unset($_SESSION['admin_name']);
unset($_SESSION['role']);

// Destroy the admin session completely
session_destroy();

// Redirect
header("Location: admin_login.php");
exit;
?>