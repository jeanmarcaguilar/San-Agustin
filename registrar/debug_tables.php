<?php
session_start();
require_once '../config/database.php';

$database = new Database();

// Check teacher database
try {
    $teacher_conn = $database->getConnection('teacher');
    echo "<h2>Teacher Database - Students Table</h2>";
    
    $stmt = $teacher_conn->query("SHOW TABLES LIKE 'students'");
    if ($stmt->rowCount() > 0) {
        echo "Students table exists in teacher database<br>";
        
        // Show table structure
        $stmt = $teacher_conn->query("DESCRIBE students");
        $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>Table structure: " . print_r($structure, true) . "</pre>";
        
        // Show sample data
        $stmt = $teacher_conn->query("SELECT * FROM students LIMIT 5");
        $sample = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>Sample data: " . print_r($sample, true) . "</pre>";
    } else {
        echo "Students table NOT found in teacher database<br>";
    }
} catch (Exception $e) {
    echo "Teacher connection error: " . $e->getMessage() . "<br>";
}

// Check registrar database
try {
    $registrar_conn = $database->getConnection('registrar');
    echo "<h2>Registrar Database - Students Table</h2>";
    
    $stmt = $registrar_conn->query("SHOW TABLES LIKE 'students'");
    if ($stmt->rowCount() > 0) {
        echo "Students table exists in registrar database<br>";
        
        // Show table structure
        $stmt = $registrar_conn->query("DESCRIBE students");
        $structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>Table structure: " . print_r($structure, true) . "</pre>";
        
        // Show sample data
        $stmt = $registrar_conn->query("SELECT * FROM students LIMIT 5");
        $sample = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo "<pre>Sample data: " . print_r($sample, true) . "</pre>";
    } else {
        echo "Students table NOT found in registrar database<br>";
    }
} catch (Exception $e) {
    echo "Registrar connection error: " . $e->getMessage() . "<br>";
}
?>