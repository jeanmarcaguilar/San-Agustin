<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('Location: ../login.php');
    exit();
}

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');

// Initialize variables
$stats = [
    'pending_documents' => 0,
    'new_applications' => 0
];

// Get registrar info for header
$registrar = [];
$registrar_id_display = 'R' . $_SESSION['user_id'];
$initials = 'R' . substr($_SESSION['user_id'], -1); // Set initials to R + last digit of user_id
try {
    $stmt = $pdo->prepare("SELECT * FROM registrars WHERE user_id = :user_id");
    $stmt->execute([':user_id' => $_SESSION['user_id']]);
    $registrar = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log error if needed
    error_log("Error fetching registrar info: " . $e->getMessage());
}

// Get current school year
$current_year = date('Y');
$current_month = date('n');
$school_year = $current_month >= 6 ? "$current_year-" . ($current_year + 1) : ($current_year - 1) . "-$current_year";

// Get filter parameters
$selected_year = $_GET['school_year'] ?? $school_year;
$report_type = $_GET['report_type'] ?? 'age_distribution';
$grade_level = $_GET['grade_level'] ?? '';
$section = $_GET['section'] ?? '';

// Get school years for dropdown
$school_years = [];
for ($i = -2; $i <= 2; $i++) {
    $year = $current_year + $i;
    $school_years[] = "$year-" . ($year + 1);
}

// Get sections for dropdown
$sections = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT grade_level, section FROM class_sections WHERE status = 'active' ORDER BY grade_level, section");
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching sections: " . $e->getMessage();
}

// Generate report data
$report_data = [];
$report_title = '';
$total_students = 0;

try {
    $query_conditions = "WHERE s.status = 'Active' AND s.school_year = ?";
    $query_params = [$selected_year];
    
    if ($grade_level) {
        $query_conditions .= " AND s.grade_level = ?";
        $query_params[] = $grade_level;
    }
    
    if ($section) {
        $query_conditions .= " AND s.section = ?";
        $query_params[] = $section;
    }
    
    switch ($report_type) {
        case 'gender_distribution':
            $report_title = 'Gender Distribution';
            $query = "SELECT s.gender, COUNT(*) as count 
                     FROM students s
                     $query_conditions
                     GROUP BY s.gender 
                     ORDER BY s.gender";
            $stmt = $pdo->prepare($query);
            $stmt->execute($query_params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total_students = array_sum(array_column($report_data, 'count'));
            break;
            
        case 'age_distribution':
            $report_title = 'Age Distribution';
            // Calculate age based on birthdate
            $query = "SELECT 
                        FLOOR(DATEDIFF(CURRENT_DATE, s.birthdate) / 365) as age, 
                        COUNT(*) as count
                     FROM students s
                     $query_conditions
                     GROUP BY age
                     ORDER BY age";
            $stmt = $pdo->prepare($query);
            $stmt->execute($query_params);
            $age_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group ages into ranges
            $age_ranges = [
                '5-7' => 0,
                '8-10' => 0,
                '11-13' => 0,
                '14-16' => 0,
                '17+' => 0
            ];
            
            foreach ($age_data as $row) {
                $age = (int)$row['age'];
                if ($age >= 5 && $age <= 7) $age_ranges['5-7'] += $row['count'];
                elseif ($age >= 8 && $age <= 10) $age_ranges['8-10'] += $row['count'];
                elseif ($age >= 11 && $age <= 13) $age_ranges['11-13'] += $row['count'];
                elseif ($age >= 14 && $age <= 16) $age_ranges['14-16'] += $row['count'];
                else $age_ranges['17+'] += $row['count'];
                
                $total_students += $row['count'];
            }
            
            // Format for the report
            $report_data = [];
            foreach ($age_ranges as $range => $count) {
                if ($count > 0) {
                    $report_data[] = [
                        'age_range' => $range,
                        'count' => $count
                    ];
                }
            }
            break;
            
        case 'address_distribution':
            $report_title = 'Address Distribution';
            $query = "SELECT 
                        CASE 
                            WHEN s.address LIKE '%San Agustin%' THEN 'San Agustin'
                            WHEN s.address LIKE '%Poblacion%' THEN 'Poblacion'
                            WHEN s.address LIKE '%Barangay%' THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(s.address, 'Barangay', -1), ',', 1))
                            ELSE 'Other Areas'
                        END as location,
                        COUNT(*) as count
                     FROM students s
                     $query_conditions
                     GROUP BY location
                     ORDER BY count DESC";
            $stmt = $pdo->prepare($query);
            $stmt->execute($query_params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total_students = array_sum(array_column($report_data, 'count'));
            break;
            
        case 'guardian_info':
            $report_title = 'Guardian Information';
            $query = "SELECT 
                        s.guardian_relationship as relationship,
                        COUNT(*) as count
                     FROM students s
                     $query_conditions
                     GROUP BY s.guardian_relationship
                     ORDER BY count DESC";
            $stmt = $pdo->prepare($query);
            $stmt->execute($query_params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total_students = array_sum(array_column($report_data, 'count'));
            break;
            
        case 'ethnicity':
            $report_title = 'Ethnicity Distribution';
            // Assuming there's an ethnicity field in the students table
            $query = "SELECT 
                        COALESCE(s.ethnicity, 'Not Specified') as ethnicity,
                        COUNT(*) as count
                     FROM students s
                     $query_conditions
                     GROUP BY s.ethnicity
                     ORDER BY count DESC";
            $stmt = $pdo->prepare($query);
            $stmt->execute($query_params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total_students = array_sum(array_column($report_data, 'count'));
            break;
            
        case 'religion':
            $report_title = 'Religious Affiliation';
            // Assuming there's a religion field in the students table
            $query = "SELECT 
                        COALESCE(s.religion, 'Not Specified') as religion,
                        COUNT(*) as count
                     FROM students s
                     $query_conditions
                     GROUP BY s.religion
                     ORDER BY count DESC";
            $stmt = $pdo->prepare($query);
            $stmt->execute($query_params);
            $report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $total_students = array_sum(array_column($report_data, 'count'));
            break;
    }
    
} catch (PDOException $e) {
    $error = "Error generating report: " . $e->getMessage();
}

// Prepare data for charts
$chart_data = [
    'labels' => [],
    'datasets' => [
        [
            'label' => 'Number of Students',
            'data' => [],
            'backgroundColor' => [
                '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
                '#ec4899', '#14b8a6', '#f97316', '#06b6d4', '#a855f7'
            ],
            'borderWidth' => 1
        ]
    ]
];

switch ($report_type) {
    case 'gender_distribution':
        foreach ($report_data as $row) {
            $chart_data['labels'][] = $row['gender'] ?: 'Not Specified';
            $chart_data['datasets'][0]['data'][] = $row['count'];
        }
        $chart_type = 'pie';
        break;
        
    case 'age_distribution':
        foreach ($report_data as $row) {
            $chart_data['labels'][] = $row['age_range'] . ' years';
            $chart_data['datasets'][0]['data'][] = $row['count'];
        }
        $chart_type = 'bar';
        break;
        
    case 'address_distribution':
    case 'guardian_info':
    case 'ethnicity':
    case 'religion':
    default:
        foreach ($report_data as $row) {
            $key = $report_type === 'guardian_info' ? 'relationship' : 
                  ($report_type === 'age_distribution' ? 'age_range' : 
                  ($report_type === 'address_distribution' ? 'location' : 
                  ($report_type === 'ethnicity' ? 'ethnicity' : 'religion')));
            
            $label = $row[$key] ?: 'Not Specified';
            if ($report_type === 'guardian_info' && empty($label)) {
                $label = 'Not Specified';
            }
            
            $chart_data['labels'][] = $label;
            $chart_data['datasets'][0]['data'][] = $row['count'];
        }
        $chart_type = in_array($report_type, ['gender_distribution', 'guardian_info', 'ethnicity', 'religion']) ? 'pie' : 'bar';
        break;
}

// Determine current page
$current_page = basename($_SERVER['PHP_SELF']);
$page_title = 'Demographic Reports';
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
                    <a href="dashboard.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'dashboard.php' ? 'bg-primary-600 text-white shadow-md' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-home w-5"></i>
                        <span class="ml-3 sidebar-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('students-submenu', this)">
                        <i class="fas fa-user-graduate w-5"></i>
                        <span class="ml-3 sidebar-text">Student Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text transition-transform" id="students-submenu-icon"></i>
                    </a>
                    <div id="students-submenu" class="submenu pl-4 mt-1">
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
                    <a href="enrollment.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'enrollment.php' ? 'bg-primary-600 text-white shadow-md' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-clipboard-list w-5"></i>
                        <span class="ml-3 sidebar-text">Enrollment</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('sections-submenu', this)">
                        <i class="fas fa-chalkboard w-5"></i>
                        <span class="ml-3 sidebar-text">Class Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text transition-transform" id="sections-submenu-icon"></i>
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
                    <a href="attendance.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'attendance.php' ? 'bg-primary-600 text-white shadow-md' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-calendar-check w-5"></i>
                        <span class="ml-3 sidebar-text">Attendance</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('reports-submenu', this)">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text transition-transform" id="reports-submenu-icon"></i>
                    </a>
                    <div id="reports-submenu" class="submenu pl-4 mt-1 <?php echo in_array($current_page, ['enrollment_reports.php', 'demographic_reports.php']) ? 'open' : ''; ?>">
                        <a href="enrollment_reports.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'enrollment_reports.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-file-alt w-5"></i>
                            <span class="ml-3 sidebar-text">Enrollment Reports</span>
                        </a>
                        <a href="demographic_reports.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'demographic_reports.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-chart-pie w-5"></i>
                            <span class="ml-3 sidebar-text">Demographic Reports</span>
                        </a>
                        </div>
                </li>
                <li>
                    <a href="documents.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'documents.php' ? 'bg-primary-600 text-white shadow-md' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
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
                    <button id="notification-btn" class="relative p-2 text-white hover:bg-primary-600 rounded-full focus:outline-none" onclick="toggleNotifications()">
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
                    <button id="user-menu-button" class="flex items-center space-x-2 focus:outline-none" onclick="toggleUserMenu()">
                        <div class="h-8 w-8 rounded-full bg-primary-600 flex items-center justify-center text-white font-medium">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <span class="hidden md:inline-block text-white">Registrar</span>
                        <i class="fas fa-chevron-down text-xs text-white transition-transform" id="user-menu-icon"></i>
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

        <div class="container mx-auto px-4 py-8">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">Demographic Reports</h1>
                    <p class="text-gray-600">View and analyze student demographic data</p>
                </div>
                <div class="mt-4 md:mt-0">
                    <button onclick="window.print()" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        <i class="fas fa-print mr-2"></i> Print Report
                    </button>
                    <button onclick="exportToExcel()" class="ml-2 bg-white border border-green-600 text-green-600 px-4 py-2 rounded-md hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                        <i class="fas fa-file-excel mr-2"></i> Export to Excel
                    </button>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="bg-white rounded-lg shadow-md p-4 mb-6">
                <form action="" method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label for="school_year" class="block text-sm font-medium text-gray-700 mb-1">School Year</label>
                        <select id="school_year" name="school_year" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <?php foreach ($school_years as $year): ?>
                                <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="report_type" class="block text-sm font-medium text-gray-700 mb-1">Report Type</label>
                        <select id="report_type" name="report_type" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="age_distribution" <?= $report_type == 'age_distribution' ? 'selected' : '' ?>>Age Distribution</option>
                            <option value="gender_distribution" <?= $report_type == 'gender_distribution' ? 'selected' : '' ?>>Gender Distribution</option>
                            <option value="address_distribution" <?= $report_type == 'address_distribution' ? 'selected' : '' ?>>Address Distribution</option>
                            <option value="guardian_info" <?= $report_type == 'guardian_info' ? 'selected' : '' ?>>Guardian Information</option>
                            <option value="ethnicity" <?= $report_type == 'ethnicity' ? 'selected' : '' ?>>Ethnicity</option>
                            <option value="religion" <?= $report_type == 'religion' ? 'selected' : '' ?>>Religious Affiliation</option>
                        </select>
                    </div>
                    
                    <div id="gradeLevelFilter">
                        <label for="grade_level" class="block text-sm font-medium text-gray-700 mb-1">Grade Level</label>
                        <select id="grade_level" name="grade_level" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                            <option value="">All Grades</option>
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <option value="<?= $i ?>" <?= $grade_level == $i ? 'selected' : '' ?>>Grade <?= $i ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    
                    <div id="sectionFilter">
                        <label for="section" class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                        <select id="section" name="section" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500" <?= !$grade_level ? 'disabled' : '' ?>>
                            <option value="">All Sections</option>
                            <?php if ($grade_level): ?>
                                <?php foreach ($sections as $sec): ?>
                                    <?php if ($sec['grade_level'] == $grade_level): ?>
                                        <option value="<?= $sec['section'] ?>" <?= $section == $sec['section'] ? 'selected' : '' ?>>
                                            <?= $sec['section'] ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    
                    <div class="md:col-span-4 flex justify-end">
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                    </div>
                </form>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>
            
            <!-- Report Content -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                <div class="border-b border-gray-200 pb-4 mb-6">
                    <h2 class="text-xl font-semibold text-gray-800"><?= $report_title ?></h2>
                    <p class="text-sm text-gray-500">School Year: <?= $selected_year ?></p>
                    <?php if ($grade_level): ?>
                        <p class="text-sm text-gray-500">Grade: <?= $grade_level ?><?= $section ? " - $section" : '' ?></p>
                    <?php endif; ?>
                    <p class="text-sm text-gray-500">Generated on: <?= date('F j, Y h:i A') ?></p>
                </div>
                
                <?php if (empty($report_data)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-chart-pie text-4xl text-gray-300 mb-3"></i>
                        <p class="text-gray-500">No data available for the selected criteria.</p>
                    </div>
                <?php else: ?>
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                        <!-- Chart -->
                        <div class="lg:col-span-2">
                            <div class="chart-container">
                                <canvas id="reportChart"></canvas>
                            </div>
                        </div>
                        
                        <!-- Data Table -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                            <?= $report_type === 'gender_distribution' ? 'Gender' : 
                                               ($report_type === 'age_distribution' ? 'Age Range' : 
                                               ($report_type === 'address_distribution' ? 'Location' : 
                                               ($report_type === 'guardian_info' ? 'Relationship' : 
                                               ($report_type === 'ethnicity' ? 'Ethnicity' : 'Religion')))) ?>
                                        </th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Students</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Percentage</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($report_data as $row): 
                                        $label = '';
                                        if ($report_type === 'gender_distribution') {
                                            $label = $row['gender'] ?: 'Not Specified';
                                        } elseif ($report_type === 'age_distribution') {
                                            $label = $row['age_range'] . ' years';
                                        } elseif ($report_type === 'address_distribution') {
                                            $label = $row['location'] ?: 'Not Specified';
                                        } elseif ($report_type === 'guardian_info') {
                                            $label = $row['relationship'] ? ucfirst($row['relationship']) : 'Not Specified';
                                        } elseif ($report_type === 'ethnicity') {
                                            $label = $row['ethnicity'] ?: 'Not Specified';
                                        } else { // religion
                                            $label = $row['religion'] ?: 'Not Specified';
                                        }
                                        
                                        $count = $row['count'];
                                        $percentage = $total_students > 0 ? ($count / $total_students) * 100 : 0;
                                    ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                <?= htmlspecialchars($label) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">
                                                <?= number_format($count) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-right">
                                                <?= number_format($percentage, 1) ?>%
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (count($report_data) > 1): ?>
                                        <tr class="bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900">
                                                Total
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-right">
                                                <?= number_format($total_students) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 text-right">
                                                100.0%
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle submenu
        function toggleSubmenu(submenuId, element) {
            const submenu = document.getElementById(submenuId);
            const icon = element.querySelector('.fa-chevron-down');
            const isOpen = submenu.classList.contains('open');

            // Close all other submenus
            document.querySelectorAll('.submenu').forEach((otherSubmenu) => {
                if (otherSubmenu !== submenu) {
                    otherSubmenu.classList.remove('open');
                    const otherIcon = document.querySelector(`#${otherSubmenu.id}-icon`);
                    if (otherIcon) otherIcon.classList.remove('rotate-90');
                }
            });

            // Toggle current submenu
            submenu.classList.toggle('open');
            icon.classList.toggle('rotate-90');
        }

        // Toggle user menu
        function toggleUserMenu() {
            const userMenu = document.getElementById('user-menu');
            const userMenuIcon = document.getElementById('user-menu-icon');
            userMenu.classList.toggle('hidden');
            userMenuIcon.classList.toggle('rotate-90');
        }

        // Toggle sidebar collapse
        function toggleSidebarCollapse() {
            const sidebar = document.getElementById('sidebar');
            const collapseIcon = document.getElementById('collapse-icon');
            sidebar.classList.toggle('collapsed');
            collapseIcon.classList.toggle('fa-chevron-left');
            collapseIcon.classList.toggle('fa-chevron-right');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        }

        // Toggle sidebar on mobile
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            sidebar.classList.toggle('sidebar-open');
            overlay.classList.toggle('overlay-open');
        }

        // Close sidebar on mobile
        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            sidebar.classList.remove('sidebar-open');
            overlay.classList.remove('overlay-open');
        }

        // Toggle notifications
        function toggleNotifications() {
            const notificationPanel = document.getElementById('notification-panel');
            notificationPanel.classList.toggle('open');
        }

        // Close menus when clicking outside
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('user-menu');
            const userMenuButton = document.getElementById('user-menu-button');
            const notificationPanel = document.getElementById('notification-panel');
            const notificationButton = document.getElementById('notification-btn');

            if (!userMenuButton.contains(event.target) && !userMenu.contains(event.target)) {
                userMenu.classList.add('hidden');
                document.getElementById('user-menu-icon').classList.remove('rotate-90');
            }

            if (!notificationButton.contains(event.target) && !notificationPanel.contains(event.target)) {
                notificationPanel.classList.remove('open');
            }
        });

        // Update sections dropdown when grade level changes
        document.getElementById('grade_level').addEventListener('change', function() {
            const gradeLevel = this.value;
            const sectionSelect = document.getElementById('section');
            
            // Clear existing options except the first one
            while (sectionSelect.options.length > 1) {
                sectionSelect.remove(1);
            }
            
            // Enable/disable section select based on grade level selection
            sectionSelect.disabled = !gradeLevel;
            
            if (gradeLevel) {
                // Add sections for the selected grade level
                <?php 
                $sections_by_grade = [];
                foreach ($sections as $sec) {
                    $sections_by_grade[$sec['grade_level']][] = $sec['section'];
                }
                ?>
                
                const sectionsByGrade = <?= json_encode($sections_by_grade) ?>;
                const sections = sectionsByGrade[gradeLevel] || [];
                
                sections.forEach(function(section) {
                    const option = document.createElement('option');
                    option.value = section;
                    option.textContent = section;
                    sectionSelect.appendChild(option);
                });
            }
        });
        
        // Initialize chart and sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!empty($report_data)): ?>
                const ctx = document.getElementById('reportChart').getContext('2d');
                const chart = new Chart(ctx, {
                    type: '<?= $chart_type ?>',
                    data: {
                        labels: <?= json_encode($chart_data['labels']) ?>,
                        datasets: [{
                            label: 'Number of Students',
                            data: <?= json_encode($chart_data['datasets'][0]['data']) ?>,
                            backgroundColor: <?= json_encode($chart_data['datasets'][0]['backgroundColor']) ?>,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: {
                                    boxWidth: 12,
                                    padding: 20
                                }
                            },
                            title: {
                                display: true,
                                text: '<?= addslashes($report_title) ?>',
                                font: { size: 16 },
                                padding: { bottom: 20 }
                            },
                            tooltip: { 
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `${label}: ${value} (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        <?php if ($chart_type === 'bar'): ?>
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { precision: 0 }
                            }
                        }
                        <?php endif; ?>
                    }
                });
            <?php endif; ?>
            
            // Initialize sidebar state from localStorage
            if (localStorage.getItem('sidebarCollapsed') === 'true') {
                document.getElementById('sidebar').classList.add('collapsed');
                document.getElementById('collapse-icon').classList.remove('fa-chevron-left');
                document.getElementById('collapse-icon').classList.add('fa-chevron-right');
            }

            // Set initial submenu state for Reports & Records
            <?php if (in_array($current_page, ['enrollment_reports.php', 'demographic_reports.php'])): ?>
                const reportsSubmenu = document.getElementById('reports-submenu');
                const reportsIcon = document.getElementById('reports-submenu-icon');
                reportsSubmenu.classList.add('open');
                reportsIcon.classList.add('rotate-90');
            <?php endif; ?>
            
            // Close sidebar when clicking on a nav item on mobile
            const navItems = document.querySelectorAll('.nav-item');
            navItems.forEach(item => {
                item.addEventListener('click', function() {
                    if (window.innerWidth < 768) {
                        closeSidebar();
                    }
                });
            });
        });
        
        // Export to Excel
        function exportToExcel() {
            // Create a temporary table with the report data
            let html = '<table>';
            
            // Add headers
            html += `
                <tr>
                    <th><?= $report_type === 'gender_distribution' ? 'Gender' : 
                           ($report_type === 'age_distribution' ? 'Age Range' : 
                           ($report_type === 'address_distribution' ? 'Location' : 
                           ($report_type === 'guardian_info' ? 'Relationship' : 
                           ($report_type === 'ethnicity' ? 'Ethnicity' : 'Religion')))) ?></th>
                    <th>Number of Students</th>
                    <th>Percentage</th>
                </tr>
            `;
            
            // Add data rows
            <?php foreach ($report_data as $row): 
                $label = '';
                if ($report_type === 'gender_distribution') {
                    $label = $row['gender'] ?: 'Not Specified';
                } elseif ($report_type === 'age_distribution') {
                    $label = $row['age_range'] . ' years';
                } elseif ($report_type === 'address_distribution') {
                    $label = $row['location'] ?: 'Not Specified';
                } elseif ($report_type === 'guardian_info') {
                    $label = $row['relationship'] ? ucfirst($row['relationship']) : 'Not Specified';
                } elseif ($report_type === 'ethnicity') {
                    $label = $row['ethnicity'] ?: 'Not Specified';
                } else { // religion
                    $label = $row['religion'] ?: 'Not Specified';
                }
                
                $count = $row['count'];
                $percentage = $total_students > 0 ? ($count / $total_students) * 100 : 0;
            ?>
                html += `
                    <tr>
                        <td><?= addslashes($label) ?></td>
                        <td><?= $count ?></td>
                        <td><?= number_format($percentage, 1) ?>%</td>
                    </tr>
                `;
            <?php endforeach; ?>
            
            // Add total row if there are multiple rows
            <?php if (count($report_data) > 1): ?>
                html += `
                    <tr>
                        <td><strong>Total</strong></td>
                        <td><strong><?= $total_students ?></strong></td>
                        <td><strong>100.0%</strong></td>
                    </tr>
                `;
            <?php endif; ?>
            
            html += '</table>';
            
            // Create a Blob with the HTML content
            const blob = new Blob([`<html><body>${html}</body></html>`], { type: 'application/vnd.ms-excel' });
            
            // Create a download link and trigger it
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            
            // Generate a filename based on the report type and date
            const reportName = '<?= strtolower(str_replace(' ', '_', $report_title)) ?>';
            const dateStr = new Date().toISOString().split('T')[0];
            a.download = `demographic_${reportName}_${dateStr}.xls`;
            
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
    </script>
</body>
</html>