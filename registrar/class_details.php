<?php
session_start();

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('Location: ../login.php');
    exit();
}

// Include database configuration
require_once '../config/database.php';

// Get parameters from URL
$grade_level = $_GET['grade'] ?? null;
$section = $_GET['section'] ?? null;

if (!$grade_level || !$section) {
    header('Location: view_sections.php');
    exit();
}

// Initialize database connection
$database = new Database();
$registrar_conn = $database->getConnection('registrar');
$student_conn = $database->getConnection('student');

// Fetch section details
try {
    $stmt = $registrar_conn->prepare("
        SELECT * FROM class_sections 
        WHERE grade_level = ? AND section = ?
    ");
    $stmt->execute([$grade_level, $section]);
    $section_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$section_info) {
        $_SESSION['error'] = 'Section not found.';
        header('Location: view_sections.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = 'Error fetching section details: ' . $e->getMessage();
    header('Location: view_sections.php');
    exit();
}

// Fetch students in this section
try {
    $stmt = $registrar_conn->prepare("
        SELECT * FROM students 
        WHERE grade_level = ? AND section = ?
        ORDER BY last_name, first_name
    ");
    $stmt->execute([$grade_level, $section]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $students = [];
    $error = 'Error fetching students: ' . $e->getMessage();
}

$page_title = "Grade $grade_level - $section";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - San Agustin Elementary School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <div class="min-h-screen">
        <!-- Header -->
        <header class="bg-blue-600 text-white p-4 shadow-md">
            <div class="container mx-auto flex items-center justify-between">
                <h1 class="text-xl font-bold">San Agustin Elementary School</h1>
                <a href="view_sections.php" class="text-white hover:text-blue-200">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Sections
                </a>
            </div>
        </header>

        <!-- Main Content -->
        <main class="container mx-auto px-4 py-8">
            <div class="max-w-6xl mx-auto">
                <!-- Section Header -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800">
                                Grade <?php echo htmlspecialchars($grade_level); ?> - <?php echo htmlspecialchars($section); ?>
                            </h2>
                            <p class="text-gray-600 mt-1">
                                School Year: <?php echo htmlspecialchars($section_info['school_year'] ?? 'N/A'); ?>
                            </p>
                        </div>
                        <div class="text-right">
                            <div class="text-3xl font-bold text-blue-600"><?php echo count($students); ?></div>
                            <div class="text-sm text-gray-600">Total Students</div>
                        </div>
                    </div>
                </div>

                <!-- Section Information -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">
                        <i class="fas fa-info-circle mr-2 text-blue-600"></i> Section Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Section Name</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($section); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Grade Level</label>
                            <p class="text-gray-900">Grade <?php echo htmlspecialchars($grade_level); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Status</label>
                            <?php
                            $status_class = $section_info['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800';
                            ?>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_class; ?>">
                                <?php echo ucfirst(htmlspecialchars($section_info['status'])); ?>
                            </span>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Max Students</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($section_info['max_students'] ?? '30'); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Current Students</label>
                            <p class="text-gray-900"><?php echo count($students); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Available Slots</label>
                            <p class="text-gray-900"><?php echo ($section_info['max_students'] ?? 30) - count($students); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Students List -->
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-users mr-2 text-blue-600"></i> Students
                        </h3>
                        <a href="add_student.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 text-sm">
                            <i class="fas fa-plus mr-2"></i> Add Student
                        </a>
                    </div>
                    
                    <?php if (!empty($students)): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">#</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Gender</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($students as $index => $student): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $index + 1; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($student['student_id']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($student['last_name'] . ', ' . $student['first_name']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($student['middle_name'] ?: 'N/A'); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($student['gender'] ?: 'N/A'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status_class = 'bg-gray-100 text-gray-800';
                                        if ($student['status'] === 'Active') $status_class = 'bg-green-100 text-green-800';
                                        elseif ($student['status'] === 'Inactive') $status_class = 'bg-red-100 text-red-800';
                                        elseif ($student['status'] === 'Pending') $status_class = 'bg-yellow-100 text-yellow-800';
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                                            <?php echo htmlspecialchars($student['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($student['contact_number'] ?: $student['guardian_contact'] ?: 'N/A'); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="px-6 py-12 text-center">
                        <i class="fas fa-users text-gray-300 text-5xl mb-4"></i>
                        <p class="text-gray-500">No students enrolled in this section yet.</p>
                        <a href="add_student.php" class="inline-block mt-4 text-blue-600 hover:text-blue-800">
                            <i class="fas fa-plus mr-2"></i> Add First Student
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Back Button -->
                <div class="mt-6">
                    <a href="view_sections.php" class="text-gray-600 hover:text-gray-800">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Sections
                    </a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
