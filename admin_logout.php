<?php
session_start();

// Remove Admin-specific session vars
unset($_SESSION['AdminID']);
unset($_SESSION['admin_name']);

// Redirect
header("Location: admin_login.php");
exit;
?>