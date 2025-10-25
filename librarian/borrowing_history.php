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
    $conn = $database->getConnection('librarian');
    
    // Get or create librarian profile
    $query = "SELECT * FROM librarians WHERE user_id = :user_id LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $librarianData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$librarianData) {
        $librarian_id = 'LIB' . strtoupper(substr($user['username'], 0, 3)) . str_pad($user_id, 4, '0', STR_PAD_LEFT);
        $first_name = ucfirst($user['username']);
        $last_name = 'Librarian';
        
        $query = "INSERT INTO librarians (user_id, librarian_id, first_name, last_name) 
                 VALUES (:user_id, :librarian_id, :first_name, :last_name)";
        $stmt = $conn->prepare($query);
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':librarian_id' => $librarian_id,
            ':first_name' => $first_name,
            ':last_name' => $last_name
        ]);
        
        $librarianData = [
            'id' => $conn->lastInsertId(),
            'user_id' => $user_id,
            'librarian_id' => $librarian_id,
            'first_name' => $first_name,
            'last_name' => $last_name
        ];
    }
    
    // Combine user and librarian data
    $userData = array_merge($user, $librarianData);
    $pageTitle = 'Borrowing History - ' . htmlspecialchars($userData['first_name'] . ' ' . $userData['last_name']);
    
    // Handle AJAX return request
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_return'])) {
        header('Content-Type: application/json');
        
        $transactionId = $_POST['transaction_id'] ?? 0;
        
        try {
            // Get transaction details
            $stmt = $conn->prepare("SELECT t.*, b.id as book_id FROM transactions t JOIN books b ON t.book_id = b.id WHERE t.id = :id");
            $stmt->execute([':id' => $transactionId]);
            $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($transaction && $transaction['status'] !== 'returned') {
                // Update transaction status
                $updateStmt = $conn->prepare("UPDATE transactions SET return_date = NOW(), status = 'returned' WHERE id = :id");
                $updateStmt->execute([':id' => $transactionId]);
                
                // Update book availability
                $bookStmt = $conn->prepare("UPDATE books SET available = available + 1 WHERE id = :book_id");
                $bookStmt->execute([':book_id' => $transaction['book_id']]);
                
                // Also update book_loans if exists
                $loanStmt = $conn->prepare("UPDATE book_loans SET return_date = NOW(), status = 'returned' 
                                            WHERE book_id = :book_id AND patron_id = :patron_id AND return_date IS NULL 
                                            ORDER BY checkout_date DESC LIMIT 1");
                $loanStmt->execute([
                    ':book_id' => $transaction['book_id'],
                    ':patron_id' => $transaction['patron_id']
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Book returned successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Transaction not found or already returned']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
    }
    
    // Initialize variables
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? 'all';
    $sort = $_GET['sort'] ?? 'checkout_date';
    $order = $_GET['order'] ?? 'desc';
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $perPage = 15;
    $offset = ($page - 1) * $perPage;
    
    // Build the query
    $query = "SELECT t.*, b.title as book_title, b.isbn, 
              CONCAT(p.first_name, ' ', p.last_name) as patron_name,
              p.patron_id as patron_card
              FROM transactions t
              JOIN books b ON t.book_id = b.id
              JOIN patrons p ON t.patron_id = p.patron_id
              WHERE 1=1";
              
    $countQuery = "SELECT COUNT(*) as total FROM transactions t
                  JOIN books b ON t.book_id = b.id
                  JOIN patrons p ON t.patron_id = p.patron_id
                  WHERE 1=1";
    
    $params = [];
    if (!empty($search)) {
        $query .= " AND (b.title LIKE :search OR b.isbn = :exact_isbn OR p.email = :exact_email OR CONCAT(p.first_name, ' ', p.last_name) LIKE :search)";
        $countQuery .= " AND (b.title LIKE :search OR b.isbn = :exact_isbn OR p.email = :exact_email OR CONCAT(p.first_name, ' ', p.last_name) LIKE :search)";
        $params[':search'] = "%$search%";
        $params[':exact_isbn'] = $search;
        $params[':exact_email'] = $search;
    }
    
    if ($status !== 'all') {
        $query .= " AND t.status = :status";
        $countQuery .= " AND t.status = :status";
        $params[':status'] = $status;
    }
    
    // Get total count for pagination
    $countStmt = $conn->prepare($countQuery);
    $countStmt->execute($params);
    $totalTransactions = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalTransactions / $perPage);
    
    // Add sorting and pagination to the query
    $validSortColumns = ['checkout_date', 'due_date', 'return_date', 'status'];
    $sort = in_array($sort, $validSortColumns) ? $sort : 'checkout_date';
    $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
    
    $query .= " ORDER BY $sort $order LIMIT :limit OFFSET :offset";
    $params[':limit'] = $perPage;
    $params[':offset'] = $offset;
    
    // Execute the main query
    $stmt = $conn->prepare($query);
    foreach ($params as $key => &$value) {
        $stmt->bindParam($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats = [
        'total' => 0,
        'overdue' => 0,
        'returned' => 0,
        'active' => 0
    ];
    
    $statsQuery = "SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue,
        SUM(CASE WHEN status = 'returned' THEN 1 ELSE 0 END) as returned,
        SUM(CASE WHEN status = 'checked_out' THEN 1 ELSE 0 END) as active
        FROM transactions";
    
    $result = $conn->query($statsQuery);
    if ($result) {
        $stats = array_merge($stats, $result->fetch(PDO::FETCH_ASSOC));
    }
    
} catch (Exception $e) {
    $error = "Database Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>📚 Librarian Portal – Borrowing History</title>
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
            transition: max-height 0.3s ease-in-out;
        }
        .submenu.open {
            max-height: 500px; /* Adjust based on content */
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
        .status-checked_out { background-color: #FEF3C7; color: #92400E; }
        .status-returned { background-color: #D1FAE5; color: #065F46; }
        .status-overdue { background-color: #FEE2E2; color: #991B1B; }
        .status-lost { background-color: #E5E7EB; color: #1F2937; }
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('catalog-submenu')">
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
                    <a href="#" class="flex items-center p-3 rounded-lg bg-primary-600 text-white shadow-md nav-item" onclick="toggleSubmenu('patrons-submenu')">
                        <i class="fas fa-users w-5"></i>
                        <span class="ml-3 sidebar-text">Patron Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="patrons-submenu" class="submenu pl-4 mt-1 open">
                        <a href="patrons.php" class="flex items-center p-2 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-list w-5"></i>
                            <span class="ml-3 sidebar-text">View Patrons</span>
                        </a>
                        <a href="borrowing_history.php" class="flex items-center p-2 rounded-lg bg-primary-600 text-white shadow-md">
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('reports-submenu')">
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

    <!-- Main Container -->
    <div class="main-container">
        <!-- Header -->
        <header class="header-bg text-white p-4 flex items-center justify-between shadow-md">
            <div class="flex items-center">
                <button id="sidebar-toggle" class="md:hidden text-white mr-4 focus:outline-none" onclick="toggleSidebar()">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl font-bold">Borrowing History</h1>
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
                <!-- Page Header and Actions -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Borrowing History</h1>
                        <p class="text-gray-600 mt-1">Track and manage all library transactions</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button onclick="window.print()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-lg hover:bg-gray-50 transition-colors flex items-center">
                            <i class="fas fa-print mr-2"></i> Print
                        </button>
                        <button id="exportPdf" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors flex items-center">
                            <i class="fas fa-file-pdf mr-2"></i> PDF
                        </button>
                        <button id="exportExcel" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700 transition-colors flex items-center">
                            <i class="fas fa-file-excel mr-2"></i> Excel
                        </button>
                        <button id="exportCsv" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors flex items-center">
                            <i class="fas fa-file-csv mr-2"></i> CSV
                        </button>
                    </div>
                </div>

                <?php if (isset($error)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg" role="alert">
                        <p><?php echo htmlspecialchars($error); ?></p>
                    </div>
                <?php endif; ?>
                
                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-xl shadow-sm border-l-4 border-blue-500 dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                                <i class="fas fa-exchange-alt text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Total Transactions</p>
                                <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($stats['total']); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-xl shadow-sm border-l-4 border-green-500 dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 text-green-600">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Returned</p>
                                <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($stats['returned'] ?? 0); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-xl shadow-sm border-l-4 border-yellow-500 dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                                <i class="fas fa-clock text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Active</p>
                                <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($stats['active'] ?? 0); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-6 rounded-xl shadow-sm border-l-4 border-red-500 dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-red-100 text-red-600">
                                <i class="fas fa-exclamation-triangle text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Overdue</p>
                                <p class="text-2xl font-semibold text-gray-800"><?php echo number_format($stats['overdue'] ?? 0); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Search and Filter -->
                <div class="bg-white rounded-xl p-6 shadow-sm mb-6 dashboard-card">
                    <form method="GET" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div class="md:col-span-2">
                                <div class="relative">
                                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                           class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent"
                                           placeholder="Search by book title, ISBN, patron name, or card number">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <i class="fas fa-search text-gray-400"></i>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <select name="status" onchange="this.form.submit()"
                                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary-500 focus:border-transparent">
                                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                        <option value="checked_out" <?php echo $status === 'checked_out' ? 'selected' : ''; ?>>Checked Out</option>
                                        <option value="returned" <?php echo $status === 'returned' ? 'selected' : ''; ?>>Returned</option>
                                        <option value="overdue" <?php echo $status === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                        <option value="lost" <?php echo $status === 'lost' ? 'selected' : ''; ?>>Lost</option>
                                    </select>
                                </div>
                                <button type="submit" class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors">
                                    Apply Filters
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Transactions Table -->
                <div class="bg-white rounded-xl shadow-sm overflow-hidden mb-8 dashboard-card">
                    <div class="overflow-x-auto custom-scrollbar">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <a href="?search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=checkout_date&order=<?php echo $sort === 'checkout_date' && $order === 'asc' ? 'desc' : 'asc'; ?>&page=<?php echo $page; ?>">
                                            Checkout Date
                                            <?php if ($sort === 'checkout_date'): ?>
                                                <i class="fas fa-sort-<?php echo $order === 'asc' ? 'up' : 'down'; ?> ml-1"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort ml-1 text-gray-300"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Book</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Patron</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <a href="?search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=due_date&order=<?php echo $sort === 'due_date' && $order === 'asc' ? 'desc' : 'asc'; ?>&page=<?php echo $page; ?>">
                                            Due Date
                                            <?php if ($sort === 'due_date'): ?>
                                                <i class="fas fa-sort-<?php echo $order === 'asc' ? 'up' : 'down'; ?> ml-1"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort ml-1 text-gray-300"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <a href="?search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=return_date&order=<?php echo $sort === 'return_date' && $order === 'asc' ? 'desc' : 'asc'; ?>&page=<?php echo $page; ?>">
                                            Return Date
                                            <?php if ($sort === 'return_date'): ?>
                                                <i class="fas fa-sort-<?php echo $order === 'asc' ? 'up' : 'down'; ?> ml-1"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort ml-1 text-gray-300"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <a href="?search=<?php echo urlencode($search); ?>&status=<?php echo $status; ?>&sort=status&order=<?php echo $sort === 'status' && $order === 'asc' ? 'desc' : 'asc'; ?>&page=<?php echo $page; ?>">
                                            Status
                                            <?php if ($sort === 'status'): ?>
                                                <i class="fas fa-sort-<?php echo $order === 'asc' ? 'up' : 'down'; ?> ml-1"></i>
                                            <?php else: ?>
                                                <i class="fas fa-sort ml-1 text-gray-300"></i>
                                            <?php endif; ?>
                                        </a>
                                    </th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($transactions as $transaction): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo date('M d, Y', strtotime($transaction['checkout_date'])); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo date('h:i A', strtotime($transaction['checkout_date'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($transaction['book_title']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                ISBN: <?php echo htmlspecialchars($transaction['isbn']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($transaction['patron_name']); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php echo htmlspecialchars($transaction['patron_card']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo date('M d, Y', strtotime($transaction['due_date'])); ?>
                                            </div>
                                            <div class="text-xs text-gray-500">
                                                <?php 
                                                    $dueDate = new DateTime($transaction['due_date']);
                                                    $today = new DateTime();
                                                    $interval = $today->diff($dueDate);
                                                    $days = $interval->format('%r%a');
                                                    if ($days < 0 && $transaction['status'] !== 'returned') {
                                                        echo '<span class="text-red-500">' . abs($days) . ' days ago</span>';
                                                    } elseif ($days > 0 && $transaction['status'] !== 'returned') {
                                                        echo 'in ' . $days . ' days';
                                                    } elseif ($days === 0 && $transaction['status'] !== 'returned') {
                                                        echo '<span class="text-yellow-500">Due today</span>';
                                                    }
                                                ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php 
                                                    if ($transaction['return_date']) {
                                                        echo date('M d, Y', strtotime($transaction['return_date']));
                                                    } else {
                                                        echo '<span class="text-gray-400">Not returned</span>';
                                                    }
                                                ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                status-<?php echo $transaction['status']; ?>">
                                                <?php 
                                                    $statusText = [
                                                        'checked_out' => 'Checked Out',
                                                        'returned' => 'Returned',
                                                        'overdue' => 'Overdue',
                                                        'lost' => 'Lost'
                                                    ];
                                                    echo $statusText[$transaction['status']] ?? ucfirst($transaction['status']);
                                                ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex justify-end space-x-2">
                                                <button onclick="viewTransactionDetails(<?php echo htmlspecialchars(json_encode($transaction)); ?>)" 
                                                   class="text-primary-600 hover:text-primary-900" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if ($transaction['status'] !== 'returned'): ?>
                                                    <button onclick="markAsReturned(<?php echo $transaction['id']; ?>)" 
                                                            class="text-green-600 hover:text-green-900" title="Mark as Returned">
                                                        <i class="fas fa-check-circle"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($transaction['status'] === 'overdue'): ?>
                                                    <button onclick="sendReminder(<?php echo $transaction['id']; ?>)" 
                                                            class="text-yellow-600 hover:text-yellow-900" title="Send Reminder">
                                                        <i class="fas fa-bell"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($transactions)): ?>
                                    <tr>
                                        <td colspan="7" class="px-6 py-8 text-center text-gray-500">
                                            <i class="fas fa-exchange-alt text-4xl mb-2 opacity-30"></i>
                                            <p>No transactions found.</p>
                                            <?php if ($search || $status !== 'all'): ?>
                                                <a href="?" class="mt-2 inline-block text-primary-600 hover:text-primary-800 font-medium">
                                                    Clear filters
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to 
                                <span class="font-medium"><?php echo min($offset + $perPage, $totalTransactions); ?></span> of 
                                <span class="font-medium"><?php echo $totalTransactions; ?></span> transactions
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo ($page - 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>" 
                                       class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                                        Previous
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($page < $totalPages): ?>
                                    <a href="?page=<?php echo ($page + 1); ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>" 
                                       class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">
                                        Next
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Export Options -->
                <div class="bg-white p-6 rounded-xl shadow-sm dashboard-card">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Export Report</h3>
                    <div class="flex flex-wrap gap-4">
                        <button id="exportPdf" class="flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-file-pdf text-red-500 mr-2"></i> PDF
                        </button>
                        <button id="exportExcel" class="flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-file-excel text-green-600 mr-2"></i> Excel
                        </button>
                        <button id="exportCsv" class="flex items-center px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            <i class="fas fa-file-csv text-blue-600 mr-2"></i> CSV
                        </button>
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
        
        function toggleSubmenu(submenuId) {
            event.preventDefault();
            const submenu = document.getElementById(submenuId);
            const parentLi = submenu.parentElement;
            const chevron = parentLi.querySelector('.fa-chevron-down');
            const isOpen = submenu.classList.contains('open');

            // Close all other submenus
            document.querySelectorAll('.submenu').forEach(menu => {
                if (menu !== submenu) {
                    menu.classList.remove('open');
                    const otherChevron = menu.parentElement.querySelector('.fa-chevron-down');
                    if (otherChevron) otherChevron.classList.remove('rotate-90');
                }
            });

            // Toggle the current submenu
            submenu.classList.toggle('open', !isOpen);
            if (chevron) {
                chevron.classList.toggle('rotate-90', !isOpen);
            }

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
        
        function markAsReturned(transactionId) {
            if (confirm('Are you sure you want to mark this book as returned?')) {
                showToast('Processing return...', 'info');
                fetch(window.location.href, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `ajax_return=1&transaction_id=${transactionId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Book returned successfully!', 'success');
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        showToast('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('An error occurred while processing the return.', 'error');
                });
            }
        }
        
        function sendReminder(transactionId) {
            if (confirm('Send a reminder email to the patron?')) {
                showToast('Sending reminder...', 'info');
                fetch('send_reminder.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `transaction_id=${transactionId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Reminder sent successfully!', 'success');
                    } else {
                        showToast('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('An error occurred while sending the reminder.', 'error');
                });
            }
        }
        
        function viewTransactionDetails(transaction) {
            const statusText = {
                'checked_out': 'Checked Out',
                'returned': 'Returned',
                'overdue': 'Overdue',
                'lost': 'Lost'
            };
            
            const checkoutDate = new Date(transaction.checkout_date).toLocaleDateString('en-US', { 
                year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' 
            });
            const dueDate = new Date(transaction.due_date).toLocaleDateString('en-US', { 
                year: 'numeric', month: 'long', day: 'numeric' 
            });
            const returnDate = transaction.return_date 
                ? new Date(transaction.return_date).toLocaleDateString('en-US', { 
                    year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' 
                })
                : 'Not returned yet';
            
            const details = `
                <div class="space-y-4">
                    <div class="border-b pb-3">
                        <h3 class="text-lg font-bold text-gray-800 mb-2">Transaction Details</h3>
                        <p class="text-sm text-gray-600">Transaction ID: #${transaction.id}</p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm font-semibold text-gray-700">Book Title</p>
                            <p class="text-sm text-gray-900">${transaction.book_title || 'N/A'}</p>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-700">Patron</p>
                            <p class="text-sm text-gray-900">${transaction.patron_name || 'N/A'}</p>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-700">Checkout Date</p>
                            <p class="text-sm text-gray-900">${checkoutDate}</p>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-700">Due Date</p>
                            <p class="text-sm text-gray-900">${dueDate}</p>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-700">Return Date</p>
                            <p class="text-sm text-gray-900">${returnDate}</p>
                        </div>
                        <div>
                            <p class="text-sm font-semibold text-gray-700">Status</p>
                            <p class="text-sm">
                                <span class="px-2 py-1 rounded-full text-xs font-semibold status-${transaction.status}">
                                    ${statusText[transaction.status] || transaction.status}
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
            `;
            
            // Create modal
            const modal = document.createElement('div');
            modal.className = 'fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50';
            modal.innerHTML = `
                <div class="bg-white rounded-lg p-6 max-w-2xl w-full mx-4 shadow-xl">
                    ${details}
                    <div class="mt-6 flex justify-end">
                        <button onclick="this.closest('.fixed').remove()" 
                                class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700">
                            Close
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Close on background click
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            showToast('Welcome to Borrowing History!', 'success');
            console.log('Borrowing History page loaded');
            
            // Handle export buttons
            document.getElementById('exportPdf').addEventListener('click', function() {
                showToast('Exporting PDF...', 'info');
                // Placeholder for server-side PDF export
                // window.location.href = 'export_pdf.php?report=transactions';
            });
            
            document.getElementById('exportExcel').addEventListener('click', function() {
                showToast('Exporting Excel...', 'info');
                // Placeholder for server-side Excel export
                // window.location.href = 'export_excel.php?report=transactions';
            });
            
            document.getElementById('exportCsv').addEventListener('click', function() {
                showToast('Exporting CSV...', 'info');
                // Placeholder for server-side CSV export
                // window.location.href = 'export_csv.php?report=transactions';
            });
            
            <?php if (isset($error)): ?>
                showToast('<?php echo htmlspecialchars($error); ?>', 'error');
            <?php endif; ?>
        });
    </script>
</body>
</html>