<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connections
require_once '../config/database.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'teacher') {
    die(json_encode(['success' => false, 'message' => 'Unauthorized access']));
}

// Get query parameters
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 'all';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$format = isset($_GET['format']) ? strtolower($_GET['format']) : 'csv';

// Validate dates
if (!strtotime($start_date) || !strtotime($end_date)) {
    die(json_encode(['success' => false, 'message' => 'Invalid date format. Please use YYYY-MM-DD']));
}

if (strtotime($start_date) > strtotime($end_date)) {
    die(json_encode(['success' => false, 'message' => 'Start date cannot be after end date']));
}

try {
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    
    // Get teacher ID from session or user info
    $teacher_id = $_SESSION['user_id'];
    
    // Build the query
    $query = "
        SELECT 
            s.student_id,
            s.first_name,
            s.last_name,
            c.subject,
            c.grade_level,
            c.section,
            a.attendance_date,
            a.status,
            a.notes,
            a.recorded_at,
            u.full_name as recorded_by
        FROM attendance a
        JOIN students s ON a.student_id = s.student_id
        JOIN classes c ON a.class_id = c.id
        LEFT JOIN users u ON a.recorded_by = u.id
        WHERE a.attendance_date BETWEEN ? AND ?
        AND c.teacher_id = ?
    ";
    
    $params = [$start_date, $end_date, $teacher_id];
    
    if ($class_id !== 'all') {
        $query .= " AND a.class_id = ?";
        $params[] = $class_id;
    }
    
    $query .= " ORDER BY c.subject, c.grade_level, c.section, a.attendance_date DESC, s.last_name, s.first_name";
    
    $stmt = $teacher_conn->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($records)) {
        die(json_encode(['success' => false, 'message' => 'No attendance records found for the selected criteria']));
    }
    
    // Set filename based on filters
    $filename = "attendance_" . date('Y-m-d') . "_" . 
               ($class_id !== 'all' ? 'class_' . $class_id . '_' : '') . 
               $start_date . "_to_" . $end_date;
    
    switch ($format) {
        case 'csv':
            header('Content-Type: text/csv; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"$filename.csv\"");
            header('Pragma: no-cache');
            header('Expires: 0');
            
            $output = fopen('php://output', 'w');
            
            // Add UTF-8 BOM for proper Excel encoding
            fputs($output, "\xEF\xBB\xBF");
            
            // Add headers
            fputcsv($output, [
                'Student ID', 
                'First Name', 
                'Last Name', 
                'Subject', 
                'Grade', 
                'Section', 
                'Date', 
                'Status', 
                'Notes', 
                'Recorded At',
                'Recorded By'
            ]);
            
            // Add data
            foreach ($records as $record) {
                fputcsv($output, [
                    $record['student_id'],
                    $record['first_name'],
                    $record['last_name'],
                    $record['subject'],
                    $record['grade_level'],
                    $record['section'],
                    $record['attendance_date'],
                    ucfirst($record['status']),
                    $record['notes'],
                    $record['recorded_at'],
                    $record['recorded_by']
                ]);
            }
            
            fclose($output);
            exit;
            
        case 'excel':
            // For Excel, we'll use CSV with .xls extension for better compatibility
            header('Content-Type: application/vnd.ms-excel; charset=utf-8');
            header("Content-Disposition: attachment; filename=\"$filename.xls\"");
            header('Pragma: no-cache');
            header('Expires: 0');
            
            $output = fopen('php://output', 'w');
            
            // Add headers with tab separator
            fputcsv($output, [
                'Student ID', 'First Name', 'Last Name', 
                'Subject', 'Grade', 'Section', 
                'Date', 'Status', 'Notes', 'Recorded At', 'Recorded By'
            ], "\t");
            
            // Add data with tab separator
            foreach ($records as $record) {
                fputcsv($output, [
                    $record['student_id'],
                    $record['first_name'],
                    $record['last_name'],
                    $record['subject'],
                    $record['grade_level'],
                    $record['section'],
                    $record['attendance_date'],
                    ucfirst($record['status']),
                    $record['notes'],
                    $record['recorded_at'],
                    $record['recorded_by']
                ], "\t");
            }
            
            fclose($output);
            exit;
            
        case 'pdf':
            try {
                require_once '../vendor/autoload.php';
                
                $mpdf = new \Mpdf\Mpdf();
                
                // Create HTML content
                $html = '<h2>Attendance Report</h2>';
                $html .= '<p>Date Range: ' . date('F j, Y', strtotime($start_date)) . ' to ' . date('F j, Y', strtotime($end_date)) . '</p>';
                
                if ($class_id !== 'all') {
                    $class_info = $teacher_conn->prepare("SELECT subject, grade_level, section FROM classes WHERE id = ?");
                    $class_info->execute([$class_id]);
                    if ($class = $class_info->fetch(PDO::FETCH_ASSOC)) {
                        $html .= '<p>Class: ' . htmlspecialchars($class['subject'] . ' - Grade ' . $class['grade_level'] . ' ' . $class['section']) . '</p>';
                    }
                }
                
                $html .= '<table border="1" cellspacing="0" cellpadding="5" style="width:100%; border-collapse: collapse;">';
                $html .= '<thead><tr style="background-color: #f3f4f6;">';
                $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Student ID</th>';
                $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Name</th>';
                $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Subject</th>';
                $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Grade</th>';
                $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Section</th>';
                $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Date</th>';
                $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Status</th>';
                $html .= '<th style="border: 1px solid #ddd; padding: 8px;">Notes</th>';
                $html .= '</tr></thead><tbody>';
                
                foreach ($records as $record) {
                    $status_class = '';
                    switch (strtolower($record['status'])) {
                        case 'present': $status_class = 'color: green; font-weight: bold;'; break;
                        case 'absent': $status_class = 'color: red; font-weight: bold;'; break;
                        case 'late': $status_class = 'color: orange; font-weight: bold;'; break;
                        case 'excused': $status_class = 'color: blue; font-weight: bold;'; break;
                    }
                    
                    $html .= '<tr>';
                    $html .= '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($record['student_id']) . '</td>';
                    $html .= '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($record['last_name'] . ', ' . $record['first_name']) . '</td>';
                    $html .= '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($record['subject']) . '</td>';
                    $html .= '<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">' . htmlspecialchars($record['grade_level']) . '</td>';
                    $html .= '<td style="border: 1px solid #ddd; padding: 8px; text-align: center;">' . htmlspecialchars($record['section']) . '</td>';
                    $html .= '<td style="border: 1px solid #ddd; padding: 8px; white-space: nowrap;">' . date('M j, Y', strtotime($record['attendance_date'])) . '</td>';
                    $html .= '<td style="border: 1px solid #ddd; padding: 8px; ' . $status_class . '">' . htmlspecialchars(ucfirst($record['status'])) . '</td>';
                    $html .= '<td style="border: 1px solid #ddd; padding: 8px;">' . htmlspecialchars($record['notes'] ?? '') . '</td>';
                    $html .= '</tr>';
                }
                
                $html .= '</tbody></table>';
                
                // Add footer with page numbers
                $html .= '<div style="text-align: center; margin-top: 20px; font-size: 10px; color: #666;">';
                $html .= 'Page {PAGENO} of {nb}';
                $html .= '</div>';
                
                $mpdf->SetTitle('Attendance Report');
                $mpdf->SetAuthor('San Agustin Elementary School');
                $mpdf->SetCreator('Teacher Portal');
                $mpdf->SetSubject('Class Attendance');
                $mpdf->SetKeywords('attendance, report, class');
                
                $mpdf->WriteHTML($html);
                
                // Output the PDF
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $filename . '.pdf"');
                $mpdf->Output($filename . '.pdf', 'D');
                exit;
                
            } catch (Exception $e) {
                // Fallback to CSV if PDF generation fails
                error_log('PDF generation failed: ' . $e->getMessage());
                $format = 'csv'; // Fallback to CSV
                // Continue to CSV export
            }
            
        default:
            // Default to CSV if format is not recognized
            $format = 'csv';
            // Continue to CSV export
    }
    
    // If we get here, the format wasn't handled properly
    die(json_encode(['success' => false, 'message' => 'Export format not properly handled']));
    
} catch (Exception $e) {
    error_log('Export error: ' . $e->getMessage());
    
    // Try to send JSON error if headers not sent
    if (!headers_sent()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred while generating the export: ' . $e->getMessage()
        ]);
    } else {
        echo 'An error occurred while generating the export. Please check the server logs for details.';
    }
    exit;
}
