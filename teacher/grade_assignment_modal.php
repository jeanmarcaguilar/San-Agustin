<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo 'Please log in first.';
    exit;
}

if (strtolower($_SESSION['role']) !== 'teacher') {
    http_response_code(403);
    echo 'Access denied. Teacher access only.';
    exit;
}

// Check if assignment ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    echo 'Invalid assignment ID.';
    exit;
}

$assignment_id = (int)$_GET['id'];
$error_messages = [];
$assignment = null;
$submissions = [];

// Include database connections
require_once '../config/database.php';
$database = new Database();
$teacher_conn = null;

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
    http_response_code(500);
    echo 'Error: ' . htmlspecialchars($e->getMessage());
    exit;
}
?>

<form id="gradeAssignmentForm">
    <input type="hidden" name="assignment_id" value="<?php echo htmlspecialchars($assignment_id); ?>">
    
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Submitted</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Feedback</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
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
            </tbody>
        </table>
    </div>
</form>
