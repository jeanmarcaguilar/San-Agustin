<?php
// Start session and error reporting
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'registrar') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Include database configuration
require_once '../config/database.php';

// Initialize database connections
$database = new Database();
$registrar_conn = $database->getConnection('registrar');
$student_conn = $database->getConnection('student');
$login_conn = $database->getConnection(''); // login_db

// Function to log errors
function logError($message, $data = []) {
    $logMessage = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    if (!empty($data)) {
        $logMessage .= 'Data: ' . print_r($data, true) . "\n";
    }
    error_log($logMessage, 3, __DIR__ . '/registrar_errors.log');
}

// Initialize response array
$response = ['success' => false, 'message' => ''];

// Initialize variables
$error_message = '';
$success_message = '';
$form_data = [];

// Get current school year
$current_year = date('Y');
$next_year = $current_year + 1;
$school_year = "$current_year-$next_year";

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Get and validate form data
        $form_data = [
            'first_name' => trim($_POST['first_name'] ?? ''),
            'last_name' => trim($_POST['last_name'] ?? ''),
            'middle_name' => trim($_POST['middle_name'] ?? ''),
            'birthdate' => $_POST['birthdate'] ?? '',
            'gender' => $_POST['gender'] ?? '',
            'phone' => trim($_POST['phone'] ?? ''),
            'address' => trim($_POST['address'] ?? ''),
            'grade_level' => (int)($_POST['grade_level'] ?? 0),
            'parent_name' => trim($_POST['parent_name'] ?? ''),
            'parent_contact' => trim($_POST['parent_contact'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'username' => trim($_POST['username'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'confirm_password' => $_POST['confirm_password'] ?? ''
        ];

        // Validate required fields
        $required_fields = [
            'First Name' => $form_data['first_name'],
            'Last Name' => $form_data['last_name'],
            'Date of Birth' => $form_data['birthdate'],
            'Gender' => $form_data['gender'],
            'Address' => $form_data['address'],
            'Grade Level' => $form_data['grade_level'],
            'Parent/Guardian Name' => $form_data['parent_name'],
            'Parent Contact' => $form_data['parent_contact']
        ];

        foreach ($required_fields as $field => $value) {
            if (empty($value)) {
                throw new Exception("$field is required");
            }
        }

        // Generate email if not provided
        if (empty($form_data['email'])) {
            $clean_first = preg_replace('/[^a-z]/', '', strtolower($form_data['first_name']));
            $clean_last = preg_replace('/[^a-z]/', '', strtolower($form_data['last_name']));
            $base_email = $clean_first . '.' . $clean_last . '@sanagustines.edu.ph';
            
            $counter = 1;
            $email = $base_email;
            $stmt = $login_conn->prepare("SELECT id FROM users WHERE email = ?");
            
            while (true) {
                $stmt->execute([$email]);
                if (!$stmt->fetch()) break;
                $email = str_replace('@', $counter . '@', $base_email);
                $counter++;
                if ($counter > 100) {
                    $email = 'student_' . uniqid() . '@sanagustines.edu.ph';
                    break;
                }
            }
            $form_data['email'] = $email;
        } else {
            // Validate email format
            if (!filter_var($form_data['email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address');
            }
            
            // Check if email already exists and auto-generate if taken
            $base_email = $form_data['email'];
            $email = $base_email;
            $counter = 1;
            $stmt = $login_conn->prepare("SELECT id FROM users WHERE email = ?");
            
            while (true) {
                $stmt->execute([$email]);
                if (!$stmt->fetch()) break;
                
                // Add number before @ symbol
                $email_parts = explode('@', $base_email);
                $email = $email_parts[0] . $counter . '@' . $email_parts[1];
                $counter++;
                
                if ($counter > 100) {
                    $email = 'student_' . uniqid() . '@sanagustines.edu.ph';
                    break;
                }
            }
            $form_data['email'] = $email;
        }

        // Generate username if not provided
        if (empty($form_data['username'])) {
            // Create base username from first letter of first name and full last name
            $clean_username = strtolower(substr($form_data['first_name'], 0, 1) . 
                          preg_replace('/[^a-z0-9]/', '', strtolower($form_data['last_name'])));
            
            // If username is too short, add more characters from first name
            if (strlen($clean_username) < 3) {
                $clean_username = strtolower(substr($form_data['first_name'], 0, 3) . 
                              preg_replace('/[^a-z0-9]/', '', strtolower($form_data['last_name'])));
            }
            
            $counter = 1;
            $username = $clean_username;
            $max_attempts = 50; // Reasonable limit to prevent infinite loops
            $attempts = 0;
            
            // Check if username exists
            $stmt = $login_conn->prepare("SELECT id FROM users WHERE username = ?");
            
            do {
                $stmt->execute([$username]);
                $exists = $stmt->fetch();
                
                if (!$exists) {
                    break; // Username is available
                }
                
                // Try with counter
                $username = $clean_username . $counter;
                $counter++;
                $attempts++;
                
                // If we've tried too many times, generate a random username
                if ($attempts > $max_attempts) {
                    $username = 'stu' . substr(uniqid(), -6);
                    break;
                }
            } while (true);
            
            $form_data['username'] = $username;
        } else {
            // If username was provided, check if it already exists and auto-generate if taken
            $base_username = $form_data['username'];
            $username = $base_username;
            $counter = 1;
            $max_attempts = 100;
            $attempts = 0;
            
            do {
                $stmt = $login_conn->prepare("SELECT id FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $exists = $stmt->fetch();
                
                if (!$exists) {
                    break; // Username is available
                }
                
                // Try with counter
                $username = $base_username . $counter;
                $counter++;
                $attempts++;
                
                // If we've tried too many times, generate a random username
                if ($attempts > $max_attempts) {
                    $username = 'stu' . substr(uniqid(), -6);
                    break;
                }
            } while (true);
            
            $form_data['username'] = $username;
        }

        // Validate password
        if (empty($form_data['password'])) {
            $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+';
            $form_data['password'] = substr(str_shuffle($chars), 0, 12);
        } elseif (strlen($form_data['password']) < 8) {
            throw new Exception('Password must be at least 8 characters long');
        } elseif ($form_data['password'] !== $form_data['confirm_password']) {
            throw new Exception('Passwords do not match');
        }
        $hashed_password = password_hash($form_data['password'], PASSWORD_DEFAULT);

        // Start transactions
        $login_conn->beginTransaction();
        $student_conn->beginTransaction();

        // Double check username doesn't exist right before insert
        $check_username = $login_conn->prepare("SELECT id FROM users WHERE username = ?");
        $check_username->execute([$form_data['username']]);
        if ($check_username->fetch()) {
            throw new Exception('The username "' . htmlspecialchars($form_data['username']) . '" is already taken. Please choose a different one.');
        }

        // Insert into users table
        $user_query = "INSERT INTO users (username, email, password, role, created_at) 
                      VALUES (?, ?, ?, 'student', NOW())";
        $user_stmt = $login_conn->prepare($user_query);
        if (!$user_stmt->execute([$form_data['username'], $form_data['email'], $hashed_password])) {
            throw new Exception('Failed to create user account');
        }
        $user_id = $login_conn->lastInsertId();

        // Generate student ID
        $stmt = $student_conn->query("SELECT MAX(CAST(SUBSTRING_INDEX(student_id, '-', -1) AS UNSIGNED)) as max_id FROM students");
        $max_id = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
        $student_id = 'ST-' . date('Y') . '-' . str_pad($max_id + 1, 4, '0', STR_PAD_LEFT);

        // Determine section
        $section_assigned = false;
        $section_name = '';
        $grade_level = $form_data['grade_level'];

        // Find or create a section
        $section_query = "
            SELECT cs.id, cs.section, 
                   (SELECT COUNT(*) FROM students s 
                    WHERE s.grade_level = cs.grade_level AND s.section = cs.section) as student_count
            FROM class_sections cs
            WHERE cs.grade_level = :grade_level 
            AND cs.school_year = :school_year
            AND cs.status = 'active'
            HAVING student_count < 30
            ORDER BY student_count ASC
            LIMIT 1";
            
        $section_stmt = $student_conn->prepare($section_query);
        $section_stmt->bindParam(':grade_level', $grade_level, PDO::PARAM_INT);
        $section_stmt->bindParam(':school_year', $school_year, PDO::PARAM_STR);
        $section_stmt->execute();
        $section = $section_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($section) {
            $section_name = $section['section'];
            $section_assigned = true;
        } else {
            $max_section_query = "
                SELECT MAX(CAST(SUBSTRING(section, 2) AS UNSIGNED)) as max_section
                FROM class_sections
                WHERE grade_level = :grade_level 
                AND school_year = :school_year";
                
            $max_section_stmt = $student_conn->prepare($max_section_query);
            $max_section_stmt->bindParam(':grade_level', $grade_level, PDO::PARAM_INT);
            $max_section_stmt->bindParam(':school_year', $school_year, PDO::PARAM_STR);
            $max_section_stmt->execute();
            $max_section = $max_section_stmt->fetch(PDO::FETCH_ASSOC);
            
            $new_section_num = ($max_section && $max_section['max_section']) ? $max_section['max_section'] + 1 : 1;
            $section_name = 'S' . $new_section_num;
            
            $insert_section_query = "
                INSERT INTO class_sections (section, grade_level, school_year, status, created_at, updated_at)
                VALUES (:section, :grade_level, :school_year, 'active', NOW(), NOW())";
                
            $insert_section_stmt = $student_conn->prepare($insert_section_query);
            $insert_section_stmt->bindParam(':section', $section_name, PDO::PARAM_STR);
            $insert_section_stmt->bindParam(':grade_level', $grade_level, PDO::PARAM_INT);
            $insert_section_stmt->bindParam(':school_year', $school_year, PDO::PARAM_STR);
            
            if ($insert_section_stmt->execute()) {
                $section_assigned = true;
            }
        }

        // Insert into students table with Pending status
        $student_query = "INSERT INTO students (
            user_id, student_id, first_name, last_name, middle_name, 
            birthdate, gender, address, phone, grade_level, section,
            parent_name, parent_contact, status, school_year, created_at
        ) VALUES (
            :user_id, :student_id, :first_name, :last_name, :middle_name,
            :birthdate, :gender, :address, :phone, :grade_level, :section,
            :parent_name, :parent_contact, 'Pending', :school_year, NOW()
        )";
        
        $student_stmt = $student_conn->prepare($student_query);
        $student_stmt->execute([
            ':user_id' => $user_id,
            ':student_id' => $student_id,
            ':first_name' => $form_data['first_name'],
            ':last_name' => $form_data['last_name'],
            ':middle_name' => $form_data['middle_name'] ?: null,
            ':birthdate' => $form_data['birthdate'],
            ':gender' => $form_data['gender'],
            ':address' => $form_data['address'],
            ':phone' => $form_data['phone'] ?: null,
            ':grade_level' => $form_data['grade_level'],
            ':section' => $section_name,
            ':parent_name' => $form_data['parent_name'],
            ':parent_contact' => $form_data['parent_contact'],
            ':school_year' => $school_year
        ]);

        // Update enrollment record if it exists
        if ($section_assigned) {
            $update_enrollment_query = "
                INSERT INTO enrollments (student_id, grade_level, section, school_year, status, created_at, updated_at)
                VALUES (:student_id, :grade_level, :section, :school_year, 'active', NOW(), NOW())
                ON DUPLICATE KEY UPDATE 
                    section = VALUES(section),
                    updated_at = NOW()";
                
            $update_enrollment_stmt = $student_conn->prepare($update_enrollment_query);
            $update_enrollment_stmt->execute([
                ':student_id' => $student_id,
                ':grade_level' => $grade_level,
                ':section' => $section_name,
                ':school_year' => $school_year
            ]);
            
            $success_message = "
                <div class='bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded'>
                    <p class='font-bold'>Student Registered Successfully!</p>
                    <div class='mt-2 space-y-1'>
                        <p><span class='font-medium'>Student ID:</span> $student_id</p>
                        <p><span class='font-medium'>Name:</span> {$form_data['first_name']} {$form_data['last_name']}</p>
                        <p><span class='font-medium'>Grade:</span> {$form_data['grade_level']} - $section_name</p>
                        <p><span class='font-medium'>Username:</span> {$form_data['username']}</p>
                        <p><span class='font-medium'>Password:</span> {$form_data['password']}</p>
                    </div>
                    <p class='mt-2 text-sm text-green-800'>Please provide these credentials to the student.</p>
                </div>
            ";
        } else {
            $success_message = "
                <div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-6 rounded'>
                    <p class='font-bold'>Student Registered Successfully!</p>
                    <div class='mt-2 space-y-1'>
                        <p><span class='font-medium'>Student ID:</span> $student_id</p>
                        <p><span class='font-medium'>Name:</span> {$form_data['first_name']} {$form_data['last_name']}</p>
                        <p><span class='font-medium'>Grade:</span> {$form_data['grade_level']} - Not Assigned</p>
                        <p><span class='font-medium'>Username:</span> {$form_data['username']}</p>
                        <p><span class='font-medium'>Password:</span> {$form_data['password']}</p>
                    </div>
                    <p class='mt-2 text-sm text-yellow-800'>Student enrolled successfully, but there was an error assigning to a section. Please assign manually.</p>
                </div>
            ";
        }

        // Commit transactions in reverse order
        $login_conn->commit();
        $student_conn->commit();

        // Prepare success response
        $response = [
            'success' => true,
            'message' => 'Student enrolled successfully',
            'data' => [
                'student_id' => $student_id,
                'name' => $form_data['first_name'] . ' ' . $form_data['last_name'],
                'grade' => $form_data['grade_level'],
                'section' => $section_name ?? 'Not Assigned',
                'username' => $form_data['username'],
                'section_assigned' => $section_assigned
            ]
        ];
        
        // Store in session for redirect
        if ($section_assigned) {
            $success_msg = "Student enrolled successfully with ID: {$student_id} in Grade {$form_data['grade_level']} - {$section_name}";
        } else {
            $success_msg = "Student enrolled successfully with ID: {$student_id} but was not assigned to a section. Please assign manually.";
        }
        
        $_SESSION['success_message'] = $success_msg;
        $_SESSION['enrolled_student'] = $response['data'];
        
        // If this is an AJAX request, return JSON
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode($response);
            exit();
        }
        
        // Otherwise, redirect to view_students.php
        header('Location: view_students.php');
        exit();

    } catch (Exception $e) {
        // Only rollback if transaction is active (in reverse order of commit)
        try {
            if ($login_conn->inTransaction()) {
                $login_conn->rollBack();
            }
            if ($student_conn->inTransaction()) {
                $student_conn->rollBack();
            }
        } catch (PDOException $rollbackEx) {
            // Log rollback error but don't override the original error
            logError('Rollback failed: ' . $rollbackEx->getMessage());
        }
        $error_message = $e->getMessage();
        logError('Exception in student enrollment: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString(),
            'post_data' => $_POST
        ]);
        
        $response = [
            'success' => false,
            'message' => 'An error occurred: ' . $e->getMessage(),
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ];
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
        
    } catch (PDOException $e) {
        try {
            if ($student_conn->inTransaction()) {
                $student_conn->rollBack();
            }
            if ($login_conn->inTransaction()) {
                $login_conn->rollBack();
            }
        } catch (PDOException $rollbackEx) {
            // Log rollback error but don't override the original error
            logError('Rollback failed: ' . $rollbackEx->getMessage());
        }
        
        $error_message = 'Database error: ' . $e->getMessage();
        logError('Database error in student enrollment: ' . $e->getMessage(), [
            'error_info' => $e->errorInfo ?? null,
            'post_data' => $_POST
        ]);
        
        $response = [
            'success' => false,
            'message' => 'Database error occurred',
            'error' => $e->getMessage(),
            'error_info' => $e->errorInfo ?? null
        ];
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit();
    }
}

// Set user initials for avatar
$initials = '';
if (!empty($_SESSION['first_name']) && !empty($_SESSION['last_name'])) {
    $initials = strtoupper(substr($_SESSION['first_name'], 0, 1) . substr($_SESSION['last_name'], 0, 1));
} elseif (!empty($_SESSION['first_name'])) {
    $initials = strtoupper(substr($_SESSION['first_name'], 0, 2));
} elseif (!empty($_SESSION['username'])) {
    $initials = strtoupper(substr($_SESSION['username'], 0, 2));
} else {
    $initials = 'RU';
}

// Set registrar ID display
$registrar_id_display = 'R' . $_SESSION['user_id'];
$current_page = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>San Agustin Elementary School - Registrar Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        },
                        secondary: {
                            50: '#f5f8f7',
                            100: '#dfe8e6',
                            200: '#bed1cd',
                            300: '#95b2ac',
                            400: '#6f8f89',
                            500: '#55736e',
                            600: '#425c58',
                            700: '#384b48',
                            800: '#303d3b',
                            900: '#2b3534',
                        },
                        dark: {
                            50: '#f8fafc',
                            100: '#f1f5f9',
                            200: '#e2e8f0',
                            300: '#cbd5e1',
                            400: '#94a3b8',
                            500: '#64748b',
                            600: '#475569',
                            700: '#334155',
                            800: '#1e293b',
                            900: '#0f172a',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }
        body {
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            color: #334155;
            min-height: 100vh;
        }
        .sidebar {
            transition: all 0.3s ease;
            background: linear-gradient(to bottom, #2b3534 0%, #384b48 100%);
        }
        .sidebar.collapsed {
            width: 70px;
        }
        .sidebar.collapsed .sidebar-text,
        .sidebar.collapsed .logo-text,
        .sidebar.collapsed .user-text,
        .sidebar.collapsed .events-title,
        .sidebar.collapsed .event-details {
            display: none;
        }
        .sidebar.collapsed .logo-container {
            margin: 0 auto;
        }
        .sidebar.collapsed .user-initials {
            margin: 0 auto;
        }
        .sidebar.collapsed .nav-item {
            justify-content: center;
            padding: 0.75rem;
        }
        .sidebar.collapsed .nav-item i {
            margin-right: 0;
        }
        .sidebar.collapsed .submenu {
            display: none !important;
        }
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
                position: fixed;
                z-index: 40;
                height: 100vh;
                width: 250px;
            }
            .sidebar-open {
                transform: translateX(0);
            }
            .overlay {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: rgba(0, 0, 0, 0.5);
                z-index: 30;
            }
            .overlay-open {
                display: block;
            }
        }
        .dashboard-card {
            transition: all 0.3s ease;
            background: white;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        .notification-dot {
            position: absolute;
            top: -5px;
            right: -5px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #0ea5e9;
            color: white;
            font-size: 0.7rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .header-bg {
            background: linear-gradient(to right, #2b3534 0%, #384b48 100%);
        }
        .logo-container {
            background: linear-gradient(135deg, #0ea5e9 0%, #38bdf8 100%);
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f1f5f9;
            border-radius: 3px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .submenu.open {
            max-height: 500px;
        }
        .rotate-90 {
            transform: rotate(90deg);
        }
        .notification-panel {
            position: absolute;
            top: 100%;
            right: 0;
            width: 350px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
            z-index: 50;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
        }
        .notification-panel.open {
            max-height: 400px;
        }
        .toast {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            border-left: 4px solid;
            opacity: 0;
            transform: translateX(20px);
            transition: all 0.3s ease;
            max-width: 350px;
            min-width: 250px;
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
        }
        .toast.show {
            opacity: 1;
            transform: translateX(0);
        }
        .toast.success {
            border-left-color: #0ea5e9;
        }
        .toast.info {
            border-left-color: #38bdf8;
        }
        .toast.warning {
            border-left-color: #facc15;
        }
        .toast.error {
            border-left-color: #ef4444;
        }
        .toast .toast-icon {
            font-size: 1.2rem;
        }
        .toast .toast-message {
            flex: 1;
            font-size: 0.875rem;
            color: #1f2937;
        }
        .toast .toast-close {
            cursor: pointer;
            color: #6b7280;
            font-size: 1rem;
            transition: color 0.2s ease;
        }
        .toast .toast-close:hover {
            color: #1f2937;
        }
        .status-active {
            background-color: #0ea5e9;
            color: white;
        }
        .status-inactive {
            background-color: #ef4444;
            color: white;
        }
        .status-pending {
            background-color: #facc15;
            color: black;
        }
    </style>
</head>
<body class="min-h-screen flex">
    <!-- Overlay for mobile sidebar -->
    <div id="overlay" class="overlay" onclick="closeSidebar()"></div>

    <!-- Sidebar -->
    <div id="sidebar" class="sidebar w-64 min-h-screen flex flex-col text-white">
        <!-- School Logo -->
        <div class="p-5 border-b border-secondary-700 flex flex-col items-center">
            <div class="logo-container w-16 h-16 rounded-full flex items-center justify-center text-white font-bold text-2xl mb-3 shadow-md">
                <i class="fas fa-file-alt"></i>
            </div>
            <h1 class="text-xl font-bold text-center logo-text">San Agustin Elementary School</h1>
            <p class="text-xs text-secondary-200 mt-1 logo-text">Registrar's Office</p>
        </div>
        
        <!-- User Profile -->
        <div class="p-5 border-b border-secondary-700">
            <div class="flex items-center space-x-3">
                <div class="w-12 h-12 rounded-full bg-primary-500 flex items-center justify-center text-white font-bold shadow-md user-initials">
                    <?php echo htmlspecialchars($registrar_id_display); ?>
                </div>
                <div class="user-text">
                    <h2>Registrar</h2>
                    <p class="text-xs text-secondary-200"><?php echo htmlspecialchars($registrar_id_display); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Navigation -->
        <div class="flex-1 p-4 overflow-y-auto custom-scrollbar">
            <ul class="space-y-2">
                <li>
                    <a href="dashboard.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'dashboard.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-home w-5"></i>
                        <span class="ml-3 sidebar-text">Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('students-submenu', this)">
                        <i class="fas fa-user-graduate w-5"></i>
                        <span class="ml-3 sidebar-text">Student Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="students-submenu" class="submenu pl-4 mt-1 <?php echo $current_page === 'add_student.php' || $current_page === 'view_students.php' || $current_page === 'student_search.php' ? 'open' : ''; ?>">
                        <a href="add_student.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'add_student.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-plus w-5"></i>
                            <span class="ml-3 sidebar-text">Enroll New Student</span>
                        </a>
                        <a href="view_students.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'view_students.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-list w-5"></i>
                            <span class="ml-3 sidebar-text">View All Students</span>
                        </a>
                        <a href="student_search.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'student_search.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-search w-5"></i>
                            <span class="ml-3 sidebar-text">Search Student</span>
                        </a>
                    </div>
                </li>
                <li>
                    <a href="enrollment.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'enrollment.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-clipboard-list w-5"></i>
                        <span class="ml-3 sidebar-text">Enrollment</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('sections-submenu', this)">
                        <i class="fas fa-chalkboard w-5"></i>
                        <span class="ml-3 sidebar-text">Class Management</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="sections-submenu" class="submenu pl-4 mt-1">
                        <a href="view_sections.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'view_sections.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-users w-5"></i>
                            <span class="ml-3 sidebar-text">Class Sections</span>
                        </a>
                        <a href="class_schedules.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'class_schedules.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-calendar-alt w-5"></i>
                            <span class="ml-3 sidebar-text">Class Schedules</span>
                        </a>
                    </div>
                </li>
                <li>
                    <a href="attendance.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'attendance.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-calendar-check w-5"></i>
                        <span class="ml-3 sidebar-text">Attendance</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors nav-item" onclick="toggleSubmenu('reports-submenu', this)">
                        <i class="fas fa-chart-bar w-5"></i>
                        <span class="ml-3 sidebar-text">Reports & Records</span>
                        <i class="fas fa-chevron-down ml-auto text-xs sidebar-text"></i>
                    </a>
                    <div id="reports-submenu" class="submenu pl-4 mt-1">
                        <a href="enrollment_reports.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'enrollment_reports.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-file-alt w-5"></i>
                            <span class="ml-3 sidebar-text">Enrollment Reports</span>
                        </a>
                        <a href="demographic_reports.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'demographic_reports.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-chart-pie w-5"></i>
                            <span class="ml-3 sidebar-text">Demographic Reports</span>
                        </a>
                        <a href="transcript_requests.php" class="flex items-center p-2 rounded-lg <?php echo $current_page === 'transcript_requests.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors">
                            <i class="fas fa-file-certificate w-5"></i>
                            <span class="ml-3 sidebar-text">Transcript Requests</span>
                        </a>
                    </div>
                </li>
                <li>
                    <a href="documents.php" class="flex items-center p-3 rounded-lg <?php echo $current_page === 'documents.php' ? 'bg-primary-600 text-white' : 'text-secondary-200 hover:bg-secondary-700 hover:text-white'; ?> transition-colors nav-item">
                        <i class="fas fa-file-archive w-5"></i>
                        <span class="ml-3 sidebar-text">Document Management</span>
                    </a>
                </li>
            </ul>
            
            <!-- Upcoming Deadlines -->
            <div class="mt-10 p-4 bg-secondary-800 rounded-lg events-container">
                <h3 class="text-sm font-bold text-white mb-3 flex items-center events-title">
                    <i class="fas fa-calendar-day mr-2"></i>Upcoming Deadlines
                </h3>
                <div class="space-y-3 event-details">
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">20</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Enrollment Deadline</p>
                            <p class="text-xs text-secondary-300">SY <?php echo htmlspecialchars($school_year); ?></p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">25</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Report Cards Distribution</p>
                            <p class="text-xs text-secondary-300">1st Quarter</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">30</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Census Submission</p>
                            <p class="text-xs text-secondary-300">DepEd Requirement</p>
                        </div>
                    </div>
                    <div class="flex items-start">
                        <div class="bg-primary-500 text-white p-1 rounded text-xs w-6 h-6 flex items-center justify-center mt-1 flex-shrink-0">5</div>
                        <div class="ml-2">
                            <p class="text-xs font-medium text-white">Classroom Assignment</p>
                            <p class="text-xs text-secondary-300">Finalization</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="p-4 border-t border-secondary-700">
            <button onclick="toggleSidebarCollapse()" class="flex items-center p-3 rounded-lg text-secondary-200 hover:bg-secondary-700 hover:text-white transition-colors w-full justify-center">
                <i class="fas fa-chevron-left" id="collapse-icon"></i>
                <span class="ml-3 sidebar-text">Collapse Sidebar</span>
            </button>
        </div>
    </div>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col">
        <!-- Header -->
        <header class="header-bg text-white p-4 flex items-center justify-between shadow-md">
            <div class="flex items-center">
                <button id="sidebar-toggle" class="md:hidden text-white mr-4 focus:outline-none" onclick="toggleSidebar()">
                    <i class="fas fa-bars text-xl"></i>
                </button>
                <h1 class="text-xl font-bold">Registrar Dashboard</h1>
            </div>
            
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <button id="notification-btn" class="relative p-2 text-white hover:bg-primary-600 rounded-full focus:outline-none" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <span class="absolute top-0 right-0 h-4 w-4 bg-red-500 text-white text-xs rounded-full flex items-center justify-center">
                            <?php echo ($stats['pending_documents'] ?? 0) + ($stats['new_applications'] ?? 0); ?>
                        </span>
                    </button>
                    
                    <!-- Notification Panel -->
                    <div id="notification-panel" class="notification-panel">
                        <div class="p-4 border-b border-gray-200">
                            <div class="flex items-center justify-between">
                                <h3 class="font-medium text-gray-900">Notifications</h3>
                                <button class="text-sm text-primary-600 hover:text-primary-800">Mark all as read</button>
                            </div>
                        </div>
                        <div class="divide-y divide-gray-200 max-h-80 overflow-y-auto">
                            <?php if (!empty($stats['new_applications'])): ?>
                            <a href="enrollment.php?status=pending" class="block p-4 hover:bg-gray-50">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 pt-0.5">
                                        <div class="h-10 w-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
                                            <i class="fas fa-clipboard-list"></i>
                                        </div>
                                    </div>
                                    <div class="ml-3 flex-1">
                                        <p class="text-sm font-medium text-gray-900"><?php echo $stats['new_applications']; ?> new enrollment <?php echo $stats['new_applications'] > 1 ? 'applications' : 'application'; ?></p>
                                        <p class="mt-1 text-sm text-gray-500">Click to review pending applications</p>
                                        <p class="mt-1 text-xs text-gray-400">Just now</p>
                                    </div>
                                </div>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (!empty($stats['pending_documents'])): ?>
                            <a href="documents.php?status=pending" class="block p-4 hover:bg-gray-50">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 pt-0.5">
                                        <div class="h-10 w-10 rounded-full bg-yellow-100 text-yellow-600 flex items-center justify-center">
                                            <i class="fas fa-file-alt"></i>
                                        </div>
                                    </div>
                                    <div class="ml-3 flex-1">
                                        <p class="text-sm font-medium text-gray-900"><?php echo $stats['pending_documents']; ?> document <?php echo $stats['pending_documents'] > 1 ? 'requests' : 'request'; ?> pending</p>
                                        <p class="mt-1 text-sm text-gray-500">Needs your attention</p>
                                        <p class="mt-1 text-xs text-gray-400">5 min ago</p>
                                    </div>
                                </div>
                            </a>
                            <?php endif; ?>
                            
                            <?php if (empty($stats['new_applications']) && empty($stats['pending_documents'])): ?>
                            <div class="p-4 text-center text-gray-500 text-sm">
                                No new notifications
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="p-2 bg-gray-50 text-center">
                            <a href="notifications.php" class="text-sm font-medium text-primary-600 hover:text-primary-800">View all notifications</a>
                        </div>
                    </div>
                </div>
                
                <!-- User Menu -->
                <div class="relative">
                    <button id="user-menu-button" class="flex items-center space-x-2 focus:outline-none" onclick="toggleUserMenu()" aria-label="User menu" aria-expanded="false">
                        <div class="h-8 w-8 rounded-full bg-primary-600 flex items-center justify-center text-white font-medium">
                            <?php echo htmlspecialchars($initials); ?>
                        </div>
                        <span class="hidden md:inline-block text-white"><?php echo htmlspecialchars($_SESSION['first_name'] ?? $_SESSION['username'] ?? 'User'); ?></span>
                        <i class="fas fa-chevron-down text-xs text-white"></i>
                    </button>
                    
                    <!-- User Dropdown Menu -->
                    <div id="user-menu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-user-circle mr-2 w-5"></i> My Profile
                        </a>
                        <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-cog mr-2 w-5"></i> Settings
                        </a>
                        <div class="border-t border-gray-200 my-1"></div>
                        <a href="#" onclick="logout()" class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100">
                            <i class="fas fa-sign-out-alt mr-2 w-5"></i> Sign out
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content Area -->
        <main class="flex-1 p-5 overflow-y-auto bg-gray-50">
            <!-- Success/Error Messages -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class='fixed top-4 right-4 z-50 max-w-sm w-full bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-y-0 opacity-100' role='alert'>
                    <div class='flex items-center'>
                        <div class='py-1'><i class='fas fa-check-circle text-green-500 mr-2'></i></div>
                        <div>
                            <p class='font-bold'>Success!</p>
                            <p class='text-sm'><?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?></p>
                        </div>
                        <button type='button' class='ml-auto -mx-1.5 -my-1.5 bg-green-100 text-green-500 rounded-lg focus:ring-2 focus:ring-green-400 p-1.5 hover:bg-green-200 inline-flex h-8 w-8' data-dismiss-target='#alert-success' aria-label='Close' onclick="this.parentElement.parentElement.remove()">
                            <span class='sr-only'>Close</span>
                            <i class='fas fa-times'></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class='fixed top-4 right-4 z-50 max-w-sm w-full bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-y-0 opacity-100' role='alert'>
                    <div class='flex items-center'>
                        <div class='py-1'><i class='fas fa-exclamation-circle text-red-500 mr-2'></i></div>
                        <div>
                            <p class='font-bold'>Error!</p>
                            <p class='text-sm'><?php echo htmlspecialchars($error_message); ?></p>
                        </div>
                        <button type='button' class='ml-auto -mx-1.5 -my-1.5 bg-red-100 text-red-500 rounded-lg focus:ring-2 focus:ring-red-400 p-1.5 hover:bg-red-200 inline-flex h-8 w-8' data-dismiss-target='#alert-error' aria-label='Close' onclick="this.parentElement.parentElement.remove()">
                            <span class='sr-only'>Close</span>
                            <i class='fas fa-times'></i>
                        </button>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Add auto-close script for success/error messages -->
            <script>
                // Auto-close success/error messages after 5 seconds
                document.addEventListener('DOMContentLoaded', function() {
                    const messages = document.querySelectorAll('[role="alert"]');
                    messages.forEach(message => {
                        setTimeout(() => {
                            message.style.opacity = '0';
                            setTimeout(() => message.remove(), 300);
                        }, 5000);
                    });
                });
            </script>

            <!-- Form Card -->
            <div class="bg-white rounded-xl p-6 border border-gray-200 shadow-sm">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-2xl font-bold text-gray-800">Enroll New Student</h1>
                        <p class="text-sm text-gray-600 mt-1">Fill out the form below to register a new student</p>
                    </div>
                    <a href="view_students.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Students
                    </a>
                </div>

                <form action="add_student.php" method="POST" class="space-y-6">
                    <!-- Personal Information -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-user-circle text-primary-600 mr-2"></i>
                            Personal Information
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">
                                    First Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="first_name" name="first_name" required 
                                       value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>"
                                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                       onblur="generateUsernameAndEmail()">
                            </div>
                            <div>
                                <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">
                                    Last Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="last_name" name="last_name" required
                                       value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>"
                                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                       onblur="generateUsernameAndEmail()">
                            </div>
                            <div>
                                <label for="middle_name" class="block text-sm font-medium text-gray-700 mb-1">
                                    Middle Name
                                </label>
                                <input type="text" id="middle_name" name="middle_name"
                                       value="<?php echo htmlspecialchars($form_data['middle_name'] ?? ''); ?>"
                                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                            </div>
                            <div>
                                <label for="birthdate" class="block text-sm font-medium text-gray-700 mb-1">
                                    Date of Birth <span class="text-red-500">*</span>
                                </label>
                                <input type="date" id="birthdate" name="birthdate" required
                                       value="<?php echo htmlspecialchars($form_data['birthdate'] ?? ''); ?>"
                                       max="<?php echo date('Y-m-d'); ?>"
                                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                            </div>
                            <div>
                                <label for="gender" class="block text-sm font-medium text-gray-700 mb-1">
                                    Gender <span class="text-red-500">*</span>
                                </label>
                                <select id="gender" name="gender" required 
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                    <option value="">Select Gender</option>
                                    <option value="Male" <?php echo (isset($form_data['gender']) && $form_data['gender'] == 'Male') ? 'selected' : ''; ?>>Male</option>
                                    <option value="Female" <?php echo (isset($form_data['gender']) && $form_data['gender'] == 'Female') ? 'selected' : ''; ?>>Female</option>
                                    <option value="Other" <?php echo (isset($form_data['gender']) && $form_data['gender'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-address-card text-primary-600 mr-2"></i>
                            Contact Information
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                                    Email Address <span class="text-gray-500 text-xs">(auto-generated)</span>
                                </label>
                                <div class="mt-1 flex rounded-md shadow-sm">
                                    <input type="email" id="email" name="email" readonly
                                           value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                                           class="block w-full rounded-none rounded-l-md border-gray-300 bg-gray-50 focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                           placeholder="Will be generated from name">
                                    <button type="button" onclick="generateEmail()" 
                                            class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-100 text-gray-500 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
                                        <i class="fas fa-sync-alt text-sm"></i>
                                    </button>
                                </div>
                            </div>
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">
                                    Phone Number
                                </label>
                                <input type="tel" id="phone" name="phone"
                                       value="<?php echo htmlspecialchars($form_data['phone'] ?? ''); ?>"
                                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                       placeholder="e.g. (123) 456-7890"
                                       oninput="formatPhoneNumber(this)">
                            </div>
                            <div class="md:col-span-2">
                                <label for="address" class="block text-sm font-medium text-gray-700 mb-1">
                                    Address <span class="text-red-500">*</span>
                                </label>
                                <textarea id="address" name="address" rows="2" required
                                          class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                          placeholder="Enter complete address"><?php echo htmlspecialchars($form_data['address'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Parent/Guardian Information -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-users text-primary-600 mr-2"></i>
                            Parent/Guardian Information
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="md:col-span-2">
                                <label for="parent_name" class="block text-sm font-medium text-gray-700 mb-1">
                                    Parent/Guardian Name <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="parent_name" name="parent_name" required
                                       value="<?php echo htmlspecialchars($form_data['parent_name'] ?? ''); ?>"
                                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                       placeholder="Full name of parent or guardian">
                            </div>
                            <div class="md:col-span-2">
                                <label for="parent_contact" class="block text-sm font-medium text-gray-700 mb-1">
                                    Contact Number <span class="text-red-500">*</span>
                                </label>
                                <input type="tel" id="parent_contact" name="parent_contact" required
                                       value="<?php echo htmlspecialchars($form_data['parent_contact'] ?? ''); ?>"
                                       class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                       placeholder="e.g. (123) 456-7890"
                                       oninput="formatPhoneNumber(this)">
                            </div>
                        </div>
                    </div>

                    <!-- Account Information -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-user-shield text-primary-600 mr-2"></i>
                            Account Information
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="username" class="block text-sm font-medium text-gray-700 mb-1">
                                    Username
                                    <span class="text-gray-500 text-xs">(auto-generated if left blank)</span>
                                </label>
                                <div class="mt-1 flex rounded-md shadow-sm">
                                    <input type="text" id="username" name="username" 
                                           value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>"
                                           class="block w-full rounded-none rounded-l-md border-gray-300 focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                                           placeholder="Will be generated from name">
                                    <button type="button" onclick="generateUsername()" 
                                            class="inline-flex items-center px-3 rounded-r-md border border-l-0 border-gray-300 bg-gray-100 text-gray-500 hover:bg-gray-200 focus:outline-none focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
                                        <i class="fas fa-sync-alt text-sm"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="flex items-end">
                                <button type="button" onclick="generatePassword()" 
                                        class="w-full inline-flex justify-center items-center px-4 py-2 border border-gray-300 shadow-sm text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                                    <i class="fas fa-key mr-2"></i> Generate Password
                                </button>
                            </div>
                            <div>
                                <label for="password" class="block text-sm font-medium text-gray-700 mb-1">
                                    Password <span class="text-red-500">*</span>
                                </label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <input type="password" id="password" name="password" required
                                           value="<?php echo htmlspecialchars($form_data['password'] ?? ''); ?>"
                                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm pr-10"
                                           minlength="8">
                                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                        <button type="button" onclick="togglePassword('password')" 
                                                class="text-gray-500 hover:text-gray-700 focus:outline-none">
                                            <i class="far fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div id="password-strength" class="mt-1 text-xs"></div>
                            </div>
                            <div>
                                <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">
                                    Confirm Password <span class="text-red-500">*</span>
                                </label>
                                <div class="mt-1 relative rounded-md shadow-sm">
                                    <input type="password" id="confirm_password" name="confirm_password" required
                                           value="<?php echo htmlspecialchars($form_data['confirm_password'] ?? ''); ?>"
                                           class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm pr-10">
                                    <div class="absolute inset-y-0 right-0 pr-3 flex items-center">
                                        <button type="button" onclick="togglePassword('confirm_password')" 
                                                class="text-gray-500 hover:text-gray-700 focus:outline-none">
                                            <i class="far fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div id="password-match" class="mt-1 text-xs"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Academic Information -->
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4 flex items-center">
                            <i class="fas fa-graduation-cap text-primary-600 mr-2"></i>
                            Academic Information
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="grade_level" class="block text-sm font-medium text-gray-700 mb-1">
                                    Grade Level <span class="text-red-500">*</span>
                                </label>
                                <select id="grade_level" name="grade_level" required 
                                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-primary-500 focus:ring-primary-500 sm:text-sm">
                                    <option value="">Select Grade Level</option>
                                    <?php for ($i = 1; $i <= 6; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo (isset($form_data['grade_level']) && $form_data['grade_level'] == $i) ? 'selected' : ''; ?>>
                                            Grade <?php echo $i; ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div>
                                <label for="school_year" class="block text-sm font-medium text-gray-700">School Year</label>
                                <input type="text" id="school_year" name="school_year" 
                                       value="<?php echo htmlspecialchars($school_year); ?>" 
                                       readonly 
                                       class="mt-1 block w-full rounded-md border-gray-300 bg-gray-100 shadow-sm sm:text-sm">
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex justify-end space-x-3">
                        <a href="view_students.php" class="inline-flex items-center px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            Cancel
                        </a>
                        <button type="submit" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500">
                            <i class="fas fa-user-plus mr-2"></i> Enroll Student
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>

    <script>
        // Toggle password visibility
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const icon = field.nextElementSibling.querySelector('i');
            field.type = field.type === 'password' ? 'text' : 'password';
            icon.classList.toggle('fa-eye');
            icon.classList.toggle('fa-eye-slash');
        }

        // Format phone number as (123) 456-7890
        function formatPhoneNumber(input) {
            let phone = input.value.replace(/\D/g, '');
            if (phone.length > 0) {
                phone = phone.match(/(\d{0,3})(\d{0,3})(\d{0,4})/);
                phone = !phone[2] ? phone[1] : '(' + phone[1] + ') ' + phone[2] + (phone[3] ? '-' + phone[3] : '');
            }
            input.value = phone;
        }

        // Generate username from first and last name
        function generateUsername() {
            const firstName = document.getElementById('first_name').value.trim().toLowerCase();
            const lastName = document.getElementById('last_name').value.trim().toLowerCase();
            
            if (!firstName || !lastName) {
                alert('Please enter both first and last name first');
                return;
            }
            
            let username = (firstName.charAt(0) + lastName).replace(/[^a-z0-9]/g, '');
            if (username.length > 20) {
                username = username.substring(0, 20);
            }
            
            document.getElementById('username').value = username;
            checkUsernameAvailability(username);
        }
        
        // Generate email from first and last name
        function generateEmail() {
            const firstName = document.getElementById('first_name').value.trim().toLowerCase();
            const lastName = document.getElementById('last_name').value.trim().toLowerCase();
            
            if (!firstName || !lastName) {
                alert('Please enter both first and last name first');
                return;
            }
            
            let email = (firstName + '.' + lastName).replace(/\s+/g, '.').toLowerCase() + '@sanagustines.edu.ph';
            email = email.replace(/\.+/g, '.').replace(/\.@/, '@');
            document.getElementById('email').value = email;
        }
        
        // Generate both username and email
        function generateUsernameAndEmail() {
            const firstName = document.getElementById('first_name').value.trim();
            const lastName = document.getElementById('last_name').value.trim();
            
            if (!firstName || !lastName) return;
            
            const usernameField = document.getElementById('username');
            const emailField = document.getElementById('email');
            
            if (!usernameField.value || usernameField.value === '') {
                generateUsername();
            }
            
            if (!emailField.value || emailField.value === '') {
                generateEmail();
            }
        }
        
        // Set default password to 'password123'
        function setDefaultPassword() {
            const defaultPassword = 'password123';
            document.getElementById('password').value = defaultPassword;
            document.getElementById('confirm_password').value = defaultPassword;
            
            checkPasswordStrength(defaultPassword);
            document.getElementById('password-match').textContent = '✓ Passwords match';
            document.getElementById('password-match').className = 'mt-1 text-xs text-green-600';
        }
        
        // Auto-generate username and set default password on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-generate username and email when first name or last name changes
            document.getElementById('first_name').addEventListener('input', generateUsernameAndEmail);
            document.getElementById('last_name').addEventListener('input', generateUsernameAndEmail);
            
            // Set default password
            setDefaultPassword();
            
            // Also generate username if first and last name are already filled
            if (document.getElementById('first_name').value && document.getElementById('last_name').value) {
                generateUsernameAndEmail();
            }
        });
        
        // Helper function to get a random character from a string
        function getRandomChar(chars) {
            return chars.charAt(Math.floor(Math.random() * chars.length));
        }
        
        // Check username availability via AJAX
        function checkUsernameAvailability(username) {
            if (!username) return;
            
            const takenUsernames = ['admin', 'user', 'test', 'student'];
            const isTaken = takenUsernames.includes(username.toLowerCase());
            
            const feedback = document.getElementById('username-feedback');
            if (!feedback) return;
            
            if (isTaken) {
                feedback.textContent = 'Username is already taken';
                feedback.className = 'mt-1 text-xs text-red-600';
            } else {
                feedback.textContent = 'Username is available';
                feedback.className = 'mt-1 text-xs text-green-600';
            }
        }
        
        // Password strength checker
        function checkPasswordStrength(password) {
            const length = password.length >= 8;
            const uppercase = /[A-Z]/.test(password);
            const number = /[0-9]/.test(password);
            
            let strength = 0;
            if (length) strength += 1;
            if (uppercase) strength += 1;
            if (number) strength += 1;
            
            const strengthText = ['Very Weak', 'Weak', 'Moderate', 'Strong'];
            const strengthColor = ['text-red-500', 'text-orange-500', 'text-yellow-500', 'text-green-500'];
            
            const strengthElement = document.getElementById('password-strength');
            strengthElement.textContent = `Strength: ${strengthText[strength]}`;
            strengthElement.className = `mt-1 text-xs ${strengthColor[strength]}`;
        }

        // Toggle sidebar for mobile
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            if (sidebar && overlay) {
                sidebar.classList.toggle('sidebar-open');
                overlay.classList.toggle('overlay-open');
            }
        }

        // Close sidebar
        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            if (sidebar && overlay) {
                sidebar.classList.remove('sidebar-open');
                overlay.classList.remove('overlay-open');
            }
        }

        // Toggle user dropdown menu
        function toggleUserMenu() {
            const userMenu = document.getElementById('user-menu');
            const userButton = document.getElementById('user-menu-button');
            if (userMenu && userButton) {
                userMenu.classList.toggle('hidden');
                const isExpanded = !userMenu.classList.contains('hidden');
                userButton.setAttribute('aria-expanded', isExpanded);
            }
        }

        // Toggle notifications panel
        function toggleNotifications() {
            const notificationPanel = document.getElementById('notification-panel');
            if (notificationPanel) {
                notificationPanel.classList.toggle('open');
            }
        }

        // Toggle submenu
        function toggleSubmenu(submenuId, element) {
            event.preventDefault();
            const submenu = document.getElementById(submenuId);
            const chevron = element.querySelector('.fa-chevron-down');
            if (submenu && chevron) {
                submenu.classList.toggle('open');
                chevron.classList.toggle('rotate-90');
            }
        }

        // Toggle sidebar collapse
        function toggleSidebarCollapse() {
            const sidebar = document.getElementById('sidebar');
            const collapseIcon = document.getElementById('collapse-icon');
            if (sidebar && collapseIcon) {
                sidebar.classList.toggle('collapsed');
                
                if (sidebar.classList.contains('collapsed')) {
                    collapseIcon.classList.remove('fa-chevron-left');
                    collapseIcon.classList.add('fa-chevron-right');
                    const collapseText = document.querySelector('.sidebar-text');
                    if (collapseText) collapseText.textContent = 'Expand Sidebar';
                } else {
                    collapseIcon.classList.remove('fa-chevron-right');
                    collapseIcon.classList.add('fa-chevron-left');
                    const collapseText = document.querySelector('.sidebar-text');
                    if (collapseText) collapseText.textContent = 'Collapse Sidebar';
                }
            }
        }

        // Logout function
        function logout() {
            if (confirm('Are you sure you want to log out?')) {
                window.location.href = '../logout.php';
            }
        }

        // Handle clicks outside dropdowns
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('user-menu');
            const userButton = document.getElementById('user-menu-button');
            if (userMenu && userButton && !userMenu.contains(event.target) && !userButton.contains(event.target)) {
                userMenu.classList.add('hidden');
                userButton.setAttribute('aria-expanded', 'false');
            }
            
            const notificationPanel = document.getElementById('notification-panel');
            const notificationButton = document.getElementById('notification-btn');
            if (notificationPanel && notificationButton && !notificationPanel.contains(event.target) && !notificationButton.contains(event.target)) {
                notificationPanel.classList.remove('open');
            }
        });

        // Handle keyboard accessibility for user menu
        document.addEventListener('keydown', function(event) {
            const userMenu = document.getElementById('user-menu');
            const userButton = document.getElementById('user-menu-button');
            if (userButton && (event.key === 'Enter' || event.key === ' ')) {
                if (event.target === userButton) {
                    event.preventDefault();
                    toggleUserMenu();
                }
            }
            if (event.key === 'Escape' && userMenu && !userMenu.classList.contains('hidden')) {
                userMenu.classList.add('hidden');
                userButton.setAttribute('aria-expanded', 'false');
                userButton.focus();
            }
        });

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            console.log('San Agustin Elementary School Enroll New Student loaded');
        });
    </script>
</body>
</html>

