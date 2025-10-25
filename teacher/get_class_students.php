<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connections
require_once '../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'teacher') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Get class ID from query parameter
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

if (!$class_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Class ID is required']);
    exit;
}

try {
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    $registrar_conn = $database->getConnection('registrar');
    
    // Get students enrolled in the class from registrar_db
    $query = "
        SELECT s.student_id, s.first_name, s.last_name, s.middle_name, s.grade_level, s.section, s.email, s.contact_number
        FROM registrar_db.students s
        JOIN teacher_db.class_students cs ON s.student_id = cs.student_id
        WHERE cs.class_id = ? AND cs.status = 'active' AND s.status = 'Active'
        ORDER BY s.last_name, s.first_name
    ";
    
    $stmt = $registrar_conn->prepare($query);
    $stmt->execute([$class_id]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'students' => $students
    ]);
    
} catch (Exception $e) {
    error_log('Error in get_class_students.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching students',
        'error' => $e->getMessage()
    ]);
}
