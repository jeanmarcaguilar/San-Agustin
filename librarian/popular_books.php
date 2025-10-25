<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if user is logged in and is a librarian
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'librarian') {
    header('Location: /San%20Agustin/login.php');
    exit();
}

try {
    // Include necessary files
    require_once __DIR__ . '/../config/database.php';
    
    // Create database instance
    $database = new Database();
    
    // Get user info from login_db
    $loginConn = $database->getConnection(''); // Connect to login_db
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
    $librarianConn = $database->getConnection('librarian');
    
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
    $pageTitle = 'Popular Books - ' . htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']);
    
    // Set default time period (30 days)
    $time_period = isset($_GET['period']) ? (int)$_GET['period'] : 30;
    $valid_periods = [7, 30, 90, 180, 365];
    if (!in_array($time_period, $valid_periods)) {
        $time_period = 30;
    }
    
    // Get popular books based on checkouts
    $popular_books_query = "
        SELECT b.id as book_id, b.title, b.author, b.isbn, c.category_name, 
               COUNT(bl.loan_id) as checkout_count,
               b.quantity as total_copies,
               COALESCE(SUM(CASE WHEN bl.return_date IS NULL AND bl.due_date < NOW() THEN 1 ELSE 0 END), 0) as overdue_count,
               b.available as available_copies
        FROM books b
        LEFT JOIN book_loans bl ON b.id = bl.book_id
        LEFT JOIN categories c ON b.category_id = c.category_id
        WHERE bl.checkout_date >= DATE_SUB(NOW(), INTERVAL :period DAY)
        GROUP BY b.id, b.title, b.author, b.isbn, c.category_name, b.quantity, b.available
        ORDER BY checkout_count DESC, b.title
        LIMIT 50
    ";
    $stmt = $librarianConn->prepare($popular_books_query);
    $stmt->bindParam(':period', $time_period, PDO::PARAM_INT);
    $stmt->execute();
    $popular_books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get most active patrons
    $active_patrons_query = "
        SELECT p.patron_id, p.first_name, p.last_name, 
               COUNT(bl.loan_id) as books_borrowed
        FROM patrons p
        JOIN book_loans bl ON p.patron_id = bl.patron_id
        WHERE bl.checkout_date >= DATE_SUB(NOW(), INTERVAL :period DAY)
        GROUP BY p.patron_id, p.first_name, p.last_name
        ORDER BY books_borrowed DESC
        LIMIT 10
    ";
    $stmt = $librarianConn->prepare($active_patrons_query);
    $stmt->bindParam(':period', $time_period, PDO::PARAM_INT);
    $stmt->execute();
    $active_patrons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get popular categories
    $categories_query = "
        SELECT c.category_id, c.category_name, 
               COUNT(bl.loan_id) as checkout_count
        FROM categories c
        JOIN books b ON c.category_id = b.category_id
        LEFT JOIN book_loans bl ON b.id = bl.book_id
        WHERE bl.checkout_date >= DATE_SUB(NOW(), INTERVAL :period DAY)
        GROUP BY c.category_id, c.category_name
        ORDER BY checkout_count DESC
        LIMIT 10
    ";
    $stmt = $librarianConn->prepare($categories_query);
    $stmt->bindParam(':period', $time_period, PDO::PARAM_INT);
    $stmt->execute();
    $popular_categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate statistics
    $total_checkouts = array_sum(array_column($popular_books, 'checkout_count'));
    $unique_books = count($popular_books);
    $unique_patrons = count($active_patrons);
    $avg_books_per_patron = $unique_patrons > 0 ? round($total_checkouts / $unique_patrons, 1) : 0;
    
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
    <title>📚 Librarian Portal – Popular Books</title>
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
            transition: max-height 0.3s ease-out;
            background: #303d3b;
            border-radius: 8px;
            margin-top: 4px;
        }
        .submenu.open {
            max-height: 500px;
            transition: max-height 0.3s ease-in;
        }
        .nav-item .chevron-icon {
            transition: transform 0.3s ease;
        }
        .nav-item.open .chevron-icon {
            transform: rotate(180deg);
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('catalog-submenu')">
                        <i class="fas fa-book w-5"></i>
                        <span class="ml-3 sidebar-text">Catalog Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs chevron-icon"></i>
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('patrons-submenu')">
                        <i class="fas fa-users w-5"></i>
                        <span class="ml-3 sidebar-text">Patron Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs chevron-icon"></i>
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
                    <a href="#" class="flex items-center p-3 rounded-lg bg-primary-600 text-white shadow-md nav-item" onclick="toggleSubmenu('reports-submenu')">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Analytics</span>
                        <i class="fas fa-chevron-down ml-auto text-xs chevron-icon"></i>
                    </a>
                    <div id="reports-submenu" class="submenu pl-4 mt-1 open">
                        <a href="circulation_reports.php" class="flex items-center p-2 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-exchange-alt w-5"></i>
                            <span class="ml-3 sidebar-text">Circulation Reports</span>
                        </a>
                        <a href="popular_books.php" class="flex items-center p-2 rounded-lg bg-primary-600 text-white shadow-md">
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
                <h1 class="text-xl font-bold">Popular Books & Analytics</h1>
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
                <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm mb-6 dashboard-card">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                        <h1 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">Popular Books & Analytics</h1>
                        <div class="flex space-x-3">
                            <button onclick="window.print()" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors flex items-center">
                                <i class="fas fa-print mr-2"></i> Print
                            </button>
                            <button id="exportPdf" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors flex items-center">
                                <i class="fas fa-file-pdf mr-2"></i> Export PDF
                            </button>
                        </div>
                    </div>
                    
                    <!-- Time Period Filter -->
                    <div class="mb-6">
                        <form method="GET" class="flex flex-col md:flex-row gap-3">
                            <div class="flex-1">
                                <div class="relative">
                                    <select name="period" onchange="this.form.submit()" 
                                            class="w-full pl-10 pr-8 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                                        <option value="7" <?= $time_period == 7 ? 'selected' : '' ?>>Last 7 Days</option>
                                        <option value="30" <?= $time_period == 30 ? 'selected' : '' ?>>Last 30 Days</option>
                                        <option value="90" <?= $time_period == 90 ? 'selected' : '' ?>>Last 90 Days</option>
                                        <option value="180" <?= $time_period == 180 ? 'selected' : '' ?>>Last 6 Months</option>
                                        <option value="365" <?= $time_period == 365 ? 'selected' : '' ?>>Last Year</option>
                                    </select>
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-calendar text-gray-400"></i>
                                    </div>
                                </div>
                            </div>
                            <?php if ($time_period != 30): ?>
                                <a href="popular_books.php" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 transition-colors flex items-center">
                                    Clear
                                </a>
                            <?php endif; ?>
                        </form>
                    </div>
                    
                    <!-- Statistics Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                        <div class="bg-white rounded-lg shadow p-6 dashboard-card">
                            <div class="flex items-center">
                                <div class="p-3 rounded-full bg-primary-100 text-primary-600">
                                    <i class="fas fa-book-open text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-500">Total Checkouts</p>
                                    <p class="text-2xl font-semibold text-gray-800"><?= number_format($total_checkouts) ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg shadow p-6 dashboard-card">
                            <div class="flex items-center">
                                <div class="p-3 rounded-full bg-primary-100 text-primary-600">
                                    <i class="fas fa-book text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-500">Unique Books</p>
                                    <p class="text-2xl font-semibold text-gray-800"><?= number_format($unique_books) ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg shadow p-6 dashboard-card">
                            <div class="flex items-center">
                                <div class="p-3 rounded-full bg-primary-100 text-primary-600">
                                    <i class="fas fa-users text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-500">Active Patrons</p>
                                    <p class="text-2xl font-semibold text-gray-800"><?= number_format($unique_patrons) ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="bg-white rounded-lg shadow p-6 dashboard-card">
                            <div class="flex items-center">
                                <div class="p-3 rounded-full bg-primary-100 text-primary-600">
                                    <i class="fas fa-chart-line text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-500">Avg. Books per Patron</p>
                                    <p class="text-2xl font-semibold text-gray-800"><?= $avg_books_per_patron ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Content Grid -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Popular Books -->
                        <div class="lg:col-span-2 bg-white rounded-xl border border-gray-200 shadow-sm dashboard-card">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h2 class="text-lg font-semibold text-gray-800">Most Popular Books</h2>
                                <p class="text-sm text-gray-500">Top 50 most checked-out books</p>
                            </div>
                            <div class="overflow-x-auto custom-scrollbar">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Book</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Author</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Checkouts</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (count($popular_books) > 0): ?>
                                            <?php foreach ($popular_books as $index => $book): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $index + 1 ?></td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="flex items-center">
                                                            <?php if (!empty($book['cover_image'])): ?>
                                                                <div class="flex-shrink-0 h-10 w-8 mr-3">
                                                                    <img class="h-10 w-8 object-cover" src="../Uploads/covers/<?= htmlspecialchars($book['cover_image']) ?>" alt="Book cover">
                                                                </div>
                                                            <?php endif; ?>
                                                            <div>
                                                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($book['title']) ?></div>
                                                                <div class="text-xs text-gray-500"><?= htmlspecialchars($book['isbn']) ?></div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?= htmlspecialchars($book['author']) ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                            <?= number_format($book['checkout_count']) ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">
                                                    No checkout data available for the selected period.
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Right Sidebar -->
                        <div class="space-y-6">
                            <!-- Popular Categories -->
                            <div class="bg-white rounded-xl border border-gray-200 shadow-sm dashboard-card">
                                <div class="px-6 py-4 border-b border-gray-200">
                                    <h2 class="text-lg font-semibold text-gray-800">Popular Categories</h2>
                                </div>
                                <div class="p-4">
                                    <div class="space-y-4">
                                        <?php if (count($popular_categories) > 0): ?>
                                            <?php foreach ($popular_categories as $category): ?>
                                                <div>
                                                    <div class="flex justify-between text-sm mb-1">
                                                        <span class="font-medium text-gray-700"><?= htmlspecialchars($category['category_name']) ?></span>
                                                        <span class="font-medium text-gray-900"><?= number_format($category['checkout_count']) ?> checkouts</span>
                                                    </div>
                                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                                        <?php 
                                                            $percentage = $total_checkouts > 0 ? ($category['checkout_count'] / $total_checkouts) * 100 : 0;
                                                            $color = match(true) {
                                                                $percentage > 50 => 'bg-primary-500',
                                                                $percentage > 20 => 'bg-primary-400',
                                                                default => 'bg-primary-300'
                                                            };
                                                        ?>
                                                        <div class="h-2 rounded-full <?= $color ?>" style="width: <?= min(100, $percentage) ?>%"></div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <p class="text-sm text-gray-500 text-center py-2">No category data available.</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Most Active Patrons -->
                            <div class="bg-white rounded-xl border border-gray-200 shadow-sm dashboard-card">
                                <div class="px-6 py-4 border-b border-gray-200">
                                    <h2 class="text-lg font-semibold text-gray-800">Most Active Patrons</h2>
                                </div>
                                <div class="overflow-hidden">
                                    <ul class="divide-y divide-gray-200">
                                        <?php if (count($active_patrons) > 0): ?>
                                            <?php foreach ($active_patrons as $index => $patron): ?>
                                                <li class="px-6 py-4 hover:bg-gray-50">
                                                    <div class="flex items-center">
                                                        <div class="flex-shrink-0 h-10 w-10 rounded-full bg-primary-100 flex items-center justify-center">
                                                            <span class="text-primary-600 font-medium">
                                                                <?= strtoupper(substr($patron['first_name'], 0, 1) . substr($patron['last_name'], 0, 1)) ?>
                                                            </span>
                                                        </div>
                                                        <div class="ml-4">
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?= htmlspecialchars($patron['first_name'] . ' ' . $patron['last_name']) ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">
                                                                <?= isset($patron['email']) ? htmlspecialchars($patron['email']) : 'No email' ?>
                                                            </div>
                                                        </div>
                                                        <div class="ml-auto">
                                                            <span class="px-2 py-1 text-xs rounded-full bg-primary-100 text-primary-800">
                                                                <?= $patron['books_borrowed'] ?> books
                                                            </span>
                                                        </div>
                                                    </div>
                                                </li>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <li class="px-6 py-4 text-center text-sm text-gray-500">
                                                No patron data available for the selected period.
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <div id="toastContainer" class="fixed top-4 right-4 z-[10000] space-y-2"></div>

    <!-- Include jsPDF library for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM fully loaded. Initializing scripts...');

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
            }

            // Sidebar toggle for mobile
            function toggleSidebar() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('overlay');
                if (!sidebar || !overlay) {
                    console.error('Sidebar or overlay element not found');
                    return;
                }
                sidebar.classList.toggle('sidebar-open');
                overlay.classList.toggle('overlay-open');
                showToast(sidebar.classList.contains('sidebar-open') ? 'Sidebar opened' : 'Sidebar closed', 'info');
            }
            
            function closeSidebar() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('overlay');
                if (!sidebar || !overlay) {
                    console.error('Sidebar or overlay element not found');
                    return;
                }
                sidebar.classList.remove('sidebar-open');
                overlay.classList.remove('overlay-open');
                showToast('Sidebar closed', 'info');
            }
            
            // User menu toggle
            function toggleUserMenu() {
                const userMenu = document.getElementById('user-menu');
                if (!userMenu) {
                    console.error('User menu element not found');
                    return;
                }
                userMenu.classList.toggle('user-menu-open');
                showToast(userMenu.classList.contains('user-menu-open') ? 'User menu opened' : 'User menu closed', 'info');
            }
            
            // Notifications toggle
            function toggleNotifications() {
                const notificationPanel = document.getElementById('notification-panel');
                if (!notificationPanel) {
                    console.error('Notification panel element not found');
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
            }
            
            // Submenu toggle function
            function toggleSubmenu(submenuId) {
                const submenu = document.getElementById(submenuId);
                if (!submenu) {
                    console.error(`Submenu with ID ${submenuId} not found`);
                    showToast('Error toggling submenu', 'error');
                    return;
                }
                
                const parentLi = submenu.closest('li');
                const navItem = parentLi.querySelector('.nav-item');
                const chevron = navItem.querySelector('.chevron-icon');
                
                // Close other open submenus
                document.querySelectorAll('.submenu.open').forEach(otherSubmenu => {
                    if (otherSubmenu !== submenu) {
                        otherSubmenu.classList.remove('open');
                        const otherParentLi = otherSubmenu.closest('li');
                        const otherNavItem = otherParentLi.querySelector('.nav-item');
                        otherNavItem.classList.remove('open');
                        const otherChevron = otherNavItem.querySelector('.chevron-icon');
                        if (otherChevron) {
                            otherChevron.classList.remove('rotate-180');
                        }
                    }
                });
                
                // Toggle current submenu
                submenu.classList.toggle('open');
                navItem.classList.toggle('open');
                if (chevron) {
                    chevron.classList.toggle('rotate-180');
                }
                
                showToast(`Submenu ${submenuId.replace('-submenu', '')} ${submenu.classList.contains('open') ? 'expanded' : 'collapsed'}`, 'info');
            }
            
            // Sidebar collapse toggle
            function toggleSidebarCollapse() {
                const sidebar = document.getElementById('sidebar');
                const collapseIcon = document.getElementById('collapse-icon');
                if (!sidebar || !collapseIcon) {
                    console.error('Sidebar or collapse icon not found');
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
            }
            
            // Close menus when clicking outside
            document.addEventListener('click', function(event) {
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
            });
            
            // Export to PDF
            function exportToPDF() {
                try {
                    const { jsPDF } = window.jspdf;
                    if (!jsPDF) {
                        console.error('jsPDF library not loaded');
                        showToast('PDF export failed: Library not loaded', 'error');
                        return;
                    }
                    const doc = new jsPDF('landscape');
                    
                    // Add title
                    doc.setFontSize(18);
                    doc.text('Popular Books Report - Last <?= $time_period ?> Days', 14, 20);
                    
                    // Add date
                    doc.setFontSize(10);
                    doc.text('Generated on: ' + new Date().toLocaleDateString(), 14, 30);
                    
                    // Add statistics
                    doc.setFontSize(12);
                    doc.text('Statistics', 14, 45);
                    
                    doc.setFontSize(10);
                    doc.text('Total Checkouts: <?= number_format($total_checkouts) ?>', 20, 55);
                    doc.text('Unique Books: <?= number_format($unique_books) ?>', 80, 55);
                    doc.text('Active Patrons: <?= number_format($unique_patrons) ?>', 140, 55);
                    doc.text('Avg. Books per Patron: <?= $avg_books_per_patron ?>', 200, 55);
                    
                    // Add popular books table
                    doc.autoTable({
                        startY: 65,
                        head: [['#', 'Book Title', 'Author', 'Category', 'Checkouts']],
                        body: [
                            <?php foreach ($popular_books as $index => $book): ?>
                                [
                                    '<?= $index + 1 ?>',
                                    '<?= addslashes($book['title']) ?>',
                                    '<?= addslashes($book['author']) ?>',
                                    '<?= addslashes($book['category_name']) ?>',
                                    '<?= number_format($book['checkout_count']) ?>'
                                ],
                            <?php endforeach; ?>
                        ],
                        headStyles: { fillColor: [29, 152, 176] }, // primary-500
                        theme: 'grid',
                        margin: { top: 65 }
                    });
                    
                    // Save the PDF
                    doc.save('popular_books_report_<?= date('Y-m-d') ?>.pdf');
                    showToast('PDF exported successfully', 'success');
                } catch (error) {
                    console.error('Error exporting PDF:', error);
                    showToast('PDF export failed', 'error');
                }
            }

            // Initialize page
            showToast('Welcome to Popular Books & Analytics!', 'success');
            console.log('Popular Books page loaded');
            
            // Add event listener to export button
            const exportButton = document.getElementById('exportPdf');
            if (exportButton) {
                exportButton.addEventListener('click', exportToPDF);
            } else {
                console.error('Export PDF button not found');
            }
        });
    </script>
</body>
</html>