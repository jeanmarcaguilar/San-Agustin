<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Initialize response array
$response = ['success' => false, 'message' => ''];

try {
    // Only allow POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    // Get POST data
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $contact_number = trim($_POST['contact_number'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $grade_level = trim($_POST['grade_level'] ?? '');
    $section = trim($_POST['section'] ?? '');
    $lrn = trim($_POST['lrn'] ?? '');

    // Validate required fields
    $required = [
        'First Name' => $first_name,
        'Last Name' => $last_name,
        'Email' => $email,
        'Username' => $username,
        'Password' => $password,
        'Grade Level' => $grade_level,
        'LRN' => $lrn
    ];

    $missing = [];
    foreach ($required as $field => $value) {
        if (empty($value)) {
            $missing[] = $field;
        }
    }

    if (!empty($missing)) {
        throw new Exception('Please fill in all required fields: ' . implode(', ', $missing));
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Please enter a valid email address');
    }

    // Validate password strength and match
    if (strlen($password) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }

    if ($password !== $confirm_password) {
        throw new Exception('Passwords do not match');
    }

    // Generate student ID (format: S-YYYY-XXXXX)
    $student_id = 'S-' . date('Y') . '-' . str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT);

    // Get database connections
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    $student_conn = $database->getConnection('student');
    $registrar_conn = $database->getConnection('registrar');
    $login_conn = $database->getLoginConnection();

    // Start transactions
    $teacher_conn->beginTransaction();
    $student_conn->beginTransaction();
    $registrar_conn->beginTransaction();
    $login_conn->beginTransaction();

    try {
        // Check if username already exists
        $stmt = $login_conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->rowCount() > 0) {
            throw new Exception('Username already exists. Please choose a different username.');
        }

        // Check if email already exists
        $stmt = $teacher_conn->prepare("SELECT id FROM students WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->rowCount() > 0) {
            throw new Exception('Email already registered. Please use a different email or login instead.');
        }

        // Check if LRN already exists
        if (!empty($lrn)) {
            $stmt = $teacher_conn->prepare("SELECT id FROM students WHERE lrn = ?");
            $stmt->execute([$lrn]);
            if ($stmt->rowCount() > 0) {
                throw new Exception('LRN already registered. Please contact the registrar if you believe this is an error.');
            }
        }

        // Hash password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);

        // Insert into users table for login first
        $stmt = $login_conn->prepare("
            INSERT INTO users (
                username, password, email, role, created_at, updated_at
            ) VALUES (
                :username, :password, :email, 'student', NOW(), NOW()
            )
        ");

        $stmt->execute([
            ':username' => $username,
            ':password' => $hashed_password,
            ':email' => $email
        ]);

        $user_id = $login_conn->lastInsertId();
        $school_year = date('Y') . '-' . (date('Y') + 1);

        // Insert into teacher database students table
        $stmt = $teacher_conn->prepare("
            INSERT INTO students (
                user_id, student_id, first_name, last_name, email, contact_number, 
                grade_level, section, lrn, created_at, updated_at
            ) VALUES (
                :user_id, :student_id, :first_name, :last_name, :email, :contact_number,
                :grade_level, :section, :lrn, NOW(), NOW()
            )
        ");

        $stmt->execute([
            ':user_id' => $user_id,
            ':student_id' => $student_id,
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':email' => $email,
            ':contact_number' => $contact_number ?: null,
            ':grade_level' => $grade_level,
            ':section' => $section ?: null,
            ':lrn' => $lrn
        ]);

        $teacher_student_id = $teacher_conn->lastInsertId();

        // Insert into student database
        $stmt = $student_conn->prepare("
            INSERT INTO students (
                user_id, student_id, first_name, last_name, grade_level, section,
                school_year, status, created_at, updated_at
            ) VALUES (
                :user_id, :student_id, :first_name, :last_name, :grade_level, :section,
                :school_year, 'Active', NOW(), NOW()
            )
        ");

        $stmt->execute([
            ':user_id' => $user_id,
            ':student_id' => $student_id,
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':grade_level' => $grade_level,
            ':section' => $section ?: 'A',
            ':school_year' => $school_year
        ]);

        $student_db_id = $student_conn->lastInsertId();

        // Insert into registrar database
        $stmt = $registrar_conn->prepare("
            INSERT INTO students (
                user_id, student_id, first_name, last_name, email, contact_number,
                grade_level, section, school_year, lrn, birthdate, status, is_active,
                created_at, updated_at
            ) VALUES (
                :user_id, :student_id, :first_name, :last_name, :email, :contact_number,
                :grade_level, :section, :school_year, :lrn, :birthdate, 'Active', 1,
                NOW(), NOW()
            )
        ");

        $stmt->execute([
            ':user_id' => $user_id,
            ':student_id' => $student_id,
            ':first_name' => $first_name,
            ':last_name' => $last_name,
            ':email' => $email,
            ':contact_number' => $contact_number ?: null,
            ':grade_level' => $grade_level,
            ':section' => $section ?: null,
            ':school_year' => $school_year,
            ':lrn' => $lrn,
            ':birthdate' => date('Y-m-d')
        ]);

        $registrar_student_id = $registrar_conn->lastInsertId();

        // Commit all transactions
        $teacher_conn->commit();
        $student_conn->commit();
        $registrar_conn->commit();
        $login_conn->commit();

        $response = [
            'success' => true,
            'message' => 'Registration successful! You can now login with your credentials.',
            'student_id' => $student_id
        ];

    } catch (Exception $e) {
        // Rollback all transactions on error
        if ($teacher_conn->inTransaction()) {
            $teacher_conn->rollBack();
        }
        if ($student_conn->inTransaction()) {
            $student_conn->rollBack();
        }
        if ($registrar_conn->inTransaction()) {
            $registrar_conn->rollBack();
        }
        if ($login_conn->inTransaction()) {
            $login_conn->rollBack();
        }
        throw $e;
    }

} catch (Exception $e) {
    $response['message'] = $e->getMessage();
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
?>
