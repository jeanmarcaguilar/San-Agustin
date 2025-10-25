<?php
// Remove session_start() from here - use session_config.php instead
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';

class Auth {
    private $conn;
    private $db;
    private $maxAttempts = 5;
    private $lockoutTime = 900; // 15 minutes in seconds

    public function __construct() {
        $this->db = new Database();
        $this->conn = $this->db->getLoginConnection();
        
        // Initialize login attempts if not set
        if (!isset($_SESSION['login_attempts'])) {
            $_SESSION['login_attempts'] = [];
        }
    }

    // Verify 2FA code, finalize login if valid
    public function verifyTwoFactor(string $code) {
        $userId = $_SESSION['2fa_user_id'] ?? null;
        $username = $_SESSION['2fa_username'] ?? null;
        $role = $_SESSION['2fa_role'] ?? null;
        if (!$userId || !$role) {
            return [false, 'Session expired. Please log in again.'];
        }

        try {
            $stmt = $this->conn->prepare("SELECT id, username, role, twofa_code_hash, twofa_expires_at FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            if ($stmt->rowCount() === 0) {
                return [false, 'Account not found. Please log in again.'];
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (empty($row['twofa_code_hash']) || empty($row['twofa_expires_at'])) {
                return [false, 'No active verification code. Please log in again.'];
            }
            if (strtotime($row['twofa_expires_at']) < time()) {
                return [false, 'Your verification code has expired. Please log in again.'];
            }

            if (!password_verify($code, $row['twofa_code_hash'])) {
                return [false, 'Invalid verification code.'];
            }

            // Clear 2FA fields
            $upd = $this->conn->prepare("UPDATE users SET twofa_code_hash = NULL, twofa_expires_at = NULL WHERE id = :id");
            $upd->execute([':id' => $userId]);

            // Finalize login
            session_regenerate_id(true);
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $row['username'];
            $_SESSION['role'] = $row['role'];
            $_SESSION['login_time'] = time();
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
            $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];

            // Cleanup 2FA session vars
            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_username'], $_SESSION['2fa_role'], $_SESSION['2fa_started_at']);

            $this->redirectToDashboard($row['role']);
            return [true, ''];
        } catch (PDOException $e) {
            error_log('2FA verify error: ' . $e->getMessage());
            return [false, 'A server error occurred. Please try again.'];
        }
    }

    private function generateOneTimeCode(): string {
        // 6-digit numeric code
        $num = random_int(100000, 999999);
        return (string)$num;
    }


    // Add these missing methods:
    
    // Get remaining login attempts
    public function getRemainingAttempts($username) {
        $cleanUsername = $this->sanitizeUsername($username);
        $attemptsKey = 'attempts_' . $cleanUsername;
        
        if (!isset($_SESSION['login_attempts'][$attemptsKey])) {
            return $this->maxAttempts;
        }
        
        $attemptData = $_SESSION['login_attempts'][$attemptsKey];
        return max(0, $this->maxAttempts - $attemptData['count']);
    }

    // Get lockout time remaining
    public function getLockoutTimeRemaining($username) {
        $cleanUsername = $this->sanitizeUsername($username);
        $attemptsKey = 'attempts_' . $cleanUsername;
        
        if (!isset($_SESSION['login_attempts'][$attemptsKey])) {
            return 0;
        }
        
        $attemptData = $_SESSION['login_attempts'][$attemptsKey];
        $timeSinceLastAttempt = time() - $attemptData['last_attempt'];
        $timeRemaining = $this->lockoutTime - $timeSinceLastAttempt;
        
        return max(0, $timeRemaining);
    }

    // Validate session security
    public function validateSession() {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        // Check if user agent matches
        if (!isset($_SESSION['user_agent']) || $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
            $this->logout();
            return false;
        }
        
        // Check session age (optional: force re-login after certain time)
        $maxSessionAge = 8 * 60 * 60; // 8 hours
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time'] > $maxSessionAge)) {
            $this->logout();
            return false;
        }
        
        return true;
    }

    // Rest of your existing methods...
    // Check if user is logged in
    public function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }

    // Redirect to login if not logged in
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header("Location: /San%20Agustin/login.php");
            exit();
        }
    }

    // Redirect to dashboard based on role if logged in
    public function redirectIfLoggedIn() {
        if ($this->isLoggedIn()) {
            $role = $_SESSION['role'];
            header("Location: /San%20Agustin/$role/dashboard.php");
            exit();
        }
    }

    // Login user with security features (stage 2FA if enabled)
    public function login($username, $password) {
        // Check if too many login attempts
        if ($this->hasTooManyLoginAttempts($username)) {
            error_log("Too many login attempts for: " . $username);
            return false;
        }

        try {
            // Fetch user with email and 2FA fields
            $query = "SELECT id, username, password, role, email, 
                              COALESCE(twofa_enabled, 0) AS twofa_enabled,
                              twofa_code_hash, twofa_expires_at
                       FROM users WHERE username = :username LIMIT 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':username', $username);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $hashed_password = $row['password'];
                
                if (password_verify($password, $hashed_password)) {
                    // Check if password needs rehashing (if algorithm changed)
                    if (password_needs_rehash($hashed_password, PASSWORD_DEFAULT)) {
                        $this->updatePasswordHash($row['id'], $password);
                    }
                    // If 2FA is enabled, stage 2FA instead of finalizing login
                    if ((int)$row['twofa_enabled'] === 1) {
                        $code = $this->generateOneTimeCode();
                        $hash = password_hash($code, PASSWORD_DEFAULT);
                        $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes

                        // Store hash and expiry
                        $upd = $this->conn->prepare("UPDATE users SET twofa_code_hash = :h, twofa_expires_at = :e WHERE id = :id");
                        $upd->execute([':h' => $hash, ':e' => $expiresAt, ':id' => $row['id']]);

                        // Send email with code
                        Mailer::send2FACode($row['email'], $row['username'], $code);

                        // Set minimal session state for pending 2FA (do NOT mark as logged in yet)
                        session_regenerate_id(true);
                        $_SESSION['2fa_user_id'] = $row['id'];
                        $_SESSION['2fa_username'] = $row['username'];
                        $_SESSION['2fa_role'] = $row['role'];
                        $_SESSION['2fa_started_at'] = time();

                        // Clear login attempts on correct password
                        $this->clearLoginAttempts($username);

                        return '2fa_required';
                    }

                    // 2FA disabled: finalize login immediately
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $row['id'];
                    $_SESSION['username'] = $row['username'];
                    $_SESSION['role'] = $row['role'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
                    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];

                    $this->clearLoginAttempts($username);
                    $this->redirectToDashboard($row['role']);
                    return true;
                }
            }
            
            // Record failed attempt
            $this->recordLoginAttempt($username);
            return false;
            
        } catch(PDOException $e) {
            // Log the error instead of displaying it
            error_log("Login error: " . $e->getMessage());
            return false;
        }
    }

    // Rest of the methods remain the same...
    // Logout user
    public function logout() {
        // Regenerate session ID
        session_regenerate_id(true);
        
        // Unset all session variables
        $_SESSION = array();
        
        // Destroy the session cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        // Destroy the session
        session_destroy();
        
        // Redirect to login page
        header("Location: /San%20Agustin/login.php");
        exit();
    }

    // Check for too many login attempts
    public function hasTooManyLoginAttempts($username) {
        $cleanUsername = $this->sanitizeUsername($username);
        $attemptsKey = 'attempts_' . $cleanUsername;
        
        if (!isset($_SESSION['login_attempts'][$attemptsKey])) {
            return false;
        }
        
        $attemptData = $_SESSION['login_attempts'][$attemptsKey];
        
        // Check if lockout time has passed
        if (time() - $attemptData['last_attempt'] > $this->lockoutTime) {
            unset($_SESSION['login_attempts'][$attemptsKey]);
            return false;
        }
        
        return $attemptData['count'] >= $this->maxAttempts;
    }

    // Record login attempt
    public function recordLoginAttempt($username) {
        $cleanUsername = $this->sanitizeUsername($username);
        $attemptsKey = 'attempts_' . $cleanUsername;
        $currentTime = time();
        
        if (!isset($_SESSION['login_attempts'][$attemptsKey])) {
            $_SESSION['login_attempts'][$attemptsKey] = [
                'count' => 1,
                'last_attempt' => $currentTime,
                'first_attempt' => $currentTime
            ];
        } else {
            $_SESSION['login_attempts'][$attemptsKey]['count']++;
            $_SESSION['login_attempts'][$attemptsKey]['last_attempt'] = $currentTime;
        }
    }

    // Clear login attempts
    public function clearLoginAttempts($username) {
        $cleanUsername = $this->sanitizeUsername($username);
        $attemptsKey = 'attempts_' . $cleanUsername;
        
        if (isset($_SESSION['login_attempts'][$attemptsKey])) {
            unset($_SESSION['login_attempts'][$attemptsKey]);
        }
    }

    // Sanitize username for session keys
    private function sanitizeUsername($username) {
        return preg_replace('/[^a-zA-Z0-9_]/', '_', $username);
    }

    // Update password hash if needed
    private function updatePasswordHash($userId, $password) {
        try {
            $newHash = password_hash($password, PASSWORD_DEFAULT);
            $query = "UPDATE users SET password = :password WHERE id = :id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':password', $newHash);
            $stmt->bindParam(':id', $userId);
            $stmt->execute();
        } catch(PDOException $e) {
            error_log("Password update error: " . $e->getMessage());
        }
    }

    // Redirect to dashboard based on role
    private function redirectToDashboard($role) {
        // Validate role to prevent directory traversal
        $allowedRoles = ['student', 'teacher', 'librarian', 'registrar'];
        if (!in_array($role, $allowedRoles)) {
            header("Location: /San%20Agustin/login.php");
            exit();
        }
        
        $dashboardPath = "/San%20Agustin/$role/dashboard.php";
        header("Location: $dashboardPath");
        exit();
    }

    // Generate CSRF token
    public function generateCSRFToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    // Validate CSRF token
    public function validateCSRFToken($token) {
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            return false;
        }
        return true;
    }
}
?>