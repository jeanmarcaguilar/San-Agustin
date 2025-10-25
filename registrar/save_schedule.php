<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');

$response = ['success' => false, 'message' => ''];

try {
    $action = $_POST['action'] ?? '';
    $id = $_POST['id'] ?? 0;
    $grade_level = $_POST['grade_level'] ?? '';
    $section = $_POST['section'] ?? '';
    $subject_id = $_POST['subject_id'] ?? null;
    $teacher_id = $_POST['teacher_id'] ?? null;
    $day_of_week = $_POST['day_of_week'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $school_year = $_POST['school_year'] ?? date('Y') . '-' . (date('Y') + 1);

    // Validate required fields
    if (empty($grade_level) || empty($section) || empty($day_of_week) || empty($start_time) || empty($end_time)) {
        throw new Exception('All fields are required');
    }

    // Check for time conflicts
    $conflict_sql = "SELECT id FROM class_schedules 
                    WHERE grade_level = ? 
                    AND section = ? 
                    AND day_of_week = ? 
                    AND school_year = ?
                    AND ((start_time < ? AND end_time > ?) OR (start_time < ? AND end_time > ?) OR (start_time >= ? AND end_time <= ?))";
    
    $params = [
        $grade_level, 
        $section, 
        $day_of_week, 
        $school_year,
        $end_time, 
        $start_time,
        $start_time,
        $end_time,
        $start_time,
        $end_time
    ];
    
    if ($id) {
        $conflict_sql .= " AND id != ?";
        $params[] = $id;
    }

    $stmt = $pdo->prepare($conflict_sql);
    $stmt->execute($params);

    if ($stmt->rowCount() > 0) {
        throw new Exception('This time slot conflicts with an existing schedule');
    }

    if ($action === 'add' || $action === 'edit') {
        $data = [
            'grade_level' => $grade_level,
            'section' => $section,
            'subject_id' => $subject_id ?: null,
            'teacher_id' => $teacher_id ?: null,
            'day_of_week' => $day_of_week,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'school_year' => $school_year
        ];

        if ($action === 'add') {
            $fields = implode(', ', array_keys($data));
            $placeholders = ':' . implode(', :', array_keys($data));
            $sql = "INSERT INTO class_schedules ($fields) VALUES ($placeholders)";
        } else {
            $updates = [];
            foreach ($data as $key => $value) {
                $updates[] = "$key = :$key";
            }
            $sql = "UPDATE class_schedules SET " . implode(', ', $updates) . " WHERE id = :id";
            $data['id'] = $id;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);

        $response = [
            'success' => true,
            'message' => 'Schedule ' . ($action === 'add' ? 'added' : 'updated') . ' successfully'
        ];
    } elseif ($action === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM class_schedules WHERE id = ?");
        $stmt->execute([$id]);
        $response = ['success' => true, 'message' => 'Schedule deleted successfully'];
    } else {
        throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    $response = ['success' => false, 'message' => $e->getMessage()];
}

header('Content-Type: application/json');
echo json_encode($response);
