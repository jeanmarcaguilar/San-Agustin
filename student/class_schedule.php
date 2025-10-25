<?php
// Start the session
session_start();

// Include the database configuration
require_once '../config/database.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection('student');

// Default values
$schedule = [];
$user = [
    'first_name' => 'Student',
    'last_name' => '',
    'grade_level' => 'N/A',
    'section' => 'N/A'
];

// Get user data from session or use default
if (isset($_SESSION['user_id'])) {
    try {
        // Try registrar_db first for student info
        $registrar_conn = $database->getConnection('registrar');
        $query = "SELECT first_name, last_name, grade_level, section FROM students WHERE user_id = ?";
        $stmt = $registrar_conn->prepare($query);
        $stmt->bindParam(1, $_SESSION['user_id']);
        $stmt->execute();
        
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If not found in registrar, try student_db
        if (!$userData) {
            $stmt = $db->prepare($query);
            $stmt->bindParam(1, $_SESSION['user_id']);
            $stmt->execute();
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if ($userData) {
            $user = array_merge($user, $userData);
        }
    } catch (PDOException $e) {
        // Log error and continue with default values
        error_log("Database error: " . $e->getMessage());
    }
}

// Note: Schedule will be loaded via API call in JavaScript

// Generate initials for avatar
$initials = '';
if (!empty($user['first_name'])) $initials .= substr($user['first_name'], 0, 1);
if (!empty($user['last_name'])) $initials .= substr($user['last_name'], 0, 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>📅 Class Schedule – San Agustin Elementary School</title>
  <script src="https://cdn.tailwindcss.com"></script>
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
    
    .collapsible-content {
      max-height: 0;
      overflow: hidden;
      transition: max-height 0.3s ease-out;
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
    }
    
    .toast.show {
      opacity: 1;
      transform: translateX(0);
    }
    
    .toast.success { border-left-color: var(--primary); }
    .toast.info { border-left-color: var(--accent); }
    .toast.warning { border-left-color: var(--secondary); }
    .toast.error { border-left-color: #ef4444; }
    
    .toast .toast-icon { font-size: 1.2rem; }
    .toast .toast-message { flex: 1; font-size: 0.875rem; color: #1f2937; }
    .toast .toast-close { cursor: pointer; color: #6b7280; font-size: 1rem; transition: color 0.2s ease; }
    .toast .toast-close:hover { color: #1f2937; }
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
        <div>
          <div class="text-lg font-extrabold">San Agustin Elementary School</div>
          <div class="text-xs text-white/80">Student Portal</div>
        </div>
      </div>
      <div class="bg-white/10 p-3 rounded-xl mb-4 flex items-center gap-3">
        <div class="w-12 h-12 rounded-full bg-school-secondary text-school-primary font-bold flex items-center justify-center">
          <?php echo !empty($initials) ? $initials : 'U'; ?>
        </div>
        <div>
          <div class="font-semibold"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
          <div class="text-xs text-white/80">
            Grade <?php echo htmlspecialchars($user['grade_level']); ?> • 
            Section <?php echo htmlspecialchars($user['section']); ?>
          </div>
        </div>
      </div>
      <nav class="mt-3 flex-1 space-y-2">
        <a id="navDashboard" href="dashboard.php" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center gap-2 px-3 py-3">
          <i class="fas fa-home"></i> Dashboard
        </a>
        <a id="navSchedule" href="class_schedule.php" class="w-full big-btn bg-green-600 hover:bg-green-500 text-white flex items-center gap-2 px-3 py-3 active-nav">
          <i class="fas fa-calendar"></i> Schedule
        </a>
        <div class="collapsible-section">
          <button id="booksBtn" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center justify-between px-3 py-3">
            <span class="flex items-center gap-2"><i class="fas fa-book"></i> Library Books</span>
            <span class="collapsible-arrow transition-transform">▼</span>
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
        <form action="../logout.php" method="post" class="w-full">
          <button type="submit" id="logout" class="w-full bg-red-600 hover:bg-red-500 big-btn flex items-center justify-center gap-2">
            <i class="fas fa-sign-out-alt"></i> Sign Out
          </button>
        </form>
      </div>
    </aside>
    <main class="flex-1 p-5">
      <header class="flex items-center justify-between mb-6 bg-white p-4 rounded-xl shadow-sm border border-gray-200">
        <div class="flex items-center gap-4">
          <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg bg-school-primary text-white">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
          <h1 class="text-2xl md:text-3xl font-extrabold text-school-primary flex items-center gap-2">
            <i class="fas fa-graduation-cap"></i>San Agustin Elementary <br>School Student Portal
          </h1>
          <span class="hidden md:inline text-sm text-gray-600">
            Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>! 🎉
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
            <input id="search" aria-label="Search" class="hidden sm:inline px-4 py-2 rounded-full border w-64 search-input pl-10" placeholder="Search announcements..." />
            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
          </div>
          <button id="notifBtn" class="p-2 rounded-full bg-school-secondary relative" title="Notifications">
            <i class="fas fa-bell"></i>
            <span id="notifCount" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">3</span>
          </button>
          <div class="flex items-center gap-2 p-2 rounded-full bg-white shadow-sm border">
            <img src="https://i.pravatar.cc/40?img=5" alt="profile" class="w-9 h-9 rounded-full">
            <div class="hidden sm:block text-sm">
              <div class="font-semibold text-school-primary">
                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
              </div>
              <div class="text-xs text-gray-500">
                Grade <?php echo htmlspecialchars($user['grade_level']); ?> - 
                Section <?php echo htmlspecialchars($user['section']); ?>
              </div>
            </div>
          </div>
        </div>
      </header>
      <div id="notificationDropdown" class="hidden absolute right-4 top-28 z-50 w-80 bg-white rounded-lg shadow-lg p-4 border">
        <h3 class="font-bold text-lg mb-2 text-school-primary flex items-center gap-2"><i class="fas fa-bell"></i> Notifications</h3>
        <ul class="space-y-2">
          <li class="p-2 bg-green-50 rounded border flex items-center gap-2"><i class="fas fa-trophy text-green-500"></i> You earned a badge!</li>
          <li class="p-2 bg-yellow-50 rounded border flex items-center gap-2"><i class="fas fa-calendar-day text-yellow-500"></i> School event tomorrow</li>
        </ul>
        <button class="mt-3 text-sm text-school-accent font-medium flex items-center gap-1"><i class="fas fa-check-circle"></i> Mark all as read</button>
      </div>
      <section class="card p-5">
        <h2 class="text-xl font-bold text-school-primary flex items-center gap-2">
          <i class="fas fa-calendar"></i> 
          Grade <?php echo htmlspecialchars($user['grade_level']); ?> – Class Schedule
        </h2>
        <div class="mt-4 overflow-x-auto">
          <table class="w-full text-left border-collapse">
            <thead>
              <tr class="bg-school-secondary text-school-primary">
                <th class="p-3 font-semibold">Subject</th>
                <th class="p-3 font-semibold">Teacher</th>
                <th class="p-3 font-semibold">Day</th>
                <th class="p-3 font-semibold">Time</th>
                <th class="p-3 font-semibold">Room</th>
              </tr>
            </thead>
            <tbody id="scheduleTable">
              <?php if (!empty($schedule)): ?>
                <?php foreach ($schedule as $class): ?>
                  <tr class="border-b hover:bg-gray-100">
                    <td class="p-3"><?php echo htmlspecialchars($class['subject']); ?></td>
                    <td class="p-3"><?php echo htmlspecialchars($class['teacher']); ?></td>
                    <td class="p-3"><?php echo htmlspecialchars($class['days']); ?></td>
                    <td class="p-3">
                      <?php 
                      echo date('g:i A', strtotime($class['start_time'])) . ' - ' . 
                           date('g:i A', strtotime($class['end_time'])); 
                      ?>
                    </td>
                    <td class="p-3"><?php echo htmlspecialchars($class['room']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php else: ?>
                <tr>
                  <td colspan="5" class="p-4 text-center text-gray-500">
                    No schedule found for your grade and section.
                  </td>
                </tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
        <div class="mt-4 flex gap-3">
          <button id="themeBtn" class="big-btn bg-school-accent hover:bg-blue-500 text-white flex items-center gap-2"><i class="fas fa-palette"></i> Change Theme</button>
          <button id="confettiTest" class="big-btn bg-pink-400 hover:bg-pink-500 text-white flex items-center gap-2"><i class="fas fa-party-horn"></i> Celebrate</button>
        </div>
      </section>
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
    let currentTheme = 'default';
    let booksExpanded = false;
    let scheduleData = [];
    
    // Load schedule from API
    function loadClassSchedule() {
      fetch('../api/get_class_schedule.php')
        .then(response => response.json())
        .then(data => {
          console.log('Schedule data:', data);
          if (data.success && data.schedules) {
            scheduleData = data.schedules;
            displaySchedule(scheduleData);
            showToast('Class schedule loaded successfully! 📅', 'success');
          } else {
            showToast('No schedule found', 'warning');
          }
        })
        .catch(error => {
          console.error('Error loading schedule:', error);
          showToast('Failed to load schedule', 'error');
        });
    }
    
    // Display schedule in table
    function displaySchedule(schedules) {
      const tbody = document.querySelector('table tbody');
      if (!tbody || schedules.length === 0) return;
      
      tbody.innerHTML = '';
      schedules.forEach(schedule => {
        const row = document.createElement('tr');
        row.className = 'border-b hover:bg-gray-50';
        row.innerHTML = `
          <td class="p-3">${schedule.subject || 'N/A'}</td>
          <td class="p-3">${schedule.teacher_name || 'TBA'}</td>
          <td class="p-3">${schedule.day_of_week || 'N/A'}</td>
          <td class="p-3">${schedule.time_range || 'N/A'}</td>
          <td class="p-3">${schedule.room || 'N/A'}</td>
        `;
        tbody.appendChild(row);
      });
    }
    const annData = [
      { text: 'School assembly next Monday' },
      { text: 'Parent-teacher meeting scheduled' }
    ];

    function showToast(message, type = 'info') {
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

    function fireConfetti(count = 30) {
      const colors = ['#FFD700', '#FF69B4', '#00CED1', '#34D399', '#60A5FA'];
      const confettiRoot = document.getElementById('confettiRoot');
      for (let i = 0; i < count; i++) {
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

    const themeBtn = document.getElementById('themeBtn');
    const body = document.body;
    themeBtn.addEventListener('click', () => {
      if (body.classList.contains('bg-gray-50')) {
        body.className = 'min-h-screen bg-gradient-to-br from-pink-50 via-purple-50 to-red-50';
        currentTheme = 'pink';
        showToast('Theme changed to Pink! 🎨', 'success');
      } else if (body.classList.contains('from-pink-50') && body.classList.contains('via-purple-50')) {
        body.className = 'min-h-screen bg-gradient-to-br from-blue-50 via-indigo-50 to-cyan-50';
        currentTheme = 'blue';
        showToast('Theme changed to Blue! 🎨', 'success');
      } else {
        body.className = 'min-h-screen bg-gray-50';
        currentTheme = 'default';
        showToast('Theme changed to Default! 🎨', 'success');
      }
    });

    const searchInput = document.getElementById('search');
    searchInput.addEventListener('keyup', (e) => {
      if (e.key === 'Enter') {
        const searchTerm = searchInput.value.toLowerCase();
        if (searchTerm.length < 2) {
          showToast('Please enter at least 2 characters to search', 'warning');
          return;
        }
        let results = [];
        scheduleData.forEach(s => {
          if (s.subject.toLowerCase().includes(searchTerm) || 
              s.teacher.toLowerCase().includes(searchTerm) || 
              s.days.toLowerCase().includes(searchTerm) || 
              s.room.toLowerCase().includes(searchTerm)) {
            results.push(`Schedule: ${s.subject} with ${s.teacher}`);
          }
        });
        annData.forEach(a => {
          if (a.text.toLowerCase().includes(searchTerm)) {
            results.push(`Announcement: ${a.text}`);
          }
        });
        if (results.length > 0) {
          showToast(`Search results for "${searchInput.value}": ${results.join(', ')}`, 'info');
        } else {
          showToast(`No results found for "${searchInput.value}"`, 'info');
        }
      }
    });

    const mobileMenuBtn = document.getElementById('mobileMenuBtn');
    const sidebar = document.querySelector('aside');
    mobileMenuBtn.addEventListener('click', () => {
      sidebar.classList.toggle('hidden');
      sidebar.classList.toggle('flex');
      sidebar.classList.toggle('fixed');
      sidebar.classList.toggle('inset-0');
      sidebar.classList.toggle('z-50');
    });

    const booksBtn = document.getElementById('booksBtn');
    const collapsibleContent = document.querySelector('.collapsible-content');
    const collapsibleArrow = document.querySelector('.collapsible-arrow');
    function initBooksSection() {
      if (booksExpanded) {
        collapsibleContent.style.maxHeight = collapsibleContent.scrollHeight + 'px';
        collapsibleArrow.style.transform = 'rotate(180deg)';
      }
    }
    booksBtn.addEventListener('click', () => {
      if (collapsibleContent.style.maxHeight) {
        collapsibleContent.style.maxHeight = null;
        collapsibleArrow.style.transform = 'rotate(0deg)';
        booksExpanded = false;
        showToast('Books section collapsed', 'success');
      } else {
        collapsibleContent.style.maxHeight = collapsibleContent.scrollHeight + 'px';
        collapsibleArrow.style.transform = 'rotate(180deg)';
        booksExpanded = true;
        showToast('Books section expanded', 'success');
      }
    });

    document.getElementById('confettiTest').addEventListener('click', () => fireConfetti(40));
    document.getElementById('logout').addEventListener('click', (e) => {
      e.preventDefault();
      if (confirm('Are you sure you want to sign out?')) {
        showToast('Signed out successfully', 'success');
        document.querySelector('form[action$="logout.php"]').submit();
      }
    });

    const notifBtn = document.getElementById('notifBtn');
    const notifCountEl = document.getElementById('notifCount');
    const notificationDropdown = document.getElementById('notificationDropdown');
    let notifications = 2;
    function initNotifications() {
      notifCountEl.textContent = notifications;
    }
    notifBtn.addEventListener('click', () => {
      notificationDropdown.classList.toggle('hidden');
      if (!notificationDropdown.classList.contains('hidden')) {
        notifications = 0;
        notifCountEl.textContent = '0';
      }
    });
    document.addEventListener('click', (e) => {
      if (!notifBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
        notificationDropdown.classList.add('hidden');
      }
    });

    function simulateOnlineUsers() {
      const onlineCount = document.getElementById('onlineCount');
      setInterval(() => {
        const baseCount = 20;
        const fluctuation = Math.floor(Math.random() * 10);
        onlineCount.textContent = (baseCount + fluctuation) + ' students online';
      }, 10000);
    }

    window.addEventListener('load', () => {
      initNotifications();
      simulateOnlineUsers();
      initBooksSection();
      loadClassSchedule();
    });
  </script>
  <script>
    // Settings modal (header button like dashboard)
    (function(){
      const openSettingsBtn = document.getElementById('settingsBtn');

      const modal = document.createElement('div');
      modal.id = 'settingsModal';
      modal.className = 'fixed inset-0 bg-black/50 hidden items-center justify-center z-[10001] p-4';
      modal.innerHTML = `
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
            <div class="md:col-span-2 flex items-center justify-end gap-2 pt-2 border-t mt-2">
              <button type="button" id="cancelSettings" class="px-4 py-2 rounded-lg border hover:bg-gray-50">Cancel</button>
              <button type="submit" class="px-4 py-2 rounded-lg bg-school-primary text-white hover:bg-green-700 flex items-center gap-2"><i class="fas fa-save"></i> Save</button>
            </div>
          </form>
        </div>`;
      document.body.appendChild(modal);

      function openSettings(){ modal.classList.remove('hidden'); modal.classList.add('flex'); }
      function closeSettings(){ modal.classList.add('hidden'); modal.classList.remove('flex'); }
      if (openSettingsBtn) openSettingsBtn.addEventListener('click', ()=>{ fill(); openSettings(); });
      modal.addEventListener('click', (e)=>{ if(e.target===modal) closeSettings(); });
      modal.querySelector('#closeSettings').addEventListener('click', closeSettings);
      modal.querySelector('#cancelSettings').addEventListener('click', closeSettings);

      const saved = (()=>{ try{ return JSON.parse(localStorage.getItem('student_info')||'null'); }catch{return null;} })();
      function fill(){
        document.getElementById('set_first_name').value = saved?.first_name || <?php echo json_encode($user['first_name'] ?? ''); ?>;
        document.getElementById('set_last_name').value  = saved?.last_name  || <?php echo json_encode($user['last_name'] ?? ''); ?>;
        document.getElementById('set_student_id').value = saved?.student_id || '';
        document.getElementById('set_school_year').value= saved?.school_year|| '';
        document.getElementById('set_grade_level').value= saved?.grade_level|| <?php echo json_encode($user['grade_level'] ?? ''); ?>;
        document.getElementById('set_section').value    = saved?.section    || <?php echo json_encode($user['section'] ?? ''); ?>;
      }
      // header button triggers fill + open

      modal.querySelector('#settingsForm').addEventListener('submit', (e)=>{
        e.preventDefault();
        const data = {
          first_name: document.getElementById('set_first_name').value.trim(),
          last_name:  document.getElementById('set_last_name').value.trim(),
          student_id: document.getElementById('set_student_id').value.trim(),
          school_year:document.getElementById('set_school_year').value.trim(),
          grade_level:document.getElementById('set_grade_level').value.trim(),
          section:    document.getElementById('set_section').value.trim()
        };
        try{
          localStorage.setItem('student_info', JSON.stringify(data));
          if (typeof showToast==='function') showToast('Settings saved locally.', 'success');
          closeSettings();
        }catch(err){ if (typeof showToast==='function') showToast('Failed to save settings.', 'error'); }
      });
    })();
  </script>
</body>
</html>
