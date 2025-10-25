<?php
/**
 * Secure Page Header
 * Include this at the top of every protected page to ensure proper session security
 * 
 * Usage: require_once __DIR__ . '/../includes/secure_page.php';
 */

// Include session configuration first
require_once __DIR__ . '/session_config.php';

// Validate session security
validate_session_security();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Clear any partial session data
    session_unset();
    session_destroy();
    
    // Redirect to login
    header('Location: /San%20Agustin/login.php?error=not_logged_in');
    exit();
}

// Verify user still exists in database (prevents deleted users from accessing)
try {
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $login_conn = $database->getLoginConnection();
    
    if ($login_conn) {
        $stmt = $login_conn->prepare("SELECT id, role, is_active FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user_check = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If user doesn't exist or is inactive, logout
        if (!$user_check || (isset($user_check['is_active']) && $user_check['is_active'] == 0)) {
            session_unset();
            session_destroy();
            header('Location: /San%20Agustin/login.php?error=account_inactive');
            exit();
        }
        
        // Verify role hasn't changed
        if ($user_check['role'] !== $_SESSION['role']) {
            session_unset();
            session_destroy();
            header('Location: /San%20Agustin/login.php?error=role_changed');
            exit();
        }
    }
} catch (Exception $e) {
    error_log("Session validation error: " . $e->getMessage());
    // Don't logout on database errors, just log them
}

// Set security headers
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Prevent caching of sensitive pages
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
