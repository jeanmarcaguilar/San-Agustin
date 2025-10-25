<?php
require_once '../config/database.php';

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');

echo "<h2>Students Table Structure</h2>";

// Get table structure
$stmt = $pdo->query("DESCRIBE students");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='5'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
foreach ($columns as $column) {
    echo "<tr>";
    echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
    echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
    echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
    echo "</tr>";
}
echo "</table>";

// Get current grade level distribution
echo "<h2>Current Grade Level Distribution</h2>";
try {
    $stmt = $pdo->query("SELECT grade_level, COUNT(*) as count FROM students GROUP BY grade_level ORDER BY grade_level");
    $gradeLevels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($gradeLevels) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Grade Level</th><th>Number of Students</th></tr>";
        foreach ($gradeLevels as $level) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($level['grade_level'] ?? 'Not Set') . "</td>";
            echo "<td>" . htmlspecialchars($level['count']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No students found in the database.";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}

// Get sample student data
echo "<h2>Sample Student Data</h2>";
try {
    $stmt = $pdo->query("SELECT id, lrn, last_name, first_name, grade_level, section FROM students ORDER BY grade_level, section, last_name LIMIT 20");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($students) > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>LRN</th><th>Name</th><th>Grade Level</th><th>Section</th></tr>";
        foreach ($students as $student) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($student['id']) . "</td>";
            echo "<td>" . htmlspecialchars($student['lrn']) . "</td>";
            echo "<td>" . htmlspecialchars($student['last_name'] . ', ' . $student['first_name']) . "</td>";
            echo "<td>" . htmlspecialchars($student['grade_level'] ?? 'Not Set') . "</td>";
            echo "<td>" . htmlspecialchars($student['section'] ?? 'Not Set') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "No students found in the database.";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>

<h2>Update Grade Levels</h2>
<form method="post" action="update_grade_levels.php">
    <p>This will help you distribute students across different grade levels.</p>
    <p>Number of students per grade level: <input type="number" name="students_per_grade" min="1" value="30"></p>
    <p>Starting grade level: 
        <select name="starting_grade">
            <?php for ($i = 1; $i <= 12; $i++): ?>
                <option value="<?php echo $i; ?>">Grade <?php echo $i; ?></option>
            <?php endfor; ?>
        </select>
    </p>
    <input type="submit" name="preview" value="Preview Changes">
    <input type="submit" name="apply" value="Apply Changes">
</form>
