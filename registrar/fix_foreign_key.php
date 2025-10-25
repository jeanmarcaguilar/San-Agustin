<?php
/**
 * Fix Foreign Key Constraint Issue
 * This script removes the problematic foreign key constraint that references users table
 * Since registrar_db and login_db are separate databases, cross-database FK is not supported
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $registrar_conn = $database->getConnection('registrar');
    
    if (!$registrar_conn) {
        throw new Exception('Failed to connect to registrar database');
    }
    
    echo "Checking and fixing foreign key constraint...\n\n";
    
    // Check if the constraint exists
    $stmt = $registrar_conn->query("
        SELECT CONSTRAINT_NAME 
        FROM information_schema.TABLE_CONSTRAINTS 
        WHERE TABLE_SCHEMA = 'registrar_db' 
        AND TABLE_NAME = 'students' 
        AND CONSTRAINT_NAME = 'students_ibfk_1'
    ");
    
    if ($stmt->rowCount() > 0) {
        echo "Found constraint 'students_ibfk_1'. Removing it...\n";
        
        // Drop the foreign key constraint
        $registrar_conn->exec("ALTER TABLE students DROP FOREIGN KEY students_ibfk_1");
        
        echo "✓ Foreign key constraint removed successfully!\n\n";
        echo "Note: user_id validation will now be handled in application code.\n";
        echo "This is safer for cross-database references.\n";
    } else {
        echo "Constraint 'students_ibfk_1' not found. Nothing to fix.\n";
    }
    
    // Verify the change
    $stmt = $registrar_conn->query("SHOW CREATE TABLE students");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "\n--- Current table structure ---\n";
    echo $result['Create Table'];
    echo "\n\n✓ Fix completed successfully!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
