<?php
/**
 * Sync Sections Script
 * Copies unique grade/section combinations from class_schedules to class_sections
 */

session_start();
require_once 'config/database.php';

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    die('Unauthorized access. Please login as registrar.');
}

echo "<h2>Syncing Sections from Class Schedules to Class Sections</h2>";
echo "<pre>";

try {
    $database = new Database();
    $pdo = $database->getConnection('registrar');
    
    echo "Connected to registrar_db\n\n";
    
    // Get unique grade/section combinations from class_schedules
    echo "Step 1: Finding unique sections in class_schedules...\n";
    $stmt = $pdo->query("
        SELECT DISTINCT 
            grade_level, 
            section,
            school_year
        FROM class_schedules
        WHERE grade_level IS NOT NULL 
        AND section IS NOT NULL
        ORDER BY grade_level, section
    ");
    
    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Found " . count($schedules) . " unique grade/section combinations\n\n";
    
    if (count($schedules) == 0) {
        echo "No sections found in class_schedules table.\n";
        echo "Please add some class schedules first.\n";
        exit;
    }
    
    // Display found sections
    echo "Sections found:\n";
    foreach ($schedules as $schedule) {
        echo "  - Grade {$schedule['grade_level']} - {$schedule['section']} ({$schedule['school_year']})\n";
    }
    echo "\n";
    
    // Sync to class_sections
    echo "Step 2: Syncing to class_sections table...\n";
    $added = 0;
    $skipped = 0;
    
    foreach ($schedules as $schedule) {
        $grade_level = $schedule['grade_level'];
        $section = $schedule['section'];
        $school_year = $schedule['school_year'];
        
        // Check if section already exists
        $stmt_check = $pdo->prepare("
            SELECT id FROM class_sections 
            WHERE grade_level = :grade_level 
            AND section = :section
        ");
        $stmt_check->execute([
            ':grade_level' => $grade_level,
            ':section' => $section
        ]);
        
        if ($stmt_check->fetch()) {
            echo "  ✓ Grade {$grade_level} - {$section} already exists (skipped)\n";
            $skipped++;
        } else {
            // Insert new section
            $stmt_insert = $pdo->prepare("
                INSERT INTO class_sections 
                (grade_level, section, school_year, max_students, status, created_at, updated_at)
                VALUES 
                (:grade_level, :section, :school_year, 40, 'active', NOW(), NOW())
            ");
            
            $stmt_insert->execute([
                ':grade_level' => $grade_level,
                ':section' => $section,
                ':school_year' => $school_year
            ]);
            
            echo "  ✓ Grade {$grade_level} - {$section} added successfully\n";
            $added++;
        }
    }
    
    echo "\n";
    echo "========================================\n";
    echo "✓ SYNC COMPLETED!\n";
    echo "========================================\n";
    echo "Added: {$added} new sections\n";
    echo "Skipped: {$skipped} existing sections\n";
    echo "Total: " . count($schedules) . " sections processed\n\n";
    
    // Show current class_sections
    echo "Step 3: Current sections in class_sections table:\n";
    $stmt_all = $pdo->query("
        SELECT 
            cs.*,
            (SELECT COUNT(*) FROM students s 
             WHERE s.grade_level = cs.grade_level 
             AND s.section = cs.section) as student_count
        FROM class_sections cs
        ORDER BY cs.grade_level, cs.section
    ");
    
    $all_sections = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($all_sections) > 0) {
        echo "\n";
        echo "ID | Grade | Section      | Students | Max | Status\n";
        echo "---+-------+--------------+----------+-----+--------\n";
        foreach ($all_sections as $sec) {
            printf(
                "%-3d| %-6s| %-13s| %-9s| %-4s| %s\n",
                $sec['id'],
                $sec['grade_level'],
                $sec['section'],
                $sec['student_count'],
                $sec['max_students'],
                $sec['status']
            );
        }
    }
    
    echo "\n";
    echo "You can now view these sections in:\n";
    echo "Registrar Dashboard → Class Management → View Sections\n";
    
} catch (PDOException $e) {
    echo "\n";
    echo "========================================\n";
    echo "✗ ERROR!\n";
    echo "========================================\n";
    echo "Database error: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo "<br><a href='registrar/view_sections.php'>← Go to View Sections</a>";
echo " | ";
echo "<a href='registrar/class_schedules.php'>← Go to Class Schedules</a>";
?>
