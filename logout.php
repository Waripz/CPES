<?php
session_start();

// Remove User-specific session vars
unset($_SESSION['UserID']);
unset($_SESSION['name']);
unset($_SESSION['role']);

// Optional: if you want to be safe, explicitly keep AdminID
// but 'unset' above allows AdminID to remain naturally.

// Redirect
header("Location: index.html");
exit;
?>