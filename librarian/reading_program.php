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
    $pageTitle = 'Reading Programs - ' . htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']);
    
    // Check if reading_programs table exists, if not create it
    $checkTable = $librarianConn->query("SHOW TABLES LIKE 'reading_programs'");
    if ($checkTable->rowCount() == 0) {
        $createTable = "CREATE TABLE IF NOT EXISTS `reading_programs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL,
            `description` text DEFAULT NULL,
            `start_date` date NOT NULL,
            `end_date` date NOT NULL,
            `target_minutes` int(11) DEFAULT NULL,
            `target_books` int(11) DEFAULT NULL,
            `age_group` enum('children','teens','adults','all') DEFAULT 'all',
            `status` enum('upcoming','active','completed','cancelled') DEFAULT 'upcoming',
            `created_by` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `created_by` (`created_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $librarianConn->exec($createTable);
    }
    
    // Check if reading_logs table exists, if not create it
    $checkTable = $librarianConn->query("SHOW TABLES LIKE 'reading_logs'");
    if ($checkTable->rowCount() == 0) {
        $createTable = "CREATE TABLE IF NOT EXISTS `reading_logs` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `program_id` int(11) NOT NULL,
            `patron_id` int(11) NOT NULL,
            `book_id` int(11) DEFAULT NULL,
            `book_title` varchar(255) NOT NULL,
            `minutes_read` int(11) NOT NULL,
            `pages_read` int(11) DEFAULT NULL,
            `log_date` date NOT NULL,
            `notes` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            KEY `program_id` (`program_id`),
            KEY `patron_id` (`patron_id`),
            KEY `book_id` (`book_id`),
            KEY `log_date` (`log_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $librarianConn->exec($createTable);
    }
    
    // Initialize variables
    $action = $_GET['action'] ?? 'list';
    $programId = $_GET['id'] ?? 0;
    $message = '';
    $messageType = '';
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['add_program'])) {
            // Add new reading program
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $startDate = $_POST['start_date'] ?? '';
            $endDate = $_POST['end_date'] ?? '';
            $targetMinutes = (int)($_POST['target_minutes'] ?? 0);
            $targetBooks = (int)($_POST['target_books'] ?? 0);
            $ageGroup = $_POST['age_group'] ?? 'all';
            $status = $_POST['status'] ?? 'upcoming';
            
            if (empty($title) || empty($startDate) || empty($endDate)) {
                $message = 'Please fill in all required fields.';
                $messageType = 'error';
            } elseif (strtotime($startDate) > strtotime($endDate)) {
                $message = 'End date must be after start date.';
                $messageType = 'error';
            } else {
                $stmt = $librarianConn->prepare("INSERT INTO reading_programs 
                                       (title, description, start_date, end_date, target_minutes, target_books, age_group, status, created_by) 
                                       VALUES (:title, :description, :start_date, :end_date, :target_minutes, :target_books, :age_group, :status, :created_by)");
                $stmt->execute([
                    ':title' => $title,
                    ':description' => $description,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate,
                    ':target_minutes' => $targetMinutes,
                    ':target_books' => $targetBooks,
                    ':age_group' => $ageGroup,
                    ':status' => $status,
                    ':created_by' => $_SESSION['user_id']
                ]);
                
                $message = 'Reading program added successfully.';
                $messageType = 'success';
                $action = 'list';
            }
        } elseif (isset($_POST['edit_program'])) {
            // Update existing reading program
            $programId = $_POST['program_id'] ?? 0;
            $title = $_POST['title'] ?? '';
            $description = $_POST['description'] ?? '';
            $startDate = $_POST['start_date'] ?? '';
            $endDate = $_POST['end_date'] ?? '';
            $targetMinutes = (int)($_POST['target_minutes'] ?? 0);
            $targetBooks = (int)($_POST['target_books'] ?? 0);
            $ageGroup = $_POST['age_group'] ?? 'all';
            $status = $_POST['status'] ?? 'upcoming';
            
            if (empty($title) || empty($startDate) || empty($endDate)) {
                $message = 'Please fill in all required fields.';
                $messageType = 'error';
            } elseif (strtotime($startDate) > strtotime($endDate)) {
                $message = 'End date must be after start date.';
                $messageType = 'error';
            } else {
                $stmt = $librarianConn->prepare("UPDATE reading_programs 
                                       SET title = :title, description = :description, start_date = :start_date, end_date = :end_date, 
                                           target_minutes = :target_minutes, target_books = :target_books, age_group = :age_group, status = :status
                                       WHERE id = :program_id");
                $stmt->execute([
                    ':title' => $title,
                    ':description' => $description,
                    ':start_date' => $startDate,
                    ':end_date' => $endDate,
                    ':target_minutes' => $targetMinutes,
                    ':target_books' => $targetBooks,
                    ':age_group' => $ageGroup,
                    ':status' => $status,
                    ':program_id' => $programId
                ]);
                
                $message = 'Reading program updated successfully.';
                $messageType = 'success';
                $action = 'list';
            }
        } elseif (isset($_POST['delete_program'])) {
            // Delete reading program
            $programId = $_POST['program_id'] ?? 0;
            
            $stmt = $librarianConn->prepare("DELETE FROM reading_programs WHERE id = :program_id");
            $stmt->execute([':program_id' => $programId]);
            
            $message = 'Reading program deleted successfully.';
            $messageType = 'success';
            $action = 'list';
        } elseif (isset($_POST['add_log'])) {
            // Add reading log
            $programId = $_POST['program_id'] ?? 0;
            $patronId = $_POST['patron_id'] ?? 0;
            $bookId = $_POST['book_id'] ?? null;
            $bookTitle = $_POST['book_title'] ?? '';
            $minutesRead = (int)($_POST['minutes_read'] ?? 0);
            $pagesRead = !empty($_POST['pages_read']) ? (int)$_POST['pages_read'] : null;
            $logDate = $_POST['log_date'] ?? date('Y-m-d');
            $notes = $_POST['notes'] ?? '';
            
            if ($programId <= 0 || $patronId <= 0 || empty($bookTitle) || $minutesRead <= 0) {
                $message = 'Please fill in all required fields.';
                $messageType = 'error';
            } else {
                $stmt = $librarianConn->prepare("INSERT INTO reading_logs 
                                       (program_id, patron_id, book_id, book_title, minutes_read, pages_read, log_date, notes) 
                                       VALUES (:program_id, :patron_id, :book_id, :book_title, :minutes_read, :pages_read, :log_date, :notes)");
                $stmt->execute([
                    ':program_id' => $programId,
                    ':patron_id' => $patronId,
                    ':book_id' => $bookId,
                    ':book_title' => $bookTitle,
                    ':minutes_read' => $minutesRead,
                    ':pages_read' => $pagesRead,
                    ':log_date' => $logDate,
                    ':notes' => $notes
                ]);
                
                $message = 'Reading log added successfully.';
                $messageType = 'success';
            }
        }
    }
    
    // Get program data for edit/view
    $program = null;
    $programs = [];
    $readingLogs = [];
    $programStats = [];
    
    if ($action === 'edit' || $action === 'view') {
        $stmt = $librarianConn->prepare("SELECT * FROM reading_programs WHERE id = :program_id");
        $stmt->execute([':program_id' => $programId]);
        $program = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$program) {
            $message = 'Reading program not found.';
            $messageType = 'error';
            $action = 'list';
        } else {
            // Get reading logs for this program
            $stmt = $librarianConn->prepare("
                SELECT rl.*, CONCAT(p.first_name, ' ', p.last_name) as patron_name, p.card_number
                FROM reading_logs rl
                JOIN patrons p ON rl.patron_id = p.id
                WHERE rl.program_id = :program_id
                ORDER BY rl.log_date DESC, rl.created_at DESC
            ");
            $stmt->execute([':program_id' => $programId]);
            $readingLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get program statistics
            $stmt = $librarianConn->prepare("
                SELECT 
                    COUNT(DISTINCT rl.patron_id) as total_participants,
                    COUNT(DISTINCT rl.book_id) as unique_books_read,
                    SUM(rl.minutes_read) as total_minutes_read,
                    SUM(rl.pages_read) as total_pages_read,
                    COUNT(*) as total_log_entries
                FROM reading_logs rl
                WHERE rl.program_id = :program_id
            ");
            $stmt->execute([':program_id' => $programId]);
            $programStats = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
    
    // Get all programs for the list view
    if ($action === 'list') {
        $query = "SELECT rp.*, 
                  (SELECT COUNT(DISTINCT patron_id) FROM reading_logs WHERE program_id = rp.id) as participant_count,
                  (SELECT COUNT(*) FROM reading_logs WHERE program_id = rp.id) as log_count
                  FROM reading_programs rp
                  ORDER BY rp.start_date DESC, rp.created_at DESC";
        $stmt = $librarianConn->prepare($query);
        $stmt->execute();
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    }
    .submenu.open {
        max-height: 500px; /* Adjust based on submenu content size */
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
    .submenu {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s ease-out;
}
.submenu.open {
    max-height: 500px; /* Adjust based on submenu content size */
    transition: max-height 0.3s ease-in;
}
.nav-item .chevron-icon {
    transition: transform 0.3s ease;
}
.nav-item.open .chevron-icon {
    transform: rotate(180deg);
}
    .status-upcoming { background-color: #E0E7FF; color: #3730A3; }
    .status-active { background-color: #D1FAE5; color: #065F46; }
    .status-completed { background-color: #E5E7EB; color: #1F2937; }
    .status-cancelled { background-color: #FEE2E2; color: #991B1B; }
    .age-group-children { background-color: #FEF3C7; color: #92400E; }
    .age-group-teens { background-color: #DBEAFE; color: #1E40AF; }
    .age-group-adults { background-color: #E0F2FE; color: #075985; }
    .age-group-all { background-color: #EDE9FE; color: #5B21B6; }
</style>
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
                    <a href="reading_program.php" class="flex items-center p-3 rounded-lg bg-primary-600 text-white shadow-md nav-item">
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
                <h1 class="text-xl font-bold">Reading Programs</h1>
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
                    <?php if ($message): ?>
                        <div class="mb-6 p-4 rounded-lg <?php echo $messageType === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                            <?php echo htmlspecialchars($message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($action === 'list'): ?>
                        <!-- List of Reading Programs -->
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                            <h1 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">Reading Programs</h1>
                            <div class="flex space-x-3">
                                <a href="?action=add" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors flex items-center">
                                    <i class="fas fa-plus mr-2"></i> New Reading Program
                                </a>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-1 gap-6">
                            <?php if (empty($programs)): ?>
                                <div class="bg-white overflow-hidden shadow rounded-lg">
                                    <div class="px-4 py-5 sm:p-6 text-center">
                                        <i class="fas fa-book-reader text-4xl text-gray-400 mb-3"></i>
                                        <h3 class="text-lg font-medium text-gray-900">No reading programs found</h3>
                                        <p class="mt-1 text-sm text-gray-500">Get started by creating a new reading program.</p>
                                        <div class="mt-6">
                                            <a href="?action=add" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors flex items-center justify-center">
                                                <i class="fas fa-plus mr-2"></i> New Reading Program
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <?php foreach ($programs as $program): ?>
                                    <div class="bg-white overflow-hidden shadow rounded-lg dashboard-card">
                                        <div class="px-4 py-5 sm:p-6">
                                            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                                <div class="flex-1">
                                                    <div class="flex items-center">
                                                        <h3 class="text-lg font-medium text-gray-900"><?php echo htmlspecialchars($program['title']); ?></h3>
                                                        <span class="ml-2 px-2 py-1 text-xs font-medium rounded-full status-<?php echo $program['status']; ?>">
                                                            <?php echo ucfirst($program['status']); ?>
                                                        </span>
                                                        <span class="ml-2 px-2 py-1 text-xs font-medium rounded-full age-group-<?php echo $program['age_group']; ?>">
                                                            <?php echo ucfirst($program['age_group']); ?>
                                                        </span>
                                                    </div>
                                                    <p class="mt-1 text-sm text-gray-500">
                                                        <?php echo date('M j, Y', strtotime($program['start_date'])); ?> - 
                                                        <?php echo date('M j, Y', strtotime($program['end_date'])); ?>
                                                    </p>
                                                    <?php if (!empty($program['description'])): ?>
                                                        <p class="mt-2 text-sm text-gray-600"><?php echo nl2br(htmlspecialchars($program['description'])); ?></p>
                                                    <?php endif; ?>
                                                    <div class="mt-3 flex flex-wrap gap-2">
                                                        <?php if ($program['target_minutes'] > 0): ?>
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                                                <i class="fas fa-clock mr-1"></i> <?php echo number_format($program['target_minutes']); ?> min target
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if ($program['target_books'] > 0): ?>
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                                                <i class="fas fa-book mr-1"></i> <?php echo $program['target_books']; ?> books target
                                                            </span>
                                                        <?php endif; ?>
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                                            <i class="fas fa-users mr-1"></i> <?php echo $program['participant_count']; ?> participants
                                                        </span>
                                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                                            <i class="fas fa-list mr-1"></i> <?php echo $program['log_count']; ?> logs
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="mt-4 flex-shrink-0 flex md:mt-0 md:ml-4 space-x-2">
                                                    <button type="button" onclick="viewProgram(<?php echo htmlspecialchars(json_encode($program)); ?>)" class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                                        <i class="fas fa-eye mr-1"></i> View
                                                    </button>
                                                    <button type="button" onclick="editProgram(<?php echo htmlspecialchars(json_encode($program)); ?>)" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                                        <i class="fas fa-edit mr-1"></i> Edit
                                                    </button>
                                                    <button type="button" onclick="confirmDeleteProgram(<?php echo $program['id']; ?>)" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                                        <i class="fas fa-trash mr-1"></i> Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                    <?php elseif ($action === 'add' || $action === 'edit'): ?>
                        <!-- Add/Edit Reading Program Form -->
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                            <h1 class="text-2xl font-bold text-gray-800 mb-4 md:mb-0">
                                <?php echo $action === 'add' ? 'Add New Reading Program' : 'Edit Reading Program'; ?>
                            </h1>
                            <div class="flex space-x-3">
                                <a href="?action=list" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 transition-colors flex items-center">
                                    <i class="fas fa-arrow-left mr-2"></i> Back to List
                                </a>
                            </div>
                        </div>
                        
                        <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm dashboard-card">
                            <form method="POST" class="space-y-6">
                                <div class="grid grid-cols-1 gap-y-6 gap-x-4 sm:grid-cols-6">
                                    <div class="sm:col-span-4">
                                        <label for="title" class="block text-sm font-medium text-gray-700">Program Title <span class="text-red-500">*</span></label>
                                        <input type="text" name="title" id="title" required
                                               value="<?php echo htmlspecialchars($program['title'] ?? ''); ?>"
                                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                    </div>
                                    
                                    <div class="sm:col-span-6">
                                        <label for="description" class="block text-sm font-medium text-gray-700">Description</label>
                                        <textarea name="description" id="description" rows="3"
                                                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"><?php echo htmlspecialchars($program['description'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="sm:col-span-2">
                                        <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date <span class="text-red-500">*</span></label>
                                        <input type="date" name="start_date" id="start_date" required
                                               value="<?php echo $program['start_date'] ?? date('Y-m-d'); ?>"
                                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                    </div>
                                    
                                    <div class="sm:col-span-2">
                                        <label for="end_date" class="block text-sm font-medium text-gray-700">End Date <span class="text-red-500">*</span></label>
                                        <input type="date" name="end_date" id="end_date" required
                                               value="<?php echo $program['end_date'] ?? date('Y-m-d', strtotime('+1 month')); ?>"
                                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                    </div>
                                    
                                    <div class="sm:col-span-2">
                                        <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                                        <select name="status" id="status"
                                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                            <option value="upcoming" <?php echo ($program['status'] ?? 'upcoming') === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
                                            <option value="active" <?php echo ($program['status'] ?? '') === 'active' ? 'selected' : ''; ?>>Active</option>
                                            <option value="completed" <?php echo ($program['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                            <option value="cancelled" <?php echo ($program['status'] ?? '') === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                    </div>
                                    
                                    <div class="sm:col-span-3">
                                        <label for="target_minutes" class="block text-sm font-medium text-gray-700">Target Minutes (optional)</label>
                                        <div class="mt-1 relative rounded-md shadow-sm">
                                            <input type="number" name="target_minutes" id="target_minutes" min="0" step="1"
                                                   value="<?php echo $program['target_minutes'] ?? ''; ?>"
                                                   class="focus:ring-primary-500 focus:border-primary-500 block w-full pr-12 sm:text-sm border-gray-300 rounded-md">
                                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                                <span class="text-gray-500 sm:text-sm">minutes</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="sm:col-span-3">
                                        <label for="target_books" class="block text-sm font-medium text-gray-700">Target Books (optional)</label>
                                        <div class="mt-1 relative rounded-md shadow-sm">
                                            <input type="number" name="target_books" id="target_books" min="0" step="1"
                                                   value="<?php echo $program['target_books'] ?? ''; ?>"
                                                   class="focus:ring-primary-500 focus:border-primary-500 block w-full pr-12 sm:text-sm border-gray-300 rounded-md">
                                            <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                                <span class="text-gray-500 sm:text-sm">books</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="sm:col-span-3">
                                        <label for="age_group" class="block text-sm font-medium text-gray-700">Age Group</label>
                                        <select name="age_group" id="age_group"
                                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                            <option value="all" <?php echo ($program['age_group'] ?? 'all') === 'all' ? 'selected' : ''; ?>>All Ages</option>
                                            <option value="children" <?php echo ($program['age_group'] ?? '') === 'children' ? 'selected' : ''; ?>>Children (0-12)</option>
                                            <option value="teens" <?php echo ($program['age_group'] ?? '') === 'teens' ? 'selected' : ''; ?>>Teens (13-17)</option>
                                            <option value="adults" <?php echo ($program['age_group'] ?? '') === 'adults' ? 'selected' : ''; ?>>Adults (18+)</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="pt-5">
                                    <div class="flex justify-end">
                                        <a href="?action=list" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 transition-colors flex items-center">
                                            Cancel
                                        </a>
                                        <?php if ($action === 'edit'): ?>
                                            <input type="hidden" name="program_id" value="<?php echo $program['id']; ?>">
                                            <button type="submit" name="edit_program" class="ml-3 bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors flex items-center">
                                                <i class="fas fa-save mr-2"></i> Save Changes
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="add_program" class="ml-3 bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors flex items-center">
                                                <i class="fas fa-plus mr-2"></i> Create Program
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <?php elseif ($action === 'view' && $program): ?>
                        <!-- View Reading Program -->
                        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                            <div class="flex items-center mb-4 md:mb-0">
                                <h1 class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($program['title']); ?></h1>
                                <span class="ml-2 px-2 py-1 text-xs font-medium rounded-full status-<?php echo $program['status']; ?>">
                                    <?php echo ucfirst($program['status']); ?>
                                </span>
                                <span class="ml-2 px-2 py-1 text-xs font-medium rounded-full age-group-<?php echo $program['age_group']; ?>">
                                    <?php echo ucfirst($program['age_group']); ?>
                                </span>
                            </div>
                            <div class="flex space-x-3">
                                <a href="?action=list" class="bg-gray-200 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-300 transition-colors flex items-center">
                                    <i class="fas fa-arrow-left mr-2"></i> Back to List
                                </a>
                                <a href="?action=edit&id=<?php echo $program['id']; ?>" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors flex items-center">
                                    <i class="fas fa-edit mr-2"></i> Edit
                                </a>
                                <button type="button" onclick="showAddLogModal()" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors flex items-center">
                                    <i class="fas fa-plus mr-2"></i> Add Reading Log
                                </button>
                            </div>
                        </div>
                        
                        <?php if (!empty($program['description'])): ?>
                            <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm mb-6 dashboard-card">
                                <h3 class="text-lg font-medium text-gray-900">Description</h3>
                                <div class="mt-2 max-w-xl text-sm text-gray-500">
                                    <p><?php echo nl2br(htmlspecialchars($program['description'])); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Program Stats -->
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-6">
                            <div class="bg-white overflow-hidden shadow rounded-lg dashboard-card">
                                <div class="px-4 py-5 sm:p-6">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 bg-primary-500 rounded-md p-3">
                                            <i class="fas fa-users text-white"></i>
                                        </div>
                                        <div class="ml-5 w-0 flex-1">
                                            <dl>
                                                <dt class="text-sm font-medium text-gray-500 truncate">Participants</dt>
                                                <dd class="flex items-baseline">
                                                    <div class="text-2xl font-semibold text-gray-900"><?php echo number_format($programStats['total_participants'] ?? 0); ?></div>
                                                </dd>
                                            </dl>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-white overflow-hidden shadow rounded-lg dashboard-card">
                                <div class="px-4 py-5 sm:p-6">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 bg-primary-500 rounded-md p-3">
                                            <i class="fas fa-book text-white"></i>
                                        </div>
                                        <div class="ml-5 w-0 flex-1">
                                            <dl>
                                                <dt class="text-sm font-medium text-gray-500 truncate">Books Read</dt>
                                                <dd class="flex items-baseline">
                                                    <div class="text-2xl font-semibold text-gray-900"><?php echo number_format($programStats['unique_books_read'] ?? 0); ?></div>
                                                    <?php if ($program['target_books'] > 0): ?>
                                                        <div class="ml-2 flex items-baseline text-sm font-semibold text-green-600">
                                                            of <?php echo $program['target_books']; ?> target
                                                        </div>
                                                    <?php endif; ?>
                                                </dd>
                                            </dl>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-white overflow-hidden shadow rounded-lg dashboard-card">
                                <div class="px-4 py-5 sm:p-6">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 bg-primary-500 rounded-md p-3">
                                            <i class="fas fa-clock text-white"></i>
                                        </div>
                                        <div class="ml-5 w-0 flex-1">
                                            <dl>
                                                <dt class="text-sm font-medium text-gray-500 truncate">Minutes Read</dt>
                                                <dd class="flex items-baseline">
                                                    <div class="text-2xl font-semibold text-gray-900"><?php echo number_format($programStats['total_minutes_read'] ?? 0); ?></div>
                                                    <?php if ($program['target_minutes'] > 0): ?>
                                                        <div class="ml-2 flex items-baseline text-sm font-semibold text-green-600">
                                                            of <?php echo number_format($program['target_minutes']); ?> target
                                                        </div>
                                                    <?php endif; ?>
                                                </dd>
                                            </dl>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="bg-white overflow-hidden shadow rounded-lg dashboard-card">
                                <div class="px-4 py-5 sm:p-6">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 bg-primary-500 rounded-md p-3">
                                            <i class="fas fa-list text-white"></i>
                                        </div>
                                        <div class="ml-5 w-0 flex-1">
                                            <dl>
                                                <dt class="text-sm font-medium text-gray-500 truncate">Reading Logs</dt>
                                                <dd class="flex items-baseline">
                                                    <div class="text-2xl font-semibold text-gray-900"><?php echo number_format($programStats['total_log_entries'] ?? 0); ?></div>
                                                </dd>
                                            </dl>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Reading Logs -->
                        <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm dashboard-card">
                            <div class="mb-6">
                                <h3 class="text-lg font-medium text-gray-900">Reading Logs</h3>
                                <p class="mt-1 text-sm text-gray-500">A list of all reading logs for this program.</p>
                            </div>
                            <div class="overflow-x-auto custom-scrollbar">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Patron</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Book</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pages</th>
                                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php if (empty($readingLogs)): ?>
                                            <tr>
                                                <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                                                    No reading logs found. Add a reading log to get started.
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($readingLogs as $log): ?>
                                                <tr class="hover:bg-gray-50">
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?php echo date('M j, Y', strtotime($log['log_date'])); ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($log['patron_name']); ?></div>
                                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($log['card_number']); ?></div>
                                                    </td>
                                                    <td class="px-6 py-4">
                                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($log['book_title']); ?></div>
                                                        <?php if ($log['book_id']): ?>
                                                            <div class="text-sm text-gray-500">ID: <?php echo $log['book_id']; ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?php echo number_format($log['minutes_read']); ?> min
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                        <?php echo $log['pages_read'] ? number_format($log['pages_read']) : 'N/A'; ?>
                                                    </td>
                                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                        <a href="javascript:void(0)" onclick="viewLogDetails(<?php echo htmlspecialchars(json_encode($log)); ?>)" class="text-primary-600 hover:text-primary-900 mr-3">
                                                            <i class="fas fa-eye"></i> View
                                                        </a>
                                                        <a href="javascript:void(0)" onclick="editLog(<?php echo $log['id']; ?>)" class="text-yellow-600 hover:text-yellow-900">
                                                            <i class="fas fa-edit"></i> Edit
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        
                        <!-- Add Reading Log Modal -->
                        <div id="addLogModal" class="fixed z-50 inset-0 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                            <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
                                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                                <div class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full sm:p-6">
                                    <div class="hidden sm:block absolute top-0 right-0 pt-4 pr-4">
                                        <button type="button" onclick="closeAddLogModal()" class="bg-white rounded-md text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                            <span class="sr-only">Close</span>
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <div>
                                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                                            <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                                Add Reading Log
                                            </h3>
                                            <div class="mt-2">
                                                <form id="readingLogForm" method="POST" class="space-y-4">
                                                    <input type="hidden" name="program_id" value="<?php echo $program['id']; ?>">
                                                    <div class="grid grid-cols-1 gap-y-4 gap-x-6 sm:grid-cols-6">
                                                        <div class="sm:col-span-3">
                                                            <label for="patron_id" class="block text-sm font-medium text-gray-700">Patron <span class="text-red-500">*</span></label>
                                                            <select name="patron_id" id="patron_id" required
                                                                    class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                                                <option value="">Select a patron</option>
                                                                <!-- Patrons would be loaded via AJAX -->
                                                            </select>
                                                        </div>
                                                        <div class="sm:col-span-3">
                                                            <label for="log_date" class="block text-sm font-medium text-gray-700">Date <span class="text-red-500">*</span></label>
                                                            <input type="date" name="log_date" id="log_date" required
                                                                   value="<?php echo date('Y-m-d'); ?>"
                                                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                                        </div>
                                                        <div class="sm:col-span-6">
                                                            <label for="book_title" class="block text-sm font-medium text-gray-700">Book Title <span class="text-red-500">*</span></label>
                                                            <input type="text" name="book_title" id="book_title" required
                                                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                                        </div>
                                                        <div class="sm:col-span-2">
                                                            <label for="book_id" class="block text-sm font-medium text-gray-700">Book ID (optional)</label>
                                                            <input type="number" name="book_id" id="book_id" min="1"
                                                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                                        </div>
                                                        <div class="sm:col-span-2">
                                                            <label for="minutes_read" class="block text-sm font-medium text-gray-700">Minutes Read <span class="text-red-500">*</span></label>
                                                            <div class="mt-1 relative rounded-md shadow-sm">
                                                                <input type="number" name="minutes_read" id="minutes_read" min="1" required
                                                                       class="focus:ring-primary-500 focus:border-primary-500 block w-full pr-12 sm:text-sm border-gray-300 rounded-md">
                                                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                                                    <span class="text-gray-500 sm:text-sm">min</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="sm:col-span-2">
                                                            <label for="pages_read" class="block text-sm font-medium text-gray-700">Pages Read (optional)</label>
                                                            <div class="mt-1 relative rounded-md shadow-sm">
                                                                <input type="number" name="pages_read" id="pages_read" min="1"
                                                                       class="focus:ring-primary-500 focus:border-primary-500 block w-full pr-12 sm:text-sm border-gray-300 rounded-md">
                                                                <div class="absolute inset-y-0 right-0 pr-3 flex items-center pointer-events-none">
                                                                    <span class="text-gray-500 sm:text-sm">pages</span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="sm:col-span-6">
                                                            <label for="notes" class="block text-sm font-medium text-gray-700">Notes (optional)</label>
                                                            <textarea name="notes" id="notes" rows="3"
                                                                      class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"></textarea>
                                                    </div>
                                                </div>
                                                
                                                <div class="mt-5 sm:mt-6 sm:grid sm:grid-cols-2 sm:gap-3 sm:grid-flow-row-dense">
                                                    <button type="submit" name="add_log" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-primary-600 text-base font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:col-start-2 sm:text-sm">
                                                        Add Log
                                                    </button>
                                                    <button type="button" onclick="closeAddLogModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:mt-0 sm:col-start-1 sm:text-sm">
                                                        Cancel
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Log Details Modal -->
                    <div id="logDetailsModal" class="fixed z-10 inset-0 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
                            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                            <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                    <div class="sm:flex sm:items-start">
                                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                            <div class="flex justify-between items-center mb-4">
                                                <h3 class="text-lg leading-6 font-medium text-gray-900" id="logDetailsTitle">
                                                    Reading Log Details
                                                </h3>
                                                <button type="button" onclick="closeLogDetailsModal()" class="text-gray-400 hover:text-gray-500">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            </div>
                                            <div id="logDetailsContent" class="mt-2 space-y-4">
                                                <!-- Content will be populated by JavaScript -->
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                                    <button type="button" onclick="closeLogDetailsModal()" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-primary-600 text-base font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:ml-3 sm:w-auto sm:text-sm">
                                        Close
                                    </button>
                                </div>
                            </div>
                        </div>
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
    event.preventDefault(); // Prevent default link behavior
    const submenu = document.getElementById(menuId);
    const parentLi = button.closest('li');
    const icon = button.querySelector('.chevron-icon');
    
    // Toggle the open class for animation
    submenu.classList.toggle('open');
    parentLi.classList.toggle('open');
    
    // Close all other open submenus in the same parent (optional)
    const parentMenu = button.closest('ul');
    const allSubmenus = parentMenu.querySelectorAll('.submenu');
    const allNavItems = parentMenu.querySelectorAll('.nav-item');
    
    allSubmenus.forEach((item) => {
        if (item.id !== menuId) {
            item.classList.remove('open');
        }
    });
    
    allNavItems.forEach((item) => {
        if (item !== button) {
            item.closest('li').classList.remove('open');
        }
    });
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
    if (!event.target.closest('.relative button') && !event.target.closest('#user-menu')) {
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
    doc.text(`Total Checkouts: ${<?php echo $stats['total_checkouts'] ?? 0; ?>}`, 50, 135);
    doc.text(`Total Returns: ${<?php echo $stats['total_returns'] ?? 0; ?>}`, 200, 135);
    doc.text(`Overdue Items: ${<?php echo $stats['total_overdue'] ?? 0; ?>}`, 50, 155);
    doc.text(`Active Patrons: ${<?php echo $stats['active_patrons'] ?? 0; ?>}`, 200, 155);
    
    // Add popular books table
    doc.setFontSize(12);
    doc.text('Most Borrowed Books', 40, 200);
    
    const popularBooks = <?php echo json_encode($popular_books ?? []); ?>;
    const booksData = popularBooks.map(book => [
        book.title || 'N/A',
        book.author || 'N/A',
        book.call_number || 'N/A',
        book.borrow_count || 0
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
    const activePatrons = <?php echo json_encode($active_patrons ?? []); ?>;
    const patronsData = activePatrons.map(patron => [
        `${patron.first_name || ''} ${patron.last_name || ''}`.trim() || 'N/A',
        patron.card_number || 'N/A',
        patron.borrow_count || 0
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
    const ctx1 = document.getElementById('circulationChart')?.getContext('2d');
    if (ctx1) {
        const circulationChart = new Chart(ctx1, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($chart_labels ?? []); ?>,
                datasets: [
                    {
                        label: 'Checkouts',
                        data: <?php echo json_encode($checkout_data ?? []); ?>,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    },
                    {
                        label: 'Returns',
                        data: <?php echo json_encode($return_data ?? []); ?>,
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
    }

    // Day of Week Chart (sample data - you'll need to implement the actual data)
    const ctx2 = document.getElementById('dayOfWeekChart')?.getContext('2d');
    if (ctx2) {
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
    }
});
</script>
                    
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- View Program Modal -->
    <div id="viewProgramModal" class="fixed z-50 inset-0 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full sm:p-6">
                <div class="hidden sm:block absolute top-0 right-0 pt-4 pr-4">
                    <button type="button" onclick="closeModal('viewProgramModal')" class="bg-white rounded-md text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        <span class="sr-only">Close</span>
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="viewProgramTitle"></h3>
                        <div class="mt-4 space-y-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Description</dt>
                                <dd class="mt-1 text-sm text-gray-900" id="viewProgramDescription"></dd>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Start Date</dt>
                                    <dd class="mt-1 text-sm text-gray-900" id="viewProgramStartDate"></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">End Date</dt>
                                    <dd class="mt-1 text-sm text-gray-900" id="viewProgramEndDate"></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Status</dt>
                                    <dd class="mt-1">
                                        <span id="viewProgramStatus" class="px-2 py-1 text-xs font-medium rounded-full"></span>
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-gray-500">Age Group</dt>
                                    <dd class="mt-1">
                                        <span id="viewProgramAgeGroup" class="px-2 py-1 text-xs font-medium rounded-full"></span>
                                    </dd>
                                </div>
                                <div class="sm:col-span-2">
                                    <dt class="text-sm font-medium text-gray-500">Target Minutes</dt>
                                    <dd class="mt-1 text-sm text-gray-900" id="viewProgramTargetMinutes"></dd>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="mt-5 sm:mt-6">
                    <button type="button" onclick="closeModal('viewProgramModal')" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-primary-600 text-base font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:text-sm">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Program Modal -->
    <div id="editProgramModal" class="fixed z-50 inset-0 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full sm:p-6">
                <div class="hidden sm:block absolute top-0 right-0 pt-4 pr-4">
                    <button type="button" onclick="closeModal('editProgramModal')" class="bg-white rounded-md text-gray-400 hover:text-gray-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        <span class="sr-only">Close</span>
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                        <h3 class="text-lg leading-6 font-medium text-gray-900">Edit Reading Program</h3>
                        <div class="mt-4">
                            <form id="editProgramForm" method="POST" class="space-y-4">
                                <input type="hidden" name="program_id" id="editProgramId">
                                <div class="grid grid-cols-1 gap-y-4 gap-x-6 sm:grid-cols-6">
                                    <div class="sm:col-span-4">
                                        <label for="editTitle" class="block text-sm font-medium text-gray-700">Program Title <span class="text-red-500">*</span></label>
                                        <input type="text" name="title" id="editTitle" required
                                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                    </div>
                                    <div class="sm:col-span-6">
                                        <label for="editDescription" class="block text-sm font-medium text-gray-700">Description</label>
                                        <textarea name="description" id="editDescription" rows="3"
                                                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"></textarea>
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label for="editStartDate" class="block text-sm font-medium text-gray-700">Start Date <span class="text-red-500">*</span></label>
                                        <input type="date" name="start_date" id="editStartDate" required
                                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label for="editEndDate" class="block text-sm font-medium text-gray-700">End Date <span class="text-red-500">*</span></label>
                                        <input type="date" name="end_date" id="editEndDate" required
                                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label for="editStatus" class="block text-sm font-medium text-gray-700">Status <span class="text-red-500">*</span></label>
                                        <select name="status" id="editStatus" required
                                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                            <option value="upcoming">Upcoming</option>
                                            <option value="active">Active</option>
                                            <option value="completed">Completed</option>
                                            <option value="cancelled">Cancelled</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label for="editAgeGroup" class="block text-sm font-medium text-gray-700">Age Group <span class="text-red-500">*</span></label>
                                        <select name="age_group" id="editAgeGroup" required
                                                class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                            <option value="children">Children (0-12)</option>
                                            <option value="teens">Teens (13-19)</option>
                                            <option value="adults">Adults (20+)</option>
                                            <option value="all">All Ages</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-3">
                                        <label for="editTargetMinutes" class="block text-sm font-medium text-gray-700">Target Minutes (optional)</label>
                                        <input type="number" name="target_minutes" id="editTargetMinutes" min="0" step="1"
                                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm">
                                    </div>
                                </div>
                                <div class="mt-5 sm:mt-6 sm:grid sm:grid-cols-2 sm:gap-3 sm:grid-flow-row-dense">
                                    <button type="submit" name="update_program" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-primary-600 text-base font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:col-start-2 sm:text-sm">
                                        Save Changes
                                    </button>
                                    <button type="button" onclick="closeModal('editProgramModal')" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:mt-0 sm:col-start-1 sm:text-sm">
                                        Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteProgramModal" class="fixed z-50 inset-0 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
            <div class="inline-block align-bottom bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full sm:p-6">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                        <i class="fas fa-exclamation text-red-600"></i>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Delete Program</h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-500">Are you sure you want to delete this program? This action cannot be undone.</p>
                        </div>
                    </div>
                </div>
                <form id="deleteProgramForm" method="POST" class="mt-5 sm:mt-4 sm:flex sm:flex-row-reverse">
                    <input type="hidden" name="program_id" id="deleteProgramId">
                    <button type="submit" name="delete_program" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:ml-3 sm:w-auto sm:text-sm">
                        Delete
                    </button>
                    <button type="button" onclick="closeModal('deleteProgramModal')" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:mt-0 sm:w-auto sm:text-sm">
                        Cancel
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        // Close modal when clicking outside of it
        window.onclick = function(event) {
            if (event.target.classList.contains('fixed') && event.target.classList.contains('inset-0')) {
                document.querySelectorAll('.fixed.inset-0.overflow-y-auto').forEach(modal => {
                    if (!modal.classList.contains('hidden')) {
                        modal.classList.add('hidden');
                        document.body.classList.remove('overflow-hidden');
                    }
                });
            }
        }

        // View Program
        function viewProgram(program) {
            document.getElementById('viewProgramTitle').textContent = program.title;
            document.getElementById('viewProgramDescription').textContent = program.description || 'No description provided';
            document.getElementById('viewProgramStartDate').textContent = formatDate(program.start_date);
            document.getElementById('viewProgramEndDate').textContent = formatDate(program.end_date);
            
            // Set status with appropriate styling
            const statusElement = document.getElementById('viewProgramStatus');
            statusElement.textContent = program.status.charAt(0).toUpperCase() + program.status.slice(1);
            statusElement.className = 'px-2 py-1 text-xs font-medium rounded-full status-' + program.status;
            
            // Set age group with appropriate styling
            const ageGroupElement = document.getElementById('viewProgramAgeGroup');
            ageGroupElement.textContent = program.age_group.charAt(0).toUpperCase() + program.age_group.slice(1);
            ageGroupElement.className = 'px-2 py-1 text-xs font-medium rounded-full age-group-' + program.age_group;
            
            document.getElementById('viewProgramTargetMinutes').textContent = program.target_minutes ? program.target_minutes + ' minutes' : 'Not set';
            
            openModal('viewProgramModal');
        }

        // Edit Program
        function editProgram(program) {
            document.getElementById('editProgramId').value = program.id;
            document.getElementById('editTitle').value = program.title;
            document.getElementById('editDescription').value = program.description || '';
            document.getElementById('editStartDate').value = program.start_date;
            document.getElementById('editEndDate').value = program.end_date;
            document.getElementById('editStatus').value = program.status;
            document.getElementById('editAgeGroup').value = program.age_group;
            document.getElementById('editTargetMinutes').value = program.target_minutes || '';
            
            openModal('editProgramModal');
        }

        // Confirm Delete Program
        function confirmDeleteProgram(programId) {
            document.getElementById('deleteProgramId').value = programId;
            openModal('deleteProgramModal');
        }

        // Format date for display
        function formatDate(dateString) {
            if (!dateString) return 'Not set';
            const options = { year: 'numeric', month: 'long', day: 'numeric' };
            return new Date(dateString).toLocaleDateString(undefined, options);
        }

        // Handle edit form submission with AJAX
        document.getElementById('editProgramForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const form = e.target;
            const formData = new FormData(form);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                // Reload the page to show updated data
                window.location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred while updating the program', 'error');
            });
        });

        // Handle delete form submission with AJAX
        document.getElementById('deleteProgramForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const form = e.target;
            const formData = new FormData(form);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                // Reload the page to show updated data
                window.location.reload();
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred while deleting the program', 'error');
            });
        });
    </script>
</body>
</html>
