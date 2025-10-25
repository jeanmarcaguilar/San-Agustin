<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Initialize database connection
$database = new Database();
$conn = $database->getConnection('student');

$student_id = $_SESSION['user_id'];
$grades = [];
$error = '';

try {
    // Get student information
    $stmt = $conn->prepare("SELECT s.*, u.email FROM students s 
                          JOIN login_db.users u ON s.user_id = u.id 
                          WHERE s.user_id = :user_id");
    $stmt->bindParam(':user_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    // Generate initials for avatar
    $initials = '';
    if (!empty($student['first_name']) && !empty($student['last_name'])) {
        $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
    }

    // Get grades for the current student
    $query = "SELECT * FROM grades WHERE student_id = :student_id AND grade_level = :grade_level ORDER BY subject, quarter";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->bindParam(':grade_level', $student['grade_level'], PDO::PARAM_INT);
    $stmt->execute();
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error = "Error fetching grades: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grades - San Agustin Elementary School</title>
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
    
    .grade-card:hover {
        transform: translateY(-5px);
    }
    .subject-header {
        background: linear-gradient(135deg, #0b6b4f 0%, #60a5fa 100%);
        color: white;
        padding: 15px;
        border-radius: 10px 10px 0 0;
    }
    .grade-value {
        font-size: 1.5rem;
        font-weight: bold;
    }
    .grade-remarks {
        font-size: 0.9rem;
        color: #666;
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
          <div class="font-semibold"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
          <div class="text-xs text-white/80">
            Grade <?php echo htmlspecialchars($student['grade_level']); ?> • 
            Section <?php echo htmlspecialchars($student['section'] ?? 'N/A'); ?>
          </div>
        </div>
      </div>
      <nav class="mt-3 flex-1 space-y-2">
        <a id="navDashboard" href="dashboard.php" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center gap-2 px-3 py-3">
          <i class="fas fa-home"></i> Dashboard
        </a>
        <a id="navSchedule" href="class_schedule.php" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center gap-2 px-3 py-3">
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
        <a id="navGrades" href="grades.php" class="w-full big-btn bg-green-600 hover:bg-green-500 text-white flex items-center gap-3 px-3 py-3 rounded active-nav">
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
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M9 17V9m4 8v-5m4 5V5" />
            </svg>
            Grades
          </h1>
          <span class="hidden md:inline text-sm text-gray-600">
            Welcome back, <?php echo htmlspecialchars($student['first_name']); ?>! 🎉
          </span>
        </div>
        <div class="flex items-center gap-3">
          <div class="hidden sm:flex items-center bg-white rounded-full border px-3 py-1 shadow-sm">
            <span class="text-sm text-green-600 font-medium mr-2"><i class="fas fa-circle animate-pulse"></i> Online</span>
            <span id="onlineCount" class="text-xs text-gray-500">24 students online</span>
          </div>
          <div class="relative">
            <input id="search" aria-label="Search grades" class="hidden sm:inline px-4 py-2 rounded-full border w-64 search-input pl-10" placeholder="Search grades..." />
            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
          </div>
          <button id="notifBtn" class="p-2 rounded-full bg-school-secondary relative" title="Notifications">
            <i class="fas fa-bell"></i>
            <span id="notifCount" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">3</span>
          </button>
          <button id="settingsBtn" class="p-2 rounded-full bg-white border hover:bg-gray-50" title="Student Settings">
            <i class="fas fa-cog text-gray-700"></i>
          </button>
          <div class="flex items-center gap-2 p-2 rounded-full bg-white shadow-sm border">
            <img src="https://i.pravatar.cc/40?img=5" alt="profile" class="w-9 h-9 rounded-full">
            <div class="hidden sm:block text-sm">
              <div class="font-semibold text-school-primary">
                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
              </div>
              <div class="text-xs text-gray-500">
                Grade <?php echo htmlspecialchars($student['grade_level']); ?> - 
                Section <?php echo htmlspecialchars($student['section'] ?? 'N/A'); ?>
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

      <!-- Display error message -->
      <?php if (!empty($error)): ?>
        <div class="toast error show mb-4">
          <i class="fas fa-times-circle toast-icon"></i>
          <div class="toast-message"><?php echo htmlspecialchars($error); ?></div>
          <i class="fas fa-times toast-close" role="button" aria-label="Close notification"></i>
        </div>
      <?php endif; ?>

      <section class="card p-5">
        <header class="mb-6">
          <div class="flex justify-between items-center">
            <h2 class="text-xl font-bold text-school-primary flex items-center gap-2">
              <i class="fas fa-chart-line"></i> My Grades
            </h2>
            <div class="flex items-center space-x-4">
              <select class="px-3 py-2 border rounded-lg focus:ring-2 focus:ring-school-accent focus:border-school-accent" id="quarterFilter">
                <option value="all">All Quarters</option>
                <option value="1st">1st Quarter</option>
                <option value="2nd">2nd Quarter</option>
                <option value="3rd">3rd Quarter</option>
                <option value="4th">4th Quarter</option>
              </select>
            </div>
          </div>
        </header>

        <?php if (!empty($grades)): ?>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6" id="gradesContainer">
            <?php foreach ($grades as $grade): ?>
              <div class="card grade-item" data-quarter="<?php echo strtolower($grade['quarter']); ?>">
                <div class="subject-header">
                  <h5 class="mb-0"><?php echo htmlspecialchars($grade['subject']); ?></h5>
                  <div class="text-white/80 text-sm"><?php echo $grade['quarter']; ?> Quarter - Grade <?php echo $grade['grade_level']; ?></div>
                </div>
                <div class="p-4">
                  <div class="flex justify-between items-center mb-3">
                    <div>
                      <div class="grade-value text-school-primary"><?php echo number_format($grade['final_grade'], 2); ?></div>
                      <div class="grade-remarks">
                        <span class="px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $grade['remarks'] === 'Passed' ? 'bg-green-100 text-green-800' : ($grade['remarks'] === 'Failed' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'); ?>">
                          <?php echo $grade['remarks']; ?>
                        </span>
                      </div>
                    </div>
                    <div class="text-end">
                      <button class="big-btn bg-school-accent hover:bg-blue-500 text-white px-3 py-1 rounded-full text-sm transition-colors" 
                              data-subject="<?php echo htmlspecialchars($grade['subject']); ?>"
                              data-quarter="<?php echo $grade['quarter']; ?>"
                              data-ww="<?php echo $grade['written_work']; ?>"
                              data-pt="<?php echo $grade['performance_task']; ?>"
                              data-qa="<?php echo $grade['quarterly_assessment']; ?>"
                              onclick="showGradeModal(this)">
                        <i class="fas fa-eye mr-1"></i> View Details
                      </button>
                    </div>
                  </div>
                  <div class="progress h-2 rounded-full bg-gray-200">
                    <div class="bg-<?php echo $grade['final_grade'] >= 75 ? 'green-500' : 'red-500'; ?> h-full rounded-full" 
                         style="width: <?php echo min(100, ($grade['final_grade'] / 100) * 100); ?>%">
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="text-center py-10">
            <i class="fas fa-book-open text-4xl text-gray-300 mb-3"></i>
            <p class="text-gray-500">No grades available yet.</p>
          </div>
        <?php endif; ?>
      </section>

      <!-- Grade Details Modal -->
      <div id="gradeDetailsModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center p-4 z-50">
        <div class="card w-full max-w-md">
          <div class="p-6">
            <div class="flex justify-between items-start mb-4">
              <h2 class="text-xl font-bold text-school-primary" id="gradeDetailsModalLabel">Grade Details</h2>
              <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-2xl"></i>
              </button>
            </div>
            <div class="space-y-4">
              <table class="w-full text-sm">
                <tr>
                  <th class="text-left text-gray-700 font-medium py-2">Component</th>
                  <th class="text-right text-gray-700 font-medium py-2">Score</th>
                </tr>
                <tr>
                  <td class="py-2">Written Work (30%)</td>
                  <td class="text-right" id="wwScore">0</td>
                </tr>
                <tr>
                  <td class="py-2">Performance Task (50%)</td>
                  <td class="text-right" id="ptScore">0</td>
                </tr>
                <tr>
                  <td class="py-2">Quarterly Assessment (20%)</td>
                  <td class="text-right" id="qaScore">0</td>
                </tr>
                <tr class="bg-gray-50">
                  <th class="py-2">Final Grade</th>
                  <th class="text-right" id="finalGrade">0</th>
                </tr>
              </table>
            </div>
            <div class="mt-6 flex justify-end">
              <button type="button" onclick="closeModal()" class="big-btn border border-gray-300 text-gray-700 hover:bg-gray-50">
                Close
              </button>
            </div>
          </div>
        </div>
      </div>

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
    // Toast notification function
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

    // Toggle mobile menu and collapsible sections
    document.addEventListener('DOMContentLoaded', function() {
      // Mobile menu toggle
      const mobileMenuBtn = document.getElementById('mobileMenuBtn');
      const sidebar = document.querySelector('aside');
      
      if (mobileMenuBtn) {
        mobileMenuBtn.addEventListener('click', function() {
          sidebar.classList.toggle('hidden');
          sidebar.classList.toggle('flex');
          sidebar.classList.toggle('fixed');
          sidebar.classList.toggle('inset-0');
          sidebar.classList.toggle('z-50');
        });
      }

      // Collapsible sections
      const collapsibleSections = document.querySelectorAll('.collapsible-section');
      
      collapsibleSections.forEach(section => {
        const button = section.querySelector('button');
        const content = section.querySelector('.collapsible-content');
        const arrow = section.querySelector('.collapsible-arrow');
        
        if (!button || !content) return;
        
        const isActive = section.querySelector('.active-nav');
        
        if (isActive) {
          content.style.maxHeight = content.scrollHeight + 'px';
          if (arrow) arrow.classList.add('rotate-180');
        } else {
          content.style.maxHeight = '0';
          if (arrow) arrow.classList.remove('rotate-180');
        }
        
        button.addEventListener('click', function(e) {
          e.preventDefault();
          
          const isOpen = content.style.maxHeight && content.style.maxHeight !== '0px';
          
          if (!isOpen) {
            content.style.maxHeight = content.scrollHeight + 'px';
            if (arrow) arrow.classList.add('rotate-180');
          } else {
            content.style.maxHeight = '0';
            if (arrow) arrow.classList.remove('rotate-180');
          }
        });
      });

      // Notification dropdown
      const notifBtn = document.getElementById('notifBtn');
      const notifCountEl = document.getElementById('notifCount');
      const notificationDropdown = document.getElementById('notificationDropdown');
      
      let notifications = 2;
      function initNotifications() {
        notifCountEl.textContent = notifications;
      }
      
      if (notifBtn) {
        notifBtn.addEventListener('click', () => {
          notificationDropdown.classList.toggle('hidden');
          if (!notificationDropdown.classList.contains('hidden')) {
            notifications = 0;
            notifCountEl.textContent = '0';
          }
        });
      }
      
      document.addEventListener('click', (e) => {
        if (!notifBtn?.contains(e.target) && !notificationDropdown?.contains(e.target)) {
          notificationDropdown.classList.add('hidden');
        }
      });

      // Simulate online users
      function simulateOnlineUsers() {
        const onlineCount = document.getElementById('onlineCount');
        if (onlineCount) {
          setInterval(() => {
            const baseCount = 20;
            const fluctuation = Math.floor(Math.random() * 10);
            onlineCount.textContent = (baseCount + fluctuation) + ' students online';
          }, 10000);
        }
      }

      // Initialize
      initNotifications();
      simulateOnlineUsers();

      // Filter grades by quarter
      const quarterFilter = document.getElementById('quarterFilter');
      if (quarterFilter) {
        quarterFilter.addEventListener('change', function() {
          const selectedQuarter = this.value;
          const gradeItems = document.querySelectorAll('.grade-item');
          
          gradeItems.forEach(item => {
            if (selectedQuarter === 'all' || item.dataset.quarter === selectedQuarter) {
              item.style.display = 'block';
            } else {
              item.style.display = 'none';
            }
          });
        });
      }

      // Logout confirmation
      const logoutBtn = document.getElementById('logout');
      if (logoutBtn) {
        logoutBtn.addEventListener('click', (e) => {
          e.preventDefault();
          if (confirm('Are you sure you want to sign out?')) {
            showToast('Signed out successfully', 'success');
            logoutBtn.closest('form').submit();
          }
        });
      }

      // Show error toast if present
      <?php if (!empty($error)): ?>
        showToast(<?php echo json_encode($error); ?>, 'error');
      <?php endif; ?>
    });

    // Handle grade details modal
    function showGradeModal(button) {
      const modal = document.getElementById('gradeDetailsModal');
      const subject = button.getAttribute('data-subject');
      const quarter = button.getAttribute('data-quarter');
      const ww = parseFloat(button.getAttribute('data-ww'));
      const pt = parseFloat(button.getAttribute('data-pt'));
      const qa = parseFloat(button.getAttribute('data-qa'));
      const finalGrade = (ww * 0.3) + (pt * 0.5) + (qa * 0.2);

      const modalTitle = document.getElementById('gradeDetailsModalLabel');
      modalTitle.textContent = `${subject} - ${quarter} Quarter`;

      document.getElementById('wwScore').textContent = ww.toFixed(2);
      document.getElementById('ptScore').textContent = pt.toFixed(2);
      document.getElementById('qaScore').textContent = qa.toFixed(2);
      document.getElementById('finalGrade').textContent = finalGrade.toFixed(2);

      modal.classList.remove('hidden');
      modal.classList.add('flex');
      document.body.style.overflow = 'hidden';
      showToast(`Viewing grades for ${subject} - ${quarter} Quarter`, 'info');
    }

    function closeModal() {
      const modal = document.getElementById('gradeDetailsModal');
      modal.classList.add('hidden');
      modal.classList.remove('flex');
      document.body.style.overflow = 'auto';
    }

    window.onclick = function(event) {
      const modal = document.getElementById('gradeDetailsModal');
      if (event.target === modal) {
        closeModal();
      }
    }
  </script>
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
          <button type="submit" class="px-4 py-2 rounded-lg bg-school-primary text-white hover:bg-green-700 flex items-center gap-2">
            <i class="fas fa-save"></i> Save
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    // Settings modal functionality
    (function(){
      const modal = document.getElementById('settingsModal');
      const openSettingsBtn = document.getElementById('settingsBtn');
      const closeSettingsBtn = document.getElementById('closeSettings');
      const cancelSettingsBtn = document.getElementById('cancelSettings');
      const settingsForm = document.getElementById('settingsForm');
      const headerNameEl = document.querySelector('header .font-semibold.text-school-primary');
      const headerGradeEl = document.querySelector('header .text-xs.text-gray-500');

      // Load saved data from localStorage
      const savedStudentInfo = (() => {
        try {
          return JSON.parse(localStorage.getItem('student_info') || 'null');
        } catch {
          return null;
        }
      })();

      // Modal functions
      function openSettingsModal() {
        fillSettingsForm();
        modal.classList.remove('hidden');
        modal.classList.add('flex');
      }

      function closeSettingsModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
      }

      // Fill the form with saved or default values
      function fillSettingsForm() {
        document.getElementById('set_first_name').value = savedStudentInfo?.first_name || '<?php echo addslashes($student['first_name'] ?? ''); ?>';
        document.getElementById('set_last_name').value = savedStudentInfo?.last_name || '<?php echo addslashes($student['last_name'] ?? ''); ?>';
        document.getElementById('set_student_id').value = savedStudentInfo?.student_id || '';
        document.getElementById('set_school_year').value = savedStudentInfo?.school_year || '';
        document.getElementById('set_grade_level').value = savedStudentInfo?.grade_level || '<?php echo addslashes($student['grade_level'] ?? ''); ?>';
        document.getElementById('set_section').value = savedStudentInfo?.section || '<?php echo addslashes($student['section'] ?? ''); ?>';
        document.getElementById('set_birthdate').value = savedStudentInfo?.birthdate || '';
        document.getElementById('set_gender').value = savedStudentInfo?.gender || '';
        document.getElementById('set_address').value = savedStudentInfo?.address || '';
        document.getElementById('set_parent_name').value = savedStudentInfo?.parent_name || '';
        document.getElementById('set_parent_contact').value = savedStudentInfo?.parent_contact || '';
      }

      // Event listeners
      if (openSettingsBtn) {
        openSettingsBtn.addEventListener('click', openSettingsModal);
      }
      
      if (closeSettingsBtn) {
        closeSettingsBtn.addEventListener('click', closeSettingsModal);
      }
      
      if (cancelSettingsBtn) {
        cancelSettingsBtn.addEventListener('click', closeSettingsModal);
      }
      
      modal.addEventListener('click', (e) => {
        if (e.target === modal) closeSettingsModal();
      });

      // Handle form submission
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
          // Save to localStorage
          localStorage.setItem('student_info', JSON.stringify(data));
          
          // Update UI
          if (headerNameEl) {
            headerNameEl.textContent = `${data.first_name} ${data.last_name}`.trim();
          }
          
          if (headerGradeEl) {
            headerGradeEl.innerHTML = `Grade ${data.grade_level} • Section ${data.section}`;
          }
          
          // Show success message
          if (typeof showToast === 'function') {
            showToast('Settings saved locally.', 'success');
          }
          
          closeSettingsModal();
        } catch (err) {
          console.error('Error saving settings:', err);
          if (typeof showToast === 'function') {
            showToast('Failed to save settings.', 'error');
          }
        }
      });
    })();
  </script>