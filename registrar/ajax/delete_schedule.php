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
    
    // Get schedule ID
    $id = $_POST['id'] ?? '';
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Schedule ID is required']);
        exit();
    }
    
    // Delete the schedule
    $stmt = $pdo->prepare("DELETE FROM class_schedules WHERE id = :id");
    $result = $stmt->execute([':id' => $id]);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Schedule deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete schedule']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
