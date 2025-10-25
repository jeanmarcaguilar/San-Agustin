<?php
/**
 * Test Student Announcements - Verify Teacher Announcements Appear
 */

require_once 'config/database.php';

echo "<h1>Student Announcements Test</h1>";
echo "<style>
    body { font-family: Arial, sans-serif; padding: 20px; max-width: 1200px; margin: 0 auto; }
    .success { color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; margin: 10px 0; border-radius: 5px; }
    .error { color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; margin: 10px 0; border-radius: 5px; }
    .info { color: blue; padding: 10px; background: #d1ecf1; border: 1px solid #bee5eb; margin: 10px 0; border-radius: 5px; }
    .warning { color: #856404; padding: 10px; background: #fff3cd; border: 1px solid #ffeaa7; margin: 10px 0; border-radius: 5px; }
    pre { background: #f5f5f5; padding: 10px; border: 1px solid #ddd; overflow-x: auto; border-radius: 5px; }
    table { width: 100%; border-collapse: collapse; margin: 10px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background: #f2f2f2; font-weight: bold; }
    h2 { margin-top: 30px; border-bottom: 2px solid #333; padding-bottom: 5px; }
    .badge { display: inline-block; padding: 3px 8px; border-radius: 3px; font-size: 12px; margin: 2px; }
    .badge-purple { background: #e9d5ff; color: #7c3aed; }
    .badge-blue { background: #dbeafe; color: #2563eb; }
    .badge-green { background: #d1fae5; color: #059669; }
    .badge-red { background: #fee2e2; color: #dc2626; }
</style>";

try {
    $database = new Database();
    
    // Test 1: Check student data
    echo "<h2>Test 1: Student Data</h2>";
    
    $student_conn = $database->getConnection('student');
    $stmt = $student_conn->query("SELECT * FROM students LIMIT 5");
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($students) > 0) {
        echo "<div class='success'>✓ Found " . count($students) . " students</div>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Name</th><th>Grade</th><th>Section</th><th>User ID</th></tr>";
        foreach ($students as $student) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($student['student_id']) . "</td>";
            echo "<td>" . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . "</td>";
            echo "<td>" . htmlspecialchars($student['grade_level']) . "</td>";
            echo "<td>" . htmlspecialchars($student['section']) . "</td>";
            echo "<td>" . htmlspecialchars($student['user_id']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='error'>✗ No students found</div>";
    }
    
    // Test 2: Check teacher announcements
    echo "<h2>Test 2: Teacher Announcements in Database</h2>";
    
    $teacher_conn = $database->getConnection('teacher');
    $stmt = $teacher_conn->query("SELECT COUNT(*) as count FROM announcements");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    echo "<div class='info'>Total teacher announcements: {$count}</div>";
    
    $stmt = $teacher_conn->query("
        SELECT a.*, 
               t.first_name, 
               t.last_name,
               CONCAT(t.first_name, ' ', t.last_name) as teacher_name
        FROM announcements a
        LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($announcements) > 0) {
        echo "<div class='success'>✓ Found " . count($announcements) . " announcements</div>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Title</th><th>Teacher</th><th>Target</th><th>Grade</th><th>Section</th><th>Status</th><th>Pinned</th></tr>";
        foreach ($announcements as $ann) {
            $statusClass = $ann['status'] === 'published' ? 'badge-green' : ($ann['status'] === 'draft' ? 'badge-blue' : 'badge-red');
            echo "<tr>";
            echo "<td>" . htmlspecialchars($ann['id']) . "</td>";
            echo "<td>" . htmlspecialchars($ann['title']) . "</td>";
            echo "<td>" . htmlspecialchars($ann['teacher_name'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($ann['target_audience']) . "</td>";
            echo "<td>" . htmlspecialchars($ann['target_grade'] ?? '-') . "</td>";
            echo "<td>" . htmlspecialchars($ann['target_section'] ?? '-') . "</td>";
            echo "<td><span class='badge {$statusClass}'>" . htmlspecialchars($ann['status']) . "</span></td>";
            echo "<td>" . ($ann['is_pinned'] ? '📌' : '-') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<div class='warning'>⚠ No teacher announcements found. Create some first!</div>";
    }
    
    // Test 3: Test filtering logic for each student
    echo "<h2>Test 3: Announcement Visibility per Student</h2>";
    
    foreach ($students as $student) {
        echo "<h3>Student: " . htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) . 
             " (Grade " . $student['grade_level'] . $student['section'] . ")</h3>";
        
        $grade_level = $student['grade_level'];
        $section = $student['section'];
        $student_id = $student['user_id'];
        
        // Get school announcements
        $stmt = $student_conn->prepare("
            SELECT COUNT(*) as count 
            FROM announcements 
            WHERE is_active = 1 
            AND (end_date IS NULL OR end_date >= NOW())
        ");
        $stmt->execute();
        $school_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Get teacher announcements
        $query = "SELECT a.*, 
                         t.first_name as teacher_first_name,
                         t.last_name as teacher_last_name,
                         CONCAT(t.first_name, ' ', t.last_name) as teacher_name
                  FROM announcements a
                  LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
                  WHERE a.status = 'published'
                  AND (a.end_date IS NULL OR a.end_date >= CURDATE())
                  AND (
                      a.target_audience = 'all'
                      OR (a.target_audience = 'specific_grade' AND a.target_grade = :grade_level)
                      OR (a.target_audience = 'specific_section' AND a.target_grade = :grade_level AND a.target_section = :section)
                      OR (a.target_audience = 'specific_class' AND a.target_class_id IN (
                          SELECT c.id FROM classes c 
                          WHERE c.grade_level = :grade_level2 AND c.section = :section2
                      ))
                  )
                  ORDER BY a.is_pinned DESC, a.start_date DESC";
        
        $stmt = $teacher_conn->prepare($query);
        $stmt->bindParam(':grade_level', $grade_level, PDO::PARAM_INT);
        $stmt->bindParam(':section', $section, PDO::PARAM_STR);
        $stmt->bindParam(':grade_level2', $grade_level, PDO::PARAM_INT);
        $stmt->bindParam(':section2', $section, PDO::PARAM_STR);
        $stmt->execute();
        $teacher_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $teacher_count = count($teacher_announcements);
        
        echo "<div class='info'>";
        echo "📘 School announcements: <strong>{$school_count}</strong><br>";
        echo "👨‍🏫 Teacher announcements: <strong>{$teacher_count}</strong><br>";
        echo "📊 Total visible: <strong>" . ($school_count + $teacher_count) . "</strong>";
        echo "</div>";
        
        if ($teacher_count > 0) {
            echo "<table>";
            echo "<tr><th>Title</th><th>Teacher</th><th>Target</th><th>Priority</th></tr>";
            foreach ($teacher_announcements as $ann) {
                $priority = $ann['is_pinned'] ? 'HIGH' : 'MEDIUM';
                $priorityClass = $ann['is_pinned'] ? 'badge-red' : 'badge-blue';
                echo "<tr>";
                echo "<td><span class='badge badge-purple'>TEACHER</span> " . htmlspecialchars($ann['title']) . "</td>";
                echo "<td>" . htmlspecialchars($ann['teacher_name'] ?? 'N/A') . "</td>";
                echo "<td>" . htmlspecialchars($ann['target_audience']) . "</td>";
                echo "<td><span class='badge {$priorityClass}'>{$priority}</span></td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    }
    
    // Test 4: Simulate API call
    echo "<h2>Test 4: Simulate API Response</h2>";
    
    if (count($students) > 0) {
        $test_student = $students[0];
        $student_id = $test_student['user_id'];
        $grade_level = $test_student['grade_level'];
        $section = $test_student['section'];
        
        echo "<div class='info'>Testing for: " . htmlspecialchars($test_student['first_name'] . ' ' . $test_student['last_name']) . 
             " (Grade {$grade_level}{$section})</div>";
        
        // Get school announcements
        $stmt = $student_conn->prepare("
            SELECT a.*, 
                   'school' as source,
                   'School Administration' as posted_by
            FROM announcements a 
            WHERE a.is_active = 1 
            AND (a.end_date IS NULL OR a.end_date >= NOW())
            ORDER BY 
              CASE a.priority 
                  WHEN 'high' THEN 1 
                  WHEN 'medium' THEN 2 
                  ELSE 3 
              END, 
              a.start_date DESC
        ");
        $stmt->execute();
        $all_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get teacher announcements
        $query = "SELECT a.*, 
                         t.first_name as teacher_first_name,
                         t.last_name as teacher_last_name,
                         CONCAT(t.first_name, ' ', t.last_name) as posted_by,
                         'teacher' as source,
                         a.content as description
                  FROM announcements a
                  LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
                  WHERE a.status = 'published'
                  AND (a.end_date IS NULL OR a.end_date >= CURDATE())
                  AND (
                      a.target_audience = 'all'
                      OR (a.target_audience = 'specific_grade' AND a.target_grade = :grade_level)
                      OR (a.target_audience = 'specific_section' AND a.target_grade = :grade_level AND a.target_section = :section)
                      OR (a.target_audience = 'specific_class' AND a.target_class_id IN (
                          SELECT c.id FROM classes c 
                          WHERE c.grade_level = :grade_level2 AND c.section = :section2
                      ))
                  )
                  ORDER BY a.is_pinned DESC, a.start_date DESC";
        
        $stmt = $teacher_conn->prepare($query);
        $stmt->bindParam(':grade_level', $grade_level, PDO::PARAM_INT);
        $stmt->bindParam(':section', $section, PDO::PARAM_STR);
        $stmt->bindParam(':grade_level2', $grade_level, PDO::PARAM_INT);
        $stmt->bindParam(':section2', $section, PDO::PARAM_STR);
        $stmt->execute();
        $teacher_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format teacher announcements
        foreach ($teacher_announcements as &$announcement) {
            if ($announcement['is_pinned']) {
                $announcement['priority'] = 'high';
            } else {
                $announcement['priority'] = 'medium';
            }
        }
        
        $all_announcements = array_merge($all_announcements, $teacher_announcements);
        
        echo "<div class='success'>✓ API would return " . count($all_announcements) . " announcements</div>";
        echo "<pre>" . json_encode([
            'success' => true,
            'announcements' => array_map(function($a) {
                return [
                    'id' => $a['id'],
                    'title' => $a['title'],
                    'source' => $a['source'],
                    'posted_by' => $a['posted_by'],
                    'priority' => $a['priority'] ?? 'medium'
                ];
            }, $all_announcements)
        ], JSON_PRETTY_PRINT) . "</pre>";
    }
    
    // Summary
    echo "<h2>Summary & Recommendations</h2>";
    
    if ($count === 0) {
        echo "<div class='warning'>";
        echo "<h3>⚠ No Teacher Announcements Found</h3>";
        echo "<p><strong>Action Required:</strong></p>";
        echo "<ol>";
        echo "<li>Login as a teacher</li>";
        echo "<li>Go to <a href='teacher/announcements.php'>teacher/announcements.php</a></li>";
        echo "<li>Click 'New Announcement'</li>";
        echo "<li>Create an announcement with target audience matching student grade/section</li>";
        echo "<li>Refresh student portal to see the announcement</li>";
        echo "</ol>";
        echo "</div>";
    } else {
        $published_count = 0;
        foreach ($announcements as $ann) {
            if ($ann['status'] === 'published') $published_count++;
        }
        
        if ($published_count === 0) {
            echo "<div class='warning'>";
            echo "<h3>⚠ No Published Announcements</h3>";
            echo "<p>All announcements are in draft or archived status. Publish them to make them visible to students.</p>";
            echo "</div>";
        } else {
            echo "<div class='success'>";
            echo "<h3>✓ System is Working!</h3>";
            echo "<p>Teacher announcements are configured correctly and will appear in the student portal.</p>";
            echo "<p><strong>Next steps:</strong></p>";
            echo "<ul>";
            echo "<li>Login as student (user_id: " . ($students[0]['user_id'] ?? 'N/A') . ")</li>";
            echo "<li>Go to <a href='student/announcements.php'>student/announcements.php</a></li>";
            echo "<li>Look for announcements with purple 'Class Announcement' badges</li>";
            echo "</ul>";
            echo "</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h3>✗ Error:</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    echo "</div>";
}
?>
