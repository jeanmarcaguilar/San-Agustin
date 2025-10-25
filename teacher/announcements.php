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

    // Fetch classes for target audience dropdown
    $teacher_classes = [];
    if (!empty($user['teacher_id'])) {
        $stmt = $teacher_conn->prepare("SELECT * FROM classes WHERE teacher_id = ? ORDER BY grade_level, section");
        $stmt->execute([$user['teacher_id']]);
        $teacher_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Fetch announcements
    $announcements = [];
    $drafts = [];
    $total_sent = 0;
    $read_rate = 0;
    $engagement_rate = 0;

    if (!empty($user['teacher_id'])) {
        // Fetch published announcements
        $stmt = $teacher_conn->prepare("SELECT * FROM announcements WHERE teacher_id = ? AND status = 'published' ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$user['teacher_id']]);
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch draft announcements
        $stmt = $teacher_conn->prepare("SELECT * FROM announcements WHERE teacher_id = ? AND status = 'draft' ORDER BY created_at DESC");
        $stmt->execute([$user['teacher_id']]);
        $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Calculate stats
        $stmt = $teacher_conn->prepare("SELECT COUNT(*) as count FROM announcements WHERE teacher_id = ? AND status = 'published'");
        $stmt->execute([$user['teacher_id']]);
        $total_sent = $stmt->fetch(PDO::FETCH_ASSOC)['count'] ?? 0;

        // Calculate read rate based on views
        $stmt = $teacher_conn->prepare("SELECT COUNT(*) as view_count 
                                      FROM announcement_views av 
                                      JOIN announcements a ON av.announcement_id = a.id 
                                      WHERE a.teacher_id = ? AND a.status = 'published'");
        $stmt->execute([$user['teacher_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_views = $result['view_count'] ?? 0;
        
        $read_rate = ($total_sent > 0) ? min(100, round(($total_views / ($total_sent * 10)) * 100, 1)) : 0; // Assuming ~10 students per class
        
        $engagement_rate = min(100, $read_rate * 0.7); // Assuming 70% of readers engage
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
    error_log("Announcements page error: " . $e->getMessage());
    $error_messages[] = "An error occurred while loading the announcements page: " . $e->getMessage();
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
    $announcements = [];
    $drafts = [];
    $teacher_classes = [];
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
                    <a href="grades.php" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Grades</span>
                    </a>
                </li>
                <li>
                    <a href="announcements.php" class="flex items-center p-3 rounded-lg bg-secondary-700 text-white transition-colors nav-item">
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
                <h1 class="text-xl font-bold">Announcements</h1>
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

        <!-- New Announcement Modal -->
        <div id="newAnnouncementModal" class="modal">
            <div class="bg-white rounded-xl w-full max-w-2xl mx-4 max-h-[90vh] flex flex-col shadow-sm border border-gray-200">
                <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-800">Create New Announcement</h3>
                    <button type="button" onclick="closeModal('newAnnouncementModal')" class="text-gray-400 hover:text-gray-500">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <form id="newAnnouncementForm" class="p-6 flex-1 overflow-y-auto">
                    <div class="mb-4">
                        <label for="announcementTitle" class="block text-sm font-medium text-gray-700 mb-2">Title <span class="text-red-500">*</span></label>
                        <input type="text" id="announcementTitle" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="Enter announcement title" required>
                    </div>
                    <div class="mb-4">
                        <label for="announcementContent" class="block text-sm font-medium text-gray-700 mb-2">Content <span class="text-red-500">*</span></label>
                        <textarea id="announcementContent" rows="6" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500" placeholder="Write your announcement here..." required></textarea>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div>
                            <label for="announcementType" class="block text-sm font-medium text-gray-700 mb-2">Type</label>
                            <select id="announcementType" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                <option value="general">General Announcement</option>
                                <option value="important">Important Notice</option>
                                <option value="event">Event</option>
                                <option value="reminder">Reminder</option>
                            </select>
                        </div>
                        <div>
                            <label for="announcementTarget" class="block text-sm font-medium text-gray-700 mb-2">Target Audience</label>
                            <select id="announcementTarget" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                <option value="all">All Users</option>
                                <option value="students">Students Only</option>
                                <option value="teachers">Teachers Only</option>
                                <option value="parents">Parents Only</option>
                                <?php if (!empty($teacher_classes)): ?>
                                    <optgroup label="Specific Classes">
                                        <?php foreach ($teacher_classes as $class): ?>
                                            <option value="class_<?php echo htmlspecialchars($class['id']); ?>"><?php echo htmlspecialchars($class['subject'] . ' - Grade ' . $class['grade_level'] . $class['section']); ?></option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="announcementStartDate" class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                            <input type="date" id="announcementStartDate" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                        </div>
                        <div>
                            <label for="announcementEndDate" class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                            <input type="date" id="announcementEndDate" class="w-full px-3 py-2 border border-gray-200 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                        </div>
                    </div>
                    <div class="flex items-center mb-4">
                        <input type="checkbox" id="announcementPinned" class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-200 rounded">
                        <label for="announcementPinned" class="ml-2 block text-sm text-gray-700">Pin this announcement to the top</label>
                    </div>
                    <div class="px-6 py-3 bg-gray-50 border-t border-gray-200 flex justify-end space-x-3">
                        <button type="button" onclick="closeModal('newAnnouncementModal')" class="px-4 py-2 border border-gray-200 rounded-lg shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <span id="submitButtonText">Publish Announcement</span>
                            <span id="submitButtonLoading" class="hidden">
                                <i class="fas fa-spinner fa-spin mr-2"></i>Publishing...
                            </span>
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
                            <h1 class="text-2xl font-bold text-gray-800 mb-2">Announcements</h1>
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
                            <button onclick="openNewAnnouncementModal()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg shadow-sm text-white bg-primary-600 hover:bg-primary-700 focus:outline-none">
                                <i class="fas fa-plus mr-2"></i>New Announcement
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Main Content and Sidebar -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">
                    <!-- Recent Announcements -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 dashboard-card">
                            <div class="px-4 py-3 border-b border-gray-200 flex justify-between items-center">
                                <div class="flex items-center">
                                    <i class="fas fa-bullhorn text-primary-500 mr-2"></i>
                                    <h3 class="text-base font-semibold text-gray-800">Recent Announcements</h3>
                                    <span class="ml-2 text-sm text-gray-500"><?php echo count($announcements); ?> announcements</span>
                                </div>
                                <div class="relative">
                                    <input type="text" id="search-announcements" placeholder="Search announcements..." class="pl-10 pr-4 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-primary-500 focus:border-primary-500">
                                    <i class="fas fa-search absolute left-3 top-2.5 text-gray-400"></i>
                                </div>
                            </div>
                            <div class="divide-y divide-gray-200 max-h-[600px] overflow-y-auto">
                                <?php if (!empty($announcements)): ?>
                                    <?php foreach ($announcements as $announcement): ?>
                                        <div class="p-4 hover:bg-gray-50 transition-colors duration-150">
                                            <div class="flex justify-between items-start">
                                                <div>
                                                    <h3 class="font-medium text-gray-800"><?php echo htmlspecialchars($announcement['title'] ?? 'Untitled'); ?></h3>
                                                    <p class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars(substr($announcement['content'] ?? '', 0, 100)); ?>...</p>
                                                    <div class="mt-1 text-xs text-gray-500 flex items-center flex-wrap">
                                                        <i class="far fa-clock mr-1"></i>
                                                        <span><?php echo date('M d, Y H:i', strtotime($announcement['created_at'] ?? 'now')); ?></span>
                                                        <?php if (!empty($announcement['class_id'])): ?>
                                                            <?php
                                                                $stmt = $teacher_conn->prepare("SELECT subject, grade_level, section FROM classes WHERE id = ?");
                                                                $stmt->execute([$announcement['class_id']]);
                                                                $class = $stmt->fetch(PDO::FETCH_ASSOC);
                                                            ?>
                                                            <span class="mx-1">•</span>
                                                            <span><?php echo htmlspecialchars($class['subject'] . ' - Grade ' . $class['grade_level'] . $class['section']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flex items-center space-x-2">
                                                    <a href="view_announcement.php?id=<?php echo htmlspecialchars($announcement['id']); ?>" class="text-xs text-primary-600 hover:text-primary-700">
                                                        <i class="fas fa-eye mr-1"></i>View
                                                    </a>
                                                    <a href="edit_announcement.php?id=<?php echo htmlspecialchars($announcement['id']); ?>" class="text-xs text-blue-600 hover:text-blue-800">
                                                        <i class="fas fa-edit mr-1"></i>Edit
                                                    </a>
                                                    <button onclick="deleteAnnouncement(<?php echo htmlspecialchars($announcement['id']); ?>, '<?php echo htmlspecialchars(addslashes($announcement['title'])); ?>')" class="text-xs text-red-600 hover:text-red-800">
                                                        <i class="fas fa-trash-alt mr-1"></i>Delete
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="p-8 text-center">
                                        <i class="fas fa-bullhorn text-4xl text-gray-200 mb-3"></i>
                                        <h3 class="text-lg font-medium text-gray-800">No announcements yet</h3>
                                        <p class="mt-1 text-sm text-gray-500">Create your first announcement to get started</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="p-4 border-t border-gray-200 text-center">
                                <a href="all_announcements.php" class="text-sm font-medium text-primary-600 hover:text-primary-700">Load more announcements</a>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar: Drafts and Stats -->
                    <div class="space-y-5">
                        <!-- Drafts -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 dashboard-card">
                            <div class="px-4 py-3 border-b border-gray-200">
                                <h3 class="text-base font-semibold text-gray-800">Drafts</h3>
                            </div>
                            <div class="p-4 max-h-[300px] overflow-y-auto">
                                <?php if (!empty($drafts)): ?>
                                    <?php foreach ($drafts as $draft): ?>
                                        <div class="py-2">
                                            <p class="text-sm font-medium text-gray-800"><?php echo htmlspecialchars($draft['title'] ?? 'Untitled Draft'); ?></p>
                                            <div class="mt-1 text-xs text-gray-500 flex items-center">
                                                <i class="far fa-clock mr-1"></i>
                                                <span><?php echo date('M d, Y H:i', strtotime($draft['created_at'] ?? 'now')); ?></span>
                                                <span class="mx-1">•</span>
                                                <a href="edit_announcement.php?id=<?php echo htmlspecialchars($draft['id']); ?>" class="text-primary-600 hover:text-primary-700">Edit</a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-sm text-gray-500 text-center py-4">No drafts saved</p>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Announcement Stats -->
                        <div class="bg-white rounded-xl shadow-sm border border-gray-200 dashboard-card">
                            <div class="px-4 py-3 border-b border-gray-200">
                                <h3 class="text-base font-semibold text-gray-800">Announcement Stats</h3>
                            </div>
                            <div class="p-4 space-y-4">
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-gray-600">Total Sent</span>
                                        <span class="font-medium text-gray-800"><?php echo $total_sent; ?></span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <div class="bg-blue-500 h-2.5 rounded-full" style="width: <?php echo min($total_sent * 10, 100); ?>%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-gray-600">Read Rate</span>
                                        <span class="font-medium text-gray-800"><?php echo $read_rate; ?>%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <div class="bg-green-500 h-2.5 rounded-full" style="width: <?php echo $read_rate; ?>%"></div>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-gray-600">Engagement</span>
                                        <span class="font-medium text-gray-800"><?php echo $engagement_rate; ?>%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2.5">
                                        <div class="bg-yellow-500 h-2.5 rounded-full" style="width: <?php echo $engagement_rate; ?>%"></div>
                                    </div>
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

        // Modal functions
        function openNewAnnouncementModal() {
            document.getElementById('newAnnouncementModal').classList.add('open');
            document.body.classList.add('overflow-hidden');
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('open');
            document.body.classList.remove('overflow-hidden');
        }

        // Format date as "Month Day, Year"
        function formatDate(date) {
            const options = { year: 'numeric', month: 'short', day: 'numeric' };
            return new Date(date).toLocaleDateString('en-US', options);
        }

        // Format time as "HH:MM AM/PM"
        function formatTime(date) {
            return new Date(date).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
        }

        // Add a new announcement to the recent announcements list
        function addAnnouncementToDOM(announcement) {
            const announcementsList = document.querySelector('.divide-y.divide-gray-200');
            if (!announcementsList) return;

            // Create the announcement element
            const announcementElement = document.createElement('div');
            announcementElement.className = 'p-4 hover:bg-gray-50 transition-colors duration-150';
            announcementElement.innerHTML = `
                <div class="flex justify-between items-start">
                    <div>
                        <h3 class="font-medium text-gray-800">${announcement.title}</h3>
                        <p class="text-sm text-gray-500 mt-1">${announcement.content.substring(0, 100)}...</p>
                        <div class="mt-1 text-xs text-gray-500 flex items-center flex-wrap">
                            <i class="far fa-clock mr-1"></i>
                            <span>${formatDate(announcement.created_at)} ${formatTime(announcement.created_at)}</span>
                            ${announcement.class_id ? `
                                <span class="mx-1">•</span>
                                <span>${announcement.class_name || 'Class'}</span>
                            ` : ''}
                        </div>
                    </div>
                    <div class="flex items-center space-x-2">
                        <a href="edit_announcement.php?id=${announcement.id}" class="text-xs text-gray-600 hover:text-gray-800">
                            <i class="fas fa-edit mr-1"></i>Edit
                        </a>
                        <a href="view_announcement.php?id=${announcement.id}" class="text-xs text-primary-600 hover:text-primary-700">
                            <i class="fas fa-eye mr-1"></i>View
                        </a>
                    </div>
                </div>
            `;

            // Add to the top of the list
            announcementsList.insertBefore(announcementElement, announcementsList.firstChild);

            // Update the announcement count
            const countElement = document.querySelector('.text-sm.text-gray-500');
            if (countElement) {
                const currentCount = parseInt(countElement.textContent) || 0;
                countElement.textContent = `${currentCount + 1} announcements`;
            }

            // Update the "No announcements" message if it exists
            const noAnnouncements = document.querySelector('.p-8.text-center');
            if (noAnnouncements) {
                noAnnouncements.style.display = 'none';
            }
        }

        // Handle New Announcement form submission
        document.getElementById('newAnnouncementForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Show loading state
            const submitBtn = document.querySelector('#newAnnouncementForm button[type="submit"]');
            const submitBtnText = document.getElementById('submitButtonText');
            const submitBtnLoading = document.getElementById('submitButtonLoading');
            
            submitBtn.disabled = true;
            submitBtnText.classList.add('hidden');
            submitBtnLoading.classList.remove('hidden');
            
            // Get form data
            const formData = {
                title: document.getElementById('announcementTitle').value,
                content: document.getElementById('announcementContent').value,
                type: document.getElementById('announcementType').value,
                target: document.getElementById('announcementTarget').value,
                pinned: document.getElementById('announcementPinned').checked,
                teacher_id: '<?php echo $user['teacher_id'] ?? ''; ?>',
                teacher_name: '<?php echo htmlspecialchars($user['full_name'] ?? $user['first_name'] . ' ' . $user['last_name']); ?>',
                start_date: document.getElementById('announcementStartDate').value || new Date().toISOString().slice(0, 10),
                end_date: document.getElementById('announcementEndDate').value || null
            };
            
            // Make API call to save the announcement
            fetch('../api/save_announcement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(formData)
            })
            .then(response => {
                if (!response.ok) {
                    return response.json().then(err => {
                        throw new Error(err.message || 'Failed to publish announcement');
                    });
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    // Show success message
                    showAlert('Announcement published successfully!', 'success');
                    
                    // Add the new announcement to the DOM
                    addAnnouncementToDOM(data.announcement);
                    
                    // Close the modal and reset the form
                    closeModal('newAnnouncementModal');
                    this.reset();
                } else {
                    throw new Error(data.message || 'Failed to publish announcement');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert(error.message || 'An error occurred. Please try again.', 'error');
            })
            .finally(() => {
                // Reset the button state
                submitBtn.disabled = false;
                submitBtnText.classList.remove('hidden');
                submitBtnLoading.classList.add('hidden');
            });
        });

        // Search announcements
        function searchAnnouncements() {
            const searchInput = document.getElementById('search-announcements');
            const announcementItems = document.querySelectorAll('.divide-y > div');
            const searchTerm = searchInput.value.toLowerCase();

            announcementItems.forEach(item => {
                const text = item.textContent.toLowerCase();
                item.style.display = text.includes(searchTerm) ? '' : 'none';
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
                const modals = ['newAnnouncementModal'];
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
            console.log('San Agustin Elementary School Announcements Page loaded');
            
            const notificationItems = document.querySelectorAll('.notification-item');
            notificationItems.forEach(item => {
                item.addEventListener('click', function() {
                    const notificationId = this.getAttribute('data-id');
                    markNotificationAsRead(notificationId);
                });
            });

            // Search functionality
            const searchInput = document.getElementById('search-announcements');
            if (searchInput) {
                searchInput.addEventListener('input', searchAnnouncements);
            }
        });

        // Delete announcement function
        function deleteAnnouncement(id, title) {
            if (!confirm(`Are you sure you want to delete the announcement "${title}"?\n\nThis action cannot be undone.`)) {
                return;
            }

            fetch('delete_announcement.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'id=' + id
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Announcement deleted successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while deleting the announcement.');
            });
        }
    </script>
</body>
</html>