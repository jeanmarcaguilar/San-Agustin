<?php
/**
 * Secure Session Configuration
 * MUST be included before any session operations
 */

// Prevent session fixation and hijacking
if (session_status() === PHP_SESSION_NONE) {
    // Secure session configuration
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_lifetime', 0); // Session cookie expires when browser closes
    ini_set('session.gc_maxlifetime', 28800); // 8 hours
    
    // Start session
    session_start();
    
    // Initialize session security on first access
    if (!isset($_SESSION['initialized'])) {
        session_regenerate_id(true);
        $_SESSION['initialized'] = true;
        $_SESSION['created_at'] = time();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    // Validate session security
    if (isset($_SESSION['user_id'])) {
        // Check if user agent changed (possible session hijacking)
        if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
            session_unset();
            session_destroy();
            session_start();
            header('Location: /San%20Agustin/login.php?error=session_invalid');
            exit();
        }
        
        // Check if IP address changed (optional - may cause issues with mobile users)
        // Uncomment if you want strict IP checking
        /*
        if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
            session_unset();
            session_destroy();
            session_start();
            header('Location: /San%20Agustin/login.php?error=session_invalid');
            exit();
        }
        */
        
        // Check session age (force re-login after 8 hours)
        $max_session_age = 28800; // 8 hours in seconds
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > $max_session_age) {
            session_unset();
            session_destroy();
            session_start();
            header('Location: /San%20Agustin/login.php?error=session_expired');
            exit();
        }
        
        // Regenerate session ID periodically (every 30 minutes)
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        } elseif (time() - $_SESSION['last_regeneration'] > 1800) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }
}

/**
 * Validate that the current session belongs to the logged-in user
 * Call this function at the start of protected pages
 */
function validate_session_security() {
    if (!isset($_SESSION['user_id'])) {
        return true; // Not logged in, no validation needed
    }
    
    // Ensure all required session variables are present
    $required_vars = ['user_id', 'username', 'role', 'login_time'];
    foreach ($required_vars as $var) {
        if (!isset($_SESSION[$var])) {
            session_unset();
            session_destroy();
            header('Location: /San%20Agustin/login.php?error=invalid_session');
            exit();
        }
    }
    
    return true;
}
?>