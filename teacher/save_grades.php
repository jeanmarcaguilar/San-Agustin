<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set JSON content type
header('Content-Type: application/json');

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please log in first.']);
    exit;
}

if (strtolower($_SESSION['role']) !== 'teacher') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Teacher access only.']);
    exit;
}

// Check if required data is provided
if (!isset($_POST['assignment_id']) || !is_numeric($_POST['assignment_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid assignment ID.']);
    exit;
}

$assignment_id = (int)$_POST['assignment_id'];
$grades = $_POST['grades'] ?? [];

try {
    // Include database connections
    require_once '../config/database.php';
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    
    if (!$teacher_conn) {
        throw new Exception('Failed to connect to teacher database');
    }
    
    // Verify the assignment belongs to the teacher
    $stmt = $teacher_conn->prepare("
        SELECT id FROM assignments 
        WHERE id = ? AND teacher_id = ?
    ");
    
    $stmt->execute([$assignment_id, $_SESSION['user_id']]);
    $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$assignment) {
        throw new Exception('Assignment not found or access denied.');
    }
    
    // Begin transaction
    $teacher_conn->beginTransaction();
    
    // Process each grade submission
    foreach ($grades as $submission_id => $grade_data) {
        $score = !empty($grade_data['score']) ? (float)$grade_data['score'] : null;
        $feedback = $grade_data['feedback'] ?? '';
        $status = $grade_data['status'] ?? 'submitted';
        
        // Check if this is a new submission (starts with 'new_')
        if (strpos($submission_id, 'new_') === 0) {
            // Extract student ID from the submission ID (format: new_123)
            $student_id = (int)substr($submission_id, 4);
            
            // Insert new submission record
            $stmt = $teacher_conn->prepare("
                INSERT INTO assignment_submissions 
                (assignment_id, student_id, score, feedback, status, graded_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $assignment_id,
                $student_id,
                $score,
                $feedback,
                $status
            ]);
        } else {
            // Update existing submission
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
    }
    
    // Commit transaction
    $teacher_conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Grades saved successfully.'
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($teacher_conn) && $teacher_conn->inTransaction()) {
        $teacher_conn->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saving grades: ' . $e->getMessage()
    ]);
}
