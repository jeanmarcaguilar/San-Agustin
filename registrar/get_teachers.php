<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

try {
    $database = new Database();
    $pdo = $database->getConnection('registrar');
    
    // Get all teachers from teacher_db
    $teacher_conn = $database->getConnection('teacher');
    
    $stmt = $teacher_conn->query("
        SELECT 
            teacher_id as id,
            CONCAT(first_name, ' ', last_name) as name,
            email,
            subject
        FROM teachers
        ORDER BY first_name, last_name
    ");
    
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($teachers);
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
