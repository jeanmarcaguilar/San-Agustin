<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connections
$student_conn = null;
$teacher_conn = null;
$error_messages = [];

// Handle delete request - FIXED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = $_POST['delete_id'];
    
    try {
        require_once '../config/database.php';
        $database = new Database();
        $teacher_conn = $database->getConnection('teacher');
        
        // First, get the student's database ID using the student_id
        $stmt = $teacher_conn->prepare("SELECT id FROM students WHERE student_id = ?");
        $stmt->execute([$delete_id]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            // Delete from class_students first (foreign key constraint)
            $stmt = $teacher_conn->prepare("DELETE FROM class_students WHERE student_id = ?");
            $stmt->execute([$delete_id]);
            
            // Then delete from students table
            $stmt = $teacher_conn->prepare("DELETE FROM students WHERE student_id = ?");
            $stmt->execute([$delete_id]);
            
            $_SESSION['success'] = 'Student deleted successfully.';
        } else {
            $_SESSION['error'] = 'Student not found.';
        }
        
        header('Location: students.php');
        exit;
        
    } catch (PDOException $e) {
        error_log("Error deleting student: " . $e->getMessage());
        $_SESSION['error'] = 'Error deleting student. Please try again.';
        header('Location: students.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $delete_id = intval($_POST['delete_id']);
    try {
        $stmt = $conn->prepare("DELETE FROM students WHERE id = ?");
        $stmt->execute([$delete_id]);
        echo "<script>alert('Student deleted successfully.'); window.location.href = window.location.href;</script>";
        exit;
    } catch (PDOException $e) {
        echo "<script>alert('Error deleting student: " . $e->getMessage() . "');</script>";
    }
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

    // Fetch students from registrar_db with their class information
    $students = [];
    if (!empty($user['teacher_id'])) {
        // Get registrar database connection
        $registrar_conn = $database->getConnection('registrar');
        
        $query = "SELECT 
                    s.*,
                    c.id as class_id,
                    c.subject,
                    c.grade_level as class_grade,
                    c.section as class_section,
                    c.schedule,
                    c.room
                 FROM registrar_db.students s 
                 LEFT JOIN teacher_db.class_students cs ON s.student_id = cs.student_id 
                 LEFT JOIN teacher_db.classes c ON cs.class_id = c.id AND c.teacher_id = ?
                 WHERE s.status = 'Active'
                 ORDER BY s.last_name, s.first_name";
        
        $params = [$user['teacher_id']];
        
        // Handle class filter
        $class_id = isset($_GET['class_id']) ? $_GET['class_id'] : null;
        if ($class_id && $class_id !== 'all') {
            $query .= " AND c.id = ?";
            $params[] = $class_id;
        }
        
        $stmt = $registrar_conn->prepare($query);
        $stmt->execute($params);
        $rawStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Process the raw data to group classes by student
        $students = [];
        foreach ($rawStudents as $row) {
            $studentId = $row['student_id'];
            
            if (!isset($students[$studentId])) {
                // Initialize student data with null coalescing for optional fields
                $students[$studentId] = [
                    'id' => $row['id'] ?? null,
                    'student_id' => $row['student_id'] ?? '',
                    'first_name' => $row['first_name'] ?? '',
                    'last_name' => $row['last_name'] ?? '',
                    'middle_name' => $row['middle_name'] ?? '',
                    'suffix' => $row['suffix'] ?? '',
                    'gender' => $row['gender'] ?? '',
                    'birthdate' => $row['birth_date'] ?? ($row['birthdate'] ?? null),
                    'address' => $row['address'] ?? '',
                    'contact_number' => $row['contact_number'] ?? '',
                    'email' => $row['email'] ?? '',
                    'lrn' => $row['lrn'] ?? '',
                    'grade_level' => $row['grade_level'] ?? null,
                    'section' => $row['section'] ?? '',
                    'status' => $row['status'] ?? 'active',
                    'classes' => []
                ];
            }
            
            // Add class information if not already added
            $classKey = $row['class_id'];
            if (!empty($classKey) && !isset($students[$studentId]['classes'][$classKey])) {
                $students[$studentId]['classes'][$classKey] = [
                    'class_id' => $row['class_id'],
                    'subject' => $row['subject'],
                    'grade_level' => $row['class_grade'],
                    'section' => $row['class_section'],
                    'schedule' => $row['schedule'],
                    'room' => $row['room']
                ];
            }
        }
        
        // Convert to sequential array
        $students = array_values($students);
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
    error_log("Students page error: " . $e->getMessage());
    $error_messages[] = "An error occurred while loading the students page: " . $e->getMessage();
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
    $students = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>San Agustin Elementary School - Student Management</title>
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
                    <a href="students.php" class="flex items-center p-3 rounded-lg bg-secondary-700 text-white transition-colors nav-item">
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
                <h1 class="text-xl font-bold">Student Management</h1>
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

<!-- Add this section for success/error messages from session -->
<?php if (isset($_SESSION['success'])): ?>
    <div class="bg-green-100 text-green-700 p-4 rounded-lg mb-6">
        <i class="fas fa-check-circle mr-2"></i>
        <?php echo htmlspecialchars($_SESSION['success']); ?>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if (isset($_SESSION['error'])): ?>
    <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-6">
        <i class="fas fa-exclamation-circle mr-2"></i>
        <?php echo htmlspecialchars($_SESSION['error']); ?>
    </div>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

                <!-- Header Section -->
                <div class="bg-white rounded-xl p-6 mb-6 shadow-sm border border-gray-200 dashboard-card">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Student Management</h1>
                            <div class="flex items-center text-gray-600 mt-2">
                                <i class="fas fa-chalkboard-teacher mr-2"></i>
                                <span>Teacher ID: <?php echo htmlspecialchars($user['teacher_id']); ?></span>
                                <?php if (!empty($user['subject'])): ?>
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-book mr-1"></i>
                                    <span><?php echo htmlspecialchars($user['subject']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-4 md:mt-0 flex space-x-2">
                            <a href="view_all_students.php" class="inline-flex items-center px-4 py-2 border border-blue-300 text-sm font-medium rounded-md text-blue-700 bg-blue-50 hover:bg-blue-100 focus:outline-none">
                                <i class="fas fa-database mr-2"></i>View All Students (Registrar)
                            </a>
                            <p class="text-sm text-gray-600 italic flex items-center">
                                <i class="fas fa-info-circle mr-1"></i>Students are managed by the Registrar
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Student List -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 dashboard-card">
                    <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
                        <div class="flex items-center">
                            <i class="fas fa-user-graduate text-primary-500 mr-2"></i>
                            <h3 class="text-base font-semibold text-gray-800">Student List</h3>
                            <span class="student-count ml-2 text-sm text-gray-500"><?php echo count($students); ?> students</span>
                        </div>
                        <div class="flex space-x-2">
                            <select id="class-filter" class="border rounded-md px-3 py-1.5 text-sm focus:ring-primary-500 focus:border-primary-500" onchange="filterByClass()">
                                <option value="all">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['id']); ?>" <?php echo (isset($_GET['class_id']) && $_GET['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['subject'] . ' - Grade ' . $class['grade_level'] . $class['section']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="relative">
                                <input type="text" id="search-students" placeholder="Search students..." class="pl-10 pr-4 py-1.5 border border-gray-300 rounded-md text-sm focus:ring-primary-500 focus:border-primary-500">
                                <i class="fas fa-search absolute left-3 top-2.5 text-gray-400"></i>
                            </div>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto" style="max-height: 600px;">
                        <?php if (!empty($students)): ?>
                            <ul class="divide-y divide-gray-200">
                                <?php foreach ($students as $student): ?>
                                    <?php 
                                        // Prepare student data
                                        $fullName = htmlspecialchars(trim($student['first_name'] . ' ' . $student['last_name']));
                                        $initials = '';
                                        $classInfo = [];
                                        
                                        // Prepare class information
                                        if (!empty($student['classes'])) {
                                            foreach ($student['classes'] as $class) {
                                                $classInfo[] = [
                                                    'subject' => htmlspecialchars($class['subject']),
                                                    'grade_section' => 'Grade ' . $class['grade_level'] . ' - ' . $class['section'],
                                                    'schedule' => !empty($class['schedule']) ? htmlspecialchars($class['schedule']) : 'Schedule not set'
                                                ];
                                            }
                                        }
                                        if (!empty($student['first_name'])) $initials .= $student['first_name'][0];
                                        if (!empty($student['last_name'])) $initials .= $student['last_name'][0];
                                        $initials = strtoupper($initials ?: 'S');
                                        $studentId = htmlspecialchars($student['student_id'] ?? 'N/A');
                                        $email = !empty($student['email']) ? htmlspecialchars($student['email']) : '';
                                        $contact = !empty($student['contact_number']) ? htmlspecialchars($student['contact_number']) : '';
                                        
                                        // Get unique subjects from classes
                                        $subjects = [];
                                        if (!empty($classInfo)) {
                                            $subjects = array_unique(array_column($classInfo, 'subject'));
                                        }
                                        $hasMultipleClasses = count($student['classes'] ?? []) > 1;
                                    ?>
                                    <li class="px-4 py-4 hover:bg-gray-50 transition-colors duration-150" data-student-id="<?php echo $studentId; ?>">
                                        <div class="flex items-start justify-between">
                                            <div class="flex items-start flex-1 min-w-0">
                                                <div class="flex-shrink-0 w-12 h-12 rounded-full bg-primary-50 flex items-center justify-center text-primary-600 font-medium text-lg">
                                                    <?php echo $initials; ?>
                                                </div>
                                                <div class="ml-4 flex-1 min-w-0">
                                                    <div class="flex items-center">
                                                        <h3 class="text-sm font-semibold text-gray-900 truncate">
                                                            <?php echo $fullName; ?>
                                                        </h3>
                                                        <?php if ($email): ?>
                                                            <a href="mailto:<?php echo $email; ?>" class="ml-2 text-primary-500 hover:text-primary-700" title="Send Email">
                                                                <i class="fas fa-envelope text-xs"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($contact): ?>
                                                            <a href="tel:<?php echo $contact; ?>" class="ml-2 text-green-500 hover:text-green-700" title="Call">
                                                                <i class="fas fa-phone text-xs"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="mt-1 text-xs text-gray-600 space-x-3">
                                                        <span><span class="font-medium">ID:</span> <?php echo $studentId; ?></span>
                                                        <span class="text-gray-400">•</span>
                                                        <span><span class="font-medium">Grade:</span> <?php echo htmlspecialchars($student['grade_level'] ?? 'N/A'); ?></span>
                                                        <?php if (!empty($student['section'])): ?>
                                                            <span class="text-gray-400">•</span>
                                                            <span><span class="font-medium">Section:</span> <?php echo htmlspecialchars($student['section']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if (!empty($classInfo)): ?>
                                                        <div class="mt-2 space-y-1">
                                                            <?php foreach ($classInfo as $class): ?>
                                                                <div class="text-xs bg-gray-50 px-2 py-1.5 rounded border border-gray-100">
                                                                    <div class="font-medium text-gray-900"><?php echo $class['subject']; ?></div>
                                                                    <div class="text-xs text-gray-500 mt-0.5">
                                                                        <span class="inline-flex items-center">
                                                                            <i class="fas fa-chalkboard-teacher mr-1"></i>
                                                                            <?php echo $class['grade_section']; ?>
                                                                        </span>
                                                                        <?php if (!empty($class['schedule'])): ?>
                                                                            <span class="mx-2 text-gray-300">•</span>
                                                                            <span class="inline-flex items-center">
                                                                                <i class="far fa-clock mr-1"></i>
                                                                                <?php echo $class['schedule']; ?>
                                                                            </span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="mt-1 text-xs text-gray-500 italic">No class information available</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="flex items-center space-x-2 ml-2">
                                                <button onclick="viewStudent(<?php echo htmlspecialchars(json_encode($student)); ?>)" 
                                                        class="px-3 py-1.5 text-xs font-medium text-primary-600 hover:text-primary-800 rounded-md transition-colors flex items-center"
                                                        title="View Student Details">
                                                    <i class="fas fa-eye mr-1.5"></i> View
                                                </button>
                                                <button onclick="editStudent(<?php echo htmlspecialchars(json_encode($student)); ?>)" 
                                                        class="px-3 py-1.5 text-xs font-medium text-gray-600 hover:text-gray-800 rounded-md transition-colors flex items-center"
                                                        title="Edit Student">
                                                    <i class="fas fa-edit mr-1.5"></i> Edit
                                                </button>

<form method="POST" onsubmit="return confirmDelete();" style="display:inline;">
    <input type="hidden" name="delete_id" value="<?php echo $student['student_id']; ?>">
    <button type="submit" 
            class="px-3 py-1.5 text-xs font-medium text-red-600 hover:text-red-800 rounded-md transition-colors flex items-center"
            title="Delete Student">
        <i class="fas fa-trash-alt mr-1.5"></i> Delete
    </button>
</form>

                                            </div>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="p-4 text-center">
                                <i class="fas fa-user-graduate text-3xl text-gray-200 mb-2"></i>
                                <h3 class="text-sm font-medium text-gray-900">No students found</h3>
                                <p class="mt-1 text-xs text-gray-500">Add or import students to get started</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Import Students Modal -->
    <div id="importModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg w-full max-w-md mx-4">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold text-gray-800">Import Students</h3>
                    <button onclick="closeModal('importModal')" class="text-gray-400 hover:text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="importForm" action="import_students.php" method="post" enctype="multipart/form-data">
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Select Class</label>
                        <select name="class_id" class="w-full border rounded-md px-3 py-2 text-sm focus:ring-primary-500 focus:border-primary-500" required>
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['subject'] . ' - Grade ' . $class['grade_level'] . $class['section']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">CSV File</label>
                        <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md">
                            <div class="space-y-1 text-center">
                                <i class="fas fa-file-csv text-4xl text-gray-400"></i>
                                <div class="flex text-sm text-gray-600">
                                    <label class="relative cursor-pointer bg-white rounded-md font-medium text-primary-600 hover:text-primary-500 focus-within:outline-none">
                                        <span>Upload a file</span>
                                        <input id="csv-file" name="csv_file" type="file" class="sr-only" accept=".csv" required>
                                    </label>
                                    <p class="pl-1">or drag and drop</p>
                                </div>
                                <p class="text-xs text-gray-500">CSV up to 5MB</p>
                                <p id="file-name" class="text-sm text-gray-500 mt-2"></p>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeModal('importModal')" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none">
                            Import Students
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Student Modal -->
    <div id="addStudentModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg w-full max-w-md mx-4">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold text-gray-800">Add New Student</h3>
                    <button onclick="closeModal('addStudentModal')" class="text-gray-400 hover:text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="addStudentForm" action="add_student.php" method="post">
                    <div class="grid grid-cols-1 gap-4 mb-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                            <input type="text" name="first_name" class="w-full border rounded-md px-3 py-2 text-sm focus:ring-primary-500 focus:border-primary-500" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                            <input type="text" name="last_name" class="w-full border rounded-md px-3 py-2 text-sm focus:ring-primary-500 focus:border-primary-500" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Student ID</label>
                            <input type="text" name="student_id" class="w-full border rounded-md px-3 py-2 text-sm focus:ring-primary-500 focus:border-primary-500" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Class</label>
                            <select name="class_id" class="w-full border rounded-md px-3 py-2 text-sm focus:ring-primary-500 focus:border-primary-500" required>
                                <option value="">-- Select Class --</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>">
                                        <?php echo htmlspecialchars($class['subject'] . ' - Grade ' . $class['grade_level'] . $class['section']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" name="email" class="w-full border rounded-md px-3 py-2 text-sm focus:ring-primary-500 focus:border-primary-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                            <input type="tel" name="contact_number" class="w-full border rounded-md px-3 py-2 text-sm focus:ring-primary-500 focus:border-primary-500">
                        </div>
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeModal('addStudentModal')" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none">
                            Add Student
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- View Student Modal -->
    <div id="viewStudentModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg w-full max-w-md mx-4">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold text-gray-800">Student Details</h3>
                    <button onclick="closeModal('viewStudentModal')" class="text-gray-400 hover:text-gray-500">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="space-y-4">
                    <div class="flex justify-center">
                        <div id="viewStudentAvatar" class="h-24 w-24 rounded-full bg-primary-50 flex items-center justify-center text-primary-600 text-2xl font-bold">
                            <!-- Avatar will be inserted here -->
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500">Full Name</p>
                            <p id="viewStudentName" class="font-medium"></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">LRN</p>
                            <p id="viewStudentLrn" class="font-medium"></p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500">Grade & Section</p>
                            <p id="viewStudentGradeSection" class="font-medium"></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Gender</p>
                            <p id="viewStudentGender" class="font-medium"></p>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Email</p>
                        <p id="viewStudentEmail" class="font-medium"></p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500">Contact Number</p>
                            <p id="viewStudentContact" class="font-medium"></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Birthdate</p>
                            <p id="viewStudentBirthdate" class="font-medium"></p>
                        </div>
                    </div>
                    <div>
                        <p class="text-sm text-gray-500">Address</p>
                        <p id="viewStudentAddress" class="font-medium"></p>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <p class="text-sm text-gray-500">Guardian Name</p>
                            <p id="viewStudentGuardian" class="font-medium"></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Guardian Contact</p>
                            <p id="viewStudentGuardianContact" class="font-medium"></p>
                        </div>
                    </div>
                </div>
                <div class="mt-6 flex justify-end">
                    <button type="button" onclick="closeModal('viewStudentModal')" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Student Modal -->
    <div id="editStudentModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-lg w-full max-w-2xl mx-4">
            <form id="editStudentForm" action="save_student.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" id="editStudentId" name="id">
                <div class="p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-semibold text-gray-800">Edit Student</h3>
                        <button type="button" onclick="closeModal('editStudentModal')" class="text-gray-400 hover:text-gray-500">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="space-y-4">
                        <div class="flex items-center space-x-6">
                            <div class="flex-shrink-0">
                                <div class="relative group">
                                    <div id="editStudentAvatar" class="h-20 w-20 rounded-full bg-primary-50 flex items-center justify-center text-primary-600 text-xl font-bold">
                                        <!-- Avatar will be inserted here -->
                                    </div>
                                    <label for="editProfilePic" class="absolute bottom-0 right-0 bg-white rounded-full p-1 border border-gray-300 cursor-pointer group-hover:bg-gray-100">
                                        <i class="fas fa-camera text-gray-600"></i>
                                        <input type="file" id="editProfilePic" name="profile_pic" class="hidden" accept="image/*">
                                    </label>
                                </div>
                            </div>
                            <div class="flex-1">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label for="editFirstName" class="block text-sm font-medium text-gray-700">First Name</label>
                                        <input type="text" id="editFirstName" name="first_name" required
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                    </div>
                                    <div>
                                        <label for="editLastName" class="block text-sm font-medium text-gray-700">Last Name</label>
                                        <input type="text" id="editLastName" name="last_name" required
                                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="editLrn" class="block text-sm font-medium text-gray-700">LRN</label>
                                <input type="text" id="editLrn" name="lrn" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                            </div>
                            <div>
                                <label for="editEmail" class="block text-sm font-medium text-gray-700">Email</label>
                                <input type="email" id="editEmail" name="email"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="editGradeLevel" class="block text-sm font-medium text-gray-700">Grade Level</label>
                                <select id="editGradeLevel" name="grade_level" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?php echo $i; ?>">Grade <?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label for="editSection" class="block text-sm font-medium text-gray-700">Section</label>
                                <input type="text" id="editSection" name="section" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                            </div>
                            <div>
                                <label for="editGender" class="block text-sm font-medium text-gray-700">Gender</label>
                                <select id="editGender" name="gender" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="editBirthdate" class="block text-sm font-medium text-gray-700">Birthdate</label>
                                <input type="date" id="editBirthdate" name="birthdate" required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                            </div>
                            <div>
                                <label for="editContactNumber" class="block text-sm font-medium text-gray-700">Contact Number</label>
                                <input type="tel" id="editContactNumber" name="contact_number"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                            </div>
                        </div>

                        <div>
                            <label for="editAddress" class="block text-sm font-medium text-gray-700">Address</label>
                            <textarea id="editAddress" name="address" rows="2"
                                class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"></textarea>
                        </div>

                        <div class="border-t border-gray-200 pt-4">
                            <h4 class="text-sm font-medium text-gray-700 mb-3">Guardian Information</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="editGuardianName" class="block text-sm font-medium text-gray-700">Guardian Name</label>
                                    <input type="text" id="editGuardianName" name="guardian_name"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                </div>
                                <div>
                                    <label for="editGuardianContact" class="block text-sm font-medium text-gray-700">Guardian Contact</label>
                                    <input type="tel" id="editGuardianContact" name="guardian_contact"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end space-x-3">
                        <button type="button" onclick="closeModal('editStudentModal')" class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none">
                            Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // View student details
        function viewStudent(student) {
            // Set student details in the view modal
            const fullName = `${student.first_name} ${student.last_name}`;
            const initials = (student.first_name.charAt(0) + student.last_name.charAt(0)).toUpperCase();
            
            // Set avatar
            const avatarContainer = document.getElementById('viewStudentAvatar');
            if (student.profile_pic) {
                avatarContainer.innerHTML = `<img src="../Uploads/${student.profile_pic}" alt="${fullName}" class="h-full w-full object-cover">`;
            } else {
                avatarContainer.textContent = initials;
                avatarContainer.className = 'h-24 w-24 rounded-full bg-primary-50 flex items-center justify-center text-2xl font-bold text-primary-600';
            }
            
            // Set student information
            document.getElementById('viewStudentName').textContent = fullName;
            document.getElementById('viewStudentLrn').textContent = student.lrn || 'N/A';
            document.getElementById('viewStudentGradeSection').textContent = `Grade ${student.grade_level} - ${student.section || 'N/A'}`;
            document.getElementById('viewStudentGender').textContent = student.gender || 'N/A';
            document.getElementById('viewStudentEmail').textContent = student.email || 'N/A';
            document.getElementById('viewStudentContact').textContent = student.contact_number || 'N/A';
            document.getElementById('viewStudentBirthdate').textContent = student.birthdate ? new Date(student.birthdate).toLocaleDateString() : 'N/A';
            document.getElementById('viewStudentAddress').textContent = student.address || 'N/A';
            document.getElementById('viewStudentGuardian').textContent = student.guardian_name || 'N/A';
            document.getElementById('viewStudentGuardianContact').textContent = student.guardian_contact || 'N/A';
            
            // Open the view modal
            openModal('viewStudentModal');
        }
        
        // Edit student details
        function editStudent(student) {
            // Set student ID
            document.getElementById('editStudentId').value = student.id;
            
            // Set avatar preview
            const avatarContainer = document.getElementById('editStudentAvatar');
            const initials = (student.first_name.charAt(0) + student.last_name.charAt(0)).toUpperCase();
            
            if (student.profile_pic) {
                avatarContainer.innerHTML = `<img src="../Uploads/${student.profile_pic}" alt="${student.first_name} ${student.last_name}" class="h-full w-full object-cover">`;
            } else {
                avatarContainer.textContent = initials;
                avatarContainer.className = 'h-20 w-20 rounded-full bg-primary-50 flex items-center justify-center text-xl font-bold text-primary-600';
            }
            
            // Set form values
            document.getElementById('editFirstName').value = student.first_name || '';
            document.getElementById('editLastName').value = student.last_name || '';
            document.getElementById('editLrn').value = student.lrn || '';
            document.getElementById('editEmail').value = student.email || '';
            document.getElementById('editGradeLevel').value = student.grade_level || '';
            document.getElementById('editSection').value = student.section || '';
            document.getElementById('editGender').value = student.gender || 'Male';
            document.getElementById('editBirthdate').value = student.birthdate || '';
            document.getElementById('editContactNumber').value = student.contact_number || '';
            document.getElementById('editAddress').value = student.address || '';
            document.getElementById('editGuardianName').value = student.guardian_name || '';
            document.getElementById('editGuardianContact').value = student.guardian_contact || '';
            
            // Handle profile picture change
            document.getElementById('editProfilePic').addEventListener('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        avatarContainer.innerHTML = `<img src="${e.target.result}" alt="Preview" class="h-full w-full object-cover">`;
                    };
                    reader.readAsDataURL(file);
                }
            });
            
            // Open the edit modal
            openModal('editStudentModal');
        }
        
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }

        // Handle file input display
        document.getElementById('csv-file').addEventListener('change', function(e) {
            const fileName = e.target.files[0] ? e.target.files[0].name : 'No file chosen';
            document.getElementById('file-name').textContent = fileName;
        });

        // Handle form submissions
        document.getElementById('importForm').addEventListener('submit', function(e) {
            const fileInput = document.getElementById('csv-file');
            if (!fileInput.files.length) {
                e.preventDefault();
                alert('Please select a CSV file to upload.');
                return false;
            }
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Importing...';
        });

        document.getElementById('addStudentForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Adding...';
            
            // Get form data
            const formData = new FormData(this);
            
            // Send AJAX request
            fetch('add_student.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    try {
                        // Close modal and reset form
                        closeModal('addStudentModal');
                        this.reset();
                        
                        // Show success message with student details
                        const student = data.student || {};
                        const studentName = student.name || 'Student';
                        const studentId = student.student_id || student.id || '';
                        const username = student.username || '';
                        const password = student.default_password || '';
                        
                        // Create success message with student credentials
                        let successMessage = `Student added successfully`;
                        if (username && password) {
                            successMessage += `\nUsername: ${username}\nPassword: ${password}`;
                        }
                        
                        showNotification('success', successMessage);
                        
                        // Add the new student to the list without refreshing
                        if (data.student) {
                            addStudentToList(data.student);
                            
                            // Update the student count
                            const countElement = document.querySelector('.student-count');
                            if (countElement) {
                                const currentCount = parseInt(countElement.textContent) || 0;
                                countElement.textContent = (currentCount + 1) + ' students';
                            }
                            
                            // Scroll to the newly added student
                            setTimeout(() => {
                                const newStudentElement = document.querySelector(`[data-student-id="${studentId}"]`);
                                if (newStudentElement) {
                                    newStudentElement.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                                    newStudentElement.classList.add('bg-primary-50');
                                    setTimeout(() => {
                                        newStudentElement.classList.remove('bg-primary-50');
                                    }, 2000);
                                }
                            }, 100);
                        }
                    } catch (error) {
                        console.error('Error processing response:', error);
                        showNotification('error', 'Student was added, but there was an error updating the interface. Please refresh the page.');
                    }
                } else {
                    // Show error message with debug info if available
                    const errorMessage = data.message || 'Failed to add student';
                    const debugInfo = data.debug ? `\n\nDebug: ${data.debug}` : '';
                    showNotification('error', `${errorMessage}${debugInfo}`);
                    
                    // Re-enable submit button
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = originalBtnText;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('error', 'An error occurred while adding the student');
                
                // Re-enable submit button
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            });
        });
        
        // Add new student to the list
        function addStudentToList(student) {
            let studentList = document.querySelector('ul.divide-y');
            const noStudentsMessage = document.querySelector('.text-center');
            const studentListContainer = document.querySelector('.flex-1.overflow-y-auto');
            
            // If no students message exists, remove it
            if (noStudentsMessage && noStudentsMessage.closest('.p-4')) {
                noStudentsMessage.closest('.p-4').remove();
            }
            
            // If student list doesn't exist, create it
            if (!studentList) {
                if (studentListContainer) {
                    studentList = document.createElement('ul');
                    studentList.className = 'divide-y divide-gray-200';
                    studentListContainer.appendChild(studentList);
                } else {
                    console.error('Could not find student list container');
                    window.location.reload();
                    return;
                }
            }
            
            // Create the new student element
            const newStudent = document.createElement('li');
            newStudent.className = 'px-4 py-4 hover:bg-gray-50 transition-colors duration-150';
            newStudent.setAttribute('data-student-id', student.student_id || student.id || '');
            
            // Get initials
            const nameParts = student.name ? student.name.split(' ') : [];
            const initials = nameParts.length >= 2 
                ? (nameParts[0][0] + nameParts[1][0]).toUpperCase()
                : (student.name && student.name[0] ? student.name[0].toUpperCase() : 'S');
            
            // Format class info if available
            let classInfo = '';
            if (student.grade_level || student.section) {
                classInfo = `
                    <div class="mt-0.5 text-xs text-gray-500 flex items-center">
                        <span>${student.student_id || student.id || ''}</span>
                        ${student.subject || student.grade_level || student.section ? `
                            <span class="mx-1">•</span>
                            <span>${student.subject || ''}${student.subject && (student.grade_level || student.section) ? ' - ' : ''}
                            ${student.grade_level ? 'Grade ' + student.grade_level : ''}${student.section || ''}</span>
                        ` : ''}
                    </div>
                `;
            }
            
            // Format the student name
            const studentName = student.name || `${student.first_name || ''} ${student.last_name || ''}`.trim() || 'New Student';
            
            // Create the student info HTML
            newStudent.innerHTML = `
                <div class="flex items-center justify-between">
                    <div class="flex items-start">
                        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-primary-50 flex items-center justify-center text-primary-600 font-medium text-lg">
                            ${initials}
                        </div>
                        <div class="ml-3 flex-1 min-w-0">
                            <div class="flex items-center">
                                <p class="text-sm font-medium text-gray-900 truncate">
                                    ${studentName}
                                </p>
                                ${student.email ? `
                                    <a href="mailto:${student.email}" class="ml-2 text-primary-500 hover:text-primary-700">
                                        <i class="fas fa-envelope text-xs"></i>
                                    </a>
                                ` : ''}
                                ${student.contact_number ? `
                                    <a href="tel:${student.contact_number}" class="ml-2 text-green-500 hover:text-green-700">
                                        <i class="fas fa-phone text-xs"></i>
                                    </a>
                                ` : ''}
                            </div>
                            <div class="text-xs text-gray-500 mt-1">
                                <span class="font-medium">ID:</span> ${student.student_id || student.id || 'N/A'}
                                ${student.grade_level ? `• <span class="font-medium">Grade:</span> ${student.grade_level}` : ''}
                                ${student.section ? `• <span class="font-medium">Section:</span> ${student.section}` : ''}
                            </div>
                            ${student.subject ? `
                                <div class="text-xs text-gray-500 mt-0.5">
                                    <span class="font-medium">Subject:</span> ${student.subject}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                    <div class="flex items-center space-x-2 ml-2">
                        <span class="px-2 py-0.5 inline-flex text-xs leading-4 font-semibold rounded-full bg-green-100 text-green-800">
                            Active
                        </span>
                        <button onclick="viewStudent(${JSON.stringify(student)})" 
                                class="px-3 py-1.5 text-xs font-medium text-primary-600 hover:text-primary-800 rounded-md transition-colors flex items-center">
                            <i class="fas fa-eye mr-1.5"></i> View
                        </button>
                    </div>
                </div>
            `;
            
            // Add the new student to the top of the list
            studentList.insertBefore(newStudent, studentList.firstChild);
            
            // Update the student count
            const countElement = document.querySelector('.student-count');
            if (countElement) {
                const currentCount = parseInt(countElement.textContent) || 0;
                countElement.textContent = (currentCount + 1) + ' students';
            }
        }
        
        // Show notification function
        function showNotification(type, message) {
            const notification = document.createElement('div');
            notification.className = `fixed top-4 right-4 p-4 rounded-md shadow-lg z-50 ${
                type === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
            }`;
            notification.innerHTML = `
                <div class="flex items-center">
                    <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-2"></i>
                    <span>${message}</span>
                </div>
            `;
            
            document.body.appendChild(notification);
            
            // Remove notification after 5 seconds
            setTimeout(() => {
                notification.classList.add('opacity-0', 'transition-opacity', 'duration-500');
                setTimeout(() => {
                    notification.remove();
                }, 500);
            }, 5000);
        }

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
                localStorage.setItem('sidebarCollapsed', 'true');
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
                localStorage.setItem('sidebarCollapsed', 'false');
            }
        }

        // Filter by class
        function filterByClass() {
            const classFilter = document.getElementById('class-filter');
            const selectedClass = classFilter.value;
            window.location.href = `students.php?class_id=${selectedClass}`;
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

        // Initialize sidebar state and search functionality
        document.addEventListener('DOMContentLoaded', function() {
            console.log('San Agustin Elementary School Students Page loaded');
            
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

            // Search functionality
            const searchInput = document.getElementById('search-students');
            const studentItems = document.querySelectorAll('ul.divide-y > li');
            
            searchInput.addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                studentItems.forEach(item => {
                    const text = item.textContent.toLowerCase();
                    item.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        });

function confirmDelete() {
  return confirm('Are you sure you want to delete this student?');
}

    </script>
</body>
</html>