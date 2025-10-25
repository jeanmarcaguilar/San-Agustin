<?php
// Include session config FIRST
require_once 'includes/session_config.php';
require_once 'includes/auth.php';

$auth = new Auth();
$error = '';
$info = '';
$show2FA = false;

// If coming back from 2FA verify with an error, show it and keep 2FA form visible
if (!empty($_SESSION['twofa_error'])) {
    $error = $_SESSION['twofa_error'];
    $show2FA = true;
    unset($_SESSION['twofa_error']);
}

// ---------------- Security & Privacy Headers ----------------
header_remove("X-XSS-Protection"); // obsolete/ignored by modern browsers

// Content Security Policy (allows Tailwind CDN + Font Awesome CDN used below)
header("Content-Security-Policy: "
    . "default-src 'self'; "
    . "script-src 'self' https://cdn.tailwindcss.com; "
    . "style-src 'self' https://cdnjs.cloudflare.com 'unsafe-inline'; "
    . "img-src 'self' data: blob:; "
    . "font-src 'self' https://cdnjs.cloudflare.com data:; "
    . "object-src 'none'; "
    . "base-uri 'self'; "
    . "form-action 'self'; "
    . "frame-ancestors 'none'"
);
header("X-Frame-Options: DENY"); // redundant with frame-ancestors, harmless
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Only send HSTS if site is fully HTTPS (including subdomains)
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}

// Check if user is already logged in
$auth->redirectIfLoggedIn();

// Generate CSRF token safely
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Process login form submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['action']) && $_POST['action'] === 'login') {
    // Add CSRF protection (constant-time compare)
    $posted = $_POST['csrf_token'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (!$posted || !$sessionToken || !hash_equals($sessionToken, $posted)) {
        $error = 'Security validation failed. Please try again.';
        // Regenerate CSRF token after failed validation
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        usleep(400000); // add small delay
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
        
        // Input validation (no deprecated FILTER_SANITIZE_STRING; validate format instead)
        if ($username === '' || $password === '') {
            $error = 'Please enter both username and password.';
        } elseif (strlen($username) > 50 || strlen($password) > 100) {
            $error = 'Invalid input length.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]{1,50}$/', $username)) {
            $error = 'Invalid username format.';
        } else {
            // Login attempt limiting (keep messages generic to avoid enumeration)
            if ($auth->hasTooManyLoginAttempts($username)) {
                // Optional: if your Auth exposes remaining time, avoid revealing precise minutes
                $error = 'Too many login attempts. Please try again later.';
                usleep(400000);
            } else {
                $result = $auth->login($username, $password);
                if ($result === '2fa_required') {
                    // Stay on the page and show 2FA verification form
                    $show2FA = true;
                    $info = 'We sent a 6-digit verification code to your registered email. Please enter it below to continue.';
                } elseif ($result === true) {
                    // Login successful, reset attempts and rotate CSRF token, then redirect
                    if (method_exists($auth, 'clearLoginAttempts')) {
                        $auth->clearLoginAttempts($username);
                    }
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // rotate on success
                    exit();
                } else {
                    if (method_exists($auth, 'recordLoginAttempt')) {
                        $auth->recordLoginAttempt($username);
                    }
                    $error = 'Invalid username or password.';
                    usleep(400000); // slow brute force
                    // Regenerate CSRF token after failed attempt
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>San Agustin ES — Login Portal</title>

  <!-- Tailwind -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <style>
    :root {
      --primary: #0b6b4f;   /* San Agustin Green */
      --secondary: #facc15;  /* San Agustin Yellow */
      --accent: #60a5fa;     /* San Agustin Blue */
    }
    
    body { 
      font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; 
      background-color: #f8fafc;
    }
    
    .card { 
      background: white; 
      border-radius: 14px; 
      box-shadow: 0 4px 12px rgba(15,23,42,0.08); 
      border: 1px solid #e2e8f0;
    }
    
    .big-btn { 
      border-radius: 12px; 
      padding: 10px 16px; 
      font-weight: 600; 
      transition: all 0.2s ease; 
    }
    
    .big-btn:hover { 
      transform: translateY(-2px); 
      box-shadow: 0 4px 8px rgba(0,0,0,0.1);
    }
    
    /* Custom scrollbar */
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
    ::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 10px; }
    ::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }
    
    /* School colors */
    .bg-school-primary { background-color: #0b6b4f; }
    .bg-school-secondary { background-color: #facc15; }
    .bg-school-accent { background-color: #60a5fa; }
    .text-school-primary { color: #0b6b4f; }
    .text-school-secondary { color: #facc15; }
    .text-school-accent { color: #60a5fa; }
    
    /* Hero section */
    .hero-pattern {
      background-color: #0b6b4f;
      background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23facc15' fill-opacity='0.15'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
    }
    
    /* Form styles */
    .form-input {
      width: 100%;
      padding: 0.75rem 1rem;
      border: 1px solid rgba(255, 255, 255, 0.5);
      border-radius: 0.5rem;
      background-color: transparent !important;
      color: white !important;
      transition: all 0.2s ease;
    }
    
    .form-input::placeholder {
      color: rgba(255, 255, 255, 0.7) !important;
    }
    
    .form-input:focus {
      outline: none;
      box-shadow: 0 0 0 3px rgba(250, 204, 21, 0.3);
      border-color: #facc15;
      background-color: rgba(255, 255, 255, 0.1) !important;
    }
    
    .form-input option {
      background-color: #0b6b4f;
      color: white;
    }
    
    .fade-in {
      animation: fadeIn 0.5s ease-in-out;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    
    /* Error message */
    .error-message {
      background-color: #fee2e2;
      color: #b91c1c;
      padding: 0.75rem 1rem;
      border-radius: 0.5rem;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    
    .error-message i {
      font-size: 1.25rem;
    }
    
    /* Security notice */
    .security-notice {
      background-color: rgba(250, 204, 21, 0.1);
      border: 1px solid rgba(250, 204, 21, 0.3);
      color: #d97706;
      padding: 0.75rem 1rem;
      border-radius: 0.5rem;
      margin-top: 1rem;
      font-size: 0.875rem;
    }

    /* Privacy blocks */
    .privacy-note, .retention-policy {
      background-color: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.18);
      color: #e5e7eb; /* text-gray-200 */
      padding: 0.75rem 1rem;
      border-radius: 0.5rem;
      margin-top: 0.75rem;
      font-size: 0.75rem; /* text-xs */
      line-height: 1.4;
    }
    .privacy-note strong, .retention-policy strong { color: #fff; }
  </style>
  
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            school: {
              primary: '#0b6b4f',
              secondary: '#facc15',
              accent: '#60a5fa'
            }
          }
        }
      }
    }
    
    // Show login form
    function showLogin() {
      document.getElementById('loginForm').classList.remove('hidden');
      window.scrollTo({
        top: document.getElementById('loginForm').offsetTop - 100,
        behavior: 'smooth'
      });
    }
    
    // Show/hide password
    function togglePassword() {
      const passwordInput = document.getElementById('password');
      const icon = document.querySelector('.toggle-password i');
      
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
      } else {
        passwordInput.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
      }
    }
    
    // Auto-hide error messages after 5 seconds
    document.addEventListener('DOMContentLoaded', function() {
      const errorMessages = document.querySelectorAll('.error-message');
      errorMessages.forEach(function(error) {
        setTimeout(function() {
          error.style.opacity = '0';
          error.style.transition = 'opacity 0.5s ease';
          setTimeout(function() {
            error.remove();
          }, 500);
        }, 5000);
      });
    });
  </script>
</head>
<body class="min-h-screen bg-gray-50">
  <nav class="bg-school-primary text-white py-4 px-6 flex justify-between items-center">
    <div class="flex items-center gap-3">
      <img src="logo.jpg" alt="San Agustin ES Logo" class="w-10 h-10 rounded-full object-cover border border-white/20">
      <div class="text-lg font-extrabold">San Agustin Elementary</div>
    </div>
    
    <div class="hidden md:flex items-center gap-6">
      <a href="#features" class="hover:text-school-secondary transition-colors">Features</a>
      <a href="#about" class="hover:text-school-secondary transition-colors">About</a>
      <a href="#contact" class="hover:text-school-secondary transition-colors">Contact</a>
    </div>
    
    <div class="flex items-center gap-3">
      <button onclick="showLogin()" class="big-btn bg-white text-school-primary hover:bg-gray-100">
        <i class="fas fa-sign-in-alt mr-2"></i> Log In
      </button>
    </div>
  </nav>

  <!-- Hero Section -->
  <section class="hero-pattern text-white py-16 md:py-24 px-6">
    <div class="max-w-6xl mx-auto grid md:grid-cols-2 gap-10 items-center">
      <div class="space-y-6">
        <h1 class="text-4xl md:text-5xl font-bold leading-tight">
          Learning Made <span class="text-school-secondary">Fun</span> & Interactive
        </h1>
        <p class="text-xl opacity-90">
          San Agustin Elementary School's portal helps students, teachers, and staff manage their activities in one place.
        </p>
        <div class="flex flex-wrap gap-4 mt-8">
          <button onclick="showLogin()" class="big-btn bg-white/20 text-white hover:bg-white/30 text-lg px-6">
            Log In <i class="fas fa-graduation-cap ml-2"></i>
          </button>
        </div>
      </div>
      <div id="loginForm" class="card p-6 bg-white/10 backdrop-blur-sm border border-white/20">
        <div class="flex justify-center mb-6">
          <img src="logo.jpg" alt="San Agustin ES Logo" class="w-20 h-20 rounded-full object-cover border-4 border-white/30 mx-auto">
        </div>
        
        <?php if (!empty($error)): ?>
          <div class="error-message">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
          </div>
        <?php endif; ?>
        <?php if (!empty($info)): ?>
          <div class="security-notice">
            <i class="fas fa-shield-alt mr-1"></i>
            <span><?php echo htmlspecialchars($info, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></span>
          </div>
        <?php endif; ?>
        
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" method="post" class="space-y-4" autocomplete="on" <?php echo $show2FA ? 'style="display:none"' : '';?>>
          <input type="hidden" name="action" value="login">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <h3 class="text-2xl font-bold text-center mb-2">Welcome Back!</h3>
          <div>
            <label class="block text-sm font-medium mb-1">Username</label>
            <input type="text" name="username" class="form-input" placeholder="Enter your username" required maxlength="50" autocomplete="username">
          </div>
          <div class="relative">
            <label class="block text-sm font-medium mb-1">Password</label>
            <input type="password" id="password" name="password" class="form-input pr-10" placeholder="Enter your password" required maxlength="100" autocomplete="current-password">
            <button type="button" class="absolute right-3 bottom-3 text-white/70 hover:text-white focus:outline-none" onclick="togglePassword()">
              <i class="fas fa-eye toggle-password"></i>
            </button>
          </div>
          <div class="flex items-center justify-between text-sm">
            <label class="flex items-center">
              <input type="checkbox" class="rounded text-school-primary focus:ring-school-primary">
              <span class="ml-2">Remember me</span>
            </label>
            <a href="#" class="text-school-secondary hover:underline">Forgot password?</a>
          </div>
          <button type="submit" class="w-full big-btn bg-school-secondary text-school-primary hover:bg-yellow-400 font-semibold py-3">
            Log In <i class="fas fa-sign-in-alt ml-2"></i>
          </button>
          
          

          <!-- Privacy Notice -->
          <div class="privacy-note">
            <i class="fas fa-info-circle mr-1"></i>
            <strong>Privacy Notice:</strong>
            Your login information is processed securely and used solely for authentication within the San Agustin ES Portal.
            We do not share, sell, or transmit your data to third parties. Only essential technical data (IP address, browser
            user-agent, and timestamps) may be stored temporarily for security monitoring.
          </div>

          
        </form>

        <!-- 2FA Verification Form -->
        <?php if ($show2FA || (!empty($_SESSION['2fa_user_id']))): ?>
        <form action="verify_2fa.php" method="post" class="space-y-4 mt-6" autocomplete="off">
          <input type="hidden" name="action" value="verify_2fa">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
          <h3 class="text-2xl font-bold text-center mb-2">Two-Factor Verification</h3>
          <p class="text-sm text-gray-700 text-center">Enter the 6-digit code sent to your email.</p>
          <div>
            <label class="block text-sm font-medium mb-1">Verification Code</label>
            <input type="text" inputmode="numeric" pattern="[0-9]{6}" name="code" class="form-input bg-white text-gray-900" placeholder="123456" required maxlength="6">
          </div>
          <button type="submit" class="w-full big-btn bg-school-secondary text-school-primary hover:bg-yellow-400 font-semibold py-3">
            Verify Code <i class="fas fa-shield-alt ml-2"></i>
          </button>
          <div class="retention-policy">
            <strong>Data Privacy:</strong> Your 2FA code is stored as a salted hash and expires after 10 minutes. It is used only to verify your login.
          </div>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <!-- Features Section -->
  <section id="features" class="py-16 bg-white px-6">
    <div class="max-w-6xl mx-auto">
      <h2 class="text-3xl md:text-4xl font-bold text-center text-school-primary mb-4">Portal Features</h2>
      <p class="text-xl text-gray-600 text-center max-w-3xl mx-auto mb-12">
        Our portal is designed to make school management easy and efficient for everyone.
      </p>
      <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-8">
        <div class="card p-6 text-center hover:shadow-lg transition-shadow">
          <div class="w-16 h-16 rounded-full bg-school-primary/10 flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-tasks text-school-primary text-2xl"></i>
          </div>
          <h3 class="text-xl font-bold mb-2">For Students</h3>
          <p class="text-gray-600">View your grades, assignments, and class schedules in one place.</p>
        </div>
        <div class="card p-6 text-center hover:shadow-lg transition-shadow">
          <div class="w-16 h-16 rounded-full bg-school-primary/10 flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-chalkboard-teacher text-school-primary text-2xl"></i>
          </div>
          <h3 class="text-xl font-bold mb-2">For Teachers</h3>
          <p class="text-gray-600">Manage your classes, record grades, and communicate with students.</p>
        </div>
        <div class="card p-6 text-center hover:shadow-lg transition-shadow">
          <div class="w-16 h-16 rounded-full bg-school-primary/10 flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-book text-school-primary text-2xl"></i>
          </div>
          <h3 class="text-xl font-bold mb-2">For Librarians</h3>
          <p class="text-gray-600">Manage the school library, track books, and handle checkouts.</p>
        </div>
      </div>
    </div>
  </section>

  <!-- Footer -->
  <footer class="bg-gray-800 text-white py-12 px-6">
    <div class="max-w-6xl mx-auto grid md:grid-cols-4 gap-8">
      <div>
        <div class="flex items-center gap-3 mb-4">
          <img src="logo.jpg" alt="San Agustin ES Logo" class="w-10 h-10 rounded-full object-cover border border-gray-700">
          <span class="text-xl font-bold">San Agustin ES</span>
        </div>
        <p class="text-gray-400 text-sm">Empowering students through quality education since 1960.</p>
      </div>
      <div>
        <h4 class="text-lg font-semibold mb-4">Quick Links</h4>
        <ul class="space-y-2">
          <li><a href="#" class="text-gray-400 hover:text-white transition-colors">Home</a></li>
          <li><a href="#features" class="text-gray-400 hover:text-white transition-colors">Features</a></li>
          <li><a href="#" class="text-gray-400 hover:text-white transition-colors">About Us</a></li>
          <li><a href="#" class="text-gray-400 hover:text-white transition-colors">Contact</a></li>
        </ul>
      </div>
      <div>
        <h4 class="text-lg font-semibold mb-4">Contact Us</h4>
        <ul class="space-y-2 text-gray-400">
          <li class="flex items-start gap-2">
            <i class="fas fa-map-marker-alt mt-1"></i>
            <span>123 School St, San Agustin, Philippines</span>
          </li>
          <li class="flex items-center gap-2">
            <i class="fas fa-phone"></i>
            <span>+63 123 456 7890</span>
          </li>
          <li class="flex items-center gap-2">
            <i class="fas fa-envelope"></i>
            <span>info@sanagustines.edu.ph</span>
          </li>
        </ul>
      </div>
      <div>
        <h4 class="text-lg font-semibold mb-4">Follow Us</h4>
        <div class="flex gap-4">
          <a href="#" class="w-10 h-10 rounded-full bg-gray-700 flex items-center justify-center hover:bg-school-primary transition-colors">
            <i class="fab fa-facebook-f"></i>
          </a>
          <a href="#" class="w-10 h-10 rounded-full bg-gray-700 flex items-center justify-center hover:bg-school-primary transition-colors">
            <i class="fab fa-twitter"></i>
          </a>
          <a href="#" class="w-10 h-10 rounded-full bg-gray-700 flex items-center justify-center hover:bg-school-primary transition-colors">
            <i class="fab fa-instagram"></i>
          </a>
          <a href="#" class="w-10 h-10 rounded-full bg-gray-700 flex items-center justify-center hover:bg-school-primary transition-colors">
            <i class="fab fa-youtube"></i>
          </a>
        </div>
      </div>
    </div>
    <div class="border-t border-gray-700 mt-12 pt-8 text-center text-gray-400 text-sm">
      <p>&copy; <?php echo date('Y'); ?> San Agustin Elementary School. All rights reserved.</p>
    </div>
  </footer>
</body>
</html>
