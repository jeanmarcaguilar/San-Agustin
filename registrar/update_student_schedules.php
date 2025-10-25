<?php
// Database connection
require_once '../config/database.php';

$database = new Database();
$pdo = $database->getConnection('registrar');

try {
    // Start transaction
    $pdo->beginTransaction();
    
    // 1. Create student_schedules table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS student_schedules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        subject_id INT NOT NULL,
        teacher_id INT NOT NULL,
        day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday') NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        room VARCHAR(20),
        school_year VARCHAR(9) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
        FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
        UNIQUE KEY unique_student_schedule (student_id, subject_id, day_of_week, start_time, school_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    
    // 2. Add student_id column to class_schedules if it doesn't exist
    $stmt = $pdo->query("SHOW COLUMNS FROM class_schedules LIKE 'student_id'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE class_schedules ADD COLUMN student_id INT NULL AFTER id, 
                    ADD CONSTRAINT fk_student_schedule FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE");
    }
    
    // 3. Add is_individual_schedule flag to class_schedules if it doesn't exist
    $stmt = $pdo->query("SHOW COLUMNS FROM class_schedules LIKE 'is_individual_schedule'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE class_schedules ADD COLUMN is_individual_schedule TINYINT(1) DEFAULT 0 AFTER student_id");
    }
    
    // 4. Update existing records to mark them as group schedules
    $pdo->exec("UPDATE class_schedules SET is_individual_schedule = 0 WHERE is_individual_schedule IS NULL");
    
    // Commit transaction
    $pdo->commit();
    
    echo "Database schema updated successfully!\n";
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $pdo->rollBack();
    die("Error updating database schema: " . $e->getMessage());
}

// Now let's update the class_schedules.php file
$classSchedulesFile = __DIR__ . '/class_schedules.php';
$content = file_get_contents($classSchedulesFile);

// 1. Add student selection to the filter form
$content = preg_replace(
    '/(<div class="grid grid-cols-1 md:grid-cols-5 gap-4">\s*<div>\s*<label for="school_year")/',
    '<div class="grid grid-cols-1 md:grid-cols-6 gap-4">
        <div>
            <label for="student_id" class="block text-sm font-medium text-gray-700 mb-1">Student</label>
            <select name="student_id" id="student_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">All Students</option>
                <?php
                $studentStmt = $pdo->query("SELECT id, CONCAT(last_name, \', \', first_name) as name FROM students ORDER BY last_name, first_name");
                while ($student = $studentStmt->fetch(PDO::FETCH_ASSOC)) {
                    $selected = (isset($_GET[\'student_id\']) && $_GET[\'student_id\'] == $student[\'id\']) ? \'selected\' : \'\';
                    echo "<option value=\"$student[id]\" $selected>$student[name]</option>";
                }
                ?>
            </select>
        </div>
        \1',
    $content
);

// 2. Update the query to filter by student_id if provided
$content = preg_replace(
    '/(if \(!empty\(\$selected_section\)\) \{\s*\$query \.= " AND cs\.section = :section";\s*\$params\[\':section\'\] = \$selected_section;\s*\})/',
    '\1
    
    // Add student filter if provided
    $selected_student = isset($_GET[\'student_id\']) ? (int)$_GET[\'student_id\'] : null;
    if (!empty($selected_student)) {
        $query .= " AND (cs.student_id = :student_id OR cs.student_id IS NULL)";
        $params[\':student_id\'] = $selected_student;
    }',
    $content
);

// 3. Update the form submission handler to handle individual schedules
$content = preg_replace(
    '/(\/\/ Ensure grade_level and section are populated from class_section dropdown\s+const classSection = document\.getElementById\()/',
    '// Check if this is an individual schedule
    const isIndividual = document.getElementById(\'is_individual\').checked;
    const studentId = document.getElementById(\'student_id\').value;
    
    if (isIndividual && !studentId) {
        showToast(\'Please select a student for individual schedule\', \'error\');
        return;
    }
    
    // If individual schedule, clear section and grade level
    if (isIndividual) {
        document.getElementById(\'class_section\').value = \'\';
        document.getElementById(\'grade_level\').value = \'\';
        document.getElementById(\'section\').value = \'\';
    }
    
    \1',
    $content
);

// 4. Add hidden input for student_id in the form
$content = preg_replace(
    '/(<input type="hidden" id="scheduleId" name="id">)/',
    '\1
    <input type="hidden" id="student_id" name="student_id">
    <input type="hidden" id="is_individual" name="is_individual" value="0">',
    $content
);

// 5. Add student selection to the modal form
$content = preg_replace(
    '/(<div class="grid grid-cols-1 md:grid-cols-2 gap-4">\s*<div class="md:col-span-2">\s*<label for="class_section")/',
    '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="md:col-span-2">
            <div class="flex items-center mb-2">
                <input type="checkbox" id="individual_schedule" name="individual_schedule" class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 rounded" onchange="toggleIndividualSchedule(this.checked)">
                <label for="individual_schedule" class="ml-2 block text-sm text-gray-700">Individual Student Schedule</label>
            </div>
            
            <div id="student_selection" class="hidden mb-4">
                <label for="student_select" class="block text-sm font-medium text-gray-700">Select Student <span class="text-red-500">*</span></label>
                <select id="student_select" name="student_id" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500">
                    <option value="">-- Select Student --</option>
                    <?php
                    $stmt = $pdo->query("SELECT id, CONCAT(last_name, \', \', first_name) as name FROM students ORDER BY last_name, first_name");
                    while ($student = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        echo "<option value=\"$student[id]\">$student[name]</option>";
                    }
                    ?>
                </select>
            </div>
            \1',
    $content
);

// 6. Add JavaScript function to toggle individual schedule fields
$jsFunction = <<<'JS'
function toggleIndividualSchedule(isIndividual) {
    const studentSelection = document.getElementById('student_selection');
    const classSection = document.getElementById('class_section').closest('.md\:col-span-2');
    const isIndividualInput = document.getElementById('is_individual');
    
    if (isIndividual) {
        studentSelection.classList.remove('hidden');
        classSection.classList.add('hidden');
        isIndividualInput.value = '1';
    } else {
        studentSelection.classList.add('hidden');
        classSection.classList.remove('hidden');
        isIndividualInput.value = '0';
    }
}

// Update form when editing a schedule
document.addEventListener('DOMContentLoaded', function() {
    // ... existing code ...
    
    // When editing a schedule
    window.editSchedule = function(id) {
        fetch(`get_schedule.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                // ... existing code ...
                
                // Handle individual schedule
                if (data.student_id) {
                    document.getElementById('individual_schedule').checked = true;
                    toggleIndividualSchedule(true);
                    document.getElementById('student_select').value = data.student_id;
                } else {
                    document.getElementById('individual_schedule').checked = false;
                    toggleIndividualSchedule(false);
                }
                
                // ... rest of the code ...
            });
    };
});
JS;

// Insert the JavaScript function before the closing </script> tag
$content = preg_replace(
    '/(<\/script>\s*<\/body>)/',
    $jsFunction . '\n\1',
    $content
);

// Save the updated file
file_put_contents($classSchedulesFile, $content);

echo "Class schedules page updated successfully!\n";
echo "Please run this script again after reviewing the changes to ensure everything is set up correctly.\n";
