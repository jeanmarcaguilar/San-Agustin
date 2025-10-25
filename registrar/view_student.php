<?php
session_start();

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('Location: ../login.php');
    exit();
}

// Include database configuration
require_once '../config/database.php';

// Get student ID from URL
$student_id = $_GET['id'] ?? null;

if (!$student_id) {
    header('Location: view_students.php');
    exit();
}

// Initialize database connection
$database = new Database();
$registrar_conn = $database->getConnection('registrar');
$login_conn = $database->getLoginConnection();

// Fetch student details
try {
    $stmt = $registrar_conn->prepare("
        SELECT s.*, u.username, u.email as user_email
        FROM students s
        LEFT JOIN login_db.users u ON s.user_id = u.id
        WHERE s.id = ?
    ");
    $stmt->execute([$student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        $_SESSION['error'] = 'Student not found.';
        header('Location: view_students.php');
        exit();
    }
} catch (PDOException $e) {
    $_SESSION['error'] = 'Error fetching student details: ' . $e->getMessage();
    header('Location: view_students.php');
    exit();
}

$page_title = 'View Student Details';
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
                <a href="view_students.php" class="text-white hover:text-blue-200">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Student List
                </a>
            </div>
        </header>

        <!-- Main Content -->
        <main class="container mx-auto px-4 py-8">
            <div class="max-w-4xl mx-auto">
                <!-- Student Header -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <div class="h-20 w-20 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 text-2xl font-bold mr-4">
                                <?php echo strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-gray-800">
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']); ?>
                                </h2>
                                <p class="text-gray-600">Student ID: <?php echo htmlspecialchars($student['student_id']); ?></p>
                            </div>
                        </div>
                        <a href="edit_student.php?id=<?php echo $student['id']; ?>" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">
                            <i class="fas fa-edit mr-2"></i> Edit Student
                        </a>
                    </div>
                    
                    <?php
                    $status_class = 'bg-gray-100 text-gray-800';
                    if ($student['status'] === 'Active') $status_class = 'bg-green-100 text-green-800';
                    elseif ($student['status'] === 'Inactive') $status_class = 'bg-red-100 text-red-800';
                    elseif ($student['status'] === 'Pending') $status_class = 'bg-yellow-100 text-yellow-800';
                    ?>
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full <?php echo $status_class; ?>">
                        <?php echo htmlspecialchars($student['status']); ?>
                    </span>
                </div>

                <!-- Personal Information -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">
                        <i class="fas fa-user mr-2 text-blue-600"></i> Personal Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600">First Name</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['first_name']); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Middle Name</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['middle_name'] ?: 'N/A'); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Last Name</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['last_name']); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Birthdate</label>
                            <p class="text-gray-900"><?php echo $student['birthdate'] ? date('F j, Y', strtotime($student['birthdate'])) : 'N/A'; ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Gender</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['gender'] ?: 'N/A'); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">LRN</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['lrn'] ?: 'N/A'); ?></p>
                        </div>
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-600">Address</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['address'] ?: 'N/A'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Academic Information -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">
                        <i class="fas fa-graduation-cap mr-2 text-blue-600"></i> Academic Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Grade Level</label>
                            <p class="text-gray-900">Grade <?php echo htmlspecialchars($student['grade_level']); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Section</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['section'] ?: 'Not Assigned'); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">School Year</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['school_year'] ?: 'N/A'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">
                        <i class="fas fa-phone mr-2 text-blue-600"></i> Contact Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Email</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['email'] ?: $student['user_email'] ?: 'N/A'); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Contact Number</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['contact_number'] ?: 'N/A'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Guardian Information -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">
                        <i class="fas fa-users mr-2 text-blue-600"></i> Guardian Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Guardian Name</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['guardian_name'] ?: 'N/A'); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Guardian Contact</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['guardian_contact'] ?: 'N/A'); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Relationship</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['guardian_relationship'] ?: 'N/A'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Login Information -->
                <?php if ($student['username']): ?>
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">
                        <i class="fas fa-key mr-2 text-blue-600"></i> Login Information
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Username</label>
                            <p class="text-gray-900"><?php echo htmlspecialchars($student['username']); ?></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-600">Account Status</label>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                Active
                            </span>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                This student does not have a login account yet.
                            </p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="flex justify-between items-center">
                    <a href="view_students.php" class="text-gray-600 hover:text-gray-800">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Student List
                    </a>
                    <a href="edit_student.php?id=<?php echo $student['id']; ?>" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700">
                        <i class="fas fa-edit mr-2"></i> Edit Student
                    </a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
