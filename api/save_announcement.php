<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once '../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'teacher') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Teacher access only.']);
    exit;
}

// Get JSON input
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate input
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

// Validate required fields
$required_fields = ['title', 'content', 'teacher_id'];
foreach ($required_fields as $field) {
    if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

try {
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    
    // Start transaction
    $teacher_conn->beginTransaction();
    
    // Map target to target_audience
    $target = $data['target'] ?? 'all';
    $target_audience = 'all';
    $target_class_id = null;
    $target_grade = null;
    $target_section = null;
    
    // Parse target to determine target_audience and related fields
    if ($target === 'students' || $target === 'all') {
        $target_audience = 'all';
    } elseif (strpos($target, 'class_') === 0) {
        $target_audience = 'specific_class';
        $target_class_id = (int)str_replace('class_', '', $target);
    } else {
        $target_audience = 'all';
    }
    
    // Determine status (published by default)
    $status = 'published';
    
    // Prepare SQL statement
    $query = "INSERT INTO announcements 
              (title, content, target_audience, target_class_id, target_grade, target_section, 
               is_pinned, teacher_id, start_date, end_date, status, created_at)
              VALUES 
              (:title, :content, :target_audience, :target_class_id, :target_grade, :target_section,
               :is_pinned, :teacher_id, :start_date, :end_date, :status, NOW())";
    
    $stmt = $teacher_conn->prepare($query);
    
    // Bind parameters
    $stmt->bindParam(':title', $data['title']);
    $stmt->bindParam(':content', $data['content']);
    $stmt->bindParam(':target_audience', $target_audience);
    $stmt->bindParam(':target_class_id', $target_class_id, PDO::PARAM_INT);
    $stmt->bindParam(':target_grade', $target_grade, PDO::PARAM_INT);
    $stmt->bindParam(':target_section', $target_section);
    $is_pinned = isset($data['pinned']) ? (int)$data['pinned'] : 0;
    $stmt->bindParam(':is_pinned', $is_pinned, PDO::PARAM_INT);
    $stmt->bindParam(':teacher_id', $data['teacher_id']);
    $start_date = !empty($data['start_date']) ? $data['start_date'] : date('Y-m-d');
    $stmt->bindParam(':start_date', $start_date);
    $end_date = !empty($data['end_date']) ? $data['end_date'] : null;
    $stmt->bindParam(':end_date', $end_date);
    $stmt->bindParam(':status', $status);
    
    // Execute the query
    $stmt->execute();
    
    // Get the ID of the newly created announcement
    $announcement_id = $teacher_conn->lastInsertId();
    
    // Commit the transaction
    $teacher_conn->commit();
    
    // Get the full announcement data with teacher info
    $stmt = $teacher_conn->prepare("
        SELECT a.*, 
               t.first_name as teacher_first_name,
               t.last_name as teacher_last_name,
               CONCAT(t.first_name, ' ', t.last_name) as teacher_name
        FROM announcements a
        LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
        WHERE a.id = ?
    ");
    $stmt->execute([$announcement_id]);
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Format dates for display
    if ($announcement) {
        $date = new DateTime($announcement['start_date']);
        $announcement['formatted_date'] = $date->format('M j, Y');
        $announcement['is_read'] = false; // New announcement is unread by default
        $announcement['description'] = $announcement['content']; // For compatibility
        $announcement['priority'] = $announcement['is_pinned'] ? 'high' : 'medium';
        $announcement['source'] = 'teacher';
        $announcement['posted_by'] = $announcement['teacher_name'] ?? 'Teacher';
    }
    
    // Broadcast the new announcement
    broadcastNewAnnouncement($announcement);
    
    echo json_encode([
        'success' => true,
        'message' => 'Announcement published successfully',
        'announcement' => $announcement
    ]);
    
} catch (PDOException $e) {
    // Rollback the transaction on error
    if (isset($teacher_conn) && $teacher_conn->inTransaction()) {
        $teacher_conn->rollBack();
    }
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

/**
 * Broadcast a new announcement to all connected clients
 */
function broadcastNewAnnouncement($announcement) {
    // In a real application, you would use WebSockets or Server-Sent Events (SSE) here
    // For this example, we'll just log the broadcast
    error_log("Broadcasting new announcement: " . $announcement['title']);
    
    // You would typically have a WebSocket server or similar to handle real-time updates
    // For example:
    // $context = new ZMQContext();
    // $socket = $context->getSocket(ZMQ::SOCKET_PUSH, 'my pusher');
    // $socket->connect("tcp://localhost:5555");
    // $socket->send(json_encode([
    //     'event' => 'new_announcement',
    //     'data' => $announcement
    // ]));
}
