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
    $id = $_POST['id'] ?? '';
    $grade_level = $_POST['grade_level'] ?? '';
    $section = $_POST['section'] ?? '';
    $subject = $_POST['subject'] ?? '';
    $teacher_id = $_POST['teacher_id'] ?? '';
    $day_of_week = $_POST['day_of_week'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $room = $_POST['room'] ?? '';
    $school_year = $_POST['school_year'] ?? '';
    
    // Validate required fields
    if (empty($id) || empty($grade_level) || empty($section) || empty($subject) || empty($teacher_id) || 
        empty($day_of_week) || empty($start_time) || empty($end_time) || empty($school_year)) {
        echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
        exit();
    }
    
    // Check for schedule conflicts (excluding current schedule)
    $stmt = $pdo->prepare("
        SELECT * FROM class_schedules 
        WHERE id != :id
        AND teacher_id = :teacher_id 
        AND day_of_week = :day_of_week 
        AND school_year = :school_year
        AND (
            (start_time <= :start_time1 AND end_time > :start_time2) OR
            (start_time < :end_time1 AND end_time >= :end_time2) OR
            (start_time >= :start_time3 AND end_time <= :end_time3)
        )
    ");
    
    $stmt->execute([
        ':id' => $id,
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
    
    // Update schedule
    $stmt = $pdo->prepare("
        UPDATE class_schedules 
        SET grade_level = :grade_level,
            section = :section,
            subject = :subject,
            teacher_id = :teacher_id,
            day_of_week = :day_of_week,
            start_time = :start_time,
            end_time = :end_time,
            room = :room,
            school_year = :school_year,
            updated_at = NOW()
        WHERE id = :id
    ");
    
    $result = $stmt->execute([
        ':id' => $id,
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
        echo json_encode(['success' => true, 'message' => 'Schedule updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update schedule']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
