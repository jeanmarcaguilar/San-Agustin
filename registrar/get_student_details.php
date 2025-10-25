<?php
session_start();

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['error' => 'Unauthorized access']);
    exit();
}

// Include database configuration
require_once '../config/database.php';

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');

// Get student ID from request
$student_id = $_GET['id'] ?? 0;

if (!$student_id) {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'Student ID is required']);
    exit();
}

try {
    // Prepare and execute query to get student details
    $query = "SELECT s.*, u.email, u.username 
              FROM students s 
              LEFT JOIN login_db.users u ON s.user_id = u.id 
              WHERE s.id = :id";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        header('HTTP/1.1 404 Not Found');
        echo json_encode(['error' => 'Student not found']);
        exit();
    }
    
    // Return student data as JSON
    header('Content-Type: application/json');
    echo json_encode($student);
    
} catch (PDOException $e) {
    // Log the error
    error_log("Error fetching student details: " . $e->getMessage());
    
    // Return error response
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Failed to fetch student details']);
}
?>
