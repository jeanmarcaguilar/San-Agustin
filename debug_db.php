<?php
// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$config = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'dbname' => 'teacher_db'
];

function testConnection($config) {
    echo "<h2>Testing Database Connection</h2>";
    
    // Test connection without selecting database
    try {
        $conn = new PDO("mysql:host={$config['host']}", $config['username'], $config['password']);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        echo "<p style='color:green'>✓ Connected to MySQL server successfully</p>";
        
        // Check if database exists
        $stmt = $conn->query("SHOW DATABASES LIKE '{$config['dbname']}'");
        if ($stmt->rowCount() > 0) {
            echo "<p style='color:green'>✓ Database '{$config['dbname']}' exists</p>";
            
            // Select the database
            $conn->exec("USE `{$config['dbname']}`");
            
            // Check required tables
            $required_tables = ['teachers', 'classes', 'activities', 'notices'];
            $all_tables_exist = true;
            
            foreach ($required_tables as $table) {
                $stmt = $conn->query("SHOW TABLES LIKE '$table'");
                if ($stmt->rowCount() > 0) {
                    echo "<p style='color:green'>✓ Table '$table' exists</p>";
                } else {
                    echo "<p style='color:red'>✗ Table '$table' is missing</p>";
                    $all_tables_exist = false;
                }
            }
            
            if (!$all_tables_exist) {
                echo "<h3>Creating missing tables...</h3>";
                createTables($conn);
            } else {
                echo "<h3>All required tables exist.</h3>";
                checkTableStructure($conn);
            }
            
        } else {
            echo "<p style='color:red'>✗ Database '{$config['dbname']}' does not exist</p>";
            echo "<h3>Creating database and tables...</h3>";
            createDatabase($config);
        }
        
    } catch(PDOException $e) {
        echo "<p style='color:red'>✗ Connection failed: " . $e->getMessage() . "</p>";
        echo "<p>Please check your MySQL server is running and the credentials in config/database.php are correct.</p>";
    }
}

function createDatabase($config) {
    try {
        $conn = new PDO("mysql:host={$config['host']}", $config['username'], $config['password']);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create database
        $sql = "CREATE DATABASE IF NOT EXISTS `{$config['dbname']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        $conn->exec($sql);
        echo "<p style='color:green'>✓ Database '{$config['dbname']}' created successfully</p>";
        
        // Select the database and create tables
        $conn->exec("USE `{$config['dbname']}`");
        createTables($conn);
        
    } catch(PDOException $e) {
        echo "<p style='color:red'>✗ Error creating database: " . $e->getMessage() . "</p>";
    }
}

function createTables($conn) {
    $tables = [
        'teachers' => "
            CREATE TABLE IF NOT EXISTS `teachers` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            
        'classes' => "
            CREATE TABLE IF NOT EXISTS `classes` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `teacher_id` int(11) NOT NULL,
                `name` varchar(100) NOT NULL,
                `description` text DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `teacher_id` (`teacher_id`),
                CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            
        'activities' => "
            CREATE TABLE IF NOT EXISTS `activities` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `teacher_id` int(11) NOT NULL,
                `title` varchar(255) NOT NULL,
                `description` text DEFAULT NULL,
                `activity_type` varchar(50) DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `teacher_id` (`teacher_id`),
                CONSTRAINT `activities_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            
        'notices' => "
            CREATE TABLE IF NOT EXISTS `notices` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];
    
    foreach ($tables as $table => $sql) {
        try {
            $conn->exec($sql);
            echo "<p style='color:green'>✓ Table '$table' created successfully</p>";
            
            // Add sample data for teachers table
            if ($table === 'teachers') {
                $conn->exec("INSERT IGNORE INTO `teachers` (`login_account_id`, `first_name`, `last_name`, `email`) VALUES (1, 'John', 'Doe', 'john.doe@example.com')");
            }
            
        } catch(PDOException $e) {
            echo "<p style='color:red'>✗ Error creating table '$table': " . $e->getMessage() . "</p>";
        }
    }
}

function checkTableStructure($conn) {
    echo "<h3>Checking table structures...</h3>";
    
    $tables = [
        'teachers' => ['id', 'login_account_id', 'first_name', 'last_name', 'email', 'profile_image', 'created_at', 'updated_at'],
        'classes' => ['id', 'teacher_id', 'name', 'description', 'created_at', 'updated_at'],
        'activities' => ['id', 'teacher_id', 'title', 'description', 'activity_type', 'created_at'],
        'notices' => ['id', 'teacher_id', 'title', 'message', 'status', 'created_at', 'updated_at']
    ];
    
    foreach ($tables as $table => $columns) {
        try {
            $stmt = $conn->query("DESCRIBE `$table`");
            $existing_columns = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existing_columns[] = $row['Field'];
            }
            
            $missing_columns = array_diff($columns, $existing_columns);
            
            if (empty($missing_columns)) {
                echo "<p style='color:green'>✓ Table '$table' has all required columns</p>";
            } else {
                echo "<p style='color:orange'>⚠ Table '$table' is missing columns: " . implode(', ', $missing_columns) . "</p>";
            }
            
        } catch(PDOException $e) {
            echo "<p style='color:red'>✗ Error checking table '$table': " . $e->getMessage() . "</p>";
        }
    }
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Database Debug Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        h1 { color: #333; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
    </style>
</head>
<body>
    <h1>Database Debug Tool</h1>
    <?php testConnection($config); ?>
    
    <h2>Next Steps:</h2>
    <ol>
        <li>If you see any red errors, please fix the database connection in <code>config/database.php</code></li>
        <li>Make sure MySQL server is running</li>
        <li>Check that the database user has proper permissions</li>
        <li>After fixing any issues, <a href="teacher/dashboard.php">try accessing the dashboard again</a></li>
    </ol>
    
    <h2>Database Configuration:</h2>
    <pre><?php echo htmlspecialchars(print_r($config, true)); ?></pre>
</body>
</html>
