<?php
require_once '../config/database.php';

// Set headers for JSON response
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

// Get the grade level from the query string
$grade = isset($_GET['grade']) ? (int)$_GET['grade'] : 0;

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'data' => []
];

try {
    // Validate grade level
    if ($grade < 1 || $grade > 12) {
        throw new Exception('Invalid grade level');
    }
    
    // Initialize database connection
    $database = new Database();
    $pdo = $database->getConnection('registrar');
    
    // Prepare and execute the query
    $query = "SELECT DISTINCT section FROM class_sections 
              WHERE grade_level = :grade_level 
              AND status = 'active' 
              ORDER BY section";
    
    $stmt = $pdo->prepare($query);
    $stmt->bindParam(':grade_level', $grade, PDO::PARAM_INT);
    $stmt->execute();
    
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare the response
    $response['success'] = true;
    $response['data'] = $sections;
    
} catch (Exception $e) {
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

// Return the JSON response
echo json_encode($response);
