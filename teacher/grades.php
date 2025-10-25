<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connections
$student_conn = null;
$teacher_conn = null;
$error_messages = [];

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

try {
    require_once '../config/database.php';
    $database = new Database();
    
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

    // Fetch teacher's classes for filter dropdown
    $classes = [];
    if (!empty($user['teacher_id'])) {
        $stmt = $teacher_conn->prepare("SELECT * FROM classes WHERE teacher_id = ? ORDER BY grade_level, section");
        $stmt->execute([$user['teacher_id']]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch all students for the add grade modal
    $students = [];
    if (!empty($user['teacher_id'])) {
        $stmt = $teacher_conn->prepare("SELECT id, student_id, first_name, last_name, grade_level, section FROM students ORDER BY grade_level, section, last_name, first_name");
        $stmt->execute();
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch grading periods
    $grading_periods = [];
    $stmt = $teacher_conn->prepare("SELECT DISTINCT grading_period FROM grades WHERE recorded_by = ? ORDER BY grading_period");
    $stmt->execute([$user['teacher_id']]);
    $grading_periods = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Fetch grades data
    $grades = [];
    $assignments_due = 0;
    $grading_progress = 0;
    $class_average = 0;

    $selected_class = isset($_GET['class_id']) ? $_GET['class_id'] : 'all';
    $selected_period = isset($_GET['grading_period']) ? $_GET['grading_period'] : 'all';

    if (!empty($user['teacher_id'])) {
        $query = "SELECT g.*, s.first_name, s.last_name, s.student_id, c.subject, c.grade_level, c.section 
                 FROM grades g 
                 JOIN students s ON g.student_id = s.student_id 
                 JOIN classes c ON g.class_id = c.id 
                 WHERE g.recorded_by = ?";
        
        $params = [$user['teacher_id']];

        if ($selected_class !== 'all') {
            $query .= " AND c.id = ?";
            $params[] = $selected_class;
        }

        if ($selected_period !== 'all') {
            $query .= " AND g.grading_period = ?";
            $params[] = $selected_period;
        }

        $stmt = $teacher_conn->prepare($query);
        $stmt->execute($params);
        $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate stats
        $stmt = $teacher_conn->prepare("SELECT COUNT(*) as count 
                                      FROM assignments 
                                      WHERE teacher_id = ? AND due_date >= CURDATE()");
        $stmt->execute([$user['teacher_id']]);
        $assignments_due = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

        if (!empty($grades)) {
            $total_grades = count($grades);
            $graded_count = 0;
            $total_score = 0;
            $valid_grades = 0;

            foreach ($grades as $grade) {
                if ($grade['score'] !== null) {
                    $graded_count++;
                    $total_score += (float)$grade['score'];
                    $valid_grades++;
                }
            }

            $grading_progress = $total_grades > 0 ? round(($graded_count / $total_grades) * 100, 1) : 0;
            $class_average = $valid_grades > 0 ? round($total_score / $valid_grades, 1) : 0;
        }
    }

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

} catch (Exception $e) {
    error_log("Grades page error: " . $e->getMessage());
    $error_messages[] = "An error occurred while loading the grades page: " . $e->getMessage();
    $user = [
        'id' => $_SESSION['user_id'] ?? 0,
        'username' => $_SESSION['username'] ?? 'Guest',
        'role' => $_SESSION['role'] ?? 'guest',
        'first_name' => 'Guest',
        'last_name' => '',
        'email' => '',
        'initials' => 'G',
        'teacher_id' => ''
    ];
    $classes = [];
    $grades = [];
    $grading_periods = [];
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

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 50;
            align-items: center;
            justify-content: center;
        }

        .modal.open {
            display: flex;
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
                    <a href="grades.php" class="flex items-center p-3 rounded-lg bg-secondary-700 text-white transition-colors nav-item">
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
                <h1 class="text-xl font-bold">Gradebook</h1>
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
                    <div id="user-menu" class="absolute right-0 top-12 mt-2 w-48 bg-white rounded-lg shadow-xl py-1 z-50 hidden border border-gray-200">
                        <div class="px-4 py-2 border-b border-gray-100">
                            <p class="text-gray-800 font-medium"><?php echo trim(htmlspecialchars($user['full_name'])); ?></p>
                            <p class="text-xs text-gray-500 truncate"><?php echo !empty($user['email']) ? htmlspecialchars($user['email']) : 'No email'; ?></p>
                        </div>
                        <a href="profile.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 transition-colors">Profile</a>
                        <a href="settings.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 transition-colors">Settings</a>
                        <a href="../logout.php" class="block px-4 py-2 text-gray-700 hover:bg-gray-100 transition-colors">Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Add Grade Modal -->
        <div id="addGradeModal" class="modal">
            <div class="bg-white rounded-xl w-full max-w-2xl mx-4 max-h-[90vh] flex flex-col shadow-sm border border-gray-200">
                <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-800">Add New Grade</h3>
                    <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeModal('addGradeModal')">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form id="addGradeForm" class="flex-1 overflow-y-auto">
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label for="studentSelect" class="block text-sm font-medium text-gray-700 mb-2">Student <span class="text-red-500">*</span></label>
                                <select id="studentSelect" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" required>
                                    <option value="">Select Student</option>
                                    <?php 
                                    if (!empty($students)) {
                                        foreach ($students as $student) {
                                            echo "<option value='{$student['id']}'>{$student['first_name']} {$student['last_name']} - Grade {$student['grade_level']}{$student['section']} (ID: {$student['student_id']})</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label for="classSelect" class="block text-sm font-medium text-gray-700 mb-2">Class <span class="text-red-500">*</span></label>
                                <select id="classSelect" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" required>
                                    <option value="">Select Class</option>
                                    <?php 
                                    if (!empty($classes)) {
                                        foreach ($classes as $class) {
                                            echo "<option value='{$class['id']}'>{$class['subject']} - Grade {$class['grade_level']}{$class['section']}</option>";
                                        }
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label for="gradeTitle" class="block text-sm font-medium text-gray-700 mb-2">Title <span class="text-red-500">*</span></label>
                                <input type="text" id="gradeTitle" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="e.g., Quiz 1, Midterm Exam" required>
                            </div>
                            <div>
                                <label for="gradeType" class="block text-sm font-medium text-gray-700 mb-2">Assessment Type</label>
                                <select id="gradeType" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" required>
                                    <option value="quiz">Quiz</option>
                                    <option value="exam">Exam</option>
                                    <option value="project">Project</option>
                                    <option value="homework">Homework</option>
                                    <option value="participation">Participation</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div>
                                <label for="gradingPeriod" class="block text-sm font-medium text-gray-700 mb-2">Grading Period</label>
                                <select id="gradingPeriod" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" required>
                                    <option value="1st Quarter">1st Quarter</option>
                                    <option value="2nd Quarter">2nd Quarter</option>
                                    <option value="3rd Quarter">3rd Quarter</option>
                                    <option value="4th Quarter">4th Quarter</option>
                                    <option value="Midterm">Midterm</option>
                                    <option value="Final">Final</option>
                                </select>
                            </div>
                            <div>
                                <label for="gradeDate" class="block text-sm font-medium text-gray-700 mb-2">Date</label>
                                <input type="date" id="gradeDate" value="<?php echo date('Y-m-d'); ?>" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" required>
                            </div>
                            <div>
                                <label for="gradeScore" class="block text-sm font-medium text-gray-700 mb-2">Score <span class="text-red-500">*</span></label>
                                <input type="number" id="gradeScore" min="0" max="100" step="0.01" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="0-100" required>
                            </div>
                            <div>
                                <label for="gradeMaxScore" class="block text-sm font-medium text-gray-700 mb-2">Max Score</label>
                                <input type="number" id="gradeMaxScore" min="1" step="0.01" value="100" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" required>
                            </div>
                            <div class="md:col-span-2">
                                <label for="gradeNotes" class="block text-sm font-medium text-gray-700 mb-2">Notes</label>
                                <textarea id="gradeNotes" rows="2" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="Additional notes (optional)"></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="px-6 py-3 bg-gray-50 border-t border-gray-200 flex justify-end space-x-3">
                        <button type="button" onclick="closeModal('addGradeModal')" class="px-4 py-2 border border-gray-200 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-save mr-2"></i>Save Grade
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Export Modal -->
        <div id="exportModal" class="modal">
            <div class="bg-white rounded-xl w-full max-w-md mx-4 shadow-sm border border-gray-200">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">Export Grades</h3>
                </div>
                <form id="exportForm" class="p-6">
                    <div class="mb-4">
                        <label for="exportClass" class="block text-sm font-medium text-gray-700 mb-2">Class</label>
                        <select id="exportClass" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <option value="all">All Classes</option>
                            <?php 
                            if (!empty($classes)) {
                                foreach ($classes as $class) {
                                    echo "<option value='{$class['id']}'>{$class['subject']} - Grade {$class['grade_level']}{$class['section']}</option>";
                                }
                            }
                            ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="exportStartDate" class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                            <input type="date" id="exportStartDate" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                        </div>
                        <div>
                            <label for="exportEndDate" class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                            <input type="date" id="exportEndDate" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="exportFormat" class="block text-sm font-medium text-gray-700 mb-2">Format</label>
                        <select id="exportFormat" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <option value="csv">CSV</option>
                            <option value="excel">Excel</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </div>
                    <div class="px-6 py-3 bg-gray-50 border-t border-gray-200 flex justify-end space-x-3">
                        <button type="button" onclick="closeModal('exportModal')" class="px-4 py-2 border border-gray-200 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            Cancel
                        </button>
                        <button type="button" onclick="exportGrades()" class="px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 flex items-center">
                            <i class="fas fa-download mr-2"></i>Export
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Main Content Area -->
        <main class="flex-1 overflow-y-auto bg-gray-50">
            <div class="p-5">
                <!-- Error Messages -->
                <?php if (!empty($error_messages)): ?>
                    <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars(implode("<br>", $error_messages)); ?>
                    </div>
                <?php endif; ?>

                <!-- Header Section -->
                <div class="bg-white rounded-xl p-6 mb-6 border border-gray-200 shadow-sm dashboard-card">
                    <div class="flex flex-col md:flex-row md:items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800 mb-2">Gradebook</h1>
                            <div class="flex items-center text-gray-600">
                                <i class="fas fa-chalkboard-teacher mr-2"></i>
                                <span>Teacher ID: <?php echo htmlspecialchars($user['teacher_id']); ?></span>
                                <?php if (!empty($user['subject'])): ?>
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-book mr-1"></i>
                                    <span><?php echo htmlspecialchars($user['subject']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-4 md:mt-0">
                            <button onclick="openAddGradeModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg shadow-sm text-white bg-primary-600 hover:bg-primary-700 focus:outline-none">
                                <i class="fas fa-plus mr-2"></i>Add Grade
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 mb-6">
                    <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-600">Assignments Due</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $assignments_due; ?></h3>
                                <p class="text-xs text-gray-500">Pending submissions</p>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center">
                                <i class="fas fa-tasks text-blue-500"></i>
                            </div>
                        </div>
                    </div>
                    <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-600">Grading Progress</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $grading_progress; ?>%</h3>
                                <div class="mt-2 w-full bg-gray-200 rounded-full h-2.5">
                                    <div class="bg-green-500 h-2.5 rounded-full" style="width: <?php echo $grading_progress; ?>%"></div>
                                </div>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-green-100 flex items-center justify-center">
                                <i class="fas fa-check-circle text-green-500"></i>
                            </div>
                        </div>
                    </div>
                    <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-600">Class Average</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $class_average ?: '-'; ?></h3>
                                <p class="text-xs text-gray-500"><?php echo $class_average ? 'Based on graded assignments' : 'No data available'; ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-purple-100 flex items-center justify-center">
                                <i class="fas fa-chart-line text-purple-500"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Gradebook -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 dashboard-card">
                    <div class="px-4 py-3 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-start sm:items-center">
                        <div class="flex items-center mb-2 sm:mb-0">
                            <i class="fas fa-chart-bar text-primary-500 mr-2"></i>
                            <h3 class="text-base font-semibold text-gray-800">Class Grades</h3>
                            <span class="ml-2 text-sm text-gray-500"><?php echo count($grades); ?> records</span>
                        </div>
                        <div class="flex space-x-2">
                            <select id="class-filter" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-primary-500 focus:border-primary-500" onchange="filterGrades()">
                                <option value="all">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['id']); ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['subject'] . ' - Grade ' . $class['grade_level'] . ' ' . $class['section']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <select id="period-filter" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-primary-500 focus:border-primary-500" onchange="filterGrades()">
                                <option value="all">All Grading Periods</option>
                                <?php foreach ($grading_periods as $period): ?>
                                    <option value="<?php echo htmlspecialchars($period); ?>" <?php echo $selected_period == $period ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($period); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button onclick="openExportModal()" class="inline-flex items-center px-4 py-1.5 border border-transparent text-sm font-medium rounded-lg shadow-sm text-white bg-primary-600 hover:bg-primary-700 focus:outline-none">
                                <i class="fas fa-download mr-2"></i>Export
                            </button>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto" style="max-height: 600px;">
                        <?php if (!empty($grades)): ?>
                            <ul class="divide-y divide-gray-200">
                                <?php foreach ($grades as $grade): ?>
                                    <li class="px-4 py-4 hover:bg-gray-50 transition-colors duration-150">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 w-10 h-10 rounded-full bg-primary-100 flex items-center justify-center text-primary-600 font-medium">
                                                    <?php 
                                                        $student_initials = '';
                                                        if (!empty($grade['first_name'])) $student_initials .= $grade['first_name'][0];
                                                        if (!empty($grade['last_name'])) $student_initials .= $grade['last_name'][0];
                                                        echo htmlspecialchars(strtoupper($student_initials) ?: 'S');
                                                    ?>
                                                </div>
                                                <div class="ml-3 flex-1 min-w-0">
                                                    <p class="text-sm font-medium text-gray-800 truncate">
                                                        <?php echo htmlspecialchars($grade['first_name'] . ' ' . $grade['last_name']); ?>
                                                    </p>
                                                    <div class="mt-0.5 text-xs text-gray-500 flex items-center flex-wrap">
                                                        <span><?php echo htmlspecialchars($grade['student_id'] ?? 'N/A'); ?></span>
                                                        <?php if (!empty($grade['subject']) && !empty($grade['grade_level']) && !empty($grade['section'])): ?>
                                                            <span class="mx-1">•</span>
                                                            <span><?php echo htmlspecialchars($grade['subject'] . ' - Grade ' . $grade['grade_level'] . ' ' . $grade['section']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($grade['grading_period'])): ?>
                                                            <span class="mx-1">•</span>
                                                            <span><?php echo htmlspecialchars($grade['grading_period']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($grade['score'] !== null): ?>
                                                            <span class="mx-1">•</span>
                                                            <span class="text-primary-600"><?php echo htmlspecialchars($grade['score']); ?>%</span>
                                                        <?php else: ?>
                                                            <span class="mx-1">•</span>
                                                            <span class="text-gray-500">Not graded</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="flex items-center space-x-2">
                                                <a href="edit_grade.php?id=<?php echo htmlspecialchars($grade['id']); ?>" class="text-xs text-gray-600 hover:text-gray-800">
                                                    <i class="fas fa-edit mr-1"></i>Edit
                                                </a>
                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="p-8 text-center">
                                <i class="fas fa-book-open text-4xl text-gray-200 mb-3"></i>
                                <h3 class="text-lg font-medium text-gray-800">No class selected</h3>
                                <p class="mt-1 text-sm text-gray-500">Please select a class to view grades</p>
                            </div>
                        <?php endif; ?>
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

        // Modal functions
        function openAddGradeModal() {
            document.getElementById('addGradeModal').classList.add('open');
            document.body.classList.add('overflow-hidden');
        }

        function openExportModal() {
            const today = new Date();
            const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
            const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            
            document.getElementById('exportStartDate').value = formatDate(firstDay);
            document.getElementById('exportEndDate').value = formatDate(lastDay);
            
            document.getElementById('exportModal').classList.add('open');
            document.body.classList.add('overflow-hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('open');
            document.body.classList.remove('overflow-hidden');
        }

        function formatDate(date) {
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            return `${year}-${month}-${day}`;
        }

        // Filter grades
        function filterGrades() {
            const classFilter = document.getElementById('class-filter').value;
            const periodFilter = document.getElementById('period-filter').value;
            const url = new URL(window.location.href);
            url.searchParams.set('class_id', classFilter);
            url.searchParams.set('grading_period', periodFilter);
            window.location.href = url.toString();
        }

        // Handle Add Grade form submission
        document.getElementById('addGradeForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = {
                student_id: document.getElementById('studentSelect').value,
                class_id: document.getElementById('classSelect').value,
                title: document.getElementById('gradeTitle').value,
                type: document.getElementById('gradeType').value,
                grading_period: document.getElementById('gradingPeriod').value,
                grade_date: document.getElementById('gradeDate').value,
                score: document.getElementById('gradeScore').value,
                max_score: document.getElementById('gradeMaxScore').value,
                notes: document.getElementById('gradeNotes').value
            };
            
            // Validate required fields
            if (!formData.student_id || !formData.class_id || !formData.title || !formData.score) {
                showAlert('Please fill in all required fields.', 'error');
                return;
            }
            
            fetch('save_grade.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(formData)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showAlert('Grade added successfully!', 'success');
                    closeModal('addGradeModal');
                    this.reset();
                    setTimeout(() => {
                        window.location.reload();
                    }, 1500);
                } else {
                    throw new Error(data.message || 'Failed to save grade');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Error: ' + (error.message || 'Failed to save grade. Please try again.'), 'error');
            });
        });

        // Handle Export functionality
        function exportGrades() {
            const classId = document.getElementById('exportClass').value;
            const startDate = document.getElementById('exportStartDate').value;
            const endDate = document.getElementById('exportEndDate').value;
            const format = document.getElementById('exportFormat').value;
            
            if (!startDate || !endDate) {
                showAlert('Please select both start and end dates', 'error');
                return;
            }
            
            if (new Date(startDate) > new Date(endDate)) {
                showAlert('Start date cannot be after end date', 'error');
                return;
            }
            
            const exportBtn = document.querySelector('#exportModal button[onclick="exportGrades()"]');
            const originalBtnText = exportBtn.innerHTML;
            exportBtn.disabled = true;
            exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Preparing export...';
            
            let url = `export_grades.php?start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}&format=${encodeURIComponent(format)}`;
            
            if (classId !== 'all') {
                url += `&class_id=${encodeURIComponent(classId)}`;
            }
            
            if (format === 'pdf') {
                const newWindow = window.open(url, '_blank');
                if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
                    showAlert('Popup was blocked. Please allow popups for this site to download the PDF.', 'error');
                    exportBtn.disabled = false;
                    exportBtn.innerHTML = originalBtnText;
                    return;
                }
                
                setTimeout(() => {
                    exportBtn.disabled = false;
                    exportBtn.innerHTML = originalBtnText;
                    closeModal('exportModal');
                }, 2000);
                
                return;
            }
            
            fetch(url, {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('Export failed. Please try again.');
                }
                return response.blob();
            })
            .then(blob => {
                const downloadUrl = window.URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = downloadUrl;
                
                let filename = `grades_export_${new Date().toISOString().slice(0, 10)}.${format === 'excel' ? 'xls' : format}`;
                
                link.setAttribute('download', filename);
                document.body.appendChild(link);
                link.click();
                
                setTimeout(() => {
                    document.body.removeChild(link);
                    window.URL.revokeObjectURL(downloadUrl);
                }, 100);
                
                showAlert('Export completed successfully!', 'success');
            })
            .catch(error => {
                console.error('Export error:', error);
                showAlert(error.message || 'An error occurred during export. Please try again.', 'error');
            })
            .finally(() => {
                exportBtn.disabled = false;
                exportBtn.innerHTML = originalBtnText;
                closeModal('exportModal');
            });
        }

        // Show alert message
        function showAlert(message, type = 'success') {
            const alertDiv = document.createElement('div');
            alertDiv.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg ${
                type === 'success' ? 'bg-green-100 text-green-700 border-l-4 border-green-500' : 
                'bg-red-100 text-red-700 border-l-4 border-red-500'
            }`;
            alertDiv.innerHTML = `
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-2"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium">${message}</p>
                    </div>
                </div>
            `;
            document.body.appendChild(alertDiv);
            setTimeout(() => {
                alertDiv.remove();
            }, 3000);
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

        // Close modals with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modals = ['addGradeModal', 'exportModal'];
                modals.forEach(modalId => {
                    const modal = document.getElementById(modalId);
                    if (modal.classList.contains('open')) {
                        closeModal(modalId);
                    }
                });
            }
        });

        // Initialize page
        document.addEventListener('DOMContentLoaded', function() {
            console.log('San Agustin Elementary School Grades Page loaded');
            
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