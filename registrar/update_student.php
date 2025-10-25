<?php
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// Include database configuration
require_once '../config/database.php';

// Get POST data
$student_id = $_POST['id'] ?? 0;

if (!$student_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Student ID is required']);
    exit();
}

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');

try {
    // Begin transaction
    $pdo->beginTransaction();
    
    // Prepare the update query
    $update_fields = [
        'first_name' => $_POST['first_name'] ?? null,
        'middle_name' => !empty($_POST['middle_name']) ? $_POST['middle_name'] : null,
        'last_name' => $_POST['last_name'] ?? null,
        'birthdate' => !empty($_POST['birthdate']) ? $_POST['birthdate'] : null,
        'gender' => $_POST['gender'] ?? null,
        'address' => $_POST['address'] ?? null,
        'email' => $_POST['email'] ?? null,
        'contact_number' => $_POST['contact_number'] ?? null,
        'grade_level' => $_POST['grade_level'] ?? null,
        'section' => $_POST['section'] ?? null,
        'lrn' => $_POST['lrn'] ?? null,
        'guardian_name' => $_POST['guardian_name'] ?? null,
        'guardian_contact' => $_POST['guardian_contact'] ?? null,
        'guardian_relationship' => $_POST['guardian_relationship'] ?? null,
        'status' => $_POST['status'] ?? 'Active', // Default to Active if not provided
        'updated_at' => date('Y-m-d H:i:s')
    ];
    
    // Build the SET clause for the SQL query
    $set_parts = [];
    $params = [':id' => $student_id];
    
    foreach ($update_fields as $field => $value) {
        $param = ":$field";
        $set_parts[] = "$field = $param";
        $params[$param] = $value;
    }
    
    $set_clause = implode(', ', $set_parts);
    
    // Prepare and execute the update query
    $query = "UPDATE students SET $set_clause WHERE id = :id";
    $stmt = $pdo->prepare($query);
    
    if (!$stmt->execute($params)) {
        throw new Exception('Failed to update student information');
    }
    
    // Update the login email if it exists
    if (!empty($_POST['email'])) {
        try {
            $login_db = $database->getConnection('login');
            $stmt = $login_db->prepare("UPDATE users SET email = :email WHERE id = (SELECT user_id FROM students WHERE id = :student_id)");
            $stmt->execute([
                ':email' => $_POST['email'],
                ':student_id' => $student_id
            ]);
        } catch (Exception $e) {
            // Log the error but don't fail the entire update
            error_log("Error updating login email: " . $e->getMessage());
        }
    }
    
    // Commit the transaction
    $pdo->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Student information updated successfully'
    ]);
    
} catch (Exception $e) {
    // Rollback the transaction on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log the error
    error_log("Error in update_student.php: " . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to update student information: ' . $e->getMessage()
    ]);
}
?>
