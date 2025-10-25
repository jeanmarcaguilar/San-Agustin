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
$pdo = $database->getConnection('registrar');

// Get registrar information from database
$registrar_id = $_SESSION['user_id'];
$registrar = [
    'user_id' => $registrar_id,
    'first_name' => 'Registrar',
    'last_name' => 'User',
    'contact_number' => '',
];

try {
    $stmt = $pdo->prepare("SELECT * FROM registrars WHERE user_id = ?");
    $stmt->execute([$registrar_id]);
    $db_registrar = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($db_registrar) {
        $registrar = array_merge($registrar, $db_registrar);
    }
} catch (PDOException $e) {
    error_log("Error fetching registrar data: " . $e->getMessage());
}

// Get school years for filters
$school_years = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT school_year FROM enrollments ORDER BY school_year DESC");
    $school_years = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching school years: " . $e->getMessage());
}

// Get grade levels for filters
$grade_levels = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT grade_level FROM students WHERE grade_level IS NOT NULL ORDER BY grade_level");
    $grade_levels = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log("Error fetching grade levels: " . $e->getMessage());
}

// Set user initials for avatar
$initials = '';
if (!empty($registrar['first_name']) && !empty($registrar['last_name'])) {
    $initials = strtoupper(substr($registrar['first_name'], 0, 1) . substr($registrar['last_name'], 0, 1));
} elseif (!empty($registrar['first_name'])) {
    $initials = strtoupper(substr($registrar['first_name'], 0, 2));
} elseif (!empty($_SESSION['username'])) {
    $initials = strtoupper(substr($_SESSION['username'], 0, 2));
} else {
    $initials = 'RU'; // Default initials
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - Registrar Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-4 sm:px-6 lg:px-8 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-900">Reports</h1>
            <div class="flex items-center space-x-4">
                <span class="text-gray-700"><?php echo htmlspecialchars($registrar['first_name'] . ' ' . $registrar['last_name']); ?></span>
                <div class="relative">
                    <button id="user-menu" class="flex items-center text-sm rounded-full focus:outline-none" aria-expanded="false">
                        <div class="h-8 w-8 rounded-full bg-blue-500 text-white flex items-center justify-center">
                            <?php echo $initials; ?>
                        </div>
                    </button>
                    <div id="user-dropdown" class="hidden absolute right-0 mt-2 w-48 bg-white rounded-md shadow-lg py-1 z-50">
                        <a href="profile.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Your Profile</a>
                        <a href="settings.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Settings</a>
                        <a href="logout.php" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">Sign out</a>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="flex h-screen bg-gray-100">
        <!-- Sidebar -->
        <div class="bg-gray-800 text-white w-64 space-y-6 py-7 px-2 absolute inset-y-0 left-0 transform -translate-x-full md:relative md:translate-x-0 transition duration-200 ease-in-out">
            <div class="flex items-center space-x-2 px-4">
                <span class="text-2xl font-extrabold">Registrar</span>
            </div>
            <nav>
                <a href="dashboard.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-tachometer-alt mr-2"></i>Dashboard
                </a>
                <a href="student_search.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-search mr-2"></i>Student Search
                </a>
                <a href="enrollment.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-user-plus mr-2"></i>Enrollment
                </a>
                <a href="view_sections.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-chalkboard mr-2"></i>View Sections
                </a>
                <a href="documents.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-file-alt mr-2"></i>Document Requests
                </a>
                <a href="reports.php" class="block py-2.5 px-4 rounded transition duration-200 bg-blue-700 hover:bg-blue-600">
                    <i class="fas fa-chart-bar mr-2"></i>Reports
                </a>
                <a href="settings.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-cog mr-2"></i>Settings
                </a>
            </nav>
        </div>

        <!-- Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 p-6">
                <!-- Report Filters -->
                <div class="bg-white shadow rounded-lg p-6 mb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Generate Report</h2>
                    <form id="report-form" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="report-type" class="block text-sm font-medium text-gray-700">Report Type</label>
                                <select id="report-type" name="report-type" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="enrollment">Enrollment Summary</option>
                                    <option value="demographic">Demographic Report</option>
                                    <option value="attendance">Attendance Report</option>
                                    <option value="document">Document Request Report</option>
                                    <option value="custom">Custom Report</option>
                                </select>
                            </div>
                            <div id="school-year-container">
                                <label for="school-year" class="block text-sm font-medium text-gray-700">School Year</label>
                                <select id="school-year" name="school-year" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="">All Years</option>
                                    <?php foreach ($school_years as $year): ?>
                                        <option value="<?php echo htmlspecialchars($year); ?>"><?php echo htmlspecialchars($year); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="grade-level-container">
                                <label for="grade-level" class="block text-sm font-medium text-gray-700">Grade Level</label>
                                <select id="grade-level" name="grade-level" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md">
                                    <option value="">All Grades</option>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?php echo $i; ?>">Grade <?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="date-range-container">
                            <div>
                                <label for="start-date" class="block text-sm font-medium text-gray-700">Start Date</label>
                                <input type="date" id="start-date" name="start-date" class="mt-1 block w-full pl-3 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                            <div>
                                <label for="end-date" class="block text-sm font-medium text-gray-700">End Date</label>
                                <input type="date" id="end-date" name="end-date" class="mt-1 block w-full pl-3 pr-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button type="button" id="generate-report" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                <i class="fas fa-file-export mr-2"></i> Generate Report
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Report Content -->
                <div class="bg-white shadow rounded-lg overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">Report Preview</h3>
                    </div>
                    <div class="p-6">
                        <div id="report-placeholder" class="text-center py-12 bg-gray-50 rounded-lg">
                            <i class="fas fa-chart-pie text-4xl text-gray-400 mb-3"></i>
                            <p class="text-gray-500">Select report options and click "Generate Report" to view data</p>
                        </div>
                        <div id="report-content" class="hidden">
                            <!-- Report content will be loaded here via JavaScript -->
                            <div class="mb-6">
                                <h4 class="text-lg font-medium text-gray-900 mb-4">Enrollment Summary (2023-2024)</h4>
                                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                                    <div class="bg-blue-50 p-4 rounded-lg">
                                        <p class="text-sm font-medium text-gray-500">Total Students</p>
                                        <p class="text-2xl font-bold text-blue-600">1,245</p>
                                        <p class="text-xs text-gray-500 mt-1">+12% from last year</p>
                                    </div>
                                    <div class="bg-green-50 p-4 rounded-lg">
                                        <p class="text-sm font-medium text-gray-500">New Enrollments</p>
                                        <p class="text-2xl font-bold text-green-600">256</p>
                                        <p class="text-xs text-gray-500 mt-1">+8% from last year</p>
                                    </div>
                                    <div class="bg-yellow-50 p-4 rounded-lg">
                                        <p class="text-sm font-medium text-gray-500">Average Class Size</p>
                                        <p class="text-2xl font-bold text-yellow-600">28</p>
                                        <p class="text-xs text-gray-500 mt-1">-2 from last year</p>
                                    </div>
                                    <div class="bg-purple-50 p-4 rounded-lg">
                                        <p class="text-sm font-medium text-gray-500">Retention Rate</p>
                                        <p class="text-2xl font-bold text-purple-600">94%</p>
                                        <p class="text-xs text-gray-500 mt-1">+2% from last year</p>
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="bg-white p-4 border border-gray-200 rounded-lg">
                                        <h5 class="font-medium text-gray-900 mb-4">Enrollment by Grade Level</h5>
                                        <canvas id="enrollmentChart" height="250"></canvas>
                                    </div>
                                    <div class="bg-white p-4 border border-gray-200 rounded-lg">
                                        <h5 class="font-medium text-gray-900 mb-4">Gender Distribution</h5>
                                        <canvas id="genderChart" height="250"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-6">
                                <div class="flex justify-between items-center mb-4">
                                    <h4 class="text-lg font-medium text-gray-900">Detailed Breakdown</h4>
                                    <div class="flex space-x-2">
                                        <button type="button" class="inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-sm leading-4 font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                            <i class="fas fa-file-export mr-1.5"></i> Export
                                        </button>
                                        <button type="button" class="inline-flex items-center px-3 py-1.5 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                            <i class="fas fa-print mr-1.5"></i> Print
                                        </button>
                                    </div>
                                </div>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade Level</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Section</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Students</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Male</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Female</th>
                                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class Adviser</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php
                                            // Sample data - in a real application, this would come from the database
                                            $sections = [
                                                ['grade' => 7, 'section' => 'A', 'total' => 42, 'male' => 20, 'female' => 22, 'adviser' => 'Ms. Santos'],
                                                ['grade' => 7, 'section' => 'B', 'total' => 40, 'male' => 19, 'female' => 21, 'adviser' => 'Mr. Reyes'],
                                                ['grade' => 8, 'section' => 'A', 'total' => 45, 'male' => 22, 'female' => 23, 'adviser' => 'Mrs. Cruz'],
                                                ['grade' => 8, 'section' => 'B', 'total' => 43, 'male' => 21, 'female' => 22, 'adviser' => 'Mr. Bautista'],
                                                ['grade' => 9, 'section' => 'A', 'total' => 41, 'male' => 20, 'female' => 21, 'adviser' => 'Ms. Dela Cruz'],
                                                ['grade' => 9, 'section' => 'B', 'total' => 44, 'male' => 21, 'female' => 23, 'adviser' => 'Mr. Garcia'],
                                                ['grade' => 10, 'section' => 'A', 'total' => 46, 'male' => 22, 'female' => 24, 'adviser' => 'Mrs. Reyes'],
                                                ['grade' => 10, 'section' => 'B', 'total' => 42, 'male' => 20, 'female' => 22, 'adviser' => 'Mr. Santos'],
                                                ['grade' => 11, 'section' => 'STEM', 'total' => 38, 'male' => 18, 'female' => 20, 'adviser' => 'Ms. Torres'],
                                                ['grade' => 11, 'section' => 'HUMSS', 'total' => 36, 'male' => 15, 'female' => 21, 'adviser' => 'Mr. Lopez'],
                                                ['grade' => 11, 'section' => 'ABM', 'total' => 40, 'male' => 19, 'female' => 21, 'adviser' => 'Mrs. Mendoza'],
                                                ['grade' => 12, 'section' => 'STEM', 'total' => 42, 'male' => 20, 'female' => 22, 'adviser' => 'Mr. Cruz'],
                                                ['grade' => 12, 'section' => 'HUMSS', 'total' => 38, 'male' => 16, 'female' => 22, 'adviser' => 'Ms. Reyes'],
                                                ['grade' => 12, 'section' => 'ABM', 'total' => 39, 'male' => 18, 'female' => 21, 'adviser' => 'Mr. Bautista'],
                                            ];
                                            
                                            foreach ($sections as $section):
                                            ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    Grade <?php echo $section['grade']; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $section['section']; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $section['total']; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $section['male']; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $section['female']; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo $section['adviser']; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot class="bg-gray-50">
                                            <tr>
                                                <th colspan="2" class="px-6 py-3 text-left text-sm font-medium text-gray-700 uppercase tracking-wider">Total</th>
                                                <th class="px-6 py-3 text-left text-sm font-medium text-gray-700">1,245</th>
                                                <th class="px-6 py-3 text-left text-sm font-medium text-gray-700">600</th>
                                                <th class="px-6 py-3 text-left text-sm font-medium text-gray-700">645</th>
                                                <th class="px-6 py-3"></th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Toggle user dropdown
        document.getElementById('user-menu').addEventListener('click', function() {
            document.getElementById('user-dropdown').classList.toggle('hidden');
        });

        // Close dropdown when clicking outside
        window.addEventListener('click', function(event) {
            if (!event.target.matches('#user-menu') && !event.target.closest('#user-menu')) {
                const dropdown = document.getElementById('user-dropdown');
                if (!dropdown.classList.contains('hidden')) {
                    dropdown.classList.add('hidden');
                }
            }
        });

        // Handle report generation
        document.getElementById('generate-report').addEventListener('click', function() {
            const reportType = document.getElementById('report-type').value;
            const schoolYear = document.getElementById('school-year').value;
            const gradeLevel = document.getElementById('grade-level').value;
            const startDate = document.getElementById('start-date').value;
            const endDate = document.getElementById('end-date').value;
            
            // Show loading state
            const generateBtn = document.getElementById('generate-report');
            const originalBtnText = generateBtn.innerHTML;
            generateBtn.disabled = true;
            generateBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Generating...';
            
            // Simulate API call with setTimeout
            setTimeout(function() {
                // Show report content
                document.getElementById('report-placeholder').classList.add('hidden');
                document.getElementById('report-content').classList.remove('hidden');
                
                // Reset button
                generateBtn.disabled = false;
                generateBtn.innerHTML = originalBtnText;
                
                // Initialize charts
                initCharts();
            }, 1000);
        });
        
        // Initialize charts
        function initCharts() {
            // Enrollment by Grade Level Chart
            const enrollmentCtx = document.getElementById('enrollmentChart').getContext('2d');
            new Chart(enrollmentCtx, {
                type: 'bar',
                data: {
                    labels: ['Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'Grade 12'],
                    datasets: [{
                        label: 'Number of Students',
                        data: [82, 88, 85, 88, 114, 119],
                        backgroundColor: [
                            'rgba(59, 130, 246, 0.7)',
                            'rgba(16, 185, 129, 0.7)',
                            'rgba(245, 158, 11, 0.7)',
                            'rgba(139, 92, 246, 0.7)',
                            'rgba(239, 68, 68, 0.7)',
                            'rgba(20, 184, 166, 0.7)'
                        ],
                        borderColor: [
                            'rgba(59, 130, 246, 1)',
                            'rgba(16, 185, 129, 1)',
                            'rgba(245, 158, 11, 1)',
                            'rgba(139, 92, 246, 1)',
                            'rgba(239, 68, 68, 1)',
                            'rgba(20, 184, 166, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                precision: 0
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        }
                    }
                }
            });
            
            // Gender Distribution Chart
            const genderCtx = document.getElementById('genderChart').getContext('2d');
            new Chart(genderCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Male', 'Female'],
                    datasets: [{
                        data: [600, 645],
                        backgroundColor: [
                            'rgba(59, 130, 246, 0.7)',
                            'rgba(236, 72, 153, 0.7)'
                        ],
                        borderColor: [
                            'rgba(59, 130, 246, 1)',
                            'rgba(236, 72, 153, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
        
        // Initialize date fields with default values (current school year)
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date();
            const currentYear = today.getFullYear();
            const currentMonth = today.getMonth() + 1; // JavaScript months are 0-indexed
            
            // Set default date range for the current school year (June to May)
            let startYear, endYear;
            if (currentMonth >= 6) {
                // If current month is June or later, it's the start of the school year
                startYear = currentYear;
                endYear = currentYear + 1;
            } else {
                // If before June, it's the second half of the school year
                startYear = currentYear - 1;
                endYear = currentYear;
            }
            
            document.getElementById('start-date').value = `${startYear}-06-01`;
            document.getElementById('end-date').value = `${endYear}-05-31`;
            
            // Set current school year in the dropdown if it exists
            const currentSchoolYear = `${startYear}-${endYear.toString().substr(-2)}`;
            const schoolYearSelect = document.getElementById('school-year');
            for (let i = 0; i < schoolYearSelect.options.length; i++) {
                if (schoolYearSelect.options[i].value === currentSchoolYear) {
                    schoolYearSelect.selectedIndex = i;
                    break;
                }
            }
        });
    </script>
</body>
</html>
