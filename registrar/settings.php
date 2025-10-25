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

// Get registrar information from database
$registrar_id = $_SESSION['user_id'];
$registrar = [
    'user_id' => $registrar_id,
    'first_name' => 'Registrar',
    'last_name' => 'User',
    'email' => '',
    'contact_number' => '',
    'profile_image' => '',
    'notification_preferences' => 'email',
    'timezone' => 'Asia/Manila'
];

// Initialize variables for form handling
$success_message = '';
$error_message = '';
$form_errors = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle profile update
    if (isset($_POST['update_profile'])) {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        $contact_number = trim($_POST['contact_number']);
        $timezone = $_POST['timezone'];
        
        // Basic validation
        if (empty($first_name)) {
            $form_errors['first_name'] = 'First name is required';
        }
        if (empty($last_name)) {
            $form_errors['last_name'] = 'Last name is required';
        }
        if (empty($email)) {
            $form_errors['email'] = 'Email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $form_errors['email'] = 'Please enter a valid email address';
        }
        
        if (empty($form_errors)) {
            try {
                // Update registrar information in the database
                $stmt = $pdo->prepare("UPDATE registrars SET first_name = ?, last_name = ?, email = ?, contact_number = ?, timezone = ?, updated_at = NOW() WHERE user_id = ?");
                $stmt->execute([$first_name, $last_name, $email, $contact_number, $timezone, $registrar_id]);
                
                // Update session
                $_SESSION['first_name'] = $first_name;
                $_SESSION['last_name'] = $last_name;
                
                $success_message = 'Profile updated successfully!';
                
                // Refresh registrar data
                $registrar['first_name'] = $first_name;
                $registrar['last_name'] = $last_name;
                $registrar['email'] = $email;
                $registrar['contact_number'] = $contact_number;
                $registrar['timezone'] = $timezone;
                
            } catch (PDOException $e) {
                error_log("Error updating profile: " . $e->getMessage());
                $error_message = 'An error occurred while updating your profile. Please try again.';
            }
        }
    }
    // Handle password change
    elseif (isset($_POST['change_password'])) {
        $current_password = $_POST['current_password'];
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        // Basic validation
        if (empty($current_password)) {
            $form_errors['current_password'] = 'Current password is required';
        }
        if (empty($new_password)) {
            $form_errors['new_password'] = 'New password is required';
        } elseif (strlen($new_password) < 8) {
            $form_errors['new_password'] = 'Password must be at least 8 characters long';
        }
        if ($new_password !== $confirm_password) {
            $form_errors['confirm_password'] = 'Passwords do not match';
        }
        
        if (empty($form_errors)) {
            try {
                // Get current password hash from database
                $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$registrar_id]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($current_password, $user['password'])) {
                    // Update password
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $stmt->execute([$hashed_password, $registrar_id]);
                    
                    $success_message = 'Password changed successfully!';
                } else {
                    $form_errors['current_password'] = 'Current password is incorrect';
                }
                
            } catch (PDOException $e) {
                error_log("Error changing password: " . $e->getMessage());
                $error_message = 'An error occurred while changing your password. Please try again.';
            }
        }
    }
    // Handle notification preferences update
    elseif (isset($_POST['update_notifications'])) {
        $notification_preferences = isset($_POST['notification_preferences']) ? $_POST['notification_preferences'] : [];
        $notification_email = in_array('email', $notification_preferences) ? 1 : 0;
        $notification_sms = in_array('sms', $notification_preferences) ? 1 : 0;
        $notification_push = in_array('push', $notification_preferences) ? 1 : 0;
        
        try {
            // Update notification preferences in the database
            $stmt = $pdo->prepare("UPDATE registrars SET notification_email = ?, notification_sms = ?, notification_push = ? WHERE user_id = ?");
            $stmt->execute([$notification_email, $notification_sms, $notification_push, $registrar_id]);
            
            $success_message = 'Notification preferences updated successfully!';
            
            // Update local registrar data
            $registrar['notification_email'] = $notification_email;
            $registrar['notification_sms'] = $notification_sms;
            $registrar['notification_push'] = $notification_push;
            
        } catch (PDOException $e) {
            error_log("Error updating notification preferences: " . $e->getMessage());
            $error_message = 'An error occurred while updating your notification preferences. Please try again.';
        }
    }
}

// Fetch registrar data if not already set
if (empty($registrar['email'])) {
    try {
        $stmt = $pdo->prepare("SELECT r.*, u.email FROM registrars r JOIN users u ON r.user_id = u.id WHERE r.user_id = ?");
        $stmt->execute([$registrar_id]);
        $db_registrar = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($db_registrar) {
            $registrar = array_merge($registrar, $db_registrar);
        }
    } catch (PDOException $e) {
        error_log("Error fetching registrar data: " . $e->getMessage());
    }
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

// Timezone options
$timezones = [
    'Asia/Manila' => 'Manila (UTC+8)',
    'Asia/Singapore' => 'Singapore (UTC+8)',
    'Asia/Tokyo' => 'Tokyo (UTC+9)',
    'Asia/Seoul' => 'Seoul (UTC+9)',
    'Asia/Shanghai' => 'Shanghai (UTC+8)',
    'America/New_York' => 'New York (UTC-5/-4)',
    'America/Los_Angeles' => 'Los Angeles (UTC-8/-7)',
    'Europe/London' => 'London (UTC+0/+1)',
    'Europe/Paris' => 'Paris (UTC+1/+2)',
    'Australia/Sydney' => 'Sydney (UTC+10/+11)'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Registrar Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .nav-tab {
            border-bottom: 2px solid transparent;
        }
        .nav-tab.active {
            border-bottom-color: #3b82f6;
            color: #3b82f6;
        }
        .nav-tab:hover:not(.active) {
            border-bottom-color: #e5e7eb;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-4 sm:px-6 lg:px-8 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-900">Account Settings</h1>
            <div class="flex items-center space-x-4">
                <span class="text-gray-700"><?php echo htmlspecialchars($registrar['first_name'] . ' ' . $registrar['last_name']); ?></span>
                <div class="relative">
                    <button id="user-menu" class="flex items-center text-sm rounded-full focus:outline-none" aria-expanded="false">
                        <div class="h-8 w-8 rounded-full bg-blue-500 text-white flex items-center justify-center">
                            <?php echo $initials; ?>
                        </div>
                    </button>
                    <div id="user-dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Your Profile</a>
                        <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                        <a href="logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Sign out</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="flex h-screen bg-gray-100">
        <!-- Sidebar -->
        <div class="bg-gray-800 text-white w-64 space-y-6 py-7 px-2 absolute inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 transition duration-200 ease-in-out">
            <div class="flex items-center space-x-2 px-4">
                <span class="text-2xl font-extrabold">Registrar</span>
            </div>
            <nav>
                <a href="dashboard.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                </a>
                <a href="student_search.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-search mr-2"></i>Student Search
                </a>
                <a href="enrollment.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-user-plus mr-2"></i>Enrollment
                </a>
                <a href="view_sections.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-chalkboard mr-2"></i>View Sections
                </a>
                <a href="documents.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-file-alt mr-2"></i>Document Requests
                </a>
                <a href="reports.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-chart-bar mr-2"></i>Reports
                </a>
                <a href="settings.php" class="block py-2.5 px-4 rounded transition duration-200 bg-blue-700 hover:bg-blue-600">
                    <i class="fas fa-cog mr-2"></i>Settings
                </a>
            </nav>
        </div>

        <!-- Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-6">
                <?php if ($success_message): ?>
                <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline"><?php echo $success_message; ?></span>
                    <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                        <svg class="fill-current h-6 w-6 text-green-500" role="button" onclick="this.parentElement.parentElement.style.display='none'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <title>Close</title>
                            <path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/>
                        </svg>
                    </span>
                </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative" role="alert">
                    <span class="block sm:inline"><?php echo $error_message; ?></span>
                    <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                        <svg class="fill-current h-6 w-6 text-red-500" role="button" onclick="this.parentElement.parentElement.style.display='none'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <title>Close</title>
                            <path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/>
                        </svg>
                    </span>
                </div>
                <?php endif; ?>
                
                <div class="bg-white shadow rounded-lg overflow-hidden">
                    <!-- Tabs -->
                    <div class="border-b border-gray-200">
                        <nav class="flex -mb-px">
                            <button onclick="openTab(event, 'profile-tab')" class="nav-tab py-4 px-6 text-sm font-medium text-gray-500 hover:text-gray-700 whitespace-nowrap active">
                                <i class="fas fa-user-circle mr-2"></i>Profile
                            </button>
                            <button onclick="openTab(event, 'password-tab')" class="nav-tab py-4 px-6 text-sm font-medium text-gray-500 hover:text-gray-700 whitespace-nowrap">
                                <i class="fas fa-key mr-2"></i>Password
                            </button>
                            <button onclick="openTab(event, 'notifications-tab')" class="nav-tab py-4 px-6 text-sm font-medium text-gray-500 hover:text-gray-700 whitespace-nowrap">
                                <i class="fas fa-bell mr-2"></i>Notifications
                            </button>
                            <button onclick="openTab(event, 'privacy-tab')" class="nav-tab py-4 px-6 text-sm font-medium text-gray-500 hover:text-gray-700 whitespace-nowrap">
                                <i class="fas fa-shield-alt mr-2"></i>Privacy
                            </button>
                        </nav>
                    </div>
                    
                    <!-- Profile Tab -->
                    <div id="profile-tab" class="tab-content p-6 active">
                        <h2 class="text-lg font-medium text-gray-900 mb-6">Profile Information</h2>
                        <form method="POST" action="" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="first_name" class="block text-sm font-medium text-gray-700">First Name</label>
                                    <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($registrar['first_name']); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm <?php echo isset($form_errors['first_name']) ? 'border-red-300' : ''; ?>">
                                    <?php if (isset($form_errors['first_name'])): ?>
                                        <p class="mt-1 text-sm text-red-600"><?php echo $form_errors['first_name']; ?></p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
                                    <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($registrar['last_name']); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm <?php echo isset($form_errors['last_name']) ? 'border-red-300' : ''; ?>">
                                    <?php if (isset($form_errors['last_name'])): ?>
                                        <p class="mt-1 text-sm text-red-600"><?php echo $form_errors['last_name']; ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($registrar['email']); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm <?php echo isset($form_errors['email']) ? 'border-red-300' : ''; ?>">
                                    <?php if (isset($form_errors['email'])): ?>
                                        <p class="mt-1 text-sm text-red-600"><?php echo $form_errors['email']; ?></p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label for="contact_number" class="block text-sm font-medium text-gray-700">Contact Number</label>
                                    <input type="tel" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($registrar['contact_number'] ?? ''); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="timezone" class="block text-sm font-medium text-gray-700">Timezone</label>
                                    <select id="timezone" name="timezone" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                        <?php foreach ($timezones as $tz => $label): ?>
                                            <option value="<?php echo $tz; ?>" <?php echo ($registrar['timezone'] ?? 'Asia/Manila') === $tz ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="flex items-end">
                                    <div class="w-full">
                                        <label class="block text-sm font-medium text-gray-700">Profile Photo</label>
                                        <div class="mt-1 flex items-center">
                                            <div class="h-12 w-12 rounded-full bg-gray-200 overflow-hidden flex items-center justify-center text-gray-500">
                                                <?php if (!empty($registrar['profile_image'])): ?>
                                                    <img src="<?php echo htmlspecialchars($registrar['profile_image']); ?>" alt="Profile" class="h-full w-full object-cover">
                                                <?php else: ?>
                                                    <i class="fas fa-user text-2xl"></i>
                                                <?php endif; ?>
                                            </div>
                                            <button type="button" class="ml-4 bg-white py-2 px-3 border border-gray-300 rounded-md shadow-sm text-sm leading-4 font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                                Change
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="pt-4 border-t border-gray-200">
                                <button type="submit" name="update_profile" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Password Tab -->
                    <div id="password-tab" class="tab-content p-6">
                        <h2 class="text-lg font-medium text-gray-900 mb-6">Change Password</h2>
                        <form method="POST" action="" class="space-y-6 max-w-2xl">
                            <div>
                                <label for="current_password" class="block text-sm font-medium text-gray-700">Current Password</label>
                                <input type="password" id="current_password" name="current_password" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm <?php echo isset($form_errors['current_password']) ? 'border-red-300' : ''; ?>">
                                <?php if (isset($form_errors['current_password'])): ?>
                                    <p class="mt-1 text-sm text-red-600"><?php echo $form_errors['current_password']; ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="new_password" class="block text-sm font-medium text-gray-700">New Password</label>
                                    <input type="password" id="new_password" name="new_password" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm <?php echo isset($form_errors['new_password']) ? 'border-red-300' : ''; ?>">
                                    <?php if (isset($form_errors['new_password'])): ?>
                                        <p class="mt-1 text-sm text-red-600"><?php echo $form_errors['new_password']; ?></p>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm <?php echo isset($form_errors['confirm_password']) ? 'border-red-300' : ''; ?>">
                                    <?php if (isset($form_errors['confirm_password'])): ?>
                                        <p class="mt-1 text-sm text-red-600"><?php echo $form_errors['confirm_password']; ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="pt-4 border-t border-gray-200">
                                <button type="submit" name="change_password" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    Update Password
                                </button>
                            </div>
                        </form>
                        
                        <div class="mt-8">
                            <h3 class="text-md font-medium text-gray-900">Password Requirements</h3>
                            <p class="mt-1 text-sm text-gray-600">Ensure that these requirements are met:</p>
                            <ul class="mt-2 text-sm text-gray-600 list-disc list-inside">
                                <li>At least 8 characters (the more, the better)</li>
                                <li>At least one lowercase character</li>
                                <li>At least one uppercase character</li>
                                <li>At least one number, symbol, or whitespace character</li>
                            </ul>
                        </div>
                    </div>
                    
                    <!-- Notifications Tab -->
                    <div id="notifications-tab" class="tab-content p-6">
                        <h2 class="text-lg font-medium text-gray-900 mb-6">Notification Preferences</h2>
                        <form method="POST" action="" class="space-y-6">
                            <div>
                                <h3 class="text-md font-medium text-gray-900 mb-4">Email Notifications</h3>
                                <div class="space-y-4">
                                    <div class="flex items-start">
                                        <div class="flex items-center h-5">
                                            <input id="notification-email" name="notification_preferences[]" value="email" type="checkbox" class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300 rounded" <?php echo ($registrar['notification_email'] ?? 1) ? 'checked' : ''; ?>>
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="notification-email" class="font-medium text-gray-700">Email</label>
                                            <p class="text-gray-500">Receive notifications via email</p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-start">
                                        <div class="flex items-center h-5">
                                            <input id="notification-sms" name="notification_preferences[]" value="sms" type="checkbox" class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300 rounded" <?php echo ($registrar['notification_sms'] ?? 0) ? 'checked' : ''; ?>>
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="notification-sms" class="font-medium text-gray-700">SMS</label>
                                            <p class="text-gray-500">Receive notifications via SMS (if phone number is provided)</p>
                                        </div>
                                    </div>
                                    
                                    <div class="flex items-start">
                                        <div class="flex items-center h-5">
                                            <input id="notification-push" name="notification_preferences[]" value="push" type="checkbox" class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300 rounded" <?php echo ($registrar['notification_push'] ?? 1) ? 'checked' : ''; ?>>
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="notification-push" class="font-medium text-gray-700">Push Notifications</label>
                                            <p class="text-gray-500">Receive push notifications from your browser</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="pt-4 border-t border-gray-200">
                                <button type="submit" name="update_notifications" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    Save Preferences
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Privacy Tab -->
                    <div id="privacy-tab" class="tab-content p-6">
                        <h2 class="text-lg font-medium text-gray-900 mb-6">Privacy Settings</h2>
                        <div class="space-y-6">
                            <div>
                                <h3 class="text-md font-medium text-gray-900 mb-2">Data Privacy</h3>
                                <p class="text-sm text-gray-600 mb-4">We take your privacy seriously. Here you can manage your data privacy settings.</p>
                                
                                <div class="bg-gray-50 p-4 rounded-lg">
                                    <div class="flex items-start">
                                        <div class="flex items-center h-5">
                                            <input id="data-analytics" type="checkbox" class="focus:ring-blue-500 h-4 w-4 text-blue-600 border-gray-300 rounded" checked>
                                        </div>
                                        <div class="ml-3 text-sm">
                                            <label for="data-analytics" class="font-medium text-gray-700">Allow data collection for analytics</label>
                                            <p class="text-gray-500">Help us improve our services by allowing anonymous usage data to be collected.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="pt-4 border-t border-gray-200">
                                <h3 class="text-md font-medium text-gray-900 mb-2">Data Export & Deletion</h3>
                                <p class="text-sm text-gray-600 mb-4">You can request an export of your data or delete your account.</p>
                                
                                <div class="space-y-4">
                                    <div>
                                        <button type="button" class="inline-flex items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                            <i class="fas fa-file-export mr-2"></i> Export My Data
                                        </button>
                                        <p class="mt-1 text-xs text-gray-500">Download a copy of all your personal data in a structured format.</p>
                                    </div>
                                    
                                    <div>
                                        <button type="button" onclick="confirmDelete()" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                                            <i class="fas fa-trash-alt mr-2"></i> Delete My Account
                                        </button>
                                        <p class="mt-1 text-xs text-gray-500">Permanently delete your account and all associated data. This action cannot be undone.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Delete Account Confirmation Modal -->
    <div id="delete-account-modal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3 text-center">
                <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-red-100">
                    <i class="fas fa-exclamation text-red-600"></i>
                </div>
                <h3 class="text-lg leading-6 font-medium text-gray-900 mt-3">Delete Account</h3>
                <div class="mt-2 px-7 py-3">
                    <p class="text-sm text-gray-500">
                        Are you sure you want to delete your account? All of your data will be permanently removed.
                        This action cannot be undone.
                    </p>
                </div>
                <div class="items-center px-4 py-3">
                    <button id="confirm-delete" class="px-4 py-2 bg-red-600 text-white text-base font-medium rounded-md shadow-sm hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500">
                        Yes, delete my account
                    </button>
                    <button id="cancel-delete" class="ml-3 px-4 py-2 bg-white text-gray-700 text-base font-medium rounded-md border border-gray-300 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Toggle user dropdown
        document.getElementById('user-menu').addEventListener('click', function() {
            document.getElementById('user-dropdown').classList.toggle('hidden');
        });

        // Close dropdown when clicking outside
        window.addEventListener('click', function(event) {
            if (!event.target.matches('#user-menu') && !event.target.closest('#user-menu')) {
                const dropdown = document.getElementById('user-dropdown');
                if (!dropdown.classList.contains('hidden')) {
                    dropdown.classList.add('hidden');
                }
            }
        });

        // Tab functionality
        function openTab(evt, tabName) {
            // Hide all tab content
            const tabContents = document.getElementsByClassName('tab-content');
            for (let i = 0; i < tabContents.length; i++) {
                tabContents[i].classList.remove('active');
            }
            
            // Remove active class from all tab buttons
            const tabButtons = document.getElementsByClassName('nav-tab');
            for (let i = 0; i < tabButtons.length; i++) {
                tabButtons[i].classList.remove('active');
            }
            
            // Show the current tab and add active class to the button that opened the tab
            document.getElementById(tabName).classList.add('active');
            evt.currentTarget.classList.add('active');
        }
        
        // Set the first tab as active by default
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelector('.nav-tab').click();
        });
        
        // Delete account confirmation
        function confirmDelete() {
            const modal = document.getElementById('delete-account-modal');
            modal.classList.remove('hidden');
            
            document.getElementById('confirm-delete').addEventListener('click', function() {
                // Here you would typically make an API call to delete the account
                alert('Account deletion requested. This would trigger an account deletion in a real application.');
                modal.classList.add('hidden');
            });
            
            document.getElementById('cancel-delete').addEventListener('click', function() {
                modal.classList.add('hidden');
            });
            
            // Close modal when clicking outside
            window.onclick = function(event) {
                if (event.target === modal) {
                    modal.classList.add('hidden');
                }
            };
        }
    </script>
</body>
</html>
