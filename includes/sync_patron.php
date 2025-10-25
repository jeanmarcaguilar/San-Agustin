<?php
/**
 * Auto-sync student to patron
 * This file is included when a student logs in to ensure they exist as a patron
 */

function syncStudentToPatron($user_id) {
    try {
        require_once __DIR__ . '/../config/database.php';
        
        $database = new Database();
        $studentDb = $database->getConnection('student');
        $librarianDb = $database->getConnection('librarian');
        $loginDb = $database->getConnection('');
        
        // Get student info
        $studentQuery = "SELECT * FROM students WHERE user_id = ? AND status = 'Active'";
        $studentStmt = $studentDb->prepare($studentQuery);
        $studentStmt->execute([$user_id]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            return false; // Not a student or inactive
        }
        
        // Get email
        $emailQuery = "SELECT email FROM users WHERE id = ?";
        $emailStmt = $loginDb->prepare($emailQuery);
        $emailStmt->execute([$user_id]);
        $userEmail = $emailStmt->fetch(PDO::FETCH_ASSOC);
        $email = $userEmail ? $userEmail['email'] : "student{$user_id}@sanagustin.edu";
        
        // Check if patron exists
        $checkQuery = "SELECT patron_id FROM patrons WHERE user_id = ?";
        $checkStmt = $librarianDb->prepare($checkQuery);
        $checkStmt->execute([$user_id]);
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
                $user_id
            ]);
        } else {
            // Insert new patron
            $insertQuery = "INSERT INTO patrons 
                            (user_id, first_name, last_name, email, contact_number, 
                             address, membership_date, membership_expiry, status, max_books_allowed)
                            VALUES (?, ?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 'active', 5)";
            $insertStmt = $librarianDb->prepare($insertQuery);
            $insertStmt->execute([
                $user_id,
                $student['first_name'],
                $student['last_name'],
                $email,
                $student['parent_contact'],
                $student['address']
            ]);
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log("Error syncing student to patron: " . $e->getMessage());
        return false;
    }
}

// Auto-sync if user_id is set in session and role is student
if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'student') {
    syncStudentToPatron($_SESSION['user_id']);
}
?>
