<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Initialize database connection
$database = new Database();
$conn = $database->getConnection('student');

$student_id = $_SESSION['user_id'];
$announcements = [];
$error = '';

try {
    // Get student information
    $stmt = $conn->prepare("SELECT * FROM students WHERE user_id = :user_id");
    $stmt->bindParam(':user_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception("Student record not found");
    }
    
    // Initialize announcements array
    $announcements = [];
    
    // Get active school announcements from student database
    $query = "SELECT a.*, 
                     (SELECT COUNT(*) FROM announcement_views av 
                      WHERE av.announcement_id = a.id AND av.student_id = :student_id) as is_read,
                     'school' as source,
                     'School Administration' as posted_by
              FROM announcements a 
              WHERE a.is_active = 1 
              AND (a.end_date IS NULL OR a.end_date >= NOW())
              ORDER BY 
                CASE a.priority 
                    WHEN 'high' THEN 1 
                    WHEN 'medium' THEN 2 
                    ELSE 3 
                END, 
                a.start_date DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $school_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add school announcements to the main array
    $announcements = array_merge($announcements, $school_announcements);
    
    // Get teacher announcements from teacher database
    try {
        $teacher_conn = $database->getConnection('teacher');
        $grade_level = $student['grade_level'];
        $section = $student['section'];
        
        $query = "SELECT a.*, 
                         t.first_name as teacher_first_name,
                         t.last_name as teacher_last_name,
                         CONCAT(t.first_name, ' ', t.last_name) as posted_by,
                         (SELECT COUNT(*) FROM announcement_views av 
                          WHERE av.announcement_id = a.id 
                          AND av.user_id = :student_id 
                          AND av.user_type = 'student') as is_read,
                         'teacher' as source,
                         a.content as description
                  FROM announcements a
                  LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
                  WHERE a.status = 'published'
                  AND (a.end_date IS NULL OR a.end_date >= CURDATE())
                  AND (
                      a.target_audience = 'all'
                      OR (a.target_audience = 'specific_grade' AND a.target_grade = :grade_level)
                      OR (a.target_audience = 'specific_section' AND a.target_grade = :grade_level AND a.target_section = :section)
                      OR (a.target_audience = 'specific_class' AND a.target_class_id IN (
                          SELECT c.id FROM classes c 
                          WHERE c.grade_level = :grade_level AND c.section = :section
                      ))
                  )
                  ORDER BY 
                    a.is_pinned DESC,
                    a.start_date DESC
                  LIMIT 50";
        
        $stmt = $teacher_conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
        $stmt->bindParam(':grade_level', $grade_level, PDO::PARAM_INT);
        $stmt->bindParam(':section', $section, PDO::PARAM_STR);
        $stmt->execute();
        $teacher_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format teacher announcements
        foreach ($teacher_announcements as &$announcement) {
            // Set priority based on pinned status
            if ($announcement['is_pinned']) {
                $announcement['priority'] = 'high';
            } else {
                $announcement['priority'] = 'medium';
            }
        }
        
        // Add teacher announcements to the main array
        $announcements = array_merge($announcements, $teacher_announcements);
        
    } catch (Exception $e) {
        error_log("Error fetching teacher announcements: " . $e->getMessage());
        // Continue without teacher announcements if there's an error
    }
    
    // Sort all announcements by priority and date
    usort($announcements, function($a, $b) {
        $priority_order = ['high' => 1, 'medium' => 2, 'low' => 3];
        $a_priority = $priority_order[$a['priority']] ?? 2;
        $b_priority = $priority_order[$b['priority']] ?? 2;
        
        if ($a_priority != $b_priority) {
            return $a_priority - $b_priority;
        }
        
        // If same priority, sort by date (newest first)
        return strtotime($b['start_date']) - strtotime($a['start_date']);
    });
    
    // Mark announcement as read when viewed
    if (isset($_GET['view']) && is_numeric($_GET['view'])) {
        $announcement_id = $_GET['view'];
        $current_announcement = null;
        
        // Find the announcement in our merged array
        foreach ($announcements as $announcement) {
            if ($announcement['id'] == $announcement_id) {
                $current_announcement = $announcement;
                break;
            }
        }
        
        if ($current_announcement) {
            $source = $current_announcement['source'];
            
            // Mark as read in the appropriate database
            if ($source === 'school') {
                // Check if view already exists
                $stmt = $conn->prepare("SELECT id FROM announcement_views 
                                       WHERE announcement_id = :announcement_id AND student_id = :student_id");
                $stmt->bindParam(':announcement_id', $announcement_id, PDO::PARAM_INT);
                $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
                $stmt->execute();
                
                if ($stmt->rowCount() === 0) {
                    // Insert new view record
                    $stmt = $conn->prepare("INSERT INTO announcement_views 
                                          (announcement_id, student_id, is_read, read_at) 
                                          VALUES (:announcement_id, :student_id, 1, NOW())");
                    $stmt->bindParam(':announcement_id', $announcement_id, PDO::PARAM_INT);
                    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
                    $stmt->execute();
                } else {
                    // Update existing view record
                    $stmt = $conn->prepare("UPDATE announcement_views 
                                          SET is_read = 1, read_at = NOW() 
                                          WHERE announcement_id = :announcement_id 
                                          AND student_id = :student_id");
                    $stmt->bindParam(':announcement_id', $announcement_id, PDO::PARAM_INT);
                    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
                    $stmt->execute();
                }
            } elseif ($source === 'teacher') {
                // Mark as read in teacher database
                try {
                    $teacher_conn = $database->getConnection('teacher');
                    
                    // Check if view already exists
                    $stmt = $teacher_conn->prepare("SELECT id FROM announcement_views 
                                                   WHERE announcement_id = :announcement_id 
                                                   AND user_id = :student_id 
                                                   AND user_type = 'student'");
                    $stmt->bindParam(':announcement_id', $announcement_id, PDO::PARAM_INT);
                    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    if ($stmt->rowCount() === 0) {
                        // Insert new view record
                        $stmt = $teacher_conn->prepare("INSERT INTO announcement_views 
                                                      (announcement_id, user_id, user_type, viewed_at) 
                                                      VALUES (:announcement_id, :student_id, 'student', NOW())");
                        $stmt->bindParam(':announcement_id', $announcement_id, PDO::PARAM_INT);
                        $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
                        $stmt->execute();
                    }
                } catch (Exception $e) {
                    error_log("Error marking teacher announcement as read: " . $e->getMessage());
                }
            }
            
            // Update the is_read status in the array
            foreach ($announcements as &$announcement) {
                if ($announcement['id'] == $announcement_id) {
                    $announcement['is_read'] = 1;
                    break;
                }
            }
        }
    }
    
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Generate initials for avatar
$initials = '';
if (!empty($student['first_name']) && !empty($student['last_name'])) {
    $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Announcements - San Agustin Elementary School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #0b6b4f;
            --secondary: #facc15;
            --accent: #60a5fa;
        }
        
        body { 
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial; 
            background-color: #f8fafc;
        }
        
        .card { 
            background: white; 
            border-radius: 14px; 
            box-shadow: 0 4px 12px rgba(15,23,42,0.08); 
            border: 1px solid #e2e8f0;
        }
        
        .big-btn { 
            border-radius: 12px; 
            padding: 10px 16px; 
            font-weight: 600; 
            transition: all 0.2s ease; 
        }
        
        .big-btn:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .confetti {
            position: fixed;
            width: 10px;
            height: 14px;
            opacity: 0.95;
            z-index: 9999;
            pointer-events: none;
            animation: fall linear forwards;
        }
        
        @keyframes fall {
            to { transform: translateY(100vh) rotate(360deg); opacity: 0; }
        }
        
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #c1c1c1; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: #a8a8a8; }
        
        .bg-school-primary { background-color: #0b6b4f; }
        .bg-school-secondary { background-color: #facc15; }
        .bg-school-accent { background-color: #60a5fa; }
        .text-school-primary { color: #0b6b4f; }
        .text-school-secondary { color: #facc15; }
        .text-school-accent { color: #60a5fa; }
        
        .dropdown-content {
            display: none;
            position: absolute;
            background-color: white;
            min-width: 200px;
            box-shadow: 0px 8px 16px 0px rgba(0,0,0,0.2);
            z-index: 1;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .dropdown:hover .dropdown-content {
            display: block;
        }
        
        .collapsible-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
        }
        
        .collapsible-arrow {
            transition: transform 0.3s ease;
        }
        
        .footer-link {
            transition: all 0.2s ease;
        }
        
        .footer-link:hover {
            color: var(--secondary);
            transform: translateY(-2px);
        }
        
        .search-input:focus {
            box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.3);
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
        }
        
        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }
        
        .toast.success { border-left-color: var(--primary); }
        .toast.info { border-left-color: var(--accent); }
        .toast.warning { border-left-color: var(--secondary); }
        .toast.error { border-left-color: #ef4444; }
        
        .toast .toast-icon { font-size: 1.2rem; }
        .toast .toast-message { flex: 1; font-size: 0.875rem; color: #1f2937; }
        .toast .toast-close { 
            cursor: pointer; 
            color: #6b7280; 
            font-size: 1rem; 
            transition: color 0.2s ease; 
        }
        .toast .toast-close:hover { color: #1f2937; }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }

        .modal-content {
            background-color: white;
            border-radius: 12px;
            padding: 24px;
            width: 100%;
            max-width: 600px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            position: relative;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            cursor: pointer;
            font-size: 1.2rem;
            color: #6b7280;
        }

        .modal-close:hover {
            color: #1f2937;
        }

        .active-nav {
            background-color: #34d399;
            color: #1f2937;
        }
        
        .active-nav:hover {
            background-color: #2dd4bf;
        }
        
        .announcement-unread {
            background-color: #f0fdf4;
            border-left: 4px solid #10b981;
        }
        
        .priority-high {
            border-left: 4px solid #ef4444;
        }
        
        .priority-medium {
            border-left: 4px solid #f59e0b;
        }
        
        .priority-low {
            border-left: 4px solid #60a5fa;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Sidebar -->
        <aside class="hidden md:flex md:flex-col w-72 p-5 bg-school-primary text-white">
            <div class="flex items-center gap-3 mb-6">
                <div class="w-14 h-14 rounded-full bg-white flex items-center justify-center">
                    <img src="logo.jpg" alt="San Agustin ES Logo" class="w-full h-full object-contain rounded-full">
                </div>
                <div>
                    <div class="text-lg font-extrabold">San Agustin Elementary School</div>
                    <div class="text-xs text-white/80">Student Portal</div>
                </div>
            </div>
            
            <div class="bg-white/10 p-3 rounded-xl mb-4 flex items-center gap-3">
                <div class="w-12 h-12 rounded-full bg-school-secondary text-school-primary font-bold flex items-center justify-center">
                    <?php echo $initials; ?>
                </div>
                <div>
                    <div class="font-semibold"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                    <div class="text-xs text-white/80">
                        Grade <?php echo htmlspecialchars($student['grade_level']); ?> • 
                        Section <?php echo htmlspecialchars($student['section']); ?>
                    </div>
                </div>
            </div>
            
            <nav class="mt-3 flex-1 space-y-2">
                <a id="navDashboard" href="dashboard.php" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center gap-2 px-3 py-3">
                    <i class="fas fa-home"></i> Dashboard
                </a>
                <a id="navSchedule" href="class_schedule.php" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center gap-2 px-3 py-3">
                    <i class="fas fa-calendar"></i> Schedule
                </a>
                <div class="collapsible-section">
                    <button id="booksBtn" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center justify-between px-3 py-3">
                        <span class="flex items-center gap-2"><i class="fas fa-book"></i> Library Books</span>
                        <span class="collapsible-arrow">▼</span>
                    </button>
                    <div class="collapsible-content bg-green-900 rounded-lg mt-1 overflow-hidden">
                        <a href="available-books.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors">
                            <i class="fas fa-book-open mr-2"></i> Available Books
                        </a>
                        <a href="borrow-history.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors">
                            <i class="fas fa-history mr-2"></i> My Borrowed Books
                        </a>
                        <a href="return-books.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors">
                            <i class="fas fa-undo mr-2"></i> Return Books
                        </a>
                        <a href="recommendations.php" class="block px-4 py-2 text-white hover:bg-green-700 transition-colors">
                            <i class="fas fa-star mr-2"></i> Recommendations
                        </a>
                    </div>
                </div>
                <a id="navGrades" href="grades.php" class="w-full big-btn bg-green-800 hover:bg-green-700 text-white flex items-center gap-3 px-3 py-3 rounded">
                    <i class="fas fa-chart-line"></i>
                    <span>Grades</span>
                </a>
                <a id="navAnnouncements" href="announcements.php" class="w-full big-btn bg-green-600 hover:bg-green-500 text-white flex items-center gap-3 px-3 py-3 rounded active-nav">
                    <i class="fas fa-bullhorn"></i>
                    <span>Announcements</span>
                </a>
            </nav>
            
            <div class="mt-4">
                <form action="../logout.php" method="post" class="w-full">
                    <button type="submit" id="logout" class="w-full bg-red-600 hover:bg-red-500 big-btn flex items-center justify-center gap-2">
                        <i class="fas fa-sign-out-alt"></i> Sign Out
                    </button>
                </form>
            </div>
        </aside>
        
        <!-- Main Content -->
        <main class="flex-1 p-5">
            <!-- Header -->
            <header class="flex items-center justify-between mb-6 bg-white p-4 rounded-xl shadow-sm border border-gray-200">
                <div class="flex items-center gap-4">
                    <button id="mobileMenuBtn" class="md:hidden p-2 rounded-lg bg-school-primary text-white">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1 class="text-2xl md:text-3xl font-extrabold text-school-primary flex items-center gap-2">
                        <i class="fas fa-bullhorn"></i>
                        Announcements
                    </h1>
                    <span class="hidden md:inline text-sm text-gray-600">
                        Welcome back, <?php echo htmlspecialchars($student['first_name']); ?>! 🎉
                    </span>
                </div>
                <div class="flex items-center gap-3">
                    <button id="settingsBtn" class="p-2 rounded-full bg-white border hover:bg-gray-50" title="Student Settings">
                        <i class="fas fa-cog text-gray-700"></i>
                    </button>
                    <div class="hidden sm:flex items-center bg-white rounded-full border px-3 py-1 shadow-sm">
                        <span class="text-sm text-green-600 font-medium mr-2"><i class="fas fa-circle animate-pulse"></i> Online</span>
                        <span id="onlineCount" class="text-xs text-gray-500">Loading...</span>
                    </div>
                    <div class="relative">
                        <input id="search" aria-label="Search announcements" class="hidden sm:inline px-4 py-2 rounded-full border w-64 search-input pl-10" placeholder="Search announcements..." />
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    </div>
                    <button id="notifBtn" class="p-2 rounded-full bg-school-secondary relative" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <span id="notifCount" class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-5 h-5 flex items-center justify-center">0</span>
                    </button>
                    <div class="flex items-center gap-2 p-2 rounded-full bg-white shadow-sm border">
                        <div class="w-9 h-9 rounded-full bg-school-primary text-white flex items-center justify-center font-semibold">
                            <?php echo $initials; ?>
                        </div>
                        <div class="hidden sm:block text-sm">
                            <div class="font-semibold text-school-primary"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></div>
                            <div class="text-xs text-gray-500">
                                Grade <?php echo htmlspecialchars($student['grade_level']); ?> - 
                                Section <?php echo htmlspecialchars($student['section']); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Settings Modal -->
            <div id="settingsModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-[10001] p-4">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl border">
                    <div class="flex items-center justify-between p-4 border-b">
                        <h3 class="text-lg font-bold text-school-primary flex items-center gap-2"><i class="fas fa-user-cog"></i> Student Information</h3>
                        <button id="closeSettings" class="p-2 rounded-full hover:bg-gray-100" aria-label="Close settings"><i class="fas fa-times"></i></button>
                    </div>
                    <form id="settingsForm" class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                          <label class="block text-sm text-black mb-1">First Name</label>
                          <input id="set_first_name" type="text" class="w-full border rounded-lg px-3 py-2 text-black" required>
                        </div>
                        <div>
                          <label class="block text-sm text-black mb-1">Last Name</label>
                          <input id="set_last_name" type="text" class="w-full border rounded-lg px-3 py-2 text-black" required>
                        </div>
                        <div>
                          <label class="block text-sm text-black mb-1">Student ID</label>
                          <input id="set_student_id" type="text" class="w-full border rounded-lg px-3 py-2 text-black">
                        </div>
                        <div>
                          <label class="block text-sm text-black mb-1">School Year</label>
                          <input id="set_school_year" type="text" class="w-full border rounded-lg px-3 py-2 text-black" placeholder="2025-2026">
                        </div>
                        <div>
                          <label class="block text-sm text-black mb-1">Grade Level</label>
                          <input id="set_grade_level" type="number" min="1" max="12" class="w-full border rounded-lg px-3 py-2 text-black">
                        </div>
                        <div>
                          <label class="block text-sm text-black mb-1">Section</label>
                          <input id="set_section" type="text" class="w-full border rounded-lg px-3 py-2 text-black">
                        </div>
                        <div class="md:col-span-2 flex items-center justify-end gap-2 pt-2 border-t mt-2">
                          <button type="button" id="cancelSettings" class="px-4 py-2 rounded-lg border hover:bg-gray-50">Cancel</button>
                          <button type="submit" class="px-4 py-2 rounded-lg bg-school-primary text-white hover:bg-green-700 flex items-center gap-2"><i class="fas fa-save"></i> Save</button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Notification Dropdown -->
            <div id="notificationDropdown" class="hidden absolute right-4 top-28 z-50 w-80 bg-white rounded-lg shadow-lg p-4 border">
                <h3 class="font-bold text-lg mb-2 text-school-primary flex items-center gap-2">
                    <i class="fas fa-bell"></i> Notifications
                </h3>
                <ul id="notificationList" class="space-y-2">
                    <li class="p-2 bg-green-50 rounded border flex items-center gap-2">
                        <i class="fas fa-info-circle text-school-primary"></i>
                        <span>No new notifications</span>
                    </li>
                </ul>
                <button id="markAllRead" class="mt-3 text-sm text-school-accent font-medium flex items-center gap-1">
                    <i class="fas fa-check-circle"></i> Mark all as read
                </button>
            </div>
            
            <?php if ($error): ?>
                <div class="mb-4 p-4 bg-red-100 border-l-4 border-red-500 text-red-700">
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Announcements Section -->
            <section class="card p-5">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6">
                    <h3 class="text-lg font-bold text-school-primary flex items-center gap-2 mb-2 md:mb-0">
                        <i class="fas fa-bullhorn"></i>
                        Latest Announcements
                    </h3>
                    <div class="flex items-center gap-3">
                        <div class="relative">
                            <input type="text" id="announcementSearch" placeholder="Search announcements..." class="px-4 py-2 rounded-full border w-full sm:w-64 pl-10 focus:outline-none focus:ring-2 focus:ring-school-primary focus:border-transparent">
                            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                        </div>
                        <select id="filterPriority" class="px-4 py-2 rounded-lg border text-sm focus:outline-none focus:ring-2 focus:ring-school-primary focus:border-transparent">
                            <option value="all">All Priorities</option>
                            <option value="high">High Priority</option>
                            <option value="medium">Medium Priority</option>
                            <option value="low">Low Priority</option>
                        </select>
                    </div>
                </div>
                
                <div class="space-y-4" id="announcementsContainer">
                    <!-- Loading state -->
                    <div id="loadingAnnouncements" class="text-center py-10">
                        <div class="inline-block animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-school-primary"></div>
                        <p class="mt-2 text-gray-600">Loading announcements...</p>
                    </div>
                    
                    <!-- Empty state (initially hidden) -->
                    <div id="noAnnouncements" class="text-center py-10 text-gray-500 hidden">
                        <i class="fas fa-bullhorn text-4xl mb-3 text-gray-300"></i>
                        <p class="text-lg">No announcements available at the moment.</p>
                        <p class="text-sm mt-1">Please check back later for updates.</p>
                        <button onclick="loadAnnouncements()" class="mt-3 px-4 py-2 bg-school-primary text-white rounded-lg hover:bg-opacity-90 transition-colors">
                            <i class="fas fa-sync-alt mr-2"></i> Refresh
                        </button>
                    </div>
                    
                    <!-- Announcements will be inserted here by JavaScript -->
                    
                    <!-- No results message (initially hidden) -->
                    <div id="noResultsMessage" class="text-center py-10 text-gray-500 hidden">
                        <i class="fas fa-search text-4xl mb-3 text-gray-300"></i>
                        <p class="text-lg">No announcements match your search criteria</p>
                        <p class="text-sm mt-1">Try adjusting your search or filter</p>
                        <button onclick="document.getElementById('announcementSearch').value = ''; document.getElementById('filterPriority').value = 'all'; filterAnnouncements();" 
                                class="mt-3 px-4 py-2 bg-school-primary text-white rounded-lg hover:bg-opacity-90 transition-colors">
                            <i class="fas fa-undo mr-2"></i> Reset filters
                        </button>
                    </div>
                </div>
            </section>
            
            <!-- Announcement Detail Modal -->
            <?php if (isset($_GET['view']) && is_numeric($_GET['view'])): 
                $viewId = $_GET['view'];
                $currentAnnouncement = null;
                
                foreach ($announcements as $announcement) {
                    if ($announcement['id'] == $viewId) {
                        $currentAnnouncement = $announcement;
                        break;
                    }
                }
                
                if ($currentAnnouncement): 
                    $announcementDate = new DateTime($currentAnnouncement['start_date']);
                    $endDate = !empty($currentAnnouncement['end_date']) ? new DateTime($currentAnnouncement['end_date']) : null;
            ?>
                <div id="announcementModal" class="modal" style="display: flex;">
                    <div class="modal-content">
                        <button id="modalClose" class="modal-close">
                            <i class="fas fa-times"></i>
                        </button>
                        
                        <div class="mb-4">
                            <h3 class="text-2xl font-bold text-gray-800 mb-2">
                                <?php echo htmlspecialchars($currentAnnouncement['title']); ?>
                            </h3>
                            <div class="flex flex-wrap gap-2 mb-4">
                                <span class="text-sm px-2 py-1 rounded-full 
                                          <?php echo $currentAnnouncement['priority'] === 'high' ? 'bg-red-100 text-red-800' : 
                                                    ($currentAnnouncement['priority'] === 'medium' ? 'bg-yellow-100 text-yellow-800' : 'bg-blue-100 text-blue-800'); ?>">
                                    <?php echo ucfirst($currentAnnouncement['priority']); ?> Priority
                                </span>
                                <?php 
                                $source = $currentAnnouncement['source'] ?? 'school';
                                $postedBy = $currentAnnouncement['posted_by'] ?? 'School Administration';
                                $sourceClass = $source === 'teacher' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800';
                                $sourceIcon = $source === 'teacher' ? 'fa-chalkboard-teacher' : 'fa-school';
                                $sourceLabel = $source === 'teacher' ? 'Class Announcement' : 'School Announcement';
                                ?>
                                <span class="text-sm px-2 py-1 rounded-full <?php echo $sourceClass; ?>">
                                    <i class="fas <?php echo $sourceIcon; ?> mr-1"></i>
                                    <?php echo $sourceLabel; ?>
                                </span>
                                <span class="text-sm text-gray-600">
                                    <i class="fas fa-user mr-1"></i>
                                    <?php echo htmlspecialchars($postedBy); ?>
                                </span>
                                <span class="text-sm text-gray-600">
                                    <i class="far fa-calendar-alt mr-1"></i>
                                    <?php echo $announcementDate->format('F j, Y \a\t g:i A'); ?>
                                </span>
                                <?php if ($endDate): ?>
                                    <span class="text-sm text-gray-600">
                                        <i class="far fa-clock mr-1"></i>
                                        Ends: <?php echo $endDate->format('F j, Y \a\t g:i A'); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($currentAnnouncement['image_path'])): ?>
                            <div class="mb-6 rounded-lg overflow-hidden">
                                <img src="<?php echo htmlspecialchars($currentAnnouncement['image_path']); ?>" 
                                     alt="Announcement image" 
                                     class="w-full h-64 object-cover">
                            </div>
                        <?php endif; ?>
                        
                        <div class="prose max-w-none mb-6">
                            <?php echo nl2br(htmlspecialchars($currentAnnouncement['description'])); ?>
                        </div>
                        
                        <div class="flex justify-end gap-3 pt-4 border-t">
                            <button id="closeModalBtn" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 rounded-lg hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-school-primary">
                                Close
                            </button>
                            <button id="markAsReadBtn" data-announcement-id="<?php echo $currentAnnouncement['id']; ?>" 
                                    class="px-4 py-2 text-sm font-medium text-white bg-school-primary rounded-lg hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-school-primary">
                                <i class="fas fa-check-circle mr-1"></i> Mark as Read
                            </button>
                        </div>
                    </div>
                </div>
            <?php 
                endif;
            endif; 
            ?>
            
            <footer class="mt-8 text-center border-t pt-6 pb-4 bg-white rounded-xl shadow-sm">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-4">
                    <div class="text-center md:text-left">
                        <h4 class="font-bold text-school-primary mb-2">San Agustin Elementary School <br>Student Portal</h4>
                        <p class="text-sm text-gray-600">Where learning is fun and exciting!</p>
                    </div>
                    <div>
                        <h4 class="font-bold text-school-primary mb-2">Quick Links</h4>
                        <div class="flex flex-col md:flex-row justify-center gap-4 text-sm">
                            <a href="#" class="footer-link text-gray-600"><i class="fas fa-home mr-1"></i> Home</a>
                            <a href="#" class="footer-link text-gray-600"><i class="fas fa-info-circle mr-1"></i> About</a>
                            <a href="#" class="footer-link text-gray-600"><i class="fas fa-envelope mr-1"></i> Contact</a>
                        </div>
                    </div>
                    <div>
                        <h4 class="font-bold text-school-primary mb-2">Connect With Us</h4>
                        <div class="flex justify-center md:justify-center gap-4">
                            <a href="#" class="footer-link text-gray-600 text-xl"><i class="fab fa-facebook"></i></a>
                            <a href="#" class="footer-link text-gray-600 text-xl"><i class="fab fa-twitter"></i></a>
                            <a href="#" class="footer-link text-gray-600 text-xl"><i class="fab fa-instagram"></i></a>
                            <a href="#" class="footer-link text-gray-600 text-xl"><i class="fas fa-envelope"></i></a>
                        </div>
                    </div>
                </div>
                <div class="text-sm text-gray-500 border-t pt-3">
                    © <?php echo date('Y'); ?> San Agustin Elementary School Student Portal • Learning is Fun!
                </div>
            </footer>
        </main>
    </div>
    
    <div id="confettiRoot">
        <div id="toastContainer" class="fixed top-4 right-4 z-[10000] space-y-2"></div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Add styles for announcement priorities */
        .announcement-item {
            transition: all 0.2s ease;
        }
        
        .announcement-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .announcement-unread {
            border-left: 4px solid #3b82f6;
        }
        
        .priority-high {
            border-left: 4px solid #ef4444;
        }
        
        .priority-medium {
            border-left: 4px solid #f59e0b;
        }
        
        .priority-low {
            border-left: 4px solid #3b82f6;
        }
        
        .priority-normal {
            border-left: 4px solid #9ca3af;
        }
        
        /* Line clamp for announcement content */
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        /* Loading spinner */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .fa-spin {
            animation: spin 1s linear infinite;
        }
    </style>
    
    <style>
        /* Add styles for announcement priorities */
        .announcement-item {
            transition: all 0.2s ease;
        }
        
        .announcement-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .announcement-unread {
            border-left: 4px solid #3b82f6;
        }
        
        .priority-high {
            border-left: 4px solid #ef4444;
        }
        
        .priority-medium {
            border-left: 4px solid #f59e0b;
        }
        
        .priority-low {
            border-left: 4px solid #3b82f6;
        }
        
        .priority-normal {
            border-left: 4px solid #9ca3af;
        }
        
        /* Line clamp for announcement content */
        .line-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        /* Loading spinner */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .animate-spin {
            animation: spin 1s linear infinite;
        }
    </style>
    
    <script>
        // Settings modal logic (local only)
        const settingsModal = document.getElementById('settingsModal');
        const openSettings = document.getElementById('settingsBtn');
        const closeSettings = document.getElementById('closeSettings');
        const cancelSettings = document.getElementById('cancelSettings');
        const settingsForm = document.getElementById('settingsForm');
        const headerNameEl = document.querySelector('header .font-semibold.text-school-primary');
        const headerGradeEl = document.querySelector('header .text-xs.text-gray-500');

        const savedStudentInfo = (() => { try { return JSON.parse(localStorage.getItem('student_info')||'null'); } catch { return null; } })();
        if (savedStudentInfo) {
            // Update header quick info if available
            if (headerNameEl) headerNameEl.textContent = `${savedStudentInfo.first_name || '<?php echo htmlspecialchars($student['first_name']); ?>'} ${savedStudentInfo.last_name || '<?php echo htmlspecialchars($student['last_name']); ?>'}`.trim();
            if (headerGradeEl) headerGradeEl.innerHTML = `Grade ${savedStudentInfo.grade_level || '<?php echo htmlspecialchars($student['grade_level']); ?>'} - Section ${savedStudentInfo.section || '<?php echo htmlspecialchars($student['section']); ?>'}`;
        }

        function fillSettingsForm() {
            const info = savedStudentInfo || {};
            document.getElementById('set_first_name').value = info.first_name || '<?php echo htmlspecialchars($student['first_name']); ?>';
            document.getElementById('set_last_name').value = info.last_name || '<?php echo htmlspecialchars($student['last_name']); ?>';
            document.getElementById('set_student_id').value = info.student_id || '<?php echo htmlspecialchars($student['student_id'] ?? ''); ?>';
            document.getElementById('set_school_year').value = info.school_year || '<?php echo htmlspecialchars($student['school_year'] ?? ''); ?>';
            document.getElementById('set_grade_level').value = info.grade_level || '<?php echo htmlspecialchars($student['grade_level']); ?>';
            document.getElementById('set_section').value = info.section || '<?php echo htmlspecialchars($student['section']); ?>';
        }

        function openSettingsModal() { fillSettingsForm(); settingsModal.classList.remove('hidden'); settingsModal.classList.add('flex'); }
        function closeSettingsModal() { settingsModal.classList.add('hidden'); settingsModal.classList.remove('flex'); }
        if (openSettings) openSettings.addEventListener('click', openSettingsModal);
        if (closeSettings) closeSettings.addEventListener('click', closeSettingsModal);
        if (cancelSettings) cancelSettings.addEventListener('click', closeSettingsModal);
        if (settingsModal) settingsModal.addEventListener('click', (e)=>{ if (e.target===settingsModal) closeSettingsModal(); });

        if (settingsForm) settingsForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const data = {
                first_name: document.getElementById('set_first_name').value.trim(),
                last_name: document.getElementById('set_last_name').value.trim(),
                student_id: document.getElementById('set_student_id').value.trim(),
                school_year: document.getElementById('set_school_year').value.trim(),
                grade_level: document.getElementById('set_grade_level').value.trim(),
                section: document.getElementById('set_section').value.trim()
            };
            try {
                localStorage.setItem('student_info', JSON.stringify(data));
                if (typeof showToast === 'function') showToast('Settings saved locally.', 'success');
                // Update header immediately
                if (headerNameEl) headerNameEl.textContent = `${data.first_name} ${data.last_name}`.trim();
                if (headerGradeEl) headerGradeEl.innerHTML = `Grade ${data.grade_level} - Section ${data.section}`;
                closeSettingsModal();
            } catch (err) {
                if (typeof showToast === 'function') showToast('Failed to save settings.', 'error');
            }
        });
        // Toast notification function
        function showToast(message, type = 'info') {
            const toastContainer = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.setAttribute('role', 'alert');
            toast.setAttribute('aria-live', 'assertive');
            toast.setAttribute('aria-atomic', 'true');
            
            const icons = {
                success: '<i class="fas fa-check-circle toast-icon"></i>',
                info: '<i class="fas fa-info-circle toast-icon"></i>',
                warning: '<i class="fas fa-exclamation-circle toast-icon"></i>',
                error: '<i class="fas fa-times-circle toast-icon"></i>'
            };
            
            toast.innerHTML = `
                ${icons[type] || icons.info}
                <div class="toast-message">${message}</div>
                <i class="fas fa-times toast-close" role="button" aria-label="Close notification"></i>
            `;
            
            toastContainer.appendChild(toast);
            
            // Trigger reflow to enable animation
            setTimeout(() => toast.classList.add('show'), 100);
            
            // Auto remove after 5 seconds
            const timeout = setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
            
            // Manual close
            const closeBtn = toast.querySelector('.toast-close');
            closeBtn.addEventListener('click', () => {
                clearTimeout(timeout);
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            });
        }
        
        // Mobile menu toggle
        document.addEventListener('DOMContentLoaded', () => {
            const mobileMenuBtn = document.getElementById('mobileMenuBtn');
            const sidebar = document.querySelector('aside');
            
            if (mobileMenuBtn && sidebar) {
                mobileMenuBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    sidebar.classList.toggle('hidden');
                    sidebar.classList.toggle('flex');
                    sidebar.classList.toggle('fixed');
                    sidebar.classList.toggle('inset-0');
                    sidebar.classList.toggle('z-50');
                    document.body.classList.toggle('overflow-hidden');
                });
                
                // Close sidebar when clicking outside
                document.addEventListener('click', (e) => {
                    if (!sidebar.contains(e.target) && e.target !== mobileMenuBtn && !mobileMenuBtn.contains(e.target)) {
                        sidebar.classList.add('hidden');
                        sidebar.classList.remove('flex', 'fixed', 'inset-0', 'z-50');
                        document.body.classList.remove('overflow-hidden');
                    }
                });
            }
            
            // Collapsible sections
            const collapsibleSections = document.querySelectorAll('.collapsible-section');
            collapsibleSections.forEach(section => {
                const button = section.querySelector('button');
                const content = section.querySelector('.collapsible-content');
                const arrow = section.querySelector('.collapsible-arrow');
                
                if (button && content) {
                    button.addEventListener('click', () => {
                        const isExpanded = content.style.maxHeight && content.style.maxHeight !== '0px';
                        content.style.maxHeight = isExpanded ? '0' : `${content.scrollHeight}px`;
                        
                        if (arrow) {
                            arrow.style.transform = isExpanded ? 'rotate(0deg)' : 'rotate(180deg)';
                        }
                        
                        // Save state to localStorage
                        const sectionId = button.id || 'collapsible-' + Math.random().toString(36).substr(2, 9);
                        localStorage.setItem(sectionId, !isExpanded);
                    });
                    
                    // Load initial state from localStorage
                    const sectionId = button.id || 'collapsible-' + Math.random().toString(36).substr(2, 9);
                    const isExpanded = localStorage.getItem(sectionId) === 'true';
                    if (isExpanded) {
                        content.style.maxHeight = `${content.scrollHeight}px`;
                        if (arrow) arrow.style.transform = 'rotate(180deg)';
                    }
                }
            });
            
            // Notification dropdown
            const notifBtn = document.getElementById('notifBtn');
            const notifCountEl = document.getElementById('notifCount');
            const notificationDropdown = document.getElementById('notificationDropdown');
            
            if (notifBtn && notificationDropdown) {
                notifBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    notificationDropdown.classList.toggle('hidden');
                    
                    // Mark notifications as read when dropdown is opened
                    if (!notificationDropdown.classList.contains('hidden')) {
                        // Update notification count to 0
                        if (notifCountEl) {
                            notifCountEl.textContent = '0';
                        }
                        
                        // Here you would typically make an AJAX call to mark notifications as read
                        // fetch('mark_notifications_read.php', { method: 'POST' });
                    }
                });
                
                // Close dropdown when clicking outside
                document.addEventListener('click', (e) => {
                    if (!notificationDropdown.contains(e.target) && e.target !== notifBtn && !notifBtn.contains(e.target)) {
                        notificationDropdown.classList.add('hidden');
                    }
                });
            }
            
            // Mark all as read button
            const markAllReadBtn = document.getElementById('markAllRead');
            if (markAllReadBtn) {
                markAllReadBtn.addEventListener('click', () => {
                    // Here you would typically make an AJAX call to mark all notifications as read
                    // fetch('mark_all_notifications_read.php', { method: 'POST' });
                    
                    // Update UI
                    const notificationItems = document.querySelectorAll('#notificationList li');
                    notificationItems.forEach(item => {
                        item.classList.remove('bg-blue-50');
                        item.classList.add('bg-gray-50');
                    });
                    
                    if (notifCountEl) {
                        notifCountEl.textContent = '0';
                    }
                    
                    showToast('All notifications marked as read', 'success');
                });
            }
            
            // Search functionality
            const announcementSearch = document.getElementById('announcementSearch');
            const filterPriority = document.getElementById('filterPriority');
            const announcementItems = document.querySelectorAll('.announcement-item');
            
            function filterAnnouncements() {
                const searchTerm = announcementSearch ? announcementSearch.value.toLowerCase() : '';
                const priorityFilter = filterPriority ? filterPriority.value : 'all';
                
                let visibleCount = 0;
                
                announcementItems.forEach(item => {
                    const title = item.getAttribute('data-title') || '';
                    const content = item.getAttribute('data-content') || '';
                    const priority = item.getAttribute('data-priority') || '';
                    
                    const matchesSearch = title.includes(searchTerm) || content.includes(searchTerm);
                    const matchesPriority = priorityFilter === 'all' || priority === priorityFilter;
                    
                    if (matchesSearch && matchesPriority) {
                        item.style.display = '';
                        visibleCount++;
                    } else {
                        item.style.display = 'none';
                    }
                });
                
                // Show message if no announcements match the filters
                const noResultsMsg = document.getElementById('noResultsMessage');
                if (visibleCount === 0) {
                    if (!noResultsMsg) {
                        const container = document.getElementById('announcementsContainer');
                        if (container) {
                            const msg = document.createElement('div');
                            msg.id = 'noResultsMessage';
                            msg.className = 'text-center py-10 text-gray-500';
                            msg.innerHTML = `
                                <i class="fas fa-search text-4xl mb-3 text-gray-300"></i>
                                <p class="text-lg">No announcements found matching your criteria.</p>
                                <p class="text-sm mt-1">Try adjusting your search or filter.</p>
                            `;
                            container.appendChild(msg);
                        }
                    }
                } else if (noResultsMsg) {
                    noResultsMsg.remove();
                }
            }
            
            if (announcementSearch) {
                announcementSearch.addEventListener('input', filterAnnouncements);
            }
            
            if (filterPriority) {
                filterPriority.addEventListener('change', filterAnnouncements);
            }
            
            // Modal functionality
            const modal = document.getElementById('announcementModal');
            const modalClose = document.getElementById('modalClose');
            const closeModalBtn = document.getElementById('closeModalBtn');
            const markAsReadBtn = document.getElementById('markAsReadBtn');
            
            function closeModal() {
                if (modal) {
                    modal.style.display = 'none';
                    // Remove the view parameter from URL without page reload
                    if (window.history.replaceState) {
                        const url = new URL(window.location);
                        url.searchParams.delete('view');
                        window.history.replaceState({}, '', url);
                    }
                }
            }
            
            if (modalClose) modalClose.addEventListener('click', closeModal);
            if (closeModalBtn) closeModalBtn.addEventListener('click', closeModal);
            
            // Close modal when clicking outside
            if (modal) {
                window.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        closeModal();
                    }
                });
                
                // Close with Escape key
                document.addEventListener('keydown', (e) => {
                    if (e.key === 'Escape' && modal.style.display === 'flex') {
                        closeModal();
                    }
                });
            }
            
            // Mark as read button
            if (markAsReadBtn) {
                markAsReadBtn.addEventListener('click', () => {
                    const announcementId = markAsReadBtn.getAttribute('data-announcement-id');
                    
                    // Here you would typically make an AJAX call to mark the announcement as read
                    // fetch(`mark_announcement_read.php?id=${announcementId}`, { method: 'POST' });
                    
                    // Update UI
                    const announcementItem = document.querySelector(`.announcement-item[data-announcement-id="${announcementId}"]`);
                    if (announcementItem) {
                        announcementItem.classList.remove('announcement-unread');
                        const unreadBadge = announcementItem.querySelector('.unread-badge');
                        if (unreadBadge) {
                            unreadBadge.remove();
                        }
                    }
                    
                    showToast('Announcement marked as read', 'success');
                    closeModal();
                });
            }
            
            // Simulate online users
            function simulateOnlineUsers() {
                const onlineCount = document.getElementById('onlineCount');
                if (onlineCount) {
                    // Set initial value
                    const baseCount = 20;
                    const fluctuation = Math.floor(Math.random() * 10);
                    onlineCount.textContent = (baseCount + fluctuation) + ' students online';
                    
                    // Update every 30 seconds
                    setInterval(() => {
                        const newFluctuation = Math.floor(Math.random() * 10);
                        onlineCount.textContent = (baseCount + newFluctuation) + ' students online';
                    }, 30000);
                }
            }
            
            // Initialize
            simulateOnlineUsers();
            
            // Show welcome message
            setTimeout(() => {
                showToast('Welcome to the announcements page!', 'success');
            }, 1000);
            
            // Check for URL hash (for direct linking to announcements)
            if (window.location.hash) {
                const announcementId = window.location.hash.substring(1);
                const announcementElement = document.getElementById(announcementId);
                if (announcementElement) {
                    announcementElement.scrollIntoView({ behavior: 'smooth' });
                    
                    // Add highlight effect
                    announcementElement.classList.add('bg-yellow-50');
                    setTimeout(() => {
                        announcementElement.classList.remove('bg-yellow-50');
                    }, 3000);
                }
            }
        });
        
        // Function to load announcements
        function loadAnnouncements() {
            const container = document.getElementById('announcementsContainer');
            const loadingEl = document.getElementById('loadingAnnouncements');
            const noAnnouncementsEl = document.getElementById('noAnnouncements');
            
            // Show loading state
            if (loadingEl) loadingEl.classList.remove('hidden');
            if (noAnnouncementsEl) noAnnouncementsEl.classList.add('hidden');
            
            // Clear existing announcements (except loading and no announcements messages)
            const existingAnnouncements = container.querySelectorAll('.announcement-item');
            existingAnnouncements.forEach(el => el.remove());
            
            // Fetch announcements from API
            fetch('../api/get_announcements.php', {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'Cache-Control': 'no-cache'
                },
                credentials: 'same-origin'
            })
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`Server error: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Announcements loaded:', data);
                    
                    if (data.success && data.announcements && data.announcements.length > 0) {
                        // Hide loading and no announcements messages
                        if (loadingEl) loadingEl.classList.add('hidden');
                        if (noAnnouncementsEl) noAnnouncementsEl.classList.add('hidden');
                        
                        // Add each announcement to the container
                        data.announcements.forEach(announcement => {
                            container.appendChild(createAnnouncementElement(announcement));
                        });
                        
                        // Initialize any event listeners
                        initializeAnnouncementEvents();
                        
                        // Apply any active filters
                        filterAnnouncements();
                        
                        // Show success toast
                        showToast(`Loaded ${data.total} announcements`, 'success');
                    } else {
                        // No announcements
                        if (loadingEl) loadingEl.classList.add('hidden');
                        if (noAnnouncementsEl) {
                            noAnnouncementsEl.innerHTML = `
                                <i class="fas fa-bullhorn text-4xl mb-3 text-gray-300"></i>
                                <p class="text-lg">No announcements available at the moment.</p>
                                <p class="text-sm mt-1">Please check back later for updates.</p>
                                <button onclick="loadAnnouncements()" class="mt-3 px-4 py-2 bg-school-primary text-white rounded-lg hover:bg-opacity-90 transition-colors">
                                    <i class="fas fa-sync-alt mr-2"></i> Refresh
                                </button>
                            `;
                            noAnnouncementsEl.classList.remove('hidden');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading announcements:', error);
                    if (loadingEl) loadingEl.classList.add('hidden');
                    if (noAnnouncementsEl) {
                        noAnnouncementsEl.innerHTML = `
                            <div class="text-center">
                                <i class="fas fa-exclamation-triangle text-4xl mb-3 text-yellow-500"></i>
                                <p class="text-lg font-semibold text-gray-800">Failed to load announcements</p>
                                <p class="text-sm mt-1 text-gray-600">${error.message || 'Please check your connection and try again'}</p>
                                <button onclick="loadAnnouncements()" class="mt-4 px-6 py-2 bg-school-primary text-white rounded-lg hover:bg-opacity-90 transition-colors shadow-md">
                                    <i class="fas fa-sync-alt mr-2"></i> Retry
                                </button>
                            </div>
                        `;
                        noAnnouncementsEl.classList.remove('hidden');
                    }
                    
                    // Show error toast
                    showToast('Failed to load announcements', 'error');
                });
        }
        
        // Toast notification function
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `fixed bottom-4 right-4 px-6 py-3 rounded-lg shadow-lg text-white z-50 transition-opacity duration-300 ${
                type === 'success' ? 'bg-green-500' : 
                type === 'error' ? 'bg-red-500' : 
                'bg-blue-500'
            }`;
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        // Function to create an announcement element
        function createAnnouncementElement(announcement) {
            const isRead = announcement.is_read || false;
            const priority = announcement.priority || 'normal';
            const announcementDate = new Date(announcement.start_date || announcement.created_at);
            const now = new Date();
            const isNew = Math.floor((now - announcementDate) / (1000 * 60 * 60 * 24)) <= 3;
            const source = announcement.source || 'school';
            const postedBy = announcement.posted_by || 'School Administration';
            
            // Format date
            const formattedDate = announcementDate.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
            
            // Priority class mapping
            const priorityClasses = {
                'high': 'bg-red-100 text-red-800',
                'medium': 'bg-yellow-100 text-yellow-800',
                'low': 'bg-blue-100 text-blue-800',
                'normal': 'bg-gray-100 text-gray-800'
            };
            
            // Source icon and color mapping
            const sourceInfo = {
                'teacher': {
                    icon: 'fa-chalkboard-teacher',
                    color: 'text-purple-600',
                    bgColor: 'bg-purple-50',
                    borderColor: 'border-purple-200'
                },
                'school': {
                    icon: 'fa-school',
                    color: 'text-blue-600',
                    bgColor: 'bg-blue-50',
                    borderColor: 'border-blue-200'
                }
            };
            
            const sourceData = sourceInfo[source] || sourceInfo['school'];
            
            // Create the announcement element
            const announcementEl = document.createElement('div');
            announcementEl.className = `announcement-item bg-white rounded-lg border overflow-hidden shadow-sm hover:shadow-md transition-shadow duration-200 priority-${priority} ${!isRead ? 'announcement-unread' : ''}`;
            announcementEl.dataset.priority = priority;
            announcementEl.dataset.title = announcement.title ? announcement.title.toLowerCase() : '';
            announcementEl.dataset.content = announcement.description ? announcement.description.toLowerCase() : '';
            announcementEl.dataset.source = source;
            announcementEl.id = `announcement-${announcement.id}`;
            
            // Set the inner HTML
            announcementEl.innerHTML = `
                <div class="p-4">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1 flex-wrap">
                                <h4 class="font-semibold text-lg text-gray-800">
                                    ${announcement.title ? announcement.title.escapeHTML() : 'No Title'}
                                </h4>
                                ${isNew ? '<span class="bg-green-100 text-green-800 text-xs px-2 py-0.5 rounded-full">New</span>' : ''}
                                ${!isRead ? '<span class="bg-blue-100 text-blue-800 text-xs px-2 py-0.5 rounded-full">Unread</span>' : ''}
                                <span class="text-xs px-2 py-0.5 rounded-full ${priorityClasses[priority] || 'bg-gray-100 text-gray-800'}">
                                    ${priority.charAt(0).toUpperCase() + priority.slice(1)} Priority
                                </span>
                            </div>
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-xs ${sourceData.color} ${sourceData.bgColor} px-2 py-1 rounded-full flex items-center gap-1">
                                    <i class="fas ${sourceData.icon}"></i>
                                    ${source === 'teacher' ? 'Class Announcement' : 'School Announcement'}
                                </span>
                                <span class="text-xs text-gray-500">
                                    <i class="fas fa-user mr-1"></i>
                                    Posted by ${postedBy.escapeHTML()}
                                </span>
                            </div>
                            <p class="text-gray-600 text-sm line-clamp-2 mb-2">
                                ${announcement.description ? announcement.description.escapeHTML().replace(/\n/g, '<br>') : 'No content'}
                            </p>
                            <div class="flex items-center justify-between text-xs text-gray-500">
                                <span>
                                    <i class="far fa-calendar-alt mr-1"></i>
                                    ${formattedDate}
                                </span>
                                <a href="announcements.php?view=${announcement.id}#announcement-${announcement.id}" 
                                   class="text-school-primary hover:underline flex items-center gap-1">
                                    Read more <i class="fas fa-arrow-right text-xs"></i>
                                </a>
                            </div>
                        </div>
                        ${announcement.image_path ? `
                            <div class="ml-4 flex-shrink-0">
                                <img src="${announcement.image_path.escapeHTML()}" 
                                     alt="Announcement image" 
                                     class="w-16 h-16 object-cover rounded-lg">
                            </div>
                        ` : ''}
                    </div>
                </div>
            `;
            
            return announcementEl;
        }
        
        // Helper function to escape HTML
        if (typeof String.prototype.escapeHTML !== 'function') {
            String.prototype.escapeHTML = function() {
                return this.replace(/[&<>"']/g, function(match) {
                    switch (match) {
                        case '&': return '&amp;';
                        case '<': return '&lt;';
                        case '>': return '&gt;';
                        case '"': return '&quot;';
                        case "'": return '&#39;';
                        default: return match;
                    }
                });
            };
        }
        
        // Initialize announcement events
        function initializeAnnouncementEvents() {
            // Add click handler to mark announcements as read when viewed
            document.querySelectorAll('.announcement-item').forEach(item => {
                item.addEventListener('click', function(e) {
                    // Don't mark as read if clicking on a link
                    if (e.target.tagName === 'A' || e.target.closest('a')) {
                        return;
                    }
                    
                    // Mark as read if unread
                    if (this.classList.contains('announcement-unread')) {
                        const announcementId = this.id.replace('announcement-', '');
                        if (announcementId) {
                            markAnnouncementAsRead(announcementId, this);
                        }
                    }
                    
                    // Navigate to the announcement
                    const link = this.querySelector('a');
                    if (link && link.href) {
                        window.location.href = link.href;
                    }
                });
            });
        }
        
        // Function to mark an announcement as read
        function markAnnouncementAsRead(announcementId, element) {
            // In a real implementation, you would make an API call here
            // For now, we'll just update the UI
            if (element) {
                element.classList.remove('announcement-unread');
                const unreadBadge = element.querySelector('.bg-blue-100');
                if (unreadBadge) {
                    unreadBadge.remove();
                }
            }
            
            // Example of how the API call would look:
            /*
            fetch(`../api/mark_announcement_read.php?id=${announcementId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success && element) {
                    element.classList.remove('announcement-unread');
                    const unreadBadge = element.querySelector('.bg-blue-100');
                    if (unreadBadge) {
                        unreadBadge.remove();
                    }
                }
            })
            .catch(error => {
                console.error('Error marking announcement as read:', error);
            });
            */
        }
        
        // Function to filter announcements based on search and priority
        function filterAnnouncements() {
            const searchTerm = document.getElementById('announcementSearch').value.toLowerCase();
            const priorityFilter = document.getElementById('filterPriority').value;
            
            document.querySelectorAll('.announcement-item').forEach(item => {
                const title = item.dataset.title || '';
                const content = item.dataset.content || '';
                const priority = item.dataset.priority || '';
                
                const matchesSearch = searchTerm === '' || 
                                    title.includes(searchTerm) || 
                                    content.includes(searchTerm);
                
                const matchesPriority = priorityFilter === 'all' || 
                                      priority === priorityFilter;
                
                if (matchesSearch && matchesPriority) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });
            
            // Show/hide no results message
            const visibleItems = document.querySelectorAll('.announcement-item:not([style*="display: none"])').length;
            const noResultsMsg = document.getElementById('noResultsMessage');
            
            if (noResultsMsg) {
                if (visibleItems === 0) {
                    noResultsMsg.classList.remove('hidden');
                } else {
                    noResultsMsg.classList.add('hidden');
                }
            }
        }
        
        // Initialize event listeners for search and filter
        function initializeFilterEvents() {
            const searchInput = document.getElementById('announcementSearch');
            const prioritySelect = document.getElementById('filterPriority');
            
            if (searchInput) {
                // Add debounce to search input
                let searchTimeout;
                searchInput.addEventListener('input', () => {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(filterAnnouncements, 300);
                });
                
                // Add clear button functionality if it exists
                const clearSearchBtn = document.getElementById('clearSearch');
                if (clearSearchBtn) {
                    clearSearchBtn.addEventListener('click', () => {
                        searchInput.value = '';
                        filterAnnouncements();
                    });
                }
            }
            
            if (prioritySelect) {
                prioritySelect.addEventListener('change', filterAnnouncements);
            }
        }
        
        // Load announcements when the page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize filter events
            initializeFilterEvents();
            
            // Initial load of announcements
            loadAnnouncements();
            
            // Set up polling to check for new announcements (every 30 seconds)
            setInterval(loadAnnouncements, 30000);
            
            // Handle browser back/forward buttons
            window.addEventListener('popstate', () => {
                if (window.location.hash) {
                    const announcementId = window.location.hash.substring(1);
                    const announcementElement = document.getElementById(announcementId);
                    if (announcementElement) {
                        announcementElement.scrollIntoView();
                    }
                } else {
                    window.scrollTo(0, 0);
                }
            });
        });
    </script>
</body>
</html>
