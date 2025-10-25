<?php
// Database configuration
$config = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'databases' => ['login_db', 'student_db']
];

// Function to execute SQL file
function executeSqlFile($pdo, $file) {
    $sql = file_get_contents($file);
    $pdo->exec($sql);
}

echo "<h2>Database Setup</h2>";

try {
    // Connect to MySQL server
    $pdo = new PDO(
        "mysql:host={$config['host']}", 
        $config['username'], 
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    // Create databases
    foreach ($config['databases'] as $dbName) {
        try {
            // Create database
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            echo "<p>&#10003; Database '$dbName' created or already exists.</p>";
            
            // Connect directly to the new database
            $db = new PDO(
                "mysql:host={$config['host']};dbname=$dbName;charset=utf8mb4",
                $config['username'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            
            // Create users table with separate statements
            $db->exec("DROP TABLE IF EXISTS `users`");
            
            $db->exec("CREATE TABLE `users` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `username` varchar(50) NOT NULL,
                `password` varchar(255) NOT NULL,
                `first_name` varchar(50) NOT NULL,
                `last_name` varchar(50) NOT NULL,
                `email` varchar(100) NOT NULL,
                `grade_level` varchar(10) DEFAULT NULL,
                `section` varchar(20) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            // Add indexes separately
            $db->exec("ALTER TABLE `users` ADD UNIQUE KEY `username` (`username`)");
            $db->exec("ALTER TABLE `users` ADD UNIQUE KEY `email` (`email`)");
            
            echo "<p>Table 'users' created or already exists in '$dbName'.</p>";
            
            // Check if we need to add test user
            $stmt = $pdo->query("SELECT COUNT(*) FROM `users`");
            if ($stmt->fetchColumn() == 0) {
                $insertUser = $pdo->prepare("
                    INSERT INTO `users` 
                    (`username`, `password`, `first_name`, `last_name`, `email`, `grade_level`, `section`) 
                    VALUES 
                    (:username, :password, :first_name, :last_name, :email, :grade_level, :section)
                ");
                
                $userData = [
                    ':username' => 'teststudent',
                    ':password' => $hashedPassword,
                    ':first_name' => 'Test',
                    ':last_name' => 'Student',
                    ':email' => 'test@example.com',
                    ':grade_level' => '5',
                    ':section' => 'A'
                ];
                
                $insertUser->execute($userData);
                echo "<p>Test user created in '$dbName'.<br>
                     Username: teststudent<br>
                     Password: student123</p>";
            }
            
        } catch (PDOException $e) {
            echo "<p style='color: orange;'>Error with database '$dbName': " . $e->getMessage() . "</p>";
            continue;
        }
    }
    
    echo "<p style='color: green; font-weight: bold;'>Database setup completed successfully!</p>";
    echo "<p><a href='login.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #0b6b4f; color: white; text-decoration: none; border-radius: 5px;'>Go to Login Page</a></p>";
    
} catch(PDOException $e) {
    die("<div style='color: red; padding: 20px; border: 1px solid #f00; background: #fff0f0;'>
        <h3>Error during database setup:</h3>
        <p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
        <p>Please check:</p>
        <ol>
            <li>MySQL server is running</li>
            <li>Database credentials in setup_database.php are correct</li>
            <li>MySQL user has proper permissions</li>
        </ol>
    </div>");
}
