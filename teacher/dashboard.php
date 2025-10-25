<?php
// Add this line at the top
require_once '../includes/session_config.php';
require_once '../includes/auth.php';

$auth = new Auth();

// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connections
$student_conn = null;
$teacher_conn = null;
$error_messages = [];

// First check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    $_SESSION['error'] = 'Please log in first.';
    header('Location: ../login.php');
    exit;
}

// Check if user has teacher role
if (strtolower($_SESSION['role']) !== 'teacher') {
    $_SESSION['error'] = 'Access denied. Teacher access only.';
    header('Location: ../login.php');
    exit;
}

try {
    require_once '../config/database.php';
    $database = new Database();
    
    // Get login connection (for authentication)
    $login_conn = $database->getLoginConnection();
    if (!$login_conn) {
        throw new Exception('Failed to connect to login database');
    }
    
    // Get teacher connection (for teacher data)
    $teacher_conn = $database->getConnection('teacher');
    if (!$teacher_conn) {
        throw new Exception('Failed to connect to teacher database');
    }
    
    // Fetch teacher login account from users table in login_db
    $stmt = $login_conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
    $stmt->execute([$_SESSION['user_id']]);
    $login_account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$login_account) {
        throw new Exception('Invalid user account');
    }
    
    // Fetch teacher info from teacher_db
    $stmt = $teacher_conn->prepare("SELECT * FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // If teacher record doesn't exist, create a basic one
        $user = [
            'user_id' => $_SESSION['user_id'],
            'first_name' => 'Teacher',
            'last_name' => '',
            'teacher_id' => 'T-' . strtoupper(uniqid()),
            'subject' => 'General',
            'grade_level' => null,
            'section' => null,
            'contact_number' => ''
        ];
        
        // Insert the new teacher record
        $stmt = $teacher_conn->prepare("INSERT INTO teachers (user_id, teacher_id, first_name, last_name, subject) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $user['user_id'],
            $user['teacher_id'],
            $user['first_name'],
            $user['last_name'],
            $user['subject']
        ]);
        
        $user['id'] = $teacher_conn->lastInsertId();
    }
    
    // Generate initials for avatar
    $initials = '';
    if (!empty($user['first_name'])) $initials .= $user['first_name'][0];
    if (!empty($user['last_name'])) $initials .= $user['last_name'][0];
    
    // Set up user data for the view
    $user = array_merge($user, [
        'id' => $user['user_id'] ?? $user['id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
        'email' => $user['email'] ?? '',
        'initials' => !empty($initials) ? strtoupper($initials) : 'T',
        'first_name' => $user['first_name'] ?? 'Teacher',
        'last_name' => $user['last_name'] ?? '',
        'full_name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'subject' => $user['subject'] ?? 'General',
        'grade_level' => $user['grade_level'] ?? null,
        'section' => $user['section'] ?? null,
        'teacher_id' => $user['teacher_id'] ?? ''
    ]);
    
} catch (Exception $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $error_messages[] = "An error occurred while loading the dashboard. " . $e->getMessage();
    
    // Initialize empty user to prevent errors
    $user = [
        'id' => $_SESSION['user_id'] ?? 0,
        'username' => $_SESSION['username'] ?? 'Guest',
        'role' => $_SESSION['role'] ?? 'guest',
        'first_name' => 'Guest',
        'last_name' => '',
        'email' => '',
        'initials' => 'G'
    ];
}

// Only redirect if it's a critical error that prevents the page from loading
if (empty($user) || !isset($user['id']) || $user['id'] == 0) {
    $_SESSION['error'] = 'Failed to load user data. Please try again.';
    header('Location: ../login.php');
    exit;
}

// Initialize variables
$classes = [];
$recent_activities = [];
$pending_notices = 0;

// Initialize user data if not set or missing required fields
if (!is_array($user)) {
    $user = [];
}

// Ensure all required user fields are set with default values
$user = array_merge([
    'id' => 0,
    'first_name' => 'Teacher',
    'email' => '',
    'profile_image' => '',
    'role' => 'teacher'
], $user);

// Fetch teacher's classes
$classes = [];
if (!empty($user['teacher_id'])) {
    $stmt = $teacher_conn->prepare("SELECT * FROM classes WHERE teacher_id = ? ORDER BY grade_level, section");
    $stmt->execute([$user['teacher_id']]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
    
// Fetch teacher's activities
$activities = [];
if (!empty($user['teacher_id'])) {
    $stmt = $teacher_conn->prepare("SELECT * FROM activities WHERE teacher_id = ? ORDER BY activity_date DESC LIMIT 5");
    $stmt->execute([$user['teacher_id']]);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
    
// Fetch data if connections are available and user is authenticated
if ($teacher_conn && !empty($user) && isset($user['id'])) {
    try {
        error_log("Starting to fetch dashboard data for teacher ID: " . $user['id']);
            
        // Initialize empty arrays for dashboard data
        $classes = [];
        $recent_activities = [];
        $pending_notices = 0;
            
        // Check if the teacher has any classes
        $stmt = $teacher_conn->prepare("SELECT c.* FROM classes c 
                                     INNER JOIN teachers t ON c.teacher_id = t.teacher_id 
                                     WHERE t.user_id = :user_id");
        $stmt->execute([':user_id' => $user['id']]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        if (empty($classes)) {
            error_log("No classes found for user ID: " . $user['id']);
        } else {
            error_log("Found " . count($classes) . " classes for user ID: " . $user['id']);
        }
            
        // Get recent activities
        try {
            $stmt = $teacher_conn->query("SHOW TABLES LIKE 'activities'");
            if ($stmt->rowCount() > 0) {
                $stmt = $teacher_conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = :user_id");
                $stmt->execute([':user_id' => $user['id']]);
                $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                if ($teacher && !empty($teacher['teacher_id'])) {
                    $stmt = $teacher_conn->prepare("SELECT * FROM activities WHERE teacher_id = :teacher_id ORDER BY activity_date DESC LIMIT 5");
                    $stmt->execute([':teacher_id' => $teacher['teacher_id']]);
                    $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $recent_activities = [];
                    error_log("No teacher_id found for user ID: " . $user['id']);
                }
                    
                if ($recent_activities === false) {
                    $recent_activities = [];
                    error_log("No activities found for teacher ID: " . $user['id']);
                } else {
                    error_log("Found " . count($recent_activities) . " activities for teacher ID: " . $user['id']);
                }
            }
        } catch (PDOException $e) {
            error_log("Activities table not available: " . $e->getMessage());
            $recent_activities = [];
        }

        // Count pending notices
        if (isset($teacher) && !empty($teacher['teacher_id'])) {
            $stmt = $teacher_conn->prepare("SELECT COUNT(*) as count FROM notices WHERE teacher_id = :teacher_id AND status = 'pending'");
            $stmt->execute([':teacher_id' => $teacher['teacher_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $pending_notices = $result ? (int)$result['count'] : 0;
        } else {
            $pending_notices = 0;
            error_log("Could not count notices: No teacher_id found for user ID: " . $user['id']);
        }

    } catch (Exception $e) {
        $errorMsg = "Error in dashboard data: " . $e->getMessage();
        error_log($errorMsg);
        error_log("Error details: " . print_r([
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], true));
        
        if ($teacher_conn) {
            $errorInfo = $teacher_conn->errorInfo();
            error_log("Database error info: " . print_r($errorInfo, true));
        }
        
        $error_messages[] = $errorMsg;
    }
}

// Calculate stats
$totalClasses = 0;
$totalStudents = 0;
$upcomingActivities = 0;
$pendingGrading = 0;
$unreadNotices = 0;
$attendancePending = 0;

if ($teacher_conn && !empty($user['teacher_id'])) {
    try {
        $stmt = $teacher_conn->prepare("SELECT COUNT(*) as count FROM classes WHERE teacher_id = ?");
        $stmt->execute([$user['teacher_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalClasses = $result ? (int)$result['count'] : 0;

        if ($totalClasses > 0) {
            $stmt = $teacher_conn->prepare("
                SELECT COUNT(DISTINCT cs.student_id) as count 
                FROM class_students cs
                JOIN classes c ON cs.class_id = c.id
                WHERE c.teacher_id = ?
            ");
            $stmt->execute([$user['teacher_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalStudents = $result ? (int)$result['count'] : 0;
        }

        $stmt = $teacher_conn->prepare("
            SELECT COUNT(*) as count 
            FROM activities 
            WHERE teacher_id = ? 
            AND activity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $stmt->execute([$user['teacher_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $upcomingActivities = $result ? (int)$result['count'] : 0;

        try {
            $stmt = $teacher_conn->prepare("
                SELECT COUNT(DISTINCT s.id) as count 
                FROM assignment_submissions s
                JOIN assignments a ON s.assignment_id = a.id
                JOIN classes c ON a.class_id = c.id
                WHERE c.teacher_id = ? AND s.grade IS NULL
            ");
            $stmt->execute([$user['teacher_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $pendingGrading = $result ? (int)$result['count'] : 0;
        } catch (Exception $e) {
            error_log("Error getting pending grading: " . $e->getMessage());
            $pendingGrading = 0;
        }

        try {
            $stmt = $teacher_conn->prepare("
                SELECT COUNT(DISTINCT c.id) as count 
                FROM classes c
                LEFT JOIN attendance a ON c.id = a.class_id 
                    AND a.attendance_date = CURDATE()
                WHERE c.teacher_id = ? 
                AND a.id IS NULL
                AND DAYOFWEEK(CURDATE()) BETWEEN 2 AND 6
            ");
            $stmt->execute([$user['teacher_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $attendancePending = $result ? (int)$result['count'] : 0;
        } catch (Exception $e) {
            error_log("Error getting pending attendance: " . $e->getMessage());
            $attendancePending = 0;
        }

        try {
            $stmt = $teacher_conn->prepare("
                SELECT COUNT(*) as count 
                FROM notices 
                WHERE (target_teacher_id = ? OR target_teacher_id IS NULL)
                AND status = 'unread'
            ");
            $stmt->execute([$user['teacher_id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $unreadNotices = $result ? (int)$result['count'] : 0;
        } catch (Exception $e) {
            error_log("Error getting unread notices: " . $e->getMessage());
            $unreadNotices = 0;
        }
    } catch (Exception $e) {
        error_log("Error fetching teacher stats: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>San Agustin Elementary School - Teacher Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#fef7ee',
                            100: '#fdecd6',
                            200: '#fad5ad',
                            300: '#f7b479',
                            400: '#f38a43',
                            500: '#f06a1d',
                            600: '#e14f13',
                            700: '#bb3b12',
                            800: '#953016',
                            900: '#782916',
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
        .sidebar.collapsed .events-title,
        .sidebar.collapsed .event-details,
        .sidebar.collapsed .events-container {
            display: none;
        }
        
        .sidebar.collapsed .nav-item {
            justify-content: center;
            padding: 0.75rem;
        }
        
        .sidebar.collapsed .nav-item i {
            margin-right: 0;
        }
        
        .sidebar.collapsed .logo-container {
            margin: 0 auto;
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
            background: #f06a1d;
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
            background: linear-gradient(135deg, #f06a1d 0%, #f38a43 100%);
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
                SA
            </div>
            <h1 class="text-xl font-bold text-white logo-text">San Agustin ES</h1>
            <p class="text-sm text-gray-400 mt-1">Teacher Portal</p>
        </div>
        <div class="flex-1 p-4 overflow-y-auto custom-scrollbar">
            <ul class="space-y-2">
                <li>
                    <a href="dashboard.php" class="flex items-center p-3 rounded-lg bg-secondary-700 text-white transition-colors nav-item">
                        <i class="fas fa-home w-5"></i>
                        <span class="ml-3 sidebar-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="classes.php" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-chalkboard-teacher w-5"></i>
                        <span class="ml-3 sidebar-text">My Classes</span>
                    </a>
                </li>
                <li>
                    <a href="students.php" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-user-graduate w-5"></i>
                        <span class="ml-3 sidebar-text">Students</span>
                    </a>
                </li>
                <li>
                    <a href="attendance.php" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-clipboard-check w-5"></i>
                        <span class="ml-3 sidebar-text">Attendance</span>
                    </a>
                </li>
                <li>
                    <a href="grades.php" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Grades</span>
                    </a>
                </li>
                <li>
                    <a href="announcements.php" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-bullhorn w-5"></i>
                        <span class="ml-3 sidebar-text">Announcements</span>
                    </a>
                </li>
                <li>
                    <a href="reports.php" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-file-alt w-5"></i>
                        <span class="ml-3 sidebar-text">Reports</span>
                    </a>
                </li>
                <li>
                    <a href="settings.php" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-cog w-5"></i>
                        <span class="ml-3 sidebar-text">Settings</span>
                    </a>
                </li>
            </ul>
        </div>
        
        <!-- Footer -->
        <div class="p-4 border-t border-secondary-700">
            <button onclick="toggleSidebarCollapse()" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors w-full justify-center">
                <i class="fas fa-chevron-left" id="collapse-icon"></i>
                <span class="ml-2 sidebar-text">Collapse</span>
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
                <h1 class="text-xl font-bold">Teacher Dashboard</h1>
            </div>
            
            <div class="flex items-center space-x-4">
                <!-- Notifications -->
                <div class="relative">
                    <button id="notification-btn" class="text-white hover:text-primary-200 transition-colors relative" onclick="toggleNotifications()">
                        <i class="fas fa-bell text-xl"></i>
                        <?php if ($pending_notices > 0): ?>
                            <span class="notification-dot"><?php echo $pending_notices; ?></span>
                        <?php endif; ?>
                    </button>
                    
                    <!-- Notifications Dropdown -->
                    <div id="notification-panel" class="notification-panel">
                        <div class="p-4 border-b border-gray-200">
                            <h3 class="font-bold text-gray-800">Notifications</h3>
                        </div>
                        <div class="overflow-y-auto max-h-72">
                            <?php if (!empty($recent_activities) && is_array($recent_activities)): ?>
                                <?php foreach ($recent_activities as $activity): 
                                    if (!is_array($activity) || !isset($activity['id'])) continue;
                                    
                                    $message = $activity['message'] ?? 'New notification';
                                    $isRead = $activity['is_read'] ?? false;
                                    $icon = $activity['icon'] ?? 'fa-bell';
                                    $type = $activity['type'] ?? 'gray';
                                    $createdAt = $activity['created_at'] ?? 'now';
                                    $formattedDate = date('M d, Y H:i', strtotime($createdAt));
                                ?>
                                    <div class="p-4 border-b border-gray-100 hover:bg-gray-50 cursor-pointer notification-item <?php echo $isRead ? '' : 'unread'; ?>" data-id="<?php echo htmlspecialchars($activity['id']); ?>">
                                        <div class="flex items-start">
                                            <div class="<?php echo [
                                                'Assignment' => 'bg-blue-100',
                                                'Meeting' => 'bg-green-100',
                                                'Grade' => 'bg-amber-100',
                                                'Schedule' => 'bg-purple-100',
                                                'Other' => 'bg-gray-100'
                                            ][$activity['type'] ?? 'Other'] ?> p-2 rounded-full mr-3">
                                                <i class="fas <?php echo [
                                                    'Assignment' => 'fa-clipboard-list',
                                                    'Meeting' => 'fa-users',
                                                    'Grade' => 'fa-graduation-cap',
                                                    'Schedule' => 'fa-calendar-alt',
                                                    'Other' => 'fa-info-circle'
                                                ][$activity['type'] ?? 'Other'] ?> text-<?php echo [
                                                    'Assignment' => 'blue-500',
                                                    'Meeting' => 'green-500',
                                                    'Grade' => 'amber-500',
                                                    'Schedule' => 'purple-500',
                                                    'Other' => 'gray-500'
                                                ][$activity['type'] ?? 'Other'] ?>"></i>
                                            </div>
                                            <div>
                                                <p class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($message); ?></p>
                                                <p class="text-xs text-gray-400 mt-1"><?php echo $formattedDate; ?></p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="p-4 text-center text-gray-500">No new notifications</div>
                            <?php endif; ?>
                        </div>
                        <div class="p-3 border-t border-gray-200 text-center">
                            <a href="notifications.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">View All Notifications</a>
                        </div>
                    </div>
                </div>
                
                <!-- User Menu -->
                <div class="relative">
                    <button id="user-menu-btn" onclick="toggleUserMenu()" class="w-10 h-10 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold shadow-md">
                        <?php echo htmlspecialchars($user['initials'] ?? 'T'); ?>
                    </button>
                    
                    <!-- User Dropdown -->
                    <div id="user-menu" class="absolute right-0 top-12 mt-2 w-48 bg-white rounded-lg shadow-xl py-1 z-50 hidden border border-gray-200">
                        <div class="px-4 py-2 border-b border-gray-100">
                            <p class="text-gray-800 font-medium"><?php echo trim(htmlspecialchars(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))); ?></p>
                            <p class="text-xs text-gray-500 truncate"><?php echo !empty($user['email']) ? htmlspecialchars($user['email']) : 'No email'; ?></p>
                        </div>
                        <a href="profile.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 transition-colors">Profile</a>
                        <a href="settings.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 transition-colors">Settings</a>
                        <a href="../logout.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 transition-colors">Logout</a>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Main Content Area -->
        <main class="flex-1 overflow-y-auto bg-gray-50">
            <!-- Pending Tasks Bar -->
            <div class="bg-white border-b border-gray-200">
                <div class="px-4 py-2 flex items-center justify-between max-w-7xl mx-auto">
                    <div class="flex items-center space-x-1 text-sm font-medium text-gray-700">
                        <span class="text-gray-500">Pending Tasks:</span>
                    </div>
                    <div class="flex items-center space-x-3 overflow-x-auto pb-1">
                        <div class="flex-shrink-0 flex items-center space-x-2 px-3 py-1.5 bg-blue-50 rounded-md">
                            <input type="checkbox" class="h-3.5 w-3.5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="text-sm whitespace-nowrap">Grade Math Quiz</span>
                            <span class="text-xs text-gray-500">Due tomorrow</span>
                        </div>
                        <div class="flex-shrink-0 flex items-center space-x-2 px-3 py-1.5 bg-yellow-50 rounded-md">
                            <input type="checkbox" class="h-3.5 w-3.5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="text-sm whitespace-nowrap">Lesson Plan</span>
                            <span class="text-xs text-gray-500">Due in 2d</span>
                        </div>
                        <div class="flex-shrink-0 flex items-center space-x-2 px-3 py-1.5 bg-red-50 rounded-md">
                            <input type="checkbox" class="h-3.5 w-3.5 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="text-sm whitespace-nowrap">Mid-term Grades</span>
                            <span class="text-xs text-gray-500">Due in 3d</span>
                        </div>
                    </div>
                    <div>
                        <button type="button" class="text-xs font-medium text-blue-600 hover:text-blue-800 whitespace-nowrap">
                            View All <i class="fas fa-chevron-right ml-1 text-xs"></i>
                        </button>
                    </div>
                </div>
            </div>

            <div class="p-5">
                <!-- Display error messages if any -->
                <?php if (!empty($error_messages)): ?>
                    <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars(implode("<br>", $error_messages)); ?>
                    </div>
                <?php endif; ?>

                <!-- Welcome Banner -->
                <div class="bg-white rounded-xl p-6 mb-6 border border-gray-200 shadow-sm dashboard-card">
                    <div class="flex flex-col md:flex-row md:items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 mb-2">Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!</h1>
                            <div class="flex items-center text-gray-600">
                                <i class="fas fa-chalkboard-teacher mr-2"></i>
                                <span>Teacher ID: <?php echo htmlspecialchars($user['teacher_id']); ?></span>
                                <?php if (!empty($user['subject'])): ?>
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-book mr-1"></i>
                                    <span><?php echo htmlspecialchars($user['subject']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($user['grade_level']) && !empty($user['section'])): ?>
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-users mr-1"></i>
                                    <span>Grade <?php echo htmlspecialchars($user['grade_level'] . ' - ' . $user['section']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-4 md:mt-0">
                            <span class="bg-primary-100 text-primary-700 px-3 py-1 rounded-full text-sm font-medium">Today: <?php echo date('M d, Y'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Quick Links -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6 dashboard-card">
                    <div class="px-4 py-3 bg-gradient-to-r from-primary-50 to-primary-50 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-bolt text-primary-500 mr-2 text-sm"></i>
                            <span>Quick Access</span>
                        </h3>
                    </div>
                    <div class="p-4 grid grid-cols-3 gap-3">
                        <a href="announcements.php" class="bg-gray-50 hover:bg-gray-100 rounded-lg p-4 text-center transition-colors border border-gray-200">
                            <div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center mx-auto">
                                <i class="fas fa-bullhorn text-blue-500"></i>
                            </div>
                            <p class="text-gray-700 text-sm mt-2 font-medium">Announcements</p>
                        </a>
                        <a href="reports.php" class="bg-gray-50 hover:bg-gray-100 rounded-lg p-4 text-center transition-colors border border-gray-200">
                            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center mx-auto">
                                <i class="fas fa-file-alt text-amber-500"></i>
                            </div>
                            <p class="text-gray-700 text-sm mt-2 font-medium">Reports</p>
                        </a>
                        <a href="attendance.php" class="bg-gray-50 hover:bg-gray-100 rounded-lg p-4 text-center transition-colors border border-gray-200">
                            <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center mx-auto">
                                <i class="fas fa-clipboard-check text-green-500"></i>
                            </div>
                            <p class="text-gray-700 text-sm mt-2 font-medium">Attendance</p>
                        </a>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-6">
                    <!-- Total Classes -->
                    <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-600">My Classes</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $totalClasses; ?></h3>
                                <p class="text-xs text-gray-500"><?php echo $totalStudents; ?> total students</p>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher text-blue-500"></i>
                            </div>
                        </div>
                        <div class="mt-4">
                            <a href="classes.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">View All</a>
                        </div>
                    </div>

                    <!-- Pending Tasks -->
                    <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-600">Pending Tasks</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $pendingGrading + $attendancePending; ?></h3>
                                <p class="text-xs text-gray-500"><?php echo $pendingGrading; ?> to grade • <?php echo $attendancePending; ?> attendance</p>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-amber-100 flex items-center justify-center">
                                <i class="fas fa-tasks text-amber-500"></i>
                            </div>
                        </div>
                        <div class="mt-4">
                            <a href="grading.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">Grade Work</a>
                        </div>
                    </div>

                    <!-- Today's Classes -->
                    <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-600">Today's Classes</p>
                                <h3 class="text-2xl font-bold text-gray-800">3</h3>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-green-100 flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher text-green-500"></i>
                            </div>
                        </div>
                        <div class="mt-4">
                            <a href="schedule.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">View Schedule</a>
                        </div>
                    </div>

                    <!-- New Notifications -->
                    <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-600">New Notifications</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $unreadNotices; ?></h3>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-red-100 flex items-center justify-center">
                                <i class="fas fa-bell text-red-500"></i>
                            </div>
                        </div>
                        <div class="mt-4">
                            <a href="notifications.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">View All</a>
                        </div>
                    </div>
                </div>

                <!-- Main Content Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                    <!-- Today's Schedule -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm dashboard-card">
                            <div class="flex items-center justify-between mb-5">
                                <div class="flex items-center">
                                    <i class="fas fa-calendar-day text-primary-500 mr-2"></i>
                                    <h2 class="text-lg font-bold text-gray-800">Today's Schedule</h2>
                                    <span class="ml-2 text-sm text-gray-500"><?php echo date('M d'); ?></span>
                                </div>
                                <a href="add_class.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">Add Class</a>
                            </div>
                            <div class="overflow-y-auto" style="max-height: 400px;">
                                <?php if (!empty($classes)): ?>
                                    <table class="w-full">
                                        <thead>
                                            <tr class="text-left text-gray-600 border-b border-gray-200">
                                                <th class="pb-3 font-medium">Class</th>
                                                <th class="pb-3 font-medium">Time</th>
                                                <th class="pb-3 font-medium">Room</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($classes, 0, 5) as $class): ?>
                                                <tr class="border-b border-gray-100 hover:bg-gray-50">
                                                    <td class="py-3">
                                                        <p class="font-medium text-gray-800"><?php echo htmlspecialchars($class['subject']); ?></p>
                                                        <?php if (!empty($class['grade_level']) && !empty($class['section'])): ?>
                                                            <p class="text-xs text-gray-500">Grade <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['section']); ?></p>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3"><?php echo htmlspecialchars($class['schedule'] ?? ''); ?></td>
                                                    <td class="py-3"><?php echo htmlspecialchars($class['room'] ?? ''); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                <?php else: ?>
                                    <div class="text-center text-gray-500">
                                        <i class="fas fa-calendar-day text-3xl text-gray-200 mb-2"></i>
                                        <p class="text-sm font-medium">No classes today</p>
                                        <p class="text-xs mt-1">Add a class to get started</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($classes) && count($classes) > 5): ?>
                                <div class="mt-4 text-right">
                                    <a href="schedule.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">View All Classes</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Announcements -->
                    <div>
                        <div class="bg-white rounded-xl p-5 border border-gray-200 shadow-sm dashboard-card">
                            <div class="flex items-center justify-between mb-5">
                                <h2 class="text-lg font-bold text-gray-800">Recent Announcements</h2>
                                <a href="announcements.php" class="text-primary-600 hover:text-primary-700 text-sm font-medium">View All</a>
                            </div>
                            <div class="space-y-4">
                                <div class="bg-gray-50 rounded-lg p-4 border border-gray-100">
                                    <div class="flex justify-between items-start">
                                        <h3 class="font-medium text-gray-800">Staff Meeting</h3>
                                        <span class="text-xs text-gray-500">Tomorrow</span>
                                    </div>
                                    <p class="text-gray-600 text-sm mt-2">Mandatory staff meeting to discuss upcoming parent-teacher conferences.</p>
                                </div>
                                <div class="bg-gray-50 rounded-lg p-4 border border-gray-100">
                                    <div class="flex justify-between items-start">
                                        <h3 class="font-medium text-gray-800">Curriculum Update</h3>
                                        <span class="text-xs text-gray-500">1 day ago</span>
                                    </div>
                                    <p class="text-gray-600 text-sm mt-2">The updated curriculum guidelines for the next semester are now available.</p>
                                </div>
                                <div class="bg-gray-50 rounded-lg p-4 border border-gray-100">
                                    <div class="flex justify-between items-start">
                                        <h3 class="font-medium text-gray-800">School Holiday</h3>
                                        <span class="text-xs text-gray-500">3 days ago</span>
                                    </div>
                                    <p class="text-gray-600 text-sm mt-2">School will be closed on October 30 for a local holiday.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
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

        // Toggle user menu
        function toggleUserMenu() {
            const userMenu = document.getElementById('user-menu');
            userMenu.classList.toggle('hidden');
        }

        // Toggle notifications panel
        function toggleNotifications() {
            const notificationPanel = document.getElementById('notification-panel');
            notificationPanel.classList.toggle('open');
        }

        // Mark notification as read
        function markNotificationAsRead(notificationId) {
            fetch('mark_notification_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `id=${notificationId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const notificationItem = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
                    if (notificationItem) {
                        notificationItem.classList.remove('unread');
                        const notificationDot = document.querySelector('.notification-dot');
                        if (notificationDot) {
                            let count = parseInt(notificationDot.textContent) - 1;
                            notificationDot.textContent = count;
                            if (count === 0) {
                                notificationDot.remove();
                            }
                        }
                    }
                } else {
                    console.error('Failed to mark notification as read:', data.error);
                }
            })
            .catch(error => console.error('Error:', error));
        }

        // Mark all notifications as read
        function markAllAsRead() {
            const notifications = document.querySelectorAll('.notification-item.unread');
            notifications.forEach(notification => {
                const notificationId = notification.getAttribute('data-id');
                markNotificationAsRead(notificationId);
            });
        }

        // Toggle sidebar collapse/expand
        function toggleSidebarCollapse() {
            const sidebar = document.getElementById('sidebar');
            const icon = document.getElementById('collapse-icon');
            sidebar.classList.toggle('collapsed');
            
            if (sidebar.classList.contains('collapsed')) {
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
                document.querySelector('.sidebar-text').textContent = 'Expand';
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
                document.querySelector('.sidebar-text').textContent = 'Collapse';
            }
        }

        // Close user menu and notifications when clicking outside
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('user-menu');
            const userButton = document.getElementById('user-menu-btn');
            
            if (userMenu && userButton && !userMenu.contains(event.target) && !userButton.contains(event.target)) {
                userMenu.classList.add('hidden');
            }
            
            const notificationPanel = document.getElementById('notification-panel');
            const notificationButton = document.getElementById('notification-btn');
            
            if (notificationPanel && notificationButton && !notificationPanel.contains(event.target) && !notificationButton.contains(event.target)) {
                notificationPanel.classList.remove('open');
            }
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            console.log('San Agustin Elementary School Teacher Portal loaded');
            
            const notificationItems = document.querySelectorAll('.notification-item');
            notificationItems.forEach(item => {
                item.addEventListener('click', function() {
                    const notificationId = this.getAttribute('data-id');
                    markNotificationAsRead(notificationId);
                });
            });
        });
    </script>
</body>
</html>