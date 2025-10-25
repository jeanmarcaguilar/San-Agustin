<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Auto-sync student to patron (ensures student can borrow books)
require_once '../includes/sync_patron.php';

// Initialize database connection
$database = new Database();
$studentDb = $database->getConnection('student');
$librarianDb = $database->getConnection('librarian');

// Get user information
$userStmt = $studentDb->prepare("SELECT * FROM students WHERE id = ?");
$userStmt->execute([$_SESSION['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Generate initials for avatar
$initials = '';
if (!empty($user['first_name']) && !empty($user['last_name'])) {
    $initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
}

// Get search parameters
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
// Build query to get books from librarian database
$query = "SELECT * FROM books WHERE available > 0";
$params = [];

if (!empty($search)) {
    $query .= " AND (title LIKE ? OR author LIKE ? OR isbn = ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $search]);
}

if (!empty($category)) {
    $query .= " AND category = ?";
    $params[] = $category;
}

$query .= " ORDER BY title ASC";

// Execute query using librarian database
$stmt = $librarianDb->prepare($query);
$stmt->execute($params);
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter from librarian database
$categories = [];
$catStmt = $librarianDb->query("SELECT DISTINCT category FROM books WHERE category IS NOT NULL ORDER BY category");
$categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

// Borrowing is now handled by librarian only - students can only view available books
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Available Books - San Agustin Elementary School</title>
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
    
    .book-card {
      transition: all 0.3s ease;
      overflow: hidden;
    }
    
    .book-card:hover {
      transform: translateY(-8px);
      box-shadow: 0 12px 24px rgba(15,23,42,0.15);
    }
    
    .book-card img {
      transition: transform 0.3s ease;
    }
    
    .book-card:hover img {
      transform: scale(1.05);
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
        <a id="navSchedule" href="class_schedule.php" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center gap-2 px-3 py-3">
          <i class="fas fa-calendar"></i> Schedule
        </a>
        <div class="collapsible-section">
          <button id="booksBtn" class="w-full big-btn bg-green-600 hover:bg-green-500 text-white flex items-center justify-between px-3 py-3">
            <span class="flex items-center gap-2"><i class="fas fa-book"></i> Library Books</span>
            <span class="collapsible-arrow transition-transform">▼</span>
          </button>
          <div class="collapsible-content bg-green-900 rounded-lg mt-1 overflow-hidden">
            <a href="available-books.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors active-nav">
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
        <div class="flex items-center gap-3">          <button id="settingsBtn" type="button" class="p-2 rounded-full bg-white border hover:bg-gray-50" title="Student Settings">
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
        <header class="mb-6">
          <div class="flex justify-between items-center">
            <h2 class="text-xl font-bold text-school-primary flex items-center gap-2">
              <i class="fas fa-book-open"></i> Available Books
            </h2>
            <div class="flex items-center space-x-4">
              <span class="text-sm text-gray-600">
                <i class="fas fa-book-open mr-1"></i> 
                <?= array_sum(array_column($books, 'available')) ?> books available
              </span>
            </div>
          </div>
          
          <!-- Search and Filter -->
          <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="md:col-span-2">
              <form method="GET" class="relative">
                <input 
                  type="text" 
                  name="search" 
                  placeholder="Search by title, author, or ISBN..." 
                  class="w-full px-4 py-2 border rounded-full search-input pl-10"
                  value="<?= htmlspecialchars($search) ?>"
                >
                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
              </form>
            </div>
            <div>
              <form method="GET" class="flex gap-2">
                <select 
                  name="category" 
                  onchange="this.form.submit()"
                  class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-school-accent focus:border-school-accent"
                >
                  <option value="">All Categories</option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>" 
                      <?= $category === $cat ? 'selected' : '' ?>>
                      <?= htmlspecialchars($cat) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <?php if (!empty($search) || !empty($category)): ?>
                  <a href="available_books.php" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg flex items-center">
                    <i class="fas fa-times"></i>
                  </a>
                <?php endif; ?>
              </form>
            </div>
          </div>
        </header>

        <!-- Display success/error messages -->
        <?php if (isset($_SESSION['success'])): ?>
          <div class="toast success show">
            <i class="fas fa-check-circle toast-icon"></i>
            <div class="toast-message"><?= htmlspecialchars($_SESSION['success']) ?></div>
            <i class="fas fa-times toast-close" role="button" aria-label="Close notification"></i>
          </div>
          <?php unset($_SESSION['success']); ?>
        <?php endif; ?>

        <?php if (isset($error)): ?>
          <div class="toast error show">
            <i class="fas fa-times-circle toast-icon"></i>
            <div class="toast-message"><?= htmlspecialchars($error) ?></div>
            <i class="fas fa-times toast-close" role="button" aria-label="Close notification"></i>
          </div>
        <?php endif; ?>

        <!-- Books Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
          <?php if (count($books) > 0): ?>
            <?php foreach ($books as $book): ?>
              <div class="card book-card">
                <div class="relative bg-gradient-to-br from-blue-100 to-purple-100">
                  <?php 
                    $hasImage = !empty($book['cover_image']) && file_exists("../Uploads/books/" . $book['cover_image']);
                    $bookTitle = htmlspecialchars($book['title']);
                    $shortTitle = strlen($book['title']) > 30 ? substr($book['title'], 0, 30) . '...' : $book['title'];
                  ?>
                  
                  <?php if ($hasImage): ?>
                    <img 
                      src="../Uploads/books/<?= htmlspecialchars($book['cover_image']) ?>" 
                      alt="<?= $bookTitle ?>" 
                      class="w-full h-48 object-cover"
                      onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                    >
                  <?php endif; ?>
                  
                  <div class="w-full h-48 items-center justify-center bg-gradient-to-br from-blue-400 to-purple-500 <?= $hasImage ? 'hidden' : 'flex' ?>" style="<?= $hasImage ? 'display:none' : '' ?>">
                    <div class="text-center text-white p-4">
                      <i class="fas fa-book text-4xl mb-2"></i>
                      <p class="text-sm font-semibold"><?= htmlspecialchars($shortTitle) ?></p>
                      <p class="text-xs mt-1 opacity-75">by <?= htmlspecialchars($book['author']) ?></p>
                    </div>
                  </div>
                  <?php if ($book['available'] > 0): ?>
                    <div class="absolute top-2 right-2 bg-green-500 text-white text-xs font-bold px-2 py-1 rounded-full">
                      <?= $book['available'] ?> available
                    </div>
                  <?php else: ?>
                    <div class="absolute top-2 right-2 bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full">
                      Out of stock
                    </div>
                  <?php endif; ?>
                </div>
                <div class="p-4">
                  <h3 class="font-bold text-lg mb-1 text-gray-800"><?= htmlspecialchars($book['title']) ?></h3>
                  <p class="text-gray-600 text-sm mb-2">by <?= htmlspecialchars($book['author']) ?></p>
                  <p class="text-gray-700 text-sm mb-3 line-clamp-2"><?= htmlspecialchars($book['description'] ?? 'No description available.') ?></p>
                  
                  <div class="flex justify-between items-center mt-4">
                    <span class="text-sm text-gray-500">
                      <i class="fas fa-map-marker-alt mr-1"></i> <?= htmlspecialchars($book['location'] ?? 'N/A') ?>
                    </span>
                    <span class="text-xs text-gray-500 italic">
                      <i class="fas fa-info-circle mr-1"></i> Contact librarian to borrow
                    </span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="col-span-full text-center py-10">
              <i class="fas fa-book-open text-4xl text-gray-300 mb-3"></i>
              <p class="text-gray-500">No books found matching your criteria.</p>
              <?php if (!empty($search) || !empty($category)): ?>
                <a href="available_books.php" class="text-school-accent hover:underline mt-2 inline-block">
                  Clear filters
                </a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
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
        
        if (!button || !content) return; // Skip if no button or content found
        
        // Set initial state (open if on current page or has active child)
        const isActive = section.querySelector('.active-nav');
        
        if (isActive) {
          content.style.maxHeight = content.scrollHeight + 'px';
          if (arrow) arrow.classList.add('rotate-180');
        } else {
          content.style.maxHeight = '0';
          if (arrow) arrow.classList.remove('rotate-180');
        }
        
        // Toggle on button click
        button.addEventListener('click', function(e) {
          e.preventDefault();
          
          // Toggle the collapsible content
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

      // Simulate online users
      function simulateOnlineUsers() {
        const onlineCount = document.getElementById('onlineCount');
        setInterval(() => {
          const baseCount = 20;
          const fluctuation = Math.floor(Math.random() * 10);
          onlineCount.textContent = (baseCount + fluctuation) + ' students online';
        }, 10000);
      }

      // Initialize on load
      initNotifications();
      simulateOnlineUsers();

      // Show success/error toasts if present
      <?php if (isset($_SESSION['success'])): ?>
        showToast(<?php echo json_encode($_SESSION['success']); ?>, 'success');
      <?php endif; ?>
      <?php if (isset($error)): ?>
        showToast(<?php echo json_encode($error); ?>, 'error');
      <?php endif; ?>
    });


    // Logout confirmation
    document.getElementById('logout').addEventListener('click', (e) => {
      e.preventDefault();
      if (confirm('Are you sure you want to sign out?')) {
        showToast('Signed out successfully', 'success');
        document.querySelector('form[action$="logout.php"]').submit();
      }
    });
  </script>
  <script>
    (function(){
      const openSettingsBtn = document.getElementById('settingsBtn');
      const headerNameEl = document.querySelector('header .font-semibold.text-school-primary');
      const headerGradeEl = document.querySelector('header .text-xs.text-gray-500');
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
      modal.addEventListener('click', (e)=>{ if(e.target===modal) closeSettings(); });
      modal.querySelector('#closeSettings').addEventListener('click', closeSettings);
      modal.querySelector('#cancelSettings').addEventListener('click', closeSettings);
      const saved = (()=>{ try{ return JSON.parse(localStorage.getItem('student_info')||'null'); }catch{return null;} })();
      function fill(){
        document.getElementById('set_first_name').value = (saved && saved.first_name) || <?php echo json_encode($user['first_name'] ?? ''); ?>;
        document.getElementById('set_last_name').value  = (saved && saved.last_name)  || <?php echo json_encode($user['last_name'] ?? ''); ?>;
        document.getElementById('set_student_id').value = (saved && saved.student_id) || '';
        document.getElementById('set_school_year').value= (saved && saved.school_year)|| '';
        document.getElementById('set_grade_level').value= (saved && saved.grade_level)|| <?php echo json_encode($user['grade_level'] ?? ''); ?>;
        document.getElementById('set_section').value    = (saved && saved.section)    || <?php echo json_encode($user['section'] ?? ''); ?>;
      }
      if (openSettingsBtn) openSettingsBtn.addEventListener('click', ()=>{ fill(); openSettings(); });
      modal.querySelector('#settingsForm').addEventListener('submit', (e)=>{
        e.preventDefault();
        const data = {
          first_name: document.getElementById('set_first_name').value.trim(),
          last_name:  document.getElementById('set_last_name').value.trim(),
          student_id: document.getElementById('set_student_id').value.trim(),
          school_year: document.getElementById('set_school_year').value.trim(),
          grade_level: document.getElementById('set_grade_level').value.trim(),
          section:    document.getElementById('set_section').value.trim()
        };
        try {
          localStorage.setItem('student_info', JSON.stringify(data));
          if (headerNameEl) headerNameEl.textContent = `${data.first_name} ${data.last_name}`.trim();
          if (headerGradeEl) headerGradeEl.innerHTML = `Grade ${data.grade_level} - Section ${data.section}`;
          if (typeof showToast==='function') showToast('Settings saved locally.', 'success');
          closeSettings();
        } catch { if (typeof showToast==='function') showToast('Failed to save settings.', 'error'); }
      });
    })();
  </script>

</body>
</html>

