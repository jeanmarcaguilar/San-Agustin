<?php
// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'teacher_db';

try {
    // Create connection
    $conn = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // SQL to create the classes table
    $sql = "
    CREATE TABLE IF NOT EXISTS `classes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `teacher_id` varchar(20) NOT NULL,
        `subject` varchar(100) NOT NULL,
        `grade_level` int(11) NOT NULL,
        `section` varchar(10) NOT NULL,
        `schedule` varchar(100) DEFAULT NULL,
        `room` varchar(20) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `teacher_id` (`teacher_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS `activities` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `teacher_id` varchar(20) NOT NULL,
        `title` varchar(255) NOT NULL,
        `description` text DEFAULT NULL,
        `activity_date` date NOT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `teacher_id` (`teacher_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    
    CREATE TABLE IF NOT EXISTS `notices` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `teacher_id` varchar(20) NOT NULL,
        `title` varchar(255) NOT NULL,
        `message` text NOT NULL,
        `status` enum('pending','read') NOT NULL DEFAULT 'pending',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `teacher_id` (`teacher_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";
    
    // Execute the SQL
    $conn->exec($sql);
    
    echo "<h2>Success!</h2>";
    echo "<p>The missing tables have been created successfully.</p>";
    echo "<p><a href='teacher/dashboard.php'>Go to Teacher Dashboard</a></p>";
    
} catch(PDOException $e) {
    echo "<h2>Error:</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
    
    // If database doesn't exist, try to create it
    if ($e->getCode() == 1049) {
        try {
            $conn = new PDO("mysql:host=$host", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $conn->exec("CREATE DATABASE `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            
            echo "<p>Database created successfully. <a href='create_missing_tables.php'>Click here to create tables</a></p>";
        } catch(PDOException $e2) {
            echo "<p>Failed to create database: " . $e2->getMessage() . "</p>";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Create Missing Tables</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        h2 { color: #2c3e50; }
        .success { color: #27ae60; }
        .error { color: #e74c3c; }
    </style>
</head>
<body>
    <h1>Create Missing Database Tables</h1>
    <p>This script will create the missing database tables needed for the Teacher Dashboard.</p>
    <?php if (!isset($sql)): ?>
        <p><a href="?create=1" class="button">Click here to create missing tables</a></p>
    <?php endif; ?>
</body>
</html>
