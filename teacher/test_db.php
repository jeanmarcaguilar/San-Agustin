<?php
// Start session
session_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

// Include database connection
require_once '../config/database.php';

// Test database connection
function testDatabaseConnection() {
    try {
        // Test teacher database connection
        $database = new Database();
        $teacher_conn = $database->getConnection('teacher');
        
        // Test login database connection
        $login_conn = $database->getLoginConnection();
        
        // Check if tables exist
        $tables = ['students', 'users', 'classes', 'class_students'];
        $tableStatus = [];
        
        foreach ($tables as $table) {
            try {
                $result = $teacher_conn->query("SHOW TABLES LIKE '$table'");
                $tableStatus[$table] = $result->rowCount() > 0 ? 'Exists' : 'Missing';
                
                // Get table structure if exists
                if ($tableStatus[$table] === 'Exists') {
                    $stmt = $teacher_conn->query("DESCRIBE $table");
                    $tableStatus["{$table}_structure"] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            } catch (PDOException $e) {
                $tableStatus[$table] = 'Error: ' . $e->getMessage();
            }
        }
        
        // Test inserting a sample student
        $testInsert = [
            'success' => false,
            'message' => 'Not attempted',
            'error' => null
        ];
        
        try {
            $testStudent = [
                'student_id' => 'TEST-' . time(),
                'first_name' => 'Test',
                'last_name' => 'Student',
                'email' => 'test@example.com',
                'grade_level' => 10,
                'section' => 'A'
            ];
            
            $stmt = $teacher_conn->prepare("
                INSERT INTO students (
                    student_id, first_name, last_name, email, grade_level, section, created_at, updated_at
                ) VALUES (
                    :student_id, :first_name, :last_name, :email, :grade_level, :section, NOW(), NOW()
                )
            ");
            
            $insertResult = $stmt->execute($testStudent);
            
            if ($insertResult) {
                $testInsert['success'] = true;
                $testInsert['message'] = 'Test student inserted successfully';
                
                // Clean up
                $teacher_conn->exec("DELETE FROM students WHERE student_id = '{$testStudent['student_id']}'");
            } else {
                $testInsert['message'] = 'Failed to insert test student';
                $testInsert['error'] = $stmt->errorInfo();
            }
        } catch (PDOException $e) {
            $testInsert['message'] = 'Error inserting test student';
            $testInsert['error'] = $e->getMessage();
        }
        
        return [
            'status' => 'success',
            'connections' => [
                'teacher' => 'Connected successfully',
                'login' => 'Connected successfully'
            ],
            'tables' => $tableStatus,
            'test_insert' => $testInsert
        ];
        
    } catch (PDOException $e) {
        return [
            'status' => 'error',
            'message' => 'Database connection failed',
            'error' => $e->getMessage()
        ];
    }
}

// Run tests
$testResults = testDatabaseConnection();

// Output results
header('Content-Type: application/json');
echo json_encode($testResults, JSON_PRETTY_PRINT);
