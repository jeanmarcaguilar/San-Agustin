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
    
    // Get registrar connection for student data
    $registrar_conn = $database->getConnection('registrar');
    if (!$registrar_conn) {
        throw new Exception('Failed to connect to registrar database');
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

    // Initialize variables
    $attendance_records = [];
    $students = [];
    $total_students = 0;
    $present_today = 0;
    $absent_today = 0;
    $late_today = 0;

    if (!empty($user['teacher_id'])) {
        // Get selected date and class from GET parameters
        $selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
        $selected_class = isset($_GET['class_id']) ? $_GET['class_id'] : 'all';

        // Get all students from teacher's classes (from registrar_db)
        $query = "SELECT s.*, c.id as class_id, c.subject, c.grade_level as class_grade, c.section as class_section, 
                         c.schedule, c.room, cs.status as enrollment_status,
                         a.status as attendance_status, a.notes as attendance_notes
                  FROM registrar_db.students s
                  JOIN teacher_db.class_students cs ON s.student_id = cs.student_id
                  JOIN teacher_db.classes c ON cs.class_id = c.id
                  LEFT JOIN teacher_db.attendance a ON a.student_id = s.student_id 
                                      AND a.class_id = c.id 
                                      AND a.attendance_date = ?
                  WHERE c.teacher_id = ? AND cs.status = 'active' AND s.status = 'Active'";
        
        $params = [$selected_date, $user['teacher_id']];

        if ($selected_class !== 'all') {
            $query .= " AND c.id = ?";
            $params[] = $selected_class;
        }

        $query .= " ORDER BY c.grade_level, c.section, s.last_name, s.first_name";

        $stmt = $registrar_conn->prepare($query);
        $stmt->execute($params);
        $rawStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Process the raw data to group by student and include class info
        $students = [];
        foreach ($rawStudents as $row) {
            $studentId = $row['student_id'];
            
            if (!isset($students[$studentId])) {
                $students[$studentId] = [
                    'id' => $row['id'] ?? null,
                    'student_id' => $row['student_id'] ?? '',
                    'first_name' => $row['first_name'] ?? '',
                    'last_name' => $row['last_name'] ?? '',
                    'middle_name' => $row['middle_name'] ?? '',
                    'suffix' => $row['suffix'] ?? '',
                    'gender' => $row['gender'] ?? '',
                    'birthdate' => $row['birth_date'] ?? ($row['birthdate'] ?? null),
                    'contact_number' => $row['contact_number'] ?? '',
                    'email' => $row['email'] ?? '',
                    'lrn' => $row['lrn'] ?? '',
                    'grade_level' => $row['grade_level'] ?? null,
                    'section' => $row['section'] ?? '',
                    'status' => $row['enrollment_status'] ?? 'active',
                    'classes' => []
                ];
            }
            
            // Add class information if not already added
            $classKey = $row['class_id'];
            if (!empty($classKey)) {
                $students[$studentId]['classes'][$classKey] = [
                    'class_id' => $row['class_id'],
                    'subject' => $row['subject'] ?? '',
                    'grade_level' => $row['class_grade'] ?? null,
                    'section' => $row['class_section'] ?? '',
                    'schedule' => $row['schedule'] ?? '',
                    'room' => $row['room'] ?? '',
                    'attendance_status' => $row['attendance_status'] ?? null,
                    'attendance_notes' => $row['attendance_notes'] ?? ''
                ];
                
                // Count attendance status for stats
                if ($selected_date == date('Y-m-d')) {
                    if ($row['attendance_status'] === 'present') $present_today++;
                    elseif ($row['attendance_status'] === 'absent') $absent_today++;
                    elseif ($row['attendance_status'] === 'late') $late_today++;
                }
            }
        }
        
        $total_students = count($students);
        
        // Convert to sequential array for the view
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
    error_log("Attendance page error: " . $e->getMessage());
    $error_messages[] = "An error occurred while loading the attendance page: " . $e->getMessage();
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
    $attendance_records = [];
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
                    <a href="attendance.php" class="flex items-center p-3 rounded-lg bg-secondary-700 text-white transition-colors nav-item">
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
                <h1 class="text-xl font-bold">Student Attendance</h1>
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

        <!-- Take Attendance Modal -->
        <div id="takeAttendanceModal" class="modal">
            <div class="bg-white rounded-xl w-full max-w-4xl mx-4 max-h-[90vh] flex flex-col shadow-sm border border-gray-200">
                <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-800">Take Attendance - <span id="attendanceModalDate"></span></h3>
                    <button type="button" class="text-gray-400 hover:text-gray-500" onclick="closeModal('takeAttendanceModal')">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto p-6">
                    <div class="mb-4">
                        <label for="attendanceClass" class="block text-sm font-medium text-gray-700 mb-2">Select Class</label>
                        <select id="attendanceClass" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['subject'] . ' - Grade ' . $class['grade_level'] . $class['section']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="attendanceDate" class="block text-sm font-medium text-gray-700 mb-2">Date</label>
                        <input type="date" id="attendanceDate" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div id="studentListContainer" class="mt-4">
                        <!-- Student list will be loaded here -->
                    </div>
                </div>
                <div class="px-6 py-3 bg-gray-50 border-t border-gray-200 flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('takeAttendanceModal')" class="px-4 py-2 border border-gray-200 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Cancel
                    </button>
                    <button type="button" onclick="saveAttendance()" class="px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Save Attendance
                    </button>
                </div>
            </div>
        </div>

        <!-- Export Modal -->
        <div id="exportModal" class="modal">
            <div class="bg-white rounded-xl w-full max-w-md mx-4 shadow-sm border border-gray-200">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-bold text-gray-800">Export Attendance Records</h3>
                </div>
                <div class="p-6">
                    <div class="mb-4">
                        <label for="exportClass" class="block text-sm font-medium text-gray-700 mb-2">Select Class</label>
                        <select id="exportClass" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <option value="all">All Classes</option>
                            <?php 
                            $firstDayOfMonth = date('Y-m-01');
                            $lastDayOfMonth = date('Y-m-t');
                            foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['subject'] . ' - Grade ' . $class['grade_level'] . $class['section']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="exportStartDate" class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                            <input type="date" id="exportStartDate" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" value="<?php echo $firstDayOfMonth; ?>">
                        </div>
                        <div>
                            <label for="exportEndDate" class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                            <input type="date" id="exportEndDate" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" value="<?php echo $lastDayOfMonth; ?>">
                        </div>
                    </div>
                    <div class="mb-4">
                        <label for="exportFormat" class="block text-sm font-medium text-gray-700 mb-2">Export Format</label>
                        <select id="exportFormat" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <option value="csv">CSV (Comma Separated Values)</option>
                            <option value="excel">Excel (XLS Format)</option>
                            <option value="pdf">PDF (Printable Format)</option>
                        </select>
                    </div>
                    <div class="bg-primary-50 border-l-4 border-primary-400 p-4 rounded">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-info-circle text-primary-400"></i>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-primary-700">
                                    The export will include all attendance records for the selected date range and class.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-3 bg-gray-50 border-t border-gray-200 flex justify-end space-x-3">
                    <button type="button" onclick="closeModal('exportModal')" class="px-4 py-2 border border-gray-200 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        Cancel
                    </button>
                    <button type="button" onclick="exportAttendance()" class="px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 flex items-center">
                        <i class="fas fa-download mr-2"></i>Export
                    </button>
                </div>
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
                            <h1 class="text-2xl font-bold text-gray-800 mb-2">Student Attendance</h1>
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
                            <button onclick="openTakeAttendanceModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg shadow-sm text-white bg-primary-600 hover:bg-primary-700 focus:outline-none">
                                <i class="fas fa-plus mr-2"></i>Take Attendance
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4 mb-6">
                    <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-600">Total Students</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $total_students; ?></h3>
                                <p class="text-xs text-gray-500">Across all classes</p>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center">
                                <i class="fas fa-users text-blue-500"></i>
                            </div>
                        </div>
                    </div>
                    <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-600">Present Today</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $present_today; ?></h3>
                                <p class="text-xs text-gray-500"><?php echo $total_students > 0 ? round(($present_today/$total_students)*100, 1) : 0; ?>% of total</p>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-green-100 flex items-center justify-center">
                                <i class="fas fa-check-circle text-green-500"></i>
                            </div>
                        </div>
                    </div>
                    <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-600">Absent Today</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $absent_today; ?></h3>
                                <p class="text-xs text-gray-500"><?php echo $total_students > 0 ? round(($absent_today/$total_students)*100, 1) : 0; ?>% of total</p>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-red-100 flex items-center justify-center">
                                <i class="fas fa-times-circle text-red-500"></i>
                            </div>
                        </div>
                    </div>
                    <div class="dashboard-card rounded-xl p-5 border border-gray-200 shadow-sm">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-gray-600">Late Today</p>
                                <h3 class="text-2xl font-bold text-gray-800"><?php echo $late_today; ?></h3>
                                <p class="text-xs text-gray-500"><?php echo $total_students > 0 ? round(($late_today/$total_students)*100, 1) : 0; ?>% of total</p>
                            </div>
                            <div class="w-12 h-12 rounded-lg bg-amber-100 flex items-center justify-center">
                                <i class="fas fa-clock text-amber-500"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Attendance Actions -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 mb-6 dashboard-card">
                    <div class="px-4 py-3 bg-gradient-to-r from-primary-50 to-primary-50 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-clipboard-check text-primary-500 mr-2 text-sm"></i>
                            <span>Attendance Management</span>
                        </h3>
                    </div>
                    <div class="p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center">
                        <div>
                            <h2 class="text-lg font-bold text-gray-800">Record and manage student attendance</h2>
                        </div>
                        <div class="mt-3 sm:mt-0 flex space-x-3">
                            <button onclick="openTakeAttendanceModal()" class="px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 flex items-center">
                                <i class="fas fa-user-check mr-2"></i> Take Attendance
                            </button>
                            <button onclick="openExportModal()" class="px-4 py-2 border border-gray-200 text-gray-700 bg-white rounded-lg hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 flex items-center">
                                <i class="fas fa-file-export mr-2"></i> Export
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Student List for Attendance -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 dashboard-card">
                    <div class="px-4 py-3 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-start sm:items-center">
                        <div class="flex items-center mb-2 sm:mb-0">
                            <i class="fas fa-users text-primary-500 mr-2"></i>
                            <h3 class="text-base font-semibold text-gray-800">Class Roster</h3>
                            <span class="ml-2 text-sm text-gray-500"><?php echo count($students); ?> students</span>
                        </div>
                        <div class="flex space-x-2">
                            <input type="date" id="date-filter" value="<?php echo htmlspecialchars($selected_date); ?>" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-primary-500 focus:border-primary-500">
                            <select id="class-filter" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-primary-500 focus:border-primary-500" onchange="filterAttendance()">
                                <option value="all">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo htmlspecialchars($class['id']); ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['subject'] . ' - Grade ' . $class['grade_level'] . ' ' . $class['section']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button onclick="saveAttendance()" class="inline-flex items-center px-4 py-1.5 border border-transparent text-sm font-medium rounded-lg shadow-sm text-white bg-primary-600 hover:bg-primary-700 focus:outline-none">
                                <i class="fas fa-save mr-2"></i>Save
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade & Section</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (!empty($students)): ?>
                                    <?php foreach ($students as $student): 
                                        $firstClass = !empty($student['classes']) ? reset($student['classes']) : null;
                                        $studentId = htmlspecialchars($student['student_id']);
                                        $fullName = htmlspecialchars(trim(($student['first_name'] ?? '') . ' ' . ($student['last_name'] ?? '')));
                                        $initials = '';
                                        if (!empty($student['first_name'])) $initials .= $student['first_name'][0];
                                        if (!empty($student['last_name'])) $initials .= $student['last_name'][0];
                                        $initials = strtoupper($initials ?: 'S');
                                        $attendanceStatus = $firstClass['attendance_status'] ?? '';
                                        $statusClass = [
                                            'present' => 'bg-green-100 text-green-800',
                                            'absent' => 'bg-red-100 text-red-800',
                                            'late' => 'bg-amber-100 text-amber-800',
                                            'excused' => 'bg-blue-100 text-blue-800'
                                        ][$attendanceStatus] ?? 'bg-gray-100 text-gray-800';
                                    ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10 rounded-full bg-primary-100 flex items-center justify-center text-primary-600 font-medium">
                                                        <?php echo $initials; ?>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-800"><?php echo $fullName; ?></div>
                                                        <div class="text-sm text-gray-500"><?php echo $student['email'] ?? ''; ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo $studentId; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-800">
                                                    Grade <?php echo htmlspecialchars($student['grade_level'] ?? 'N/A'); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($student['section'] ?: 'No Section'); ?>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($firstClass): ?>
                                                    <div class="text-sm text-gray-800"><?php echo htmlspecialchars($firstClass['subject'] ?? ''); ?></div>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($firstClass['schedule'] ?? ''); ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-sm text-gray-500">Not enrolled</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <select name="attendance_status[<?php echo $studentId; ?>]" class="attendance-status mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-200 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm rounded-lg <?php echo $statusClass; ?>">
                                                    <option value="" class="bg-white">Select status</option>
                                                    <option value="present" <?php echo $attendanceStatus === 'present' ? 'selected' : ''; ?> class="bg-green-100 text-green-800">Present</option>
                                                    <option value="absent" <?php echo $attendanceStatus === 'absent' ? 'selected' : ''; ?> class="bg-red-100 text-red-800">Absent</option>
                                                    <option value="late" <?php echo $attendanceStatus === 'late' ? 'selected' : ''; ?> class="bg-amber-100 text-amber-800">Late</option>
                                                    <option value="excused" <?php echo $attendanceStatus === 'excused' ? 'selected' : ''; ?> class="bg-blue-100 text-blue-800">Excused</option>
                                                </select>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <input type="text" name="notes[<?php echo $studentId; ?>]" value="<?php echo htmlspecialchars($firstClass['attendance_notes'] ?? ''); ?>" class="notes-input shadow-sm focus:ring-primary-500 focus:border-primary-500 block w-full sm:text-sm border-gray-200 rounded-lg" placeholder="Notes (optional)">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No students found in your classes.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('open');
            document.body.classList.add('overflow-hidden');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('open');
            document.body.classList.remove('overflow-hidden');
        }
        
        function openTakeAttendanceModal() {
            const today = new Date().toISOString().split('T')[0];
            const dateInput = document.getElementById('attendanceDate');
            dateInput.value = today;
            dateInput.max = today; // Prevent future dates
            document.getElementById('attendanceModalDate').textContent = formatDate(today);
            
            // Clear previous data
            document.getElementById('studentListContainer').innerHTML = '';
            document.getElementById('attendanceClass').value = '';
            
            openModal('takeAttendanceModal');
            
            // Add event listeners for class and date changes
            document.getElementById('attendanceClass').addEventListener('change', loadClassStudents);
            dateInput.addEventListener('change', function() {
                document.getElementById('attendanceModalDate').textContent = formatDate(this.value);
                if (document.getElementById('attendanceClass').value) {
                    loadClassStudents();
                }
            });
        }
        
        function openExportModal() {
            const today = new Date().toISOString().split('T')[0];
            const firstDayOfMonth = new Date();
            firstDayOfMonth.setDate(1);
            
            const exportStartDate = document.getElementById('exportStartDate');
            const exportEndDate = document.getElementById('exportEndDate');
            
            exportStartDate.value = firstDayOfMonth.toISOString().split('T')[0];
            exportEndDate.value = today;
            exportEndDate.max = today; // Prevent future dates
            
            openModal('exportModal');
        }
        
        function formatDate(dateString) {
            const options = { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' };
            return new Date(dateString).toLocaleDateString('en-US', options);
        }
        
        // Load students for selected class
        function loadClassStudents() {
            const classId = document.getElementById('attendanceClass').value;
            const container = document.getElementById('studentListContainer');
            
            if (!classId) {
                container.innerHTML = '<p class="text-gray-500 text-center py-4">Please select a class to view students.</p>';
                return;
            }
            
            // Show loading state
            container.innerHTML = `
                <div class="flex justify-center items-center py-8">
                    <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-500"></div>
                    <span class="ml-3 text-gray-600">Loading students...</span>
                </div>
            `;
            
            fetch(`get_class_students.php?class_id=${classId}&date=${document.getElementById('attendanceDate').value}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        renderStudentList(data.students);
                    } else {
                        throw new Error(data.message || 'Failed to load students');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    container.innerHTML = `
                        <div class="bg-red-100 border-l-4 border-red-400 p-4 rounded">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-circle text-red-400"></i>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm text-red-700">
                                        Error loading students: ${error.message || 'Please try again later.'}
                                    </p>
                                </div>
                            </div>
                        </div>
                    `;
                });
        }
        
        function renderStudentList(students) {
            const container = document.getElementById('studentListContainer');
            if (!students || students.length === 0) {
                container.innerHTML = '<p class="text-gray-500 text-center py-4">No students found in this class.</p>';
                return;
            }
            
            let html = `
                <div class="overflow-hidden border border-gray-200 rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
            `;
            
            students.forEach(student => {
                const initials = (student.first_name ? student.first_name[0] : '') + (student.last_name ? student.last_name[0] : '');
                html += `
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center">
                                <div class="flex-shrink-0 h-10 w-10 rounded-full bg-primary-100 flex items-center justify-center text-primary-600 font-medium">
                                    ${initials || 'S'}
                                </div>
                                <div class="ml-4">
                                    <div class="text-sm font-medium text-gray-800">${student.first_name} ${student.last_name}</div>
                                    <div class="text-sm text-gray-500">${student.student_id}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <select class="attendance-status block w-full pl-3 pr-10 py-2 text-base border-gray-200 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm rounded-lg ${student.status === 'present' ? 'bg-green-100 text-green-800' : student.status === 'absent' ? 'bg-red-100 text-red-800' : student.status === 'late' ? 'bg-amber-100 text-amber-800' : student.status === 'excused' ? 'bg-blue-100 text-blue-800' : 'bg-white'}" 
                                    data-student-id="${student.student_id}">
                                <option value="present" ${student.status === 'present' ? 'selected' : ''}>Present</option>
                                <option value="absent" ${student.status === 'absent' ? 'selected' : ''}>Absent</option>
                                <option value="late" ${student.status === 'late' ? 'selected' : ''}>Late</option>
                                <option value="excused" ${student.status === 'excused' ? 'selected' : ''}>Excused</option>
                            </select>
                        </td>
                    </tr>
                `;
            });
            
            html += `
                        </tbody>
                    </table>
                </div>
            `;
            
            container.innerHTML = html;
        }
        
        // Save attendance
        function saveAttendance() {
            const classId = document.getElementById('attendanceClass').value;
            const date = document.getElementById('attendanceDate').value;
            const saveBtn = document.querySelector('#takeAttendanceModal button[onclick="saveAttendance()"]');
            const originalBtnText = saveBtn.innerHTML;
            
            if (!classId) {
                showAlert('Please select a class', 'error');
                return;
            }
            
            const attendanceData = [];
            document.querySelectorAll('.attendance-status').forEach(select => {
                attendanceData.push({
                    student_id: select.dataset.studentId,
                    status: select.value
                });
            });
            
            if (attendanceData.length === 0) {
                showAlert('No students to save attendance for', 'error');
                return;
            }
            
            // Show loading state
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
            
            fetch('save_attendance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    class_id: classId,
                    date: date,
                    attendance: attendanceData,
                    recorded_by: '<?php echo $_SESSION['user_id'] ?? 0; ?>'
                })
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => {
                        throw new Error(err.message || 'Failed to save attendance');
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    showAlert('Attendance saved successfully!', 'success');
                    setTimeout(() => {
                        closeModal('takeAttendanceModal');
                        window.location.reload();
                    }, 1500);
                } else {
                    throw new Error(data.message || 'Failed to save attendance');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('Error: ' + (error.message || 'Failed to save attendance. Please try again.'), 'error');
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalBtnText;
            });
        }
        
        // Export attendance
        function exportAttendance() {
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
            
            // Show loading state
            const exportBtn = document.querySelector('#exportModal button[onclick="exportAttendance()"]');
            const originalBtnText = exportBtn.innerHTML;
            exportBtn.disabled = true;
            exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Preparing export...';
            
            // Build export URL
            let url = `export_attendance.php?start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}&format=${encodeURIComponent(format)}`;
            
            if (classId !== 'all') {
                url += `&class_id=${encodeURIComponent(classId)}`;
            }
            
            // For PDF, we'll open in a new tab
            if (format === 'pdf') {
                const newWindow = window.open(url, '_blank');
                if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
                    showAlert('Popup was blocked. Please allow popups for this site to download the PDF.', 'error');
                    exportBtn.disabled = false;
                    exportBtn.innerHTML = originalBtnText;
                    return;
                }
                
                // Reset button state after a short delay
                setTimeout(() => {
                    exportBtn.disabled = false;
                    exportBtn.innerHTML = originalBtnText;
                    closeModal('exportModal');
                }, 2000);
                
                return;
            }
            
            // For CSV/Excel, trigger download
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
                
                const contentDisposition = response.headers.get('content-disposition');
                let filename = `attendance_export_${new Date().toISOString().slice(0, 10)}.${format}`;
                
                if (contentDisposition) {
                    const filenameMatch = contentDisposition.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/);
                    if (filenameMatch != null && filenameMatch[1]) { 
                        filename = filenameMatch[1].replace(/['"]/g, '');
                    }
                }
                
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
        
        // Filter attendance based on date and class
        function filterAttendance() {
            const date = document.getElementById('date-filter').value;
            const classId = document.getElementById('class-filter').value;
            
            if (!date) {
                showAlert('Please select a date', 'error');
                return;
            }
            
            let url = `attendance.php?date=${encodeURIComponent(date)}`;
            if (classId !== 'all') {
                url += `&class_id=${encodeURIComponent(classId)}`;
            }
            
            window.location.href = url;
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
                document.querySelector('.sidebar-text').textContent = 'Expand';
            } else {
                icon.classList.remove('fa-chevron-right');
                icon.classList.add('fa-chevron-left');
                document.querySelector('.sidebar-text').textContent = 'Collapse';
            }
        }

        // Show alert
        function showAlert(message, type) {
            const alertContainer = document.createElement('div');
            alertContainer.className = `fixed top-4 right-4 z-50 p-4 rounded-lg shadow-lg ${
                type === 'success' ? 'bg-green-100 text-green-700 border-l-4 border-green-500' : 
                'bg-red-100 text-red-700 border-l-4 border-red-500'
            }`;
            alertContainer.innerHTML = `
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas ${type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'} mr-2"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm">${message}</p>
                    </div>
                </div>
            `;
            document.body.appendChild(alertContainer);
            setTimeout(() => {
                alertContainer.remove();
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

            // Add event listener for date filter
            const dateFilter = document.getElementById('date-filter');
            dateFilter.addEventListener('change', filterAttendance);
        });
    </script>
</body>
</html>