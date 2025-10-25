<?php
session_start();

// Check if user is logged in as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../config/database.php';

header('Content-Type: application/json');

// Get announcement ID
$announcement_id = $_POST['id'] ?? $_GET['id'] ?? null;

if (!$announcement_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Announcement ID is required']);
    exit();
}

try {
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    
    // Get teacher ID
    $stmt = $teacher_conn->prepare("SELECT teacher_id FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$teacher) {
        throw new Exception('Teacher record not found');
    }
    
    // Verify the announcement belongs to this teacher
    $stmt = $teacher_conn->prepare("SELECT id, title FROM announcements WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$announcement_id, $teacher['teacher_id']]);
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$announcement) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Announcement not found or you do not have permission to delete it']);
        exit();
    }
    
    // Delete the announcement
    $stmt = $teacher_conn->prepare("DELETE FROM announcements WHERE id = ?");
    $stmt->execute([$announcement_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Announcement deleted successfully',
        'announcement_id' => $announcement_id
    ]);
    
} catch (Exception $e) {
    error_log('Error in delete_announcement.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting announcement: ' . $e->getMessage()
    ]);
}
?>
