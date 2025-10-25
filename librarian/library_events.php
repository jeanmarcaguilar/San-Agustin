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
    $pageTitle = 'Library Events - ' . htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']);
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Check if request is AJAX
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        
        if (isset($_POST['add_event'])) {
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $event_date = trim($_POST['event_date'] ?? '');
            $start_time = trim($_POST['start_time'] ?? '');
            $end_time = trim($_POST['end_time'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $image = '';
            
            // Validate required fields
            if (empty($title) || empty($event_date) || empty($start_time) || empty($end_time)) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Title, event date, start time, and end time are required']);
                    exit();
                } else {
                    $_SESSION['error_message'] = 'Title, event date, start time, and end time are required';
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
            }
            
            // Get the librarian ID for the current user
            $query = "SELECT id FROM librarians WHERE user_id = :user_id LIMIT 1";
            $stmt = $librarianConn->prepare($query);
            $stmt->execute([':user_id' => $_SESSION['user_id']]);
            $librarian = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$librarian) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Librarian record not found']);
                    exit();
                } else {
                    die("Error: Librarian record not found for this user.");
                }
            }
            
            $librarian_id = $librarian['id'];
            
            // Handle file upload
            if (isset($_FILES['event_image']) && $_FILES['event_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../Uploads/events/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                $file_extension = pathinfo($_FILES['event_image']['name'], PATHINFO_EXTENSION);
                $image = uniqid('event_') . '.' . $file_extension;
                $upload_path = $upload_dir . $image;
                
                if (!move_uploaded_file($_FILES['event_image']['tmp_name'], $upload_path)) {
                    $image = '';
                }
            }
            
            // Insert event into database
            $query = "INSERT INTO events (title, description, event_date, start_time, end_time, location, image, created_by) 
                     VALUES (:title, :description, :event_date, :start_time, :end_time, :location, :image, :created_by)";
            $stmt = $librarianConn->prepare($query);
            $stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':event_date' => $event_date,
                ':start_time' => $start_time,
                ':end_time' => $end_time,
                ':location' => $location,
                ':image' => $image,
                ':created_by' => $librarian_id
            ]);
            
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Event added successfully']);
                exit();
            } else {
                $_SESSION['success_message'] = "Event added successfully!";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
            
        } elseif (isset($_POST['update_event'])) {
            $event_id = intval($_POST['event_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $event_date = trim($_POST['event_date'] ?? '');
            $start_time = trim($_POST['start_time'] ?? '');
            $end_time = trim($_POST['end_time'] ?? '');
            $location = trim($_POST['location'] ?? '');
            $current_image = trim($_POST['current_image'] ?? '');
            $image = $current_image;
            
            // Validate required fields
            if (empty($title) || empty($event_date) || empty($start_time) || empty($end_time)) {
                if ($isAjax) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'Title, event date, start time, and end time are required']);
                    exit();
                } else {
                    $_SESSION['error_message'] = 'Title, event date, start time, and end time are required';
                    header("Location: " . $_SERVER['PHP_SELF']);
                    exit();
                }
            }
            
            // Handle file upload if a new image is provided
            if (isset($_FILES['event_image']) && $_FILES['event_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../Uploads/events/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                if ($current_image && file_exists($upload_dir . $current_image)) {
                    unlink($upload_dir . $current_image);
                }
                $file_extension = pathinfo($_FILES['event_image']['name'], PATHINFO_EXTENSION);
                $image = uniqid('event_') . '.' . $file_extension;
                $upload_path = $upload_dir . $image;
                
                if (!move_uploaded_file($_FILES['event_image']['tmp_name'], $upload_path)) {
                    $image = $current_image;
                }
            }
            
            // Update event in database
            $query = "UPDATE events SET 
                     title = :title, description = :description, event_date = :event_date, 
                     start_time = :start_time, end_time = :end_time, location = :location, 
                     image = :image 
                     WHERE event_id = :event_id";
            $stmt = $librarianConn->prepare($query);
            $stmt->execute([
                ':title' => $title,
                ':description' => $description,
                ':event_date' => $event_date,
                ':start_time' => $start_time,
                ':end_time' => $end_time,
                ':location' => $location,
                ':image' => $image,
                ':event_id' => $event_id
            ]);
            
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Event updated successfully']);
                exit();
            } else {
                $_SESSION['success_message'] = "Event updated successfully!";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
            
        } elseif (isset($_POST['delete_event'])) {
            $event_id = intval($_POST['event_id'] ?? 0);
            
            // Get event image to delete
            $query = "SELECT image FROM events WHERE event_id = :event_id";
            $stmt = $librarianConn->prepare($query);
            $stmt->bindParam(':event_id', $event_id, PDO::PARAM_INT);
            $stmt->execute();
            $event = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Delete event
            $query = "DELETE FROM events WHERE event_id = :event_id";
            $stmt = $librarianConn->prepare($query);
            $stmt->bindParam(':event_id', $event_id, PDO::PARAM_INT);
            $stmt->execute();
            
            // Delete image file
            if (!empty($event['image']) && file_exists('../Uploads/events/' . $event['image'])) {
                unlink('../Uploads/events/' . $event['image']);
            }
            
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Event deleted successfully']);
                exit();
            } else {
                $_SESSION['success_message'] = "Event deleted successfully!";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        }
    }
    
    // Get all events
    $events = [];
    $query = "SELECT e.*, CONCAT(l.first_name, ' ', l.last_name) as created_by_name 
             FROM events e 
             JOIN librarians l ON e.created_by = l.id 
             ORDER BY e.event_date DESC, e.start_time DESC";
    $stmt = $librarianConn->prepare($query);
    $stmt->execute();
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get upcoming events for sidebar
    $upcoming_events = [];
    $query = "SELECT event_id, title, event_date, start_time 
             FROM events 
             WHERE event_date >= CURDATE() 
             ORDER BY event_date ASC, start_time ASC 
             LIMIT 5";
    $stmt = $librarianConn->prepare($query);
    $stmt->execute();
    $upcoming_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
    exit();
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📅 Librarian Portal – Library Events</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
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
        .modal {
            transition: opacity 0.3s ease, visibility 0.3s ease;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        .modal.modal-open {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        .modal.modal-hidden {
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" data-submenu="catalog-submenu">
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" data-submenu="patrons-submenu">
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
                        <a href="fines.php" class="flex items-center p-2 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-money-bill-wave w-5"></i>
                            <span class="ml-3 sidebar-text">Fines & Payments</span>
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" data-submenu="reports-submenu">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Analytics</span>
                        <i class="fas fa-chevron-down ml-auto text-xs chevron-icon"></i>
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
                    <?php if (count($upcoming_events) > 0): ?>
                        <?php foreach ($upcoming_events as $event): 
                            $event_date = new DateTime($event['event_date']);
                            $start_time = new DateTime($event['start_time']);
                        ?>
                            <div class="flex items-start">
                                <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">
                                    <?= $event_date->format('d') ?>
                                </div>
                                <div class="ml-2">
                                    <p class="text-xs font-medium text-white"><?= htmlspecialchars($event['title']) ?></p>
                                    <p class="text-xs text-secondary-300"><?= $start_time->format('g:i A') ?> - Library Hall</p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p class="text-xs text-secondary-300">No upcoming events.</p>
                    <?php endif; ?>
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
                <h1 class="text-xl font-bold">Library Events</h1>
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
                    <div id="user-menu" class="absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50 hidden">
                        <div class="px-4 py-2 border-b border-gray-100">
                            <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']); ?></p>
                            <p class="text-xs text-gray-500"><?php echo htmlspecialchars($userData['librarian_id']); ?></p>
                        </div>
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-user-circle mr-2"></i> Profile
                        </a>
                        <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-cog mr-2"></i> Settings
                        </a>
                        <button onclick="toggleSignoutModal()" class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                            <i class="fas fa-sign-out-alt mr-2"></i> Sign out
                        </button>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-5 overflow-y-auto bg-gray-50">
            <div class="max-w-6xl mx-auto">
                <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm mb-6">
                    <div class="flex justify-between items-center mb-6">
                        <h1 class="text-2xl font-bold text-gray-800">Library Events</h1>
                        <div class="flex space-x-3">
                            <button onclick="toggleAddEventModal()" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors flex items-center">
                                <i class="fas fa-plus mr-2"></i> Add Event
                            </button>
                            <button onclick="exportToPDF()" class="bg-secondary-600 text-white px-4 py-2 rounded-lg hover:bg-secondary-700 transition-colors flex items-center">
                                <i class="fas fa-file-pdf mr-2"></i> Export to PDF
                            </button>
                        </div>
                    </div>
                    
                    <!-- Events Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <?php if (count($events) > 0): ?>
                            <?php foreach ($events as $event): 
                                $event_date = new DateTime($event['event_date']);
                                $start_time = new DateTime($event['start_time']);
                                $end_time = new DateTime($event['end_time']);
                                // Default status class
                                $status_class = 'bg-blue-100 text-blue-800';
                            ?>
                                <div class="dashboard-card rounded-lg shadow-md overflow-hidden">
                                    <?php if (!empty($event['image'])): ?>
                                        <img src="../Uploads/events/<?= htmlspecialchars($event['image']) ?>" alt="<?= htmlspecialchars($event['title']) ?>" class="w-full h-48 object-cover">
                                    <?php else: ?>
                                        <div class="bg-gray-200 h-48 flex items-center justify-center text-gray-400">
                                            <i class="fas fa-calendar-alt text-4xl"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="p-4">
                                        <div class="flex justify-between items-start">
                                            <h3 class="text-lg font-semibold text-gray-800 mb-1"><?= htmlspecialchars($event['title']) ?></h3>
                                        </div>
                                        
                                        <p class="text-sm text-gray-600 mb-3 line-clamp-2"><?= htmlspecialchars($event['description']) ?></p>
                                        
                                        <div class="flex items-center text-sm text-gray-500 mb-2">
                                            <i class="far fa-calendar-alt mr-2"></i>
                                            <span><?= $event_date->format('F j, Y') ?></span>
                                        </div>
                                        
                                        <div class="flex items-center text-sm text-gray-500 mb-2">
                                            <i class="far fa-clock mr-2"></i>
                                            <span><?= $start_time->format('g:i A') ?> - <?= $end_time->format('g:i A') ?></span>
                                        </div>
                                        
                                        <?php if (!empty($event['location'])): ?>
                                            <div class="flex items-center text-sm text-gray-500 mb-3">
                                                <i class="fas fa-map-marker-alt mr-2"></i>
                                                <span><?= htmlspecialchars($event['location']) ?></span>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="flex justify-between items-center mt-4 pt-3 border-t border-gray-100">
                                            <span class="text-sm text-gray-500">
                                                Created by <?= !empty($event['created_by_name']) ? htmlspecialchars($event['created_by_name']) : 'System' ?>
                                            </span>
                                            <div class="flex space-x-2">
                                                <button onclick='toggleEditEventModal(<?= htmlspecialchars(json_encode($event)) ?>)' 
                                                        class="text-primary-600 hover:text-primary-800">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button onclick="toggleDeleteEventModal(<?= $event['event_id'] ?>, '<?= addslashes($event['title']) ?>')" 
                                                        class="text-red-600 hover:text-red-800">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="col-span-3 text-center py-12">
                                <i class="far fa-calendar-plus text-4xl text-gray-400 mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-700">No events found</h3>
                                <p class="text-gray-500 mt-1">Get started by adding your first event.</p>
                                <button onclick="toggleAddEventModal()" 
                                        class="mt-4 bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors inline-flex items-center">
                                    <i class="fas fa-plus mr-2"></i> Add Event
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Sign Out Confirmation Modal -->
    <div id="signoutModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 modal modal-hidden">
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

    <!-- Add Event Modal -->
    <div id="addEventModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 modal modal-hidden">
        <div class="bg-white rounded-xl p-6 w-11/12 md:w-2/3 lg:w-1/2 shadow-lg dashboard-card">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-semibold text-gray-800">Add New Event</h3>
                <button onclick="toggleAddEventModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="addEventForm" method="POST" enctype="multipart/form-data" class="mt-4 space-y-4">
                <input type="hidden" name="add_event" value="1">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Event Title <span class="text-red-500">*</span></label>
                        <input type="text" name="title" required 
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent">
                    </div>
                    
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea name="description" rows="3"
                                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent"></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Event Date <span class="text-red-500">*</span></label>
                        <input type="date" name="event_date" required min="<?= date('Y-m-d') ?>"
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Start Time <span class="text-red-500">*</span></label>
                            <input type="time" name="start_time" required
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">End Time <span class="text-red-500">*</span></label>
                            <input type="time" name="end_time" required
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Location</label>
                        <input type="text" name="location"
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent">
                    </div>
                    
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Event Image</label>
                        <input type="file" name="event_image" accept="image/*"
                               class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" onclick="toggleAddEventModal()"
                            class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Cancel
                    </button>
                    <button type="submit"
                            class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Add Event
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Event Modal -->
    <div id="editEventModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 modal modal-hidden">
        <div class="bg-white rounded-xl p-6 w-11/12 md:w-2/3 lg:w-1/2 shadow-lg dashboard-card">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-semibold text-gray-800">Edit Event</h3>
                <button onclick="toggleEditEventModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="editEventForm" method="POST" enctype="multipart/form-data" class="mt-4 space-y-4">
                <input type="hidden" name="update_event" value="1">
                <input type="hidden" name="event_id" id="edit_event_id">
                <input type="hidden" name="current_image" id="edit_current_image">
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Event Title <span class="text-red-500">*</span></label>
                        <input type="text" name="title" id="edit_title" required 
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent">
                    </div>
                    
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Description</label>
                        <textarea name="description" id="edit_description" rows="3"
                                  class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent"></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Event Date <span class="text-red-500">*</span></label>
                        <input type="date" name="event_date" id="edit_event_date" required
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Start Time <span class="text-red-500">*</span></label>
                            <input type="time" name="start_time" id="edit_start_time" required
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">End Time <span class="text-red-500">*</span></label>
                            <input type="time" name="end_time" id="edit_end_time" required
                                   class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent">
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Location</label>
                        <input type="text" name="location" id="edit_location"
                               class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-transparent">
                    </div>
                    
                    <div class="col-span-2">
                        <label class="block text-sm font-medium text-gray-700">Event Image</label>
                        <input type="file" name="event_image" accept="image/*"
                               class="mt-1 block w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-md file:border-0 file:text-sm file:font-semibold file:bg-primary-50 file:text-primary-700 hover:file:bg-primary-100">
                        <p id="current_image_name" class="mt-1 text-xs text-gray-500"></p>
                    </div>
                    
                    <div class="col-span-2">
                        <div id="current_image_container" class="mt-2"></div>
                    </div>
                </div>
                
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" onclick="toggleEditEventModal()"
                            class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Cancel
                    </button>
                    <button type="submit"
                            class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Update Event
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteEventModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center z-50 modal modal-hidden">
        <div class="bg-white rounded-xl p-6 w-96 shadow-lg dashboard-card">
            <div class="flex justify-between items-center border-b pb-3">
                <h3 class="text-lg font-semibold text-gray-800">Confirm Deletion</h3>
                <button onclick="toggleDeleteEventModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="mt-4">
                <p class="text-gray-700">Are you sure you want to delete the event "<span id="eventToDelete"></span>"?</p>
                <p class="text-sm text-red-600 mt-1">This action cannot be undone.</p>
                
                <div class="mt-6 flex justify-end space-x-3">
                    <button type="button" onclick="toggleDeleteEventModal()"
                            class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Cancel
                    </button>
                    <form id="deleteEventForm" method="POST" class="inline">
                        <input type="hidden" name="delete_event" value="1">
                        <input type="hidden" name="event_id" id="delete_event_id">
                        <button type="submit"
                                class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                            Delete Event
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="toastContainer" class="fixed top-4 right-4 z-[10000] space-y-2"></div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('DOM fully loaded. Initializing scripts...');

            // Toast notifications
            function showToast(message, type = 'info') {
                const toastContainer = document.getElementById('toastContainer');
                if (!toastContainer) {
                    console.error('Toast container not found');
                    return;
                }
                
                const toast = document.createElement('div');
                const icons = {
                    success: 'check-circle',
                    error: 'times-circle',
                    warning: 'exclamation-circle',
                    info: 'info-circle'
                };
                
                toast.className = `flex items-center p-4 mb-4 text-${type === 'success' ? 'green' : type === 'error' ? 'red' : type === 'warning' ? 'yellow' : 'blue'}-700 bg-${type === 'success' ? 'green' : type === 'error' ? 'red' : type === 'warning' ? 'yellow' : 'blue'}-100 rounded-lg`;
                toast.role = 'alert';
                toast.innerHTML = `
                    <i class="fas fa-${icons[type] || 'info-circle'} mr-2"></i>
                    <span>${message}</span>
                    <button type="button" class="ml-auto -mx-1.5 -my-1.5 text-${type === 'success' ? 'green' : type === 'error' ? 'red' : type === 'warning' ? 'yellow' : 'blue'}-500 rounded-lg p-1.5 hover:bg-${type === 'success' ? 'green' : type === 'error' ? 'red' : type === 'warning' ? 'yellow' : 'blue'}-200 inline-flex items-center justify-center h-8 w-8" onclick="this.parentElement.remove()">
                        <i class="fas fa-times"></i>
                    </button>
                `;
                
                toastContainer.appendChild(toast);
                
                // Auto remove toast after 5 seconds
                setTimeout(() => {
                    toast.remove();
                }, 5000);
            }

            // Toggle modals
            function toggleModal(modalId, show = null) {
                const modal = document.getElementById(modalId);
                if (!modal) {
                    console.error(`Modal with ID ${modalId} not found`);
                    return;
                }
                
                if (show === true || (show === null && modal.classList.contains('modal-hidden'))) {
                    modal.classList.remove('modal-hidden');
                    modal.classList.add('modal-open');
                    document.body.style.overflow = 'hidden';
                } else {
                    modal.classList.add('modal-hidden');
                    modal.classList.remove('modal-open');
                    document.body.style.overflow = 'auto';
                }
            }
            
            // Add Event Modal
            window.toggleAddEventModal = function() {
                toggleModal('addEventModal');
                const form = document.getElementById('addEventForm');
                if (form) form.reset();
            };
            
            // Edit Event Modal
            window.toggleEditEventModal = function(event = null) {
                const modal = document.getElementById('editEventModal');
                if (!modal) return;
                
                if (event) {
                    // If event data is provided, fill the form
                    const form = document.getElementById('editEventForm');
                    if (!form) return;
                    
                    // Set form values from event data
                    form.querySelector('[name="event_id"]').value = event.event_id || '';
                    form.querySelector('[name="title"]').value = event.title || '';
                    form.querySelector('[name="description"]').value = event.description || '';
                    form.querySelector('[name="event_date"]').value = event.event_date || '';
                    form.querySelector('[name="start_time"]').value = event.start_time || '';
                    form.querySelector('[name="end_time"]').value = event.end_time || '';
                    form.querySelector('[name="location"]').value = event.location || '';
                    form.querySelector('[name="current_image"]').value = event.image || '';
                    
                    // Update image preview
                    const imageContainer = document.getElementById('current_image_container');
                    const imageName = document.getElementById('current_image_name');
                    if (event.image) {
                        imageContainer.innerHTML = `
                            <img src="../Uploads/events/${event.image}" alt="Current event image" class="mt-2 h-32 object-cover rounded-lg">
                        `;
                        imageName.textContent = event.image;
                    } else {
                        imageContainer.innerHTML = '<p class="text-sm text-gray-500">No image uploaded</p>';
                        imageName.textContent = '';
                    }
                }
                
                toggleModal('editEventModal');
            };
            
            // Delete Event Modal
            window.toggleDeleteEventModal = function(eventId = null, eventTitle = null) {
                const modal = document.getElementById('deleteEventModal');
                if (!modal) return;
                
                if (eventId && eventTitle) {
                    document.getElementById('eventToDelete').textContent = eventTitle;
                    document.getElementById('delete_event_id').value = eventId;
                }
                
                toggleModal('deleteEventModal');
            };
            
            // Sidebar toggle for mobile
            function toggleSidebar() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('overlay');
                if (!sidebar || !overlay) return;
                
                sidebar.classList.toggle('sidebar-open');
                overlay.classList.toggle('overlay-open');
                document.body.style.overflow = sidebar.classList.contains('sidebar-open') ? 'hidden' : 'auto';
            }
            
            function closeSidebar() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('overlay');
                if (!sidebar || !overlay) return;
                
                sidebar.classList.remove('sidebar-open');
                overlay.classList.remove('overlay-open');
                document.body.style.overflow = 'auto';
            }
            
            // User menu toggle
            function toggleUserMenu() {
                const userMenu = document.getElementById('user-menu');
                if (!userMenu) return;
                userMenu.classList.toggle('user-menu-open');
            }
            
            // Notifications toggle
            function toggleNotifications() {
                const notificationPanel = document.getElementById('notification-panel');
                if (!notificationPanel) return;
                notificationPanel.classList.toggle('open');
                
                if (notificationPanel.classList.contains('open')) {
                    const notificationDot = document.querySelector('.notification-dot');
                    if (notificationDot) notificationDot.style.display = 'none';
                }
            }
            
            // Submenu toggle function
            function toggleSubmenu(submenuId, element) {
                if (!submenuId || !element) return;
                
                const submenu = document.getElementById(submenuId);
                const navItem = element.closest('.nav-item');
                const chevron = element.querySelector('.chevron-icon');
                
                if (!submenu || !navItem) return;
                
                // Close other open submenus
                document.querySelectorAll('.submenu.open').forEach(otherSubmenu => {
                    if (otherSubmenu.id !== submenuId) {
                        otherSubmenu.classList.remove('open');
                        const otherNavItem = otherSubmenu.closest('li').querySelector('.nav-item');
                        if (otherNavItem) {
                            otherNavItem.classList.remove('open');
                            const otherChevron = otherNavItem.querySelector('.chevron-icon');
                            if (otherChevron) otherChevron.classList.remove('rotate-180');
                        }
                    }
                });
                
                // Toggle current submenu
                submenu.classList.toggle('open');
                navItem.classList.toggle('open');
                if (chevron) {
                    chevron.classList.toggle('rotate-180');
                }
            }
            
            // Sidebar collapse toggle
            function toggleSidebarCollapse() {
                const sidebar = document.getElementById('sidebar');
                const collapseIcon = document.getElementById('collapse-icon');
                if (!sidebar || !collapseIcon) return;
                
                sidebar.classList.toggle('collapsed');
                
                if (sidebar.classList.contains('collapsed')) {
                    collapseIcon.classList.remove('fa-chevron-left');
                    collapseIcon.classList.add('fa-chevron-right');
                } else {
                    collapseIcon.classList.remove('fa-chevron-right');
                    collapseIcon.classList.add('fa-chevron-left');
                }
            }

            // Add Event Modal toggle
            function toggleAddEventModal() {
                const modal = document.getElementById('addEventModal');
                if (!modal) {
                    console.error('Add Event modal not found');
                    showToast('Error toggling Add Event modal', 'error');
                    return;
                }
                modal.classList.toggle('modal-hidden');
                modal.classList.toggle('modal-open');
                document.body.style.overflow = modal.classList.contains('modal-open') ? 'hidden' : 'auto';
                showToast(modal.classList.contains('modal-open') ? 'Add Event modal opened' : 'Add Event modal closed', 'info');
            }

            // Edit Event Modal toggle
            function toggleEditEventModal(event = null) {
                const modal = document.getElementById('editEventModal');
                if (!modal) {
                    console.error('Edit Event modal not found');
                    showToast('Error toggling Edit Event modal', 'error');
                    return;
                }
                if (event) {
                    document.getElementById('edit_event_id').value = event.event_id;
                    document.getElementById('edit_title').value = event.title;
                    document.getElementById('edit_description').value = event.description || '';
                    document.getElementById('edit_event_date').value = event.event_date;
                    document.getElementById('edit_start_time').value = event.start_time;
                    document.getElementById('edit_end_time').value = event.end_time;
                    document.getElementById('edit_location').value = event.location || '';
                    
                    const currentImage = event.image || '';
                    document.getElementById('edit_current_image').value = currentImage;
                    const currentImageContainer = document.getElementById('current_image_container');
                    const currentImageName = document.getElementById('current_image_name');
                    
                    if (currentImage) {
                        currentImageContainer.innerHTML = `
                            <p class="text-sm font-medium text-gray-700 mb-1">Current Image:</p>
                            <img src="../Uploads/events/${currentImage}" alt="Current event image" class="h-32 w-full object-cover rounded-md">
                        `;
                        currentImageName.textContent = currentImage;
                    } else {
                        currentImageContainer.innerHTML = '<p class="text-sm text-gray-500">No image uploaded</p>';
                        currentImageName.textContent = '';
                    }
                }
                modal.classList.toggle('modal-hidden');
                modal.classList.toggle('modal-open');
                document.body.style.overflow = modal.classList.contains('modal-open') ? 'hidden' : 'auto';
                showToast(modal.classList.contains('modal-open') ? 'Edit Event modal opened' : 'Edit Event modal closed', 'info');
            }

            // Delete Event Modal toggle
            function toggleDeleteEventModal(eventId = null, eventTitle = null) {
                const modal = document.getElementById('deleteEventModal');
                if (!modal) {
                    console.error('Delete Event modal not found');
                    showToast('Error toggling Delete Event modal', 'error');
                    return;
                }
                if (eventId && eventTitle) {
                    document.getElementById('eventToDelete').textContent = eventTitle;
                    document.getElementById('delete_event_id').value = eventId;
                }
                modal.classList.toggle('modal-hidden');
                modal.classList.toggle('modal-open');
                document.body.style.overflow = modal.classList.contains('modal-open') ? 'hidden' : 'auto';
                showToast(modal.classList.contains('modal-open') ? 'Delete Event modal opened' : 'Delete Event modal closed', 'info');
            }

            // Handle form submissions with AJAX
            function handleFormSubmit(form, successMessage) {
                if (!form) return;
                
                const formData = new FormData(form);
                const submitButton = form.querySelector('button[type="submit"]');
                const originalButtonText = submitButton ? submitButton.innerHTML : '';
                
                // Show loading state
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
                }
                
                fetch(form.action || window.location.href, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        showToast(successMessage || data.message || 'Operation completed successfully', 'success');
                        // Close the modal and refresh the page after a short delay
                        const modal = form.closest('.modal');
                        if (modal) {
                            toggleModal(modal.id);
                        }
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        throw new Error(data.message || 'An error occurred');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast(error.message || 'An error occurred. Please try again.', 'error');
                })
                .finally(() => {
                    // Restore button state
                    if (submitButton) {
                        submitButton.disabled = false;
                        submitButton.innerHTML = originalButtonText;
                    }
                });
            }
            
            // Attach form submission handlers
            document.addEventListener('submit', function(e) {
                if (e.target.matches('#addEventForm, #editEventForm, #deleteEventForm')) {
                    e.preventDefault();
                    const successMessage = {
                        'addEventForm': 'Event added successfully!',
                        'editEventForm': 'Event updated successfully!',
                        'deleteEventForm': 'Event deleted successfully!'
                    }[e.target.id];
                    handleFormSubmit(e.target, successMessage);
                }
            });
            
            // Attach event listeners to edit and delete buttons in event cards
            document.addEventListener('click', function(e) {
                // Edit button
                if (e.target.closest('.edit-event-btn')) {
                    const button = e.target.closest('.edit-event-btn');
                    const eventData = {
                        event_id: button.dataset.id,
                        title: button.dataset.title,
                        description: button.dataset.description,
                        event_date: button.dataset.date,
                        start_time: button.dataset.startTime,
                        end_time: button.dataset.endTime,
                        location: button.dataset.location,
                        image: button.dataset.image || ''
                    };
                    toggleEditEventModal(eventData);
                }
                
                // Delete button
                if (e.target.closest('.delete-event-btn')) {
                    const button = e.target.closest('.delete-event-btn');
                    toggleDeleteEventModal(button.dataset.id, button.dataset.title);
                }
            });
            
            // Export to PDF
            window.exportToPDF = function() {
                try {
                    const { jsPDF } = window.jspdf;
                    if (!jsPDF) {
                        throw new Error('PDF library not loaded');
                    }
                    
                    const doc = new jsPDF();
                    
                    // Add title
                    doc.setFontSize(20);
                    doc.text('Library Events Report', 105, 15, { align: 'center' });
                    
                    // Add school info
                    doc.setFontSize(12);
                    doc.text('San Agustin Elementary School', 105, 25, { align: 'center' });
                    doc.text('Library Management System', 105, 32, { align: 'center' });
                    
                    // Add date
                    doc.setFontSize(10);
                    doc.text(`Generated on: ${new Date().toLocaleString()}`, 105, 42, { align: 'center' });
                    
                    // Add events
                    let y = 60;
                    const events = <?php echo json_encode($events); ?>;
                    
                    if (events.length === 0) {
                        doc.setFontSize(14);
                        doc.text('No events found', 105, y, { align: 'center' });
                    } else {
                        doc.setFontSize(12);
                        
                        events.forEach((event, index) => {
                            // Add page break if needed
                            if (y > 250) {
                                doc.addPage();
                                y = 20;
                            }
                            
                            const eventDate = new Date(event.event_date).toLocaleDateString('en-US', { 
                                year: 'numeric', 
                                month: 'long', 
                                day: 'numeric' 
                            });
                            
                            const startTime = new Date(`1970-01-01T${event.start_time}`).toLocaleTimeString('en-US', { 
                                hour: 'numeric', 
                                minute: '2-digit',
                                hour12: true 
                            });
                            
                            const endTime = new Date(`1970-01-01T${event.end_time}`).toLocaleTimeString('en-US', { 
                                hour: 'numeric', 
                                minute: '2-digit',
                                hour12: true 
                            });
                            
                            // Add event title
                            doc.setFont('helvetica', 'bold');
                            doc.text(`Event ${index + 1}: ${event.title}`, 20, y);
                            y += 8;
                            
                            // Add event details
                            doc.setFont('helvetica', 'normal');
                            doc.text(`Date: ${eventDate}`, 20, y);
                            y += 7;
                            
                            doc.text(`Time: ${startTime} - ${endTime}`, 20, y);
                            y += 7;
                            
                            if (event.location) {
                                doc.text(`Location: ${event.location}`, 20, y);
                                y += 7;
                            }
                            
                            if (event.description) {
                                const splitDescription = doc.splitTextToSize(`Description: ${event.description}`, 170);
                                doc.text(splitDescription, 20, y);
                                y += splitDescription.length * 7;
                            }
                            
                            // Add a small separator between events
                            y += 5;
                            doc.line(20, y, 190, y);
                            y += 10;
                        });
                    }
                    
                    // Save the PDF
                    doc.save(`library-events-${new Date().toISOString().split('T')[0]}.pdf`);
                    showToast('PDF exported successfully!', 'success');
                    
                } catch (error) {
                    console.error('Error exporting to PDF:', error);
                    showToast('Failed to export PDF: ' + (error.message || 'Unknown error'), 'error');
                }
            };
            
            // Attach submenu toggle functionality
            function initSubmenuToggles() {
                const submenuToggles = document.querySelectorAll('[data-submenu]');
                submenuToggles.forEach(toggle => {
                    toggle.addEventListener('click', function(e) {
                        e.preventDefault();
                        const submenuId = this.getAttribute('data-submenu');
                        toggleSubmenu(submenuId, this);
                    });
                });
            }
            
            // Initialize submenu toggles
            initSubmenuToggles();
            
            // Toggle sign out modal
            window.toggleSignoutModal = function() {
                const modal = document.getElementById('signoutModal');
                if (!modal) return;
                
                // Toggle modal visibility
                modal.classList.toggle('modal-hidden');
                
                // Toggle body scroll
                document.body.style.overflow = modal.classList.contains('modal-hidden') ? 'auto' : 'hidden';
                
                // Close user menu when opening sign out modal
                const userMenu = document.getElementById('user-menu');
                if (userMenu) userMenu.classList.add('hidden');
            };
            
        });
        
        // Close sign out modal when clicking outside
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('signoutModal');
            const modalContent = modal ? modal.querySelector('> div') : null;
            
            if (modal && !modal.classList.contains('modal-hidden') && 
                !modalContent.contains(event.target) && 
                event.target !== document.querySelector('[onclick*="toggleSignoutModal"]')) {
                toggleSignoutModal();
            }
        });
        
        // Close sign out modal with Escape key
        document.addEventListener('keydown', function(event) {
            const modal = document.getElementById('signoutModal');
            if (event.key === 'Escape' && modal && !modal.classList.contains('modal-hidden')) {
                toggleSignoutModal();
            }
        });
        
        // Initialize page when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Show welcome message
            showToast('Welcome to Library Events!', 'success');
            console.log('Library Events page loaded');
            
            // Show any server-side messages
            <?php if (isset($_SESSION['success_message'])): ?>
                showToast('<?php echo addslashes($_SESSION['success_message']); ?>', 'success');
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                showToast('<?php echo addslashes($_SESSION['error_message']); ?>', 'error');
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>
            
            // Initialize any date pickers or other UI components
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                if (!input.value) {
                    const today = new Date().toISOString().split('T')[0];
                    input.min = today;
                }
            });
        });
</body>
</html>