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
    
    // Initialize activeCheckouts as an empty array
    $activeCheckouts = [];
    
    // Fetch active checkouts
    try {
        $checkoutsQuery = "SELECT bl.*, b.title as book_title, p.first_name, p.last_name, p.patron_id as patron_number
                         FROM book_loans bl
                         JOIN books b ON bl.book_id = b.id
                         JOIN patrons p ON bl.patron_id = p.patron_id
                         WHERE bl.return_date IS NULL
                         ORDER BY bl.due_date ASC";
        $stmt = $librarianConn->prepare($checkoutsQuery);
        $stmt->execute();
        $activeCheckouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Log error but don't stop execution
        error_log("Error fetching active checkouts: " . $e->getMessage());
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
    $pageTitle = 'Circulation - ' . htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']);
    
    // Handle check-in/check-out actions
    $message = '';
    $messageType = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';
        $bookId = $_POST['book_id'] ?? 0;
        $patronId = $_POST['patron_id'] ?? '';
        
        if ($action === 'checkout' && !empty($patronId) && $bookId > 0) {
            // Check if book is available
            $stmt = $librarianConn->prepare("SELECT * FROM books WHERE id = :book_id AND available > 0");
            $stmt->bindParam(':book_id', $bookId, PDO::PARAM_INT);
            $stmt->execute();
            $book = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($book) {
                // Get or create patron record for the student
                $patronStmt = $librarianConn->prepare("SELECT patron_id FROM patrons WHERE user_id = :user_id");
                $patronStmt->execute([':user_id' => $patronId]);
                $patron = $patronStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$patron) {
                    // Create patron record from student data
                    try {
                        $studentConn = $database->getConnection('student');
                        $studentStmt = $studentConn->prepare("SELECT s.*, u.email FROM students s LEFT JOIN login_db.users u ON s.user_id = u.id WHERE s.user_id = :user_id");
                        $studentStmt->execute([':user_id' => $patronId]);
                        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($student) {
                            $insertPatron = $librarianConn->prepare("INSERT INTO patrons (user_id, first_name, last_name, email, membership_date, status) 
                                                                     VALUES (:user_id, :first_name, :last_name, :email, CURDATE(), 'active')");
                            $insertPatron->execute([
                                ':user_id' => $patronId,
                                ':first_name' => $student['first_name'],
                                ':last_name' => $student['last_name'],
                                ':email' => $student['email'] ?? ''
                            ]);
                            $actualPatronId = $librarianConn->lastInsertId();
                        } else {
                            throw new Exception("Student not found.");
                        }
                    } catch (Exception $e) {
                        $message = 'Error creating patron record: ' . $e->getMessage();
                        $messageType = 'error';
                        $patron = null;
                    }
                } else {
                    $actualPatronId = $patron['patron_id'];
                }
                
                if (isset($actualPatronId)) {
                    // Calculate due date (14 days from now)
                    $dueDate = date('Y-m-d', strtotime('+14 days'));
                    
                    // Create checkout record in book_loans table
                    $stmt = $librarianConn->prepare("INSERT INTO book_loans (book_id, patron_id, librarian_id, checkout_date, due_date, status) 
                                                  VALUES (:book_id, :patron_id, :librarian_id, NOW(), :due_date, 'checked_out')");
                    $stmt->execute([
                        ':book_id' => $bookId,
                        ':patron_id' => $actualPatronId,
                        ':librarian_id' => $librarianData['id'],
                        ':due_date' => $dueDate
                    ]);
                    
                    // Also create record in transactions table for student visibility
                    $transStmt = $librarianConn->prepare("INSERT INTO transactions (book_id, patron_id, checkout_date, due_date, status) 
                                                          VALUES (:book_id, :patron_id, NOW(), :due_date, 'checked_out')");
                    $transStmt->execute([
                        ':book_id' => $bookId,
                        ':patron_id' => $actualPatronId,
                        ':due_date' => $dueDate
                    ]);
                    
                    // Update book availability
                    $updateStmt = $librarianConn->prepare("UPDATE books SET available = available - 1 WHERE id = :book_id");
                    $updateStmt->bindParam(':book_id', $bookId, PDO::PARAM_INT);
                    $updateStmt->execute();
                    
                    $message = 'Book checked out successfully. Due date: ' . $dueDate;
                    $messageType = 'success';
                }
            } else {
                $message = 'Book is not available for checkout.';
                $messageType = 'error';
            }
        } elseif ($action === 'checkin' && !empty($bookId)) {
            // Find the book by ID, ISBN, or title
            $bookSearchStmt = $librarianConn->prepare("SELECT id FROM books WHERE id = :book_id OR isbn = :isbn OR title LIKE :title LIMIT 1");
            $bookSearchStmt->execute([
                ':book_id' => is_numeric($bookId) ? $bookId : 0,
                ':isbn' => $bookId,
                ':title' => "%$bookId%"
            ]);
            $foundBook = $bookSearchStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($foundBook) {
                $actualBookId = $foundBook['id'];
                
                // Find the active checkout
                $stmt = $librarianConn->prepare("SELECT * FROM book_loans WHERE book_id = :book_id AND return_date IS NULL AND status = 'checked_out' ORDER BY checkout_date DESC LIMIT 1");
                $stmt->bindParam(':book_id', $actualBookId, PDO::PARAM_INT);
                $stmt->execute();
                $checkout = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $checkout = null;
            }
            
            if ($checkout) {
                // Update book_loans record
                $updateStmt = $librarianConn->prepare("UPDATE book_loans SET return_date = NOW(), status = 'returned' WHERE loan_id = :loan_id");
                $updateStmt->execute([
                    ':loan_id' => $checkout['loan_id']
                ]);
                
                // Also update transactions table for student visibility
                $updateTransStmt = $librarianConn->prepare("UPDATE transactions SET return_date = NOW(), status = 'returned' 
                                                            WHERE book_id = :book_id AND patron_id = (SELECT user_id FROM patrons WHERE patron_id = :patron_id) 
                                                            AND return_date IS NULL AND status = 'checked_out' 
                                                            ORDER BY checkout_date DESC LIMIT 1");
                $updateTransStmt->execute([
                    ':book_id' => $actualBookId,
                    ':patron_id' => $checkout['patron_id']
                ]);
                
                // Update book availability
                $updateBookStmt = $librarianConn->prepare("UPDATE books SET available = available + 1 WHERE id = :book_id");
                $updateBookStmt->bindParam(':book_id', $actualBookId, PDO::PARAM_INT);
                $updateBookStmt->execute();
                
                $message = 'Book checked in successfully.';
                $messageType = 'success';
            } else {
                if (isset($foundBook)) {
                    $message = 'No active checkout found for this book.';
                } else {
                    $message = 'Book not found. Please check the ID, ISBN, or title.';
                }
                $messageType = 'error';
            }
        }
    }
    
    // Get active checkouts
    $activeCheckouts = [];
    $checkoutsQuery = "SELECT bl.*, b.title, b.isbn, p.first_name, p.last_name, p.email as card_number 
                      FROM book_loans bl 
                      JOIN books b ON bl.book_id = b.id 
                      JOIN patrons p ON bl.patron_id = p.patron_id 
                      WHERE bl.return_date IS NULL 
                      AND bl.status = 'checked_out'
                      ORDER BY bl.due_date ASC";
    $result = $librarianConn->query($checkoutsQuery);
    if ($result) {
        $activeCheckouts = $result->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get available books for checkout
    $availableBooks = [];
    $booksQuery = "SELECT * FROM books WHERE available > 0 ORDER BY title";
    $result = $librarianConn->query($booksQuery);
    if ($result) {
        $availableBooks = $result->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get all students from student database for patron selection
    $students = [];
    try {
        $studentConn = $database->getConnection('student');
        $studentsQuery = "SELECT s.*, u.email 
                         FROM students s 
                         LEFT JOIN login_db.users u ON s.user_id = u.id 
                         ORDER BY s.last_name, s.first_name";
        $result = $studentConn->query($studentsQuery);
        if ($result) {
            $students = $result->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("Error fetching students: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    $error = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📚 Librarian Portal – Circulation</title>
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
            display: flex;
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
        /* Fixed header and main content styles */
        .main-container {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 0;
        }
        header {
            position: sticky;
            top: 0;
            z-index: 50;
            width: 100%;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        main {
            flex: 1;
            overflow-y: auto;
            width: 100%;
            padding: 1rem;
        }
        @media (min-width: 768px) {
            main {
                padding: 1.5rem 2rem;
            }
            .main-content {
                max-width: 1280px;
                margin: 0 auto;
                width: 100%;
            }
        }
    </style>
</head>
<body>
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
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text transition-transform"></i>
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
                    <a href="checkouts.php" class="flex items-center p-3 rounded-lg bg-primary-600 text-white shadow-md nav-item">
                        <i class="fas fa-exchange-alt w-5"></i>
                        <span class="ml-3 sidebar-text">Circulation</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('patrons-submenu', this)">
                        <i class="fas fa-users w-5"></i>
                        <span class="ml-3 sidebar-text">Patron Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text transition-transform"></i>
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
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text transition-transform"></i>
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

    <!-- Main Container -->
    <div class="main-container">
        <!-- Header -->
        <header class="header-bg text-white p-4 flex items-center justify-between shadow-md">
            <div class="flex items-center">
                <button id="sidebar-toggle" class="md:hidden text-white mr-4 focus:outline-none" onclick="toggleSidebar()">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl font-bold">Circulation</h1>
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

        <!-- Main Content -->
        <main>
            <div class="main-content">
                <!-- Page Header -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Circulation</h1>
                        <p class="text-gray-600 mt-1">Manage book checkouts and check-ins</p>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="bg-<?php echo $messageType === 'success' ? 'green' : 'red'; ?>-100 border-l-4 border-<?php echo $messageType === 'success' ? 'green' : 'red'; ?>-500 text-<?php echo $messageType === 'success' ? 'green' : 'red'; ?>-700 p-4 mb-6 rounded-lg dashboard-card" role="alert">
                        <p><?php echo htmlspecialchars($message); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg dashboard-card" role="alert">
                        <p><?php echo htmlspecialchars($error); ?></p>
                    </div>
                <?php endif; ?>
                
                <!-- Check Out Book -->
                <div class="bg-white rounded-xl p-6 shadow-sm mb-6 dashboard-card">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Check Out Book</h2>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="checkout">
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="book_id" class="block text-sm font-medium text-gray-700 mb-1">Select Book</label>
                                <select id="book_id" name="book_id" required
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                                    <option value="">-- Select a book --</option>
                                    <?php foreach ($availableBooks as $book): ?>
                                        <option value="<?php echo $book['id']; ?>">
                                            <?php echo htmlspecialchars($book['title']); ?> (<?php echo htmlspecialchars($book['isbn']); ?>) - 
                                            Available: <?php echo (int)$book['available']; ?>/<?php echo (int)$book['quantity']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="relative">
                                <label for="patron_search" class="block text-sm font-medium text-gray-700 mb-1">Search Student</label>
                                <div class="relative">
                                    <input type="text" id="patron_search" autocomplete="off"
                                           class="w-full px-4 py-2 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                           placeholder="Type or click to select student...">
                                    <div class="absolute inset-y-0 right-0 flex items-center pr-3 pointer-events-none">
                                        <i class="fas fa-chevron-down text-gray-400"></i>
                                    </div>
                                </div>
                                <input type="hidden" id="patron_id" name="patron_id" required>
                                
                                <!-- Student dropdown -->
                                <div id="student_dropdown" class="absolute z-50 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg max-h-60 overflow-y-auto hidden">
                                    <?php foreach ($students as $student): ?>
                                        <div class="student-option px-4 py-2 hover:bg-primary-50 cursor-pointer border-b border-gray-100"
                                             data-student-id="<?php echo htmlspecialchars($student['user_id']); ?>"
                                             data-student-name="<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>"
                                             data-student-number="<?php echo htmlspecialchars($student['student_id'] ?? ''); ?>">
                                            <div class="font-medium text-gray-900">
                                                <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                ID: <?php echo htmlspecialchars($student['student_id'] ?? 'N/A'); ?> • 
                                                Grade <?php echo htmlspecialchars($student['grade_level'] ?? 'N/A'); ?> - 
                                                Section <?php echo htmlspecialchars($student['section'] ?? 'N/A'); ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div class="flex items-end">
                                <button type="submit" class="bg-primary-600 text-white px-6 py-2 rounded-lg hover:bg-primary-700 transition-colors w-full">
                                    <i class="fas fa-sign-out-alt mr-2"></i> Check Out
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Check In Book -->
                <div class="bg-white rounded-xl p-6 shadow-sm mb-6 dashboard-card">
                    <h2 class="text-xl font-bold text-gray-800 mb-4">Check In Book</h2>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="checkin">
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="md:col-span-2">
                                <label for="checkin_book_id" class="block text-sm font-medium text-gray-700 mb-1">Book ID/ISBN/Title</label>
                                <input type="text" id="checkin_book_id" name="book_id" required
                                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                       placeholder="Scan or enter book ID, ISBN, or title">
                            </div>
                            
                            <div class="flex items-end">
                                <button type="submit" class="bg-green-600 text-white px-6 py-2 rounded-lg hover:bg-green-700 transition-colors w-full">
                                    <i class="fas fa-sign-in-alt mr-2"></i> Check In
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Active Checkouts -->
                <div class="bg-white rounded-xl p-6 shadow-sm dashboard-card">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-xl font-bold text-gray-800">Active Checkouts</h2>
                        <span class="text-sm text-gray-600">
                            <?php echo count($activeCheckouts); ?> active checkout<?php echo count($activeCheckouts) !== 1 ? 's' : ''; ?>
                        </span>
                    </div>
                    
                    <?php if (!empty($activeCheckouts)): ?>
                        <div class="overflow-x-auto custom-scrollbar">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Book</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Patron</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Checked Out</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($activeCheckouts as $checkout): 
                                        $dueDate = new DateTime($checkout['due_date']);
                                        $today = new DateTime();
                                        $isOverdue = $dueDate < $today;
                                    ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="font-medium text-gray-900"><?php echo htmlspecialchars($checkout['title']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($checkout['isbn']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($checkout['first_name'] . ' ' . $checkout['last_name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($checkout['card_number']); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('M j, Y', strtotime($checkout['checkout_date'])); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                <span class="<?php echo $isOverdue ? 'text-red-600' : 'text-gray-900'; ?>">
                                                    <?php echo date('M j, Y', strtotime($checkout['due_date'])); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($isOverdue): ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                        Overdue
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                        Active
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-8 text-gray-500">
                            <i class="fas fa-inbox text-4xl mb-2 opacity-30"></i>
                            <p>No active checkouts found.</p>
                        </div>
                    <?php endif; ?>
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

        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            sidebar.classList.toggle('sidebar-open');
            overlay.classList.toggle('overlay-open');
            showToast(sidebar.classList.contains('sidebar-open') ? 'Sidebar opened' : 'Sidebar closed', 'info');
        }
        
        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            sidebar.classList.remove('sidebar-open');
            overlay.classList.remove('overlay-open');
            showToast('Sidebar closed', 'info');
        }
        
        function toggleUserMenu() {
            const userMenu = document.getElementById('user-menu');
            userMenu.classList.toggle('user-menu-open');
            showToast(userMenu.classList.contains('user-menu-open') ? 'User menu opened' : 'User menu closed', 'info');
        }
        
        function toggleNotifications() {
            const notificationPanel = document.getElementById('notification-panel');
            notificationPanel.classList.toggle('open');
            if (notificationPanel.classList.contains('open')) {
                document.querySelector('.notification-dot').textContent = '0';
                showToast('Notifications viewed', 'info');
            }
        }
        
        function toggleSubmenu(submenuId, element) {
            event.preventDefault();
            const submenu = document.getElementById(submenuId);
            const chevron = element.querySelector('.fa-chevron-down');
            const isOpen = submenu.classList.contains('open');
            
            // Close all other submenus
            document.querySelectorAll('.submenu').forEach(otherSubmenu => {
                if (otherSubmenu.id !== submenuId) {
                    otherSubmenu.classList.remove('open');
                    const otherChevron = otherSubmenu.parentElement.querySelector('.fa-chevron-down');
                    if (otherChevron) otherChevron.classList.remove('rotate-90');
                }
            });
            
            // Toggle the current submenu
            submenu.classList.toggle('open', !isOpen);
            chevron.classList.toggle('rotate-90', !isOpen);
            
            showToast(`Submenu ${submenuId.replace('-submenu', '')} ${submenu.classList.contains('open') ? 'expanded' : 'collapsed'}`, 'info');
        }
        
        function toggleSidebarCollapse() {
            const sidebar = document.getElementById('sidebar');
            const collapseIcon = document.getElementById('collapse-icon');
            
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
        
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('user-menu');
            const userButton = document.querySelector('.relative button:last-child');
            const notificationPanel = document.getElementById('notification-panel');
            const notificationButton = document.getElementById('notification-btn');
            
            if (!userMenu.contains(event.target) && !userButton.contains(event.target)) {
                userMenu.classList.remove('user-menu-open');
            }
            
            if (!notificationPanel.contains(event.target) && !notificationButton.contains(event.target)) {
                notificationPanel.classList.remove('open');
            }
        });
        
        document.addEventListener('DOMContentLoaded', function() {
            showToast('Welcome to Circulation!', 'success');
            console.log('Circulation page loaded');
            
            // Student search functionality
            const patronSearch = document.getElementById('patron_search');
            const patronIdField = document.getElementById('patron_id');
            const studentDropdown = document.getElementById('student_dropdown');
            const studentOptions = document.querySelectorAll('.student-option');
            
            // Show dropdown when typing
            patronSearch.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                let hasResults = false;
                
                studentOptions.forEach(option => {
                    const studentName = option.dataset.studentName.toLowerCase();
                    const studentNumber = option.dataset.studentNumber.toLowerCase();
                    
                    if (studentName.includes(searchTerm) || studentNumber.includes(searchTerm)) {
                        option.style.display = 'block';
                        hasResults = true;
                    } else {
                        option.style.display = 'none';
                    }
                });
                
                if (searchTerm.length > 0 && hasResults) {
                    studentDropdown.classList.remove('hidden');
                } else {
                    studentDropdown.classList.add('hidden');
                }
            });
            
            // Handle student selection
            studentOptions.forEach(option => {
                option.addEventListener('click', function() {
                    const studentName = this.dataset.studentName;
                    const studentId = this.dataset.studentId;
                    const studentNumber = this.dataset.studentNumber;
                    
                    patronSearch.value = studentName + ' (ID: ' + studentNumber + ')';
                    patronIdField.value = studentId;
                    studentDropdown.classList.add('hidden');
                    
                    showToast('Student selected: ' + studentName, 'success');
                });
            });
            
            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                if (!patronSearch.contains(event.target) && !studentDropdown.contains(event.target)) {
                    studentDropdown.classList.add('hidden');
                }
            });
            
            // Show dropdown when focusing on search - show all students
            patronSearch.addEventListener('focus', function() {
                // Show all students when clicking on the field
                studentOptions.forEach(option => {
                    option.style.display = 'block';
                });
                studentDropdown.classList.remove('hidden');
            });
            
            // Also show dropdown when clicking on the field
            patronSearch.addEventListener('click', function() {
                studentOptions.forEach(option => {
                    option.style.display = 'block';
                });
                studentDropdown.classList.remove('hidden');
            });
            
            // Auto-focus on patron search field when book is selected
            const bookSelect = document.getElementById('book_id');
            
            if (bookSelect && patronSearch) {
                bookSelect.addEventListener('change', function() {
                    if (this.value) {
                        patronSearch.focus();
                    }
                });
            }
            
            // Auto-focus on check-in field
            const checkinField = document.getElementById('checkin_book_id');
            if (checkinField) {
                checkinField.focus();
            }
            
            <?php if ($message): ?>
                showToast('<?php echo htmlspecialchars($message); ?>', '<?php echo $messageType; ?>');
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                showToast('<?php echo htmlspecialchars($error); ?>', 'error');
            <?php endif; ?>
        });
    </script>
</body>
</html>