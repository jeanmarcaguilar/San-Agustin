<?php
/**
 * Remove Foreign Key Constraint
 * Run this once to fix the sync issue
 */

// Start session to check authentication
session_start();

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    die('Access denied. Registrar access only.');
}

require_once __DIR__ . '/../config/database.php';

try {
    $database = new Database();
    $registrar_conn = $database->getConnection('registrar');
    
    if (!$registrar_conn) {
        throw new Exception('Failed to connect to registrar database');
    }
    
    echo "<h2>Removing Foreign Key Constraint...</h2>";
    
    // Check if the constraint exists
    $stmt = $registrar_conn->query("
        SELECT CONSTRAINT_NAME 
        FROM information_schema.TABLE_CONSTRAINTS 
        WHERE TABLE_SCHEMA = 'registrar_db' 
        AND TABLE_NAME = 'students' 
        AND CONSTRAINT_NAME = 'students_ibfk_1'
    ");
    
    if ($stmt->rowCount() > 0) {
        echo "<p>✓ Found constraint 'students_ibfk_1'. Removing it...</p>";
        
        // Drop the foreign key constraint
        $registrar_conn->exec("ALTER TABLE students DROP FOREIGN KEY students_ibfk_1");
        
        echo "<p style='color: green;'><strong>✓ Foreign key constraint removed successfully!</strong></p>";
        echo "<p>Students can now be synced without foreign key errors.</p>";
    } else {
        echo "<p style='color: orange;'>⚠️ Constraint 'students_ibfk_1' not found. Already removed or doesn't exist.</p>";
    }
    
    echo "<hr>";
    echo "<p><strong>✓ Fix completed!</strong></p>";
    echo "<p><a href='sync_students_page.php'>Go to Sync Students Page</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
