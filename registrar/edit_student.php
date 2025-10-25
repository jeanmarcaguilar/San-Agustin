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

$error = '';
$success = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $registrar_conn->beginTransaction();
        
        $stmt = $registrar_conn->prepare("
            UPDATE students SET
                first_name = ?,
                middle_name = ?,
                last_name = ?,
                birthdate = ?,
                gender = ?,
                address = ?,
                email = ?,
                contact_number = ?,
                grade_level = ?,
                section = ?,
                lrn = ?,
                guardian_name = ?,
                guardian_contact = ?,
                guardian_relationship = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $_POST['first_name'],
            $_POST['middle_name'] ?: null,
            $_POST['last_name'],
            $_POST['birthdate'] ?: null,
            $_POST['gender'] ?: null,
            $_POST['address'] ?: null,
            $_POST['email'] ?: null,
            $_POST['contact_number'] ?: null,
            $_POST['grade_level'],
            $_POST['section'] ?: null,
            $_POST['lrn'] ?: null,
            $_POST['guardian_name'] ?: null,
            $_POST['guardian_contact'] ?: null,
            $_POST['guardian_relationship'] ?: null,
            $_POST['status'],
            $student_id
        ]);
        
        $registrar_conn->commit();
        $success = 'Student information updated successfully!';
        
    } catch (Exception $e) {
        if ($registrar_conn->inTransaction()) {
            $registrar_conn->rollBack();
        }
        $error = 'Error updating student: ' . $e->getMessage();
    }
}

// Fetch student details
try {
    $stmt = $registrar_conn->prepare("SELECT * FROM students WHERE id = ?");
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

$page_title = 'Edit Student';
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
                <div class="flex space-x-4">
                    <a href="view_student.php?id=<?php echo $student_id; ?>" class="text-white hover:text-blue-200">
                        <i class="fas fa-eye mr-2"></i> View Details
                    </a>
                    <a href="view_students.php" class="text-white hover:text-blue-200">
                        <i class="fas fa-arrow-left mr-2"></i> Back to List
                    </a>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="container mx-auto px-4 py-8">
            <div class="max-w-4xl mx-auto">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6">Edit Student Information</h2>
                    
                    <?php if ($error): ?>
                    <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                        <i class="fas fa-exclamation-circle mr-2"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($success): ?>
                    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                        <i class="fas fa-check-circle mr-2"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="space-y-6">
                        <!-- Personal Information -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                                <i class="fas fa-user mr-2 text-blue-600"></i> Personal Information
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">First Name *</label>
                                    <input type="text" name="first_name" value="<?php echo htmlspecialchars($student['first_name']); ?>" required
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Middle Name</label>
                                    <input type="text" name="middle_name" value="<?php echo htmlspecialchars($student['middle_name'] ?: ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Last Name *</label>
                                    <input type="text" name="last_name" value="<?php echo htmlspecialchars($student['last_name']); ?>" required
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Birthdate</label>
                                    <input type="date" name="birthdate" value="<?php echo htmlspecialchars($student['birthdate'] ?: ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Gender</label>
                                    <select name="gender" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="">Select Gender</option>
                                        <option value="Male" <?php echo $student['gender'] === 'Male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="Female" <?php echo $student['gender'] === 'Female' ? 'selected' : ''; ?>>Female</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">LRN</label>
                                    <input type="text" name="lrn" value="<?php echo htmlspecialchars($student['lrn'] ?: ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div class="md:col-span-3">
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Address</label>
                                    <textarea name="address" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"><?php echo htmlspecialchars($student['address'] ?: ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Academic Information -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                                <i class="fas fa-graduation-cap mr-2 text-blue-600"></i> Academic Information
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Grade Level *</label>
                                    <select name="grade_level" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="">Select Grade</option>
                                        <?php for ($i = 1; $i <= 6; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo $student['grade_level'] == $i ? 'selected' : ''; ?>>Grade <?php echo $i; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Section</label>
                                    <input type="text" name="section" value="<?php echo htmlspecialchars($student['section'] ?: ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Status *</label>
                                    <select name="status" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        <option value="Active" <?php echo $student['status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                                        <option value="Inactive" <?php echo $student['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                        <option value="Pending" <?php echo $student['status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="Transferred" <?php echo $student['status'] === 'Transferred' ? 'selected' : ''; ?>>Transferred</option>
                                        <option value="Graduated" <?php echo $student['status'] === 'Graduated' ? 'selected' : ''; ?>>Graduated</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                                <i class="fas fa-phone mr-2 text-blue-600"></i> Contact Information
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                    <input type="email" name="email" value="<?php echo htmlspecialchars($student['email'] ?: ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Contact Number</label>
                                    <input type="text" name="contact_number" value="<?php echo htmlspecialchars($student['contact_number'] ?: ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <!-- Guardian Information -->
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-4 border-b pb-2">
                                <i class="fas fa-users mr-2 text-blue-600"></i> Guardian Information
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Guardian Name</label>
                                    <input type="text" name="guardian_name" value="<?php echo htmlspecialchars($student['guardian_name'] ?: ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Guardian Contact</label>
                                    <input type="text" name="guardian_contact" value="<?php echo htmlspecialchars($student['guardian_contact'] ?: ''); ?>"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Relationship</label>
                                    <input type="text" name="guardian_relationship" value="<?php echo htmlspecialchars($student['guardian_relationship'] ?: ''); ?>"
                                           placeholder="e.g., Mother, Father, Guardian"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                </div>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="flex justify-between items-center pt-6 border-t">
                            <a href="view_students.php" class="text-gray-600 hover:text-gray-800">
                                <i class="fas fa-arrow-left mr-2"></i> Cancel
                            </a>
                            <div class="space-x-3">
                                <a href="view_student.php?id=<?php echo $student_id; ?>" class="inline-block bg-gray-500 text-white px-6 py-2 rounded-lg hover:bg-gray-600">
                                    <i class="fas fa-eye mr-2"></i> View Details
                                </a>
                                <button type="submit" class="bg-blue-600 text-white px-6 py-2 rounded-lg hover:bg-blue-700">
                                    <i class="fas fa-save mr-2"></i> Save Changes
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
