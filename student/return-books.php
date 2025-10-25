<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Initialize database connection
$database = new Database();
$studentDb = $database->getConnection('student');
$librarianDb = $database->getConnection('librarian');

// Get user information from student database
$userStmt = $studentDb->prepare("SELECT * FROM students WHERE user_id = ?");
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

// Handle book return
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_book'])) {
    $loanId = $_POST['loan_id'] ?? 0;
    $bookId = $_POST['book_id'] ?? 0;
    
    try {
        // Get patron_id from user_id
        $patronQuery = "SELECT patron_id FROM patrons WHERE user_id = :user_id";
        $patronStmt = $librarianDb->prepare($patronQuery);
        $patronStmt->execute([':user_id' => $_SESSION['user_id']]);
        $patronData = $patronStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patronData) {
            throw new Exception('Patron record not found. Please contact the librarian.');
        }
        
        // Start transaction on librarian database
        $librarianDb->beginTransaction();
        
        // Update the transaction record
        $updateLoan = $librarianDb->prepare("
            UPDATE transactions 
            SET return_date = NOW(), 
                status = 'returned',
                updated_at = NOW()
            WHERE id = ? AND patron_id = ? AND status = 'checked_out'
        ");
        $updateLoan->execute([$loanId, $patronData['patron_id']]);
        
        if ($updateLoan->rowCount() > 0) {
            // Increment available copies
            $updateBook = $librarianDb->prepare("
                UPDATE books 
                SET available = available + 1,
                    updated_at = NOW()
                WHERE id = ?
            ");
            $updateBook->execute([$bookId]);
            
            $librarianDb->commit();
            $_SESSION['success'] = 'Book returned successfully!';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
            exit();
        } else {
            throw new Exception('No matching book found or book already returned.');
        }
    } catch (Exception $e) {
        $librarianDb->rollBack();
        $error = $e->getMessage();
    }
}

// Get search parameter
$search = $_GET['search'] ?? '';

// Get patron_id from user_id
$patronQuery = "SELECT patron_id FROM patrons WHERE user_id = :user_id";
$patronStmt = $librarianDb->prepare($patronQuery);
$patronStmt->execute([':user_id' => $_SESSION['user_id']]);
$patron = $patronStmt->fetch(PDO::FETCH_ASSOC);

$borrowedBooks = [];

if ($patron) {
    // Build query to get borrowed books with book details from librarian database
    $query = "
        SELECT t.id as loan_id, t.checkout_date, t.due_date, 
               b.id as book_id, b.title, b.author, b.isbn, b.cover_image
        FROM transactions t
        JOIN books b ON t.book_id = b.id
        WHERE t.patron_id = :patron_id 
        AND t.status = 'checked_out'
    ";

    $params = [':patron_id' => $patron['patron_id']];

    // Add search condition if search term exists
    if (!empty($search)) {
        $query .= " AND (b.title LIKE :search OR b.author LIKE :search OR b.isbn = :exact_search)";
        $searchTerm = "%$search%";
        $params[':search'] = $searchTerm;
        $params[':exact_search'] = $search;
    }

    $query .= " ORDER BY t.due_date ASC";

    // Execute query on librarian database
    $stmt = $librarianDb->prepare($query);
    $stmt->execute($params);
    $borrowedBooks = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>📚 Return Books – San Agustin Elementary School</title>
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
    
    .status-badge {
      display: inline-block;
      padding: 0.25rem 0.5rem;
      border-radius: 9999px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: capitalize;
    }
    
    .status-borrowed { background-color: #dbeafe; color: #1e40af; }
    .status-overdue { background-color: #fee2e2; color: #991b1b; }
    
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
            <a href="available-books.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors">
              <i class="fas fa-book-open mr-2"></i> Available Books
            </a>
            <a href="borrow-history.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors">
              <i class="fas fa-history mr-2"></i> My Borrowed Books
            </a>
            <a href="return-books.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors active-nav">
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
        <a href="../logout.php" class="w-full bg-red-600 hover:bg-red-500 big-btn flex items-center justify-center gap-2">
          <i class="fas fa-sign-out-alt"></i> Sign Out
        </a>
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
            <i class="fas fa-undo"></i> Return Books
          </h1>
          <span class="hidden md:inline text-sm text-gray-600">
            Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>! 🎉
          </span>
        </div>
        <div class="flex items-center gap-3">
          <div class="hidden sm:flex items-center bg-white rounded-full border px-3 py-1 shadow-sm">
            <span class="text-sm text-green-600 font-medium mr-2"><i class="fas fa-circle animate-pulse"></i> Online</span>
            <span id="onlineCount" class="text-xs text-gray-500">24 students online</span>
          </div>
          <div class="relative">
            <form method="GET" class="flex items-center">
              <input 
                type="text" 
                name="search" 
                placeholder="Search borrowed books..." 
                class="px-4 py-2 rounded-full border w-64 search-input pl-10"
                value="<?php echo htmlspecialchars($search); ?>"
              >
              <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
              <?php if (!empty($search)): ?>
                <a href="return-books.php" class="ml-2 p-2 text-gray-500 hover:text-gray-700">
                  <i class="fas fa-times"></i>
                </a>
              <?php endif; ?>
            </form>
          </div>
          <button id="settingsBtn" class="p-2 rounded-full bg-white border hover:bg-gray-50" title="Student Settings">
            <i class="fas fa-cog text-gray-700"></i>
          </button>
          <button id="notifBtn" class="p-2 rounded-full bg-school-secondary relative" title="Notifications">
            <i class="fas fa-bell"></i>
            <span id="notifCount" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">3</span>
          </button>
          <div class="flex items-center gap-2 p-2 rounded-full bg-white shadow-sm border">
            <div class="w-9 h-9 rounded-full bg-school-secondary text-school-primary font-bold flex items-center justify-center">
              <?php echo $initials; ?>
            </div>
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
        <div class="flex justify-between items-center mb-6">
          <h2 class="text-xl font-bold text-school-primary flex items-center gap-2">
            <i class="fas fa-undo"></i> Return Books
          </h2>
          <div class="text-sm text-gray-600">
            <?php echo count($borrowedBooks); ?> book(s) currently borrowed
          </div>
        </div>
        
        <?php if (!empty($error)): ?>
          <div class="mb-4 p-4 bg-red-50 text-red-700 rounded-lg border border-red-200">
            <i class="fas fa-exclamation-circle mr-2"></i> <?php echo htmlspecialchars($error); ?>
          </div>
        <?php endif; ?>
        
        <?php if (empty($borrowedBooks)): ?>
          <div class="text-center py-12">
            <i class="fas fa-check-circle text-4xl text-green-500 mb-3"></i>
            <p class="text-gray-700 font-medium">All books have been returned!</p>
            <p class="text-gray-500 mt-1">You don't have any books to return at the moment.</p>
            <a href="available-books.php" class="inline-block mt-4 text-school-accent hover:underline">
              <i class="fas fa-book-open mr-1"></i> Browse available books
            </a>
          </div>
        <?php else: ?>
          <div class="overflow-x-auto">
            <table class="w-full text-sm text-left text-gray-700">
              <thead class="text-xs text-gray-700 uppercase bg-gray-50">
                <tr>
                  <th scope="col" class="px-6 py-3">Book</th>
                  <th scope="col" class="px-6 py-3">Book ID</th>
                  <th scope="col" class="px-6 py-3">Borrowed On</th>
                  <th scope="col" class="px-6 py-3">Due Date</th>
                  <th scope="col" class="px-6 py-3 text-center">Status</th>
                  <th scope="col" class="px-6 py-3">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($borrowedBooks as $book): 
                  $dueDate = new DateTime($book['due_date']);
                  $today = new DateTime();
                  $isOverdue = $dueDate < $today;
                ?>
                  <tr class="bg-white border-b hover:bg-gray-50">
                    <td class="px-6 py-4">
                      <div class="flex items-center gap-3">
                        <div class="w-12 h-16 bg-gradient-to-br from-blue-100 to-purple-100 rounded overflow-hidden flex-shrink-0">
                          <?php 
                            $hasImage = !empty($book['cover_image']) && file_exists("../Uploads/books/" . $book['cover_image']);
                          ?>
                          <?php if ($hasImage): ?>
                            <img 
                              src="../Uploads/books/<?php echo htmlspecialchars($book['cover_image']); ?>" 
                              alt="<?php echo htmlspecialchars($book['title']); ?>" 
                              class="w-full h-full object-cover"
                              onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            >
                          <?php endif; ?>
                          <div class="w-full h-full items-center justify-center bg-gradient-to-br from-blue-400 to-purple-500 <?php echo $hasImage ? 'hidden' : 'flex'; ?>" style="<?php echo $hasImage ? 'display:none' : ''; ?>">
                            <i class="fas fa-book text-white text-lg"></i>
                          </div>
                        </div>
                        <div>
                          <div class="font-medium text-gray-900"><?php echo htmlspecialchars($book['title']); ?></div>
                          <div class="text-xs text-gray-500"><?php echo htmlspecialchars($book['author']); ?></div>
                        </div>
                      </div>
                    </td>
                    <td class="px-6 py-4">
                      <span class="bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded">
                        <?php echo htmlspecialchars($book['isbn'] ?? 'N/A'); ?>
                      </span>
                    </td>
                    <td class="px-6 py-4">
                      <?php echo date('M d, Y', strtotime($book['checkout_date'])); ?>
                    </td>
                    <td class="px-6 py-4">
                      <?php echo date('M d, Y', strtotime($book['due_date'])); ?>
                      <?php if ($isOverdue): ?>
                        <span class="text-xs text-red-500 block">Overdue</span>
                      <?php endif; ?>
                    </td>
                    <td class="px-6 py-4 text-center">
                      <span class="status-badge <?php echo $isOverdue ? 'status-overdue' : 'status-borrowed'; ?>">
                        <?php echo $isOverdue ? 'Overdue' : 'Borrowed'; ?>
                      </span>
                    </td>
                    <td class="px-6 py-4 text-center">
                      <span class="text-sm text-gray-600 italic">
                        <i class="fas fa-info-circle mr-1"></i>
                        Contact librarian to return
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
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
          © <?php echo date('Y'); ?> San Agustin Elementary School Student Portal • Learning is Fun!
        </div>
      </footer>
    </main>
  </div>
  
  <div id="confettiRoot">
    <div id="toastContainer" class="fixed top-4 right-4 z-[10000] space-y-2">
      <?php if (isset($_SESSION['success'])): ?>
        <div class="toast success show">
          <i class="fas fa-check-circle toast-icon"></i>
          <div class="toast-message"><?php echo htmlspecialchars($_SESSION['success']); ?></div>
          <i class="fas fa-times toast-close" role="button" aria-label="Close notification"></i>
        </div>
        <?php unset($_SESSION['success']); ?>
      <?php endif; ?>
    </div>
  </div>

  <script>
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
        
        // Set initial state (open if on current page)
        const isActive = section.querySelector('.active-nav');
        
        if (isActive) {
          content.style.maxHeight = content.scrollHeight + 'px';
          if (arrow) arrow.style.transform = 'rotate(180deg)';
        } else {
          content.style.maxHeight = '0';
        }
        
        // Toggle on click
        if (button) {
          button.addEventListener('click', function(e) {
            e.preventDefault(); // Prevent default form submission
            if (content.style.maxHeight === '0px' || !content.style.maxHeight) {
              content.style.maxHeight = content.scrollHeight + 'px';
              if (arrow) arrow.style.transform = 'rotate(180deg)';
            } else {
              content.style.maxHeight = '0';
              if (arrow) arrow.style.transform = 'rotate(0deg)';
            }
            // Prevent the click from propagating to parent elements
            e.stopPropagation();
            return false;
          });
        }
        
        // Make sure the collapsible content doesn't close when clicking inside it
        content.addEventListener('click', (e) => {
          e.stopPropagation();
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
        notifBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          notificationDropdown.classList.toggle('hidden');
          if (!notificationDropdown.classList.contains('hidden')) {
            notifications = 0;
            notifCountEl.textContent = '0';
          }
        });
      }
      
      // Close notification dropdown when clicking outside
      document.addEventListener('click', (e) => {
        if (notifBtn && !notifBtn.contains(e.target) && notificationDropdown && !notificationDropdown.contains(e.target)) {
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

      // Initialize on load
      initNotifications();
      simulateOnlineUsers();
    });

    // Toast notification function
    function showToast(message, type = 'info') {
      const toastContainer = document.getElementById('toastContainer');
      if (!toastContainer) return;
      
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
      
      // Trigger reflow to enable transition
      toast.offsetHeight;
      toast.classList.add('show');
      
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

    // Confetti effect
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

    // Show success message if present in URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('success')) {
      showToast('Book returned successfully!', 'success');
      fireConfetti(20);
      
      // Clean up URL
      const cleanUrl = window.location.pathname;
      window.history.replaceState({}, document.title, cleanUrl);
    }

    // Settings modal
    (function(){
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
        </div>`;
      document.body.appendChild(modal);

      // Get elements
      const openSettingsBtn = document.getElementById('settingsBtn');
      const closeSettingsBtn = document.getElementById('closeSettings');
      const cancelSettingsBtn = document.getElementById('cancelSettings');
      const settingsForm = document.getElementById('settingsForm');
      const headerNameEl = document.querySelector('header .font-semibold.text-school-primary');
      const headerGradeEl = document.querySelector('header .text-xs.text-gray-500');

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

      // Load saved data from localStorage
      const savedStudentInfo = (() => {
        try {
          return JSON.parse(localStorage.getItem('student_info') || 'null');
        } catch {
          return null;
        }
      })();

      // Fill the form with saved or default values
      function fillSettingsForm() {
        document.getElementById('set_first_name').value = savedStudentInfo?.first_name || '<?php echo addslashes($user['first_name'] ?? ''); ?>';
        document.getElementById('set_last_name').value = savedStudentInfo?.last_name || '<?php echo addslashes($user['last_name'] ?? ''); ?>';
        document.getElementById('set_student_id').value = savedStudentInfo?.student_id || '';
        document.getElementById('set_school_year').value = savedStudentInfo?.school_year || '';
        document.getElementById('set_grade_level').value = savedStudentInfo?.grade_level || '<?php echo addslashes($user['grade_level'] ?? ''); ?>';
        document.getElementById('set_section').value = savedStudentInfo?.section || '<?php echo addslashes($user['section'] ?? ''); ?>';
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
</body>
</html>
