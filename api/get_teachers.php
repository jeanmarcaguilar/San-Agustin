<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
header('Content-Type: application/json');

// Check auth
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    
    $subject = $_GET['subject'] ?? null;
    
    $query = "SELECT teacher_id, first_name, last_name, email, subject, 
              CONCAT(first_name, ' ', last_name) as full_name
              FROM teachers WHERE status = 'active'";
    
    if ($subject) {
        $query .= " AND subject = :subject";
    }
    
    $query .= " ORDER BY first_name, last_name";
    
    $stmt = $teacher_conn->prepare($query);
    
    if ($subject) {
        $stmt->bindParam(':subject', $subject);
    }
    
    $stmt->execute();
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'teachers' => $teachers,
        'total' => count($teachers)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
