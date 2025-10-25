<?php
/**
 * Student-Librarian Integration Script
 * Syncs students from student_db to patrons table in librarian_db
 */

require_once __DIR__ . '/config/database.php';

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Student-Librarian Integration</title>
    <script src='https://cdn.tailwindcss.com'></script>
</head>
<body class='bg-gray-100 p-8'>
    <div class='max-w-4xl mx-auto bg-white rounded-lg shadow-lg p-8'>
        <h1 class='text-3xl font-bold text-blue-600 mb-6'>📚 Student-Librarian Integration</h1>
        <div class='space-y-4'>";

try {
    $database = new Database();
    $studentDb = $database->getConnection('student');
    $librarianDb = $database->getConnection('librarian');
    
    echo "<div class='bg-blue-50 border-l-4 border-blue-500 p-4'>
            <p class='font-semibold'>✓ Database connections established</p>
          </div>";
    
    // Get all students from student_db
    $studentsQuery = "SELECT id, user_id, student_id, first_name, last_name, 
                             grade_level, section, parent_contact, address, created_at
                      FROM students 
                      WHERE status = 'Active'";
    $studentsStmt = $studentDb->query($studentsQuery);
    $students = $studentsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<div class='bg-green-50 border-l-4 border-green-500 p-4'>
            <p class='font-semibold'>✓ Found " . count($students) . " active students</p>
          </div>";
    
    // Get user emails from login_db
    $loginDb = $database->getConnection('');
    
    $synced = 0;
    $updated = 0;
    $errors = [];
    
    foreach ($students as $student) {
        try {
            // Get email from login_db
            $emailQuery = "SELECT email FROM users WHERE id = ?";
            $emailStmt = $loginDb->prepare($emailQuery);
            $emailStmt->execute([$student['user_id']]);
            $userEmail = $emailStmt->fetch(PDO::FETCH_ASSOC);
            
            $email = $userEmail ? $userEmail['email'] : "student{$student['user_id']}@sanagustin.edu";
            
            // Check if patron already exists
            $checkQuery = "SELECT patron_id FROM patrons WHERE user_id = ?";
            $checkStmt = $librarianDb->prepare($checkQuery);
            $checkStmt->execute([$student['user_id']]);
            $existingPatron = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingPatron) {
                // Update existing patron
                $updateQuery = "UPDATE patrons SET 
                                first_name = ?,
                                last_name = ?,
                                email = ?,
                                contact_number = ?,
                                address = ?,
                                status = 'active',
                                updated_at = NOW()
                                WHERE user_id = ?";
                $updateStmt = $librarianDb->prepare($updateQuery);
                $updateStmt->execute([
                    $student['first_name'],
                    $student['last_name'],
                    $email,
                    $student['parent_contact'],
                    $student['address'],
                    $student['user_id']
                ]);
                $updated++;
            } else {
                // Insert new patron
                $insertQuery = "INSERT INTO patrons 
                                (user_id, first_name, last_name, email, contact_number, 
                                 address, membership_date, membership_expiry, status, max_books_allowed)
                                VALUES (?, ?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'active', 5)";
                $insertStmt = $librarianDb->prepare($insertQuery);
                $insertStmt->execute([
                    $student['user_id'],
                    $student['first_name'],
                    $student['last_name'],
                    $email,
                    $student['parent_contact'],
                    $student['address']
                ]);
                $synced++;
            }
            
        } catch (Exception $e) {
            $errors[] = "Error syncing student {$student['first_name']} {$student['last_name']}: " . $e->getMessage();
        }
    }
    
    echo "<div class='bg-green-50 border-l-4 border-green-500 p-4'>
            <p class='font-semibold'>✓ Successfully synced {$synced} new students as patrons</p>
          </div>";
    
    if ($updated > 0) {
        echo "<div class='bg-blue-50 border-l-4 border-blue-500 p-4'>
                <p class='font-semibold'>✓ Updated {$updated} existing patron records</p>
              </div>";
    }
    
    if (!empty($errors)) {
        echo "<div class='bg-red-50 border-l-4 border-red-500 p-4'>
                <p class='font-semibold text-red-700'>⚠ Errors encountered:</p>
                <ul class='list-disc list-inside mt-2 text-sm text-red-600'>";
        foreach ($errors as $error) {
            echo "<li>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul></div>";
    }
    
    // Display summary
    $totalPatrons = $librarianDb->query("SELECT COUNT(*) FROM patrons")->fetchColumn();
    
    echo "<div class='mt-6 p-6 bg-gradient-to-r from-blue-500 to-purple-600 text-white rounded-lg'>
            <h2 class='text-2xl font-bold mb-4'>📊 Integration Summary</h2>
            <div class='grid grid-cols-2 gap-4'>
                <div class='bg-white bg-opacity-20 rounded p-4'>
                    <p class='text-sm opacity-90'>Total Students</p>
                    <p class='text-3xl font-bold'>" . count($students) . "</p>
                </div>
                <div class='bg-white bg-opacity-20 rounded p-4'>
                    <p class='text-sm opacity-90'>Total Patrons</p>
                    <p class='text-3xl font-bold'>{$totalPatrons}</p>
                </div>
                <div class='bg-white bg-opacity-20 rounded p-4'>
                    <p class='text-sm opacity-90'>Newly Synced</p>
                    <p class='text-3xl font-bold'>{$synced}</p>
                </div>
                <div class='bg-white bg-opacity-20 rounded p-4'>
                    <p class='text-sm opacity-90'>Updated</p>
                    <p class='text-3xl font-bold'>{$updated}</p>
                </div>
            </div>
          </div>";
    
    echo "<div class='mt-6 bg-green-50 border border-green-200 rounded-lg p-6'>
            <h3 class='text-lg font-bold text-green-800 mb-2'>✅ Integration Complete!</h3>
            <p class='text-green-700'>Students can now:</p>
            <ul class='list-disc list-inside mt-2 text-green-600 space-y-1'>
                <li>Browse available books</li>
                <li>Borrow books from the library</li>
                <li>View their borrowing history</li>
                <li>Return borrowed books</li>
            </ul>
          </div>";
    
    echo "<div class='mt-6 flex gap-4'>
            <a href='student/available-books.php' class='bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg font-semibold'>
                📚 View Available Books
            </a>
            <a href='librarian/books.php' class='bg-purple-600 hover:bg-purple-700 text-white px-6 py-3 rounded-lg font-semibold'>
                📖 Librarian Dashboard
            </a>
          </div>";
    
} catch (Exception $e) {
    echo "<div class='bg-red-50 border-l-4 border-red-500 p-4'>
            <p class='font-semibold text-red-700'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>
          </div>";
}

echo "    </div>
    </div>
</body>
</html>";
?>
