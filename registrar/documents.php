<?php
session_start();

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('Location: ../login.php');
    exit();
}

// Database connection
require_once '../config/database.php';

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');

// Initialize stats array
$stats = [
    'pending_documents' => 0,
    'new_applications' => 0
];

try {
    if ($pdo) {
        // Get pending document requests count
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM document_requests WHERE status = 'pending'");
        if ($stmt) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['pending_documents'] = $result['count'] ?? 0;
        }
        
        // Get new enrollment applications count
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM enrollment_requests WHERE status = 'pending'");
        if ($stmt) {
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['new_applications'] = $result['count'] ?? 0;
        }
    } else {
        error_log("Failed to establish database connection");
    }
} catch (PDOException $e) {
    error_log("Error fetching statistics: " . $e->getMessage());
}

// Get registrar info for the header
$registrar_id_display = 'R' . $_SESSION['user_id'];
$initials = 'R' . substr($_SESSION['user_id'], -1);
$registrar = [
    'first_name' => 'Registrar',
    'last_name' => '',
    'contact_number' => ''
];
try {
    $stmt = $pdo->prepare("SELECT first_name, last_name, contact_number FROM registrars WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $registrar = $stmt->fetch(PDO::FETCH_ASSOC) ?: $registrar;
} catch (PDOException $e) {
    error_log("Error fetching registrar info: " . $e->getMessage());
}

// Get document requests
$status_filter = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';
$document_requests = [];

try {
    $query = "SELECT dr.*, 
                     CONCAT(s.last_name, ', ', s.first_name) as student_name, 
                     s.grade_level, s.section, s.lrn 
              FROM document_requests dr 
              JOIN students s ON dr.student_id = s.id 
              WHERE 1=1";
    
    $params = [];
    
    if ($status_filter !== 'all') {
        $query .= " AND dr.status = ?";
        $params[] = $status_filter;
    }
    
    if (!empty($search_query)) {
        $query .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR dr.request_number LIKE ? OR s.lrn LIKE ?)";
        $search_param = "%$search_query%";
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    $query .= " ORDER BY dr.request_date DESC";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $document_requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching document requests: " . $e->getMessage());
}

// Determine current page
$current_page = basename($_SERVER['PHP_SELF']);
$page_title = 'Document Management';
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
        .status-pending {
            background-color: #fef3c7;
            color: #b45309;
        }
        .status-processing {
            background-color: #dbeafe;
            color: #1e40af;
        }
        .status-ready {
            background-color: #d1fae5;
            color: #065f46;
        }
        .status-released {
            background-color: #d4d4d4;
            color: #4b5563;
        }
        .status-cancelled {
            background-color: #fee2e2;
            color: #b91c1c;
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
                    <?php echo htmlspecialchars($initials); ?>
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
                    <a href="dashboard.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'dashboard.php' ? 'bg-primary-600 text-white shadow-md' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-home w-5"></i>
                        <span class="ml-3 sidebar-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('students-submenu', this)">
                        <i class="fas fa-user-graduate w-5"></i>
                        <span class="ml-3 sidebar-text">Student Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text transition-transform" id="students-submenu-icon"></i>
                    </a>
                    <div id="students-submenu" class="submenu pl-4 mt-1">
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
                    <a href="enrollment.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'enrollment.php' ? 'bg-primary-600 text-white shadow-md' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-clipboard-list w-5"></i>
                        <span class="ml-3 sidebar-text">Enrollment</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('sections-submenu', this)">
                        <i class="fas fa-chalkboard w-5"></i>
                        <span class="ml-3 sidebar-text">Class Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text transition-transform" id="sections-submenu-icon"></i>
                    </a>
                    <div id="sections-submenu" class="submenu pl-4 mt-1">
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
                    <a href="attendance.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'attendance.php' ? 'bg-primary-600 text-white shadow-md' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-calendar-check w-5"></i>
                        <span class="ml-3 sidebar-text">Attendance</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('reports-submenu', this)">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text transition-transform <?php echo in_array($current_page, ['enrollment_reports.php', 'demographic_reports.php', 'transcript_requests.php', 'documents.php']) ? 'rotate-90' : ''; ?>" id="reports-submenu-icon"></i>
                    </a>
                    <div id="reports-submenu" class="submenu pl-4 mt-1 <?php echo in_array($current_page, ['enrollment_reports.php', 'demographic_reports.php', 'transcript_requests.php', 'documents.php']) ? 'open' : ''; ?>">
                        <a href="enrollment_reports.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'enrollment_reports.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-file-alt w-5"></i>
                            <span class="ml-3 sidebar-text">Enrollment Reports</span>
                        </a>
                        <a href="demographic_reports.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'demographic_reports.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-chart-pie w-5"></i>
                            <span class="ml-3 sidebar-text">Demographic Reports</span>
                        </a>
                        <a href="transcript_requests.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'transcript_requests.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-file-certificate w-5"></i>
                            <span class="ml-3 sidebar-text">Transcript Requests</span>
                        </a>
                    </div>
                </li>
                <li>
                    <a href="documents.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'documents.php' ? 'bg-primary-600 text-white shadow-md' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-file-archive w-5"></i>
                        <span class="ml-3 sidebar-text">Document Management</span>
                    </a>
                </li>
            </ul>
            <div class="mt-10 p-4 bg-secondary-800 rounded-lg events-container">
                <h3 class="text-sm font-bold text-white mb-3 flex items-center events-title">
                    <i class="fas fa-calendar-day mr-2"></i>Upcoming Deadlines
                </h3>
                <div class="space-y-3 event-details">
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">20</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Enrollment Deadline</p>
                            <p class="text-xs text-secondary-300">SY 2023-2024</p>
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
                <h1 class="text-xl font-bold">Document Management</h1>
            </div>
            
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <button id="notification-btn" class="relative p-2 text-white hover:bg-primary-600 rounded-full focus:outline-none" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <span class="absolute top-0 right-0 h-4 w-4 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">
                            <?php echo ($stats['pending_documents'] ?? 0) + ($stats['new_applications'] ?? 0); ?>
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
                            <?php if (($stats['new_applications'] ?? 0) > 0): ?>
                            <a href="enrollment.php?status=pending" class="block p-4 hover:bg-gray-50">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 pt-0.5">
                                        <div class="h-10 w-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
                                            <i class="fas fa-clipboard-list"></i>
                                        </div>
                                    </div>
                                    <div class="ml-3 flex-1">
                                        <p class="text-sm font-medium text-gray-900"><?php echo ($stats['new_applications'] ?? 0); ?> new enrollment <?php echo ($stats['new_applications'] ?? 0) > 1 ? 'applications' : 'application'; ?></p>
                                        <p class="mt-1 text-sm text-gray-500">Click to review pending applications</p>
                                        <p class="mt-1 text-xs text-gray-400">Just now</p>
                                    </div>
                                </div>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (($stats['pending_documents'] ?? 0) > 0): ?>
                            <a href="documents.php?status=pending" class="block p-4 hover:bg-gray-50">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 pt-0.5">
                                        <div class="h-10 w-10 rounded-full bg-yellow-100 text-yellow-600 flex items-center justify-center">
                                            <i class="fas fa-file-alt"></i>
                                        </div>
                                    </div>
                                    <div class="ml-3 flex-1">
                                        <p class="text-sm font-medium text-gray-900"><?php echo ($stats['pending_documents'] ?? 0); ?> document <?php echo ($stats['pending_documents'] ?? 0) > 1 ? 'requests' : 'request'; ?> pending</p>
                                        <p class="mt-1 text-sm text-gray-500">Needs your attention</p>
                                        <p class="mt-1 text-xs text-gray-400">5 min ago</p>
                                    </div>
                                </div>
                            </a>
                            <?php endif; ?>
                            
                            <?php if ((empty($stats['new_applications']) || $stats['new_applications'] == 0) && (empty($stats['pending_documents']) || $stats['pending_documents'] == 0)): ?>
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
                    <button id="user-menu-button" class="flex items-center space-x-2 focus:outline-none" onclick="toggleUserMenu()">
                        <div class="h-8 w-8 rounded-full bg-primary-600 flex items-center justify-center text-white font-medium">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <span class="hidden md:inline-block text-white">Registrar</span>
                        <i class="fas fa-chevron-down text-xs text-white transition-transform" id="user-menu-icon"></i>
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
                        <a href="../logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100" onclick="return confirm('Are you sure you want to log out?');">
                            <i class="fas fa-sign-out-alt mr-2 w-5"></i> Sign out
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-6">
                <h1 class="text-2xl font-bold text-gray-800">Document Management</h1><br>
                <!-- Filters and Actions -->
                <div class="bg-white shadow rounded-lg p-6 mb-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                        <div class="flex space-x-2">
                            <div class="relative">
                                <select id="status-filter" class="block appearance-none bg-white border border-gray-300 text-gray-700 py-2 px-4 pr-8 rounded leading-tight focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="processing" <?php echo $status_filter === 'processing' ? 'selected' : ''; ?>>Processing</option>
                                    <option value="ready" <?php echo $status_filter === 'ready' ? 'selected' : ''; ?>>Ready for Pickup</option>
                                    <option value="released" <?php echo $status_filter === 'released' ? 'selected' : ''; ?>>Released</option>
                                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                                <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center px-2 text-gray-700">
                                    <i class="fas fa-chevron-down text-xs"></i>
                                </div>
                            </div>
                        </div>
                        
                        <div class="relative">
                            <div class="flex rounded-md shadow-sm">
                                <input type="text" id="search-query" class="focus:ring-blue-500 focus:border-blue-500 block w-full pl-4 pr-10 py-2 sm:text-sm border-gray-300 rounded-l-md" placeholder="Search by name, LRN, or request #" value="<?php echo htmlspecialchars($search_query); ?>">
                                <button id="search-btn" class="-ml-px relative inline-flex items-center px-4 py-2 border border-l-0 border-gray-300 bg-gray-50 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500 rounded-r-md">
                                    <i class="fas fa-search"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Document Requests Table -->
                <div class="bg-white shadow rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">Document Requests</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Request #</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document Type</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Request Date</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($document_requests)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">
                                        No document requests found.
                                    </td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach ($document_requests as $request): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($request['request_number']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($request['student_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                Grade <?php echo htmlspecialchars($request['grade_level'] . ' - ' . $request['section']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($request['document_type']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('M j, Y', strtotime($request['request_date'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leadingVirtus Technologies, Inc. 5 font-semibold rounded-full status-<?php echo $request['status']; ?>">
                                                <?php echo ucfirst($request['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="document_details.php?id=<?php echo $request['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                            <?php if ($request['status'] === 'pending' || $request['status'] === 'processing'): ?>
                                            <button class="text-indigo-600 hover:text-indigo-900 update-status" data-id="<?php echo $request['id']; ?>" data-status="<?php echo $request['status']; ?>">
                                                Update Status
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <!-- Pagination -->
                    <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-gray-700">
                                    Showing <span class="font-medium">1</span> to <span class="font-medium">10</span> of <span class="font-medium">20</span> results
                                </p>
                            </div>
                            <div>
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                    <a href="#" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <span class="sr-only">Previous</span>
                                        <i class="fas fa-chevron-left h-5 w-5"></i>
                                    </a>
                                    <a href="#" aria-current="page" class="z-10 bg-blue-50 border-blue-500 text-blue-600 relative inline-flex items-center px-4 py-2 border text-sm font-medium">
                                        1
                                    </a>
                                    <a href="#" class="bg-white border-gray-300 text-gray-500 hover:bg-gray-50 relative inline-flex items-center px-4 py-2 border text-sm font-medium">
                                        2
                                    </a>
                                    <a href="#" class="bg-white border-gray-300 text-gray-500 hover:bg-gray-50 relative inline-flex items-center px-4 py-2 border text-sm font-medium">
                                        3
                                    </a>
                                    <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">
                                        ...
                                    </span>
                                    <a href="#" class="bg-white border-gray-300 text-gray-500 hover:bg-gray-50 relative inline-flex items-center px-4 py-2 border text-sm font-medium">
                                        8
                                    </a>
                                    <a href="#" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <span class="sr-only">Next</span>
                                        <i class="fas fa-chevron-right h-5 w-5"></i>
                                    </a>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="status-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <h3 class="text-lg leading-6 font-medium text-gray-900">Update Document Status</h3>
                <div class="mt-2 px-7 py-3">
                    <input type="hidden" id="request-id">
                    <div class="mb-4">
                        <label for="new-status" class="block text-sm font-medium text-gray-700 text-left mb-1">Status</label>
                        <select id="new-status" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                            <option value="processing">Processing</option>
                            <option value="ready">Ready for Pickup</option>
                            <option value="released">Released</option>
                            <option value="cancelled">Cancelled</option>
                        </select>
                    </div>
                    <div class="mb-4" id="released-by-container" style="display: none;">
                        <label for="released-by" class="block text-sm font-medium text-gray-700 text-left mb-1">Released By</label>
                        <input type="text" id="released-by" class="mt-1 block w-full pl-3 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" value="<?php echo htmlspecialchars($registrar['first_name'] . ' ' . $registrar['last_name']); ?> (You)" readonly>
                    </div>
                    <div class="mb-4">
                        <label for="status-notes" class="block text-sm font-medium text-gray-700 text-left mb-1">Notes (Optional)</label>
                        <textarea id="status-notes" rows="3" class="shadow-sm focus:ring-blue-500 focus:border-blue-500 mt-1 block w-full sm:text-sm border border-gray-300 rounded-md"></textarea>
                    </div>
                </div>
                <div class="px-4 py-3 flex justify-between">
                    <button id="cancel-update" type="button" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500">
                        Cancel
                    </button>
                    <button id="save-status" type="button" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Save Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Apply filters when status or document type changes
        document.getElementById('status-filter').addEventListener('change', applyFilters);
        document.getElementById('document-type-filter').addEventListener('change', applyFilters);
        document.getElementById('search-query').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyFilters();
            }
        });
        document.getElementById('search-btn').addEventListener('click', applyFilters);

        function applyFilters() {
            const status = document.getElementById('status-filter').value;
            const docType = document.getElementById('document-type-filter').value;
            const search = document.getElementById('search-query').value;
            
            let url = 'documents.php?';
            url += `status=${status}`;
            
            if (docType !== 'all') {
                url += `&type=${encodeURIComponent(docType)}`;
            }
            
            if (search) {
                url += `&search=${encodeURIComponent(search)}`;
            }
            
            window.location.href = url;
        }

        // Update status modal
        const statusModal = document.getElementById('status-modal');
        const updateStatusBtns = document.querySelectorAll('.update-status');
        const cancelUpdateBtn = document.getElementById('cancel-update');
        const saveStatusBtn = document.getElementById('save-status');
        const requestIdInput = document.getElementById('request-id');
        const newStatusSelect = document.getElementById('new-status');
        
        updateStatusBtns.forEach(btn => {
            btn.addEventListener('click', function() {
                const requestId = this.getAttribute('data-id');
                const currentStatus = this.getAttribute('data-status');
                
                requestIdInput.value = requestId;
                newStatusSelect.value = currentStatus === 'pending' ? 'processing' : currentStatus;
                
                // Show/hide released by field based on status
                toggleReleasedByField();
                
                statusModal.classList.remove('hidden');
            });
        });
        
        newStatusSelect.addEventListener('change', toggleReleasedByField);
        
        function toggleReleasedByField() {
            const releasedByContainer = document.getElementById('released-by-container');
            if (newStatusSelect.value === 'released') {
                releasedByContainer.style.display = 'block';
            } else {
                releasedByContainer.style.display = 'none';
            }
        }
        
        cancelUpdateBtn.addEventListener('click', function() {
            statusModal.classList.add('hidden');
        });
        
        saveStatusBtn.addEventListener('click', function() {
            const requestId = requestIdInput.value;
            const newStatus = newStatusSelect.value;
            const notes = document.getElementById('status-notes').value;
            
            // Here you would typically make an AJAX call to update the status
            console.log(`Updating request ${requestId} to status ${newStatus} with notes: ${notes}`);
            
            // For demo purposes, just close the modal and reload the page
            statusModal.classList.add('hidden');
            window.location.reload();
        });
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            if (event.target === statusModal) {
                statusModal.classList.add('hidden');
            }
        });
        
        // Toggle sidebar on mobile
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            sidebar.classList.toggle('sidebar-open');
            overlay.classList.toggle('overlay-open');
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            sidebar.classList.remove('sidebar-open');
            overlay.classList.remove('overlay-open');
        }

        // Toggle user dropdown
        function toggleUserMenu() {
            const menu = document.getElementById('user-menu');
            menu.classList.toggle('hidden');
            const icon = document.getElementById('user-menu-icon');
            icon.classList.toggle('rotate-90');
        }

        // Toggle notifications panel
        function toggleNotifications() {
            const panel = document.getElementById('notification-panel');
            panel.classList.toggle('open');
        }

        // Toggle submenus
        function toggleSubmenu(submenuId, element) {
            const submenu = document.getElementById(submenuId);
            const icon = element.querySelector('.fa-chevron-down');
            
            submenu.classList.toggle('open');
            icon.classList.toggle('rotate-90');
        }

        // Toggle sidebar collapse
        function toggleSidebarCollapse() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('collapsed');
            
            // Save preference to localStorage
            const isCollapsed = sidebar.classList.contains('collapsed');
            localStorage.setItem('sidebarCollapsed', isCollapsed);
            document.getElementById('collapse-icon').classList.toggle('rotate-90');
        }

        // Close dropdowns when clicking outside
        document.addEventListener('click', function(event) {
            // User menu
            const userMenu = document.getElementById('user-menu');
            const userButton = document.getElementById('user-menu-button');
            
            if (userMenu && !userMenu.contains(event.target) && !userButton.contains(event.target)) {
                userMenu.classList.add('hidden');
                document.getElementById('user-menu-icon').classList.remove('rotate-90');
            }
            
            // Notifications panel
            const notificationPanel = document.getElementById('notification-panel');
            const notificationButton = document.getElementById('notification-btn');
            
            if (notificationPanel && !notificationPanel.contains(event.target) && !notificationButton.contains(event.target)) {
                notificationPanel.classList.remove('open');
            }
        });

        // Initialize sidebar state from localStorage
        document.addEventListener('DOMContentLoaded', function() {
            // Check if sidebar was collapsed
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                document.getElementById('sidebar').classList.add('collapsed');
                document.getElementById('collapse-icon').classList.add('rotate-90');
            }
            
            // Close sidebar when clicking on a nav item on mobile
            const navItems = document.querySelectorAll('.nav-item');
            navItems.forEach(item => {
                item.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeSidebar();
                    }
                });
            });
        });
    </script>
</body>
</html>