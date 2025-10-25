<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connections
$student_conn = null;
$teacher_conn = null;
$error_messages = [];
$success_message = '';

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
            'contact_number' => '',
            'bio' => ''
        ];
        
        $stmt = $teacher_conn->prepare("INSERT INTO teachers (user_id, teacher_id, first_name, last_name, subject, bio) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $user['user_id'],
            $user['teacher_id'],
            $user['first_name'],
            $user['last_name'],
            $user['subject'],
            $user['bio']
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
        'teacher_id' => $user['teacher_id'] ?? '',
        'bio' => $user['bio'] ?? ''
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

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $first_name = filter_input(INPUT_POST, 'first-name', FILTER_SANITIZE_STRING) ?: $user['first_name'];
        $last_name = filter_input(INPUT_POST, 'last-name', FILTER_SANITIZE_STRING) ?: $user['last_name'];
        $email = filter_input(INPUT_POST, 'email-address', FILTER_SANITIZE_EMAIL) ?: $user['email'];
        $bio = filter_input(INPUT_POST, 'about', FILTER_SANITIZE_STRING) ?: $user['bio'];

        // Update teacher info
        $stmt = $teacher_conn->prepare("UPDATE teachers SET first_name = ?, last_name = ?, bio = ? WHERE user_id = ?");
        $stmt->execute([$first_name, $last_name, $bio, $_SESSION['user_id']]);

        // Update email in login database
        $stmt = $login_conn->prepare("UPDATE users SET email = ? WHERE id = ?");
        $stmt->execute([$email, $_SESSION['user_id']]);

        $success_message = 'Profile updated successfully!';
        
        // Update user array for display
        $user['first_name'] = $first_name;
        $user['last_name'] = $last_name;
        $user['email'] = $email;
        $user['bio'] = $bio;
        $user['full_name'] = trim($first_name . ' ' . $last_name);
        $initials = (strlen($first_name) > 0 ? $first_name[0] : '') . (strlen($last_name) > 0 ? $last_name[0] : '');
        $user['initials'] = !empty($initials) ? strtoupper($initials) : 'T';
    }

} catch (Exception $e) {
    error_log("Settings page error: " . $e->getMessage());
    $error_messages[] = "An error occurred while loading the settings page: " . $e->getMessage();
    $user = [
        'id' => $_SESSION['user_id'] ?? 0,
        'username' => $_SESSION['username'] ?? 'Guest',
        'role' => $_SESSION['role'] ?? 'guest',
        'first_name' => 'Guest',
        'last_name' => '',
        'email' => '',
        'initials' => 'G',
        'teacher_id' => '',
        'bio' => ''
    ];
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
        
        .tab-active {
            border-bottom: 2px solid #f06a1d !important;
            color: #f06a1d !important;
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
                    <a href="reports.php" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-file-alt w-5"></i>
                        <span class="ml-3 sidebar-text">Reports</span>
                    </a>
                </li>
                <li>
                    <a href="settings.php" class="flex items-center p-3 rounded-lg bg-secondary-700 text-white transition-colors nav-item">
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
                <h1 class="text-xl font-bold">Account Settings</h1>
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
                <!-- Success Message -->
                <?php if (!empty($success_message)): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded-lg">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Error Messages -->
                <?php if (!empty($error_messages)): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded-lg">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars(implode("<br>", $error_messages)); ?>
                    </div>
                <?php endif; ?>

                <!-- Header Section -->
                <div class="bg-white rounded-xl p-6 mb-6 border border-gray-200 shadow-sm dashboard-card">
                    <div class="flex flex-col md:flex-row md:items-center justify-between">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Account Settings</h1>
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
                    </div>
                </div>

                <!-- Settings Tabs -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 dashboard-card">
                    <div class="border-b border-gray-200">
                        <nav class="flex -mb-px px-6">
                            <button class="tab-button border-b-2 border-primary-500 text-primary-600 px-4 py-4 text-sm font-medium tab-active" data-tab="profile">Profile</button>
                            <button class="tab-button border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 px-4 py-4 text-sm font-medium" data-tab="account">Account</button>
                            <button class="tab-button border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 px-4 py-4 text-sm font-medium" data-tab="notifications">Notifications</button>
                            <button class="tab-button border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 px-4 py-4 text-sm font-medium" data-tab="security">Security</button>
                        </nav>
                    </div>

                    <div class="p-6">
                        <!-- Profile Tab -->
                        <div id="profile-tab" class="tab-content">
                            <div class="md:grid md:grid-cols-3 md:gap-6">
                                <div class="md:col-span-1">
                                    <h3 class="text-lg font-medium text-gray-900">Profile</h3>
                                    <p class="mt-1 text-sm text-gray-500">This information will be displayed publicly, so be careful what you share.</p>
                                </div>
                                <div class="mt-5 md:mt-0 md:col-span-2">
                                    <form action="settings.php" method="POST">
                                        <div class="space-y-6">
                                            <!-- Profile Picture -->
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Photo</label>
                                                <div class="mt-2 flex items-center">
                                                    <span class="inline-block h-12 w-12 rounded-full bg-primary-100 flex items-center justify-center text-primary-600 font-medium">
                                                        <?php echo htmlspecialchars($user['initials'] ?? 'T'); ?>
                                                    </span>
                                                    <button type="button" class="ml-5 py-2 px-3 border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                                        Change
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Name -->
                                            <div class="grid grid-cols-6 gap-6">
                                                <div class="col-span-6 sm:col-span-3">
                                                    <label for="first-name" class="block text-sm font-medium text-gray-700">First name</label>
                                                    <input type="text" name="first-name" id="first-name" autocomplete="given-name" value="<?php echo htmlspecialchars($user['first_name']); ?>" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                                </div>
                                                <div class="col-span-6 sm:col-span-3">
                                                    <label for="last-name" class="block text-sm font-medium text-gray-700">Last name</label>
                                                    <input type="text" name="last-name" id="last-name" autocomplete="family-name" value="<?php echo htmlspecialchars($user['last_name']); ?>" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                                </div>
                                            </div>

                                            <!-- Email -->
                                            <div class="col-span-6 sm:col-span-4">
                                                <label for="email-address" class="block text-sm font-medium text-gray-700">Email address</label>
                                                <input type="email" name="email-address" id="email-address" autocomplete="email" value="<?php echo htmlspecialchars($user['email']); ?>" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                            </div>

                                            <!-- Bio -->
                                            <div>
                                                <label for="about" class="block text-sm font-medium text-gray-700">Bio</label>
                                                <div class="mt-1">
                                                    <textarea id="about" name="about" rows="3" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"><?php echo htmlspecialchars($user['bio']); ?></textarea>
                                                </div>
                                                <p class="mt-2 text-sm text-gray-500">Brief description for your profile. URLs are hyperlinked.</p>
                                            </div>
                                        </div>
                                        <div class="mt-6 flex justify-end">
                                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-lg text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                                Save
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Account Tab -->
                        <div id="account-tab" class="tab-content hidden">
                            <div class="md:grid md:grid-cols-3 md:gap-6">
                                <div class="md:col-span-1">
                                    <h3 class="text-lg font-medium text-gray-900">Account</h3>
                                    <p class="mt-1 text-sm text-gray-500">Manage your account settings and preferences.</p>
                                </div>
                                <div class="mt-5 md:mt-0 md:col-span-2">
                                    <form action="#" method="POST">
                                        <div class="space-y-6">
                                            <div>
                                                <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                                                <input type="text" name="username" id="username" value="<?php echo htmlspecialchars($user['username']); ?>" disabled class="mt-1 block w-full rounded-lg border-gray-300 bg-gray-100 shadow-sm sm:text-sm">
                                                <p class="mt-2 text-sm text-gray-500">Username cannot be changed.</p>
                                            </div>
                                            <div>
                                                <label for="language" class="block text-sm font-medium text-gray-700">Language</label>
                                                <select id="language" name="language" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                                    <option value="en" selected>English</option>
                                                    <option value="es">Spanish</option>
                                                    <option value="fr">French</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label for="timezone" class="block text-sm font-medium text-gray-700">Timezone</label>
                                                <select id="timezone" name="timezone" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                                    <option value="UTC" selected>UTC</option>
                                                    <option value="America/New_York">America/New York</option>
                                                    <option value="America/Los_Angeles">America/Los Angeles</option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="mt-6 flex justify-end">
                                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-lg text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                                Save
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Notifications Tab -->
                        <div id="notifications-tab" class="tab-content hidden">
                            <div class="md:grid md:grid-cols-3 md:gap-6">
                                <div class="md:col-span-1">
                                    <h3 class="text-lg font-medium text-gray-900">Notifications</h3>
                                    <p class="mt-1 text-sm text-gray-500">Manage your notification preferences.</p>
                                </div>
                                <div class="mt-5 md:mt-0 md:col-span-2">
                                    <form action="#" method="POST">
                                        <div class="space-y-6">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Email Notifications</label>
                                                <div class="mt-2 space-y-2">
                                                    <div class="flex items-center">
                                                        <input id="email-announcements" name="email-announcements" type="checkbox" checked class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded">
                                                        <label for="email-announcements" class="ml-2 text-sm text-gray-600">New announcements</label>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <input id="email-grades" name="email-grades" type="checkbox" checked class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded">
                                                        <label for="email-grades" class="ml-2 text-sm text-gray-600">Grade updates</label>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <input id="email-attendance" name="email-attendance" type="checkbox" class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded">
                                                        <label for="email-attendance" class="ml-2 text-sm text-gray-600">Attendance alerts</label>
                                                    </div>
                                                </div>
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700">Push Notifications</label>
                                                <div class="mt-2 space-y-2">
                                                    <div class="flex items-center">
                                                        <input id="push-announcements" name="push-announcements" type="checkbox" checked class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded">
                                                        <label for="push-announcements" class="ml-2 text-sm text-gray-600">New announcements</label>
                                                    </div>
                                                    <div class="flex items-center">
                                                        <input id="push-grades" name="push-grades" type="checkbox" class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded">
                                                        <label for="push-grades" class="ml-2 text-sm text-gray-600">Grade updates</label>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="mt-6 flex justify-end">
                                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-lg text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                                Save
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <!-- Security Tab -->
                        <div id="security-tab" class="tab-content hidden">
                            <div class="md:grid md:grid-cols-3 md:gap-6">
                                <div class="md:col-span-1">
                                    <h3 class="text-lg font-medium text-gray-900">Security</h3>
                                    <p class="mt-1 text-sm text-gray-500">Manage your password and security settings.</p>
                                </div>
                                <div class="mt-5 md:mt-0 md:col-span-2">
                                    <form action="#" method="POST">
                                        <div class="space-y-6">
                                            <div>
                                                <label for="current-password" class="block text-sm font-medium text-gray-700">Current Password</label>
                                                <input type="password" name="current-password" id="current-password" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                            </div>
                                            <div>
                                                <label for="new-password" class="block text-sm font-medium text-gray-700">New Password</label>
                                                <input type="password" name="new-password" id="new-password" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                            </div>
                                            <div>
                                                <label for="confirm-password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                                <input type="password" name="confirm-password" id="confirm-password" class="mt-1 block w-full rounded-lg border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                            </div>
                                        </div>
                                        <div class="mt-6 flex justify-end">
                                            <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-lg text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                                Update Password
                                            </button>
                                        </div>
                                    </form>
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

        // Tab switching
        function switchTab(tabId) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.add('hidden');
            });
            // Remove active class from all buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('tab-active');
                button.classList.add('border-transparent', 'text-gray-500', 'hover:text-gray-700', 'hover:border-gray-300');
                button.classList.remove('border-primary-500', 'text-primary-600');
            });
            // Show selected tab and activate button
            const activeTab = document.getElementById(`${tabId}-tab`);
            const activeButton = document.querySelector(`.tab-button[data-tab="${tabId}"]`);
            if (activeTab && activeButton) {
                activeTab.classList.remove('hidden');
                activeButton.classList.add('tab-active', 'border-primary-500', 'text-primary-600');
                activeButton.classList.remove('border-transparent', 'text-gray-500', 'hover:text-gray-700', 'hover:border-gray-300');
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
            console.log('San Agustin Elementary School Settings Page loaded');
            
            // Add click events for notifications
            const notificationItems = document.querySelectorAll('.notification-item');
            notificationItems.forEach(item => {
                item.addEventListener('click', function() {
                    const notificationId = this.getAttribute('data-id');
                    markNotificationAsRead(notificationId);
                });
            });

            // Add click events for tabs
            const tabButtons = document.querySelectorAll('.tab-button');
            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    switchTab(tabId);
                });
            });

            // Initialize with profile tab
            switchTab('profile');
        });
    </script>
</body>
</html>