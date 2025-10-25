
<?php
// Enable error reporting for debugging, but suppress output to browser
ini_set('display_errors', 0); // Disable error output in production
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1); // Enable error logging
ini_set('error_log', __DIR__ . '/../logs/php_errors.log'); // Specify log file
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
    
    // Get user info from login_db
    $loginConn = $database->getConnection(''); // Connect to login_db
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
    
    // Get librarian details from librarian_db
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
    $pageTitle = 'Add New Book - ' . htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']);
    
    // Handle form submission
    $success = '';
    $error = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $title = trim($_POST['title'] ?? '');
        $author = trim($_POST['author'] ?? '');
        $isbn = trim($_POST['isbn'] ?? '');
        $publisher = trim($_POST['publisher'] ?? '');
        $publication_year = !empty($_POST['publication_year']) ? intval($_POST['publication_year']) : null;
        $category = trim($_POST['category'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $quantity = intval($_POST['quantity'] ?? 1);
        
        // Validate required fields
        $errors = [];
        if (empty($title)) $errors[] = 'Title is required';
        if (empty($author)) $errors[] = 'Author is required';
        if (empty($isbn)) $errors[] = 'ISBN is required';
        if ($quantity < 1) $errors[] = 'Quantity must be at least 1';
        
        if (empty($errors)) {
            try {
                // Check if books table exists, if not create it
                $checkTable = $librarianConn->query("SHOW TABLES LIKE 'books'");
                if ($checkTable->rowCount() == 0) {
                    $createTable = "CREATE TABLE IF NOT EXISTS `books` (
                        `id` int(11) NOT NULL AUTO_INCREMENT,
                        `isbn` varchar(20) NOT NULL,
                        `title` varchar(255) NOT NULL,
                        `author` varchar(100) NOT NULL,
                        `description` text DEFAULT NULL,
                        `publisher` varchar(100) DEFAULT NULL,
                        `publication_year` year(4) DEFAULT NULL,
                        `category` varchar(50) DEFAULT NULL,
                        `quantity` int(11) NOT NULL DEFAULT 1,
                        `available` int(11) NOT NULL DEFAULT 1,
                        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `isbn` (`isbn`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
                    $librarianConn->exec($createTable);
                }
                
                // Check if description column exists
                $checkDesc = $librarianConn->query("SHOW COLUMNS FROM books LIKE 'description'");
                if ($checkDesc->rowCount() == 0) {
                    $librarianConn->exec("ALTER TABLE books ADD COLUMN description text DEFAULT NULL AFTER author");
                }
                
                $stmt = $librarianConn->prepare("INSERT INTO books 
                    (title, author, isbn, publisher, publication_year, category, description, quantity, available) 
                    VALUES (:title, :author, :isbn, :publisher, :publication_year, :category, :description, :quantity, :available)");
                
                $stmt->execute([
                    ':title' => $title,
                    ':author' => $author,
                    ':isbn' => $isbn,
                    ':publisher' => !empty($publisher) ? $publisher : null,
                    ':publication_year' => $publication_year,
                    ':category' => !empty($category) ? $category : null,
                    ':description' => !empty($description) ? $description : null,
                    ':quantity' => $quantity,
                    ':available' => $quantity
                ]);
                
                $book_id = $librarianConn->lastInsertId();
                
                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    // Return JSON response for AJAX requests
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Book added successfully!',
                        'book_id' => $book_id
                    ]);
                    exit();
                } else {
                    // Redirect for normal form submission
                    $_SESSION['success_message'] = 'Book "' . $title . '" added successfully! (ID: ' . $book_id . ')';
                    header('Location: books.php');
                    exit();
                }
                
            } catch (PDOException $e) {
                error_log('Error adding book: ' . $e->getMessage());
                $errorMsg = 'Error adding book: ';
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $errorMsg .= 'A book with this ISBN already exists.';
                } else {
                    $errorMsg .= $e->getMessage();
                }
                
                if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    // Return JSON response for AJAX requests
                    header('Content-Type: application/json');
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => $errorMsg]);
                    exit();
                } else {
                    $error = $errorMsg;
                }
            }
        } else {
            $errorMessage = implode(' ', $errors);
            if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                // Return JSON response for AJAX requests
                header('Content-Type: application/json');
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => $errorMessage]);
                exit();
            } else {
                $error = $errorMessage;
            }
        }
    }
    
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
    <title>📚 Librarian Portal – Add New Book</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="/San%20Agustin/librarian/js/common.js"></script>
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
                    <a href="dashboard.php" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-home w-5"></i>
                        <span class="ml-3 sidebar-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg bg-primary-600 text-white shadow-md nav-item" onclick="toggleSubmenu('catalog-submenu', this)">
                        <i class="fas fa-book w-5"></i>
                        <span class="ml-3 sidebar-text">Catalog Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="catalog-submenu" class="submenu pl-4 mt-1">
                        <a href="add_book.php" class="flex items-center p-2 rounded-lg bg-primary-600 text-white shadow-md">
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('reports-submenu', this)">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Analytics</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
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
                <h1 class="text-xl font-bold">Add New Book</h1>
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
            <div class="max-w-4xl mx-auto">
                <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm mb-6">
                    <div class="flex justify-between items-center mb-6">
                        <h1 class="text-2xl font-bold text-gray-800">Add New Book</h1>
                        <a href="books.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">View Catalog</a>
                    </div>
                    
                    <?php if ($success): ?>
                        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                            <?php echo htmlspecialchars($success); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error): ?>
                        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" id="addBookForm" class="space-y-4">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-1">Title <span class="text-red-500">*</span></label>
                            <input type="text" id="title" name="title" required
                                   value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                   placeholder="Enter book title">
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="author" class="block text-sm font-medium text-gray-700 mb-1">Author <span class="text-red-500">*</span></label>
                                <input type="text" id="author" name="author" required
                                       value="<?php echo isset($_POST['author']) ? htmlspecialchars($_POST['author']) : ''; ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                       placeholder="Enter author name">
                            </div>
                            
                            <div>
                                <label for="isbn" class="block text-sm font-medium text-gray-700 mb-1">ISBN <span class="text-red-500">*</span></label>
                                <input type="text" id="isbn" name="isbn" required
                                       value="<?php echo isset($_POST['isbn']) ? htmlspecialchars($_POST['isbn']) : ''; ?>"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                       placeholder="978-0-123456-78-9">
                            </div>
                            
                            <div>
                                <label for="publisher" class="block text-sm font-medium text-gray-700 mb-1">Publisher</label>
                                <input type="text" id="publisher" name="publisher"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label for="publication_year" class="block text-sm font-medium text-gray-700 mb-1">Publication Year</label>
                                    <input type="number" id="publication_year" name="publication_year" min="1000" max="<?php echo date('Y') + 1; ?>"
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                                </div>
                                
                                <div>
                                    <label for="quantity" class="block text-sm font-medium text-gray-700 mb-1">Quantity <span class="text-red-500">*</span></label>
                                    <input type="number" id="quantity" name="quantity" min="1" value="1" required
                                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                                </div>
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Category</label>
                                <input type="text" id="category" name="category"
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                       placeholder="e.g., Fiction, Science, History">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                                <textarea id="description" name="description" rows="3"
                                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"></textarea>
                            </div>
                        </div>
                        
                        <div class="pt-4">
                            <button type="submit" class="bg-primary-600 text-white px-6 py-2 rounded-lg hover:bg-primary-700 transition-colors">
                                <i class="fas fa-save mr-2"></i> Save Book
                            </button>
                        </div>
                    </form>
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
        
        function toggleUserMenu() {
            try {
                const userMenu = document.getElementById('user-menu');
                if (!userMenu) {
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
                // Prevent default link behavior
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

                // Toggle submenu visibility
                submenu.classList.toggle('open');
                if (chevron) {
                    chevron.classList.toggle('rotate-90');
                }

                // Show toast notification
                const submenuName = submenuId.replace('-submenu', '');
                showToast(`Submenu ${submenuName} ${submenu.classList.contains('open') ? 'expanded' : 'collapsed'}`, 'info');
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

                console.log('Add New Book page loaded');

                // Safely handle PHP-generated toast messages
                <?php if ($success): ?>
                    showToast(<?php echo json_encode($success); ?>, 'success');
                <?php endif; ?>
                <?php if ($error): ?>
                    showToast(<?php echo json_encode($error); ?>, 'error');
                <?php endif; ?>
                
                // Handle form submission
                const addBookForm = document.getElementById('addBookForm');
                if (addBookForm) {
                    addBookForm.addEventListener('submit', function(e) {
                        const submitBtn = this.querySelector('button[type="submit"]');
                        if (submitBtn) {
                            submitBtn.disabled = true;
                            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Saving...';
                        }
                    });
                }
            } catch (error) {
                console.error('Error in DOMContentLoaded:', error);
                showToast('Error loading page', 'error');
            }
        });
    </script>
</body>
</html>