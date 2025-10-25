<?php
// Script to remove all references to transcript requests from the registrar's portal

$files = [
    'view_students.php',
    'view_sections.php',
    'transcript_requests.php',
    'dashboard.php',
    'enrollment_reports.php',
    'demographic_reports.php'
];

foreach ($files as $file) {
    $filePath = __DIR__ . '/' . $file;
    
    if (!file_exists($filePath)) {
        echo "File not found: $file\n";
        continue;
    }
    
    $content = file_get_contents($filePath);
    
    // Remove transcript requests link from reports submenu
    $content = preg_replace(
        '/<a href="transcript_requests\.php" class="[^"]*?\\s*?flex items-center p-2 rounded-lg[^"]*?\\s*?transition-colors[^"]*?">\\s*<i class="fas fa-file-certificate w-5"><\\/i>\\s*<span class="ml-3 sidebar-text">Transcript Requests<\\/span>\\s*<\\/a>\\s*/',
        '',
        $content
    );
    
    // Remove transcript_requests.php from in_array checks in reports submenu
    $content = str_replace(
        ["in_array(\$current_page, ['enrollment_reports.php', 'demographic_reports.php', 'transcript_requests.php'])",
         "in_array(\$current_page, ['enrollment_reports.php', 'demographic_reports.php'])"],
        "in_array(\$current_page, ['enrollment_reports.php', 'demographic_reports.php'])",
        $content
    );
    
    // Remove transcript requests section from dashboard if it exists
    if ($file === 'dashboard.php') {
        // This pattern might need adjustment based on the actual dashboard content
        $content = preg_replace(
            '/<div class="[^"]*?transcript-requests[^"]*?\".*?<\\/div>\\s*<\\/div>\\s*<\\/div>/s',
            '',
            $content
        );
    }
    
    // Save the updated content
    file_put_contents($filePath, $content);
    echo "Updated: $file\n";
}

// Remove the transcript_requests.php file if it exists
$transcriptFile = __DIR__ . '/transcript_requests.php';
if (file_exists($transcriptFile)) {
    if (unlink($transcriptFile)) {
        echo "Removed: transcript_requests.php\n";
    } else {
        echo "Failed to remove transcript_requests.php\n";
    }
}

// Remove the process_transcript.php file if it exists
$processFile = __DIR__ . '/process_transcript.php';
if (file_exists($processFile)) {
    if (unlink($processFile)) {
        echo "Removed: process_transcript.php\n";
    } else {
        echo "Failed to remove process_transcript.php\n";
    }
}

echo "Transcript requests removal complete!\n";
