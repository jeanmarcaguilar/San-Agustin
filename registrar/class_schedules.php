<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('Location: ../login.php');
    exit();
}

// Initialize database connections
$database = new Database();
$pdo = $database->getConnection('registrar');
$login_pdo = $database->getConnection('login');

// Initialize stats array
$stats = [
    'pending_documents' => 0,
    'new_applications' => 0,
    'total_students' => 0,
    'active_sections' => 0
];

// Get registrar info for header
$registrar_id_display = 'R' . $_SESSION['user_id'];
$initials = 'R' . substr($_SESSION['user_id'], -1); // Get last digit for initials
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
    
    // Keep the R + last digit format for consistency
    $initials = 'R' . substr($_SESSION['user_id'], -1);
} catch (Exception $e) {
    error_log("Error fetching registrar info: " . $e->getMessage());
}

// Get grade and section from URL parameters
$selected_grade = isset($_GET['grade_level']) ? (int)$_GET['grade_level'] : null;
$selected_section = isset($_GET['section']) ? $_GET['section'] : null;

// Get statistics
try {
    // Total Students
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM students");
    $stats['total_students'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    error_log("Error getting student count: " . $e->getMessage());
}

try {
    // Active Sections
    $stmt = $pdo->query("SELECT COUNT(DISTINCT id) as count FROM class_sections WHERE status = 'active'");
    $stats['active_sections'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    error_log("Error getting active sections: " . $e->getMessage());
}

try {
    // New Applications
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM enrollment_requests WHERE status = 'pending'");
    $stats['new_applications'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    error_log("Error fetching new applications count: " . $e->getMessage());
}

try {
    // Pending Documents
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM document_requests WHERE status = 'pending'");
    $stats['pending_documents'] = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    error_log("Error fetching pending documents count: " . $e->getMessage());
}

// Get school years for filter
$school_years = [];
$current_year = date('Y');
for ($i = -2; $i <= 2; $i++) {
    $year = $current_year + $i;
    $school_years[] = "$year-" . ($year + 1);
}

// Set default school year to 2024-2025 since that's when the schedules are available
$current_year = 2024; // Force 2024 to get 2024-2025
$selected_year = $_GET['school_year'] ?? $current_year . '-' . ($current_year + 1);

// For future use: If no schedules found for current year, fall back to previous year
$test_year = ($current_year - 1) . '-' . $current_year;
$selected_grade = $_GET['grade_level'] ?? '';
$selected_section = $_GET['section'] ?? '';
$error = '';

// Debug: Check database connection
if (!$pdo) {
    die("Database connection failed");
}

// Debug: Check database connection details
error_log("Database connection successful. Database: " . $pdo->query("SELECT DATABASE()")->fetchColumn());

// Get class schedules, sections, and teachers
try {
    // Debug: Check if tables exist
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    error_log("Available tables: " . print_r($tables, true));
    
    // Check if class_schedules table exists
    if (!in_array('class_schedules', $tables)) {
        die("Error: class_schedules table does not exist in the database");
    }
    
    // Debug: Check class_schedules table structure
    $stmt = $pdo->query("DESCRIBE class_schedules");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    error_log("class_schedules columns: " . print_r($columns, true));
    
    // Debug: Check if there's any data in class_schedules
    $count = $pdo->query("SELECT COUNT(*) FROM class_schedules")->fetchColumn();
    error_log("Number of schedules in class_schedules: " . $count);
    
    // Get all active sections for the dropdown
    $stmt = $pdo->query("SELECT DISTINCT grade_level, section FROM class_sections WHERE status = 'active' ORDER BY grade_level, section");
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Sections: " . print_r($sections, true));
    
    // Get all teachers for the dropdown
    $stmt = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) as teacher_name FROM teachers WHERE status = 'active' ORDER BY last_name, first_name");
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Teachers: " . print_r($teachers, true));
    
    // Initialize parameters array
    $params = [];
    
    // Check if we have active teachers
    $teacherCount = $pdo->query("SELECT COUNT(*) FROM teachers WHERE status = 'active'")->fetchColumn();
    error_log("Active teachers found: " . $teacherCount);
    
    // If no active teachers, show a warning
    if ($teacherCount == 0) {
        $error = "Warning: No active teachers found. Please add teachers first.";
        error_log($error);
    }
    
    // Prepare base query for schedules with proper joins
    $query = "SELECT 
                cs.*, 
                CONCAT(COALESCE(t.first_name, ''), ' ', COALESCE(t.last_name, '')) as teacher_name,
                cs.grade_level, 
                cs.section,
                COALESCE(sec.room_number, cs.room) as room_number
              FROM class_schedules cs
              LEFT JOIN teachers t ON cs.teacher_id = t.id AND t.status = 'active'
              LEFT JOIN class_sections sec ON cs.grade_level = sec.grade_level AND cs.section = sec.section
              WHERE cs.status = 'active'";
    
    // Add school year filter - try current year first, then fall back to previous year if needed
    $query .= " AND (cs.school_year = :school_year";
    $params[':school_year'] = $selected_year;
    
    // If we're not already testing the previous year, add it as an OR condition
    if ($selected_year !== $test_year) {
        $query .= " OR cs.school_year = :test_year";
        $params[':test_year'] = $test_year;
    }
    $query .= ")";
    
    if (!empty($selected_grade) && is_numeric($selected_grade)) {
        $query .= " AND cs.grade_level = :grade_level";
        $params[':grade_level'] = (int)$selected_grade;
    }
    
    if (!empty($selected_section)) {
        $query .= " AND cs.section = :section";
        $params[':section'] = $selected_section;
    }
    
    // Add ordering
    $query .= " ORDER BY 
        cs.grade_level, 
        cs.section,
        FIELD(cs.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'),
        cs.start_time";
    
    error_log("Executing query: " . $query);
    error_log("With params: " . print_r($params, true));
    
    try {
        // Prepare and execute the query
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Schedules found: " . count($schedules));
        
        // Debug: Log first few schedules if any
        if (count($schedules) > 0) {
            error_log("First schedule: " . print_r($schedules[0], true));
        }
    } catch (PDOException $e) {
        error_log("Error fetching schedules: " . $e->getMessage());
        $schedules = [];
    }
    
} catch (PDOException $e) {
    $error = "Error fetching class schedules: " . $e->getMessage();
    error_log($error);
    // Output the error to the page for debugging
    die("Database Error: " . $e->getMessage() . "<br>Query: " . ($query ?? '') . "<br>Params: " . print_r($params ?? [], true));
}

$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

try {
    // Build the query based on filters
    $query = "SELECT cs.*, t.first_name, t.last_name, t.teacher_id 
              FROM class_schedules cs 
              LEFT JOIN teachers t ON cs.teacher_id = t.id 
              WHERE cs.school_year = :school_year";
    
    $params = [':school_year' => $selected_year];
    
    if ($selected_grade) {
        $query .= " AND cs.grade_level = :grade_level";
        $params[':grade_level'] = $selected_grade;
    }
    
    if ($selected_section) {
        $query .= " AND cs.section = :section";
        $params[':section'] = $selected_section;
    }
    
    $query .= " ORDER BY cs.grade_level, cs.section, cs.day_of_week, cs.start_time";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all distinct sections for the selected grade
    $section_query = "SELECT DISTINCT section FROM class_sections 
                     WHERE status = 'active'";
    
    if ($selected_grade) {
        $section_query .= " AND grade_level = :grade_level";
    }
    
    $section_query .= " ORDER BY section";
    
    $stmt = $pdo->prepare($section_query);
    
    if ($selected_grade) {
        $stmt->bindParam(':grade_level', $selected_grade);
    }
    
    $stmt->execute();
    $sections = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get all active teachers
    $stmt = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) as name, teacher_id 
                         FROM teachers 
                         WHERE status = 'Active' 
                         ORDER BY last_name, first_name");
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Get all active sections from student_db
    $student_conn = $database->getConnection('student');
    $stmt = $student_conn->query("SELECT DISTINCT 
                         cs.grade_level, 
                         cs.section,
                         (SELECT COUNT(*) FROM students s 
                          WHERE s.grade_level = cs.grade_level 
                          AND s.section = cs.section) as current_students
                         FROM class_sections cs 
                         WHERE cs.status = 'active' 
                         ORDER BY cs.grade_level, cs.section");
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add default max_students
    foreach ($sections as &$section) {
        $section['max_students'] = 30; // Default capacity
    }
    
    // Get all teachers from teacher_db
    $teacher_conn = $database->getConnection('teacher');
    $stmt = $teacher_conn->query("SELECT teacher_id as id, CONCAT(first_name, ' ', last_name) as name 
                                   FROM teachers 
                                   ORDER BY first_name, last_name");
    $teachers = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Build query for schedules
    // Note: teacher_id in class_schedules is VARCHAR (e.g., "T-001")
    // We need to join with teacher_db to get teacher names
    $teacher_conn = $database->getConnection('teacher');
    
    $query = "SELECT cs.*, 
              cs.teacher_id as teacher_id_string,
              CONCAT(cs.grade_level, '-', cs.section) as class_name
              FROM class_schedules cs
              WHERE cs.school_year = :school_year";
    
    $params = [':school_year' => $selected_year];
    
    if ($selected_grade) {
        $query .= " AND COALESCE(cs.grade_level, '') = :grade_level";
        $params[':grade_level'] = $selected_grade;
    }
    if ($selected_section) {
        $query .= " AND COALESCE(cs.section, '') = :section";
        $params[':section'] = $selected_section;
    }
    
    $query .= " ORDER BY cs.grade_level, cs.section, cs.day_of_week";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch teacher names from teacher_db for each schedule
    foreach ($schedules as &$schedule) {
        if (!empty($schedule['teacher_id'])) {
            $stmt_teacher = $teacher_conn->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM teachers WHERE teacher_id = ?");
            $stmt_teacher->execute([$schedule['teacher_id']]);
            $teacher = $stmt_teacher->fetch(PDO::FETCH_ASSOC);
            $schedule['teacher_name'] = $teacher ? $teacher['name'] : 'Unassigned';
        } else {
            $schedule['teacher_name'] = 'Unassigned';
        }
    }
} catch (PDOException $e) {
    $error = "Error fetching schedules: " . $e->getMessage();
    error_log($error);
}

// Determine current page for sidebar highlighting
$current_page = basename($_SERVER['PHP_SELF']);
$page_title = 'Class Schedules';
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
        .modal {
            background: rgba(0, 0, 0, 0.5);
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('students-submenu', this)">
                        <i class="fas fa-user-graduate w-5"></i>
                        <span class="ml-3 sidebar-text">Student Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="students-submenu" class="submenu pl-4 mt-1 <?php echo $current_page === 'add_student.php' || $current_page === 'view_students.php' || $current_page === 'student_search.php' ? 'open' : ''; ?>">
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
                    <div id="sections-submenu" class="submenu pl-4 mt-1 <?php echo in_array($current_page, ['view_sections.php', 'class_schedules.php']) ? 'open' : ''; ?>">
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('reports-submenu', this)">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="reports-submenu" class="submenu pl-4 mt-1">
                        <a href="enrollment_reports.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'enrollment_reports.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-file-alt w-5"></i>
                            <span class="ml-3 sidebar-text">Enrollment Reports</span>
                        </a>
                        <a href="demographic_reports.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'demographic_reports.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-chart-pie w-5"></i>
                            <span class="ml-3 sidebar-text">Demographic Reports</span>
                        </a>
                        <a href="transcript_requests.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'transcript_requests.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-file-certificate w-5"></i>
                            <span class="ml-3 sidebar-text">Transcript Requests</span>
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
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <span class="hidden md:inline-block">Registrar</span>
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
                <div>
                    <div class="flex items-center">
                        <?php if ($selected_grade && $selected_section): ?>
                            <a href="view_sections.php" class="text-blue-600 hover:text-blue-800 mr-2">
                                <i class="fas fa-arrow-left"></i> Back to Sections
                            </a>
                            <span class="text-gray-400 mx-2">/</span>
                        <?php endif; ?>
                        <h1 class="text-2xl font-bold text-gray-800">
                            <?php 
                            if ($selected_grade && $selected_section) {
                                echo "Grade $selected_grade - $selected_section Schedule";
                            } else {
                                echo 'Class Schedules';
                            }
                            ?>
                        </h1>
                    </div>
                    <?php if ($selected_grade && $selected_section): ?>
                        <p class="text-sm text-gray-600 mt-1">Manage the class schedule for this section</p>
                    <?php endif; ?>
                </div>
                <button id="addScheduleBtn" class="bg-primary-600 text-white px-4 py-2 rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 dashboard-card" aria-label="Add new schedule">
                    <i class="fas fa-plus mr-2"></i> Add Schedule
                </button>
            </div>
            
            <!-- Filters -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6 dashboard-card">
                <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <div>
                        <label for="school_year" class="block text-sm font-medium text-gray-700 mb-1">School Year</label>
                        <select name="school_year" id="school_year" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <?php foreach ($school_years as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo $selected_year == $year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="grade_level" class="block text-sm font-medium text-gray-700 mb-1">Grade Level</label>
                        <select name="grade_level" id="grade_level" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <option value="">All Grades</option>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $selected_grade == $i ? 'selected' : ''; ?>>
                                    Grade <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="section" class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                        <select name="section" id="section" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <option value="">All Sections</option>
                            <?php foreach ($sections as $section): ?>
                                <?php if ($selected_grade && $section['grade_level'] == $selected_grade || !$selected_grade): ?>
                                    <option value="<?php echo htmlspecialchars($section['section']); ?>" 
                                            <?php echo $selected_section === $section['section'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($section['section']); ?> (<?php echo $section['current_students']; ?>/<?php echo $section['max_students']; ?>)
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="bg-primary-600 text-white px-4 py-2 rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-filter mr-2"></i> Filter
                        </button>
                        <button type="button" onclick="document.getElementById('filterForm').reset(); document.getElementById('filterForm').submit();" 
                                class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            Clear
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
                    <h2 class="text-lg font-medium text-gray-800">
                        <?php 
                        if ($selected_grade && $selected_section) {
                            echo "Grade $selected_grade - $selected_section Schedule";
                        } elseif ($selected_grade) {
                            echo "Grade $selected_grade Schedules";
                        } else {
                            echo 'All Class Schedules';
                        }
                        ?>
                    </h2>
                    <p class="mt-1 text-sm text-gray-500">
                        School Year: <?php echo htmlspecialchars($selected_year); ?>
                    </p>
                </div>
                
                <div class="overflow-x-auto">
                    <?php if (empty($schedules)): ?>
                        <div class="px-6 py-12 text-center text-gray-500">
                            <i class="fas fa-calendar-alt text-4xl mb-3"></i>
                            <p>No schedules found for the selected filters. Add a new schedule to get started.</p>
                        </div>
                    <?php else: ?>
                        <!-- Weekly Schedule View -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                                        <?php foreach ($days_of_week as $day): ?>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo $day; ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php
                                    // Group schedules by time slots
                                    $time_slots = [];
                                    foreach ($schedules as $schedule) {
                                        $time_key = $schedule['start_time'] . '-' . $schedule['end_time'];
                                        $time_slots[$time_key] = [
                                            'start' => $schedule['start_time'],
                                            'end' => $schedule['end_time']
                                        ];
                                    }
                                    
                                    // Sort time slots
                                    usort($time_slots, function($a, $b) {
                                        return strtotime($a['start']) - strtotime($b['start']);
                                    });
                                    
                                    // Display each time slot row
                                    foreach ($time_slots as $time_slot):
                                        $start = date('h:i A', strtotime($time_slot['start']));
                                        $end = date('h:i A', strtotime($time_slot['end']));
                                    ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?php echo $start; ?><br>
                                                <span class="text-xs text-gray-500"><?php echo $end; ?></span>
                                            </td>
                                            
                                            <?php foreach ($days_of_week as $day): 
                                                // Find schedule for this day and time slot
                                                $schedule_found = array_filter($schedules, function($s) use ($day, $time_slot) {
                                                    return is_array($s) && 
                                                           isset($s['day_of_week'], $s['start_time'], $s['end_time'], 
                                                                $time_slot['start'], $time_slot['end']) &&
                                                           $s['day_of_week'] === $day && 
                                                           $s['start_time'] === $time_slot['start'] && 
                                                           $s['end_time'] === $time_slot['end'];
                                                });
                                                
                                                $schedule = reset($schedule_found);
                                            ?>
                                                <td class="px-6 py-4">
                                                    <?php if ($schedule): ?>
                                                        <div class="bg-blue-50 border-l-4 border-blue-400 p-3 rounded">
                                                            <div class="font-medium text-sm text-gray-900">
                                                                <?php echo htmlspecialchars($schedule['subject']); ?>
                                                            </div>
                                                            <div class="text-xs text-gray-600 mt-1">
                                                                <?php 
                                                                    echo !empty($schedule['first_name']) 
                                                                        ? htmlspecialchars($schedule['first_name'] . ' ' . $schedule['last_name'])
                                                                        : 'No teacher assigned';
                                                                ?>
                                                            </div>
                                                            <div class="text-xs text-gray-500 mt-1">
                                                                <?php 
                                                                    echo !empty($schedule['room']) 
                                                                        ? 'Room: ' . htmlspecialchars($schedule['room'])
                                                                        : 'No room assigned';
                                                                ?>
                                                            </div>
                                                            <div class="mt-2 flex space-x-2">
                                                                <button onclick="editSchedule(<?php echo htmlspecialchars(json_encode($schedule)); ?>)" class="text-xs text-blue-600 hover:text-blue-800">
                                                                    <i class="fas fa-edit"></i>
                                                                </button>
                                                                <button onclick="deleteSchedule(<?php echo $schedule['id']; ?>)" class="text-xs text-red-600 hover:text-red-800">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="text-center text-gray-400 text-sm py-3">
                                                            <i class="far fa-calendar-minus"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Add/Edit Schedule Modal -->
            <div id="scheduleModal" class="hidden fixed inset-0 modal overflow-y-auto h-full w-full z-50" aria-labelledby="modalTitle" role="dialog">
                <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white dashboard-card">
                    <div class="flex justify-between items-center pb-3">
                        <h3 class="text-xl font-medium text-gray-900" id="modalTitle">Add New Schedule</h3>
                        <button onclick="closeModal()" class="text-gray-400 hover:text-gray-500 focus:outline-none" aria-label="Close modal">
                            <span class="text-2xl">&times;</span>
                        </button>
                    </div>
                    
                    <form id="scheduleForm" class="space-y-4">
                        <input type="hidden" id="scheduleId" name="id">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="md:col-span-2">
                                <label for="class_section" class="block text-sm font-medium text-gray-700">Grade & Section <span class="text-red-500">*</span></label>
                                <select id="class_section" name="class_section" required 
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    <option value="">Select Grade & Section</option>
                                    <?php foreach ($sections as $section): ?>
                                        <option value="<?php echo $section['grade_level'] . '|' . htmlspecialchars($section['section']); ?>" 
                                                data-grade="<?php echo $section['grade_level']; ?>"
                                                data-section="<?php echo htmlspecialchars($section['section']); ?>">
                                            Grade <?php echo $section['grade_level']; ?> - <?php echo htmlspecialchars($section['section']); ?> 
                                            (<?php echo $section['current_students']; ?>/<?php echo $section['max_students']; ?> students)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" id="grade_level" name="grade_level">
                                <input type="hidden" id="section" name="section">
                            </div>
                            
                            <div>
                                <label for="subject" class="block text-sm font-medium text-gray-700">Subject <span class="text-red-500">*</span></label>
                                <input type="text" id="subject" name="subject" required
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            </div>
                            
                            <div>
                                <label for="teacher_id" class="block text-sm font-medium text-gray-700">Teacher <span class="text-red-500">*</span></label>
                                <select id="teacher_id" name="teacher_id" required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    <option value="">Select Teacher</option>
                                    <?php foreach ($teachers as $id => $name): ?>
                                        <option value="<?php echo $id; ?>"><?php echo htmlspecialchars($name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="day_of_week" class="block text-sm font-medium text-gray-700">Day of Week <span class="text-red-500">*</span></label>
                                <select id="day_of_week" name="day_of_week" required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    <option value="Monday">Monday</option>
                                    <option value="Tuesday">Tuesday</option>
                                    <option value="Wednesday">Wednesday</option>
                                    <option value="Thursday">Thursday</option>
                                    <option value="Friday">Friday</option>
                                    <option value="Saturday">Saturday</option>
                                </select>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label for="start_time" class="block text-sm font-medium text-gray-700">Start Time <span class="text-red-500">*</span></label>
                                    <input type="time" id="start_time" name="start_time" required
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                </div>
                                <div>
                                    <label for="end_time" class="block text-sm font-medium text-gray-700">End Time <span class="text-red-500">*</span></label>
                                    <input type="time" id="end_time" name="end_time" required
                                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                </div>
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="room" class="block text-sm font-medium text-gray-700">Room</label>
                                <input type="text" id="room" name="room"
                                       class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                            </div>
                            
                            <div class="md:col-span-2">
                                <label for="school_year" class="block text-sm font-medium text-gray-700">School Year <span class="text-red-500">*</span></label>
                                <select id="school_year" name="school_year" required
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                                    <?php foreach ($school_years as $year): ?>
                                        <option value="<?php echo $year; ?>" <?php echo $selected_year == $year ? 'selected' : ''; ?>>
                                            <?php echo $year; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex justify-end space-x-3">
                            <button type="button" onclick="closeModal()" 
                                    class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                Cancel
                            </button>
                            <button type="submit" id="saveScheduleBtn"
                                    class="inline-flex justify-center py-2 px-4 border border-transparent rounded-md text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                Save Schedule
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
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
            
            setTimeout(() => toast.classList.add('show'), 10);
            setTimeout(() => toast.remove(), 5000);
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
                userButton.setAttribute('aria-expanded', !userMenu.classList.contains('hidden'));
            }
        }

        // Toggle notifications panel
        function toggleNotifications() {
            const notificationPanel = document.getElementById('notification-panel');
            const notificationButton = document.getElementById('notification-btn');
            if (notificationPanel && notificationButton) {
                notificationPanel.classList.toggle('open');
                notificationButton.setAttribute('aria-expanded', notificationPanel.classList.contains('open'));
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
                
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            }
        }

        // Modal functions
        function openModal() {
            const modal = document.getElementById('scheduleModal');
            if (modal) {
                modal.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
                document.getElementById('grade_level').focus();
            }
        }

        function closeModal() {
            const modal = document.getElementById('scheduleModal');
            if (modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
                document.getElementById('scheduleForm').reset();
                document.getElementById('scheduleId').value = '';
                document.getElementById('modalTitle').textContent = 'Add New Schedule';
                document.getElementById('section').innerHTML = '<option value="">Select Section</option>';
            }
        }

        // Edit schedule
        function editSchedule(schedule) {
            document.getElementById('modalTitle').textContent = 'Edit Schedule';
            document.getElementById('scheduleId').value = schedule.id;
            
            // Set combined class_section dropdown
            const classSection = document.getElementById('class_section');
            const combinedValue = schedule.grade_level + '|' + schedule.section;
            classSection.value = combinedValue;
            
            // Set hidden fields
            document.getElementById('grade_level').value = schedule.grade_level || '';
            document.getElementById('section').value = schedule.section || '';
            
            // Set other fields
            document.getElementById('subject').value = schedule.subject || '';
            document.getElementById('teacher_id').value = schedule.teacher_id || '';
            document.getElementById('day_of_week').value = schedule.day_of_week || 'Monday';
            document.getElementById('start_time').value = schedule.start_time || '';
            document.getElementById('end_time').value = schedule.end_time || '';
            document.getElementById('room').value = schedule.room || '';
            document.getElementById('school_year').value = schedule.school_year || '<?php echo $selected_year; ?>';
            
            openModal();
        }

        // Delete schedule
        function deleteSchedule(id, scheduleName) {
            if (confirm(`Are you sure you want to delete the schedule for ${scheduleName}? This action cannot be undone.`)) {
                fetch('ajax/delete_schedule.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'id=' + id
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showToast('Schedule deleted successfully', 'success');
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        showToast(data.message || 'Failed to delete schedule', 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showToast('An error occurred while deleting the schedule', 'error');
                });
            }
        }

        // Validate form
        function validateForm() {
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            
            if (startTime && endTime) {
                const start = new Date(`1970-01-01T${startTime}:00`);
                const end = new Date(`1970-01-01T${endTime}:00`);
                if (start >= end) {
                    showToast('End time must be after start time', 'error');
                    return false;
                }
            }
            return true;
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize sidebar state
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
            
            // Show toast messages
            <?php if (isset($_SESSION['success_message'])): ?>
                showToast('<?php echo addslashes($_SESSION['success_message']); ?>', 'success');
                <?php unset($_SESSION['success_message']); ?>
            <?php elseif ($error): ?>
                showToast('<?php echo addslashes($error); ?>', 'error');
            <?php else: ?>
                showToast('Welcome to the Registrar Portal!', 'success');
            <?php endif; ?>
            
            // Close sidebar on nav item click (mobile)
            document.querySelectorAll('.nav-item').forEach(item => {
                item.addEventListener('click', () => {
                    if (window.innerWidth < 768) closeSidebar();
                });
            });
            
            // Handle class_section change to populate hidden fields
            document.getElementById('class_section').addEventListener('change', function() {
                const selected = this.options[this.selectedIndex];
                if (selected.value) {
                    const grade = selected.dataset.grade;
                    const section = selected.dataset.section;
                    document.getElementById('grade_level').value = grade;
                    document.getElementById('section').value = section;
                } else {
                    document.getElementById('grade_level').value = '';
                    document.getElementById('section').value = '';
                }
            });
            
            // Add schedule button
            document.getElementById('addScheduleBtn').addEventListener('click', function() {
                document.getElementById('modalTitle').textContent = 'Add New Schedule';
                document.getElementById('scheduleForm').reset();
                document.getElementById('scheduleId').value = '';
                document.getElementById('grade_level').value = '';
                document.getElementById('section').value = '';
                openModal();
            });
            
            // Form submission
            document.getElementById('scheduleForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Ensure grade_level and section are populated from class_section dropdown
                const classSection = document.getElementById('class_section');
                if (classSection.value) {
                    const selected = classSection.options[classSection.selectedIndex];
                    document.getElementById('grade_level').value = selected.dataset.grade;
                    document.getElementById('section').value = selected.dataset.section;
                }
                
                if (!validateForm()) return;
                
                const formData = new FormData(this);
                const action = formData.get('id') ? 'update_schedule.php' : 'add_schedule.php';
                const saveButton = document.getElementById('saveScheduleBtn');
                saveButton.disabled = true;
                saveButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';
                
                fetch('ajax/' + action, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    saveButton.disabled = false;
                    saveButton.innerHTML = 'Save Schedule';
                    if (data.success) {
                        showToast(data.message || 'Schedule saved successfully', 'success');
                        closeModal();
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        showToast(data.message || 'Failed to save schedule', 'error');
                    }
                })
                .catch(error => {
                    saveButton.disabled = false;
                    saveButton.innerHTML = 'Save Schedule';
                    console.error('Error:', error);
                    showToast('An error occurred while saving the schedule', 'error');
                });
            });
            
            
            // Keyboard accessibility
            document.addEventListener('keydown', function(event) {
                const modal = document.getElementById('scheduleModal');
                if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
                    closeModal();
                }
                
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
                
                const saveButton = document.getElementById('saveScheduleBtn');
                if (saveButton && (event.key === 'Enter' || event.key === ' ') && event.target === saveButton && !saveButton.disabled) {
                    event.preventDefault();
                    document.getElementById('scheduleForm').submit();
                }
            });
            
            // Handle clicks outside dropdowns and modal
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
                
                const modal = document.getElementById('scheduleModal');
                if (modal && !modal.classList.contains('hidden') && !event.target.closest('.dashboard-card') && !event.target.closest('#addScheduleBtn')) {
                    closeModal();
                }
            });
        });
    </script>
</body>
</html>