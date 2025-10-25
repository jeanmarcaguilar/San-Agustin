<?php
/**
 * Migration Script: Modify class_schedules.teacher_id to VARCHAR
 * Run this file once to update the database structure
 */

require_once 'config/database.php';

echo "<h2>Running Database Migration...</h2>";
echo "<pre>";

try {
    $database = new Database();
    $pdo = $database->getConnection('registrar');
    
    echo "Connected to registrar_db\n\n";
    
    // Step 1: Drop the foreign key constraint
    echo "Step 1: Dropping foreign key constraint...\n";
    try {
        $pdo->exec("ALTER TABLE class_schedules DROP FOREIGN KEY class_schedules_ibfk_1");
        echo "✓ Foreign key constraint dropped successfully\n\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "check that column/key exists") !== false) {
            echo "✓ Foreign key constraint doesn't exist (already removed)\n\n";
        } else {
            throw $e;
        }
    }
    
    // Step 2: Modify teacher_id column from INT to VARCHAR
    echo "Step 2: Modifying teacher_id column to VARCHAR(20)...\n";
    $pdo->exec("ALTER TABLE class_schedules MODIFY COLUMN teacher_id VARCHAR(20) DEFAULT NULL");
    echo "✓ Column modified successfully\n\n";
    
    // Step 3: Add index for performance
    echo "Step 3: Adding index on teacher_id...\n";
    try {
        $pdo->exec("ALTER TABLE class_schedules ADD INDEX idx_teacher_id (teacher_id)");
        echo "✓ Index added successfully\n\n";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Duplicate key name") !== false) {
            echo "✓ Index already exists\n\n";
        } else {
            throw $e;
        }
    }
    
    // Verification
    echo "Step 4: Verifying changes...\n";
    $stmt = $pdo->query("
        SELECT 
            COLUMN_NAME, 
            DATA_TYPE, 
            IS_NULLABLE,
            COLUMN_KEY,
            CHARACTER_MAXIMUM_LENGTH
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = 'registrar_db' 
        AND TABLE_NAME = 'class_schedules' 
        AND COLUMN_NAME = 'teacher_id'
    ");
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo "✓ Verification successful:\n";
        echo "  - Column Name: " . $result['COLUMN_NAME'] . "\n";
        echo "  - Data Type: " . $result['DATA_TYPE'] . "\n";
        echo "  - Max Length: " . $result['CHARACTER_MAXIMUM_LENGTH'] . "\n";
        echo "  - Nullable: " . $result['IS_NULLABLE'] . "\n";
        echo "  - Key: " . $result['COLUMN_KEY'] . "\n\n";
    }
    
    echo "\n";
    echo "========================================\n";
    echo "✓ MIGRATION COMPLETED SUCCESSFULLY!\n";
    echo "========================================\n";
    echo "\nYou can now add class schedules.\n";
    echo "The teacher_id column now accepts VARCHAR values like 'T-001'.\n";
    
} catch (PDOException $e) {
    echo "\n";
    echo "========================================\n";
    echo "✗ MIGRATION FAILED!\n";
    echo "========================================\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nPlease check the error and try again.\n";
}

echo "</pre>";
echo "<br><a href='registrar/class_schedules.php'>← Back to Class Schedules</a>";
?>
