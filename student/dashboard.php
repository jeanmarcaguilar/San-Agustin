<?php
// Add secure session configuration FIRST
require_once '../includes/session_config.php';
require_once '../includes/auth.php';

$auth = new Auth();

// Redirect to login if not authenticated as student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    $auth->logout();
    header('Location: /San%20Agustin/login.php');
    exit();
}

// Database configuration
require_once '../config/database.php';
$database = new Database();

// Get student data
$user_id = $_SESSION['user_id'];

// First, try to get basic user data from login_db
$loginConn = $database->getConnection('');
$query = "SELECT id, username, email, role FROM users WHERE id = ? AND role = 'student' LIMIT 1";
$stmt = $loginConn->prepare($query);
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // User not found or not a student, log them out
    $auth->logout();
    header('Location: /San%20Agustin/login.php');
    exit();
}

// Initialize default user data
$user_data = [
    'id' => $user['id'],
    'username' => $user['username'],
    'email' => $user['email'],
    'first_name' => ucfirst($user['username']), // Use username as fallback for first name
    'last_name' => '',
    'grade_level' => 'N/A',
    'section' => 'N/A',
    'student_id' => 'N/A'
];

// Try to get additional student data from student_db
try {
    $studentConn = $database->getConnection('student');
    $query = "SELECT * FROM students WHERE user_id = ? LIMIT 1";
    $stmt = $studentConn->prepare($query);
    $stmt->execute([$user_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($student) {
        // Merge student data with user data if available
        $user_data = array_merge($user_data, [
            'first_name' => $student['first_name'] ?? $user_data['first_name'],
            'last_name' => $student['last_name'] ?? $user_data['last_name'],
            'grade_level' => $student['grade_level'] ?? $student['grade'] ?? $user_data['grade_level'],
            'section' => $student['section'] ?? $student['class_section'] ?? $user_data['section'],
            'student_id' => $student['student_id'] ?? $user_data['student_id']
        ]);
    }
} catch (PDOException $e) {
    // If student database is not accessible, continue with basic user data
    error_log("Failed to fetch student data: " . $e->getMessage());
}

// Update session data
$_SESSION['first_name'] = $user_data['first_name'];
$_SESSION['last_name'] = $user_data['last_name'];
$_SESSION['grade_level'] = $user_data['grade_level'];
$_SESSION['section'] = $user_data['section'];

// Set variables for the view
$full_name = htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']);
$initials = !empty($user_data['first_name']) ? strtoupper(substr($user_data['first_name'], 0, 1)) : 'S';
$initials .= !empty($user_data['last_name']) ? strtoupper(substr($user_data['last_name'], 0, 1)) : 'T';
$grade_level = $user_data['grade_level'];
$section = $user_data['section'];
$username = $user_data['username'];
$student_id_display = $user_data['student_id'] ?? $user_data['id'] ?? 'N/A';
// Provide a default school year to avoid undefined variable notices in the script section
// Format: YYYY-YYYY (e.g., 2025-2026)
if (!isset($school_year) || empty($school_year)) {
    $currentYear = (int)date('Y');
    $school_year = $currentYear . '-' . ($currentYear + 1);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>San Agustin Elementary School Student Portal</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #0b6b4f;
      --secondary: #facc15;
      --accent: #60a5fa;
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
    
    .confetti {
      position: fixed;
      width: 10px;
      height: 14px;
      opacity: 0.95;
      z-index: 9999;
      pointer-events: none;
      animation: fall linear forwards;
    }
    
    @keyframes fall {
      to { transform: translateY(100vh) rotate(360deg); opacity: 0; }
    }
    
    .pet-img { 
      width: 120px; 
      height: 120px; 
      object-fit: contain; 
    }
    
    ::-webkit-scrollbar { width: 8px; }
    ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
    ::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 10px; }
    ::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }
    
    .bg-school-primary { background-color: #0b6b4f; }
    .bg-school-secondary { background-color: #facc15; }
    .bg-school-accent { background-color: #60a5fa; }
    .text-school-primary { color: #0b6b4f; }
    .text-school-secondary { color: #facc15; }
    .text-school-accent { color: #60a5fa; }
    
    .dropdown-content {
      display: none;
      position: absolute;
      background-color: white;
      min-width: 200px;
      box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
      z-index: 1;
      border-radius: 8px;
      overflow: hidden;
    }
    
    .dropdown:hover .dropdown-content {
      display: block;
    }
    
    .line-clamp-2 {
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
    }
    
    .collapsible-content {
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.3s ease-out;
    }
    
    .collapsible-arrow {
      transition: transform 0.3s ease;
    }
    
    .footer-link {
      transition: all 0.2s ease;
    }
    
    .footer-link:hover {
      color: var(--secondary);
      transform: translateY(-2px);
    }
    
    .search-input:focus {
      box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.3);
    }
    
    .toast {
      background: white;
      border-radius: 8px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
      padding: 12px 16px;
      display: flex;
      align-items: center;
      gap: 8px;
      border-left: 4px solid;
      opacity: 0;
      transform: translateX(20px);
      transition: all 0.3s ease;
      max-width: 350px;
      min-width: 250px;
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 1000;
    }
    
    .toast.show {
      opacity: 1;
      transform: translateX(0);
    }
    
    .toast.success {
      border-left-color: var(--primary);
    }
    
    .toast.info {
      border-left-color: var(--accent);
    }
    
    .toast.warning {
      border-left-color: var(--secondary);
    }
    
    .toast.error {
      border-left-color: #ef4444;
    }
    
    .toast .toast-icon {
      font-size: 1.2rem;
    }
    
    .toast .toast-message {
      flex: 1;
      font-size: 0.875rem;
      color: #1f2937;
    }
    
    .toast .toast-close {
      cursor: pointer;
      color: #6b7280;
      font-size: 1rem;
      transition: color 0.2s ease;
    }
    
    .toast .toast-close:hover {
      color: #1f2937;
    }

    .active-nav {
      background-color: #34d399;
      color: #1f2937;
    }
    .active-nav:hover {
      background-color: #2dd4bf;
    }
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
  </script>
</head>
<body class="min-h-screen bg-gray-50">
  <div class="min-h-screen flex">
    <aside class="hidden md:flex md:flex-col w-72 p-5 bg-school-primary text-white">
      <div class="flex items-center gap-3 mb-6">
        <div class="w-14 h-14 rounded-full bg-white flex items-center justify-center">
          <img src="logo.jpg" alt="San Agustin ES Logo" class="w-full h-full object-contain rounded-full">
        </div>
  
  <!-- Sign Out Modal -->
  <div id="signoutModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-[10001] p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md border">
      <div class="p-4 border-b">
        <h3 class="text-lg font-bold text-red-600 flex items-center gap-2"><i class="fas fa-sign-out-alt"></i> Sign Out</h3>
      </div>
      <div class="p-4 text-gray-700">
        Are you sure you want to sign out?
      </div>
      <div class="p-4 flex justify-end gap-2 border-t">
        <button id="cancelSignout" class="px-4 py-2 rounded-lg border hover:bg-gray-50">Cancel</button>
        <button id="confirmSignout" class="px-4 py-2 rounded-lg bg-red-600 text-white hover:bg-red-500">Sign Out</button>
      </div>
    </div>
  </div>
  
  <!-- Settings Modal -->
  <div id="settingsModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-[10001] p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl border">
      <div class="flex items-center justify-between p-4 border-b">
        <h3 class="text-lg font-bold text-school-primary flex items-center gap-2"><i class="fas fa-user-cog"></i> Student Information</h3>
        <button id="closeSettings" class="p-2 rounded-full hover:bg-gray-100" aria-label="Close settings"><i class="fas fa-times"></i></button>
      </div>
      <form id="settingsForm" class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm text-black mb-1">First Name</label>
          <input id="set_first_name" type="text" class="w-full border rounded-lg px-3 py-2 text-black" required>
        </div>
        <div>
          <label class="block text-sm text-black mb-1">Last Name</label>
          <input id="set_last_name" type="text" class="w-full border rounded-lg px-3 py-2 text-black" required>
        </div>
        <div>
          <label class="block text-sm text-black mb-1">Student ID</label>
          <input id="set_student_id" type="text" class="w-full border rounded-lg px-3 py-2 text-black">
        </div>
        <div>
          <label class="block text-sm text-black mb-1">School Year</label>
          <input id="set_school_year" type="text" class="w-full border rounded-lg px-3 py-2 text-black" placeholder="2025-2026">
        </div>
        <div>
          <label class="block text-sm text-black mb-1">Grade Level</label>
          <input id="set_grade_level" type="number" min="1" max="12" class="w-full border rounded-lg px-3 py-2 text-black">
        </div>
        <div>
          <label class="block text-sm text-black mb-1">Section</label>
          <input id="set_section" type="text" class="w-full border rounded-lg px-3 py-2 text-black">
        </div>
        <div>
          <label class="block text-sm text-black mb-1">Birthdate</label>
          <input id="set_birthdate" type="date" class="w-full border rounded-lg px-3 py-2 text-black">
        </div>
        <div>
          <label class="block text-sm text-black mb-1">Gender</label>
          <select id="set_gender" class="w-full border rounded-lg px-3 py-2 text-black">
            <option value="">Select</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
          </select>
        </div>
        <div class="md:col-span-2">
          <label class="block text-sm text-black mb-1">Address</label>
          <input id="set_address" type="text" class="w-full border rounded-lg px-3 py-2 text-black">
        </div>
        <div>
          <label class="block text-sm text-black mb-1">Parent/Guardian Name</label>
          <input id="set_parent_name" type="text" class="w-full border rounded-lg px-3 py-2 text-black">
        </div>
        <div>
          <label class="block text-sm text-black mb-1">Parent Contact</label>
          <input id="set_parent_contact" type="text" class="w-full border rounded-lg px-3 py-2 text-black">
        </div>
        <div class="md:col-span-2 flex items-center justify-end gap-2 pt-2 border-t mt-2">
          <button type="button" id="cancelSettings" class="px-4 py-2 rounded-lg border hover:bg-gray-50">Cancel</button>
          <button type="submit" class="px-4 py-2 rounded-lg bg-school-primary text-white hover:bg-green-700 flex items-center gap-2"><i class="fas fa-save"></i> Save</button>
        </div>
      </form>
    </div>
  </div>
        <div>
          <div class="text-lg font-extrabold">San Agustin Elementary School</div>
          <div class="text-xs text-white/80">Student Portal</div>
        </div>
      </div>
      <div class="bg-white/10 p-3 rounded-xl mb-4 flex items-center gap-3">
        <div class="w-12 h-12 rounded-full bg-school-secondary text-school-primary font-bold flex items-center justify-center">
          <?php echo $initials; ?>
        </div>
        <div>
          <div class="font-semibold"><?php echo htmlspecialchars($user_data['first_name'] . ' ' . $user_data['last_name']); ?></div>
          <div class="text-xs text-white/80">
            <?php if ($user_data['grade_level'] !== 'N/A' && $user_data['section'] !== 'N/A'): ?>
              Grade <?php echo htmlspecialchars($user_data['grade_level']); ?> • 
              Section <?php echo htmlspecialchars($user_data['section']); ?>
            <?php else: ?>
              Grade and section not set
            <?php endif; ?>
          </div>
        </div>
      </div>
      <nav class="mt-3 flex-1 space-y-2">
        <a id="navDashboard" href="dashboard.php" class="w-full big-btn bg-green-600 hover:bg-green-500 text-white flex items-center gap-2 px-3 py-3 active-nav">
          <i class="fas fa-home"></i> Dashboard
        </a>
        <a id="navSchedule" href="class_schedule.php" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center gap-2 px-3 py-3">
          <i class="fas fa-calendar"></i> Schedule
        </a>
        <div class="collapsible-section">
          <button id="booksBtn" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center justify-between px-3 py-3">
            <span class="flex items-center gap-2"><i class="fas fa-book"></i> Library Books</span>
            <span class="collapsible-arrow">▼</span>
          </button>
          <div class="collapsible-content bg-green-900 rounded-lg mt-1 overflow-hidden">
            <a href="available-books.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors">
              <i class="fas fa-book-open mr-2"></i> Available Books
            </a>
            <a href="borrow-history.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors">
              <i class="fas fa-history mr-2"></i> My Borrowed Books
            </a>
            <a href="return-books.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors">
              <i class="fas fa-undo mr-2"></i> Return Books
            </a>
            <a href="recommendations.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors">
              <i class="fas fa-star mr-2"></i> Recommendations
            </a>
          </div>
        </div>
        <a id="navGrades" href="grades.php" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center gap-3 px-3 py-3 rounded">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M9 17V9m4 8v-5m4 5V5" />
          </svg>
          <span>Grades</span>
        </a>
        <a id="navAnnouncements" href="announcements.php" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center gap-3 px-3 py-3 rounded">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11V5l7-2v14l-7-2v-6m0 6a4 4 0 01-8 0V7a4 4 0 018 0" />
          </svg>
          <span>Announcements</span>
        </a>
      </nav>
      <div class="mt-4">
        <a href="/San%20Agustin/logout.php" id="logout" class="w-full bg-red-600 hover:bg-red-500 big-btn flex items-center justify-center gap-2 px-3 py-3 rounded">
          <i class="fas fa-sign-out-alt"></i> Sign Out
        </a>
      </div>
    </aside>
    <!-- Main Content -->
    <main class="flex-1 p-5">
      <header class="flex items-center justify-between mb-6 bg-white p-4 rounded-xl shadow-sm border border-gray-200">
        <div class="flex items-center gap-4">
          <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg bg-school-primary text-white">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
          <h1 class="text-2xl md:text-3xl font-extrabold text-school-primary flex items-center gap-2">
            <i class="fas fa-graduation-cap"></i>San Agustin <br>Elementary School <br>Student Portal
          </h1>
          <span class="hidden md:inline text-sm text-gray-600">
            Welcome back, <?php echo htmlspecialchars($user_data['first_name']); ?>!
          </span>
        </div>
        <div class="flex items-center gap-3">
          <button id="settingsBtn" class="p-2 rounded-full bg-white border hover:bg-gray-50" title="Student Settings">
            <i class="fas fa-cog text-gray-700"></i>
          </button>
          <div class="hidden sm:flex items-center bg-white rounded-full border px-3 py-1 shadow-sm">
            <span class="text-sm text-green-600 font-medium mr-2"><i class="fas fa-circle animate-pulse"></i> Online</span>
            <span id="onlineCount" class="text-xs text-gray-500">24 students online</span>
          </div>
          <div class="relative">
            <input id="search" aria-label="Search" class="hidden sm:inline px-4 py-2 rounded-full border w-64 search-input pl-10" placeholder="Search portal..." />
            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
          </div>
          <button id="themeBtn" class="p-2 rounded-full bg-school-accent text-white" title="Change Theme">
            <i class="fas fa-palette"></i>
          </button>
          <button id="notifBtn" class="p-2 rounded-full bg-school-secondary relative" title="Notifications">
            <i class="fas fa-bell"></i>
            <span id="notifCount" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">3</span>
          </button>
          <div class="flex items-center gap-2 p-2 rounded-full bg-white shadow-sm border">
            <img src="https://i.pravatar.cc/40?img=5" alt="profile" class="w-9 h-9 rounded-full">
            <div class="hidden sm:block text-sm">
              <div class="font-semibold text-school-primary">
                <?php 
                  $fullName = trim($user_data['first_name'] . ' ' . $user_data['last_name']);
                  echo htmlspecialchars(!empty($fullName) ? $fullName : 'Student');
                ?>
              </div>
              <div class="text-xs text-gray-500">
                <?php if (!empty($grade_level) && $grade_level !== 'N/A'): ?>
                  Grade <?php echo htmlspecialchars($grade_level); ?> - 
                  Section <?php echo htmlspecialchars($section); ?>
                <?php else: ?>
                  Grade and section not set
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </header>
      <!-- Notification Dropdown -->
      <div id="notificationDropdown" class="hidden absolute right-4 top-28 z-50 w-80 bg-white rounded-lg shadow-lg p-4 border">
        <h3 class="font-bold text-lg mb-2 text-school-primary flex items-center gap-2"><i class="fas fa-bell"></i> Notifications</h3>
        <ul class="space-y-2">
          <li class="p-2 bg-green-50 rounded border flex items-center gap-2"><i class="fas fa-trophy text-green-500"></i> You earned a badge!</li>
          <li class="p-2 bg-yellow-50 rounded border flex items-center gap-2"><i class="fas fa-calendar-day text-yellow-500"></i> School event tomorrow</li>
          <li class="p-2 bg-red-50 rounded border flex items-center gap-2"><i class="fas fa-tasks text-red-500"></i> Math assignment due tomorrow</li>
        </ul>
        <button class="mt-3 text-sm text-school-accent font-medium flex items-center gap-1"><i class="fas fa-check-circle"></i> Mark all as read</button>
      </div>
      <!-- Main Content Grid -->
      <section class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="card p-5 flex flex-col justify-between">
          <div>
            <h2 class="text-xl font-bold text-school-primary flex items-center gap-2"><i class="fas fa-sparkle"></i> Daily Motivation</h2>
            <p id="motivationText" class="mt-2 text-gray-700 text-lg">Education is the most powerful weapon which you can use to change the world. - Nelson Mandela</p>
          </div>
          <div class="mt-4 flex gap-3">
            <button id="themeBtnSecondary" class="big-btn bg-school-accent hover:bg-blue-500 text-white flex items-center gap-2"><i class="fas fa-palette"></i> Change Theme</button>
            <button id="confettiTest" class="big-btn bg-pink-400 hover:bg-pink-500 text-white flex items-center gap-2"><i class="fas fa-party-horn"></i> Celebrate</button>
          </div>
        </div>
        <div id="starsSection" class="card p-5 flex flex-col items-center justify-center cursor-pointer hover:shadow-lg transition-shadow">
          <h3 class="text-lg font-bold text-school-secondary flex items-center gap-2"><i class="fas fa-star"></i> My Stars</h3>
          <div class="text-6xl font-extrabold text-school-secondary mt-2" id="starCount"></div>
          <div class="mt-3 flex gap-2">
            <button id="earnStarBtn" class="big-btn bg-school-primary hover:bg-green-700 text-white flex items-center gap-2"><i class="fas fa-plus"></i> Earn Star</button>
            <button id="spendStarBtn" class="big-btn bg-amber-400 hover:bg-amber-500 text-white flex items-center gap-2"><i class="fas fa-gift"></i> Use Star</button>
          </div>
          <p class="mt-2 text-xs text-gray-500 flex items-center gap-1"><i class="fas fa-info-circle"></i> Stars saved locally</p>
        </div>
        <div class="card p-5">
          <h3 class="text-lg font-bold text-school-primary flex items-center gap-2"><i class="fas fa-book-open"></i> Modules</h3>
          <ul id="moduleList" class="mt-3 space-y-3 max-h-60 overflow-y-auto"></ul>
          <button id="completeSelectedModule" class="mt-4 big-btn bg-school-primary hover:bg-green-700 text-white w-full flex items-center justify-center gap-2">
            <i class="fas fa-check-circle"></i> Mark Selected Done (+star)
          </button>
        </div>
      </section>
      <section class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
        <div class="lg:col-span-2 space-y-6">
          <div class="card p-5 bg-yellow-50 border-2 border-school-secondary shadow-lg">
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-lg font-bold text-school-primary flex items-center gap-2">
                <i class="fas fa-bullhorn"></i> Latest Announcements
              </h3>
              <a href="announcements.php" class="text-sm text-school-accent hover:underline flex items-center gap-1">
                View All <i class="fas fa-arrow-right"></i>
              </a>
            </div>
            <div id="dashboardAnnouncements" class="mt-3 space-y-3 max-h-80 overflow-y-auto">
              <!-- Loading state -->
              <div class="flex items-center justify-center py-8">
                <i class="fas fa-spinner fa-spin text-2xl text-school-primary"></i>
                <span class="ml-2 text-gray-600">Loading announcements...</span>
              </div>
            </div>
          </div>
          <div class="card p-5">
            <h3 class="text-lg font-bold text-school-primary flex items-center gap-2"><i class="fas fa-chart-line"></i> Grades Overview</h3>
            <canvas id="gradesChart" class="mt-2"></canvas>
            <div class="mt-3 grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div class="p-3 rounded-lg bg-blue-50 text-center border">
                <div class="text-sm text-gray-600"><i class="fas fa-calculator"></i> Average</div>
                <div id="avgGrade" class="font-bold text-school-primary text-2xl">85%</div>
              </div>
              <div class="p-3 rounded-lg bg-green-50 text-center border">
                <div class="text-sm text-gray-600"><i class="fas fa-trophy"></i> Best Subject</div>
                <div id="bestSub" class="font-bold text-school-primary text-2xl">Math</div>
              </div>
              <div class="p-3 rounded-lg bg-yellow-50 text-center border">
                <div class="text-sm text-gray-600"><i class="fas fa-medal"></i> Badges</div>
                <div id="badgeList" class="font-bold text-school-primary text-2xl">3 🏅</div>
              </div>
            </div>
            <a href="grades.php" class="mt-4 big-btn bg-school-primary hover:bg-green-700 text-white flex items-center justify-center gap-2">
              <i class="fas fa-eye"></i> View All Grades
            </a>
          </div>
        </div>
        <div class="space-y-6">
          <div class="card p-5">
            <div id="attendanceSection">
              <h4 class="text-md font-semibold text-school-primary flex items-center gap-2"><i class="fas fa-calendar-check"></i> Attendance</h4>
              <div class="mt-4 flex flex-col gap-4">
                <div class="flex justify-center">
                  <div style="width: 200px; height: 200px;">
                    <canvas id="attendanceChart"></canvas>
                  </div>
                </div>
                <div class="space-y-3">
                  <div>
                    <div class="flex justify-between text-sm text-gray-600 mb-1">
                      <span class="flex items-center gap-1"><i class="fas fa-check-circle text-green-500"></i>Present</span>
                      <span id="presentPercent">80%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-4">
                      <div id="attendanceBar" class="h-4 rounded-full bg-green-500 transition-all duration-500" style="width: 80%"></div>
                    </div>
                  </div>
                  <div>
                    <div class="flex justify-between text-sm text-gray-600 mb-1">
                      <span class="flex items-center gap-1"><i class="fas fa-times-circle text-red-400"></i>Absent</span>
                      <span id="absentPercent">20%</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-4">
                      <div id="absentBar" class="h-4 rounded-full bg-red-400 transition-all duration-500" style="width: 20%"></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="card p-5 flex items-center gap-4">
            <img id="pet" class="pet-img rounded-xl" src="https://img.icons8.com/color/96/000000/rabbit.png" alt="Pet / Mascot">
            <div>
              <div id="petStatus" class="font-bold text-school-primary">Hi! I'm Buddy 🐰</div>
              <div id="petHint" class="text-sm text-gray-600">I get happy when you earn stars!</div>
              <button id="petInteract" class="mt-2 text-sm bg-purple-100 text-purple-800 px-3 py-1 rounded-lg border flex items-center gap-1"><i class="fas fa-gamepad"></i> Play with me</button>
            </div>
          </div>
        </div>
      </section>
      <!-- Footer -->
      <footer class="mt-8 text-center border-t pt-6 pb-4 bg-white rounded-xl shadow-sm">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
          <div class="text-center md:text-left">
            <h4 class="font-bold text-school-primary mb-2">San Agustin Elementary School <br>Student Portal</h4>
            <p class="text-sm text-gray-600">Where learning is fun and exciting!</p>
          </div>
          <div>
            <h4 class="font-bold text-school-primary mb-2">Quick Links</h4>
            <div class="flex flex-col md:flex-row justify-center gap-4 text-sm">
              <a href="#" class="footer-link text-gray-600"><i class="fas fa-home mr-1"></i> Home</a>
              <a href="#" class="footer-link text-gray-600"><i class="fas fa-info-circle mr-1"></i> About</a>
              <a href="#" class="footer-link text-gray-600"><i class="fas fa-envelope mr-1"></i> Contact</a>
            </div>
          </div>
          <div>
            <h4 class="font-bold text-school-primary mb-2">Connect With Us</h4>
            <div class="flex justify-center md:justify-center gap-4">
              <a href="#" class="footer-link text-gray-600 text-xl"><i class="fab fa-facebook"></i></a>
              <a href="#" class="footer-link text-gray-600 text-xl"><i class="fab fa-twitter"></i></a>
              <a href="#" class="footer-link text-gray-600 text-xl"><i class="fab fa-instagram"></i></a>
              <a href="#" class="footer-link text-gray-600 text-xl"><i class="fas fa-envelope"></i></a>
            </div>
          </div>
        </div>
        <div class="text-sm text-gray-500 border-t pt-3">
          © 2025 San Agustin Elementary School Student Portal • Learning is Fun!
        </div>
      </footer>
    </main>
  </div>
  <div id="confettiRoot">
    <div id="toastContainer" class="fixed top-4 right-4 z-[10000] space-y-2"></div>
  </div>
  <script>
    // Student data from session
    const userData = {
      name: "<?php echo $full_name; ?>",
      initials: "<?php echo $initials; ?>",
      grade: "<?php echo htmlspecialchars($grade_level); ?>",
      studentId: "<?php echo htmlspecialchars($student_id_display); ?>",
      schoolYear: "<?php echo htmlspecialchars($school_year); ?>",
      section: "<?php echo htmlspecialchars($section); ?>",
      stars: 10,
      theme: "default",
      books_expanded: 0
    };

    // Merge any locally saved student info for display purposes
    const savedStudentInfo = (() => {
      try { return JSON.parse(localStorage.getItem('student_info') || 'null'); } catch { return null; }
    })();
    if (savedStudentInfo) {
      userData.name = `${savedStudentInfo.first_name || userData.name.split(' ')[0]} ${savedStudentInfo.last_name || userData.name.split(' ')[1] || ''}`.trim();
      userData.grade = savedStudentInfo.grade_level || userData.grade;
      userData.section = savedStudentInfo.section || userData.section;
      userData.studentId = savedStudentInfo.student_id || userData.studentId;
      userData.schoolYear = savedStudentInfo.school_year || userData.schoolYear;
    }

    const announcements = [
      { img: "https://via.placeholder.com/150", text: "School fair next week!" },
      { img: "https://via.placeholder.com/150", text: "Parent-teacher meeting on Friday." }
    ];

    const grades = {
      labels: ["Math", "Science", "English"],
      data: [85, 90, 80]
    };

    const attendance = {
      present: 16,
      absent: 4
    };

    const badges = ["Math Whiz", "Science Star", "Perfect Attendance"];

    // Load persisted data or use defaults
    let stars = localStorage.getItem('stars') !== null ? parseInt(localStorage.getItem('stars')) : userData.stars;
    let modules = localStorage.getItem('modules') !== null ? JSON.parse(localStorage.getItem('modules')) : [
      { id: 1, title: "Math Basics", description: "Introduction to algebra", done: false },
      { id: 2, title: "Science Experiment", description: "Water cycle project", done: true }
    ];

    // Render modules dynamically
    function renderModules() {
      const moduleList = document.getElementById('moduleList');
      moduleList.innerHTML = '';
      modules.forEach(module => {
        const li = document.createElement('li');
        li.className = `p-3 rounded-lg border flex justify-between items-center ${module.done ? 'bg-green-50' : 'bg-white'}`;
        li.innerHTML = `
          <div class="flex items-center gap-2">
            <input type="checkbox" class="module-checkbox" data-id="${module.id}" ${module.done ? 'disabled' : ''}>
            <div>
              <div class="font-medium">${module.title}</div>
              <div class="text-sm text-gray-600">${module.description}</div>
            </div>
          </div>
          <span class="text-sm ${module.done ? 'text-green-500' : 'text-gray-500'}">
            ${module.done ? '<i class="fas fa-check-circle"></i> Done' : 'Pending'}
          </span>
        `;
        moduleList.appendChild(li);
      });
    }

    // Theme handling
    let currentTheme = localStorage.getItem('theme') || userData.theme;
    function applyTheme(theme) {
      if (theme === 'pink') {
        document.body.className = 'min-h-screen bg-gradient-to-br from-pink-50 via-purple-50 to-red-50';
      } else if (theme === 'blue') {
        document.body.className = 'min-h-screen bg-gradient-to-br from-blue-50 via-indigo-50 to-cyan-50';
      } else {
        document.body.className = 'min-h-screen bg-gray-50';
      }
      showToast('Theme updated! 🎨', 'success');
      triggerConfetti();
      currentTheme = theme;
      localStorage.setItem('theme', theme);
    }

    document.querySelectorAll('#themeBtn, #themeBtnSecondary').forEach(btn => {
      btn.addEventListener('click', () => {
        const themes = ['default', 'blue', 'pink'];
        const nextTheme = themes[(themes.indexOf(currentTheme) + 1) % themes.length];
        applyTheme(nextTheme);
      });
    });

    // Star handling
    document.getElementById('starCount').textContent = stars;

    document.getElementById('earnStarBtn').addEventListener('click', () => {
      stars++;
      document.getElementById('starCount').textContent = stars;
      showToast('Star earned!', 'success');
      triggerConfetti();
      updatePetStatus();
      localStorage.setItem('stars', stars);
    });

    document.getElementById('spendStarBtn').addEventListener('click', () => {
      if (stars > 0) {
        stars--;
        document.getElementById('starCount').textContent = stars;
        showToast('Star spent!', 'success');
        updatePetStatus();
        localStorage.setItem('stars', stars);
      } else {
        showToast('No stars to spend!', 'warning');
      }
    });

    // Module handling
    document.getElementById('completeSelectedModule').addEventListener('click', () => {
      const checkboxes = document.querySelectorAll('.module-checkbox:checked');
      if (checkboxes.length > 0) {
        let markedCount = 0;
        checkboxes.forEach(checkbox => {
          const id = parseInt(checkbox.dataset.id);
          const module = modules.find(m => m.id === id);
          if (module && !module.done) {
            module.done = true;
            markedCount++;
          }
        });
        if (markedCount > 0) {
          stars += markedCount;
          document.getElementById('starCount').textContent = stars;
          localStorage.setItem('stars', stars);
          localStorage.setItem('modules', JSON.stringify(modules));
          renderModules();
          showToast(`${markedCount} module(s) completed! +${markedCount} Star(s)`, 'success');
          triggerConfetti();
        }
      } else {
        showToast('No modules selected!', 'warning');
      }
    });

    // Pet interaction
    function updatePetStatus() {
      const petStatus = document.getElementById('petStatus');
      const petHint = document.getElementById('petHint');
      if (stars >= 10) {
        petStatus.textContent = "Buddy is super happy! 🐰✨";
        petHint.textContent = "Keep earning stars to make me even happier!";
      } else if (stars >= 5) {
        petStatus.textContent = "Buddy is happy! 🐰";
        petHint.textContent = "More stars make me even happier!";
      } else {
        petStatus.textContent = "Hi! I'm Buddy 🐰";
        petHint.textContent = "I get happy when you earn stars!";
      }
    }

    document.getElementById('petInteract').addEventListener('click', () => {
      showToast('Played with Buddy!', 'success');
      triggerConfetti();
      updatePetStatus();
    });

    // Confetti effect
    function triggerConfetti() {
      const colors = ['#FFD700', '#FF69B4', '#00CED1', '#34D399', '#60A5FA'];
      const confettiRoot = document.getElementById('confettiRoot');
      for (let i = 0; i < 30; i++) {
        const el = document.createElement('div');
        el.className = 'confetti';
        el.style.left = Math.random() * 100 + 'vw';
        el.style.background = colors[Math.floor(Math.random() * colors.length)];
        el.style.transform = `translateY(-20vh) rotate(${Math.random() * 360}deg)`;
        el.style.opacity = String(0.9 + Math.random() * 0.1);
        el.style.width = (6 + Math.random() * 10) + 'px';
        el.style.height = (8 + Math.random() * 12) + 'px';
        el.style.borderRadius = (Math.random() * 4) + 'px';
        el.style.animationDuration = (2 + Math.random() * 2) + 's';
        confettiRoot.appendChild(el);
        setTimeout(() => el.remove(), 3500);
      }
    }

    // Celebrate button
    document.getElementById('confettiTest').addEventListener('click', () => {
      showToast('Celebration time!', 'success');
      triggerConfetti();
    });

    // Toast notifications
    function showToast(message, type) {
      const toastContainer = document.getElementById('toastContainer');
      const toast = document.createElement('div');
      toast.className = `toast ${type}`;
      toast.setAttribute('role', 'alert');
      toast.setAttribute('aria-live', 'assertive');
      toast.setAttribute('aria-atomic', 'true');
      const icons = {
        success: '<i class="fas fa-check-circle toast-icon"></i>',
        info: '<i class="fas fa-info-circle toast-icon"></i>',
        warning: '<i class="fas fa-exclamation-circle toast-icon"></i>',
        error: '<i class="fas fa-times-circle toast-icon"></i>'
      };
      toast.innerHTML = `
        ${icons[type] || icons.info}
        <div class="toast-message">${message}</div>
        <i class="fas fa-times toast-close" role="button" aria-label="Close notification"></i>
      `;
      toastContainer.appendChild(toast);
      setTimeout(() => toast.classList.add('show'), 100);
      const timeout = setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
      }, 5000);
      toast.querySelector('.toast-close').addEventListener('click', () => {
        clearTimeout(timeout);
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
      });
    }

    // Chart.js for Grades
    const gradesCtx = document.getElementById('gradesChart').getContext('2d');
    const gradesChart = new Chart(gradesCtx, {
      type: 'bar',
      data: {
        labels: grades.labels,
        datasets: [{
          label: 'Grades',
          data: grades.data,
          backgroundColor: 'rgba(96, 165, 250, 0.5)',
          borderColor: 'rgba(96, 165, 250, 1)',
          borderWidth: 1
        }]
      },
      options: {
        scales: {
          y: { beginAtZero: true, max: 100 }
        },
        plugins: {
          legend: { display: false }
        }
      }
    });

    // Update grade stats
    if (grades.data.length > 0) {
      const avg = grades.data.reduce((a, b) => a + b, 0) / grades.data.length;
      document.getElementById('avgGrade').textContent = `${Math.round(avg)}%`;
      const maxIndex = grades.data.indexOf(Math.max(...grades.data));
      document.getElementById('bestSub').textContent = grades.labels[maxIndex] || '-';
    }

    // Chart.js for Attendance
    const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
    const attendanceChart = new Chart(attendanceCtx, {
      type: 'doughnut',
      data: {
        labels: ['Present', 'Absent'],
        datasets: [{
          data: [attendance.present, attendance.absent],
          backgroundColor: ['#10b981', '#f87171'],
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
          legend: { 
            position: 'bottom',
            labels: {
              padding: 10,
              font: {
                size: 12
              }
            }
          }
        }
      }
    });

    // Update attendance stats
    const totalAttendance = attendance.present + attendance.absent;
    if (totalAttendance > 0) {
      const presentPercent = (attendance.present / totalAttendance * 100).toFixed(1);
      const absentPercent = (attendance.absent / totalAttendance * 100).toFixed(1);
      document.getElementById('presentPercent').textContent = `${presentPercent}%`;
      document.getElementById('absentPercent').textContent = `${absentPercent}%`;
      document.getElementById('attendanceBar').style.width = `${presentPercent}%`;
      document.getElementById('absentBar').style.width = `${absentPercent}%`;
    }

    // Mobile menu toggle
    document.getElementById('mobileMenuBtn').addEventListener('click', () => {
      const sidebar = document.querySelector('aside');
      sidebar.classList.toggle('hidden');
      sidebar.classList.toggle('flex');
      sidebar.classList.toggle('fixed');
      sidebar.classList.toggle('inset-0');
      sidebar.classList.toggle('z-50');
      showToast(sidebar.classList.contains('flex') ? 'Menu opened' : 'Menu closed', 'info');
    });

    // Collapsible books section
    let booksExpanded = localStorage.getItem('books_expanded') || userData.books_expanded;
    document.getElementById('booksBtn').addEventListener('click', (e) => {
      e.preventDefault(); // Prevent default form submission
      const content = document.querySelector('.collapsible-content');
      const arrow = document.querySelector('.collapsible-arrow');
      const isExpanded = content.style.maxHeight && content.style.maxHeight !== '0px';
      content.style.maxHeight = isExpanded ? '0px' : `${content.scrollHeight}px`;
      arrow.style.transform = isExpanded ? 'rotate(0deg)' : 'rotate(180deg)';
      booksExpanded = isExpanded ? 0 : 1;
      localStorage.setItem('books_expanded', booksExpanded);
      
      // Prevent the click from propagating to parent elements
      e.stopPropagation();
      return false;
    });
    
    // Make sure the collapsible content doesn't close when clicking inside it
    document.querySelector('.collapsible-content').addEventListener('click', (e) => {
      e.stopPropagation();
    });

    // Initialize collapsible state
    if (booksExpanded) {
      const content = document.querySelector('.collapsible-content');
      content.style.maxHeight = `${content.scrollHeight}px`;
      document.querySelector('.collapsible-arrow').style.transform = 'rotate(180deg)';
    }

    // Notification toggle
    let notifications = 3;
    const notifBtn = document.getElementById('notifBtn');
    const notifCountEl = document.getElementById('notifCount');
    const notificationDropdown = document.getElementById('notificationDropdown');
    function initNotifications() {
      notifCountEl.textContent = notifications;
    }
    notifBtn.addEventListener('click', () => {
      notificationDropdown.classList.toggle('hidden');
      if (!notificationDropdown.classList.contains('hidden')) {
        notifications = 0;
        notifCountEl.textContent = '0';
        showToast('Notifications viewed', 'info');
      }
    });
    document.addEventListener('click', (e) => {
      if (!notifBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
        notificationDropdown.classList.add('hidden');
      }
    });

    // Navigation feedback
    const navButtons = ['navDashboard', 'navSchedule', 'navModules', 'navGrades', 'navAnnouncements'];
    navButtons.forEach(id => {
      const btn = document.getElementById(id);
      if (btn) {
        btn.addEventListener('click', () => {
          showToast(`Navigating to ${id.replace('nav', '')} page...`, 'info');
        });
      }
    });

    // Logout via modal
    const logoutLink = document.getElementById('logout');
    const signoutModal = document.getElementById('signoutModal');
    const cancelSignout = document.getElementById('cancelSignout');
    const confirmSignout = document.getElementById('confirmSignout');
    let pendingLogoutHref = null;

    function openSignoutModal(href) {
      pendingLogoutHref = href || '/San%20Agustin/logout.php';
      signoutModal.classList.remove('hidden');
      signoutModal.classList.add('flex');
    }
    function closeSignoutModal() {
      signoutModal.classList.add('hidden');
      signoutModal.classList.remove('flex');
      pendingLogoutHref = null;
    }

    if (logoutLink) {
      logoutLink.addEventListener('click', (e) => {
        e.preventDefault();
        const href = e.currentTarget.getAttribute('href');
        openSignoutModal(href);
      });
    }
    if (cancelSignout) {
      cancelSignout.addEventListener('click', closeSignoutModal);
    }
    if (confirmSignout) {
      confirmSignout.addEventListener('click', () => {
        showToast('Logging out...', 'info');
        const dest = pendingLogoutHref || '/San%20Agustin/logout.php';
        closeSignoutModal();
        setTimeout(() => { window.location.href = dest; }, 300);
      });
    }
    // Close when clicking outside the dialog
    if (signoutModal) {
      signoutModal.addEventListener('click', (e) => {
        if (e.target === signoutModal) closeSignoutModal();
      });
    }

    // Simulate online users
    function simulateOnlineUsers() {
      const onlineCount = document.getElementById('onlineCount');
      setInterval(() => {
        const baseCount = 20;
        const fluctuation = Math.floor(Math.random() * 10);
        onlineCount.textContent = (baseCount + fluctuation) + ' students online';
      }, 10000);
    }

    // Initialize page
    window.addEventListener('load', () => {
      renderModules();
      initNotifications();
      simulateOnlineUsers();
      setTimeout(() => {
        showToast('Welcome to your dashboard! 🎉', 'success');
        triggerConfetti();
      }, 1000);
      updatePetStatus();
      applyTheme(currentTheme);
      // If we have saved student info, notify that it is for display only
      if (savedStudentInfo) {
        showToast('Loaded your saved student info (local).', 'info');
      }
    });

    // Settings modal logic
    const settingsModal = document.getElementById('settingsModal');
    const openSettings = document.getElementById('settingsBtn');
    const closeSettings = document.getElementById('closeSettings');
    const cancelSettings = document.getElementById('cancelSettings');
    const settingsForm = document.getElementById('settingsForm');

    function fillSettingsForm() {
      const info = savedStudentInfo || {};
      document.getElementById('set_first_name').value = (info.first_name) || (userData.name.split(' ')[0] || '');
      document.getElementById('set_last_name').value = (info.last_name) || (userData.name.split(' ').slice(1).join(' ') || '');
      document.getElementById('set_student_id').value = info.student_id || userData.studentId || '';
      document.getElementById('set_school_year').value = info.school_year || userData.schoolYear || '';
      document.getElementById('set_grade_level').value = info.grade_level || userData.grade || '';
      document.getElementById('set_section').value = info.section || userData.section || '';
      document.getElementById('set_birthdate').value = info.birthdate || '';
      document.getElementById('set_gender').value = info.gender || '';
      document.getElementById('set_address').value = info.address || '';
      document.getElementById('set_parent_name').value = info.parent_name || '';
      document.getElementById('set_parent_contact').value = info.parent_contact || '';
    }

    function openSettingsModal() {
      fillSettingsForm();
      settingsModal.classList.remove('hidden');
      settingsModal.classList.add('flex');
    }
    function closeSettingsModal() {
      settingsModal.classList.add('hidden');
      settingsModal.classList.remove('flex');
    }

    openSettings.addEventListener('click', openSettingsModal);
    closeSettings.addEventListener('click', closeSettingsModal);
    cancelSettings.addEventListener('click', closeSettingsModal);
    settingsModal.addEventListener('click', (e) => { if (e.target === settingsModal) closeSettingsModal(); });

    settingsForm.addEventListener('submit', (e) => {
      e.preventDefault();
      const data = {
        first_name: document.getElementById('set_first_name').value.trim(),
        last_name: document.getElementById('set_last_name').value.trim(),
        student_id: document.getElementById('set_student_id').value.trim(),
        school_year: document.getElementById('set_school_year').value.trim(),
        grade_level: document.getElementById('set_grade_level').value.trim(),
        section: document.getElementById('set_section').value.trim(),
        birthdate: document.getElementById('set_birthdate').value,
        gender: document.getElementById('set_gender').value,
        address: document.getElementById('set_address').value.trim(),
        parent_name: document.getElementById('set_parent_name').value.trim(),
        parent_contact: document.getElementById('set_parent_contact').value.trim()
      };
      try {
        localStorage.setItem('student_info', JSON.stringify(data));
        showToast('Settings saved locally. Reload to see updates in the dashboard.', 'success');
        closeSettingsModal();
      } catch (err) {
        showToast('Failed to save settings locally.', 'error');
      }
    });

    // Load announcements for dashboard
    function loadDashboardAnnouncements() {
      const container = document.getElementById('dashboardAnnouncements');
      
      console.log('Fetching announcements from API...');
      
      fetch('../api/get_announcements.php')
        .then(response => {
          console.log('API Response Status:', response.status);
          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          return response.json();
        })
        .then(data => {
          console.log('Announcements data received:', data);
          
          if (data.success && data.announcements && data.announcements.length > 0) {
            console.log('Total announcements:', data.announcements.length);
            
            // Count teacher vs school announcements
            const teacherCount = data.announcements.filter(a => a.source === 'teacher').length;
            const schoolCount = data.announcements.filter(a => a.source === 'school').length;
            console.log(`Teacher announcements: ${teacherCount}, School announcements: ${schoolCount}`);
            // Show only the latest 3 announcements
            const latestAnnouncements = data.announcements.slice(0, 3);
            
            let html = '';
            latestAnnouncements.forEach(announcement => {
              const priority = announcement.priority || 'medium';
              const source = announcement.source || 'school';
              const postedBy = announcement.posted_by || 'School Administration';
              const date = announcement.formatted_date || 'Recent';
              
              // Priority badge colors
              let priorityClass = 'bg-blue-100 text-blue-800';
              if (priority === 'high') priorityClass = 'bg-red-100 text-red-800';
              else if (priority === 'medium') priorityClass = 'bg-yellow-100 text-yellow-800';
              
              // Source icon and styling
              let sourceIcon = source === 'teacher' ? '🎓' : '🏫';
              let sourceText = source === 'teacher' ? 'Class Announcement' : 'School Announcement';
              let sourceBadgeClass = source === 'teacher' ? 'bg-blue-100 text-blue-700' : 'bg-green-100 text-green-700';
              let borderClass = source === 'teacher' ? 'border-blue-200 border-l-4' : 'border-gray-200';
              
              html += `
                <div class="rounded-lg border ${borderClass} p-4 bg-white hover:shadow-md transition-shadow cursor-pointer" onclick="window.location.href='announcements.php'">
                  <div class="flex items-start justify-between mb-2">
                    <h4 class="font-semibold text-school-primary flex-1">${escapeHtml(announcement.title)}</h4>
                    <span class="text-xs px-2 py-1 rounded-full ${priorityClass} ml-2">${priority.toUpperCase()}</span>
                  </div>
                  <div class="flex items-center gap-2 text-xs mb-2">
                    <span class="px-2 py-1 rounded-full ${sourceBadgeClass} font-medium">${sourceIcon} ${sourceText}</span>
                    <span class="text-gray-500">${date}</span>
                  </div>
                  <p class="text-sm text-gray-700 line-clamp-2 mb-2">${escapeHtml(announcement.description || announcement.content || 'No description')}</p>
                  <div class="flex items-center justify-between">
                    <span class="text-xs text-gray-600 font-medium">${escapeHtml(postedBy)}</span>
                    <span class="text-xs text-school-accent hover:underline font-medium">Read more →</span>
                  </div>
                </div>
              `;
            });
            
            container.innerHTML = html;
          } else {
            container.innerHTML = `
              <div class="text-center py-8 text-gray-500">
                <i class="fas fa-inbox text-4xl mb-2"></i>
                <p>No announcements available</p>
                <a href="announcements.php" class="text-school-accent hover:underline text-sm mt-2 inline-block">
                  Go to Announcements Page
                </a>
              </div>
            `;
          }
        })
        .catch(error => {
          console.error('Error loading announcements:', error);
          container.innerHTML = `
            <div class="text-center py-8 text-red-500">
              <i class="fas fa-exclamation-triangle text-4xl mb-2"></i>
              <p>Failed to load announcements</p>
              <button onclick="loadDashboardAnnouncements()" class="text-school-accent hover:underline text-sm mt-2">
                Try Again
              </button>
            </div>
          `;
        });
    }
    
    // Helper function to escape HTML
    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
    
    // Load announcements on page load
    document.addEventListener('DOMContentLoaded', function() {
      loadDashboardAnnouncements();
      
      // Refresh announcements every 2 minutes
      setInterval(loadDashboardAnnouncements, 120000);
    });
  </script>
</body>
</html>
