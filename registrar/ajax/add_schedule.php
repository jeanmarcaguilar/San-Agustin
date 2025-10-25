<?php
session_start();
require_once '../../config/database.php';

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

try {
    $database = new Database();
    $pdo = $database->getConnection('registrar');
    
    // Get form data
    $grade_level = $_POST['grade_level'] ?? '';
    $section = $_POST['section'] ?? '';
    
    // If class_section is provided instead, parse it
    if (empty($grade_level) || empty($section)) {
        $class_section = $_POST['class_section'] ?? '';
        if (!empty($class_section) && strpos($class_section, '|') !== false) {
            list($grade_level, $section) = explode('|', $class_section, 2);
        }
    }
    
    $subject = $_POST['subject'] ?? '';
    $teacher_id_string = $_POST['teacher_id'] ?? '';
    $day_of_week = $_POST['day_of_week'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $room = $_POST['room'] ?? '';
    $school_year = $_POST['school_year'] ?? '';
    
    // Validate required fields
    if (empty($grade_level) || empty($section) || empty($subject) || empty($teacher_id_string) || 
        empty($day_of_week) || empty($start_time) || empty($end_time) || empty($school_year)) {
        echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
        exit();
    }
    
    // Get or create teacher in registrar_db
    // First, get teacher info from teacher_db
    $teacher_conn = $database->getConnection('teacher');
    $stmt_teacher = $teacher_conn->prepare("SELECT * FROM teachers WHERE teacher_id = :teacher_id");
    $stmt_teacher->execute([':teacher_id' => $teacher_id_string]);
    $teacher_info = $stmt_teacher->fetch(PDO::FETCH_ASSOC);
    
    if (!$teacher_info) {
        echo json_encode(['success' => false, 'message' => 'Teacher not found']);
        exit();
    }
    
    // Check if teacher exists in registrar_db
    $stmt_check = $pdo->prepare("SELECT id FROM teachers WHERE teacher_id = :teacher_id");
    $stmt_check->execute([':teacher_id' => $teacher_id_string]);
    $existing_teacher = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_teacher) {
        // Teacher exists, use their id
        $teacher_id = $existing_teacher['id'];
    } else {
        // Teacher doesn't exist, create them in registrar_db
        $stmt_insert = $pdo->prepare("
            INSERT INTO teachers (user_id, teacher_id, first_name, middle_name, last_name, email, phone, created_at, updated_at)
            VALUES (:user_id, :teacher_id, :first_name, :middle_name, :last_name, :email, :phone, NOW(), NOW())
        ");
        
        $stmt_insert->execute([
            ':user_id' => $teacher_info['user_id'] ?? 0,
            ':teacher_id' => $teacher_info['teacher_id'],
            ':first_name' => $teacher_info['first_name'],
            ':middle_name' => $teacher_info['middle_name'] ?? '',
            ':last_name' => $teacher_info['last_name'],
            ':email' => $teacher_info['email'] ?? '',
            ':phone' => $teacher_info['phone'] ?? ''
        ]);
        
        $teacher_id = $pdo->lastInsertId();
    }
    
    // Check for schedule conflicts (same teacher, day, and overlapping time)
    $stmt = $pdo->prepare("
        SELECT * FROM class_schedules 
        WHERE teacher_id = :teacher_id 
        AND day_of_week = :day_of_week 
        AND school_year = :school_year
        AND (
            (start_time <= :start_time1 AND end_time > :start_time2) OR
            (start_time < :end_time1 AND end_time >= :end_time2) OR
            (start_time >= :start_time3 AND end_time <= :end_time3)
        )
    ");
    
    $stmt->execute([
        ':teacher_id' => $teacher_id,
        ':day_of_week' => $day_of_week,
        ':school_year' => $school_year,
        ':start_time1' => $start_time,
        ':start_time2' => $start_time,
        ':start_time3' => $start_time,
        ':end_time1' => $end_time,
        ':end_time2' => $end_time,
        ':end_time3' => $end_time
    ]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Schedule conflict: Teacher already has a class at this time']);
        exit();
    }
    
    // Insert new schedule
    $stmt = $pdo->prepare("
        INSERT INTO class_schedules 
        (grade_level, section, subject, teacher_id, day_of_week, start_time, end_time, room, school_year, status, created_at, updated_at) 
        VALUES 
        (:grade_level, :section, :subject, :teacher_id, :day_of_week, :start_time, :end_time, :room, :school_year, 'active', NOW(), NOW())
    ");
    
    $result = $stmt->execute([
        ':grade_level' => $grade_level,
        ':section' => $section,
        ':subject' => $subject,
        ':teacher_id' => $teacher_id,
        ':day_of_week' => $day_of_week,
        ':start_time' => $start_time,
        ':end_time' => $end_time,
        ':room' => $room,
        ':school_year' => $school_year
    ]);
    
    if ($result) {
        echo json_encode([
            'success' => true, 
            'message' => 'Schedule added successfully',
            'schedule_id' => $pdo->lastInsertId()
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add schedule']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
