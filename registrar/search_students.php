<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Include database configuration
require_once '../config/database.php';

// Get search query
$search = $_GET['search'] ?? '';

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');

try {
    // Build the query
    $query = "SELECT s.*, u.email, u.username 
              FROM students s 
              LEFT JOIN login_db.users u ON s.user_id = u.id 
              WHERE 1=1";
    $params = [];

    if (!empty($search)) {
        $query .= " AND (s.first_name LIKE :search OR s.last_name LIKE :search OR s.student_id LIKE :search OR s.lrn LIKE :search OR u.email LIKE :search OR u.username LIKE :search)";
        $params[':search'] = "%$search%";
    }

    // Add sorting
    $query .= " ORDER BY s.last_name, s.first_name";

    // Prepare and execute the query
    $stmt = $pdo->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Return the results
    echo json_encode([
        'success' => true,
        'students' => $students,
        'message' => count($students) . ' students found',
        'search_term' => $search
    ]);

} catch (Exception $e) {
    error_log("Search error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'An error occurred while searching',
        'message' => $e->getMessage()
    ]);
}
