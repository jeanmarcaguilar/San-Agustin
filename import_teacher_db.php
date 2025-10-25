<?php
// Database configuration
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'teacher_db';

// Create connection
$conn = new mysqli($host, $username, $password);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Read the SQL file
$sql = file_get_contents(__DIR__ . '/database/teacher_db.sql');

// Execute multi query
if ($conn->multi_query($sql)) {
    echo "<h2>Database Import Successful!</h2>";
    echo "<p>The teacher database has been successfully imported.</p>";
    
    // Process all results to clear the buffer
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->next_result());
    
    // Verify the import
    $conn->select_db($database);
    $result = $conn->query("SHOW TABLES");
    
    if ($result->num_rows > 0) {
        echo "<h3>Tables in the database:</h3>";
        echo "<ul>";
        while($row = $result->fetch_row()) {
            echo "<li>" . $row[0] . "</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>No tables found in the database.</p>";
    }
    
    echo "<p><a href='teacher/dashboard.php'>Go to Teacher Dashboard</a></p>";
    
} else {
    echo "<h2>Error importing database:</h2>";
    echo "<p>" . $conn->error . "</p>";
}

$conn->close();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Database Import</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; line-height: 1.6; }
        h2 { color: #2c3e50; }
        .success { color: #27ae60; }
        .error { color: #e74c3c; }
    </style>
</head>
<body>
    <h1>Teacher Database Import</h1>
    <p>This script will import the teacher database structure and sample data.</p>
    <?php if (!isset($sql)): ?>
        <p><a href="?import=1" class="button">Click here to import the database</a></p>
    <?php endif; ?>
</body>
</html>
