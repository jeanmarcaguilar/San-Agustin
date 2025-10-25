<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

// Check if grade ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = 'Invalid grade ID.';
    header('Location: grades.php');
    exit;
}

$grade_id = (int)$_GET['id'];
$error_messages = [];
$success_message = '';
$grade = null;

// Include database connections
require_once '../config/database.php';
$database = new Database();
$teacher_conn = null;
$student_conn = null;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_grade'])) {
    try {
        $teacher_conn = $database->getConnection('teacher');
        $student_conn = $database->getConnection('student');
        
        $score = isset($_POST['score']) ? (float)$_POST['score'] : null;
        $max_score = isset($_POST['max_score']) ? (float)$_POST['max_score'] : 100;
        $assessment_type = $_POST['assessment_type'] ?? 'quiz';
        $title = $_POST['title'] ?? '';
        $grading_period = $_POST['grading_period'] ?? '1st Quarter';
        $grade_date = $_POST['grade_date'] ?? date('Y-m-d');
        $notes = $_POST['notes'] ?? '';
        
        // Validate inputs
        if (empty($title)) {
            throw new Exception('Title is required.');
        }
        
        if ($score !== null && ($score < 0 || $score > $max_score)) {
            throw new Exception('Score must be between 0 and ' . $max_score);
        }
        
        // Begin transaction
        $teacher_conn->beginTransaction();
        
        // Get the grade info first to get student_id
        $stmt = $teacher_conn->prepare("SELECT student_id, class_id FROM grades WHERE id = ?");
        $stmt->execute([$grade_id]);
        $grade_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$grade_info) {
            throw new Exception('Grade not found.');
        }
        
        // Update grade in teacher database
        $stmt = $teacher_conn->prepare("
            UPDATE grades 
            SET score = :score,
                max_score = :max_score,
                assessment_type = :assessment_type,
                title = :title,
                grading_period = :grading_period,
                grade_date = :grade_date,
                notes = :notes,
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':score' => $score,
            ':max_score' => $max_score,
            ':assessment_type' => $assessment_type,
            ':title' => $title,
            ':grading_period' => $grading_period,
            ':grade_date' => $grade_date,
            ':notes' => $notes,
            ':id' => $grade_id
        ]);
        
        // Sync to student database - calculate and update student grades
        // Get student's numeric ID from student_id string
        $stmt = $teacher_conn->prepare("SELECT id FROM students WHERE student_id = ?");
        $stmt->execute([$grade_info['student_id']]);
        $student_record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student_record) {
            $student_numeric_id = $student_record['id'];
            
            // Get class info to determine subject
            $stmt = $teacher_conn->prepare("SELECT subject, grade_level FROM classes WHERE id = ?");
            $stmt->execute([$grade_info['class_id']]);
            $class_info = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($class_info) {
                // Map grading period to quarter
                $quarter_map = [
                    '1st Quarter' => '1st',
                    '2nd Quarter' => '2nd',
                    '3rd Quarter' => '3rd',
                    '4th Quarter' => '4th'
                ];
                $quarter = $quarter_map[$grading_period] ?? '1st';
                
                // Calculate average for this subject and quarter
                $stmt = $teacher_conn->prepare("
                    SELECT AVG(percentage) as avg_grade
                    FROM grades 
                    WHERE student_id = ? 
                    AND class_id = ?
                    AND grading_period = ?
                    AND score IS NOT NULL
                ");
                $stmt->execute([$grade_info['student_id'], $grade_info['class_id'], $grading_period]);
                $avg_result = $stmt->fetch(PDO::FETCH_ASSOC);
                $avg_grade = $avg_result['avg_grade'] ?? 0;
                
                // Update or insert into student database
                $stmt = $student_conn->prepare("
                    INSERT INTO grades (student_id, subject, grade_level, quarter, final_grade, remarks, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                        final_grade = VALUES(final_grade),
                        remarks = VALUES(remarks),
                        updated_at = NOW()
                ");
                
                $remarks = $avg_grade >= 75 ? 'Passed' : 'In Progress';
                $stmt->execute([
                    $student_numeric_id,
                    $class_info['subject'],
                    $class_info['grade_level'],
                    $quarter,
                    $avg_grade,
                    $remarks
                ]);
            }
        }
        
        $teacher_conn->commit();
        $success_message = 'Grade updated successfully!';
        
        // Refresh grade data
        $stmt = $teacher_conn->prepare("
            SELECT g.*, s.first_name, s.last_name, s.student_id, c.subject, c.grade_level, c.section 
            FROM grades g 
            JOIN students s ON g.student_id = s.student_id 
            JOIN classes c ON g.class_id = c.id 
            WHERE g.id = ?
        ");
        $stmt->execute([$grade_id]);
        $grade = $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        if ($teacher_conn && $teacher_conn->inTransaction()) {
            $teacher_conn->rollBack();
        }
        $error_messages[] = 'Error updating grade: ' . $e->getMessage();
    }
}

// Fetch grade data
try {
    $teacher_conn = $database->getConnection('teacher');
    
    $stmt = $teacher_conn->prepare("
        SELECT g.*, s.first_name, s.last_name, s.student_id, c.subject, c.grade_level, c.section 
        FROM grades g 
        JOIN students s ON g.student_id = s.student_id 
        JOIN classes c ON g.class_id = c.id 
        WHERE g.id = ?
    ");
    $stmt->execute([$grade_id]);
    $grade = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$grade) {
        throw new Exception('Grade not found.');
    }
    
} catch (Exception $e) {
    $error_messages[] = 'Error: ' . $e->getMessage();
}

// Get teacher info for the header
$teacher_info = [
    'initials' => substr($_SESSION['username'], 0, 2),
    'full_name' => $_SESSION['full_name'] ?? 'Teacher'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Grade - San Agustin Elementary School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-gradient-to-r from-gray-700 to-gray-800 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-4 sm:px-6 lg:px-8 flex justify-between items-center">
            <div class="flex items-center">
                <a href="dashboard.php" class="text-xl font-semibold">
                    <i class="fas fa-graduation-cap mr-2"></i> San Agustin ES
                </a>
            </div>
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <button id="user-menu-btn" class="flex items-center text-sm text-white focus:outline-none">
                        <span class="w-8 h-8 rounded-full bg-orange-600 flex items-center justify-center text-white font-medium">
                            <?php echo strtoupper($teacher_info['initials']); ?>
                        </span>
                        <span class="ml-2"><?php echo htmlspecialchars($teacher_info['full_name']); ?></span>
                        <i class="fas fa-chevron-down ml-1 text-xs"></i>
                    </button>
                    <div id="user-menu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-user mr-2"></i> Profile
                        </a>
                        <a href="../logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-sign-out-alt mr-2"></i> Sign out
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-4xl mx-auto px-4 py-6 sm:px-6 lg:px-8">
        <!-- Back button -->
        <div class="mb-6">
            <a href="grades.php" class="inline-flex items-center text-orange-600 hover:text-orange-800">
                <i class="fas fa-arrow-left mr-2"></i> Back to Grades
            </a>
        </div>

        <!-- Page Header -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Edit Grade</h1>
            <?php if ($grade): ?>
                <p class="mt-1 text-sm text-gray-600">
                    Student: <?php echo htmlspecialchars($grade['first_name'] . ' ' . $grade['last_name']); ?> 
                    (<?php echo htmlspecialchars($grade['student_id']); ?>)
                </p>
                <p class="text-sm text-gray-500">
                    <?php echo htmlspecialchars($grade['subject'] ?? ''); ?> - 
                    Grade <?php echo htmlspecialchars($grade['grade_level'] ?? ''); ?>
                    <?php echo htmlspecialchars($grade['section'] ?? ''); ?>
                </p>
            <?php endif; ?>
        </div>

        <?php if (!empty($error_messages)): ?>
            <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
                <div class="flex">
                    <div class="py-1">
                        <i class="fas fa-exclamation-circle mr-3"></i>
                    </div>
                    <div>
                        <?php foreach ($error_messages as $error): ?>
                            <p class="font-bold"><?php echo htmlspecialchars($error); ?></p>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded" role="alert">
                <div class="flex">
                    <div class="py-1">
                        <i class="fas fa-check-circle mr-3"></i>
                    </div>
                    <p class="font-bold"><?php echo htmlspecialchars($success_message); ?></p>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($grade): ?>
            <form method="POST" action="" class="bg-white shadow-md rounded-lg overflow-hidden">
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                                Title <span class="text-red-500">*</span>
                            </label>
                            <input type="text" 
                                   id="title" 
                                   name="title" 
                                   value="<?php echo htmlspecialchars($grade['title'] ?? ''); ?>" 
                                   required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
                        </div>

                        <div>
                            <label for="assessment_type" class="block text-sm font-medium text-gray-700 mb-2">
                                Assessment Type
                            </label>
                            <select id="assessment_type" 
                                    name="assessment_type" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
                                <option value="quiz" <?php echo ($grade['assessment_type'] ?? '') == 'quiz' ? 'selected' : ''; ?>>Quiz</option>
                                <option value="exam" <?php echo ($grade['assessment_type'] ?? '') == 'exam' ? 'selected' : ''; ?>>Exam</option>
                                <option value="project" <?php echo ($grade['assessment_type'] ?? '') == 'project' ? 'selected' : ''; ?>>Project</option>
                                <option value="homework" <?php echo ($grade['assessment_type'] ?? '') == 'homework' ? 'selected' : ''; ?>>Homework</option>
                                <option value="participation" <?php echo ($grade['assessment_type'] ?? '') == 'participation' ? 'selected' : ''; ?>>Participation</option>
                                <option value="other" <?php echo ($grade['assessment_type'] ?? '') == 'other' ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>

                        <div>
                            <label for="score" class="block text-sm font-medium text-gray-700 mb-2">
                                Score
                            </label>
                            <input type="number" 
                                   id="score" 
                                   name="score" 
                                   value="<?php echo htmlspecialchars($grade['score'] ?? ''); ?>" 
                                   min="0" 
                                   step="0.01" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
                        </div>

                        <div>
                            <label for="max_score" class="block text-sm font-medium text-gray-700 mb-2">
                                Max Score
                            </label>
                            <input type="number" 
                                   id="max_score" 
                                   name="max_score" 
                                   value="<?php echo htmlspecialchars($grade['max_score'] ?? '100'); ?>" 
                                   min="1" 
                                   step="0.01" 
                                   required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
                        </div>

                        <div>
                            <label for="grading_period" class="block text-sm font-medium text-gray-700 mb-2">
                                Grading Period
                            </label>
                            <select id="grading_period" 
                                    name="grading_period" 
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
                                <option value="1st Quarter" <?php echo ($grade['grading_period'] ?? '') == '1st Quarter' ? 'selected' : ''; ?>>1st Quarter</option>
                                <option value="2nd Quarter" <?php echo ($grade['grading_period'] ?? '') == '2nd Quarter' ? 'selected' : ''; ?>>2nd Quarter</option>
                                <option value="3rd Quarter" <?php echo ($grade['grading_period'] ?? '') == '3rd Quarter' ? 'selected' : ''; ?>>3rd Quarter</option>
                                <option value="4th Quarter" <?php echo ($grade['grading_period'] ?? '') == '4th Quarter' ? 'selected' : ''; ?>>4th Quarter</option>
                                <option value="Midterm" <?php echo ($grade['grading_period'] ?? '') == 'Midterm' ? 'selected' : ''; ?>>Midterm</option>
                                <option value="Final" <?php echo ($grade['grading_period'] ?? '') == 'Final' ? 'selected' : ''; ?>>Final</option>
                            </select>
                        </div>

                        <div>
                            <label for="grade_date" class="block text-sm font-medium text-gray-700 mb-2">
                                Grade Date
                            </label>
                            <input type="date" 
                                   id="grade_date" 
                                   name="grade_date" 
                                   value="<?php echo htmlspecialchars($grade['grade_date'] ?? date('Y-m-d')); ?>" 
                                   required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500">
                        </div>

                        <div class="md:col-span-2">
                            <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
                                Notes
                            </label>
                            <textarea id="notes" 
                                      name="notes" 
                                      rows="3" 
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:ring-orange-500 focus:border-orange-500"><?php echo htmlspecialchars($grade['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end space-x-3">
                    <a href="grades.php" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                        Cancel
                    </a>
                    <button type="submit" name="update_grade" class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500">
                        <i class="fas fa-save mr-2"></i> Update Grade
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </main>

    <script>
        // Toggle user menu
        document.getElementById('user-menu-btn').addEventListener('click', function() {
            document.getElementById('user-menu').classList.toggle('hidden');
        });

        // Close user menu when clicking outside
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('user-menu');
            const userButton = document.getElementById('user-menu-btn');
            
            if (userMenu && userButton && !userMenu.contains(event.target) && !userButton.contains(event.target)) {
                userMenu.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
