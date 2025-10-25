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
$registrar = [];
$initials = 'R';
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
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM students WHERE status = 'Active'");
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

// Get current date and school year
$current_date = date('Y-m-d');
$current_year = date('Y');
$school_year = "$current_year-" . ($current_year + 1);

// Get filter parameters
$selected_date = $_GET['date'] ?? $current_date;
$selected_grade = $_GET['grade_level'] ?? '';
$selected_section = $_GET['section'] ?? '';
$error = '';

// Get sections for dropdown
$sections = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT grade_level, section, 
                         (SELECT COUNT(*) FROM students WHERE grade_level = cs.grade_level AND section = cs.section) as current_students,
                         max_students 
                         FROM class_sections cs 
                         WHERE status = 'active' 
                         ORDER BY grade_level, section");
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching sections: " . $e->getMessage();
    error_log($error);
}

// Get attendance records
$attendance_records = [];
$class_info = [];

try {
    if ($selected_grade && $selected_section) {
        $stmt = $pdo->prepare("SELECT id, max_students FROM class_sections 
                              WHERE grade_level = :grade_level AND section = :section AND status = 'active'");
        $stmt->execute([':grade_level' => $selected_grade, ':section' => $selected_section]);
        $class_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($class_info) {
            $query = "SELECT s.id, s.student_id, 
                      COALESCE(s.first_name, '') as first_name, 
                      COALESCE(s.last_name, '') as last_name, 
                      COALESCE(s.middle_name, '') as middle_name,
                      COALESCE(a.status, 'present') as attendance_status, 
                      COALESCE(a.remarks, '') as remarks
                      FROM students s
                      LEFT JOIN attendance a ON s.id = a.student_id 
                          AND a.date = :date 
                          AND a.class_section_id = :class_section_id
                      WHERE s.grade_level = :grade_level 
                          AND s.section = :section 
                          AND s.status = 'Active'
                      ORDER BY s.last_name, s.first_name";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                ':date' => $selected_date,
                ':class_section_id' => $class_info['id'],
                ':grade_level' => $selected_grade,
                ':section' => $selected_section
            ]);
            $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    $error = "Error fetching attendance records: " . $e->getMessage();
    error_log($error);
}
$registrar_id_display = 'R' . $_SESSION['user_id'];
$initials = 'R' . substr($_SESSION['user_id'], -1); // Get last digit for initials
// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    try {
        $pdo->beginTransaction();
        
        foreach ($_POST['attendance'] as $student_id => $data) {
            $status = $data['status'];
            $remarks = trim($data['remarks'] ?? '');
            
            if (!in_array($status, ['present', 'absent', 'late', 'excused'])) {
                throw new Exception("Invalid attendance status for student ID: $student_id");
            }
            
            $stmt = $pdo->prepare("SELECT id FROM attendance 
                                  WHERE student_id = :student_id 
                                  AND date = :date 
                                  AND class_section_id = :class_section_id");
            $stmt->execute([
                ':student_id' => $student_id,
                ':date' => $selected_date,
                ':class_section_id' => $class_info['id']
            ]);
            $exists = $stmt->fetch();
            
            if ($exists) {
                $stmt = $pdo->prepare("UPDATE attendance 
                                      SET status = :status, remarks = :remarks, updated_at = NOW() 
                                      WHERE id = :id");
                $stmt->execute([
                    ':status' => $status,
                    ':remarks' => $remarks,
                    ':id' => $exists['id']
                ]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO attendance 
                                      (student_id, date, class_section_id, status, remarks, recorded_by, created_at, updated_at) 
                                      VALUES (:student_id, :date, :class_section_id, :status, :remarks, :recorded_by, NOW(), NOW())");
                $stmt->execute([
                    ':student_id' => $student_id,
                    ':date' => $selected_date,
                    ':class_section_id' => $class_info['id'],
                    ':status' => $status,
                    ':remarks' => $remarks,
                    ':recorded_by' => $_SESSION['user_id']
                ]);
            }
        }
        
        $pdo->commit();
        $_SESSION['success_message'] = "Attendance records saved successfully!";
        header("Location: attendance.php?date=$selected_date&grade_level=$selected_grade&section=$selected_section");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error saving attendance: " . $e->getMessage();
        error_log($error);
    }
}

// Determine current page for sidebar highlighting
$current_page = basename($_SERVER['PHP_SELF']);
$page_title = 'Student Attendance';
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
        .status-present {
            background-color: #0ea5e9;
            color: white;
        }
        .status-absent {
            background-color: #ef4444;
            color: white;
        }
        .status-late {
            background-color: #facc15;
            color: #1f2937;
        }
        .status-excused {
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
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('sections-submenu', this)">
                        <i class="fas fa-chalkboard w-5"></i>
                        <span class="ml-3 sidebar-text">Class Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="sections-submenu" class="submenu pl-4 mt-1">
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
                        <span>Registrar </span>
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
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Student Attendance</h1>
            
            <!-- Filters -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6 dashboard-card">
                <form method="GET" id="filterForm" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label for="date" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                        <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>" 
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                               aria-describedby="date-help">
                        <p id="date-help" class="text-xs text-gray-500 mt-1">Select the date for attendance</p>
                    </div>
                    
                    <div>
                        <label for="grade_level" class="block text-sm font-medium text-gray-700 mb-1">Grade Level</label>
                        <select id="grade_level" name="grade_level" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                                aria-describedby="grade_level-help">
                            <option value="">Select Grade</option>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo $selected_grade == $i ? 'selected' : ''; ?>>
                                    Grade <?php echo $i; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <p id="grade_level-help" class="text-xs text-gray-500 mt-1">Select a grade level to view sections</p>
                    </div>
                    
                    <div>
                        <label for="section" class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                        <select id="section" name="section" 
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500"
                                <?php echo !$selected_grade ? 'disabled' : ''; ?>
                                aria-describedby="section-help">
                            <option value="">Select Section</option>
                            <?php if ($selected_grade): ?>
                                <?php foreach ($sections as $section): ?>
                                    <?php if ($section['grade_level'] == $selected_grade): ?>
                                        <option value="<?php echo htmlspecialchars($section['section']); ?>" 
                                                <?php echo $selected_section === $section['section'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($section['section']); ?> (<?php echo $section['current_students']; ?>/<?php echo $section['max_students']; ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p id="section-help" class="text-xs text-gray-500 mt-1">Select a section to view students</p>
                    </div>
                    
                    <div class="flex items-end space-x-2">
                        <button type="submit" 
                                class="bg-primary-600 text-white px-4 py-2 rounded-md hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 dashboard-card"
                                aria-label="Apply filters">
                            <i class="fas fa-search mr-2"></i> View
                        </button>
                        <button type="button" onclick="document.getElementById('filterForm').reset(); document.getElementById('section').disabled = true; document.getElementById('filterForm').submit();" 
                                class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 dashboard-card"
                                aria-label="Clear filters">
                            Clear
                        </button>
                    </div>
                </form>
            </div>
            
            <?php if ($selected_grade && $selected_section && $class_info): ?>
                <form action="" method="post" id="attendanceForm">
                    <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6 dashboard-card">
                        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                            <div class="flex justify-between items-center">
                                <h2 class="text-lg font-medium text-gray-800">
                                    Grade <?php echo htmlspecialchars($selected_grade); ?> - <?php echo htmlspecialchars($selected_section); ?>
                                    <span class="text-sm font-normal text-gray-500 ml-2">
                                        (<?php echo date('F j, Y', strtotime($selected_date)); ?>, <?php echo date('l', strtotime($selected_date)); ?>)
                                    </span>
                                </h2>
                                <div>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                        <?php echo count($attendance_records); ?> Students
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($error): ?>
                            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 px-4 py-3 mb-4">
                                <?php echo htmlspecialchars($error); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student Name</th>
                                        <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remarks</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php if (empty($attendance_records)): ?>
                                        <tr>
                                            <td colspan="4" class="px-6 py-12 text-center text-gray-500">
                                                <i class="fas fa-users text-4xl mb-3"></i>
                                                <p>No students found in this class.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($attendance_records as $record): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($record['student_id']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($record['last_name']); ?>, 
                                                        <?php echo htmlspecialchars($record['first_name']); ?>
                                                        <?php echo $record['middle_name'] ? ' ' . htmlspecialchars(mb_substr($record['middle_name'], 0, 1) . '.') : ''; ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                                    <select name="attendance[<?php echo $record['id']; ?>][status]" 
                                                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm status-select"
                                                            aria-label="Attendance status for <?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?>">
                                                        <option value="present" <?php echo $record['attendance_status'] == 'present' ? 'selected' : ''; ?>>Present</option>
                                                        <option value="absent" <?php echo $record['attendance_status'] == 'absent' ? 'selected' : ''; ?>>Absent</option>
                                                        <option value="late" <?php echo $record['attendance_status'] == 'late' ? 'selected' : ''; ?>>Late</option>
                                                        <option value="excused" <?php echo $record['attendance_status'] == 'excused' ? 'selected' : ''; ?>>Excused</option>
                                                    </select>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <input type="text" name="attendance[<?php echo $record['id']; ?>][remarks]" 
                                                           value="<?php echo htmlspecialchars($record['remarks']); ?>"
                                                           placeholder="Optional remarks"
                                                           class="w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 text-sm"
                                                           aria-label="Remarks for <?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?>">
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <?php if (!empty($attendance_records)): ?>
                            <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 text-right">
                                <button type="submit" name="save_attendance" id="saveAttendanceBtn"
                                        class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 dashboard-card"
                                        aria-label="Save attendance records">
                                    <i class="fas fa-save mr-2"></i> Save Attendance
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
                
                <!-- Attendance Summary -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-white rounded-lg shadow p-4 dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 text-green-600 mr-4">
                                <i class="fas fa-user-check text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Present</p>
                                <p class="text-2xl font-semibold text-gray-800" id="presentCount">0</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-4 dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-red-100 text-red-600 mr-4">
                                <i class="fas fa-user-times text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Absent</p>
                                <p class="text-2xl font-semibold text-gray-800" id="absentCount">0</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-4 dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-yellow-100 text-yellow-600 mr-4">
                                <i class="fas fa-clock text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Late</p>
                                <p class="text-2xl font-semibold text-gray-800" id="lateCount">0</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white rounded-lg shadow p-4 dashboard-card">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 text-blue-600 mr-4">
                                <i class="fas fa-user-clock text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-500">Excused</p>
                                <p class="text-2xl font-semibold text-gray-800" id="excusedCount">0</p>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($selected_grade || $selected_section): ?>
                <div class="bg-white rounded-lg shadow-md p-6 text-center dashboard-card">
                    <i class="fas fa-info-circle text-4xl text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">No class information found</h3>
                    <p class="text-gray-500">The selected grade and section combination does not exist or is not active.</p>
                </div>
            <?php else: ?>
                <div class="bg-white rounded-lg shadow-md p-6 text-center dashboard-card">
                    <i class="fas fa-chalkboard-teacher text-4xl text-gray-400 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Select a class to view attendance</h3>
                    <p class="text-gray-500">Please select a grade level and section to view and record attendance.</p>
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
                localStorage.setItem(submenuId, submenu.classList.contains('open'));
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

        // Update attendance summary
        function updateSummary() {
            const statuses = {
                'present': 0,
                'absent': 0,
                'late': 0,
                'excused': 0
            };
            
            document.querySelectorAll('.status-select').forEach(select => {
                if (select.value) {
                    statuses[select.value]++;
                }
            });
            
            document.getElementById('presentCount').textContent = statuses['present'];
            document.getElementById('absentCount').textContent = statuses['absent'];
            document.getElementById('lateCount').textContent = statuses['late'];
            document.getElementById('excusedCount').textContent = statuses['excused'];
        }

        // Update section dropdown based on grade selection
        function updateSections() {
            const gradeSelect = document.getElementById('grade_level');
            const sectionSelect = document.getElementById('section');
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
                    })
                    .catch(error => {
                        showToast('Error fetching sections', 'error');
                        sectionSelect.disabled = true;
                    });
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
            
            // Initialize submenu states
            ['students-submenu', 'sections-submenu', 'reports-submenu'].forEach(submenuId => {
                if (localStorage.getItem(submenuId) === 'true') {
                    const submenu = document.getElementById(submenuId);
                    const parent = submenu?.parentElement.querySelector('.fa-chevron-down');
                    if (submenu && parent) {
                        submenu.classList.add('open');
                        parent.classList.add('rotate-90');
                    }
                }
            });
            
            // Show toast messages
            <?php if (isset($_SESSION['success_message'])): ?>
                showToast('<?php echo addslashes($_SESSION['success_message']); ?>', 'success');
                <?php unset($_SESSION['success_message']); ?>
            <?php elseif ($error): ?>
                showToast('<?php echo addslashes($error); ?>', 'error');
            <?php else: ?>
                showToast('Welcome to the Attendance Management page!', 'success');
            <?php endif; ?>
            
            // Close sidebar on nav item click (mobile)
            document.querySelectorAll('.nav-item').forEach(item => {
                item.addEventListener('click', () => {
                    if (window.innerWidth < 768) closeSidebar();
                });
            });
            
            // Update sections on grade change
            document.getElementById('grade_level').addEventListener('change', updateSections);
            
            // Update summary on status change
            document.querySelectorAll('.status-select').forEach(select => {
                select.addEventListener('change', updateSummary);
            });
            updateSummary();
            
            // Form submission
            document.getElementById('attendanceForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                const saveButton = document.getElementById('saveAttendanceBtn');
                if (saveButton) {
                    saveButton.disabled = true;
                    saveButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Saving...';
                }
                
                const formData = new FormData(this);
                fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (saveButton) {
                        saveButton.disabled = false;
                        saveButton.innerHTML = '<i class="fas fa-save mr-2"></i> Save Attendance';
                    }
                    if (data.success) {
                        showToast(data.message || 'Attendance saved successfully', 'success');
                        setTimeout(() => window.location.reload(), 1000);
                    } else {
                        showToast(data.message || 'Failed to save attendance', 'error');
                    }
                })
                .catch(error => {
                    if (saveButton) {
                        saveButton.disabled = false;
                        saveButton.innerHTML = '<i class="fas fa-save mr-2"></i> Save Attendance';
                    }
                    console.error('Error:', error);
                    showToast('An error occurred while saving attendance', 'error');
                });
            });
            
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
                
                const saveButton = document.getElementById('saveAttendanceBtn');
                if (saveButton && (event.key === 'Enter' || event.key === ' ') && event.target === saveButton && !saveButton.disabled) {
                    event.preventDefault();
                    document.getElementById('attendanceForm').submit();
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