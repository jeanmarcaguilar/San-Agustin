<?php
session_start();

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('Location: ../login.php');
    exit();
}

// Include database configuration
require_once '../config/database.php';

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');
$conn = $pdo; // Keep the existing $conn variable for backward compatibility

// Get registrar information from database
$registrar_id = $_SESSION['user_id'];
$registrar = [
    'user_id' => $registrar_id,
    'first_name' => 'Registrar',
    'last_name' => 'User',
    'contact_number' => '',
];

try {
    $stmt = $pdo->prepare("SELECT * FROM registrars WHERE user_id = ?");
    $stmt->execute([$registrar_id]);
    $db_registrar = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($db_registrar) {
        $registrar = array_merge($registrar, $db_registrar);
    }
} catch (PDOException $e) {
    // Log error and continue with default values
    error_log("Error fetching registrar data: " . $e->getMessage());
}

// Initialize stats
$stats = [
    'new_applications' => 0,
    'pending_documents' => 0,
];

try {
    // Get count of new applications
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM enrollments WHERE status = 'pending'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['new_applications'] = $result ? (int)$result['count'] : 0;
    
    // Get count of pending documents
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM documents WHERE status = 'pending'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending_documents'] = $result ? (int)$result['count'] : 0;
} catch (PDOException $e) {
    // Log error and continue with default values
    error_log("Error fetching stats: " . $e->getMessage());
}

// Set user initials for avatar
$initials = '';
if (!empty($registrar['first_name']) && !empty($registrar['last_name'])) {
    $initials = strtoupper(substr($registrar['first_name'], 0, 1) . substr($registrar['last_name'], 0, 1));
} elseif (!empty($registrar['first_name'])) {
    $initials = strtoupper(substr($registrar['first_name'], 0, 2));
} elseif (!empty($_SESSION['username'])) {
    $initials = strtoupper(substr($_SESSION['username'], 0, 2));
} else {
    $initials = 'RU'; // Default initials
}

// Calculate current school year
$current_year = date('Y');
$next_year = $current_year + 1;
$school_year = "$current_year-$next_year";

// Check for success message
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); // Clear the message after displaying
}

// Get search parameter
$search = $_GET['search'] ?? '';

// Build the base query
$query = "SELECT s.*, u.email, u.username
          FROM students s 
          LEFT JOIN login_db.users u ON s.user_id = u.id 
          WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (s.first_name LIKE :search OR s.last_name LIKE :search OR s.student_id LIKE :search OR s.lrn LIKE :search OR u.email LIKE :search OR u.username LIKE :search)";
    $params[':search'] = "%$search%";
}

// Add sorting
$query .= " ORDER BY s.last_name, s.first_name";

// Prepare and execute the query
$stmt = $pdo->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine current page for sidebar highlighting
$current_page = basename($_SERVER['PHP_SELF']);
$registrar_id_display = 'R' . $_SESSION['user_id'];
$initials = 'R' . substr($_SESSION['user_id'], -1); // Get last digit for initials
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>San Agustin Elementary School - Registrar Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
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
            background: #0ea5e9;
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
            background: linear-gradient(135deg, #0ea5e9 0%, #38bdf8 100%);
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
            border-left-color: #0ea5e9;
        }
        .toast.info {
            border-left-color: #38bdf8;
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
        .status-active {
            background-color: #0ea5e9;
            color: white;
        }
        .status-inactive {
            background-color: #ef4444;
            color: white;
        }
        .status-pending {
            background-color: #facc15;
            color: black;
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
                <i class="fas fa-file-alt"></i>
            </div>
            <h1 class="text-xl font-bold text-center logo-text">San Agustin Elementary School</h1>
            <p class="text-xs text-secondary-200 mt-1 logo-text">Registrar's Office</p>
        </div>
        
        <!-- User Profile -->
        <div class="p-5 border-b border-secondary-700">
            <div class="flex items-center space-x-3">
                <div class="w-12 h-12 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold shadow-md user-initials">
                    <?php echo htmlspecialchars($registrar_id_display); ?>
                </div>
                <div class="user-text">
                    <h2>Registrar</h2>
                    <p class="text-xs text-secondary-200"><?php echo htmlspecialchars($registrar_id_display); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Navigation -->
        <div class="flex-1 p-4 overflow-y-auto custom-scrollbar">
            <ul class="space-y-2">
                <li>
                    <a href="dashboard.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'dashboard.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-home w-5"></i>
                        <span class="ml-3 sidebar-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('students-submenu', this)">
                        <i class="fas fa-user-graduate w-5"></i>
                        <span class="ml-3 sidebar-text">Student Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="students-submenu" class="submenu pl-4 mt-1 <?php echo $current_page === 'add_student.php' || $current_page === 'view_students.php' || $current_page === 'student_search.php' ? 'open' : ''; ?>">
                        <a href="add_student.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'add_student.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-plus w-5"></i>
                            <span class="ml-3 sidebar-text">Enroll New Student</span>
                        </a>
                        <a href="view_students.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'view_students.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-list w-5"></i>
                            <span class="ml-3 sidebar-text">View All Students</span>
                        </a>
                        <a href="student_search.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'student_search.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-search w-5"></i>
                            <span class="ml-3 sidebar-text">Search Student</span>
                        </a>
                    </div>
                </li>
                <li>
                    <a href="enrollment.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'enrollment.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-clipboard-list w-5"></i>
                        <span class="ml-3 sidebar-text">Enrollment</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg <?php echo in_array($current_page, ['view_sections.php', 'class_schedules.php']) ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item" onclick="toggleSubmenu('sections-submenu', this)">
                        <i class="fas fa-chalkboard w-5"></i>
                        <span class="ml-3 sidebar-text">Class Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="sections-submenu" class="submenu pl-4 mt-1 <?php echo in_array($current_page, ['view_sections.php', 'class_schedules.php']) ? 'open' : ''; ?>">
                        <a href="view_sections.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'view_sections.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-users w-5"></i>
                            <span class="ml-3 sidebar-text">Class Sections</span>
                        </a>
                        <a href="class_schedules.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'class_schedules.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-calendar-alt w-5"></i>
                            <span class="ml-3 sidebar-text">Class Schedules</span>
                        </a>
                    </div>
                </li>
                <li>
                    <a href="attendance.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'attendance.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-calendar-check w-5"></i>
                        <span class="ml-3 sidebar-text">Attendance</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('reports-submenu', this)">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="reports-submenu" class="submenu pl-4 mt-1">
                        <a href="enrollment_reports.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'enrollment_reports.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-file-alt w-5"></i>
                            <span class="ml-3 sidebar-text">Enrollment Reports</span>
                        </a>
                        <a href="demographic_reports.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'demographic_reports.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-chart-pie w-5"></i>
                            <span class="ml-3 sidebar-text">Demographic Reports</span>
                        </a>
                        </div>
                </li>
                <li>
                    <a href="documents.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'documents.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-file-archive w-5"></i>
                        <span class="ml-3 sidebar-text">Document Management</span>
                    </a>
                </li>
            </ul>
            
            <!-- Upcoming Deadlines -->
            <div class="mt-10 p-4 bg-secondary-800 rounded-lg events-container">
                <h3 class="text-sm font-bold text-white mb-3 flex items-center events-title">
                    <i class="fas fa-calendar-day mr-2"></i>Upcoming Deadlines
                </h3>
                <div class="space-y-3 event-details">
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">20</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Enrollment Deadline</p>
                            <p class="text-xs text-secondary-300">SY <?php echo htmlspecialchars($school_year); ?></p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">25</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Report Cards Distribution</p>
                            <p class="text-xs text-secondary-300">1st Quarter</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">30</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Census Submission</p>
                            <p class="text-xs text-secondary-300">DepEd Requirement</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">5</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Classroom Assignment</p>
                            <p class="text-xs text-secondary-300">Finalization</p>
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
        <header class="header-bg text-white p-4 flex items-center justify-between shadow-md">
            <div class="flex items-center">
                <button id="sidebar-toggle" class="md:hidden text-white mr-4 focus:outline-none" onclick="toggleSidebar()">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl font-bold">Registrar Dashboard</h1>
            </div>
            
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <button id="notification-btn" class="relative p-2 text-white hover:bg-primary-600 rounded-full focus:outline-none" onclick="toggleNotifications()" aria-label="Notifications" aria-expanded="false">
                        <i class="fas fa-bell"></i>
                        <span class="absolute top-0 right-0 h-4 w-4 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">
                            <?php echo $stats['pending_documents'] + $stats['new_applications']; ?>
                        </span>
                    </button>
                    
                    <!-- Notification Panel -->
                    <div id="notification-panel" class="notification-panel">
                        <div class="p-4 border-b border-gray-200">
                            <div class="flex items-center justify-between">
                                <h3 class="font-medium text-gray-900">Notifications</h3>
                                <button class="text-sm text-primary-600 hover:text-primary-800">Mark all as read</button>
                            </div>
                        </div>
                        <div class="divide-y divide-gray-200 max-h-80 overflow-y-auto">
                            <?php if ($stats['new_applications'] > 0): ?>
                            <a href="enrollment.php?status=pending" class="block p-4 hover:bg-gray-50">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 pt-0.5">
                                        <div class="h-10 w-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
                                            <i class="fas fa-clipboard-list"></i>
                                        </div>
                                    </div>
                                    <div class="ml-3 flex-1">
                                        <p class="text-sm font-medium text-gray-900"><?php echo $stats['new_applications']; ?> new enrollment <?php echo $stats['new_applications'] > 1 ? 'applications' : 'application'; ?></p>
                                        <p class="mt-1 text-sm text-gray-500">Click to review pending applications</p>
                                        <p class="mt-1 text-xs text-gray-400">Just now</p>
                                    </div>
                                </div>
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($stats['pending_documents'] > 0): ?>
                            <a href="documents.php?status=pending" class="block p-4 hover:bg-gray-50">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 pt-0.5">
                                        <div class="h-10 w-10 rounded-full bg-yellow-100 text-yellow-600 flex items-center justify-center">
                                            <i class="fas fa-file-alt"></i>
                                        </div>
                                    </div>
                                    <div class="ml-3 flex-1">
                                        <p class="text-sm font-medium text-gray-900"><?php echo $stats['pending_documents']; ?> document <?php echo $stats['pending_documents'] > 1 ? 'requests' : 'request'; ?> pending</p>
                                        <p class="mt-1 text-sm text-gray-500">Needs your attention</p>
                                        <p class="mt-1 text-xs text-gray-400">5 min ago</p>
                                    </div>
                                </div>
                            </a>
                            <?php endif; ?>
                            
                            <?php if ($stats['new_applications'] == 0 && $stats['pending_documents'] == 0): ?>
                            <div class="p-4 text-center text-gray-500 text-sm">
                                No new notifications
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="p-2 bg-gray-50 text-center">
                            <a href="notifications.php" class="text-sm font-medium text-primary-600 hover:text-primary-800">View all notifications</a>
                        </div>
                    </div>
                </div>
                
                <!-- User Menu -->
                <div class="relative">
                    <button id="user-menu-button" class="flex items-center space-x-2 focus:outline-none" onclick="toggleUserMenu()" aria-label="User menu" aria-expanded="false">
                        <div class="h-8 w-8 rounded-full bg-primary-600 flex items-center justify-center text-white font-medium">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <span class="hidden md:inline-block text-white"><?php echo htmlspecialchars($registrar['first_name'] ?? $_SESSION['username'] ?? 'User'); ?></span>
                        <i class="fas fa-chevron-down text-xs text-white"></i>
                    </button>
                    
                    <!-- User Dropdown Menu -->
                    <div id="user-menu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-user-circle mr-2 w-5"></i> My Profile
                        </a>
                        <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-cog mr-2 w-5"></i> Settings
                        </a>
                        <div class="border-t border-gray-200 my-1"></div>
                        <a href="#" onclick="logout()" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                            <i class="fas fa-sign-out-alt mr-2 w-5"></i> Sign out
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-5 overflow-y-auto bg-gray-50">
            <!-- Search -->
            <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm dashboard-card mb-6">
                <div class="relative max-w-lg">
                    <label for="search" class="sr-only">Search</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" name="search" id="search" 
                               value="<?php echo htmlspecialchars($search); ?>"
                               class="focus:ring-primary-500 focus:border-primary-500 block w-full pl-10 pr-12 sm:text-sm border-gray-300 rounded-md"
                               placeholder="Search students by name, ID, or email..."
                               oninput="searchStudents(this.value)">
                        <div id="search-loading" class="absolute inset-y-0 right-0 pr-3 flex items-center hidden">
                            <i class="fas fa-spinner fa-spin text-gray-400"></i>
                        </div>
                    </div>
                    <p id="search-info" class="mt-2 text-sm text-gray-500">Type to search for students</p>
                </div>
            </div>

            <!-- Students Table -->
            <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden dashboard-card">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h2 class="text-lg font-semibold text-gray-800">Student List</h2>
                    <div class="flex space-x-2">
                        <button onclick="showSyncModal()" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-sync-alt mr-2"></i> Sync from Student DB
                        </button>
                        <a href="add_student.php" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-user-plus mr-2"></i> Add New Student
                        </a>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade Level</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th scope="col" class="relative px-6 py-3">
                                    <span class="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (count($students) > 0): ?>
                                <?php foreach ($students as $student): ?>
                                    <tr class="hover:bg-gray-50 transition-opacity duration-300" data-student-id="<?php echo $student['id']; ?>">
                                        <td scope="row" class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($student['student_id']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 rounded-full bg-primary-100 flex items-center justify-center text-primary-600 font-medium">
                                                    <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name'] . ' ' . ($student['middle_name'] ? substr($student['middle_name'], 0, 1) . '.' : '')); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo date('M d, Y', strtotime($student['birthdate'])); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            Grade <?php echo htmlspecialchars($student['grade_level']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <div class="text-gray-900"><?php echo htmlspecialchars($student['guardian_name']); ?></div>
                                            <div class="text-gray-500"><?php echo htmlspecialchars($student['guardian_contact']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php
                                            $status_class = 'bg-gray-100 text-gray-800';
                                            if ($student['status'] === 'Active') $status_class = 'status-active';
                                            elseif ($student['status'] === 'Inactive') $status_class = 'status-inactive';
                                            elseif ($student['status'] === 'Pending') $status_class = 'status-pending';
                                            ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($student['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="#" onclick="viewStudent(<?php echo $student['id']; ?>); return false;" class="text-primary-600 hover:text-primary-900 mr-3">
                                                <i class="fas fa-eye"></i> View
                                            </a>
                                            <a href="#" onclick="editStudent(<?php echo $student['id']; ?>); return false;" class="text-blue-600 hover:text-blue-900 mr-3">
                                                <i class="fas fa-edit"></i> Edit
                                            </a>
                                            <a href="#" onclick="showDeleteModal(<?php echo $student['id']; ?>, '<?php echo addslashes(htmlspecialchars($student['first_name'] . ' ' . $student['last_name'])); ?>'); return false;" class="text-red-600 hover:text-red-900">
                                                <i class="fas fa-trash-alt"></i> Delete
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                                        No students found. <a href="add_student.php" class="text-primary-600 hover:text-primary-800">Add a new student</a> to get started.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <!-- Pagination placeholder (implement as needed) -->
                <!-- <div class="px-6 py-4 border-t border-gray-200">
                    <nav class="flex items-center justify-between" aria-label="Pagination">
                        <div class="hidden sm:block">
                            <p class="text-sm text-gray-700">
                                Showing <span class="font-medium">1</span> to <span class="font-medium">10</span> of <span class="font-medium">50</span> results
                            </p>
                        </div>
                        <div class="flex justify-end space-x-2">
                            <a href="#" class="px-3 py-2 border rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Previous</a>
                            <a href="#" class="px-3 py-2 border rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">Next</a>
                        </div>
                    </nav>
                </div> -->
            </div>
        </main>
    </div>

    <!-- Edit Student Modal -->
    <div id="editStudentModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-2/3 xl:w-1/2 shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center pb-3 border-b">
                <h3 class="text-xl font-semibold text-gray-800">Edit Student Information</h3>
                <button onclick="closeEditModal()" class="text-gray-500 hover:text-gray-700 focus:outline-none">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            <div class="modal-edit-body overflow-y-auto max-h-[70vh] py-4">
                <!-- Edit form will be loaded here -->
                <div class="animate-pulse text-center py-10">
                    <i class="fas fa-spinner fa-spin text-4xl text-primary-600 mb-4"></i>
                    <p class="text-gray-600">Loading student information...</p>
                </div>
            </div>
            <div class="mt-4 flex justify-end space-x-3 pt-3 border-t border-gray-200">
                <button onclick="closeEditModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    Cancel
                </button>
                <button id="saveStudentBtn" onclick="saveStudent()" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-save mr-1"></i> Save Changes
                </button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteStudentModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md transform transition-all">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">Confirm Deletion</h3>
                    <button type="button" onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-500 focus:outline-none">
                        <i class="fas fa-times h-5 w-5"></i>
                    </button>
                </div>
            </div>
            <div class="p-6">
                <p class="text-gray-700 mb-6">
                    Are you sure you want to delete <span id="studentNameToDelete" class="font-semibold text-red-600"></span>? This action cannot be undone.
                </p>
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeDeleteModal()" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors">
                        Cancel
                    </button>
                    <button id="confirmDeleteBtn" type="button" class="px-4 py-2 text-sm font-medium text-white bg-red-600 border border-transparent rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 transition-colors flex items-center">
                        <i class="fas fa-trash-alt mr-2"></i> Delete
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Sync Students Modal -->
    <div id="syncStudentsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-md transform transition-all">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">Sync Students</h3>
                    <button type="button" onclick="closeSyncModal()" class="text-gray-400 hover:text-gray-500 focus:outline-none">
                        <i class="fas fa-times h-5 w-5"></i>
                    </button>
                </div>
            </div>
            <div class="p-6">
                <div id="syncStatus" class="mb-4">
                    <p class="text-gray-700 mb-4">This will synchronize student data from the main student database. This process may take a few moments.</p>
                    <div class="flex items-center space-x-2 text-blue-600" id="syncLoader" style="display: none;">
                        <i class="fas fa-spinner fa-spin"></i>
                        <span>Syncing students, please wait...</span>
                    </div>
                    <div id="syncResult" class="mt-4 hidden">
                        <div class="p-3 rounded-md bg-green-50">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-check-circle text-green-400"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-green-800">
                                        Sync completed successfully!
                                    </p>
                                    <p class="text-sm text-green-700 mt-1" id="syncResultDetails"></p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="syncError" class="mt-4 hidden">
                        <div class="p-3 rounded-md bg-red-50">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-circle text-red-400"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm font-medium text-red-800">
                                        Sync failed
                                    </p>
                                    <p class="text-sm text-red-700 mt-1" id="syncErrorDetails">
                                        An error occurred while syncing students. Please try again.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeSyncModal()" id="cancelSyncBtn" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors">
                        Cancel
                    </button>
                    <button type="button" onclick="startSync()" id="confirmSyncBtn" class="px-4 py-2 text-sm font-medium text-white bg-blue-600 border border-transparent rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors flex items-center">
                        <i class="fas fa-sync-alt mr-2"></i> Start Sync
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- View Student Modal -->
    <div id="viewStudentModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
            <div class="flex justify-between items-center pb-3">
                <h3 class="text-xl font-semibold text-gray-800">Student Details</h3>
                <button onclick="closeModal()" class="text-gray-500 hover:text-gray-700 focus:outline-none">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body overflow-y-auto max-h-[70vh]">
                <!-- Student details will be loaded here -->
                <div class="animate-pulse text-center py-10">
                    <i class="fas fa-spinner fa-spin text-4xl text-primary-600 mb-4"></i>
                    <p class="text-gray-600">Loading student details...</p>
                </div>
            </div>
            <div class="mt-4 flex justify-end space-x-3 pt-3 border-t border-gray-200">
                <button onclick="closeModal()" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                    Close
                </button>
            </div>
        </div>
    </div>

    <script>
        // Function to open the modal and load student details
        function viewStudent(studentId) {
            const modal = document.getElementById('viewStudentModal');
            const modalBody = document.querySelector('.modal-body');
            
            // Show loading state
            modalBody.innerHTML = `
                <div class="animate-pulse text-center py-10">
                    <i class="fas fa-spinner fa-spin text-4xl text-primary-600 mb-4"></i>
                    <p class="text-gray-600">Loading student details...</p>
                </div>
            `;
            
            // Show modal
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            
            // Fetch student details via AJAX
            fetch(`get_student_details.php?id=${studentId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    
                    // Format the student details
                    const statusClass = getStatusClass(data.status);
                    const formattedDob = new Date(data.birthdate).toLocaleDateString('en-US', { 
                        year: 'numeric', 
                        month: 'long', 
                        day: 'numeric' 
                    });
                    
                    // Update modal content with student details
                    modalBody.innerHTML = `
                        <div class="space-y-6">
                            <!-- Student Header -->
                            <div class="flex items-center space-x-4 border-b pb-4">
                                <div class="flex-shrink-0 h-16 w-16 rounded-full bg-primary-100 flex items-center justify-center text-primary-600 text-2xl font-bold">
                                    ${data.first_name.charAt(0)}${data.last_name.charAt(0)}
                                </div>
                                <div>
                                    <h4 class="text-xl font-bold text-gray-900">${data.last_name}, ${data.first_name} ${data.middle_name || ''}</h4>
                                    <p class="text-gray-600">${data.student_id} • Grade ${data.grade_level}</p>
                                    <span class="mt-1 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${statusClass}">
                                        ${data.status}
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Personal Information -->
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <h5 class="text-sm font-medium text-gray-500">Date of Birth</h5>
                                    <p class="mt-1 text-sm text-gray-900">${formattedDob}</p>
                                </div>
                                <div>
                                    <h5 class="text-sm font-medium text-gray-500">Gender</h5>
                                    <p class="mt-1 text-sm text-gray-900">${data.gender || 'N/A'}</p>
                                </div>
                                <div>
                                    <h5 class="text-sm font-medium text-gray-500">Address</h5>
                                    <p class="mt-1 text-sm text-gray-900">${data.address || 'N/A'}</p>
                                </div>
                                <div>
                                    <h5 class="text-sm font-medium text-gray-500">Contact Number</h5>
                                    <p class="mt-1 text-sm text-gray-900">${data.contact_number || 'N/A'}</p>
                                </div>
                            </div>
                            
                            <!-- Guardian Information -->
                            <div class="border-t pt-4">
                                <h5 class="text-sm font-medium text-gray-700 mb-3">Guardian Information</h5>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <h5 class="text-sm font-medium text-gray-500">Guardian Name</h5>
                                        <p class="mt-1 text-sm text-gray-900">${data.guardian_name || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <h5 class="text-sm font-medium text-gray-500">Relationship</h5>
                                        <p class="mt-1 text-sm text-gray-900">${data.guardian_relationship || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <h5 class="text-sm font-medium text-gray-500">Contact Number</h5>
                                        <p class="mt-1 text-sm text-gray-900">${data.guardian_contact || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <h5 class="text-sm font-medium text-gray-500">Email</h5>
                                        <p class="mt-1 text-sm text-gray-900">${data.email || 'N/A'}</p>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Additional Information -->
                            <div class="border-t pt-4">
                                <h5 class="text-sm font-medium text-gray-700 mb-3">Additional Information</h5>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <h5 class="text-sm font-medium text-gray-500">LRN (Learner Reference Number)</h5>
                                        <p class="mt-1 text-sm text-gray-900">${data.lrn || 'N/A'}</p>
                                    </div>
                                    <div>
                                        <h5 class="text-sm font-medium text-gray-500">Enrollment Date</h5>
                                        <p class="mt-1 text-sm text-gray-900">${data.enrollment_date ? new Date(data.enrollment_date).toLocaleDateString() : 'N/A'}</p>
                                    </div>
                                    <div class="md:col-span-2">
                                        <h5 class="text-sm font-medium text-gray-500">Notes</h5>
                                        <p class="mt-1 text-sm text-gray-900">${data.notes || 'No additional notes.'}</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `
                        <div class="text-center py-10">
                            <i class="fas fa-exclamation-circle text-red-500 text-4xl mb-4"></i>
                            <p class="text-red-600 font-medium">Failed to load student details</p>
                            <p class="text-gray-600 text-sm mt-2">${error.message || 'Please try again later.'}</p>
                        </div>
                    `;
                });
        }
        
        // Function to close the modal
        function closeModal() {
            const modal = document.getElementById('viewStudentModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
        
        // Helper function to get status class
        function getStatusClass(status) {
            switch(status) {
                case 'Active': return 'bg-green-100 text-green-800';
                case 'Inactive': return 'bg-red-100 text-red-800';
                case 'Pending': return 'bg-yellow-100 text-yellow-800';
                default: return 'bg-gray-100 text-gray-800';
            }
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const viewModal = document.getElementById('viewStudentModal');
            const editModal = document.getElementById('editStudentModal');
            const deleteModal = document.getElementById('deleteStudentModal');
            const syncModal = document.getElementById('syncStudentsModal');
            
            if (event.target === viewModal) {
                closeModal();
            }
            if (event.target === editModal) {
                closeEditModal();
            }
            if (event.target === deleteModal) {
                closeDeleteModal();
            }
            if (event.target === syncModal) {
                closeSyncModal();
            }
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            const viewModal = document.getElementById('viewStudentModal');
            const editModal = document.getElementById('editStudentModal');
            const deleteModal = document.getElementById('deleteStudentModal');
            const syncModal = document.getElementById('syncStudentsModal');
            
            if (event.key === 'Escape') {
                if (!viewModal.classList.contains('hidden')) {
                    closeModal();
                }
                if (!editModal.classList.contains('hidden')) {
                    closeEditModal();
                }
                if (!deleteModal.classList.contains('hidden')) {
                    closeDeleteModal();
                }
                if (!syncModal.classList.contains('hidden')) {
                    closeSyncModal();
                }
            }
        });
        // Function to open edit modal
        function editStudent(studentId) {
            const modal = document.getElementById('editStudentModal');
            const modalBody = document.querySelector('.modal-edit-body');
            
            // Show loading state
            modalBody.innerHTML = `
                <div class="animate-pulse text-center py-10">
                    <i class="fas fa-spinner fa-spin text-4xl text-primary-600 mb-4"></i>
                    <p class="text-gray-600">Loading student information...</p>
                </div>
            `;
            
            // Show modal
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            
            // Fetch student edit form
            fetch(`get_edit_form.php?id=${studentId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.text();
                })
                .then(html => {
                    modalBody.innerHTML = html;
                    // Initialize any date pickers or other JS components here if needed
                })
                .catch(error => {
                    console.error('Error:', error);
                    modalBody.innerHTML = `
                        <div class="text-center py-10">
                            <i class="fas fa-exclamation-circle text-red-500 text-4xl mb-4"></i>
                            <p class="text-red-600 font-medium">Failed to load student form</p>
                            <p class="text-gray-600 text-sm mt-2">${error.message || 'Please try again later.'}</p>
                        </div>
                    `;
                });
        }
        
        // Function to close edit modal
        function closeEditModal() {
            const modal = document.getElementById('editStudentModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
        }
        
        // Function to save student data
        function saveStudent() {
            const form = document.getElementById('editStudentForm');
            if (!form) return;
            
            const formData = new FormData(form);
            const studentId = formData.get('id');
            const saveBtn = document.getElementById('saveStudentBtn');
            const originalBtnText = saveBtn.innerHTML;
            
            // Show loading state
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';
            
            fetch('update_student.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Show success message
                    showToast('success', 'Student information updated successfully!');
                    // Close modal and refresh the page after a short delay
                    closeEditModal();
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    throw new Error(data.error || 'Failed to update student');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('error', error.message || 'Failed to update student. Please try again.');
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalBtnText;
            });
        }
        
        // Function to show delete confirmation modal
        let currentStudentIdToDelete = null;
        
        function showDeleteModal(studentId, studentName) {
            currentStudentIdToDelete = studentId;
            const modal = document.getElementById('deleteStudentModal');
            document.getElementById('studentNameToDelete').textContent = studentName;
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            
            // Set focus on the cancel button for better keyboard navigation
            setTimeout(() => {
                const cancelBtn = modal.querySelector('button:first-of-type');
                if (cancelBtn) cancelBtn.focus();
            }, 100);
        }
        
        // Function to close delete modal
        function closeDeleteModal() {
            const modal = document.getElementById('deleteStudentModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
            currentStudentIdToDelete = null;
        }
        
        // Function to show sync modal
        function showSyncModal() {
            const modal = document.getElementById('syncStudentsModal');
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        
        // Function to close sync modal
        function closeSyncModal() {
            const modal = document.getElementById('syncStudentsModal');
            modal.classList.add('hidden');
            document.body.style.overflow = 'auto';
            // Reset sync UI
            document.getElementById('syncLoader').style.display = 'none';
            document.getElementById('syncResult').classList.add('hidden');
            document.getElementById('syncError').classList.add('hidden');
            document.getElementById('confirmSyncBtn').disabled = false;
            document.getElementById('cancelSyncBtn').disabled = false;
        }
        
        // Function to start the sync process
        function startSync() {
            const loader = document.getElementById('syncLoader');
            const confirmBtn = document.getElementById('confirmSyncBtn');
            const cancelBtn = document.getElementById('cancelSyncBtn');
            const resultDiv = document.getElementById('syncResult');
            const errorDiv = document.getElementById('syncError');
            
            // Show loading state
            loader.style.display = 'flex';
            confirmBtn.disabled = true;
            cancelBtn.disabled = true;
            resultDiv.classList.add('hidden');
            errorDiv.classList.add('hidden');
            
            // Make AJAX call to sync endpoint
            fetch('sync_students.php', {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(async response => {
                const data = await response.json().catch(() => {
                    throw new Error('Invalid response from server');
                });
                
                if (!response.ok) {
                    throw new Error(data.error || 'Failed to sync students');
                }
                
                return data;
            })
            .then(data => {
                // Hide loader
                loader.style.display = 'none';
                
                if (data.success) {
                    // Show success message
                    const resultDetails = document.getElementById('syncResultDetails');
                    if (data.stats) {
                        const stats = data.stats;
                        resultDetails.innerHTML = `
                            <div class="mt-2 space-y-1 text-sm">
                                <p>• Inserted: <span class="font-medium">${stats.inserted} students</span></p>
                                <p>• Updated: <span class="font-medium">${stats.updated} students</span></p>
                                ${stats.errors > 0 ? `<p>• Errors: <span class="text-red-600 font-medium">${stats.errors} students</span></p>` : ''}
                                <p>• Total students: <span class="font-medium">${stats.total}</span></p>
                            </div>
                        `;
                    } else if (data.message) {
                        resultDetails.textContent = data.message;
                    } else {
                        resultDetails.textContent = 'Student data has been synchronized successfully.';
                    }
                    resultDiv.classList.remove('hidden');
                    
                    // Reload the page after a short delay to show updated data
                    setTimeout(() => {
                        window.location.reload();
                    }, 3000);
                } else {
                    throw new Error(data.error || 'Failed to sync students');
                }
            })
            .catch(error => {
                console.error('Error syncing students:', error);
                loader.style.display = 'none';
                errorDiv.classList.remove('hidden');
                const errorDetails = document.getElementById('syncErrorDetails');
                errorDetails.textContent = error.message || 'An error occurred while syncing students. Please try again.';
                confirmBtn.disabled = false;
                cancelBtn.disabled = false;
            });
        }
        
        // Handle delete confirmation
        document.addEventListener('DOMContentLoaded', function() {
            const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
            if (confirmDeleteBtn) {
                confirmDeleteBtn.addEventListener('click', function() {
                    if (!currentStudentIdToDelete) return;
                    
                    const btn = this;
                    const originalBtnText = btn.innerHTML;
                    
                    // Show loading state
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Deleting...';
                    
                    // Send delete request
                    fetch(`delete_student.php?id=${currentStudentIdToDelete}`, {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                        }
                    })
                    .then(response => {
                        if (!response.ok) {
                            return response.json().then(err => { throw new Error(err.error || 'Failed to delete student'); });
                        }
                        return response.json();
                    })
                    .then(data => {
                        if (data.success) {
                            showToast('success', 'Student deleted successfully!');
                            closeDeleteModal();
                            // Remove the student row from the table
                            const row = document.querySelector(`tr[data-student-id="${currentStudentIdToDelete}"]`);
                            if (row) {
                                row.style.opacity = '0';
                                setTimeout(() => row.remove(), 300);
                                
                                // If no more students, show empty state
                                const tbody = document.querySelector('tbody');
                                if (tbody && tbody.children.length === 1 && tbody.querySelector('.no-students')) {
                                    // Already showing empty state
                                } else if (tbody && tbody.children.length === 0) {
                                    const emptyRow = document.createElement('tr');
                                    emptyRow.className = 'no-students';
                                    emptyRow.innerHTML = `
                                        <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No students found. <a href="add_student.php" class="text-primary-600 hover:text-primary-800">Add a new student</a> to get started.
                                        </td>
                                    `;
                                    tbody.appendChild(emptyRow);
                                }
                            }
                        } else {
                            throw new Error(data.error || 'Failed to delete student');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showToast('error', error.message || 'Failed to delete student. Please try again.');
                    })
                    .finally(() => {
                        btn.disabled = false;
                        btn.innerHTML = originalBtnText;
                    });
                });
            }
        });
        
        // Function to show toast notifications
        function showToast(type, message) {
            const toast = document.createElement('div');
            toast.className = `fixed top-4 right-4 p-4 rounded-md shadow-lg ${
                type === 'success' ? 'bg-green-500' : 'bg-red-500'
            } text-white`;
            toast.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            document.body.appendChild(toast);
            
            // Remove toast after 5 seconds
            setTimeout(() => {
                toast.remove();
            }, 5000);
        }
        
        // Toggle sidebar for mobile
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('sidebar-open');
                overlay.classList.toggle('overlay-open');
            }
        }

        // Close sidebar
        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            if (sidebar && overlay) {
                sidebar.classList.remove('sidebar-open');
                overlay.classList.remove('overlay-open');
            }
        }

        // Toggle user dropdown menu
        function toggleUserMenu() {
            const userMenu = document.getElementById('user-menu');
            const userButton = document.getElementById('user-menu-button');
            if (userMenu && userButton) {
                userMenu.classList.toggle('hidden');
                const isExpanded = !userMenu.classList.contains('hidden');
                userButton.setAttribute('aria-expanded', isExpanded);
            }
        }

        // Toggle notifications panel
        function toggleNotifications() {
            const notificationPanel = document.getElementById('notification-panel');
            if (notificationPanel) {
                notificationPanel.classList.toggle('open');
            }
        }

        // Toggle submenu
        function toggleSubmenu(submenuId, element) {
            event.preventDefault();
            const submenu = document.getElementById(submenuId);
            const chevron = element.querySelector('.fa-chevron-down');
            if (submenu && chevron) {
                submenu.classList.toggle('open');
                chevron.classList.toggle('rotate-90');
            }
        }

        // Toggle sidebar collapse
        function toggleSidebarCollapse() {
            const sidebar = document.getElementById('sidebar');
            const collapseIcon = document.getElementById('collapse-icon');
            if (sidebar && collapseIcon) {
                sidebar.classList.toggle('collapsed');
                
                if (sidebar.classList.contains('collapsed')) {
                    collapseIcon.classList.remove('fa-chevron-left');
                    collapseIcon.classList.add('fa-chevron-right');
                    const collapseText = document.querySelector('.sidebar-text');
                    if (collapseText) collapseText.textContent = 'Expand Sidebar';
                } else {
                    collapseIcon.classList.remove('fa-chevron-right');
                    collapseIcon.classList.add('fa-chevron-left');
                    const collapseText = document.querySelector('.sidebar-text');
                    if (collapseText) collapseText.textContent = 'Collapse Sidebar';
                }
            }
        }

        // Confirm delete student
        function confirmDelete(studentId) {
            if (confirm('Are you sure you want to delete this student? This action cannot be undone.')) {
                window.location.href = 'delete_student.php?id=' + studentId;
            }
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to log out?')) {
                window.location.href = '../logout.php';
            }
        }

        // Handle clicks outside dropdowns
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('user-menu');
            const userButton = document.getElementById('user-menu-button');
            if (userMenu && userButton && !userMenu.contains(event.target) && !userButton.contains(event.target)) {
                userMenu.classList.add('hidden');
                userButton.setAttribute('aria-expanded', 'false');
            }
            
            const notificationPanel = document.getElementById('notification-panel');
            const notificationButton = document.getElementById('notification-btn');
            if (notificationPanel && notificationButton && !notificationPanel.contains(event.target) && !notificationButton.contains(event.target)) {
                notificationPanel.classList.remove('open');
            }
        });

        // Handle keyboard accessibility for user menu
        document.addEventListener('keydown', function(event) {
            const userMenu = document.getElementById('user-menu');
            const userButton = document.getElementById('user-menu-button');
            if (userButton && (event.key === 'Enter' || event.key === ' ')) {
                if (event.target === userButton) {
                    event.preventDefault();
                    toggleUserMenu();
                }
            }
            if (event.key === 'Escape' && userMenu && !userMenu.classList.contains('hidden')) {
                userMenu.classList.add('hidden');
                userButton.setAttribute('aria-expanded', 'false');
                userButton.focus();
            }
        });

        // Initialize on page load
        // Search students with debounce
        let searchTimeout;
        
        function searchStudents(query) {
            const searchLoading = document.getElementById('search-loading');
            const searchInfo = document.getElementById('search-info');
            
            // Show loading indicator
            searchLoading.classList.remove('hidden');
            searchInfo.textContent = 'Searching...';
            
            // Clear any existing timeout
            clearTimeout(searchTimeout);
            
            // Set a new timeout
            searchTimeout = setTimeout(() => {
                if (query.length === 0) {
                    // If search is empty, reload the page to show all students
                    window.location.href = 'view_students.php';
                    return;
                }
                
                // Make AJAX request to search
                fetch(`search_students.php?search=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        // Update the table with search results
                        updateStudentsTable(data.students);
                        searchInfo.textContent = data.message || `Found ${data.students.length} students`;
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        searchInfo.textContent = 'Error performing search';
                    })
                    .finally(() => {
                        searchLoading.classList.add('hidden');
                    });
            }, 300); // 300ms debounce
        }
        
        function updateStudentsTable(students) {
            const tbody = document.querySelector('tbody');
            
            if (students.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                            No students found. <a href="add_student.php" class="text-primary-600 hover:text-primary-800">Add a new student</a>
                        </td>
                    </tr>`;
                return;
            }
            
            // Clear existing rows
            tbody.innerHTML = '';
            
            // Add new rows
            students.forEach(student => {
                const row = document.createElement('tr');
                row.className = 'hover:bg-gray-50 transition-opacity duration-300';
                row.dataset.studentId = student.id;
                
                row.innerHTML = `
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                        ${student.student_id || 'N/A'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 h-10 w-10 rounded-full bg-primary-100 flex items-center justify-center text-primary-600 font-medium">
                                ${student.first_name ? student.first_name.charAt(0) : ''}${student.last_name ? student.last_name.charAt(0) : ''}
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900">
                                    ${student.first_name} ${student.last_name}
                                </div>
                                <div class="text-sm text-gray-500">
                                    ${student.email || ''}
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        Grade ${student.grade_level || 'N/A'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${getStatusBadgeClass(student.enrollment_status || 'Active')}">
                            ${student.enrollment_status || 'Active'}
                        </span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                        ${student.section || 'N/A'}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        <div class="flex items-center space-x-2 justify-end">
                            <button onclick="viewStudent(${student.id})" class="text-blue-600 hover:text-blue-900 mr-2">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button onclick="editStudent(${student.id})" class="text-yellow-600 hover:text-yellow-900 mr-2">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="showDeleteModal(${student.id}, '${student.first_name} ${student.last_name}')" class="text-red-600 hover:text-red-900">
                                <i class="fas fa-trash-alt"></i>
                            </button>
                        </div>
                    </td>`;
                
                tbody.appendChild(row);
            });
        }
        
        // Handle Enter key in search
        document.getElementById('search').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchStudents(this.value);
            }
        });
        
        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            console.log('San Agustin Elementary School View Students loaded');
            
            // If there's a search term in the URL, trigger the search
            const urlParams = new URLSearchParams(window.location.search);
            const searchTerm = urlParams.get('search');
            if (searchTerm) {
                const searchInput = document.getElementById('search');
                searchInput.value = searchTerm;
                searchStudents(searchTerm);
            }
        });
    </script>
</body>
</html>