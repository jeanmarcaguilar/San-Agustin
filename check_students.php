<?php
// Include database configuration
require_once 'config/database.php';

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');

// Get table structure
try {
    // Get table structure
    $stmt = $pdo->query("DESCRIBE students");
    $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get distinct status values
    $statusStmt = $pdo->query("SELECT DISTINCT status, COUNT(*) as count FROM students GROUP BY status");
    $statusCounts = $statusStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count
    $totalStmt = $pdo->query("SELECT COUNT(*) as total FROM students");
    $total = $totalStmt->fetch(PDO::FETCH_ASSOC);
    
    // Output results
    echo "<h2>Students Table Structure</h2>";
    echo "<pre>";
    print_r($structure);
    echo "</pre>";
    
    echo "<h2>Status Distribution</h2>";
    echo "<pre>";
    print_r($statusCounts);
    echo "</pre>";
    
    echo "<h2>Total Students</h2>";
    echo "<p>Total students in database: " . $total['total'] . "</p>";
    
} catch (PDOException $e) {
    echo "<div style='color:red;'>Error: " . $e->getMessage() . "</div>";
}
?>
