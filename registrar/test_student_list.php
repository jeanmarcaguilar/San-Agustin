<?php
/**
 * Test Student List Display
 * Verify that synced students appear in registrar's student list
 */

require_once '../config/database.php';

try {
    $database = new Database();
    $registrar_conn = $database->getConnection('registrar');
    $login_conn = $database->getLoginConnection();
    
    if (!$registrar_conn || !$login_conn) {
        throw new Exception('Failed to connect to databases');
    }
    
    echo "<h1>Student List Test</h1>\n";
    echo "<p>Testing if students appear in registrar's student list...</p>\n\n";
    
    // Query students from registrar database (same query as view_students.php)
    $query = "SELECT s.*, u.email, u.username 
              FROM students s 
              LEFT JOIN login_db.users u ON s.user_id = u.id 
              ORDER BY s.last_name, s.first_name ASC";
    
    $stmt = $registrar_conn->prepare($query);
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h2>Total Students in Registrar Database: " . count($students) . "</h2>\n\n";
    
    if (empty($students)) {
        echo "<p style='color: orange;'>⚠️ No students found in registrar database.</p>\n";
        echo "<p>Run the sync from: <a href='sync_students_page.php'>sync_students_page.php</a></p>\n";
    } else {
        echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>\n";
        echo "<thead>\n";
        echo "<tr style='background-color: #f0f0f0;'>\n";
        echo "<th>Student ID</th>\n";
        echo "<th>Name</th>\n";
        echo "<th>Grade</th>\n";
        echo "<th>Section</th>\n";
        echo "<th>Email</th>\n";
        echo "<th>Username</th>\n";
        echo "<th>Status</th>\n";
        echo "<th>Has Login?</th>\n";
        echo "</tr>\n";
        echo "</thead>\n";
        echo "<tbody>\n";
        
        foreach ($students as $student) {
            $has_login = !empty($student['username']) ? '✅ Yes' : '❌ No';
            $login_style = !empty($student['username']) ? 'color: green;' : 'color: red;';
            
            echo "<tr>\n";
            echo "<td>" . htmlspecialchars($student['student_id']) . "</td>\n";
            echo "<td>" . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . "</td>\n";
            echo "<td>" . htmlspecialchars($student['grade_level']) . "</td>\n";
            echo "<td>" . htmlspecialchars($student['section'] ?? 'N/A') . "</td>\n";
            echo "<td>" . htmlspecialchars($student['email'] ?? 'N/A') . "</td>\n";
            echo "<td>" . htmlspecialchars($student['username'] ?? 'N/A') . "</td>\n";
            echo "<td>" . htmlspecialchars($student['status'] ?? 'Active') . "</td>\n";
            echo "<td style='$login_style'><strong>$has_login</strong></td>\n";
            echo "</tr>\n";
        }
        
        echo "</tbody>\n";
        echo "</table>\n\n";
        
        // Statistics
        $with_login = 0;
        $without_login = 0;
        foreach ($students as $student) {
            if (!empty($student['username'])) {
                $with_login++;
            } else {
                $without_login++;
            }
        }
        
        echo "<h3>Statistics:</h3>\n";
        echo "<ul>\n";
        echo "<li>✅ Students with login accounts: <strong>$with_login</strong></li>\n";
        echo "<li>❌ Students without login accounts: <strong>$without_login</strong></li>\n";
        echo "</ul>\n";
        
        if ($without_login > 0) {
            echo "<p style='color: orange;'>⚠️ <strong>Note:</strong> Students without login accounts exist in the registrar system but cannot log in.</p>\n";
            echo "<p>You can create login accounts for them later using the 'Add Student' form.</p>\n";
        }
    }
    
    echo "\n<hr>\n";
    echo "<h3>✅ Test Complete!</h3>\n";
    echo "<p>Students from the sync are now visible in the registrar's student list.</p>\n";
    echo "<p><a href='view_students.php'>View Students Page</a> | <a href='sync_students_page.php'>Sync Students</a></p>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red;'><strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>\n";
}
?>
