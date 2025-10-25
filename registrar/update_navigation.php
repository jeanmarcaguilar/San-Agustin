<?php
// Script to update navigation menus by removing student assignment links

$files = [
    'student_search.php',
    'settings.php',
    'reports.php',
    'profile.php',
    'enrollment_reports.php',
    'documents.php',
    'demographic_reports.php',
    'class_assignment.php',
    'attendance.php',
    'add_student.php'
];

foreach ($files as $file) {
    $filePath = __DIR__ . '/' . $file;
    
    if (!file_exists($filePath)) {
        echo "File not found: $file\n";
        continue;
    }
    
    $content = file_get_contents($filePath);
    
    // Remove student assignment link from class management submenu
    $content = preg_replace(
        '/<a href="class_assignment\.php" class="[^"]*?\s*?flex items-center p-2 rounded-lg[^"]*?\s*?transition-colors[^"]*?">\s*<i class="fas fa-tasks w-5"><\/i>\s*<span class="ml-3 sidebar-text">Student Assignment<\/span>\s*<\/a>\s*/',
        '',
        $content
    );
    
    // Remove class_assignment.php from in_array checks
    $content = str_replace(
        ["in_array(\$current_page, ['view_sections.php', 'class_assignment.php', 'class_schedules.php'])",
         "in_array(\$current_page, ['view_sections.php', 'class_schedules.php'])"],
        "in_array(\$current_page, ['view_sections.php', 'class_schedules.php'])",
        $content
    );
    
    // Remove standalone class assignment links (like in settings.php, reports.php, profile.php)
    $content = preg_replace(
        '/<a href="class_assignment\.php" class="[^"]*?\s*?block py-2\.5 px-4 rounded[^"]*?\s*?transition duration-200[^"]*?\s*?hover:bg-gray-700[^"]*?">\s*<i class="fas fa-users-class mr-2"><\/i>Class Assignment\s*<\/a>\s*/',
        '',
        $content
    );
    
    // Save the updated content
    file_put_contents($filePath, $content);
    echo "Updated: $file\n";
}

echo "Navigation update complete!\n";
