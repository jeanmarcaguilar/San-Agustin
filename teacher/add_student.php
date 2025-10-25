<?php
// Start session and error reporting
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'teacher') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Include database connection
require_once '../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Initialize response array
$response = ['success' => false, 'message' => ''];

// Function to log errors
function logError($message, $data = []) {
    $logMessage = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    if (!empty($data)) {
        $logMessage .= 'Data: ' . print_r($data, true) . "\n";
    }
    error_log($logMessage, 3, __DIR__ . '/student_errors.log');
}
try {
    // Get POST data
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $student_id = $_POST['student_id'] ?? '';
    $class_id = $_POST['class_id'] ?? '';
    $email = $_POST['email'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($student_id) || empty($class_id)) {
        throw new Exception('Please fill in all required fields');
    }
    
    // Clean and format student ID
    $student_id = trim($student_id);
    
    // If ID doesn't start with S-, add it
    if (strtoupper(substr($student_id, 0, 2)) !== 'S-') {
        $student_id = 'S-' . ltrim($student_id, 'S-');
    }
    
    // Ensure the rest is alphanumeric with optional hyphens and underscores
    if (!preg_match('/^S-[a-zA-Z0-9_-]+$/', $student_id)) {
        throw new Exception('Student ID must start with S- followed by letters, numbers, hyphens, or underscores');
    }
    
    // Get database connection
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    $login_conn = $database->getLoginConnection();
    
    // Set error mode to exception
    $teacher_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $login_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Start transaction
    $teacher_conn->beginTransaction();
    $login_conn->beginTransaction();
    
    // Flag to track if we should commit
    $commit = false;
    
    try {
        // Check if student ID already exists
        $stmt = $teacher_conn->prepare("SELECT id FROM students WHERE student_id = ?");
        $stmt->execute([$student_id]);
        
        if ($stmt->rowCount() > 0) {
            throw new Exception('A student with this ID already exists');
        }
        
        // Insert into students table
        $stmt = $teacher_conn->prepare("
            INSERT INTO students (
                student_id, first_name, last_name, email, contact_number, created_at, updated_at
            ) VALUES (
                :student_id, :first_name, :last_name, :email, :contact_number, NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            ':student_id' => $student_id,
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':email' => $email ?: null,
            ':contact_number' => $contact_number ?: null
        ]);
        
        // Enroll student in the selected class
        $stmt = $teacher_conn->prepare("
            INSERT INTO class_students (
                student_id, class_id, enrollment_date, status, created_at, updated_at
            ) VALUES (
                :student_id, :class_id, CURDATE(), 'active', NOW(), NOW()
            )
        ");
        
        $stmt->execute([
            ':student_id' => $student_id,
            ':class_id' => $class_id
        ]);
        
        // Create a login account for the student
        $default_password = password_hash('password123', PASSWORD_DEFAULT); // Default password, should be changed on first login
        $username = strtolower($first_name . '.' . $last_name);
        
        // Make sure username is unique
        $counter = 1;
        $original_username = $username;
        
        while (true) {
            $stmt = $login_conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            
            if ($stmt->rowCount() === 0) {
                break;
            }
            $username = $original_username . $counter;
            $counter++;
        }
        
        // Generate email if not provided
        $student_email = !empty($email) ? $email : ($username . '@school.edu');
        
        // Insert into users table
        try {
            $stmt = $login_conn->prepare("
                INSERT INTO users (
                    username, password, role, email, created_at, updated_at
                ) VALUES (
                    :username, :password, 'student', :email, NOW(), NOW()
                )
            ");
            
            $result = $stmt->execute([
                ':username' => $username,
                ':password' => $default_password,
                ':email' => $student_email
            ]);
            
            if (!$result) {
                $errorInfo = $login_conn->errorInfo();
                throw new Exception('Database error: ' . ($errorInfo[2] ?? 'Unknown error'));
            }
            
            $user_id = $login_conn->lastInsertId();
            
            // Update student record with user_id
            $stmt = $teacher_conn->prepare("UPDATE students SET user_id = ? WHERE student_id = ?");
            $stmt->execute([$user_id, $student_id]);
            
            // If we got here, both transactions were successful
            $commit = true;
            $teacher_conn->commit();
            $login_conn->commit();
            
        } catch (PDOException $e) {
            $teacher_conn->rollBack();
            throw new Exception('Failed to create user account: ' . $e->getMessage());
        }
        
        // Get the newly added student data with all required fields
        $stmt = $teacher_conn->prepare("
            SELECT s.*, c.subject, c.grade_level, c.section 
            FROM students s 
            LEFT JOIN class_students cs ON s.student_id = cs.student_id 
            LEFT JOIN classes c ON cs.class_id = c.id 
            WHERE s.student_id = ?
        ");
        $stmt->execute([$student_id]);
        $new_student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$new_student) {
            throw new Exception('Failed to retrieve the newly added student data');
        }
        
        $response = [
            'success' => true,
            'message' => 'Student added successfully',
            'student' => [
                'id' => $new_student['id'],
                'student_id' => $new_student['student_id'],
                'first_name' => $new_student['first_name'],
                'last_name' => $new_student['last_name'],
                'name' => $new_student['first_name'] . ' ' . $new_student['last_name'],
                'email' => $new_student['email'],
                'contact_number' => $new_student['contact_number'],
                'subject' => $new_student['subject'] ?? '',
                'grade_level' => $new_student['grade_level'] ?? '',
                'section' => $new_student['section'] ?? '',
                'username' => $username,
                'default_password' => 'password123' // Only for display, not for production
            ]
        ];
    } catch (PDOException $e) {
        // Log the error
        error_log('Database error in add_student.php: ' . $e->getMessage());
        
        // Only rollback if we're still in a transaction
        if ($teacher_conn->inTransaction()) {
            $teacher_conn->rollBack();
        }
        if ($login_conn->inTransaction()) {
            $login_conn->rollBack();
        }
        
        throw new Exception('Database error: ' . $e->getMessage());
    } catch (Exception $e) {
        // Only rollback if we're still in a transaction
        if ($teacher_conn->inTransaction()) {
            $teacher_conn->rollBack();
        }
        if ($login_conn->inTransaction()) {
            $login_conn->rollBack();
        }
        throw $e;
    } finally {
        // If we didn't set commit to true, make sure to rollback any remaining transactions
        if (!$commit) {
            if (isset($teacher_conn) && $teacher_conn->inTransaction()) {
                $teacher_conn->rollBack();
            }
            if (isset($login_conn) && $login_conn->inTransaction()) {
                $login_conn->rollBack();
            }
        }
    }
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $response = [
        'success' => false,
        'message' => 'An error occurred while adding the student',
        'debug' => $errorMessage,
        'trace' => $e->getTraceAsString()
    ];
    
    // Log detailed error
    $errorData = [
        'error' => $errorMessage,
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
        'post_data' => $_POST,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Log to PHP error log
    error_log('Add Student Error: ' . print_r($errorData, true));
    
    // Also log to custom error log
    if (!function_exists('logError')) {
        function logError($message, $data = []) {
            $logMessage = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
            $logMessage .= 'Data: ' . print_r($data, true) . PHP_EOL;
            $logMessage .= '----------------------------------------' . PHP_EOL;
            file_put_contents(__DIR__ . '/student_errors.log', $logMessage, FILE_APPEND);
        }
    }
    
    logError('Add Student Error', $errorData);
}

// Return JSON response
echo json_encode($response);
