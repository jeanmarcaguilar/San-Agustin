<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'librarian') {
    header('Location: login.php');
    exit();
}

try {
    // Include necessary files
    require_once __DIR__ . '/../config/database.php';
    
    // Create database instance
    $database = new Database();
    $librarianConn = $database->getConnection('librarian');
    
    // Get user info from login_db
    $loginConn = $database->getConnection('');
    $user_id = $_SESSION['user_id'];
    
    $query = "SELECT id, username, email, role FROM users WHERE id = :user_id AND role = 'librarian' LIMIT 1";
    $stmt = $loginConn->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("User not found or unauthorized access");
    }
    
    // Get librarian details from librarian_db
    $query = "SELECT * FROM librarians WHERE user_id = :user_id LIMIT 1";
    $stmt = $librarianConn->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $librarianData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$librarianData) {
        // Create a default librarian profile if not exists
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
    $pageTitle = 'Circulation Reports - ' . htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']);
    
    // Set default time period (30 days)
    $time_period = isset($_GET['period']) ? (int)$_GET['period'] : 30;
    $start_date = date('Y-m-d', strtotime("-$time_period days"));
    $end_date = date('Y-m-d');
    
    // Get circulation statistics
    $stats = [];
    
    // Total checkouts
    $query = "SELECT COUNT(*) as total FROM book_loans 
              WHERE checkout_date BETWEEN :start_date AND :end_date";
    $stmt = $librarianConn->prepare($query);
    $stmt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $stats['total_checkouts'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total returns
    $query = "SELECT COUNT(*) as total FROM book_loans 
              WHERE return_date BETWEEN :start_date AND :end_date";
    $stmt = $librarianConn->prepare($query);
    $stmt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $stats['total_returns'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total overdue items
    $query = "SELECT COUNT(*) as total FROM book_loans 
              WHERE due_date < CURDATE() 
              AND status = 'checked_out'";
    $stmt = $librarianConn->query($query);
    $stats['total_overdue'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Total active patrons
    $query = "SELECT COUNT(DISTINCT patron_id) as total FROM book_loans 
              WHERE checkout_date BETWEEN :start_date AND :end_date";
    $stmt = $librarianConn->prepare($query);
    $stmt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $stats['active_patrons'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get most borrowed books
    $query = "SELECT b.id, b.title, b.author, b.isbn, COUNT(bl.loan_id) as borrow_count
              FROM book_loans bl
              JOIN books b ON bl.book_id = b.id
              WHERE bl.checkout_date BETWEEN :start_date AND :end_date
              GROUP BY b.id, b.title, b.author, b.isbn
              ORDER BY borrow_count DESC
              LIMIT 10";
    $stmt = $librarianConn->prepare($query);
    $stmt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $popular_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get most active patrons
    $query = "SELECT p.patron_id, p.first_name, p.last_name, 
                     COUNT(t.id) as borrow_count
              FROM transactions t
              JOIN patrons p ON t.patron_id = p.patron_id
              WHERE t.status = 'checked_out'
              AND t.checkout_date BETWEEN :start_date AND :end_date
              GROUP BY p.patron_id, p.first_name, p.last_name
              ORDER BY borrow_count DESC
              LIMIT 10";
    $stmt = $librarianConn->prepare($query);
    $stmt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $active_patrons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get daily circulation data for chart
    $query = "SELECT DATE(checkout_date) as date, 
                     COUNT(*) as checkouts,
                     SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returns
              FROM transactions
              WHERE checkout_date BETWEEN :start_date AND :end_date
              GROUP BY DATE(checkout_date)
              ORDER BY date";
    $stmt = $librarianConn->prepare($query);
    $stmt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
    $daily_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare data for the chart
    $chart_labels = [];
    $checkout_data = [];
    $return_data = [];
    
    foreach ($daily_data as $row) {
        $chart_labels[] = date('M j', strtotime($row['date']));
        $checkout_data[] = (int)$row['checkouts'];
        $return_data[] = (int)$row['returns'];
    }
    
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage() . "<br>File: " . $e->getFile() . "<br>Line: " . $e->getLine());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📚 <?php echo htmlspecialchars($pageTitle); ?> - Librarian Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.28/jspdf.plugin.autotable.min.js"></script>
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
            transition: max-height 0.3s ease;
        }
        .submenu.open {
            max-height: 500px;
        }
        .rotate-90 {
            transform: rotate(90deg);
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
                    <a href="dashboard.php" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-home w-5"></i>
                        <span class="ml-3 sidebar-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('catalog-submenu', this)">
                        <i class="fas fa-book w-5"></i>
                        <span class="ml-3 sidebar-text">Catalog Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('patrons-submenu', this)">
                        <i class="fas fa-users w-5"></i>
                        <span class="ml-3 sidebar-text">Patron Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
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
                    <a href="#" class="flex items-center p-3 rounded-lg bg-primary-600 text-white shadow-md nav-item" onclick="toggleSubmenu('reports-submenu', this)">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Analytics</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="reports-submenu" class="submenu pl-4 mt-1">
                        <a href="circulation_report.php" class="flex items-center p-2 rounded-lg text-white bg-primary-700 transition-colors">
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
                <li>
                    <a href="library_events.php" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-calendar-alt w-5"></i>
                        <span class="ml-3 sidebar-text">Library Events</span>
                    </a>
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
                <h1 class="text-xl font-bold">Circulation Reports</h1>
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
                
                <div class="relative">
                    <button onclick="toggleUserMenu()" class="w-10 h-10 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold shadow-md">
                        <?php echo htmlspecialchars($userData ? strtoupper(substr($userData['first_name'], 0, 1) . substr($userData['last_name'], 0, 1)) : 'LB'); ?>
                    </button>
                    
                    <div id="user-menu" class="user-menu">
                        <div class="px-4 py-2 border-b border-gray-100">
                            <p class="text-gray-800 font-medium"><?php echo htmlspecialchars($userData ? trim($userData['first_name'] . ' ' . $userData['last_name']) : 'Librarian'); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($userData ? ($userData['email'] ?? 'librarian@sanaugustin.edu') : 'librarian@sanaugustin.edu'); ?></p>
                        </div>
                        <a href="profile.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 transition-colors">Profile</a>
                        <a href="settings.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 transition-colors">Settings</a>
                        <a href="/San%20Agustin/logout.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 transition-colors">Sign Out</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-5 overflow-y-auto bg-gray-50">
            <div class="max-w-7xl mx-auto">
                <!-- Page Header -->
                <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Circulation Reports</h1>
                        <p class="text-sm text-gray-600 mt-1">Analyze and track library circulation activities</p>
                    </div>
                    <div class="mt-4 md:mt-0 flex space-x-3">
                        <div class="relative
                        ">
                            <select id="timePeriod" onchange="updateReport()" class="bg-white border border-gray-300 text-gray-700 py-2 px-4 pr-8 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-primary-500">
                                <option value="7" <?php echo $time_period == 7 ? 'selected' : ''; ?>>Last 7 Days</option>
                                <option value="30" <?php echo $time_period == 30 ? 'selected' : ''; ?>>Last 30 Days</option>
                                <option value="90" <?php echo $time_period == 90 ? 'selected' : ''; ?>>Last 90 Days</option>
                                <option value="180" <?php echo $time_period == 180 ? 'selected' : ''; ?>>Last 6 Months</option>
                                <option value="365" <?php echo $time_period == 365 ? 'selected' : ''; ?>>Last Year</option>
                            </select>
                        </div>
                        <button onclick="exportToPDF()" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors flex items-center">
                            <i class="fas fa-file-pdf mr-2"></i> Export PDF
                        </button>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                    <!-- Total Checkouts -->
                    <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-blue-100 text-blue-600 mr-4">
                                <i class="fas fa-book-reader text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Total Checkouts</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['total_checkouts']); ?></h3>
                                <p class="text-xs text-gray-500 mt-1">Last <?php echo $time_period; ?> days</p>
                            </div>
                        </div>
                    </div>

                    <!-- Total Returns -->
                    <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-green-100 text-green-600 mr-4">
                                <i class="fas fa-undo text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Total Returns</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['total_returns']); ?></h3>
                                <p class="text-xs text-gray-500 mt-1">Last <?php echo $time_period; ?> days</p>
                            </div>
                        </div>
                    </div>

                    <!-- Overdue Items -->
                    <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-yellow-100 text-yellow-600 mr-4">
                                <i class="fas fa-exclamation-triangle text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Overdue Items</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['total_overdue']); ?></h3>
                                <p class="text-xs text-gray-500 mt-1">Currently overdue</p>
                            </div>
                        </div>
                    </div>

                    <!-- Active Patrons -->
                    <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-lg bg-purple-100 text-purple-600 mr-4">
                                <i class="fas fa-users text-2xl"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Active Patrons</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['active_patrons']); ?></h3>
                                <p class="text-xs text-gray-500 mt-1">Last <?php echo $time_period; ?> days</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Circulation Trend -->
                    <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm dashboard-card">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Circulation Trend</h3>
                            <div class="flex space-x-2">
                                <button class="px-3 py-1 text-xs rounded-full bg-blue-100 text-blue-800">Checkouts</button>
                                <button class="px-3 py-1 text-xs rounded-full bg-green-100 text-green-800">Returns</button>
                            </div>
                        </div>
                        <div class="h-64">
                            <canvas id="circulationChart"></canvas>
                        </div>
                    </div>

                    <!-- Circulation by Day of Week -->
                    <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm dashboard-card">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Circulation by Day of Week</h3>
                        <div class="h-64">
                            <canvas id="dayOfWeekChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Popular Books -->
                <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm dashboard-card mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-medium text-gray-900">Most Borrowed Books</h3>
                        <a href="popular_books.php" class="text-sm text-primary-600 hover:text-primary-700">View All</a>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Author</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Call Number</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Borrow Count</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($popular_books)): ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No borrowing data available for the selected period.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($popular_books as $book): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($book['title']); ?></div>
                                                <div class="text-xs text-gray-500"><?php echo htmlspecialchars($book['isbn']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($book['author']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo isset($book['call_number']) ? htmlspecialchars($book['call_number']) : 'N/A'; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                    <?php echo $book['borrow_count']; ?> checkouts
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Active Patrons -->
                <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm dashboard-card">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-medium text-gray-900">Most Active Patrons</h3>
                        <a href="patrons.php" class="text-sm text-primary-600 hover:text-primary-700">View All</a>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Patron</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade/Section</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Borrow Count</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($active_patrons)): ?>
                                    <tr>
                                        <td colspan="3" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No patron data available for the selected period.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($active_patrons as $patron): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10 rounded-full bg-primary-100 flex items-center justify-center text-primary-800 font-medium">
                                                        <?php echo strtoupper(substr($patron['first_name'], 0, 1) . substr($patron['last_name'], 0, 1)); ?>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($patron['first_name'] . ' ' . $patron['last_name']); ?>
                                                        </div>
                                                        <div class="text-xs text-gray-500">
                                                            <?php echo htmlspecialchars($patron['card_number']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php 
                                                    $grade_section = [];
                                                    if (!empty($patron['grade_level'])) $grade_section[] = 'Grade ' . $patron['grade_level'];
                                                    if (!empty($patron['section'])) $grade_section[] = 'Section ' . $patron['section'];
                                                    echo !empty($grade_section) ? implode(' - ', $grade_section) : 'N/A';
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                    <?php echo $patron['borrow_count']; ?> checkouts
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast">
        <div class="toast-icon">
            <i class="fas fa-info-circle"></i>
        </div>
        <div class="toast-message">
            This is a notification message.
        </div>
        <button class="toast-close" onclick="this.parentElement.classList.remove('show')">
            <i class="fas fa-times"></i>
        </button>
    </div>

    <script>
        // Toggle sidebar on mobile
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            sidebar.classList.toggle('sidebar-open');
            overlay.classList.toggle('overlay-open');
        }

        // Close sidebar when clicking outside on mobile
        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            sidebar.classList.remove('sidebar-open');
            overlay.classList.remove('overlay-open');
        }

        // Toggle sidebar collapse
        function toggleSidebarCollapse() {
            const sidebar = document.getElementById('sidebar');
            const collapseIcon = document.getElementById('collapse-icon');
            sidebar.classList.toggle('collapsed');
            
            if (sidebar.classList.contains('collapsed')) {
                collapseIcon.classList.remove('fa-chevron-left');
                collapseIcon.classList.add('fa-chevron-right');
            } else {
                collapseIcon.classList.remove('fa-chevron-right');
                collapseIcon.classList.add('fa-chevron-left');
            }
        }

        // Toggle submenus
        function toggleSubmenu(menuId, button) {
            const submenu = document.getElementById(menuId);
            const icon = button.querySelector('.fa-chevron-down');
            
            // Close all other open submenus in the same parent
            const parentMenu = button.closest('li');
            const allSubmenus = parentMenu.parentElement.querySelectorAll('.submenu');
            const allIcons = parentMenu.parentElement.querySelectorAll('.fa-chevron-down');
            
            allSubmenus.forEach((item) => {
                if (item.id !== menuId) {
                    item.classList.remove('open');
                }
            });
            
            allIcons.forEach((item) => {
                if (item !== icon) {
                    item.classList.remove('rotate-180');
                }
            });
            
            // Toggle current submenu
            submenu.classList.toggle('open');
            icon.classList.toggle('rotate-180');
        }

        // Toggle notifications panel
        function toggleNotifications() {
            const panel = document.getElementById('notification-panel');
            panel.classList.toggle('open');
            
            // Close user menu if open
            document.getElementById('user-menu').classList.remove('user-menu-open');
        }

        // Toggle user menu
        function toggleUserMenu() {
            const menu = document.getElementById('user-menu');
            menu.classList.toggle('user-menu-open');
            
            // Close notifications panel if open
            document.getElementById('notification-panel').classList.remove('open');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            // Close notifications panel
            if (!event.target.closest('#notification-btn') && !event.target.closest('#notification-panel')) {
                document.getElementById('notification-panel').classList.remove('open');
            }
            
            // Close user menu
            if (!event.target.closest('.relative button') || !event.target.closest('#user-menu')) {
                document.getElementById('user-menu').classList.remove('user-menu-open');
            }
        });

        // Show toast notification
        function showToast(message, type = 'info') {
            const toast = document.getElementById('toast');
            const toastMessage = toast.querySelector('.toast-message');
            const toastIcon = toast.querySelector('.toast-icon i');
            
            // Set message and type
            toastMessage.textContent = message;
            
            // Remove all type classes
            toast.className = 'toast';
            
            // Add the appropriate type class
            toast.classList.add(type);
            
            // Set icon based on type
            switch(type) {
                case 'success':
                    toastIcon.className = 'fas fa-check-circle';
                    break;
                case 'error':
                    toastIcon.className = 'fas fa-exclamation-circle';
                    break;
                case 'warning':
                    toastIcon.className = 'fas fa-exclamation-triangle';
                    break;
                default:
                    toastIcon.className = 'fas fa-info-circle';
            }
            
            // Show the toast
            toast.classList.add('show');
            
            // Auto-hide after 5 seconds
            setTimeout(() => {
                toast.classList.remove('show');
            }, 5000);
        }

        // Update report when time period changes
        function updateReport() {
            const timePeriod = document.getElementById('timePeriod').value;
            window.location.href = `circulation_report.php?period=${timePeriod}`;
        }

        // Export to PDF
        function exportToPDF() {
            // Show loading toast
            showToast('Preparing PDF export...', 'info');
            
            // Initialize jsPDF
            const { jsPDF } = window.jspdf;
            const doc = new jsPDF('p', 'pt', 'a4');
            
            // Add title and date
            doc.setFontSize(18);
            doc.text('Circulation Report', 40, 40);
            
            doc.setFontSize(10);
            doc.setTextColor(100);
            doc.text(`Generated on: ${new Date().toLocaleDateString()}`, 40, 60);
            doc.text(`Time Period: Last ${document.getElementById('timePeriod').options[document.getElementById('timePeriod').selectedIndex].text}`, 40, 75);
            
            // Add stats
            doc.setFontSize(12);
            doc.setTextColor(0, 0, 0);
            doc.text('Summary Statistics', 40, 110);
            
            doc.setFontSize(10);
            doc.text(`Total Checkouts: ${<?php echo $stats['total_checkouts']; ?>}`, 50, 135);
            doc.text(`Total Returns: ${<?php echo $stats['total_returns']; ?>}`, 200, 135);
            doc.text(`Overdue Items: ${<?php echo $stats['total_overdue']; ?>}`, 50, 155);
            doc.text(`Active Patrons: ${<?php echo $stats['active_patrons']; ?>}`, 200, 155);
            
            // Add popular books table
            doc.setFontSize(12);
            doc.text('Most Borrowed Books', 40, 200);
            
            const popularBooks = <?php echo json_encode($popular_books); ?>;
            const booksData = popularBooks.map(book => [
                book.title,
                book.author,
                book.call_number || 'N/A',
                book.borrow_count
            ]);
            
            doc.autoTable({
                head: [['Title', 'Author', 'Call Number', 'Borrow Count']],
                body: booksData,
                startY: 210,
                margin: { left: 40 },
                headStyles: { fillColor: [45, 55, 72], textColor: 255 },
                theme: 'grid',
                styles: { fontSize: 8, cellPadding: 3 },
                columnStyles: {
                    0: { cellWidth: 120 },
                    1: { cellWidth: 80 },
                    2: { cellWidth: 60 },
                    3: { cellWidth: 30, halign: 'center' }
                }
            });
            
            // Add active patrons table
            const activePatrons = <?php echo json_encode($active_patrons); ?>;
            const patronsData = activePatrons.map(patron => [
                `${patron.first_name} ${patron.last_name}`,
                patron.card_number,
                patron.borrow_count
            ]);
            
            doc.autoTable({
                head: [['Patron Name', 'Card Number', 'Borrow Count']],
                body: patronsData,
                startY: doc.lastAutoTable.finalY + 20,
                margin: { left: 40 },
                headStyles: { fillColor: [45, 55, 72], textColor: 255 },
                theme: 'grid',
                styles: { fontSize: 8, cellPadding: 3 },
                columnStyles: {
                    0: { cellWidth: 150 },
                    1: { cellWidth: 80 },
                    2: { cellWidth: 40, halign: 'center' }
                }
            });
            
            // Add page number
            const pageCount = doc.internal.getNumberOfPages();
            for (let i = 1; i <= pageCount; i++) {
                doc.setPage(i);
                doc.setFontSize(8);
                doc.text(`Page ${i} of ${pageCount}`, doc.internal.pageSize.width - 60, doc.internal.pageSize.height - 20);
            }
            
            // Save the PDF
            doc.save(`circulation_report_${new Date().toISOString().split('T')[0]}.pdf`);
            
            // Show success message
            showToast('PDF exported successfully!', 'success');
        }

        // Initialize charts when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Circulation Trend Chart
            const ctx1 = document.getElementById('circulationChart').getContext('2d');
            const circulationChart = new Chart(ctx1, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [
                        {
                            label: 'Checkouts',
                            data: <?php echo json_encode($checkout_data); ?>,
                            borderColor: '#3b82f6',
                            backgroundColor: 'rgba(59, 130, 246, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true
                        },
                        {
                            label: 'Returns',
                            data: <?php echo json_encode($return_data); ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });

            // Day of Week Chart (sample data - you'll need to implement the actual data)
            const ctx2 = document.getElementById('dayOfWeekChart').getContext('2d');
            const dayOfWeekChart = new Chart(ctx2, {
                type: 'bar',
                data: {
                    labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
                    datasets: [
                        {
                            label: 'Checkouts',
                            data: [12, 19, 15, 17, 22, 5, 2],
                            backgroundColor: 'rgba(59, 130, 246, 0.7)',
                            borderColor: 'rgba(59, 130, 246, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Returns',
                            data: [8, 12, 10, 15, 18, 3, 1],
                            backgroundColor: 'rgba(16, 185, 129, 0.7)',
                            borderColor: 'rgba(16, 185, 129, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>
