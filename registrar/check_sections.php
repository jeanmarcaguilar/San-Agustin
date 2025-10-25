<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('HTTP/1.1 401 Unauthorized');
    echo json_encode(['exists' => false]);
    exit();
}

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');

try {
    // Check if any sections exist
    $stmt = $pdo->query("SELECT COUNT(*) FROM class_sections");
    $sectionCount = $stmt->fetchColumn();
    
    header('Content-Type: application/json');
    echo json_encode(['exists' => $sectionCount > 0]);
    
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode([
        'exists' => false,
        'error' => $e->getMessage()
    ]);
}
