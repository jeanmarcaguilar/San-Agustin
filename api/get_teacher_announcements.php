<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once '../config/database.php';

// Set content type to JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

try {
    $database = new Database();
    
    // Get student connection to fetch student info
    $student_conn = $database->getConnection('student');
    $teacher_conn = $database->getConnection('teacher');
    
    // Get student ID from session
    $student_id = $_SESSION['user_id'];
    
    // Get student information (grade and section)
    $stmt = $student_conn->prepare("SELECT grade_level, section FROM students WHERE user_id = :user_id");
    $stmt->bindParam(':user_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception("Student record not found");
    }
    
    $grade_level = $student['grade_level'];
    $section = $student['section'];
    
    // Get published teacher announcements that are relevant to this student
    // Include announcements targeted to:
    // 1. All users
    // 2. Students only
    // 3. Specific grade that matches student's grade
    // 4. Specific section that matches student's section
    $query = "SELECT a.*, 
                     t.first_name as teacher_first_name,
                     t.last_name as teacher_last_name,
                     CONCAT(t.first_name, ' ', t.last_name) as teacher_name,
                     (SELECT COUNT(*) FROM announcement_views av 
                      WHERE av.announcement_id = a.id 
                      AND av.user_id = :student_id 
                      AND av.user_type = 'student') as is_read
              FROM announcements a
              LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
              WHERE a.status = 'published'
              AND (a.end_date IS NULL OR a.end_date >= CURDATE())
              AND (
                  a.target_audience = 'all'
                  OR (a.target_audience = 'specific_grade' AND a.target_grade = :grade_level)
                  OR (a.target_audience = 'specific_section' AND a.target_grade = :grade_level AND a.target_section = :section)
                  OR (a.target_audience = 'specific_class' AND a.target_class_id IN (
                      SELECT c.id FROM classes c 
                      WHERE c.grade_level = :grade_level AND c.section = :section
                  ))
              )
              ORDER BY 
                a.is_pinned DESC,
                a.start_date DESC
              LIMIT 50";
    
    $stmt = $teacher_conn->prepare($query);
    $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
    $stmt->bindParam(':grade_level', $grade_level, PDO::PARAM_INT);
    $stmt->bindParam(':section', $section, PDO::PARAM_STR);
    $stmt->execute();
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format announcements for display
    foreach ($announcements as &$announcement) {
        $date = new DateTime($announcement['start_date']);
        $announcement['formatted_date'] = $date->format('M j, Y');
        $announcement['is_read'] = (bool)$announcement['is_read'];
        $announcement['source'] = 'teacher';
        
        // Map teacher announcement fields to student announcement format
        $announcement['description'] = $announcement['content'];
        $announcement['priority'] = 'medium'; // Default priority for teacher announcements
        
        // Determine priority based on pinned status and target audience
        if ($announcement['is_pinned']) {
            $announcement['priority'] = 'high';
        }
        
        // Add teacher info
        $announcement['posted_by'] = $announcement['teacher_name'] ?? 'Teacher';
    }
    
    echo json_encode([
        'success' => true,
        'announcements' => $announcements,
        'student_info' => [
            'grade_level' => $grade_level,
            'section' => $section
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
