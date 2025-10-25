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
    $student_conn = $database->getConnection('student');
    $teacher_conn = $database->getConnection('teacher');
    
    if (!$student_conn || !$teacher_conn) {
        throw new Exception('Failed to connect to database');
    }
    
    // Get student ID from session
    $student_id = $_SESSION['user_id'];
    
    // Get student information (grade and section) from registrar_db
    $registrar_conn = $database->getConnection('registrar');
    if (!$registrar_conn) {
        throw new Exception('Failed to connect to registrar database');
    }
    
    $stmt = $registrar_conn->prepare("SELECT grade_level, section FROM students WHERE user_id = :user_id");
    $stmt->bindParam(':user_id', $student_id, PDO::PARAM_INT);
    $stmt->execute();
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If student not found in registrar, try student_db
    if (!$student) {
        $stmt = $student_conn->prepare("SELECT grade_level, section FROM students WHERE user_id = :user_id");
        $stmt->bindParam(':user_id', $student_id, PDO::PARAM_INT);
        $stmt->execute();
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    $all_announcements = [];
    
    // Get student database announcements (general school announcements)
    try {
        $query = "SELECT a.*, 
                         (SELECT COUNT(*) FROM announcement_views av 
                          WHERE av.announcement_id = a.id AND av.student_id = :student_id) as is_read
                  FROM announcements a 
                  WHERE a.is_active = 1 
                  AND (a.end_date IS NULL OR a.end_date >= NOW())
                  ORDER BY 
                    CASE a.priority 
                        WHEN 'high' THEN 1 
                        WHEN 'medium' THEN 2 
                        ELSE 3 
                    END, 
                    a.start_date DESC";
        
        $stmt = $student_conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
        $stmt->execute();
        $student_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format student announcements
        foreach ($student_announcements as &$announcement) {
            if (!empty($announcement['start_date'])) {
                $date = new DateTime($announcement['start_date']);
                $announcement['formatted_date'] = $date->format('M j, Y');
            } else {
                $announcement['formatted_date'] = date('M j, Y');
            }
            $announcement['is_read'] = (bool)$announcement['is_read'];
            $announcement['source'] = 'school';
            $announcement['posted_by'] = 'School Administration';
        }
        
        $all_announcements = array_merge($all_announcements, $student_announcements);
    } catch (PDOException $e) {
        error_log("Error fetching school announcements: " . $e->getMessage());
        // Continue without school announcements
    }
    
    // Get teacher announcements if student info is available
    if ($student && !empty($student['grade_level'])) {
        try {
            $grade_level = $student['grade_level'];
            $section = $student['section'] ?? '';
            
            // Get published teacher announcements relevant to this student
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
            $teacher_announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format teacher announcements
            foreach ($teacher_announcements as &$announcement) {
                if (!empty($announcement['start_date'])) {
                    $date = new DateTime($announcement['start_date']);
                    $announcement['formatted_date'] = $date->format('M j, Y');
                } else {
                    $announcement['formatted_date'] = date('M j, Y');
                }
                $announcement['is_read'] = (bool)$announcement['is_read'];
                $announcement['source'] = 'teacher';
                $announcement['description'] = $announcement['content'] ?? '';
                
                // Determine priority based on pinned status
                if (!empty($announcement['is_pinned'])) {
                    $announcement['priority'] = 'high';
                } else {
                    $announcement['priority'] = 'medium';
                }
                
                $announcement['posted_by'] = $announcement['teacher_name'] ?? 'Teacher';
            }
            
            $all_announcements = array_merge($all_announcements, $teacher_announcements);
        } catch (PDOException $e) {
            error_log("Error fetching teacher announcements: " . $e->getMessage());
            // Continue without teacher announcements
        }
    }
    
    // Sort all announcements by priority and date
    usort($all_announcements, function($a, $b) {
        $priority_order = ['high' => 1, 'medium' => 2, 'low' => 3];
        $a_priority = $priority_order[$a['priority']] ?? 2;
        $b_priority = $priority_order[$b['priority']] ?? 2;
        
        if ($a_priority != $b_priority) {
            return $a_priority - $b_priority;
        }
        
        // If same priority, sort by date (newest first)
        return strtotime($b['start_date']) - strtotime($a['start_date']);
    });
    
    echo json_encode([
        'success' => true,
        'announcements' => $all_announcements,
        'total' => count($all_announcements),
        'timestamp' => date('Y-m-d H:i:s')
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
