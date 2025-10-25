<?php
// Database configuration
$config = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => ''
];

// Function to execute SQL file
function executeSqlFile($pdo, $file) {
    // Read the SQL file
    $sql = file_get_contents($file);
    
    // Split into individual queries
    $queries = explode(';', $sql);
    
    // Execute each query
    foreach ($queries as $query) {
        $query = trim($query);
        if (!empty($query)) {
            try {
                $pdo->exec($query);
            } catch (PDOException $e) {
                echo "<p style='color: red;'>Error executing query: " . htmlspecialchars($e->getMessage()) . "</p>";
                echo "<p>Query: " . htmlspecialchars($query) . "</p>";
            }
        }
    }
}

// Main execution
echo "<h2>Database Setup</h2>";

try {
    // Connect to MySQL server
    $pdo = new PDO(
        "mysql:host={$config['host']}",
        $config['username'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    
    echo "<p>✓ Connected to MySQL server successfully.</p>";
    
    // Import the SQL file
    $sqlFile = __DIR__ . '/database_setup.sql';
    if (file_exists($sqlFile)) {
        echo "<p>Importing database structure and data...</p>";
        executeSqlFile($pdo, $sqlFile);
        echo "<p style='color: green; font-weight: bold;'>✓ Database setup completed successfully!</p>";
        echo "<p>Test user created:</p>";
        echo "<ul>";
        echo "<li>Username: teststudent</li>";
        echo "<li>Password: student123</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: red;'>Error: database_setup.sql file not found!</p>";
    }
    
    echo "<p><a href='login.php' style='display: inline-block; margin-top: 20px; padding: 10px 20px; background: #0b6b4f; color: white; text-decoration: none; border-radius: 5px;'>Go to Login Page</a></p>";
    
} catch (PDOException $e) {
    die("<div style='color: red; padding: 20px; border: 1px solid #f00; background: #fff0f0;'>
        <h3>⚠️ Database Connection Error</h3>
        <p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>
        <p>Please check:</p>
        <ol>
            <li>MySQL server is running</li>
            <li>Database credentials in import_database.php are correct</li>
            <li>MySQL user has proper permissions</li>
        </ol>
    </div>");
}
?>
