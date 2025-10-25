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

// Get grade level and section filters
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';

$students = [];
$sections = [];
$error = '';

// Initialize stats array
$stats = [
    'pending_documents' => 0,
    'new_applications' => 0,
    'total_students' => 0,
    'active_sections' => 0
];
$registrar_id_display = 'R' . $_SESSION['user_id'];
$initials = 'R' . substr($_SESSION['user_id'], -1); // Get last digit for initials
// Get registrar info for header
$registrar = [];
$initials = 'R' . substr($_SESSION['user_id'], -1); // Set initials to R + last digit of user_id
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
    
    // Set initials from registrar name or username
    if (!empty($registrar['first_name'])) {
        $initials = strtoupper(substr($registrar['first_name'], 0, 1));
        if (!empty($registrar['last_name'])) {
            $initials .= strtoupper(substr($registrar['last_name'], 0, 1));
        }
    } else {
        $initials = strtoupper(substr($_SESSION['username'] ?? 'R', 0, 2));
    }
} catch (Exception $e) {
    error_log("Error fetching registrar info: " . $e->getMessage());
}

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
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM enrollments WHERE status = 'pending'");
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

try {
    // First, check if max_students column exists in class_sections table
    $columnExists = false;
    try {
        $checkStmt = $pdo->query("SHOW COLUMNS FROM class_sections LIKE 'max_students'");
        $columnExists = $checkStmt->rowCount() > 0;
    } catch (PDOException $e) {
        error_log("Error checking for max_students column: " . $e->getMessage());
    }

    // Build query with fallback for max_students
    $query = "SELECT DISTINCT cs.grade_level, cs.section, ";
    $query .= $columnExists ? "cs.max_students" : "40 as max_students";
    $query .= ", (SELECT COUNT(*) FROM students s WHERE s.grade_level = cs.grade_level AND s.section = cs.section) as current_students ";
    $query .= "FROM class_sections cs ";
    $query .= "WHERE cs.status = 'active' ";
    $query .= "ORDER BY cs.grade_level, cs.section";
    
    $stmt = $pdo->query($query);
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch students based on filters
    $query = "SELECT s.* FROM students s WHERE s.status = 'Active'";
    $params = [];
    
    if ($grade_level && $section) {
        $query .= " AND COALESCE(s.grade_level, '') = :grade_level AND COALESCE(s.section, '') = :section";
        $params[':grade_level'] = $grade_level;
        $params[':section'] = $section;
    } elseif ($grade_level) {
        $query .= " AND COALESCE(s.grade_level, '') = :grade_level";
        $params[':grade_level'] = $grade_level;
    } else {
        $query .= " AND (COALESCE(s.grade_level, '') = '' OR COALESCE(s.section, '') = '')";
    }
    
    $query .= " ORDER BY s.last_name, s.first_name";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching student data: " . $e->getMessage();
}

// Handle form submission for bulk assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_students'])) {
    try {
        $pdo->beginTransaction();
        
        $grade = $_POST['assign_grade'];
        $section = $_POST['assign_section'];
        $student_ids = $_POST['student_ids'] ?? [];
        
        if (empty($student_ids)) {
            throw new Exception("No students selected for assignment.");
        }
        
        // Validate section capacity
        $stmt = $pdo->prepare("SELECT max_students, 
                              (SELECT COUNT(*) FROM students WHERE grade_level = :grade_level AND section = :section) as current_students 
                              FROM class_sections WHERE grade_level = :grade_level AND section = :section AND status = 'active'");
        $stmt->execute([':grade_level' => $grade, ':section' => $section]);
        $section_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$section_info) {
            throw new Exception("Selected section does not exist or is inactive.");
        }
        
        $available_slots = $section_info['max_students'] - $section_info['current_students'];
        if (count($student_ids) > $available_slots) {
            throw new Exception("Cannot assign " . count($student_ids) . " students. Only $available_slots slots available in Grade $grade - $section.");
        }
        
        // Update students
        $placeholders = rtrim(str_repeat('?,', count($student_ids)), ',');
        $stmt = $pdo->prepare("UPDATE students SET grade_level = ?, section = ? WHERE id IN ($placeholders)");
        $stmt->execute(array_merge([$grade, $section], $student_ids));
        
        $pdo->commit();
        
        $_SESSION['success_message'] = "Successfully assigned " . count($student_ids) . " students to Grade $grade - $section";
        header("Location: " . $_SERVER['PHP_SELF'] . "?grade_level=$grade&section=" . urlencode($section));
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error assigning students: " . $e->getMessage();
    }
}

// Determine current page for sidebar highlighting
$current_page = basename($_SERVER['PHP_SELF']);
$page_title = 'Student Class Assignment';
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
        .status-active {
            background-color: #0ea5e9;
            color: white;
        }
        .status-inactive {
            background-color: #6b7280;
            color: white;
        }
        .status-transferred {
            background-color: #facc15;
            color: #1f2937;
        }
        .status-graduated {
            background-color: #0284c7;
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
                    <a href="#" class="flex items-center p-3 rounded-lg <?php echo in_array($current_page, ['add_student.php', 'view_students.php', 'student_search.php']) ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item" onclick="toggleSubmenu('students-submenu', this)">
                        <i class="fas fa-user-graduate w-5"></i>
                        <span class="ml-3 sidebar-text">Student Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="students-submenu" class="submenu pl-4 mt-1 <?php echo in_array($current_page, ['add_student.php', 'view_students.php', 'student_search.php']) ? 'open' : ''; ?>">
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
                    <a href="#" class="flex items-center p-3 rounded-lg <?php echo in_array($current_page, ['enrollment_reports.php', 'demographic_reports.php', 'transcript_requests.php']) ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item" onclick="toggleSubmenu('reports-submenu', this)">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="reports-submenu" class="submenu pl-4 mt-1 <?php echo in_array($current_page, ['enrollment_reports.php', 'demographic_reports.php', 'transcript_requests.php']) ? 'open' : ''; ?>">
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
                <button id="sidebar-toggle" class="md:hidden text-white mr-4 focus:outline-none" onclick="toggleSidebar()" aria-label="Toggle sidebar">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl font-bold">Registrar Dashboard</h1>
            </div>
            
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <button id="notification-btn" class="relative p-2 text-white hover:bg-primary-600 rounded-full focus:outline-none" onclick="toggleNotifications()" aria-label="Notifications" aria-expanded="false">
                        <i class="fas fa-bell"></i>
                        <span class="notification-dot">
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
                            <?php echo htmlspecialchars($registrar_id_display); ?>
                        </div>
                        <span class="hidden md:inline-block text-white">
                            Registrar
                        </span>
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
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Student Class Assignment</h1>
            
            <!-- Filters -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6">
                <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label for="grade_level" class="block text-sm font-medium text-gray-700 mb-1">Grade Level</label>
                        <select name="grade_level" id="grade_level" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                            <option value="">All Grades</option>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $grade_level == $i ? 'selected' : ''; ?>>
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
                            <?php foreach ($sections as $sec): ?>
                                <?php if ($grade_level && $sec['grade_level'] == $grade_level || !$grade_level): ?>
                                    <option value="<?php echo htmlspecialchars($sec['section']); ?>" 
                                            <?php echo $section === $sec['section'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sec['section']); ?> (<?php echo $sec['current_students']; ?>/<?php echo $sec['max_students']; ?>)
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
            
            <!-- Student List -->
            <form method="POST" id="assignmentForm">
                <div class="bg-white rounded-lg shadow-md overflow-hidden dashboard-card">
                    <div class="px-6 py-4 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                        <h2 class="text-lg font-medium text-gray-800">
                            <?php 
                            if ($grade_level && $section) {
                                echo "Grade $grade_level - $section";
                            } elseif ($grade_level) {
                                echo "Grade $grade_level - All Sections";
                            } else {
                                echo "Unassigned Students";
                            }
                            ?>
                            <span class="text-sm font-normal text-gray-500 ml-2">
                                (<?php echo count($students); ?> students)
                            </span>
                        </h2>
                        
                        <?php if (!empty($students)): ?>
                            <div class="flex items-center space-x-3">
                                <select name="assign_grade" id="assign_grade" required
                                        class="px-3 py-1 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                    <option value="">Select Grade</option>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?php echo $i; ?>">Grade <?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                                
                                <select name="assign_section" id="assign_section" required
                                        class="px-3 py-1 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500">
                                    <option value="">Select Section</option>
                                    <!-- Populated dynamically via JavaScript -->
                                </select>
                                
                                <button type="submit" name="assign_students" id="assignButton" disabled
                                        class="px-3 py-1 bg-primary-600 text-white text-sm font-medium rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 opacity-50 cursor-not-allowed" 
                                        aria-describedby="selected-count">
                                    <i class="fas fa-user-plus mr-1"></i> Assign
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (empty($students)): ?>
                        <div class="text-center py-12 text-gray-500">
                            <i class="fas fa-user-graduate text-4xl mb-3"></i>
                            <p>No students found matching your criteria.</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <input type="checkbox" id="selectAll" class="rounded text-primary-600 focus:ring-primary-500" aria-describedby="selected-count">
                                        </th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Assignment</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($students as $student): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <input type="checkbox" name="student_ids[]" value="<?php echo $student['id']; ?>" 
                                                       class="student-checkbox rounded text-primary-600 focus:ring-primary-500" 
                                                       aria-describedby="selected-count">
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <?php echo htmlspecialchars($student['student_id']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10 rounded-full bg-primary-100 flex items-center justify-center text-primary-600 font-medium">
                                                        <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>
                                                        </div>
                                                        <div class="text-sm text-gray-500">
                                                            <?php echo htmlspecialchars($student['email']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php 
                                                if (!empty($student['grade_level']) && !empty($student['section'])) {
                                                    echo "Grade " . htmlspecialchars($student['grade_level']) . " - " . htmlspecialchars($student['section']);
                                                } else {
                                                    echo '<span class="text-yellow-600">Unassigned</span>';
                                                }
                                                ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full status-<?php echo strtolower($student['status'] ?? 'active'); ?>">
                                                    <?php echo htmlspecialchars($student['status'] ?? 'Active'); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
            
            <!-- Bulk Actions -->
            <?php if (!empty($students)): ?>
                <div class="mt-4 flex justify-between items-center">
                    <div class="text-sm text-gray-500">
                        <span id="selected-count">0</span> students selected
                    </div>
                    <div class="space-x-2">
                        <button type="button" onclick="selectAllStudents()" 
                                class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            Select All
                        </button>
                        <button type="button" onclick="deselectAllStudents()" 
                                class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            Deselect All
                        </button>
                    </div>
                </div>
            <?php endif; ?>
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

        // Update selected count and assign button state
        function updateSelectedCount() {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            const assignButton = document.getElementById('assignButton');
            const gradeSelect = document.getElementById('assign_grade');
            const sectionSelect = document.getElementById('assign_section');
            
            document.getElementById('selected-count').textContent = checkboxes.length;
            
            if (checkboxes.length > 0 && gradeSelect.value && sectionSelect.value) {
                assignButton.disabled = false;
                assignButton.classList.remove('opacity-50', 'cursor-not-allowed');
            } else {
                assignButton.disabled = true;
                assignButton.classList.add('opacity-50', 'cursor-not-allowed');
            }
        }

        // Select all students
        function selectAllStudents() {
            document.querySelectorAll('.student-checkbox').forEach(checkbox => checkbox.checked = true);
            document.getElementById('selectAll').checked = true;
            updateSelectedCount();
        }

        // Deselect all students
        function deselectAllStudents() {
            document.querySelectorAll('.student-checkbox').forEach(checkbox => checkbox.checked = false);
            document.getElementById('selectAll').checked = false;
            updateSelectedCount();
        }

        // Update section dropdown based on grade selection
        function updateSections() {
            const gradeSelect = document.getElementById('assign_grade');
            const sectionSelect = document.getElementById('assign_section');
            const grade = gradeSelect.value;
            
            sectionSelect.innerHTML = '<option value="">Select Section</option>';
            sectionSelect.disabled = true;
            
            if (grade) {
                fetch(`get_sections.php?grade_level=${encodeURIComponent(grade)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            showToast(data.error, 'error');
                            return;
                        }
                        data.forEach(section => {
                            const option = document.createElement('option');
                            option.value = section.section;
                            option.textContent = `${section.section} (${section.current_students}/${section.max_students})`;
                            sectionSelect.appendChild(option);
                        });
                        sectionSelect.disabled = false;
                        updateSelectedCount();
                    })
                    .catch(error => {
                        showToast('Error fetching sections', 'error');
                        sectionSelect.disabled = true;
                    });
            }
            updateSelectedCount();
        }

        // Handle form submission with confirmation
        function handleFormSubmission(event) {
            const checkboxes = document.querySelectorAll('.student-checkbox:checked');
            if (checkboxes.length > 0) {
                if (!confirm(`Are you sure you want to assign ${checkboxes.length} student${checkboxes.length > 1 ? 's' : ''} to the selected class?`)) {
                    event.preventDefault();
                    return;
                }
                const assignButton = document.getElementById('assignButton');
                assignButton.disabled = true;
                assignButton.classList.add('opacity-50', 'cursor-not-allowed');
                assignButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Assigning...';
            }
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
            
            // Initialize select all checkbox
            document.getElementById('selectAll').addEventListener('change', function() {
                document.querySelectorAll('.student-checkbox').forEach(checkbox => checkbox.checked = this.checked);
                updateSelectedCount();
            });
            
            // Listen for checkbox and select changes
            document.querySelectorAll('.student-checkbox').forEach(checkbox => {
                checkbox.addEventListener('change', updateSelectedCount);
            });
            document.getElementById('assign_grade').addEventListener('change', updateSections);
            document.getElementById('assign_section').addEventListener('change', updateSelectedCount);
            
            // Initialize section dropdown
            updateSections();
            
            // Handle form submission
            document.getElementById('assignmentForm').addEventListener('submit', handleFormSubmission);
            
            // Keyboard accessibility
            document.addEventListener('keydown', function(event) {
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
                
                const assignButton = document.getElementById('assignButton');
                if (assignButton && (event.key === 'Enter' || event.key === ' ') && event.target === assignButton && !assignButton.disabled) {
                    event.preventDefault();
                    document.getElementById('assignmentForm').submit();
                }
            });
            
            // Handle clicks outside dropdowns
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
            });
        });
    </script>
</body>
</html>