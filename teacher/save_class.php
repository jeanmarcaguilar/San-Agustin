<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'teacher') {
    $_SESSION['error'] = 'Access denied. Teacher access only.';
    header('Location: ../login.php');
    exit;
}

require_once '../config/database.php';

try {
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    
    // Get teacher info
    $stmt = $teacher_conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$teacher) {
        throw new Exception('Teacher record not found');
    }
    
    $teacher_id = $teacher['teacher_id'];
    
    // Check if this is an update or insert
    if (!empty($_POST['id'])) {
        // Update existing class
        $stmt = $teacher_conn->prepare("
            UPDATE classes SET
                subject = ?,
                grade_level = ?,
                section = ?,
                schedule = ?,
                room = ?,
                updated_at = NOW()
            WHERE id = ? AND teacher_id = ?
        ");
        
        $stmt->execute([
            $_POST['subject'],
            $_POST['grade_level'],
            $_POST['section'],
            $_POST['schedule'],
            $_POST['room'],
            $_POST['id'],
            $teacher_id
        ]);
        
        $_SESSION['success'] = 'Class updated successfully!';
    } else {
        // Insert new class
        $stmt = $teacher_conn->prepare("
            INSERT INTO classes (teacher_id, subject, grade_level, section, schedule, room, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $teacher_id,
            $_POST['subject'],
            $_POST['grade_level'],
            $_POST['section'],
            $_POST['schedule'],
            $_POST['room']
        ]);
        
        $_SESSION['success'] = 'Class added successfully!';
    }
    
    header('Location: classes.php');
    exit;
    
} catch (Exception $e) {
    $_SESSION['error'] = 'Error saving class: ' . $e->getMessage();
    header('Location: classes.php');
    exit;
}
?>
