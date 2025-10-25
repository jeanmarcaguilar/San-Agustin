<?php
// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'teacher_db';

// SQL to create tables
$sql = [
    "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';",
    "START TRANSACTION;",
    "SET time_zone = '+00:00';",
    
    // Create database if not exists
    "CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
    "USE `$dbname`;",
    
    // Teachers table
    "CREATE TABLE IF NOT EXISTS `teachers` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `login_account_id` int(11) NOT NULL,
        `first_name` varchar(50) NOT NULL,
        `last_name` varchar(50) NOT NULL,
        `email` varchar(100) NOT NULL,
        `profile_image` varchar(255) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        UNIQUE KEY `login_account_id` (`login_account_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    
    // Classes table
    "CREATE TABLE IF NOT EXISTS `classes` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `teacher_id` int(11) NOT NULL,
        `name` varchar(100) NOT NULL,
        `description` text DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `teacher_id` (`teacher_id`),
        CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    
    // Activities table
    "CREATE TABLE IF NOT EXISTS `activities` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `teacher_id` int(11) NOT NULL,
        `title` varchar(255) NOT NULL,
        `description` text DEFAULT NULL,
        `activity_type` varchar(50) DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `teacher_id` (`teacher_id`),
        CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    
    // Notices table
    "CREATE TABLE IF NOT EXISTS `notices` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `teacher_id` int(11) NOT NULL,
        `title` varchar(255) NOT NULL,
        `message` text NOT NULL,
        `status` enum('pending','read') NOT NULL DEFAULT 'pending',
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`id`),
        KEY `teacher_id` (`teacher_id`),
        CONSTRAINT `notices_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    
    // Insert sample data
    "INSERT INTO `teachers` (`login_account_id`, `first_name`, `last_name`, `email`) VALUES
    (1, 'John', 'Doe', 'john.doe@example.com');",
    
    "COMMIT;"
];

try {
    // Create connection without selecting a database first
    $conn = new PDO("mysql:host=$host", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Execute each SQL statement
    foreach ($sql as $query) {
        try {
            $conn->exec($query);
        } catch (PDOException $e) {
            echo "Error executing query: " . $e->getMessage() . "<br>\n";
            echo "Query: " . htmlspecialchars($query) . "<br><br>\n";
        }
    }
    
    echo "<h2>Database setup completed successfully!</h2>";
    echo "<p>The teacher database and tables have been created.</p>";
    echo "<p>You can now <a href='teacher/dashboard.php'>go to the teacher dashboard</a>.</p>";
    
} catch(PDOException $e) {
    echo "<h2>Database Error</h2>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
    echo "<p>Please check your database configuration in config/database.php</p>";
    echo "<p>Make sure MySQL is running and the credentials are correct.</p>";
}
?>
