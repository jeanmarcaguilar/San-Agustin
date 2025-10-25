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

// Check if assignment ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = 'Invalid assignment ID.';
    header('Location: report_assignments.php');
    exit;
}

$assignment_id = (int)$_GET['id'];
$error_messages = [];
$success_message = '';
$assignment = null;
$submissions = [];

// Include database connections
require_once '../config/database.php';
$database = new Database();
$teacher_conn = null;

// Process grade submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades'])) {
    try {
        $teacher_conn = $database->getConnection('teacher');
        $teacher_conn->beginTransaction();
        
        foreach ($_POST['grades'] as $submission_id => $grade_data) {
            $score = isset($grade_data['score']) ? (float)$grade_data['score'] : null;
            $feedback = $grade_data['feedback'] ?? '';
            $status = $grade_data['status'] ?? 'submitted';
            
            $stmt = $teacher_conn->prepare("
                UPDATE assignment_submissions 
                SET score = :score, 
                    feedback = :feedback,
                    status = :status,
                    graded_at = NOW()
                WHERE id = :id AND assignment_id = :assignment_id
            ");
            
            $stmt->execute([
                ':score' => $score,
                ':feedback' => $feedback,
                ':status' => $status,
                ':id' => $submission_id,
                ':assignment_id' => $assignment_id
            ]);
        }
        
        $teacher_conn->commit();
        $success_message = 'Grades updated successfully!';
    } catch (Exception $e) {
        if ($teacher_conn) {
            $teacher_conn->rollBack();
        }
        $error_messages[] = 'Error updating grades: ' . $e->getMessage();
    }
}

try {
    // Get teacher connection
    $teacher_conn = $database->getConnection('teacher');
    
    // Get assignment details
    $stmt = $teacher_conn->prepare("
        SELECT a.*, t.subject, t.grade_level, t.section
        FROM assignments a
        JOIN teachers t ON a.teacher_id = t.id
        WHERE a.id = ? AND a.teacher_id = ?
    ");
    $stmt->execute([$assignment_id, $_SESSION['user_id']]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$assignment) {
        throw new Exception('Assignment not found or access denied.');
    }
    
    // Get all submissions for this assignment
    $stmt = $teacher_conn->prepare("
        SELECT s.*, st.student_name, st.student_id
        FROM assignment_submissions s
        JOIN students st ON s.student_id = st.id
        WHERE s.assignment_id = ?
        ORDER BY st.student_name
    ");
    $stmt->execute([$assignment_id]);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no submissions exist yet, create placeholders for all students in the class
    if (empty($submissions)) {
        $stmt = $teacher_conn->prepare("
            SELECT id, student_name, student_id
            FROM students 
            WHERE grade_level = ? AND section = ?
            ORDER BY student_name
        ");
        $stmt->execute([$assignment['grade_level'], $assignment['section']]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($students as $student) {
            $submissions[] = [
                'id' => null,
                'student_id' => $student['id'],
                'student_name' => $student['student_name'],
                'student_id' => $student['student_id'],
                'submitted_at' => null,
                'score' => null,
                'feedback' => '',
                'status' => 'missing',
                'file_path' => null
            ];
        }
    }
    
} catch (Exception $e) {
    $error_messages[] = 'Error: ' . $e->getMessage();
}

// Get teacher info for the header
$teacher_info = [
    'initials' => substr($_SESSION['username'], 0, 2),
    'full_name' => $_SESSION['full_name'] ?? 'Teacher',
    'subject' => $assignment['subject'] ?? 'General'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Assignment - San Agustin Elementary School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-blue-700 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-4 sm:px-6 lg:px-8 flex justify-between items-center">
            <div class="flex items-center">
                <a href="dashboard.php" class="text-xl font-semibold">
                    <i class="fas fa-graduation-cap mr-2"></i> San Agustin ES
                </a>
            </div>
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <button id="user-menu-btn" class="flex items-center text-sm text-white focus:outline-none">
                        <span class="w-8 h-8 rounded-full bg-blue-600 flex items-center justify-center text-white font-medium">
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
    <main class="max-w-7xl mx-auto px-4 py-6 sm:px-6 lg:px-8">
        <!-- Back button -->
        <div class="mb-6">
            <a href="report_assignments.php" class="inline-flex items-center text-blue-600 hover:text-blue-800">
                <i class="fas fa-arrow-left mr-2"></i> Back to Assignments
            </a>
        </div>

        <!-- Page Header -->
        <div class="md:flex md:items-center md:justify-between mb-6">
            <div>
                <h1 class="text-2xl font-bold text-gray-900">
                    Grade Assignment: <?php echo htmlspecialchars($assignment['title'] ?? 'N/A'); ?>
                </h1>
                <p class="mt-1 text-sm text-gray-600">
                    <?php echo htmlspecialchars($assignment['subject'] ?? ''); ?> - 
                    Grade <?php echo htmlspecialchars($assignment['grade_level'] ?? ''); ?>
                    <?php echo htmlspecialchars($assignment['section'] ?? ''); ?>
                </p>
                <p class="text-sm text-gray-500 mt-1">
                    Due: <?php echo !empty($assignment['due_date']) ? date('F j, Y', strtotime($assignment['due_date'])) : 'No due date'; ?>
                    <?php if (!empty($assignment['max_score'])): ?>
                        • Max Score: <?php echo htmlspecialchars($assignment['max_score']); ?>
                    <?php endif; ?>
                </p>
            </div>
            <div class="mt-4 flex md:mt-0 md:ml-4
                <button type="button" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-download mr-2"></i> Export Grades
                </button>
            </div>
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

        <form method="POST" action="">
            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <div class="px-4 py-5 sm:px-6 border-b border-gray-200">
                    <h3 class="text-lg leading-6 font-medium text-gray-900">
                        Student Submissions
                    </h3>
                    <p class="mt-1 max-w-2xl text-sm text-gray-500">
                        Review and grade student submissions
                    </p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Submitted</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Feedback</th>
                                <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (!empty($submissions)): ?>
                                <?php foreach ($submissions as $submission): 
                                    $status_class = [
                                        'submitted' => 'bg-blue-100 text-blue-800',
                                        'graded' => 'bg-green-100 text-green-800',
                                        'late' => 'bg-yellow-100 text-yellow-800',
                                        'missing' => 'bg-red-100 text-red-800'
                                    ][$submission['status']] ?? 'bg-gray-100 text-gray-800';
                                    
                                    $status_text = ucfirst($submission['status']);
                                    $submitted_at = !empty($submission['submitted_at']) ? 
                                        date('M j, Y g:i A', strtotime($submission['submitted_at'])) : 'Not submitted';
                                    
                                    // Calculate if submission is late
                                    $is_late = false;
                                    if (!empty($submission['submitted_at']) && !empty($assignment['due_date'])) {
                                        $submitted_date = new DateTime($submission['submitted_at']);
                                        $due_date = new DateTime($assignment['due_date']);
                                        $is_late = $submitted_date > $due_date;
                                        
                                        if ($is_late && $submission['status'] !== 'graded') {
                                            $status_class = 'bg-yellow-100 text-yellow-800';
                                            $status_text = 'Late';
                                        }
                                    }
                                ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-medium">
                                                    <?php 
                                                        $name_parts = explode(' ', $submission['student_name']);
                                                        $initials = '';
                                                        foreach ($name_parts as $part) {
                                                            $initials .= strtoupper(substr($part, 0, 1));
                                                        }
                                                        echo substr($initials, 0, 2);
                                                    ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($submission['student_name']); ?></div>
                                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($submission['student_id']); ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 text-center">
                                            <?php echo $submitted_at; ?>
                                            <?php if ($is_late): ?>
                                                <span class="block text-xs text-yellow-600">(Late)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center justify-center">
                                                <input type="number" 
                                                       name="grades[<?php echo $submission['id'] ?? 'new_' . $submission['student_id']; ?>][score]" 
                                                       value="<?php echo htmlspecialchars($submission['score'] ?? ''); ?>" 
                                                       min="0" 
                                                       max="<?php echo htmlspecialchars($assignment['max_score'] ?? '100'); ?>" 
                                                       step="0.1" 
                                                       class="w-20 px-2 py-1 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                       placeholder="0-<?php echo htmlspecialchars($assignment['max_score'] ?? '100'); ?>"
                                                       onchange="updateStatus(this, '<?php echo $submission['id'] ?? 'new_' . $submission['student_id']; ?>')">
                                                <?php if (!empty($assignment['max_score'])): ?>
                                                    <span class="ml-1 text-sm text-gray-500">/ <?php echo htmlspecialchars($assignment['max_score']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <input type="text" 
                                                   name="grades[<?php echo $submission['id'] ?? 'new_' . $submission['student_id']; ?>][feedback]" 
                                                   value="<?php echo htmlspecialchars($submission['feedback'] ?? ''); ?>" 
                                                   class="w-full px-3 py-1 border border-gray-300 rounded-md shadow-sm focus:ring-blue-500 focus:border-blue-500 sm:text-sm"
                                                   placeholder="Add feedback">
                                            <input type="hidden" 
                                                   name="grades[<?php echo $submission['id'] ?? 'new_' . $submission['student_id']; ?>][status]" 
                                                   id="status_<?php echo $submission['id'] ?? 'new_' . $submission['student_id']; ?>" 
                                                   value="<?php echo htmlspecialchars($submission['status']); ?>">
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <?php if (!empty($submission['file_path'])): ?>
                                                <a href="<?php echo htmlspecialchars($submission['file_path']); ?>" 
                                                   target="_blank" 
                                                   class="text-blue-600 hover:text-blue-900 mr-3"
                                                   title="View Submission">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="<?php echo htmlspecialchars($submission['file_path']); ?>" 
                                                   download 
                                                   class="text-green-600 hover:text-green-900"
                                                   title="Download Submission">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-gray-400" title="No submission">
                                                    <i class="fas fa-file"></i>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                        No students found in this class.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="px-4 py-3 bg-gray-50 text-right sm:px-6">
                    <button type="submit" name="save_grades" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Save All Grades
                    </button>
                </div>
            </div>
        </form>
    </main>

    <script>
        // Update status when score is entered
        function updateStatus(input, submissionId) {
            const statusInput = document.getElementById('status_' + submissionId);
            if (input.value.trim() !== '') {
                statusInput.value = 'graded';
            } else {
                statusInput.value = 'submitted';
            }
        }

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
