<?php
require_once 'includes/session_config.php';
require_once 'includes/auth.php';

$auth = new Auth();

echo "<h3>Security Check</h3>";
echo "Session Secure: " . (ini_get('session.cookie_httponly') ? '✓' : '✗') . "<br>";
echo "CSRF Working: " . ($auth->validateCSRFToken($auth->generateCSRFToken()) ? '✓' : '✗') . "<br>";
echo "Brute Force Protection: " . ($auth->getRemainingAttempts('test') == 5 ? '✓' : '✗') . "<br>";
?>