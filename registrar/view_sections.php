<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('Location: ../login.php');
    exit();
}
$registrar_id_display = 'R' . $_SESSION['user_id'];
$initials = 'R' . substr($_SESSION['user_id'], -1); // Get last digit for initials

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');
$login_pdo = $database->getConnection('login');

$sections = [];
$error = '';

// Initialize stats array
$stats = [
    'pending_documents' => 0,
    'new_applications' => 0,
    'total_students' => 0,
    'active_sections' => 0
];

// Get registrar info for header
$registrar = [];
try {
    // Verify user exists in login database
    $stmt = $login_pdo->prepare("SELECT * FROM users WHERE id = :user_id AND role = 'registrar'");
    $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception("User not found or not authorized as registrar");
    }
    
    // Get registrar details
    $stmt = $pdo->prepare("SELECT * FROM registrars WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $registrar = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Set initials from registrar name if available
    if (!empty($registrar['first_name'])) {
        $initials = strtoupper(substr($registrar['first_name'], 0, 1));
        if (!empty($registrar['last_name'])) {
            $initials .= strtoupper(substr($registrar['last_name'], 0, 1));
        }
    } else {
        $initials = strtoupper(substr($_SESSION['username'] ?? 'R', 0, 2));
    }
} catch (Exception $e) {
    error_log("Error fetching registrar info: " . $e->getMessage());
    $error = "Error fetching registrar information. Please try again later.";
}

// Get statistics
try {
    // Total Students
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM students");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_students'] = $result ? (int)$result['count'] : 0;
} catch (PDOException $e) {
    $stats['total_students'] = 0;
    error_log("Error getting student count: " . $e->getMessage());
}

try {
    // New Applications
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM enrollments WHERE status = 'pending'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['new_applications'] = $result ? (int)$result['count'] : 0;
} catch (PDOException $e) {
    $stats['new_applications'] = 0;
    error_log("Error fetching new applications count: " . $e->getMessage());
}

try {
    // Pending Documents
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM document_requests WHERE status = 'pending'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending_documents'] = $result ? (int)$result['count'] : 0;
} catch (PDOException $e) {
    $stats['pending_documents'] = 0;
    error_log("Error fetching pending documents count: " . $e->getMessage());
}

try {
    // Active Sections
    $stmt = $pdo->query("SELECT COUNT(DISTINCT id) as count FROM class_sections WHERE status = 'active'");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['active_sections'] = $result ? (int)$result['count'] : 0;
} catch (PDOException $e) {
    $stats['active_sections'] = 0;
    error_log("Error getting active sections: " . $e->getMessage());
}

try {
    // Auto-sync: Copy sections from class_schedules to class_sections if they don't exist
    $stmt_schedules = $pdo->query("
        SELECT DISTINCT grade_level, section, school_year
        FROM class_schedules
        WHERE grade_level IS NOT NULL AND section IS NOT NULL
    ");
    $schedule_sections = $stmt_schedules->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($schedule_sections as $sched_section) {
        // Check if section exists in class_sections
        $stmt_check = $pdo->prepare("
            SELECT id FROM class_sections 
            WHERE grade_level = :grade_level AND section = :section
        ");
        $stmt_check->execute([
            ':grade_level' => $sched_section['grade_level'],
            ':section' => $sched_section['section']
        ]);
        
        // If doesn't exist, create it
        if (!$stmt_check->fetch()) {
            $stmt_insert = $pdo->prepare("
                INSERT INTO class_sections 
                (grade_level, section, school_year, status, created_at, updated_at)
                VALUES (:grade_level, :section, :school_year, 'active', NOW(), NOW())
            ");
            $stmt_insert->execute([
                ':grade_level' => $sched_section['grade_level'],
                ':section' => $sched_section['section'],
                ':school_year' => $sched_section['school_year']
            ]);
        }
    }
    
    // Get all class sections with adviser information
    $query = "SELECT cs.*, r.first_name, r.last_name, 
              (SELECT COUNT(*) FROM students s WHERE s.grade_level = cs.grade_level AND s.section = cs.section) as student_count
              FROM class_sections cs
              LEFT JOIN registrars r ON cs.adviser_id = r.id
              ORDER BY cs.grade_level, cs.section";
    
    $stmt = $pdo->query($query);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching class sections: " . $e->getMessage();
    error_log("Error fetching class sections: " . $e->getMessage());
}

// Determine current page for sidebar highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Include header and sidebar
$page_title = 'Class Sections';
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
            background-color: #6b7280;
            color: white;
        }
    </style>
</head>
<body class="min-h-screen flex">
    <!-- Toast Container -->
    <div id="toastContainer"></div>

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
                    <a href="#" class="flex items-center p-3 rounded-lg <?php echo in_array($current_page, ['add_student.php', 'view_students.php', 'student_search.php']) ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item" onclick="toggleSubmenu('students-submenu', this)">
                        <i class="fas fa-user-graduate w-5"></i>
                        <span class="ml-3 sidebar-text">Student Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="students-submenu" class="submenu pl-4 mt-1 <?php echo in_array($current_page, ['add_student.php', 'view_students.php', 'student_search.php']) ? 'open' : ''; ?>">
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
                    <div id="sections-submenu" class="submenu pl-4 mt-1 <?php echo in_array($current_page, ['view_sections.php', 'class_schedules.php']) ? 'open' : ''; ?>>
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
                    <a href="#" class="flex items-center p-3 rounded-lg <?php echo in_array($current_page, ['enrollment_reports.php', 'demographic_reports.php']) ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item" onclick="toggleSubmenu('reports-submenu', this)">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="reports-submenu" class="submenu pl-4 mt-1 <?php echo in_array($current_page, ['enrollment_reports.php', 'demographic_reports.php']) ? 'open' : ''; ?>">
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
                            <?php echo htmlspecialchars($registrar_id_display); ?>
                        </div>
                        <span class="hidden md:inline-block text-white">
                            Registrar
                        </span>
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
                        <a href="../logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100" onclick="return confirm('Are you sure you want to log out?');">
                            <i class="fas fa-sign-out-alt mr-2 w-5"></i> Sign out
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <div class="container mx-auto px-4 py-6">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-2xl font-bold text-gray-800">Class Sections</h1>
                <button onclick="openAddSectionModal()" 
                        class="bg-primary-600 text-white px-4 py-2 rounded-lg hover:bg-primary-700 transition-colors">
                    <i class="fas fa-plus mr-2"></i> Add New Section
                </button>
            </div>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <!-- Grade Filter Tabs -->
            <div class="mb-6 bg-white p-4 rounded-lg shadow-sm border border-gray-200">
                <h2 class="text-lg font-semibold text-gray-800 mb-3">Filter by Grade</h2>
                <div class="flex flex-wrap gap-2">
                    <button 
                        onclick="filterSections('all')" 
                        class="grade-filter px-4 py-2 rounded-lg font-medium transition-colors bg-blue-100 text-blue-700" 
                        data-grade="all"
                    >
                        All Grades
                    </button>
                    <?php
                    $grades = range(1, 6);
                    foreach ($grades as $grade):
                        $gradeSections = array_filter($sections, function($section) use ($grade) {
                            return $section['grade_level'] == $grade;
                        });
                        $sectionCount = count($gradeSections);
                    ?>
                        <button 
                            onclick="filterSections(<?php echo $grade; ?>)" 
                            class="grade-filter px-4 py-2 rounded-lg font-medium transition-colors bg-gray-100 text-gray-700 hover:bg-gray-200" 
                            data-grade="<?php echo $grade; ?>"
                        >
                            Grade <?php echo $grade; ?> 
                            <span class="ml-1 px-2 py-0.5 bg-white text-gray-700 text-xs rounded-full">
                                <?php echo $sectionCount; ?>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Class Sections Grid -->
            <div class="space-y-6">
                <?php 
                // Group sections by grade level
                $groupedSections = [];
                foreach ($sections as $section) {
                    $grade = $section['grade_level'];
                    if (!isset($groupedSections[$grade])) {
                        $groupedSections[$grade] = [];
                    }
                    $groupedSections[$grade][] = $section;
                }
                ksort($groupedSections);
                
                foreach ($groupedSections as $grade => $gradeSections): 
                ?>
                    <div class="grade-section" data-grade="<?php echo $grade; ?>">
                        <div class="flex items-center justify-between mb-3 px-2">
                            <h3 class="text-xl font-semibold text-gray-800 flex items-center">
                                <i class="fas fa-graduation-cap text-blue-600 mr-2"></i>
                                Grade <?php echo $grade; ?> 
                                <span class="ml-2 text-sm font-normal text-gray-500">(<?php echo count($gradeSections); ?> sections)</span>
                            </h3>
                            <button class="text-blue-600 hover:text-blue-800 text-sm font-medium" onclick="toggleGradeSection(<?php echo $grade; ?>)">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        
                        <div id="grade-<?php echo $grade; ?>-sections" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-6">
                            <?php foreach ($gradeSections as $section): ?>
                                <div class="section-card bg-white rounded-lg shadow-sm border border-gray-200 hover:shadow-md transition-shadow">
                                    <div class="p-4">
                                        <div class="flex justify-between items-start mb-2">
                                            <div>
                                                <h4 class="font-semibold text-gray-800">
                                                    <?php echo htmlspecialchars($section['section'] ?? 'N/A'); ?>
                                                </h4>
                                                <p class="text-sm text-gray-500">
                                                    Room: <?php echo htmlspecialchars($section['room_number'] ?? 'N/A'); ?>
                                                </p>
                                            </div>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo $section['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                <?php echo ucfirst(htmlspecialchars($section['status'])); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="space-y-2 mt-3 pt-3 border-t border-gray-100">
                                            <div class="flex items-center text-sm text-gray-600">
                                                <i class="fas fa-chalkboard-teacher mr-2 text-gray-400 w-4 text-center"></i>
                                                <span class="truncate">
                                                    <?php 
                                                    echo ($section['first_name'] && $section['last_name'])
                                                        ? htmlspecialchars($section['first_name'] . ' ' . $section['last_name'])
                                                        : 'No Adviser';
                                                    ?>
                                                </span>
                                            </div>
                                            
                                            <div class="flex items-center text-sm text-gray-600">
                                                <i class="fas fa-users mr-2 text-gray-400 w-4 text-center"></i>
                                                <span><?php echo (int)($section['student_count'] ?? 0); ?> students</span>
                                            </div>
                                            
                                            <div class="flex justify-between pt-2">
                                                <a href="class_details.php?grade=<?php echo $grade; ?>&section=<?php echo urlencode($section['section']); ?>" 
                                                   class="text-blue-600 hover:text-blue-800 text-sm font-medium inline-flex items-center">
                                                    <i class="fas fa-eye mr-1"></i> View
                                                </a>
                                                <div class="flex space-x-2">
                                                    <a href="class_schedules.php?grade_level=<?php echo $section['grade_level']; ?>&section=<?php echo urlencode($section['section']); ?>" 
                                                       class="text-green-600 hover:text-green-800 transition-colors" 
                                                       title="View Schedule">
                                                        <i class="fas fa-calendar-alt"></i>
                                                    </a>
                                                    <?php
                                                    // Prepare the values with proper escaping
                                                    $id = htmlspecialchars($section['id'], ENT_QUOTES, 'UTF-8');
                                                    $grade_level = htmlspecialchars($section['grade_level'], ENT_QUOTES, 'UTF-8');
                                                    $section_name = htmlspecialchars($section['section'], ENT_QUOTES, 'UTF-8');
                                                    $room_number = htmlspecialchars($section['room_number'] ?? '', ENT_QUOTES, 'UTF-8');
                                                    $status = htmlspecialchars($section['status'] ?? 'active', ENT_QUOTES, 'UTF-8');
                                                    ?>
                                                    <button onclick="editSection('<?php echo $id; ?>', '<?php echo $grade_level; ?>', '<?php echo $section_name; ?>', '<?php echo $room_number; ?>', '<?php echo $status; ?>')" 
                                                            class="text-blue-600 hover:text-blue-800 transition-colors" 
                                                            title="Edit Section">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button onclick="confirmDelete(<?php echo $section['id']; ?>)" 
                                                            class="text-red-600 hover:text-red-800 text-sm font-medium">
                                                        <i class="fas fa-trash-alt mr-1"></i> Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <script>
                // Function to filter sections by grade
                function filterSections(grade) {
                    // Update active tab
                    document.querySelectorAll('.grade-filter').forEach(btn => {
                        if (btn.dataset.grade == grade) {
                            btn.classList.remove('bg-gray-100', 'text-gray-700');
                            btn.classList.add('bg-blue-100', 'text-blue-700', 'font-semibold');
                        } else {
                            btn.classList.remove('bg-blue-100', 'text-blue-700', 'font-semibold');
                            btn.classList.add('bg-gray-100', 'text-gray-700');
                        }
                    });
                    
                    // Show/hide sections
                    document.querySelectorAll('.grade-section').forEach(section => {
                        if (grade === 'all') {
                            section.style.display = 'block';
                            // Show all sections within this grade
                            const gradeNum = section.dataset.grade;
                            const sectionsDiv = document.getElementById(`grade-${gradeNum}-sections`);
                            if (sectionsDiv) {
                                sectionsDiv.style.display = 'grid';
                                const button = section.querySelector('button[onclick^="toggleGradeSection"]');
                                if (button) {
                                    button.innerHTML = '<i class="fas fa-chevron-down"></i>';
                                }
                            }
                        } else if (section.dataset.grade == grade) {
                            section.style.display = 'block';
                            const sectionsDiv = document.getElementById(`grade-${grade}-sections`);
                            if (sectionsDiv) {
                                sectionsDiv.style.display = 'grid';
                                const button = section.querySelector('button[onclick^="toggleGradeSection"]');
                                if (button) {
                                    button.innerHTML = '<i class="fas fa-chevron-down"></i>';
                                }
                            }
                        } else {
                            section.style.display = 'none';
                        }
                    });
                }
                
                // Function to toggle grade section visibility
                function toggleGradeSection(grade, event) {
                    event.preventDefault();
                    event.stopPropagation();
                    
                    const section = document.getElementById(`grade-${grade}-sections`);
                    const button = event.currentTarget;
                    
                    if (!section) return;
                    
                    if (section.style.display === 'none' || section.style.display === '') {
                        section.style.display = 'grid';
                        button.innerHTML = '<i class="fas fa-chevron-down"></i>';
                    } else {
                        section.style.display = 'none';
                        button.innerHTML = '<i class="fas fa-chevron-right"></i>';
                    }
                }
                
                // Initialize with all sections visible
                document.addEventListener('DOMContentLoaded', function() {
                    // Make sure all grade sections are properly initialized
                    document.querySelectorAll('.grade-section').forEach((section, index) => {
                        const grade = section.dataset.grade;
                        const sectionsDiv = document.getElementById(`grade-${grade}-sections`);
                        const button = section.querySelector('button[onclick^="toggleGradeSection"]');
                        
                        if (sectionsDiv) {
                            if (index === 0) {
                                // First section is expanded by default
                                sectionsDiv.style.display = 'grid';
                                if (button) {
                                    button.innerHTML = '<i class="fas fa-chevron-down"></i>';
                                }
                            } else {
                                // Other sections are collapsed
                                sectionsDiv.style.display = 'none';
                                if (button) {
                                    button.innerHTML = '<i class="fas fa-chevron-right"></i>';
                                }
                            }
                        }
                    });
                    
                    // Set the first grade filter as active by default
                    const firstGradeBtn = document.querySelector('.grade-filter');
                    if (firstGradeBtn) {
                        firstGradeBtn.click();
                    }
                });
            </script>
                <?php if (empty($sections)): ?>
                    <div class="col-span-full text-center py-12 text-gray-500">
                        <i class="fas fa-chalkboard text-4xl mb-3"></i>
                        <p>No class sections found. Add your first section to get started.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add Section Modal -->
        <div id="addSectionModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <!-- Background overlay -->
                <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="closeModal()"></div>

                <!-- Modal panel -->
                <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-primary-100 sm:mx-0 sm:h-10 sm:w-10">
                                <i class="fas fa-plus text-primary-600"></i>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">
                                    Add New Section
                                </h3>
                                <div class="mt-2">
                                    <form id="sectionForm" action="save_section.php" method="POST" class="space-y-4 mt-4">
                                        <input type="hidden" name="section_id" id="section_id" value="">
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label for="grade_level" class="block text-sm font-medium text-gray-700">Grade Level <span class="text-red-500">*</span></label>
                                                <select name="grade_level" id="grade_level" required
                                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border border-gray-300 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm rounded-md">
                                                    <option value="">Select Grade Level</option>
                                                    <?php for ($i = 1; $i <= 6; $i++): ?>
                                                        <option value="<?php echo $i; ?>">Grade <?php echo $i; ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                            </div>
                                            
                                            <div>
                                                <label for="section" class="block text-sm font-medium text-gray-700">Section Name <span class="text-red-500">*</span></label>
                                                <input type="text" name="section" id="section" required
                                                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                                       placeholder="e.g., Sampaguita">
                                            </div>
                                            
                                            <div>
                                                <label for="room_number" class="block text-sm font-medium text-gray-700">Room Number</label>
                                                <input type="text" name="room_number" id="room_number"
                                                       class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                                       placeholder="e.g., 101">
                                            </div>
                                            
                                            <div>
                                                <label for="adviser_id" class="block text-sm font-medium text-gray-700">Adviser/Teacher</label>
                                                <select name="adviser_id" id="adviser_id"
                                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border border-gray-300 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm rounded-md">
                                                    <option value="">No Adviser Assigned</option>
                                                    <?php
                                                    // Get teachers from teacher_db
                                                    try {
                                                        $teacher_conn = $database->getConnection('teacher');
                                                        $stmt_teachers = $teacher_conn->query("SELECT teacher_id, CONCAT(first_name, ' ', last_name) as name FROM teachers ORDER BY first_name, last_name");
                                                        $teachers = $stmt_teachers->fetchAll(PDO::FETCH_ASSOC);
                                                        foreach ($teachers as $teacher) {
                                                            echo '<option value="' . htmlspecialchars($teacher['teacher_id']) . '">' . htmlspecialchars($teacher['name']) . '</option>';
                                                        }
                                                    } catch (Exception $e) {
                                                        error_log("Error fetching teachers: " . $e->getMessage());
                                                    }
                                                    ?>
                                                </select>
                                            </div>
                                            
                                            <div class="md:col-span-2">
                                                <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                                                <select name="status" id="status" required
                                                        class="mt-1 block w-full pl-3 pr-10 py-2 text-base border border-gray-300 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm rounded-md">
                                                    <option value="active">Active</option>
                                                    <option value="inactive">Inactive</option>
                                                </select>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                        <button type="submit" form="sectionForm"
                                class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-primary-600 text-base font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:ml-3 sm:w-auto sm:text-sm">
                            Save Section
                        </button>
                        <button type="button" onclick="closeModal()"
                                class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Function to open the add section modal
        function openAddSectionModal() {
            const modal = document.getElementById('addSectionModal');
            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            document.documentElement.classList.add('modal-open');
            
            // Reset form and clear any previous errors
            const form = document.getElementById('sectionForm');
            if (form) {
                form.reset();
                // Clear any validation errors
                const errorElements = form.querySelectorAll('.error-message');
                errorElements.forEach(el => el.remove());
                const errorInputs = form.querySelectorAll('.border-red-500');
                errorInputs.forEach(el => el.classList.remove('border-red-500'));
            }
            
            // Focus on the first input field when modal opens
            setTimeout(() => {
                const firstInput = modal.querySelector('input, select, textarea');
                if (firstInput) firstInput.focus();
            }, 100);
        }

        // Function to close the modal
        function closeModal() {
            const modal = document.getElementById('addSectionModal');
            modal.classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
            document.documentElement.classList.remove('modal-open');
            
            // Reset form when closing modal
            const form = document.getElementById('sectionForm');
            if (form) {
                form.reset();
                // Clear any validation errors
                const errorElements = form.querySelectorAll('.error-message');
                errorElements.forEach(el => el.remove());
                const errorInputs = form.querySelectorAll('.border-red-500');
                errorInputs.forEach(el => el.classList.remove('border-red-500'));
            }
        }

        // Close modal when clicking outside the modal content
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('addSectionModal');
            const modalDialog = modal ? modal.querySelector('.bg-white') : null;
            
            if (modal && !modalDialog.contains(event.target) && event.target === modal) {
                closeModal();
            }
        });

        // Close modal when pressing Escape key
        document.addEventListener('keydown', function(event) {
            const modal = document.getElementById('addSectionModal');
            if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });

        // Handle form submission
        document.getElementById('sectionForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Get form data
            const formData = new FormData(this);
            const sectionId = document.getElementById('section_id').value;
            const isEdit = !!sectionId;
            
            // Show loading state
            const submitButton = this.querySelector('button[type="submit"]');
            const originalButtonText = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
            
            // Send AJAX request
            fetch('save_section.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    showToast(data.message || (isEdit ? 'Section updated successfully' : 'Section added successfully'), 'success');
                    
                    // Close modal and refresh the page after a short delay
                    closeModal();
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    // Show error message
                    showToast(data.message || 'Failed to save section', 'error');
                    
                    // Handle validation errors
                    if (data.errors) {
                        // Clear previous errors
                        const errorElements = document.querySelectorAll('.error-message');
                        errorElements.forEach(el => el.remove());
                        const errorInputs = document.querySelectorAll('.border-red-500');
                        errorInputs.forEach(el => el.classList.remove('border-red-500'));
                        
                        // Add new errors
                        Object.entries(data.errors).forEach(([field, message]) => {
                            const input = document.querySelector(`[name="${field}"]`);
                            if (input) {
                                input.classList.add('border-red-500');
                                const errorElement = document.createElement('p');
                                errorElement.className = 'mt-1 text-sm text-red-600 error-message';
                                errorElement.textContent = message;
                                input.parentNode.insertBefore(errorElement, input.nextSibling);
                            }
                        });
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('An error occurred while saving the section', 'error');
            })
            .finally(() => {
                // Reset button state
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonText;
            });
        });

        // Function to add default sections
        async function addDefaultSections() {
            const sections = [
                { grade_level: 1, section: 'Section A', room_number: '101' },
                { grade_level: 2, section: 'Section A', room_number: '102' },
                { grade_level: 3, section: 'Section A', room_number: '103' }
            ];

            const currentYear = new Date().getFullYear();
            const schoolYear = `${currentYear}-${currentYear + 1}`;
            
            try {
                // Check if sections already exist
                const response = await fetch('check_sections.php');
                const data = await response.json();
                
                if (data.exists) {
                    showToast('Default sections already exist', 'info');
                    return;
                }

                // Add each section
                for (const section of sections) {
                    const formData = new FormData();
                    formData.append('grade_level', section.grade_level);
                    formData.append('section', section.section);
                    formData.append('room_number', section.room_number);
                    formData.append('max_students', 50);
                    formData.append('status', 'active');
                    
                    const response = await fetch('save_section.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    if (!result.success) {
                        showToast(`Error adding ${section.section}: ${result.message}`, 'error');
                        return;
                    }
                }
                
                showToast('Default sections added successfully', 'success');
                // Reload the page to show the new sections
                setTimeout(() => window.location.reload(), 1500);
            } catch (error) {
                showToast('Error adding default sections: ' + error.message, 'error');
            }
        }

        // Call this function when the page loads if needed
        document.addEventListener('DOMContentLoaded', function() {
            // Check if we should add default sections
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('add_default_sections') === '1') {
                addDefaultSections();
            }
        });

        // Toast notifications
        function showToast(message, type = 'info') {
            const toastContainer = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            const icons = {
                success: 'check-circle',
                error: 'exclamation-circle',
                warning: 'exclamation-triangle',
                info: 'info-circle'
            };
            const colors = {
                success: '#0ea5e9',
                error: '#ef4444',
                warning: '#facc15',
                info: '#38bdf8'
            };
            
            toast.className = `toast ${type} flex items-center p-4 bg-white rounded-lg shadow-lg`;
            toast.innerHTML = `
                <i class="fas fa-${icons[type] || 'info-circle'} text-[${colors[type] || '#38bdf8'}] mr-3 toast-icon"></i>
                <span class="toast-message">${message}</span>
                <button class="toast-close ml-4" onclick="this.parentElement.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            toastContainer.appendChild(toast);
            
            // Show toast with animation
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            // Auto-remove toast after 5 seconds
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
            const notificationButton = document.getElementById('notification-btn');
            if (notificationPanel && notificationButton) {
                notificationPanel.classList.toggle('open');
                const isExpanded = notificationPanel.classList.contains('open');
                notificationButton.setAttribute('aria-expanded', isExpanded);
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
                
                // Save preference to localStorage
                const isCollapsed = sidebar.classList.contains('collapsed');
                localStorage.setItem('sidebarCollapsed', isCollapsed);
            }
        }

        // Function to populate form for editing
        function editSection(section) {
            document.getElementById('section_id').value = section.id;
            document.getElementById('grade_level').value = section.grade_level;
            document.getElementById('section').value = section.section || '';
            document.getElementById('room_number').value = section.room_number || '';
            document.getElementById('adviser_id').value = section.adviser_id || '';
            document.getElementById('status').value = section.status || 'active';
            
            // Update modal title
            document.querySelector('#addSectionModal h3').textContent = 'Edit Class Section';
            
            // Show modal
            document.getElementById('addSectionModal').classList.remove('hidden');
        }

        // Function to confirm section deletion
        function confirmDelete(sectionId) {
            if (confirm('Are you sure you want to delete this section? This action cannot be undone.')) {
                // Send AJAX request to delete section
                fetch('delete_section.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'section_id=' + sectionId
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast(data.message || 'Section deleted successfully', 'success');
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        showToast(data.message || 'Failed to delete section', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('An error occurred while deleting the section', 'error');
                });
            }
        }

        // Close modal
        function closeModal() {
            const modal = document.getElementById('addSectionModal');
            if (modal) {
                modal.classList.add('hidden');
                document.getElementById('sectionForm').reset();
                document.getElementById('section_id').value = '';
                document.querySelector('#addSectionModal h3').textContent = 'Add New Class Section';
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
                notificationButton.setAttribute('aria-expanded', 'false');
            }
            
            const modal = document.getElementById('addSectionModal');
            const modalContent = modal ? modal.querySelector('.relative') : null;
            if (modal && !modal.classList.contains('hidden') && !modalContent.contains(event.target)) {
                closeModal();
            }
        });

        // Add event listeners for the Add Default Sections button (if applicable)
        document.addEventListener('DOMContentLoaded', function() {
            const addDefaultSectionsBtn = document.getElementById('addDefaultSectionsBtn');
            if (addDefaultSectionsBtn) {
                addDefaultSectionsBtn.addEventListener('click', function() {
                    if (confirm('Are you sure you want to add default sections for Grades 1-3? This will create 3 new sections if they don\'t already exist.')) {
                        window.location.href = 'view_sections.php?add_default_sections=1';
                    }
                });
            }

            // Show success message if redirected with success parameter
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('success') === '1') {
                showToast('Section added successfully!', 'success');
                // Remove the success parameter from URL without reloading
                const newUrl = window.location.pathname;
                window.history.replaceState({}, document.title, newUrl);
            }
        });

        // Handle keyboard accessibility
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
            
            const notificationPanel = document.getElementById('notification-btn');
            if (notificationPanel && (event.key === 'Enter' || event.key === ' ')) {
                if (event.target === notificationPanel) {
                    event.preventDefault();
                    toggleNotifications();
                }
            }
            if (event.key === 'Escape' && notificationPanel && document.getElementById('notification-panel').classList.contains('open')) {
                toggleNotifications();
                notificationPanel.focus();
            }
            
            const modal = document.getElementById('addSectionModal');
            if (event.key === 'Escape' && modal && !modal.classList.contains('hidden')) {
                closeModal();
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize sidebar state from localStorage
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                const sidebar = document.getElementById('sidebar');
                const collapseIcon = document.getElementById('collapse-icon');
                if (sidebar && collapseIcon) {
                    sidebar.classList.add('collapsed');
                    collapseIcon.classList.remove('fa-chevron-left');
                    collapseIcon.classList.add('fa-chevron-right');
                    const collapseText = document.querySelector('.sidebar-text');
                    if (collapseText) collapseText.textContent = 'Expand Sidebar';
                }
            }
            
            // Show welcome toast
            showToast('Welcome to the Registrar Portal!', 'success');
            
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