<?php
/**
 * API: Get Sections
 * Fetches available sections from registrar_db
 * Can filter by grade level
 */

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
    $registrar_conn = $database->getConnection('registrar');
    
    if (!$registrar_conn) {
        throw new Exception('Failed to connect to registrar database');
    }
    
    $grade_level = $_GET['grade_level'] ?? null;
    
    // Build query
    if ($grade_level) {
        // Get sections for specific grade level
        $query = "
            SELECT DISTINCT 
                cs.section,
                cs.grade_level,
                COUNT(DISTINCT cs.id) as class_count,
                (SELECT COUNT(*) FROM students s 
                 WHERE s.grade_level = cs.grade_level 
                 AND s.section = cs.section 
                 AND s.status = 'active') as student_count
            FROM class_sections cs
            WHERE cs.grade_level = :grade_level
            AND cs.status = 'active'
            GROUP BY cs.section, cs.grade_level
            ORDER BY cs.section
        ";
        
        $stmt = $registrar_conn->prepare($query);
        $stmt->bindParam(':grade_level', $grade_level, PDO::PARAM_INT);
    } else {
        // Get all sections
        $query = "
            SELECT DISTINCT 
                cs.section,
                cs.grade_level,
                COUNT(DISTINCT cs.id) as class_count,
                (SELECT COUNT(*) FROM students s 
                 WHERE s.grade_level = cs.grade_level 
                 AND s.section = cs.section 
                 AND s.status = 'active') as student_count
            FROM class_sections cs
            WHERE cs.status = 'active'
            GROUP BY cs.section, cs.grade_level
            ORDER BY cs.grade_level, cs.section
        ";
        
        $stmt = $registrar_conn->prepare($query);
    }
    
    $stmt->execute();
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format sections
    foreach ($sections as &$section) {
        $section['display_name'] = 'Grade ' . $section['grade_level'] . ' - ' . $section['section'];
        $section['student_count'] = (int)$section['student_count'];
        $section['class_count'] = (int)$section['class_count'];
    }
    
    echo json_encode([
        'success' => true,
        'sections' => $sections,
        'total' => count($sections)
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
?>
