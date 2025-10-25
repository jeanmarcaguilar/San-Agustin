<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    $_SESSION['error'] = 'Please log in first.';
    header('Location: ../login.php');
    exit;
}

if (strtolower($_SESSION['role']) !== 'teacher') {
    $_SESSION['error'] = 'Access denied. Teacher access only.';
    header('Location: ../login.php');
    exit;
}

// Include database connections
require_once '../config/database.php';
$database = new Database();
$login_conn = null;
$teacher_conn = null;
$error_messages = [];
$students = [];
$class_average = 0;
$total_students = 0;
$avg_completion = 0;

try {
    // Get login connection
    $login_conn = $database->getLoginConnection();
    if (!$login_conn) {
        throw new Exception('Failed to connect to login database');
    }
    
    // Get teacher connection
    $teacher_conn = $database->getConnection('teacher');
    if (!$teacher_conn) {
        throw new Exception('Failed to connect to teacher database');
    }
    
    // Fetch teacher login account
    $stmt = $login_conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
    $stmt->execute([$_SESSION['user_id']]);
    $login_account = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$login_account) {
        throw new Exception('Invalid user account');
    }
    
    // Fetch teacher info
    $stmt = $teacher_conn->prepare("SELECT * FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Create basic teacher record if not exists
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
    
    // Set up user data
    $user = array_merge($user, [
        'id' => $user['user_id'] ?? $user['id'],
        'username' => $_SESSION['username'],
        'role' => $_SESSION['role'],
        'email' => $login_account['email'] ?? '',
        'initials' => !empty($initials) ? strtoupper($initials) : 'T',
        'first_name' => $user['first_name'] ?? 'Teacher',
        'last_name' => $user['last_name'] ?? '',
        'full_name' => trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')),
        'subject' => $user['subject'] ?? 'General',
        'grade_level' => $user['grade_level'] ?? null,
        'section' => $user['section'] ?? null,
        'teacher_id' => $user['teacher_id'] ?? ''
    ]);

    // Fetch pending notices count
    $pending_notices = 0;
    if (!empty($user['teacher_id'])) {
        $stmt = $teacher_conn->prepare("SELECT COUNT(*) as count FROM notices WHERE teacher_id = ? AND status = 'pending'");
        $stmt->execute([$user['teacher_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $pending_notices = $result ? (int)$result['count'] : 0;
    }

    // Fetch recent activities
    $recent_activities = [];
    if (!empty($user['teacher_id'])) {
        $stmt = $teacher_conn->prepare("SELECT * FROM activities WHERE teacher_id = ? ORDER BY activity_date DESC LIMIT 5");
        $stmt->execute([$user['teacher_id']]);
        $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get student progress data
    if (!empty($user['grade_level']) && !empty($user['section'])) {
        $stmt = $teacher_conn->prepare("
            SELECT s.id, s.student_id, s.first_name, s.last_name, 
                   sg.final_grade, sg.attendance_rate, sg.assignment_completion
            FROM students s
            LEFT JOIN student_grades sg ON s.id = sg.student_id AND sg.teacher_id = ?
            WHERE s.grade_level = ? AND s.section = ?
            ORDER BY s.last_name, s.first_name
        ");
        $stmt->execute([$user['teacher_id'], $user['grade_level'], $user['section']]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate class average and completion
        $total_grade = 0;
        $total_completion = 0;
        $total_students = count($students);
        foreach ($students as $student) {
            $total_grade += (float)($student['final_grade'] ?? 0);
            $total_completion += (float)($student['assignment_completion'] ?? 0);
        }
        $class_average = $total_students > 0 ? $total_grade / $total_students : 0;
        $avg_completion = $total_students > 0 ? $total_completion / $total_students : 0;
    }

} catch (Exception $e) {
    error_log("Student Progress Report error: " . $e->getMessage());
    $error_messages[] = "Error fetching student progress: " . $e->getMessage();
    $user = [
        'id' => $_SESSION['user_id'] ?? 0,
        'username' => $_SESSION['username'] ?? 'Guest',
        'role' => $_SESSION['role'] ?? 'guest',
        'first_name' => 'Guest',
        'last_name' => '',
        'email' => '',
        'initials' => 'G',
        'teacher_id' => '',
        'subject' => 'General',
        'grade_level' => null,
        'section' => null
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>San Agustin Elementary School - Student Progress Report</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary: #4F46E5;
            --primary-light: #6366F1;
            --primary-dark: #4338CA;
            --secondary: #1F2937;
            --secondary-light: #374151;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --gray-100: #F3F4F6;
            --gray-200: #E5E7EB;
            --gray-300: #D1D5DB;
            --gray-700: #374151;
            --gray-900: #111827;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            @apply bg-gray-50;
        }

        .sidebar {
            background: linear-gradient(180deg, #1F2937 0%, #111827 100%);
            transition: all 0.3s ease;
            z-index: 40;
        }

        .sidebar.collapsed {
            width: 80px;
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
            padding: 0.75rem 0;
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
                position: fixed;
                left: -100%;
                top: 0;
                bottom: 0;
                transition: left 0.3s ease;
            }

            .sidebar.open {
                left: 0;
            }

            .overlay.open {
                display: block;
            }
        }

        .header-bg {
            background: linear-gradient(90deg, #4F46E5 0%, #6366F1 100%);
        }

        .dashboard-card {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        .notification-dot {
            position: absolute;
            top: -5px;
            right: -5px;
            background-color: #EF4444;
            color: white;
            border-radius: 50%;
            width: 18px;
            height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            font-weight: bold;
        }

        .user-menu {
            display: none;
            min-width: 200px;
        }

        .user-menu.open {
            display: block;
        }

        .notification-panel {
            position: absolute;
            right: 0;
            top: 100%;
            width: 350px;
            background: white;
            border-radius: 0.5rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }

        .notification-panel.open {
            max-height: 500px;
            border: 1px solid #E5E7EB;
        }

        .notification-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #F3F4F6;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .notification-item:hover {
            background-color: #F9FAFB;
        }

        .notification-item.unread {
            background-color: #F0F9FF;
        }

        .notification-time {
            font-size: 0.75rem;
            color: #6B7280;
        }

        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }

        .custom-scrollbar::-webkit-scrollbar-track {
            background: #1F2937;
            border-radius: 3px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #4B5563;
            border-radius: 3px;
        }

        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #6B7280;
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
                    <a href="dashboard.php" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
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
                    <a href="reports.php" class="flex items-center p-3 rounded-lg bg-secondary-700 text-white transition-colors nav-item">
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
                <h1 class="text-xl font-semibold">Student Progress Report</h1>
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
                    <div id="notification-panel" class="notification-panel">
                        <div class="p-3 border-b border-gray-200 flex justify-between items-center">
                            <h3 class="font-medium">Notifications</h3>
                            <button class="text-sm text-primary-600 hover:text-primary-800" onclick="markAllAsRead()">
                                Mark all as read
                            </button>
                        </div>
                        <div class="max-h-80 overflow-y-auto">
                            <?php if (!empty($recent_activities) && is_array($recent_activities)): ?>
                                <?php foreach ($recent_activities as $activity): 
                                    if (!is_array($activity) || !isset($activity['id'])) continue;
                                    $message = $activity['message'] ?? 'New notification';
                                    $isRead = $activity['is_read'] ?? false;
                                    $icon = $activity['icon'] ?? 'fa-bell';
                                    $type = $activity['type'] ?? 'gray';
                                    $createdAt = $activity['created_at'] ?? 'now';
                                    $formattedDate = date('M j, Y g:i A', strtotime($createdAt));
                                ?>
                                    <div class="notification-item <?php echo $isRead ? '' : 'unread'; ?>" data-id="<?php echo htmlspecialchars($activity['id']); ?>">
                                        <div class="flex items-start">
                                            <div class="flex-shrink-0 pt-0.5">
                                                <i class="fas <?php echo htmlspecialchars($icon); ?> text-<?php echo htmlspecialchars($type); ?>-500"></i>
                                            </div>
                                            <div class="ml-3">
                                                <p class="text-sm text-gray-800"><?php echo htmlspecialchars($message); ?></p>
                                                <p class="text-xs text-gray-500 mt-1">
                                                    <i class="far fa-clock mr-1"></i>
                                                    <?php echo $formattedDate; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="p-4 text-center text-gray-500">
                                    No new notifications
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="p-3 border-t border-gray-200 text-center">
                            <a href="notifications.php" class="text-sm font-medium text-primary-600 hover:text-primary-800">
                                View all notifications
                            </a>
                        </div>
                    </div>
                </div>
                <!-- User Menu -->
                <div class="relative">
                    <button id="user-menu-btn" onclick="toggleUserMenu()" class="w-10 h-10 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold shadow-md">
                        <?php echo htmlspecialchars($user['initials'] ?? 'T'); ?>
                    </button>
                    <div id="user-menu" class="user-menu absolute right-0 top-12 mt-2 w-48 bg-white rounded-lg shadow-xl py-1 z-50 hidden border border-gray-200">
                        <div class="px-4 py-2 border-b border-gray-100">
                            <p class="text-sm font-medium text-gray-900"><?php echo trim(htmlspecialchars($user['full_name'])); ?></p>
                            <p class="text-xs text-gray-500 truncate"><?php echo !empty($user['email']) ? htmlspecialchars($user['email']) : 'No email'; ?></p>
                        </div>
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-user-circle mr-2 w-5"></i>Profile
                        </a>
                        <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-cog mr-2 w-5"></i>Settings
                        </a>
                        <div class="border-t border-gray-100"></div>
                        <a href="../logout.php" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50">
                            <i class="fas fa-sign-out-alt mr-2 w-5"></i>Sign out
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 overflow-y-auto p-5">
            <!-- Error Messages -->
            <?php if (!empty($error_messages)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
                    <div class="flex">
                        <div class="py-1">
                            <i class="fas fa-exclamation-circle mr-3"></i>
                        </div>
                        <div>
                            <?php foreach ($error_messages as $error): ?>
                                <p class="font-bold"><?php echo htmlspecialchars($error); ?></p>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="bg-white rounded-xl p-6 mb-6 shadow-sm border border-gray-200">
                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Student Progress Report</h1>
                        <div class="flex items-center text-gray-600 mt-2">
                            <i class="fas fa-chalkboard-teacher mr-2"></i>
                            <span>Teacher: <?php echo htmlspecialchars($user['full_name']); ?></span>
                            <?php if (!empty($user['subject'])): ?>
                                <span class="mx-2">•</span>
                                <i class="fas fa-book mr-1"></i>
                                <span><?php echo htmlspecialchars($user['subject']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($user['grade_level']) && !empty($user['section'])): ?>
                                <span class="mx-2">•</span>
                                <i class="fas fa-layer-group mr-1"></i>
                                <span>Grade <?php echo htmlspecialchars($user['grade_level']); ?> - <?php echo htmlspecialchars($user['section']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="mt-4 md:mt-0 flex space-x-2">
                        <button onclick="exportToExcel()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i class="fas fa-file-export mr-2"></i> Export to Excel
                        </button>
                        <button onclick="window.print()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                            <i class="fas fa-print mr-2"></i> Print
                        </button>
                        <a href="reports.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700">
                            <i class="fas fa-arrow-left mr-2"></i> Back to Reports
                        </a>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 dashboard-card">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-500 mr-4">
                            <i class="fas fa-users text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Students</p>
                            <p class="text-2xl font-bold"><?php echo $total_students; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 dashboard-card">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-500 mr-4">
                            <i class="fas fa-chart-line text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Class Average</p>
                            <p class="text-2xl font-bold"><?php echo number_format($class_average, 1); ?>%</p>
                        </div>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-200 dashboard-card">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-500 mr-4">
                            <i class="fas fa-tasks text-xl"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Average Completion</p>
                            <p class="text-2xl font-bold"><?php echo number_format($avg_completion, 0); ?>%</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Student Progress Table -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
                    <div class="flex items-center">
                        <i class="fas fa-user-graduate text-blue-500 mr-2"></i>
                        <h3 class="text-base font-medium text-gray-900">Student Progress</h3>
                        <span class="ml-2 text-sm text-gray-500"><?php echo count($students); ?> students</span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Grade</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Assignments</th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (count($students) > 0): ?>
                                <?php foreach ($students as $student): ?>
                                    <tr class="hover:bg-gray-50 transition-colors duration-150">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-medium">
                                                    <?php echo strtoupper(substr($student['first_name'] ?? '', 0, 1) . substr($student['last_name'] ?? '', 0, 1)); ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '')); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($student['student_id'] ?? 'N/A'); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <?php 
                                                $grade = $student['final_grade'] ?? 0;
                                                $grade_class = '';
                                                if ($grade >= 90) $grade_class = 'text-green-600 bg-green-50';
                                                elseif ($grade >= 80) $grade_class = 'text-blue-600 bg-blue-50';
                                                elseif ($grade >= 75) $grade_class = 'text-yellow-600 bg-yellow-50';
                                                else $grade_class = 'text-red-600 bg-red-50';
                                            ?>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $grade_class; ?>">
                                                <?php echo $grade ? number_format($grade, 1) . '%' : 'N/A'; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="w-full bg-gray-200 rounded-full h-2">
                                                <?php 
                                                    $attendance = $student['attendance_rate'] ?? 0;
                                                    $attendance_color = $attendance >= 90 ? 'bg-green-500' : ($attendance >= 80 ? 'bg-blue-500' : 'bg-yellow-500');
                                                ?>
                                                <div class="h-2 rounded-full <?php echo $attendance_color; ?>" style="width: <?php echo $attendance; ?>%"></div>
                                            </div>
                                            <div class="text-xs text-gray-500 text-center mt-1"><?php echo number_format($attendance, 0); ?>%</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="w-full bg-gray-200 rounded-full h-2">
                                                <?php 
                                                    $completion = $student['assignment_completion'] ?? 0;
                                                    $completion_color = $completion >= 90 ? 'bg-green-500' : ($completion >= 70 ? 'bg-blue-500' : 'bg-yellow-500');
                                                ?>
                                                <div class="h-2 rounded-full <?php echo $completion_color; ?>" style="width: <?php echo $completion; ?>%"></div>
                                            </div>
                                            <div class="text-xs text-gray-500 text-center mt-1"><?php echo number_format($completion, 0); ?>%</div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="student_details.php?id=<?php echo htmlspecialchars($student['id'] ?? ''); ?>" class="text-blue-600 hover:text-blue-800 mr-3">
                                                <i class="fas fa-eye mr-1"></i>View
                                            </a>
                                            <a href="student_grades.php?student_id=<?php echo htmlspecialchars($student['id'] ?? ''); ?>" class="text-green-600 hover:text-green-900">
                                                <i class="fas fa-edit mr-1"></i>Grades
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        <i class="fas fa-user-graduate text-3xl text-gray-200 mb-2"></i>
                                        <p class="text-sm font-medium text-gray-900">No students found</p>
                                        <p class="text-xs text-gray-500 mt-1">No students are assigned to this class.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="bg-gray-50 px-6 py-3 flex items-center justify-between border-t border-gray-200">
                    <div class="text-sm text-gray-500">
                        Showing <span class="font-medium">1</span> to <span class="font-medium"><?php echo count($students); ?></span> of <span class="font-medium"><?php echo count($students); ?></span> students
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
            sidebar.classList.toggle('open');
            overlay.classList.toggle('open');
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            sidebar.classList.remove('open');
            overlay.classList.remove('open');
        }

        // Toggle user menu
        function toggleUserMenu() {
            const menu = document.getElementById('user-menu');
            menu.classList.toggle('hidden');
        }

        // Toggle notifications panel
        function toggleNotifications() {
            const panel = document.getElementById('notification-panel');
            panel.classList.toggle('open');
        }

        // Mark notification as read
        function markNotificationAsRead(notificationId) {
            const notification = document.querySelector(`.notification-item[data-id="${notificationId}"]`);
            if (notification) {
                notification.classList.remove('unread');
                const countElement = document.querySelector('.notification-dot');
                if (countElement) {
                    const count = parseInt(countElement.textContent) - 1;
                    if (count > 0) {
                        countElement.textContent = count;
                    } else {
                        countElement.remove();
                    }
                }
            }
        }

        // Mark all notifications as read
        function markAllAsRead() {
            const notifications = document.querySelectorAll('.notification-item.unread');
            notifications.forEach(notification => {
                notification.classList.remove('unread');
            });
            const countElement = document.querySelector('.notification-dot');
            if (countElement) {
                countElement.remove();
            }
        }

        // Toggle sidebar collapse/expand
        function toggleSidebarCollapse() {
            const sidebar = document.getElementById('sidebar');
            const icon = document.getElementById('collapse-icon');
            const isCollapsed = sidebar.classList.contains('collapsed');
            
            if (isCollapsed) {
                sidebar.classList.remove('collapsed');
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
                localStorage.setItem('sidebarCollapsed', 'false');
            } else {
                sidebar.classList.add('collapsed');
                icon.classList.remove('fa-chevron-left');
                icon.classList.add('fa-chevron-right');
                localStorage.setItem('sidebarCollapsed', 'true');
            }
        }

        // Export to Excel (simplified client-side implementation)
        function exportToExcel() {
            const data = [
                ['Student', 'Student ID', 'Grade', 'Attendance', 'Assignments'],
                <?php foreach ($students as $student): ?>
                    [
                        '<?php echo addslashes($student['last_name'] . ', ' . $student['first_name']); ?>',
                        '<?php echo addslashes($student['student_id'] ?? 'N/A'); ?>',
                        '<?php echo $student['final_grade'] ? number_format($student['final_grade'], 1) . '%' : 'N/A'; ?>',
                        '<?php echo number_format($student['attendance_rate'] ?? 0, 0); ?>%',
                        '<?php echo number_format($student['assignment_completion'] ?? 0, 0); ?>%'
                    ],
                <?php endforeach; ?>
            ];
            
            const csvContent = data.map(row => row.join(',')).join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'student_progress_report.csv');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            console.log('San Agustin Elementary School Student Progress Report loaded');
            
            // Set sidebar state
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                const sidebar = document.getElementById('sidebar');
                const icon = document.getElementById('collapse-icon');
                if (sidebar && icon) {
                    sidebar.classList.add('collapsed');
                    icon.classList.remove('fa-chevron-left');
                    icon.classList.add('fa-chevron-right');
                }
            }
            
            // Add click events for notifications
            const notificationItems = document.querySelectorAll('.notification-item');
            notificationItems.forEach(item => {
                item.addEventListener('click', function() {
                    const notificationId = this.getAttribute('data-id');
                    markNotificationAsRead(notificationId);
                });
            });
        });

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
    </script>
</body>
</html>