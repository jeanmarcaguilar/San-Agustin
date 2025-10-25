<?php
/**
 * Sync Students from Student Database to Registrar Database
 * This script fetches all student data from student_db and inserts/updates it in registrar_db
 */

session_start();
require_once '../config/database.php';

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Registrar access only.']);
    exit();
}

$response = [
    'success' => false,
    'message' => '',
    'synced' => 0,
    'updated' => 0,
    'errors' => []
];

try {
    // Get database connections
    $database = new Database();
    $student_conn = $database->getConnection('student');
    $registrar_conn = $database->getConnection('registrar');
    $login_conn = $database->getLoginConnection();
    
    if (!$student_conn || !$registrar_conn || !$login_conn) {
        throw new Exception('Failed to connect to databases');
    }
    
    // Fetch all students from student database
    $stmt = $student_conn->prepare("
        SELECT 
            s.*,
            u.email as user_email,
            u.username
        FROM students s
        LEFT JOIN login_db.users u ON s.user_id = u.id
        ORDER BY s.id
    ");
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($students)) {
        throw new Exception('No students found in student database');
    }
    
    // Begin transaction
    $registrar_conn->beginTransaction();
    
    $synced_count = 0;
    $updated_count = 0;
    
    foreach ($students as $student) {
        try {
            // Check if student already exists in registrar database
            $stmt = $registrar_conn->prepare("SELECT id FROM students WHERE student_id = ?");
            $stmt->execute([$student['student_id']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if user_id exists in login database
            // Only use user_id if it exists in login_db.users table
            $user_id_to_use = null;
            if (!empty($student['user_id'])) {
                try {
                    $stmt = $login_conn->prepare("SELECT id FROM users WHERE id = ?");
                    $stmt->execute([$student['user_id']]);
                    $user_record = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($user_record) {
                        $user_id_to_use = $student['user_id'];
                    } else {
                        // Log that user_id doesn't exist in login database
                        error_log("Student {$student['student_id']}: user_id {$student['user_id']} not found in login database, setting to NULL");
                    }
                } catch (Exception $e) {
                    error_log("Error checking user_id for student {$student['student_id']}: " . $e->getMessage());
                }
            }
            
            if ($existing) {
                // Update existing student - don't include student_id in data array for UPDATE
                $update_data = [
                    ':user_id' => $user_id_to_use,
                    ':first_name' => $student['first_name'],
                    ':last_name' => $student['last_name'],
                    ':middle_name' => $student['middle_name'] ?? null,
                    ':birthdate' => $student['birthdate'] ?? date('Y-m-d'),
                    ':gender' => $student['gender'] ?? null,
                    ':address' => $student['address'] ?? null,
                    ':email' => $student['user_email'] ?? null,
                    ':contact_number' => $student['phone'] ?? $student['parent_contact'] ?? null,
                    ':guardian_name' => $student['parent_name'] ?? null,
                    ':guardian_contact' => $student['parent_contact'] ?? null,
                    ':guardian_relationship' => 'Parent/Guardian',
                    ':grade_level' => $student['grade_level'],
                    ':section' => $student['section'] ?? null,
                    ':school_year' => $student['school_year'] ?? date('Y') . '-' . (date('Y') + 1),
                    ':status' => $student['status'] ?? 'Active',
                    ':is_active' => 1,
                    ':student_id' => $student['student_id']
                ];
                
                $stmt = $registrar_conn->prepare("
                    UPDATE students SET
                        user_id = :user_id,
                        first_name = :first_name,
                        last_name = :last_name,
                        middle_name = :middle_name,
                        birthdate = :birthdate,
                        gender = :gender,
                        address = :address,
                        email = :email,
                        contact_number = :contact_number,
                        guardian_name = :guardian_name,
                        guardian_contact = :guardian_contact,
                        guardian_relationship = :guardian_relationship,
                        grade_level = :grade_level,
                        section = :section,
                        school_year = :school_year,
                        status = :status,
                        is_active = :is_active,
                        updated_at = NOW()
                    WHERE student_id = :student_id
                ");
                
                $stmt->execute($update_data);
                $updated_count++;
            } else {
                // Insert new student
                $insert_data = [
                    ':user_id' => $user_id_to_use,
                    ':student_id' => $student['student_id'],
                    ':first_name' => $student['first_name'],
                    ':last_name' => $student['last_name'],
                    ':middle_name' => $student['middle_name'] ?? null,
                    ':birthdate' => $student['birthdate'] ?? date('Y-m-d'),
                    ':gender' => $student['gender'] ?? null,
                    ':address' => $student['address'] ?? null,
                    ':email' => $student['user_email'] ?? null,
                    ':contact_number' => $student['phone'] ?? $student['parent_contact'] ?? null,
                    ':guardian_name' => $student['parent_name'] ?? null,
                    ':guardian_contact' => $student['parent_contact'] ?? null,
                    ':guardian_relationship' => 'Parent/Guardian',
                    ':grade_level' => $student['grade_level'],
                    ':section' => $student['section'] ?? null,
                    ':school_year' => $student['school_year'] ?? date('Y') . '-' . (date('Y') + 1),
                    ':lrn' => null,
                    ':status' => $student['status'] ?? 'Active',
                    ':is_active' => 1
                ];
                
                $stmt = $registrar_conn->prepare("
                    INSERT INTO students (
                        user_id, student_id, first_name, last_name, middle_name,
                        birthdate, gender, address, email, contact_number,
                        guardian_name, guardian_contact, guardian_relationship,
                        grade_level, section, school_year, lrn, status, is_active,
                        created_at, updated_at
                    ) VALUES (
                        :user_id, :student_id, :first_name, :last_name, :middle_name,
                        :birthdate, :gender, :address, :email, :contact_number,
                        :guardian_name, :guardian_contact, :guardian_relationship,
                        :grade_level, :section, :school_year, :lrn, :status, :is_active,
                        NOW(), NOW()
                    )
                ");
                
                $stmt->execute($insert_data);
                $synced_count++;
            }
            
        } catch (Exception $e) {
            $response['errors'][] = "Error syncing student {$student['student_id']}: " . $e->getMessage();
            error_log("Error syncing student {$student['student_id']}: " . $e->getMessage());
        }
    }
    
    // Commit transaction
    $registrar_conn->commit();
    
    $response['success'] = true;
    $response['synced'] = $synced_count;
    $response['updated'] = $updated_count;
    $response['message'] = "Successfully synced {$synced_count} new students and updated {$updated_count} existing students.";
    
} catch (Exception $e) {
    if (isset($registrar_conn) && $registrar_conn->inTransaction()) {
        $registrar_conn->rollBack();
    }
    
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log("Student sync error: " . $e->getMessage());
}

// Return JSON response
header('Content-Type: application/json');
echo json_encode($response);
