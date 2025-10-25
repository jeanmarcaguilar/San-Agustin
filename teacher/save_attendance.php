<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connections
require_once '../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if request is AJAX
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'teacher') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in again.']);
    exit;
}

// Get the JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate input
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid JSON data: ' . json_last_error_msg()
    ]);
    exit;
}

// Validate required fields
$required_fields = ['class_id', 'date', 'attendance'];
foreach ($required_fields as $field) {
    if (!isset($data[$field]) || (is_array($data[$field]) && empty($data[$field]))) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Missing required field: ' . $field,
            'data' => $data
        ]);
        exit;
    }
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['date'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid date format. Use YYYY-MM-DD.'
    ]);
    exit;
}

// Validate class_id
if (!is_numeric($data['class_id'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid class ID.'
    ]);
    exit;
}

try {
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    
    // Get teacher ID from session
    $teacher_id = $_SESSION['user_id'];
    $recorded_by = $data['recorded_by'] ?? $teacher_id;
    $attendance_date = $data['date'];
    $class_id = $data['class_id'];
    
    // Verify the teacher has access to this class
    $stmt = $teacher_conn->prepare("SELECT id FROM classes WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$class_id, $teacher_id]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('You do not have permission to take attendance for this class.');
    }
    
    // Start transaction
    $teacher_conn->beginTransaction();
    
    // Prepare insert/update statement with additional validation
    $upsert_query = "
        INSERT INTO attendance 
        (class_id, student_id, date, status, notes, recorded_by, recorded_at) 
        SELECT :class_id, s.id, :date, :status, :notes, :recorded_by, NOW()
        FROM students s
        INNER JOIN class_students cs ON s.id = cs.student_id
        WHERE s.id = :student_id 
        AND cs.class_id = :class_id
        AND cs.status = 'active'
        ON DUPLICATE KEY UPDATE 
            status = VALUES(status),
            notes = COALESCE(VALUES(notes), notes),
            recorded_by = VALUES(recorded_by),
            recorded_at = NOW()
    ";
    
    $stmt = $teacher_conn->prepare($upsert_query);
    $success_count = 0;
    $error_messages = [];
    $valid_statuses = ['present', 'absent', 'late', 'excused'];
    
    foreach ($data['attendance'] as $record) {
        try {
            // Validate student record
            if (empty($record['student_id'])) {
                throw new Exception('Missing student ID');
            }
            
            // Validate status
            $status = strtolower(trim($record['status']));
            if (!in_array($status, $valid_statuses)) {
                throw new Exception("Invalid status: {$record['status']}");
            }
            
            // Clean notes
            $notes = !empty($record['notes']) ? substr(trim($record['notes']), 0, 500) : null;
            
            $stmt->execute([
                ':class_id' => $class_id,
                ':student_id' => $record['student_id'],
                ':date' => $attendance_date,
                ':status' => $status,
                ':notes' => $notes,
                ':recorded_by' => $recorded_by
            ]);
            
            if ($stmt->rowCount() > 0) {
                $success_count++;
            } else {
                throw new Exception('Student not found in this class or inactive');
            }
            
        } catch (PDOException $e) {
            $error_code = $e->errorInfo[1] ?? 0;
            $error_msg = $e->getMessage();
            
            // Handle specific database errors
            if ($error_code == 1452) { // Foreign key constraint
                $error_msg = 'Invalid student or class reference';
            }
            
            error_log(sprintf(
                'Error saving attendance for student %s: %s', 
                $record['student_id'] ?? 'unknown', 
                $error_msg
            ));
            
            $error_messages[] = [
                'student_id' => $record['student_id'] ?? 'unknown',
                'error' => $error_msg
            ];
        } catch (Exception $e) {
            error_log(sprintf(
                'Validation error for student %s: %s', 
                $record['student_id'] ?? 'unknown', 
                $e->getMessage()
            ));
            
            $error_messages[] = [
                'student_id' => $record['student_id'] ?? 'unknown',
                'error' => $e->getMessage()
            ];
        }
    }
    
    if ($success_count === 0 && !empty($error_messages)) {
        // If all records failed, rollback and return error
        $teacher_conn->rollBack();
        
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to save attendance for all students',
            'errors' => $error_messages
        ]);
    } else {
        // Commit transaction
        $teacher_conn->commit();
        
        // Prepare the response array
        $response = [
            'success' => true,
            'message' => "Attendance recorded for $success_count students",
            'saved' => $success_count,
            'date' => $attendance_date,
            'class_id' => $class_id
        ];
        
        // Add warning if there were any errors
        if (!empty($error_messages)) {
            $response['warning'] = count($error_messages) . ' records had errors';
            $response['errors'] = $error_messages;
        }
        
        echo json_encode($response);
    }
    
} catch (PDOException $e) {
    if (isset($teacher_conn) && $teacher_conn->inTransaction()) {
        $teacher_conn->rollBack();
    }
    
    error_log("Database error in save_attendance.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'A database error occurred',
        'error' => $e->getMessage(),
        'code' => $e->getCode()
    ]);
    
} catch (Exception $e) {
    if (isset($teacher_conn) && $teacher_conn->inTransaction()) {
        $teacher_conn->rollBack();
    }
    
    error_log("Error in save_attendance.php: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
