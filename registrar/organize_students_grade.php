<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('Location: ../login.php');
    exit();
}

// Initialize database connection
$database = new Database();
$pdo = $database->getConnection('registrar');

$message = '';
$preview = [];
$totalStudents = 0;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentsPerGrade = isset($_POST['students_per_grade']) ? (int)$_POST['students_per_grade'] : 30;
    $startingGrade = isset($_POST['starting_grade']) ? (int)$_POST['starting_grade'] : 1;
    
    if ($studentsPerGrade < 1) {
        $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <strong>Error:</strong> Number of students per grade must be at least 1.
        </div>';
    } else {
        try {
            // Get all students ordered by ID or name
            $stmt = $pdo->query("SELECT id, lrn, last_name, first_name, grade_level, section FROM students ORDER BY last_name, first_name");
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $totalStudents = count($students);
            
            // Calculate number of grades needed
            $numGrades = ceil($totalStudents / $studentsPerGrade);
            $maxGrade = $startingGrade + $numGrades - 1;
            
            // Prepare preview data
            $preview = [];
            $studentCounter = 0;
            $currentGrade = $startingGrade;
            $gradeCount = 0;
            
            foreach ($students as $index => $student) {
                if ($gradeCount >= $studentsPerGrade) {
                    $currentGrade = min($currentGrade + 1, $maxGrade);
                    $gradeCount = 0;
                }
                
                $preview[] = [
                    'id' => $student['id'],
                    'lrn' => $student['lrn'],
                    'name' => $student['last_name'] . ', ' . $student['first_name'],
                    'current_grade' => $student['grade_level'] ?? 'Not Set',
                    'new_grade' => 'Grade ' . $currentGrade,
                    'new_section' => chr(65 + floor($gradeCount / 30)) // A, B, C...
                ];
                
                $gradeCount++;
                $studentCounter++;
                
                // If we've reached the maximum grade, start over from the starting grade
                if ($currentGrade >= $maxGrade && $gradeCount >= $studentsPerGrade) {
                    $currentGrade = $startingGrade;
                    $gradeCount = 0;
                }
            }
            
            // Apply changes if requested
            if (isset($_POST['apply'])) {
                $pdo->beginTransaction();
                
                try {
                    // Update students with new grade levels and sections
                    foreach ($preview as $student) {
                        $grade = (int)str_replace('Grade ', '', $student['new_grade']);
                        $section = $student['new_section'];
                        
                        $stmt = $pdo->prepare("UPDATE students SET grade_level = ?, section = ? WHERE id = ?");
                        $stmt->execute([$grade, $section, $student['id']]);
                    }
                    
                    $pdo->commit();
                    $message = '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                        Successfully updated ' . count($preview) . ' students across ' . $numGrades . ' grade levels.
                    </div>';
                    
                    // Clear preview after applying changes
                    $preview = [];
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                        <strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
                    </div>';
                }
            }
            
        } catch (PDOException $e) {
            $message = '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                <strong>Database Error:</strong> ' . htmlspecialchars($e->getMessage()) . '
            </div>';
        }
    }
}

// Get current grade level distribution for display
$gradeDistribution = [];
try {
    $stmt = $pdo->query("SELECT grade_level, COUNT(*) as count FROM students GROUP BY grade_level ORDER BY grade_level");
    $gradeDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message .= '<div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-4" role="alert">
        <strong>Warning:</strong> Could not retrieve current grade distribution.
    </div>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Organize Students by Grade Level - Registrar Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
        }
        .sidebar {
            background: linear-gradient(195deg, #2b3534 0%, #1e2a2a 100%);
        }
        .header-bg {
            background: linear-gradient(90deg, #2b3534 0%, #3d4e4d 100%);
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Include your sidebar/header here or use the existing layout -->
    
    <div class="flex">
        <!-- Sidebar would go here -->
        
        <!-- Main Content -->
        <div class="flex-1">
            <!-- Header -->
            <header class="header-bg text-white p-4 shadow-md">
                <div class="container mx-auto flex justify-between items-center">
                    <h1 class="text-xl font-semibold">Organize Students by Grade Level</h1>
                    <a href="students.php" class="bg-white text-gray-800 px-4 py-2 rounded-md hover:bg-gray-100 transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Students
                    </a>
                </div>
            </header>
            
            <!-- Main Content -->
            <main class="container mx-auto p-6">
                <?php echo $message; ?>
                
                <!-- Current Grade Distribution -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h2 class="text-lg font-semibold mb-4">Current Grade Level Distribution</h2>
                    <?php if (!empty($gradeDistribution)): ?>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <?php foreach ($gradeDistribution as $grade): ?>
                                <div class="bg-blue-50 p-4 rounded-lg border border-blue-100">
                                    <div class="text-2xl font-bold text-blue-700">
                                        <?php echo $grade['grade_level'] ? 'Grade ' . htmlspecialchars($grade['grade_level']) : 'Not Set'; ?>
                                    </div>
                                    <div class="text-sm text-blue-500">
                                        <?php echo $grade['count']; ?> students
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p class="text-gray-500">No grade level data available.</p>
                    <?php endif; ?>
                </div>
                
                <!-- Organization Form -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h2 class="text-lg font-semibold mb-4">Organize Students</h2>
                    <form method="post" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="students_per_grade" class="block text-sm font-medium text-gray-700 mb-1">
                                    Students per Grade Level
                                </label>
                                <input type="number" id="students_per_grade" name="students_per_grade" 
                                       min="1" value="30" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <p class="mt-1 text-sm text-gray-500">Maximum number of students per grade level.</p>
                            </div>
                            
                            <div>
                                <label for="starting_grade" class="block text-sm font-medium text-gray-700 mb-1">
                                    Starting Grade Level
                                </label>
                                <select id="starting_grade" name="starting_grade" class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?php echo $i; ?>">Grade <?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <p class="mt-1 text-sm text-gray-500">First grade level to assign students to.</p>
                            </div>
                            
                            <div class="flex items-end">
                                <div class="space-x-2 w-full">
                                    <button type="submit" name="preview" class="w-full md:w-auto bg-blue-600 text-white px-4 py-2 rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                        <i class="fas fa-eye mr-2"></i> Preview Changes
                                    </button>
                                    <button type="submit" name="apply" class="w-full md:w-auto bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                        <i class="fas fa-save mr-2"></i> Apply Changes
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Preview Section -->
                <?php if (!empty($preview)): ?>
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h2 class="text-lg font-semibold">Preview Changes</h2>
                            <div class="text-sm text-gray-500">
                                Total Students: <?php echo count($preview); ?>
                            </div>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Grade</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">New Grade</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">New Section</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach (array_slice($preview, 0, 50) as $student): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <?php echo htmlspecialchars($student['lrn']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <?php echo htmlspecialchars($student['name']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo htmlspecialchars($student['current_grade']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <span class="px-2 py-1 bg-green-100 text-green-800 rounded-full text-xs">
                                                    <?php echo htmlspecialchars($student['new_grade']); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                <span class="px-2 py-1 bg-blue-100 text-blue-800 rounded-full text-xs">
                                                    <?php echo htmlspecialchars($student['new_section']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            
                            <?php if (count($preview) > 50): ?>
                                <div class="mt-4 text-sm text-gray-500">
                                    Showing 50 of <?php echo count($preview); ?> students. Use the "Apply Changes" button to update all students.
                                </div>
                            <?php endif; ?>
                            
                            <div class="mt-6 flex justify-end">
                                <button type="submit" name="apply" class="bg-green-600 text-white px-4 py-2 rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                                    <i class="fas fa-save mr-2"></i> Apply Changes to All Students
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <script>
        // Add any necessary JavaScript here
        document.addEventListener('DOMContentLoaded', function() {
            // You can add confirmation dialogs or other interactive elements here
        });
    </script>
</body>
</html>
