<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');

try {
    // Get form data
    $section_id = isset($_POST['section_id']) && !empty($_POST['section_id']) ? (int)$_POST['section_id'] : null;
    $grade_level = isset($_POST['grade_level']) ? (int)$_POST['grade_level'] : null;
    $section = isset($_POST['section']) ? trim($_POST['section']) : null;
    $room_number = isset($_POST['room_number']) ? trim($_POST['room_number']) : null;
    $adviser_id = isset($_POST['adviser_id']) && !empty($_POST['adviser_id']) ? trim($_POST['adviser_id']) : null;
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'active';
    $school_year = isset($_POST['school_year']) ? trim($_POST['school_year']) : date('Y') . '-' . (date('Y') + 1);

    // Validate input
    if (!$grade_level || !$section || !in_array($status, ['active', 'inactive'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid input data']);
        exit();
    }

    // Check if section already exists (for new sections or if updating to a different section name)
    $query = "SELECT COUNT(*) as count FROM class_sections WHERE grade_level = :grade_level AND section = :section";
    if ($section_id) {
        $query .= " AND id != :section_id";
    }
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':grade_level', $grade_level, PDO::PARAM_INT);
    $stmt->bindParam(':section', $section, PDO::PARAM_STR);
    if ($section_id) {
        $stmt->bindParam(':section_id', $section_id, PDO::PARAM_INT);
    }
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'A section with this grade level and name already exists']);
        exit();
    }

    if ($section_id) {
        // Update existing section
        $query = "UPDATE class_sections SET grade_level = :grade_level, section = :section, room_number = :room_number, 
                  adviser_id = :adviser_id, school_year = :school_year, status = :status, updated_at = NOW() WHERE id = :section_id";
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':section_id', $section_id, PDO::PARAM_INT);
    } else {
        // Insert new section
        $query = "INSERT INTO class_sections (grade_level, section, room_number, adviser_id, school_year, status, created_at, updated_at) 
                  VALUES (:grade_level, :section, :room_number, :adviser_id, :school_year, :status, NOW(), NOW())";
        $stmt = $pdo->prepare($query);
    }

    // Bind parameters
    $stmt->bindParam(':grade_level', $grade_level, PDO::PARAM_INT);
    $stmt->bindParam(':section', $section, PDO::PARAM_STR);
    $stmt->bindParam(':room_number', $room_number, PDO::PARAM_STR);
    $stmt->bindParam(':adviser_id', $adviser_id, PDO::PARAM_STR);
    $stmt->bindParam(':school_year', $school_year, PDO::PARAM_STR);
    $stmt->bindParam(':status', $status, PDO::PARAM_STR);

    // Execute the query
    if ($stmt->execute()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'message' => $section_id ? 'Section updated successfully' : 'Section added successfully']);
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Failed to save section']);
    }
} catch (PDOException $e) {
    error_log("Error saving section: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("General error: " . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'An unexpected error occurred']);
}
?>