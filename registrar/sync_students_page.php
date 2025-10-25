<?php
session_start();

// Check if user is logged in as registrar
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'registrar') {
    header('Location: ../login.php');
    exit();
}

// Include database configuration
require_once '../config/database.php';

// Initialize database connection
$database = new Database();
$registrar_conn = $database->getConnection('registrar');
$student_conn = $database->getConnection('student');

// Get registrar information
$registrar_id = $_SESSION['user_id'];
$registrar = [
    'user_id' => $registrar_id,
    'first_name' => 'Registrar',
    'last_name' => 'User',
];

try {
    $stmt = $registrar_conn->prepare("SELECT * FROM registrars WHERE user_id = ?");
    $stmt->execute([$registrar_id]);
    $db_registrar = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($db_registrar) {
        $registrar = array_merge($registrar, $db_registrar);
    }
} catch (PDOException $e) {
    error_log("Error fetching registrar data: " . $e->getMessage());
}

// Set user initials for avatar
$initials = '';
if (!empty($registrar['first_name']) && !empty($registrar['last_name'])) {
    $initials = strtoupper(substr($registrar['first_name'], 0, 1) . substr($registrar['last_name'], 0, 1));
} else {
    $initials = 'RU';
}

// Get student counts
$student_db_count = 0;
$registrar_db_count = 0;

try {
    $stmt = $student_conn->query("SELECT COUNT(*) as count FROM students");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $student_db_count = $result['count'] ?? 0;
    
    $stmt = $registrar_conn->query("SELECT COUNT(*) as count FROM students");
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $registrar_db_count = $result['count'] ?? 0;
} catch (PDOException $e) {
    error_log("Error fetching student counts: " . $e->getMessage());
}

// Get preview of students from student database
$preview_students = [];
try {
    $stmt = $student_conn->prepare("
        SELECT s.*, u.email, u.username
        FROM students s
        LEFT JOIN login_db.users u ON s.user_id = u.id
        ORDER BY s.id
        LIMIT 10
    ");
    $stmt->execute();
    $preview_students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching preview students: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Students - San Agustin Elementary School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-gradient-to-r from-blue-600 to-blue-800 text-white shadow-md">
        <div class="max-w-7xl mx-auto px-4 py-4 sm:px-6 lg:px-8 flex justify-between items-center">
            <div class="flex items-center">
                <a href="dashboard.php" class="text-xl font-semibold">
                    <i class="fas fa-graduation-cap mr-2"></i> San Agustin ES - Registrar
                </a>
            </div>
            <div class="flex items-center space-x-4">
                <div class="relative">
                    <button id="user-menu-btn" class="flex items-center text-sm text-white focus:outline-none">
                        <span class="w-8 h-8 rounded-full bg-blue-500 flex items-center justify-center text-white font-medium">
                            <?php echo $initials; ?>
                        </span>
                        <span class="ml-2"><?php echo htmlspecialchars($registrar['first_name'] . ' ' . $registrar['last_name']); ?></span>
                        <i class="fas fa-chevron-down ml-1 text-xs"></i>
                    </button>
                    <div id="user-menu" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-user mr-2"></i> Profile
                        </a>
                        <a href="../logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-sign-out-alt mr-2"></i> Sign out
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 py-6 sm:px-6 lg:px-8">
        <!-- Back button -->
        <div class="mb-6">
            <a href="view_students.php" class="inline-flex items-center text-blue-600 hover:text-blue-800">
                <i class="fas fa-arrow-left mr-2"></i> Back to Students
            </a>
        </div>

        <!-- Page Header -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-900">Sync Student Data</h1>
            <p class="mt-1 text-sm text-gray-600">
                Import student records from the Student Database to the Registrar Database
            </p>
        </div>

        <!-- Alert Container -->
        <div id="alert-container" class="mb-6"></div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Student Database</p>
                        <p class="text-3xl font-bold text-blue-600"><?php echo $student_db_count; ?></p>
                        <p class="text-xs text-gray-500">Total students</p>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-database text-blue-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">Registrar Database</p>
                        <p class="text-3xl font-bold text-green-600"><?php echo $registrar_db_count; ?></p>
                        <p class="text-xs text-gray-500">Total students</p>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-users text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm text-gray-600">To Sync</p>
                        <p class="text-3xl font-bold text-orange-600"><?php echo max(0, $student_db_count - $registrar_db_count); ?></p>
                        <p class="text-xs text-gray-500">New records</p>
                    </div>
                    <div class="w-12 h-12 bg-orange-100 rounded-full flex items-center justify-center">
                        <i class="fas fa-sync text-orange-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sync Action Card -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-lg font-semibold text-gray-900 mb-4">Sync Students</h2>
            <p class="text-gray-600 mb-4">
                This will fetch all student records from the Student Database and sync them to the Registrar Database. 
                Existing records will be updated, and new records will be created.
            </p>
            <div class="flex items-center space-x-4">
                <button id="sync-btn" onclick="syncStudents()" class="inline-flex items-center px-6 py-3 border border-transparent text-base font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-sync-alt mr-2"></i> Sync Now
                </button>
                <button onclick="window.location.reload()" class="inline-flex items-center px-6 py-3 border border-gray-300 text-base font-medium rounded-md shadow-sm text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    <i class="fas fa-redo mr-2"></i> Refresh
                </button>
            </div>
        </div>

        <!-- Preview Students -->
        <?php if (!empty($preview_students)): ?>
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-lg font-semibold text-gray-900">Preview: Students in Student Database</h2>
                <p class="text-sm text-gray-600">Showing first 10 records</p>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade & Section</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($preview_students as $student): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($student['student_id']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                </div>
                                <?php if (!empty($student['username'])): ?>
                                <div class="text-sm text-gray-500">@<?php echo htmlspecialchars($student['username']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                Grade <?php echo htmlspecialchars($student['grade_level']); ?> 
                                <?php echo !empty($student['section']) ? htmlspecialchars($student['section']) : ''; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                <?php echo htmlspecialchars($student['email'] ?? 'N/A'); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    <?php echo ($student['status'] ?? 'Active') === 'Active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                    <?php echo htmlspecialchars($student['status'] ?? 'Active'); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-yellow-700">
                        No students found in the Student Database.
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </main>

    <script>
        // Toggle user menu
        document.getElementById('user-menu-btn').addEventListener('click', function() {
            document.getElementById('user-menu').classList.toggle('hidden');
        });

        // Close user menu when clicking outside
        document.addEventListener('click', function(event) {
            const userMenu = document.getElementById('user-menu');
            const userButton = document.getElementById('user-menu-btn');
            
            if (userMenu && userButton && !userMenu.contains(event.target) && !userButton.contains(event.target)) {
                userMenu.classList.add('hidden');
            }
        });

        // Sync students function
        function syncStudents() {
            const syncBtn = document.getElementById('sync-btn');
            const originalBtnText = syncBtn.innerHTML;
            
            // Disable button and show loading
            syncBtn.disabled = true;
            syncBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Syncing...';
            
            fetch('sync_students.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    
                    // Show additional details
                    if (data.synced > 0 || data.updated > 0) {
                        let details = `<ul class="list-disc list-inside mt-2">`;
                        if (data.synced > 0) details += `<li>${data.synced} new students added</li>`;
                        if (data.updated > 0) details += `<li>${data.updated} existing students updated</li>`;
                        details += `</ul>`;
                        
                        showAlert('success', details);
                    }
                    
                    // Show errors if any
                    if (data.errors && data.errors.length > 0) {
                        let errorMsg = '<strong>Some errors occurred:</strong><ul class="list-disc list-inside mt-2">';
                        data.errors.forEach(error => {
                            errorMsg += `<li>${error}</li>`;
                        });
                        errorMsg += '</ul>';
                        showAlert('warning', errorMsg);
                    }
                    
                    // Reload page after 3 seconds
                    setTimeout(() => {
                        window.location.reload();
                    }, 3000);
                } else {
                    showAlert('error', data.message || 'Failed to sync students');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showAlert('error', 'An error occurred while syncing students: ' + error.message);
            })
            .finally(() => {
                syncBtn.disabled = false;
                syncBtn.innerHTML = originalBtnText;
            });
        }

        // Show alert function
        function showAlert(type, message) {
            const alertContainer = document.getElementById('alert-container');
            const alertColors = {
                success: 'bg-green-100 border-green-500 text-green-700',
                error: 'bg-red-100 border-red-500 text-red-700',
                warning: 'bg-yellow-100 border-yellow-500 text-yellow-700',
                info: 'bg-blue-100 border-blue-500 text-blue-700'
            };
            
            const alertIcons = {
                success: 'fa-check-circle',
                error: 'fa-exclamation-circle',
                warning: 'fa-exclamation-triangle',
                info: 'fa-info-circle'
            };
            
            const alertDiv = document.createElement('div');
            alertDiv.className = `${alertColors[type]} border-l-4 p-4 rounded mb-4`;
            alertDiv.innerHTML = `
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas ${alertIcons[type]}"></i>
                    </div>
                    <div class="ml-3">
                        <div class="text-sm">${message}</div>
                    </div>
                    <div class="ml-auto pl-3">
                        <button onclick="this.parentElement.parentElement.parentElement.remove()" class="inline-flex text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
            
            alertContainer.appendChild(alertDiv);
            
            // Auto-remove after 10 seconds
            setTimeout(() => {
                if (alertDiv.parentElement) {
                    alertDiv.remove();
                }
            }, 10000);
        }
    </script>
</body>
</html>
