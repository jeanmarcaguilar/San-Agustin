<?php
require_once __DIR__ . '/includes/session_config.php';
require_once __DIR__ . '/includes/auth.php';

$auth = new Auth();
$error = '';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_2fa') {
    $posted = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!$posted || !$sessionToken || !hash_equals($sessionToken, $posted)) {
        $error = 'Security validation failed. Please try again.';
    } else {
        $code = trim($_POST['code'] ?? '');
        if (!preg_match('/^[0-9]{6}$/', $code)) {
            $error = 'Invalid code format.';
        } else {
            list($ok, $msg) = $auth->verifyTwoFactor($code);
            if ($ok) {
                exit; // verifyTwoFactor will redirect on success
            }
            $error = $msg ?: 'Verification failed.';
        }
    }
}

// If we reach here, show error by redirecting back to login with session message
if (!empty($error)) {
    $_SESSION['twofa_error'] = $error;
}
header('Location: /San%20Agustin/login.php');
exit();
