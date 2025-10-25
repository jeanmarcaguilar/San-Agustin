<?php
session_start();
require_once 'auth.php';
require_once 'check_login.php';

// Check if user is logged in and has the right role
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: ../login.php');
    exit();
}

// Check if user has the right role (registrar)
if (!isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'registrar') {
    header('Location: ../unauthorized.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Dashboard - San Agustin ES</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        .sidebar {
            transition: all 0.3s ease;
        }
        .sidebar.collapsed {
            width: 5rem;
        }
        .sidebar.collapsed .sidebar-text,
        .sidebar.collapsed .submenu {
            display: none;
        }
        .sidebar.collapsed .fas.fa-chevron-down {
            display: none;
        }
        .submenu {
            transition: all 0.3s ease;
            max-height: 0;
            overflow: hidden;
        }
        .submenu.active {
            max-height: 1000px;
        }
        .nav-item {
            transition: all 0.2s ease;
        }
        .nav-item:hover {
            transform: translateX(4px);
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 10px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
    </style>
</head>
<body class="bg-gray-100 flex h-screen overflow-hidden">
    <!-- Sidebar -->
    <div class="sidebar bg-secondary-800 text-white w-64 flex flex-col flex-shrink-0">
        <!-- Logo -->
        <div class="p-4 border-b border-secondary-700 flex items-center justify-between">
            <div class="flex items-center">
                <img src="../logo.jpg" alt="Logo" class="h-10 w-10 rounded-full mr-3">
                <span class="text-xl font-bold sidebar-text">San Agustin ES</span>
            </div>
            <button id="toggleSidebar" class="text-gray-400 hover:text-white focus:outline-none">
                <i class="fas fa-bars"></i>
            </button>
        </div>
        
        <!-- User Profile -->
        <div class="p-4 border-b border-secondary-700 flex items-center sidebar-text">
            <div class="h-10 w-10 rounded-full bg-primary-600 flex items-center justify-center text-white font-bold mr-3">
                <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium truncate"><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></p>
                <p class="text-xs text-gray-400 truncate"><?php echo ucfirst($_SESSION['role'] ?? 'User'); ?></p>
            </div>
        </div>
        
        <!-- Navigation -->
        <div class="flex-1 overflow-y-auto custom-scrollbar">
            <ul class="space-y-2 p-2">
                <li>
                    <a href="dashboard.php" class="flex items-center p-3 rounded-lg text-white bg-primary-600 shadow-md nav-item">
                        <i class="fas fa-home w-5"></i>
                        <span class="ml-3 sidebar-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('students-submenu', this)">
                        <i class="fas fa-user-graduate w-5"></i>
                        <span class="ml-3 sidebar-text">Student Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="students-submenu" class="submenu pl-4 mt-1">
                        <a href="enroll_student_form.php" class="flex items-center p-2 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-plus w-5"></i>
                            <span class="ml-3 sidebar-text">Enroll New Student</span>
                        </a>
                        <a href="view_students.php" class="flex items-center p-2 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-list w-5"></i>
                            <span class="ml-3 sidebar-text">View All Students</span>
                        </a>
                        <a href="student_search.php" class="flex items-center p-2 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors">
                            <i class="fas fa-search w-5"></i>
                            <span class="ml-3 sidebar-text">Search Student</span>
                        </a>
                    </div>
                </li>
                <li>
                    <a href="enrollment.php" class="flex items-center p-3 rounded-lg text-gray-300 hover:bg-secondary-700 hover:text-white transition-colors nav-item">
                        <i class="fas fa-clipboard-list w-5"></i>
                        <span class="ml-3 sidebar-text">Enrollment</span>
                    </a>
                </li>
                <!-- Add more menu items as needed -->
            </ul>
        </div>
        
        <!-- Logout -->
        <div class="p-4 border-t border-secondary-700">
            <a href="../logout.php" class="flex items-center p-2 rounded-lg text-red-400 hover:bg-red-900 hover:text-red-200 transition-colors">
                <i class="fas fa-sign-out-alt w-5"></i>
                <span class="ml-3 sidebar-text">Logout</span>
            </a>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="flex-1 flex flex-col overflow-hidden">
        <!-- Top Navigation -->
        <header class="bg-white shadow-sm">
            <div class="flex items-center justify-between p-4">
                <div class="flex items-center">
                    <h1 class="text-xl font-semibold text-gray-800">
                        <?php 
                        $title = '';
                        $current_page = basename($_SERVER['PHP_SELF']);
                        switch($current_page) {
                            case 'dashboard.php':
                                $title = 'Dashboard';
                                break;
                            case 'enroll_student_form.php':
                                $title = 'Enroll New Student';
                                break;
                            case 'view_students.php':
                                $title = 'View All Students';
                                break;
                            case 'student_search.php':
                                $title = 'Search Students';
                                break;
                            case 'enrollment.php':
                                $title = 'Enrollment';
                                break;
                            default:
                                $title = 'Registrar Dashboard';
                        }
                        echo $title;
                        ?>
                    </h1>
                </div>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <button class="p-2 text-gray-600 hover:text-gray-900 focus:outline-none">
                            <i class="fas fa-bell"></i>
                            <span class="absolute top-0 right-0 h-2 w-2 rounded-full bg-red-500"></span>
                        </button>
                    </div>
                    <div class="relative">
                        <button class="flex items-center space-x-2 focus:outline-none">
                            <div class="h-8 w-8 rounded-full bg-primary-600 flex items-center justify-center text-white font-bold">
                                <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
                            </div>
                            <span class="hidden md:inline text-sm font-medium text-gray-700">
                                <?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </header>
        
        <!-- Page Content -->
        <main class="flex-1 overflow-y-auto p-4 bg-gray-50">
