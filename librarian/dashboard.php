
<?php
// Add this line at the top
require_once '../includes/session_config.php';
require_once '../includes/auth.php';

$auth = new Auth();

// Enable error reporting for debugging, but suppress output to browser
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');
error_reporting(E_ALL);

session_start();

// Check if user is logged in and is a librarian
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'librarian') {
    header('Location: /San%20Agustin/login.php');
    exit();
}

try {
    // Include necessary files
    $databaseFile = __DIR__ . '/../config/database.php';
    if (!file_exists($databaseFile)) {
        error_log('Database configuration file not found: ' . $databaseFile);
        throw new Exception('Internal server error. Please contact the administrator.');
    }
    require_once $databaseFile;
    
    // Create database instance
    if (!class_exists('Database')) {
        error_log('Database class not found in database.php');
        throw new Exception('Internal server error. Please contact the administrator.');
    }
    $database = new Database();
    
    // 1. Get user info from login_db
    $loginConn = $database->getConnection('');
    if (!$loginConn) {
        error_log('Failed to connect to login_db');
        throw new Exception('Database connection failed.');
    }
    $user_id = $_SESSION['user_id'];
    
    $query = "SELECT id, username, email, role FROM users WHERE id = :user_id AND role = 'librarian' LIMIT 1";
    $stmt = $loginConn->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        error_log('User not found or unauthorized access for user_id: ' . $user_id);
        throw new Exception('User not found or unauthorized access');
    }
    
    // 2. Get librarian details from librarian_db
    $librarianConn = $database->getConnection('librarian');
    if (!$librarianConn) {
        error_log('Failed to connect to librarian_db');
        throw new Exception('Database connection failed.');
    }
    
    // Check if librarians table exists, if not create it
    $checkTable = $librarianConn->query("SHOW TABLES LIKE 'librarians'");
    if ($checkTable->rowCount() == 0) {
        $createTable = "CREATE TABLE IF NOT EXISTS `librarians` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `librarian_id` varchar(20) NOT NULL,
            `first_name` varchar(50) NOT NULL,
            `last_name` varchar(50) NOT NULL,
            `contact_number` varchar(20) DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `librarian_id` (`librarian_id`),
            KEY `user_id` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $librarianConn->exec($createTable);
    }
    
    // Get or create librarian profile
    $query = "SELECT * FROM librarians WHERE user_id = :user_id LIMIT 1";
    $stmt = $librarianConn->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $librarianData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$librarianData) {
        $librarian_id = 'LIB' . strtoupper(substr($user['username'], 0, 3)) . str_pad($user_id, 4, '0', STR_PAD_LEFT);
        $first_name = ucfirst($user['username']);
        $last_name = 'Librarian';
        
        $query = "INSERT INTO librarians (user_id, librarian_id, first_name, last_name) 
                 VALUES (:user_id, :librarian_id, :first_name, :last_name)";
        $stmt = $librarianConn->prepare($query);
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':librarian_id' => $librarian_id,
            ':first_name' => $first_name,
            ':last_name' => $last_name
        ]);
        
        $librarianData = [
            'id' => $librarianConn->lastInsertId(),
            'user_id' => $user_id,
            'librarian_id' => $librarian_id,
            'first_name' => $first_name,
            'last_name' => $last_name
        ];
    }
    
    // Combine user and librarian data
    $userData = array_merge($user, $librarianData);
    $pageTitle = 'Librarian Dashboard - ' . htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']);
    
    // Get statistics for the dashboard
    $stats = [];
    
    // Get total books count
    $query = "SELECT COUNT(*) as total_books, SUM(available) as available_books FROM books";
    $stmt = $librarianConn->query($query);
    $bookStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get total patrons count
    $query = "SELECT COUNT(*) as total_patrons FROM patrons WHERE status = 'active'";
    $stmt = $librarianConn->query($query);
    $patronStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get active loans count
    $query = "SELECT COUNT(*) as active_loans FROM book_loans WHERE status = 'checked_out' OR status = 'overdue'";
    $stmt = $librarianConn->query($query);
    $loanStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get overdue books count
    $query = "SELECT COUNT(*) as overdue_books FROM book_loans WHERE due_date < NOW() AND status = 'checked_out'";
    $stmt = $librarianConn->query($query);
    $overdueStats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent checkouts
    $query = "SELECT bl.*, b.title as book_title, 
                     CONCAT(p.first_name, ' ', p.last_name) as patron_name
              FROM book_loans bl
              JOIN books b ON bl.book_id = b.id
              JOIN patrons p ON bl.patron_id = p.patron_id
              WHERE bl.status IN ('checked_out', 'overdue')
              ORDER BY bl.due_date ASC
              LIMIT 5";
    $recentCheckouts = $librarianConn->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
    // Get book categories with counts
    $query = "SELECT c.category_name, COUNT(b.id) as book_count
              FROM categories c
              LEFT JOIN books b ON c.category_id = b.category_id
              GROUP BY c.category_name
              ORDER BY book_count DESC
              LIMIT 6";
    $categories = $librarianConn->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
    // Get overdue books with patron info
    $query = "SELECT bl.*, b.title as book_title, 
                     CONCAT(p.first_name, ' ', p.last_name) as patron_name,
                     DATEDIFF(NOW(), bl.due_date) as days_overdue
              FROM book_loans bl
              JOIN books b ON bl.book_id = b.id
              JOIN patrons p ON bl.patron_id = p.patron_id
              WHERE bl.due_date < NOW() 
              AND bl.status = 'checked_out'
              ORDER BY bl.due_date ASC
              LIMIT 3";
    $overdueBooks = $librarianConn->query($query)->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total books
    $result = $librarianConn->query("SELECT COUNT(*) as total_books, SUM(available) as available_books FROM books");
    $stats = $result->fetch(PDO::FETCH_ASSOC);
    
    // Get overdue books count from book_loans
    $result = $librarianConn->query("
        SELECT COUNT(*) as total_overdue 
        FROM book_loans 
        WHERE status = 'checked_out' AND due_date < NOW()
        ");
    $overdue = $result->fetch(PDO::FETCH_ASSOC);
    $stats['overdue_books'] = $overdue['total_overdue'] ?? 0;

} catch (PDOException $e) {
    error_log('Database Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    die('Internal server error. Please contact the administrator.');
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    die('Internal server error. Please contact the administrator.');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📚 Librarian Portal – Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eefafc',
                            100: '#d5f2f6',
                            200: '#afe4ed',
                            300: '#78d0e0',
                            400: '#39b4cc',
                            500: '#1d98b0',
                            600: '#1b7a96',
                            700: '#1d637a',
                            800: '#215265',
                            900: '#204456',
                        },
                        secondary: {
                            50: '#f5f8f7',
                            100: '#dfe8e6',
                            200: '#bed1cd',
                            300: '#95b2ac',
                            400: '#6f8f89',
                            500: '#55736e',
                            600: '#425c58',
                            700: '#384b48',
                            800: '#303d3b',
                            900: '#2b3534',
                        },
                        dark: {
                            50: '#f8fafc',
                            100: '#f1f5f9',
                            200: '#e2e8f0',
                            300: '#cbd5e1',
                            400: '#94a3b8',
                            500: '#64748b',
                            600: '#475569',
                            700: '#334155',
                            800: '#1e293b',
                            900: '#0f172a',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: #334155;
            min-height: 100vh;
        }
        .sidebar {
            transition: all 0.3s ease;
            background: linear-gradient(to bottom, #2b3534 0%, #384b48 100%);
        }
        .sidebar.collapsed {
            width: 70px;
        }
        .sidebar.collapsed .sidebar-text,
        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .user-text,
        .sidebar.collapsed .events-title,
        .sidebar.collapsed .event-details {
            display: none;
        }
        .sidebar.collapsed .logo-container {
            margin: 0 auto;
        }
        .sidebar.collapsed .user-initials {
            margin: 0 auto;
        }
        .sidebar.collapsed .nav-item {
            justify-content: center;
            padding: 0.75rem;
        }
        .sidebar.collapsed .nav-item i {
            margin-right: 0;
        }
        .sidebar.collapsed .submenu {
            display: none !important;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 40;
                height: 100vh;
                width: 250px;
            }
            .sidebar-open {
                transform: translateX(0);
            }
            .overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 30;
            }
            .overlay-open {
                display: block;
            }
        }
        .dashboard-card {
            transition: all 0.3s ease;
            background: white;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .notification-dot {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #1d98b0;
            color: white;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .header-bg {
            background: linear-gradient(to right, #2b3534 0%, #384b48 100%);
        }
        .logo-container {
            background: linear-gradient(135deg, #1d98b0 0%, #39b4cc 100%);
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-in-out;
        }
        .submenu.open {
            max-height: 500px;
        }
        .rotate-90 {
            transform: rotate(90deg);
            transition: transform 0.3s ease;
        }
        .notification-panel {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            z-index: 50;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .notification-panel.open {
            max-height: 400px;
        }
        .user-menu {
            position: absolute;
            top: calc(100% + 0.5rem);
            right: 0;
            width: 180px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            border: 1px solid rgba(0, 0, 0, 0.1);
            z-index: 50;
            padding: 0.75rem 0;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: opacity 0.3s, visibility 0.3s, transform 0.3s;
        }
        .user-menu.user-menu-open {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
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
            border-left-color: #1d98b0;
        }
        .toast.info {
            border-left-color: #39b4cc;
        }
        .toast.warning {
            border-left-color: #facc15;
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
    </style>
</head>
<body class="min-h-screen flex">
    <!-- Overlay for mobile sidebar -->
    <div id="overlay" class="overlay" onclick="closeSidebar()"></div>

    <!-- Sidebar -->
    <div id="sidebar" class="sidebar w-64 min-h-screen flex flex-col text-white">
        <!-- School Logo -->
        <div class="p-5 border-b border-secondary-700 flex flex-col items-center">
            <div class="logo-container w-16 h-16 rounded-full flex items-center justify-center text-white font-bold text-2xl mb-3 shadow-md">
                <i class="fas fa-book"></i>
            </div>
            <h1 class="text-xl font-bold text-center logo-text">San Agustin Elementary School</h1>
            <p class="text-xs text-secondary-200 mt-1 logo-text">Library Management System</p>
        </div>
        
        <!-- User Profile -->
        <div class="p-5 border-b border-secondary-700">
            <div class="flex items-center space-x-3">
                <div class="w-12 h-12 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold shadow-md user-initials">
                    <?php echo htmlspecialchars($userData ? strtoupper(substr($userData['first_name'], 0, 1) . substr($userData['last_name'], 0, 1)) : 'LB'); ?>
                </div>
                <div class="user-text">
                    <h2 class="font-bold text-white"><?php echo htmlspecialchars($userData ? trim($userData['first_name'] . ' ' . $userData['last_name']) : 'Librarian'); ?></h2>
                    <p class="text-xs text-secondary-200"><?php echo htmlspecialchars($userData ? ($userData['role'] ?? 'Head Librarian') : 'Head Librarian'); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Navigation -->
        <div class="flex-1 p-4 overflow-y-auto custom-scrollbar">
            <ul class="space-y-2">
                <li>
                    <a href="dashboard.php" class="flex items-center p-3 rounded-lg bg-primary-600 text-white shadow-md nav-item">
                        <i class="fas fa-home w-5"></i>
                        <span class="ml-3 sidebar-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <button class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item w-full text-left" onclick="toggleSubmenu('catalog-submenu', this)">
                        <i class="fas fa-book w-5"></i>
                        <span class="ml-3 sidebar-text">Catalog Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </button>
                    <div id="catalog-submenu" class="submenu pl-4 mt-1">
                        <a href="add_book.php" class="flex items-center p-2 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-plus w-5"></i>
                            <span class="ml-3 sidebar-text">Add New Books</span>
                        </a>
                        <a href="books.php" class="flex items-center p-2 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-eye w-5"></i>
                            <span class="ml-3 sidebar-text">View Catalog</span>
                        </a>
                        <a href="catalog_reports.php" class="flex items-center p-2 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-print w-5"></i>
                            <span class="ml-3 sidebar-text">Catalog Reports</span>
                        </a>
                    </div>
                </li>
                <li>
                    <a href="checkouts.php" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-exchange-alt w-5"></i>
                        <span class="ml-3 sidebar-text">Circulation</span>
                    </a>
                </li>
                <li>
                    <button class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item w-full text-left" onclick="toggleSubmenu('patrons-submenu', this)">
                        <i class="fas fa-users w-5"></i>
                        <span class="ml-3 sidebar-text">Patron Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </button>
                    <div id="patrons-submenu" class="submenu pl-4 mt-1">
                        <a href="patrons.php" class="flex items-center p-2 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-list w-5"></i>
                            <span class="ml-3 sidebar-text">View Patrons</span>
                        </a>
                        <a href="borrowing_history.php" class="flex items-center p-2 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-history w-5"></i>
                            <span class="ml-3 sidebar-text">Borrowing History</span>
                        </a>
                    </div>
                </li>
                <li>
                    <a href="reading_program.php" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-book-reader w-5"></i>
                        <span class="ml-3 sidebar-text">Reading Programs</span>
                    </a>
                </li>
                <li>
                    <button class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item w-full text-left" onclick="toggleSubmenu('reports-submenu', this)">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Analytics</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </button>
                    <div id="reports-submenu" class="submenu pl-4 mt-1">
                        <a href="circulation_reports.php" class="flex items-center p-2 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-exchange-alt w-5"></i>
                            <span class="ml-3 sidebar-text">Circulation Reports</span>
                        </a>
                        <a href="popular_books.php" class="flex items-center p-2 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-star w-5"></i>
                            <span class="ml-3 sidebar-text">Popular Books</span>
                        </a>
                        <a href="inventory.php" class="flex items-center p-2 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-clipboard-check w-5"></i>
                            <span class="ml-3 sidebar-text">Inventory Reports</span>
                        </a>
                    </div>
                </li>
                </li>
            </ul>
            
            <!-- Upcoming Events -->
            <div class="mt-10 p-4 bg-secondary-800 rounded-lg events-container">
                <h3 class="text-sm font-bold text-white mb-3 flex items-center events-title">
                    <i class="fas fa-calendar-day mr-2"></i>Upcoming Library Events
                </h3>
                <div class="space-y-3 event-details">
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">28</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Book Fair</p>
                            <p class="text-xs text-secondary-300">10:00 AM - Library Hall</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">30</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Storytelling Session</p>
                            <p class="text-xs text-secondary-300">2:00 PM - Reading Room</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">2</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Library Orientation</p>
                            <p class="text-xs text-secondary-300">All Day - Main Library</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">5</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Reading Challenge Kickoff</p>
                            <p class="text-xs text-secondary-300">9:00 AM - Library Hall</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="p-4 border-t border-secondary-700">
            <button onclick="toggleSidebarCollapse()" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors w-full justify-center">
                <i class="fas fa-chevron-left" id="collapse-icon"></i>
                <span class="ml-3 sidebar-text">Collapse Sidebar</span>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col">
        <!-- Header -->
        <header class="header-bg text-white p-4 flex items-center justify-between shadow-md sticky top-0 z-50">
            <div class="flex items-center">
                <button id="sidebar-toggle" class="md:hidden text-white mr-4 focus:outline-none" onclick="toggleSidebar()">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl font-bold">Librarian Dashboard</h1>
            </div>
            
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <button id="notification-btn" class="text-white hover:text-primary-200 transition-colors relative" onclick="toggleNotifications()">
                        <i class="fas fa-bell text-xl"></i>
                        <span class="notification-dot">5</span>
                    </button>
                    
                    <!-- Notification Panel -->
                    <div id="notification-panel" class="notification-panel">
                        <div class="p-4 border-b border-gray-200">
                            <h3 class="font-bold text-gray-800">Notifications</h3>
                        </div>
                        <div class="overflow-y-auto max-h-72">
                            <div class="p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                                <div class="flex items-start">
                                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                                        <i class="fas fa-exclamation-circle text-blue-500"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-800">Overdue Book</p>
                                        <p class="text-xs text-gray-500">Book overdue by John Doe</p>
                                        <p class="text-xs text-gray-400">Sep 25, 2025, 10:30 AM</p>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                                <div class="flex items-start">
                                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                                        <i class="fas fa-book text-blue-500"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-800">New Book Added</p>
                                        <p class="text-xs text-gray-500">"The Hobbit" added to catalog</p>
                                        <p class="text-xs text-gray-400">Sep 24, 2025, 2:15 PM</p>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                                <div class="flex items-start">
                                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                                        <i class="fas fa-exchange-alt text-blue-500"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-800">Book Checked Out</p>
                                        <p class="text-xs text-gray-500">"Charlotte's Web" checked out</p>
                                        <p class="text-xs text-gray-400">Sep 24, 2025, 11:00 AM</p>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                                <div class="flex items-start">
                                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                                        <i class="fas fa-undo text-blue-500"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-800">Book Returned</p>
                                        <p class="text-xs text-gray-500">"Matilda" returned on time</p>
                                        <p class="text-xs text-gray-400">Sep 23, 2025, 3:45 PM</p>
                                    </div>
                                </div>
                            </div>
                            <div class="p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer">
                                <div class="flex items-start">
                                    <div class="bg-blue-100 p-2 rounded-full mr-3">
                                        <i class="fas fa-calendar-alt text-blue-500"></i>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-800">Event Reminder</p>
                                        <p class="text-xs text-gray-500">Book Fair scheduled</p>
                                        <p class="text-xs text-gray-400">Sep 23, 2025, 9:00 AM</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="p-3 border-t border-gray-200 text-center">
                            <a href="notifications.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">View All Notifications</a>
                        </div>
                    </div>
                </div>
                
                <div class="relative" id="user-menu-container">
                    <button type="button" 
                            class="flex items-center text-sm rounded-full focus:outline-none" 
                            id="user-menu-button" 
                            aria-expanded="false" 
                            aria-haspopup="true"
                            onclick="toggleUserMenu()">
                        <span class="sr-only">Open user menu</span>
                        <div class="w-10 h-10 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold shadow-md">
                            <?php echo htmlspecialchars($userData ? strtoupper(substr($userData['first_name'], 0, 1) . substr($userData['last_name'], 0, 1)) : 'LB'); ?>
                        </div>
                    </button>

                    <!-- Dropdown menu -->
                    <div id="user-menu" class="hidden absolute right-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 focus:outline-none z-50" 
                         role="menu" 
                         aria-orientation="vertical" 
                         aria-labelledby="user-menu-button" 
                         tabindex="-1">
                        <div class="py-4 px-4 border-b border-gray-100">
                            <p class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars(($userData['first_name'] ?? '') . ' ' . ($userData['last_name'] ?? '')); ?>
                            </p>
                            <p class="text-xs text-gray-500 truncate">
                                <?php echo htmlspecialchars($userData['email'] ?? 'librarian@example.com'); ?>
                            </p>
                            <span class="inline-flex items-center px-2.5 py-0.5 mt-1 text-xs font-medium bg-blue-100 text-blue-800 rounded-full">
                                Librarian
                            </span>
                        </div>
                        <div class="py-1" role="none">
                            <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem" tabindex="-1">
                                <i class="fas fa-user-circle w-5 mr-2 text-gray-400"></i>
                                Your Profile
                            </a>
                            <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem" tabindex="-1">
                                <i class="fas fa-cog w-5 mr-2 text-gray-400"></i>
                                Settings
                            </a>
                            <div class="border-t border-gray-100 my-1"></div>
                            <button onclick="toggleSignoutModal()" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50" role="menuitem" tabindex="-1">
                                <i class="fas fa-sign-out-alt w-5 mr-2"></i>
                                Sign out
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-5 overflow-y-auto bg-gray-50">
            <!-- Welcome Banner -->
            <div class="bg-white rounded-xl p-6 mb-6 border border-gray-200 shadow-sm">
                <div class="flex flex-col md:flex-row md:items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800 mb-2">Welcome back, <?php echo htmlspecialchars($userData ? trim($userData['first_name'] . ' ' . $userData['last_name']) : 'Librarian'); ?>!</h1>
                        <p class="text-gray-600">Here's what's happening in the library today.</p>
                    </div>
                    <div class="mt-4 md:mt-0">
                        <span class="bg-primary-100 text-primary-700 px-3 py-1 rounded-full text-sm font-medium">Today: September 30, 2025</span>
                    </div>
                </div>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-5 mb-6">
                <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-600">Total Books</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($bookStats['total_books'] ?? 0); ?>
                            </h3>
                        </div>
                        <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center">
                            <i class="fas fa-book text-blue-500"></i>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center">
                        <span class="text-gray-500 text-sm">
                            <?php echo number_format($bookStats['available_books'] ?? 0) . ' available'; ?>
                        </span>
                    </div>
                </div>
                
                <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-600">Books Checked Out</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($loanStats['active_loans'] ?? 0); ?>
                            </h3>
                        </div>
                        <div class="w-12 h-12 rounded-lg bg-amber-100 flex items-center justify-center">
                            <i class="fas fa-exchange-alt text-amber-500"></i>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center">
                        <span class="text-gray-500 text-sm">
                            <?php echo number_format($overdueStats['overdue_books'] ?? 0) . ' overdue'; ?>
                        </span>
                    </div>
                </div>
                
                <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-600">Active Patrons</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php echo number_format($patronStats['total_patrons'] ?? 0); ?>
                            </h3>
                        </div>
                        <div class="w-12 h-12 rounded-lg bg-green-100 flex items-center justify-center">
                            <i class="fas fa-users text-green-500"></i>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center">
                        <span class="text-gray-500 text-sm">
                            <?php 
                            $query = "SELECT COUNT(DISTINCT patron_id) as active_borrowers FROM book_loans WHERE status = 'checked_out'";
                            $stmt = $librarianConn->query($query);
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            echo number_format($result['active_borrowers'] ?? 0) . ' active borrowers';
                            ?>
                        </span>
                    </div>
                </div>
                
                <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-600">Categories</p>
                            <h3 class="text-2xl font-bold text-gray-800">
                                <?php 
                                $query = "SELECT COUNT(*) as total_categories FROM categories";
                                $stmt = $librarianConn->query($query);
                                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                echo number_format($result['total_categories'] ?? 0);
                                ?>
                            </h3>
                        </div>
                        <div class="w-12 h-12 rounded-lg bg-purple-100 flex items-center justify-center">
                            <i class="fas fa-tags text-purple-500"></i>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center">
                        <span class="text-gray-500 text-sm">
                            <?php 
                            $query = "SELECT COUNT(DISTINCT category_id) as used_categories FROM books WHERE category_id IS NOT NULL";
                            $stmt = $librarianConn->query($query);
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            echo number_format($result['used_categories'] ?? 0) . ' in use';
                            ?>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Main Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                <!-- Circulation Section -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm mb-5">
                        <div class="flex items-center justify-between mb-5">
                            <h2 class="text-lg font-bold text-gray-800">Recent Checkouts</h2>
                            <a href="checkouts.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">View All</a>
                        </div>
                        
                        <div class="overflow-x-auto custom-scrollbar">
                            <table class="w-full">
                                <thead>
                                    <tr class="text-left text-gray-600 border-b border-gray-200">
                                        <th class="pb-3 font-medium">Patron</th>
                                        <th class="pb-3 font-medium">Book Title</th>
                                        <th class="pb-3 font-medium">Due Date</th>
                                        <th class="pb-3 font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recentCheckouts)): ?>
                                    <tr>
                                        <td colspan="4" class="py-4 text-center text-gray-500">No recent checkouts found</td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($recentCheckouts as $checkout): 
                                            $dueDate = new DateTime($checkout['due_date']);
                                            $now = new DateTime();
                                            $interval = $now->diff($dueDate);
                                            $daysDiff = $interval->format('%r%a');
                                            
                                            if ($daysDiff < 0) {
                                                $statusClass = 'bg-red-100 text-red-800';
                                                $statusText = 'Overdue';
                                            } elseif ($daysDiff <= 2) {
                                                $statusClass = 'bg-amber-100 text-amber-800';
                                                $statusText = 'Due Soon';
                                            } else {
                                                $statusClass = 'bg-green-100 text-green-800';
                                                $statusText = 'On Time';
                                            }
                                        ?>
                                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                                            <td class="py-3">
                                                <p class="font-medium text-gray-800"><?php echo htmlspecialchars($checkout['patron_name']); ?></p>
                                                <?php if (!empty($checkout['contact_number'])): ?>
                                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($checkout['contact_number']); ?></p>
                                                <?php endif; ?>
                                            </td>
                                            <td class="py-3"><?php echo htmlspecialchars($checkout['book_title']); ?></td>
                                            <td class="py-3"><?php echo date('M d, Y', strtotime($checkout['due_date'])); ?></td>
                                            <td class="py-3">
                                                <span class="text-xs px-2 py-1 rounded-full <?php echo $statusClass; ?>">
                                                    <?php echo $statusText; ?>
                                                    <?php if ($daysDiff < 0): ?>
                                                        (<?php echo abs($daysDiff); ?>d)
                                                    <?php elseif ($daysDiff <= 2): ?>
                                                        (<?php echo $daysDiff; ?>d)
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Book Categories -->
                    <div class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between mb-5">
                            <h2 class="text-lg font-bold text-gray-800">Book Categories</h2>
                            <a href="books.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">View All</a>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php if (empty($categories)): ?>
                                <div class="col-span-3 text-center py-4 text-gray-500">No categories found</div>
                            <?php else: ?>
                                <?php 
                                $categoryIcons = [
                                    'mathematics' => ['fa-calculator', 'blue'],
                                    'science' => ['fa-flask', 'green'],
                                    'literature' => ['fa-book', 'yellow'],
                                    'history' => ['fa-landmark', 'purple'],
                                    'social' => ['fa-globe-americas', 'indigo'],
                                    'arts' => ['fa-paint-brush', 'red'],
                                    'physical' => ['fa-dumbbell', 'indigo'],
                                    'language' => ['fa-language', 'pink'],
                                    'reference' => ['fa-book-open', 'gray'],
                                    'education' => ['fa-graduation-cap', 'blue'],
                                    'technology' => ['fa-laptop-code', 'purple']
                                ];
                                
                                foreach ($categories as $category): 
                                    $icon = 'fa-book';
                                    $color = 'gray';
                                    $categoryLower = strtolower($category['category_name']);
                                    
                                    foreach ($categoryIcons as $key => $value) {
                                        if (strpos($categoryLower, $key) !== false) {
                                            $icon = $value[0];
                                            $color = $value[1];
                                            break;
                                        }
                                    }
                                    
                                    $bgColor = 'bg-' . $color . '-100';
                                    $textColor = 'text-' . $color . '-600';
                                ?>
                                <div class="bg-gray-50 rounded-lg p-4 border border-gray-100 hover:shadow-md transition-shadow">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-12 w-12 rounded-full <?php echo $bgColor; ?> flex items-center justify-center">
                                            <i class="fas <?php echo $icon; ?> <?php echo $textColor; ?>"></i>
                                        </div>
                                        <div class="ml-4 overflow-hidden">
                                            <h4 class="text-sm font-medium text-gray-900 truncate"><?php echo htmlspecialchars($category['category_name']); ?></h4>
                                            <p class="text-sm text-gray-500">
                                                <?php 
                                                echo number_format($category['book_count']) . ' ';
                                                echo ($category['book_count'] == 1) ? 'book' : 'books';
                                                ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column -->
                <div class="space-y-5">
                    <!-- Quick Actions -->
                    <div class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm">
                        <h2 class="text-lg font-bold text-gray-800 mb-5">Quick Actions</h2>
                        
                        <div class="grid grid-cols-2 gap-3">
                            <a href="checkouts.php" class="bg-gray-50 hover:bg-gray-100 rounded-lg p-4 text-center transition-colors border border-gray-200">
                                <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center mx-auto">
                                    <i class="fas fa-exchange-alt text-blue-500"></i>
                                </div>
                                <p class="text-gray-700 text-sm mt-2 font-medium">Check Out</p>
                            </a>
                            
                            <a href="return.php" class="bg-gray-50 hover:bg-gray-100 rounded-lg p-4 text-center transition-colors border border-gray-200">
                                <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center mx-auto">
                                    <i class="fas fa-undo text-green-500"></i>
                                </div>
                                <p class="text-gray-700 text-sm mt-2 font-medium">Returns</p>
                            </a>
                            
                            <a href="add_book.php" class="bg-gray-50 hover:bg-gray-100 rounded-lg p-4 text-center transition-colors border border-gray-200">
                                <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center mx-auto">
                                    <i class="fas fa-plus text-amber-500"></i>
                                </div>
                                <p class="text-gray-700 text-sm mt-2 font-medium">Add Books</p>
                            </a>
                            
                            <a href="reports.php" class="bg-gray-50 hover:bg-gray-100 rounded-lg p-4 text-center transition-colors border border-gray-200">
                                <div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center mx-auto">
                                    <i class="fas fa-chart-bar text-purple-500"></i>
                                </div>
                                <p class="text-gray-700 text-sm mt-2 font-medium">Reports</p>
                            </a>
                        </div>
                    </div>
                    
                    <!-- Overdue Books -->
                    <div class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm">
                        <h2 class="text-lg font-bold text-gray-800 mb-5">Overdue Books</h2>
                        
                        <div class="space-y-4">
                            <?php if (empty($overdueBooks)): ?>
                                <div class="text-center py-4 text-gray-500">No overdue books at the moment</div>
                            <?php else: ?>
                                <?php foreach ($overdueBooks as $book): 
                                    $daysOverdue = $book['days_overdue'];
                                    $statusText = $daysOverdue > 0 ? 
                                        $daysOverdue . ' day' . ($daysOverdue > 1 ? 's' : '') . ' overdue' : 
                                        'Due today';
                                    $statusClass = $daysOverdue > 0 ? 'text-red-600' : 'text-amber-600';
                                ?>
                                <div class="flex items-start">
                                    <div class="bg-red-100 p-2 rounded-full mr-3 flex-shrink-0">
                                        <i class="fas fa-book text-red-600"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-800 truncate" title="<?php echo htmlspecialchars($book['book_title']); ?>">
                                            <?php echo htmlspecialchars($book['book_title']); ?>
                                        </p>
                                        <div class="flex items-center">
                                            <p class="text-xs text-gray-500 truncate">
                                                <?php echo htmlspecialchars($book['patron_name']); ?>
                                            </p>
                                            <?php if (!empty($book['patron_status'])): ?>
                                                <span class="ml-2 text-xs px-1.5 py-0.5 rounded-full <?php 
                                                    echo $book['patron_status'] === 'active' ? 'bg-green-100 text-green-800' : 
                                                         ($book['patron_status'] === 'suspended' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'); ?>">
                                                    <?php echo ucfirst(htmlspecialchars($book['patron_status'])); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-xs font-medium <?php echo $statusClass; ?>">
                                            <?php echo $statusText; ?>
                                        </p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <a href="checkouts.php?status=overdue" class="mt-4 w-full text-primary-600 hover:text-primary-700 text-sm font-medium text-center">
                            View All Overdue Books <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                    
                    <!-- Popular Books -->
                    <div class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm">
                        <h2 class="text-lg font-bold text-gray-800 mb-5">Most Popular Books</h2>
                        
                        <?php
                        $query = "SELECT b.id, b.title, b.isbn, 
                                 COUNT(bl.loan_id) as loan_count,
                                 GROUP_CONCAT(DISTINCT CONCAT(a.first_name, ' ', a.last_name) SEPARATOR ', ') as authors
                                 FROM books b
                                 INNER JOIN book_loans bl ON b.id = bl.book_id
                                 LEFT JOIN book_authors ba ON b.id = ba.book_id
                                 LEFT JOIN authors a ON ba.author_id = a.author_id
                                 WHERE bl.loan_id IS NOT NULL
                                 AND (bl.librarian_id IS NULL OR bl.librarian_id IN (SELECT id FROM librarians))
                                 GROUP BY b.id, b.title, b.isbn
                                 ORDER BY loan_count DESC, b.title ASC
                                 LIMIT 5";
                        $popularBooks = $librarianConn->query($query)->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                        
                        <div class="space-y-3">
                            <?php if (empty($popularBooks)): ?>
                                <div class="text-center py-2 text-gray-500">No book loan data available</div>
                            <?php else: ?>
                                <?php foreach ($popularBooks as $book): 
                                    if (empty($book['title'])) continue;
                                    $loanCount = (int)($book['loan_count'] ?? 0);
                                    $authors = !empty($book['authors']) ? $book['authors'] : 'Unknown Author';
                                ?>
                                <div class="group">
                                    <div class="flex items-center justify-between">
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-gray-800 truncate" title="<?php echo htmlspecialchars($book['title']); ?>">
                                                <?php echo htmlspecialchars($book['title']); ?>
                                            </p>
                                            <?php if (!empty($authors)): ?>
                                                <p class="text-xs text-gray-500 truncate" title="<?php echo htmlspecialchars($authors); ?>">
                                                    <?php echo htmlspecialchars($authors); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <?php if ($loanCount > 0): ?>
                                            <span class="bg-primary-100 text-primary-800 text-xs px-2 py-1 rounded-full whitespace-nowrap ml-2">
                                                <?php echo number_format($loanCount) . ' ' . ($loanCount === 1 ? 'loan' : 'loans'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <a href="books.php?sort=popular" class="mt-4 w-full text-primary-600 hover:text-primary-700 text-sm font-medium text-center">
                            View Full Report <i class="fas fa-arrow-right ml-1"></i>
                        </a>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="toastContainer" class="fixed top-4 right-4 z-[10000] space-y-2"></div>

    <script>
        // Simulated data for notifications
        const notifications = [
            { message: "Overdue Book", description: "Book overdue by John Doe", created_at: "2025-09-25 10:30:00", icon_class: "fas fa-exclamation-circle text-blue-500" },
            { message: "New Book Added", description: '"The Hobbit" added to catalog', created_at: "2025-09-24 14:15:00", icon_class: "fas fa-book text-blue-500" },
            { message: "Book Checked Out", description: '"Charlotte\'s Web" checked out', created_at: "2025-09-24 11:00:00", icon_class: "fas fa-exchange-alt text-blue-500" },
            { message: "Book Returned", description: '"Matilda" returned on time', created_at: "2025-09-23 15:45:00", icon_class: "fas fa-undo text-blue-500" },
            { message: "Event Reminder", description: "Book Fair scheduled", created_at: "2025-09-23 09:00:00", icon_class: "fas fa-calendar-alt text-blue-500" }
        ];

        // Toast notifications
        function showToast(message, type) {
            try {
                const toastContainer = document.getElementById('toastContainer');
                if (!toastContainer) {
                    console.error('Toast container not found');
                    return;
                }
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
            } catch (error) {
                console.error('Error in showToast:', error);
            }
        }

        function toggleSidebar() {
            try {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('overlay');
                if (!sidebar || !overlay) {
                    console.error('Sidebar or overlay not found');
                    showToast('Error toggling sidebar', 'error');
                    return;
                }
                sidebar.classList.toggle('sidebar-open');
                overlay.classList.toggle('overlay-open');

                // Close all submenus when sidebar is toggled on mobile
                if (sidebar.classList.contains('sidebar-open')) {
                    document.querySelectorAll('.submenu.open').forEach(submenu => {
                        submenu.classList.remove('open');
                        const parentButton = submenu.previousElementSibling;
                        const chevron = parentButton.querySelector('.fa-chevron-down');
                        if (chevron) {
                            chevron.classList.remove('rotate-90');
                        }
                    });
                }

                showToast(sidebar.classList.contains('sidebar-open') ? 'Sidebar opened' : 'Sidebar closed', 'info');
            } catch (error) {
                console.error('Error in toggleSidebar:', error);
                showToast('Error toggling sidebar', 'error');
            }
        }
        
        function closeSidebar() {
            try {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('overlay');
                if (!sidebar || !overlay) {
                    console.error('Sidebar or overlay not found');
                    showToast('Error closing sidebar', 'error');
                    return;
                }
                sidebar.classList.remove('sidebar-open');
                overlay.classList.remove('overlay-open');
                showToast('Sidebar closed', 'info');
            } catch (error) {
                console.error('Error in closeSidebar:', error);
                showToast('Error closing sidebar', 'error');
            }
        }
        
        // Toggle user menu
       function toggleUserMenu() {
            try {
                const userMenu = document.getElementById('user-menu');
                const notificationPanel = document.getElementById('notification-panel');
                
                if (!userMenu) {
                    console.error('User menu element not found');
                    return;
                }
                
                // Toggle the menu
                userMenu.classList.toggle('hidden');
                
                // Close notifications if open
                if (notificationPanel && notificationPanel.classList.contains('open')) {
                    notificationPanel.classList.remove('open');
                }
                
                // Close menu when clicking outside
                const handleClickOutside = (event) => {
                    const userMenuContainer = document.getElementById('user-menu-container');
                    const userMenuButton = document.getElementById('user-menu-button');
                    
                    if (userMenuContainer && 
                        !userMenuContainer.contains(event.target) && 
                        !userMenuButton.contains(event.target)) {
                        userMenu.classList.add('hidden');
                        document.removeEventListener('click', handleClickOutside);
                    }
                };
                
                if (!userMenu.classList.contains('hidden')) {
                    // Add click outside listener when menu is opened
                    setTimeout(() => {
                        document.addEventListener('click', handleClickOutside);
                    }, 10);
                } else {
                    // Remove listener when menu is closed
                    document.removeEventListener('click', handleClickOutside);
                }
                    console.error('User menu not found');
                    showToast('Error toggling user menu', 'error');
                    return;
                }
                userMenu.classList.toggle('user-menu-open');
                showToast(userMenu.classList.contains('user-menu-open') ? 'User menu opened' : 'User menu closed', 'info');
            } catch (error) {
                console.error('Error in toggleUserMenu:', error);
                showToast('Error toggling user menu', 'error');
            }
        }
        
        function toggleNotifications() {
            try {
                const notificationPanel = document.getElementById('notification-panel');
                if (!notificationPanel) {
                    console.error('Notification panel not found');
                    showToast('Error toggling notifications', 'error');
                    return;
                }
                notificationPanel.classList.toggle('open');
                if (notificationPanel.classList.contains('open')) {
                    const notificationDot = document.querySelector('.notification-dot');
                    if (notificationDot) {
                        notificationDot.textContent = '0';
                    }
                    showToast('Notifications viewed', 'info');
                }
            } catch (error) {
                console.error('Error in toggleNotifications:', error);
                showToast('Error toggling notifications', 'error');
            }
        }
        
        function toggleSubmenu(submenuId, element) {
            try {
                // Prevent default button behavior
                event.preventDefault();

                // Find the submenu and chevron
                const submenu = document.getElementById(submenuId);
                const chevron = element.querySelector('.fa-chevron-down');
                const sidebar = document.getElementById('sidebar');

                // Check if submenu exists
                if (!submenu) {
                    console.error(`Submenu with ID ${submenuId} not found`);
                    showToast(`Error: Submenu ${submenuId} not found`, 'error');
                    return;
                }

                // Check if sidebar is collapsed
                if (sidebar && sidebar.classList.contains('collapsed')) {
                    toggleSidebarCollapse();
                }

                // Close other submenus
                document.querySelectorAll('.submenu').forEach(menu => {
                    if (menu.id !== submenuId && menu.classList.contains('open')) {
                        menu.classList.remove('open');
                        const parentButton = menu.previousElementSibling;
                        const parentChevron = parentButton.querySelector('.fa-chevron-down');
                        if (parentChevron) {
                            parentChevron.classList.remove('rotate-90');
                        }
                    }
                });

                // Toggle submenu visibility
                submenu.classList.toggle('open');
                if (chevron) {
                    chevron.classList.toggle('rotate-90');
                }

                // Show toast notification
                const submenuName = element.querySelector('.sidebar-text').textContent.trim();
                showToast(`${submenuName} ${submenu.classList.contains('open') ? 'expanded' : 'collapsed'}`, 'info');
            } catch (error) {
                console.error('Error in toggleSubmenu:', error);
                showToast('Error toggling submenu', 'error');
            }
        }
        
        function toggleSidebarCollapse() {
            try {
                const sidebar = document.getElementById('sidebar');
                const collapseIcon = document.getElementById('collapse-icon');
                if (!sidebar || !collapseIcon) {
                    console.error('Sidebar or collapse icon not found');
                    showToast('Error toggling sidebar collapse', 'error');
                    return;
                }
                
                sidebar.classList.toggle('collapsed');
                
                if (sidebar.classList.contains('collapsed')) {
                    collapseIcon.classList.remove('fa-chevron-left');
                    collapseIcon.classList.add('fa-chevron-right');
                    document.querySelector('.sidebar-text').textContent = 'Expand Sidebar';
                    showToast('Sidebar collapsed', 'info');
                } else {
                    collapseIcon.classList.remove('fa-chevron-right');
                    collapseIcon.classList.add('fa-chevron-left');
                    document.querySelector('.sidebar-text').textContent = 'Collapse Sidebar';
                    showToast('Sidebar expanded', 'info');
                }
            } catch (error) {
                console.error('Error in toggleSidebarCollapse:', error);
                showToast('Error toggling sidebar collapse', 'error');
            }
        }
        
        document.addEventListener('click', function(event) {
            try {
                const userMenu = document.getElementById('user-menu');
                const userButton = document.querySelector('.relative button:last-child');
                const notificationPanel = document.getElementById('notification-panel');
                const notificationButton = document.getElementById('notification-btn');
                
                if (userMenu && userButton && !userMenu.contains(event.target) && !userButton.contains(event.target)) {
                    userMenu.classList.remove('user-menu-open');
                }
                
                if (notificationPanel && notificationButton && !notificationPanel.contains(event.target) && !notificationButton.contains(event.target)) {
                    notificationPanel.classList.remove('open');
                }
            } catch (error) {
                console.error('Error in click event listener:', error);
                showToast('Error handling click event', 'error');
            }
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            try {
                // Initialize dropdown buttons
                const dropdownButtons = document.querySelectorAll('.nav-item[onclick*="toggleSubmenu"]');
                dropdownButtons.forEach(button => {
                    try {
                        const submenuId = button.getAttribute('onclick').match(/'([^']+)'/)[1];
                        const submenu = document.getElementById(submenuId);
                        if (submenu && submenu.classList.contains('open')) {
                            const chevron = button.querySelector('.fa-chevron-down');
                            if (chevron) {
                                chevron.classList.add('rotate-90');
                            }
                        }
                    } catch (error) {
                        console.error('Error initializing dropdown button:', error);
                    }
                });

                showToast('Welcome to the Librarian Portal!', 'success');
                console.log('Librarian Portal loaded');
            } catch (error) {
                console.error('Error in DOMContentLoaded:', error);
                showToast('Error loading page', 'error');
            }
        });
    </script>
    
    <!-- Sign Out Confirmation Modal -->
    <div id="signoutModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl p-6 w-96 shadow-lg">
            <div class="text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                    <i class="fas fa-sign-out-alt text-red-600 text-xl"></i>
                </div>
                <h3 class="text-lg font-medium text-gray-900 mt-3">Sign Out</h3>
                <div class="mt-2">
                    <p class="text-sm text-gray-500">
                        Are you sure you want to sign out?
                    </p>
                </div>
                <div class="mt-5 sm:mt-6 grid grid-cols-2 gap-3">
                    <button type="button" onclick="toggleSignoutModal()" class="w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:text-sm">
                        Cancel
                    </button>
                    <a href="../logout.php" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:text-sm text-center">
                        Sign Out
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    // Toggle sign out modal
    function toggleSignoutModal() {
        const modal = document.getElementById('signoutModal');
        if (modal) {
            modal.classList.toggle('hidden');
            
            // Toggle body scroll
            document.body.style.overflow = modal.classList.contains('hidden') ? 'auto' : 'hidden';
            
            // Close user menu if open
            const userMenu = document.getElementById('user-menu');
            if (userMenu && !userMenu.classList.contains('hidden')) {
                userMenu.classList.add('hidden');
            }
        }
    }
    
    // Close modal when clicking outside
    document.addEventListener('click', function(event) {
        const modal = document.getElementById('signoutModal');
        const modalContent = modal ? modal.querySelector('> div') : null;
        
        if (modal && !modal.classList.contains('hidden') && 
            !modalContent.contains(event.target) && 
            event.target !== document.querySelector('[onclick*="toggleSignoutModal"]')) {
            toggleSignoutModal();
        }
    });
    
    // Close modal with Escape key
    document.addEventListener('keydown', function(event) {
        const modal = document.getElementById('signoutModal');
        if (event.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
            toggleSignoutModal();
        }
    });
    </script>
</body>
</html>