<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database configuration
require_once '../config/database.php';

try {
    // Get section ID
    $section_id = isset($_POST['section_id']) ? (int)$_POST['section_id'] : null;
    
    if (!$section_id) {
        echo json_encode(['success' => false, 'message' => 'Section ID is required']);
        exit();
    }
    
    // Initialize database connection
    $database = new Database();
    $registrar_conn = $database->getConnection('registrar');
    
    // Check if section exists
    $stmt = $registrar_conn->prepare("SELECT * FROM class_sections WHERE id = ?");
    $stmt->execute([$section_id]);
    $section = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$section) {
        echo json_encode(['success' => false, 'message' => 'Section not found']);
        exit();
    }
    
    // Check if there are students in this section
    $stmt = $registrar_conn->prepare("
        SELECT COUNT(*) as count FROM students 
        WHERE grade_level = ? AND section = ?
    ");
    $stmt->execute([$section['grade_level'], $section['section']]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'Cannot delete section with enrolled students. Please reassign students first.'
        ]);
        exit();
    }
    
    // Delete the section
    $stmt = $registrar_conn->prepare("DELETE FROM class_sections WHERE id = ?");
    
    if ($stmt->execute([$section_id])) {
        echo json_encode([
            'success' => true, 
            'message' => 'Section deleted successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to delete section'
        ]);
    }
    
} catch (PDOException $e) {
    error_log("Error deleting section: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    error_log("General error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'An unexpected error occurred'
    ]);
}
?>
