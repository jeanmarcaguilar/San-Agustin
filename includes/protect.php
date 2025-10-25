<?php
require_once 'session_config.php';
require_once 'auth.php';

$auth = new Auth();

// Security headers for all protected pages
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");

// Validate session and require login
if (!$auth->validateSession()) {
    header("Location: ../login.php");
    exit();
}

// Get current role and page
$currentRole = $_SESSION['role'] ?? '';
$currentPage = basename($_SERVER['PHP_SELF']);

// Define allowed roles for this page (you can customize per page)
$allowedRoles = ['librarian', 'registrar', 'teacher', 'student']; // Adjust as needed

if (!in_array($currentRole, $allowedRoles)) {
    header("Location: ../login.php");
    exit();
}
?>