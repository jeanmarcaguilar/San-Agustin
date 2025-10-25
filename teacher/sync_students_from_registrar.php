<?php
/**
 * Sync Students from Registrar Database to Teacher View
 * This ensures teachers see the latest student data from registrar
 */

session_start();

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../config/database.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $registrar_conn = $database->getConnection('registrar');
    $teacher_conn = $database->getConnection('teacher');
    
    if (!$registrar_conn || !$teacher_conn) {
        throw new Exception('Failed to connect to databases');
    }
    
    // Get teacher ID
    $stmt = $teacher_conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$teacher) {
        throw new Exception('Teacher record not found');
    }
    
    $teacher_id = $teacher['teacher_id'];
    
    // Fetch all active students from registrar database
    $stmt = $registrar_conn->query("
        SELECT 
            student_id,
            first_name,
            last_name,
            middle_name,
            email,
            contact_number,
            grade_level,
            section,
            birthdate,
            gender,
            address,
            guardian_name,
            guardian_contact,
            lrn,
            status,
            school_year
        FROM students 
        WHERE status = 'Active'
        ORDER BY grade_level, section, last_name, first_name
    ");
    
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_students = count($students);
    
    // Get teacher's classes to determine which students are enrolled
    $stmt = $teacher_conn->prepare("
        SELECT c.id, c.grade_level, c.section, c.subject
        FROM classes c
        WHERE c.teacher_id = ?
    ");
    $stmt->execute([$teacher_id]);
    $teacher_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get currently enrolled students in teacher's classes
    $enrolled_students = [];
    foreach ($teacher_classes as $class) {
        $stmt = $teacher_conn->prepare("
            SELECT student_id 
            FROM class_students 
            WHERE class_id = ? AND status = 'active'
        ");
        $stmt->execute([$class['id']]);
        $class_students = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $enrolled_students = array_merge($enrolled_students, $class_students);
    }
    $enrolled_students = array_unique($enrolled_students);
    
    // Categorize students
    $available_students = [];
    $enrolled_in_my_classes = [];
    $by_grade = [];
    
    foreach ($students as $student) {
        $student_id = $student['student_id'];
        $grade = $student['grade_level'];
        
        // Add to grade grouping
        if (!isset($by_grade[$grade])) {
            $by_grade[$grade] = [];
        }
        $by_grade[$grade][] = $student;
        
        // Check if enrolled in teacher's classes
        if (in_array($student_id, $enrolled_students)) {
            $enrolled_in_my_classes[] = $student;
        } else {
            $available_students[] = $student;
        }
    }
    
    // Get statistics
    $stats = [
        'total_students' => $total_students,
        'enrolled_in_my_classes' => count($enrolled_in_my_classes),
        'available_students' => count($available_students),
        'by_grade' => array_map('count', $by_grade),
        'teacher_classes' => count($teacher_classes)
    ];
    
    // Return comprehensive data
    echo json_encode([
        'success' => true,
        'message' => "Successfully synced {$total_students} students from registrar",
        'data' => [
            'all_students' => $students,
            'enrolled_in_my_classes' => $enrolled_in_my_classes,
            'available_students' => $available_students,
            'by_grade' => $by_grade,
            'teacher_classes' => $teacher_classes,
            'stats' => $stats
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log('Error in sync_students_from_registrar.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error syncing students: ' . $e->getMessage()
    ]);
}
?>
