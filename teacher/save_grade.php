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

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Validate required fields
    if (empty($data['student_id']) || empty($data['class_id']) || empty($data['type']) || !isset($data['score'])) {
        throw new Exception('Missing required fields.');
    }
    
    $student_id = (int)$data['student_id'];
    $class_id = (int)$data['class_id'];
    $assessment_type = $data['type'];
    $score = (float)$data['score'];
    $notes = $data['notes'] ?? '';
    $title = $data['title'] ?? ucfirst($assessment_type);
    $grading_period = $data['grading_period'] ?? '1st Quarter';
    $max_score = $data['max_score'] ?? 100;
    $grade_date = $data['grade_date'] ?? date('Y-m-d');
    
    // Validate score
    if ($score < 0 || $score > $max_score) {
        throw new Exception('Score must be between 0 and ' . $max_score);
    }
    
    // Include database connections
    require_once '../config/database.php';
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    $student_conn = $database->getConnection('student');
    
    if (!$teacher_conn) {
        throw new Exception('Failed to connect to teacher database');
    }
    
    // Get teacher info
    $stmt = $teacher_conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$teacher) {
        throw new Exception('Teacher record not found.');
    }
    
    $teacher_id = $teacher['teacher_id'];
    
    // Get student info
    $stmt = $teacher_conn->prepare("SELECT student_id FROM students WHERE id = ?");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Student not found.');
    }
    
    $student_id_string = $student['student_id'];
    
    // Get class info
    $stmt = $teacher_conn->prepare("SELECT subject, grade_level, section FROM classes WHERE id = ?");
    $stmt->execute([$class_id]);
    $class_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$class_info) {
        throw new Exception('Class not found.');
    }
    
    // Begin transaction
    $teacher_conn->beginTransaction();
    
    // Insert grade into teacher database
    $stmt = $teacher_conn->prepare("
        INSERT INTO grades 
        (student_id, class_id, grading_period, assessment_type, title, score, max_score, grade_date, notes, recorded_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    
    $stmt->execute([
        $student_id_string,
        $class_id,
        $grading_period,
        $assessment_type,
        $title,
        $score,
        $max_score,
        $grade_date,
        $notes,
        $teacher_id
    ]);
    
    $grade_id = $teacher_conn->lastInsertId();
    
    // Sync to student database
    if ($student_conn) {
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
        $stmt->execute([$student_id_string, $class_id, $grading_period]);
        $avg_result = $stmt->fetch(PDO::FETCH_ASSOC);
        $avg_grade = $avg_result['avg_grade'] ?? 0;
        
        // Check if student exists in student database
        $stmt = $student_conn->prepare("SELECT id FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
        $student_exists = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student_exists) {
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
                $student_id,
                $class_info['subject'],
                $class_info['grade_level'],
                $quarter,
                $avg_grade,
                $remarks
            ]);
        }
    }
    
    // Commit transaction
    $teacher_conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Grade saved successfully.',
        'grade_id' => $grade_id
    ]);
    
} catch (Exception $e) {
    // Rollback transaction on error
    if (isset($teacher_conn) && $teacher_conn->inTransaction()) {
        $teacher_conn->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saving grade: ' . $e->getMessage()
    ]);
}
