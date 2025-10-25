<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    $_SESSION['error'] = 'Please log in first.';
    header('Location: ../login.php');
    exit;
}

if (strtolower($_SESSION['role']) !== 'teacher') {
    $_SESSION['error'] = 'Access denied. Teacher access only.';
    header('Location: ../login.php');
    exit;
}

// Include database connections
require_once '../config/database.php';
$database = new Database();
$teacher_conn = $database->getConnection('teacher');

// Get teacher info
$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['full_name'] ?? 'Teacher';
$subject = $_SESSION['subject'] ?? 'Subject';
$grade_level = $_SESSION['grade_level'] ?? '';
$section = $_SESSION['section'] ?? '';

// Get grade distribution data
try {
    // Get grade distribution
    $stmt = $teacher_conn->prepare("
        SELECT 
            COUNT(CASE WHEN final_grade >= 90 THEN 1 END) as a,
            COUNT(CASE WHEN final_grade >= 80 AND final_grade < 90 THEN 1 END) as b,
            COUNT(CASE WHEN final_grade >= 70 AND final_grade < 80 THEN 1 END) as c,
            COUNT(CASE WHEN final_grade >= 60 AND final_grade < 70 THEN 1 END) as d,
            COUNT(CASE WHEN final_grade < 60 THEN 1 END) as f,
            COUNT(*) as total_students,
            AVG(final_grade) as class_average,
            MIN(final_grade) as min_grade,
            MAX(final_grade) as max_grade,
            STDDEV(final_grade) as std_deviation
        FROM student_grades
        WHERE teacher_id = ? AND subject = ?
    ");
    $stmt->execute([$teacher_id, $subject]);
    $grade_distribution = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get grade distribution by gender (if available)
    $grade_by_gender = [
        'male' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0, 'total' => 0],
        'female' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0, 'total' => 0]
    ];
    
    try {
        $stmt = $teacher_conn->prepare("
            SELECT 
                s.gender,
                COUNT(CASE WHEN sg.final_grade >= 90 THEN 1 END) as a,
                COUNT(CASE WHEN sg.final_grade >= 80 AND sg.final_grade < 90 THEN 1 END) as b,
                COUNT(CASE WHEN sg.final_grade >= 70 AND sg.final_grade < 80 THEN 1 END) as c,
                COUNT(CASE WHEN sg.final_grade >= 60 AND sg.final_grade < 70 THEN 1 END) as d,
                COUNT(CASE WHEN sg.final_grade < 60 THEN 1 END) as f,
                COUNT(*) as total
            FROM students s
            JOIN student_grades sg ON s.id = sg.student_id
            WHERE sg.teacher_id = ? AND sg.subject = ?
            GROUP BY s.gender
        ");
        $stmt->execute([$teacher_id, $subject]);
        $gender_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($gender_results as $row) {
            $gender = strtolower($row['gender']) === 'm' ? 'male' : 'female';
            $grade_by_gender[$gender] = [
                'A' => (int)$row['a'],
                'B' => (int)$row['b'],
                'C' => (int)$row['c'],
                'D' => (int)$row['d'],
                'F' => (int)$row['f'],
                'total' => (int)$row['total']
            ];
        }
    } catch (Exception $e) {
        // Gender data might not be available, continue without it
        error_log("Could not retrieve grade distribution by gender: " . $e->getMessage());
    }
    
    // Get grade trend over time (by month)
    $stmt = $teacher_conn->prepare("
        SELECT 
            DATE_FORMAT(updated_at, '%Y-%m') as month,
            AVG(final_grade) as avg_grade,
            COUNT(*) as count
        FROM student_grades
        WHERE teacher_id = ? AND subject = ?
        GROUP BY DATE_FORMAT(updated_at, '%Y-%m')
        ORDER BY month
        LIMIT 6
    ");
    $stmt->execute([$teacher_id, $subject]);
    $grade_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare data for charts
    $grade_labels = ['A (90-100)', 'B (80-89)', 'C (70-79)', 'D (60-69)', 'F (Below 60)'];
    $grade_data = [
        $grade_distribution['a'] ?? 0,
        $grade_distribution['b'] ?? 0,
        $grade_distribution['c'] ?? 0,
        $grade_distribution['d'] ?? 0,
        $grade_distribution['f'] ?? 0
    ];
    
    $male_data = [
        $grade_by_gender['male']['A'] ?? 0,
        $grade_by_gender['male']['B'] ?? 0,
        $grade_by_gender['male']['C'] ?? 0,
        $grade_by_gender['male']['D'] ?? 0,
        $grade_by_gender['male']['F'] ?? 0
    ];
    
    $female_data = [
        $grade_by_gender['female']['A'] ?? 0,
        $grade_by_gender['female']['B'] ?? 0,
        $grade_by_gender['female']['C'] ?? 0,
        $grade_by_gender['female']['D'] ?? 0,
        $grade_by_gender['female']['F'] ?? 0
    ];
    
    $trend_months = [];
    $trend_grades = [];
    
    foreach ($grade_trend as $trend) {
        $trend_months[] = date('M Y', strtotime($trend['month'] . '-01'));
        $trend_grades[] = (float)$trend['avg_grade'];
    }
    
} catch (PDOException $e) {
    $error_message = "Error fetching grade distribution data: " . $e->getMessage();
    error_log($error_message);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Grade Distribution Report - San Agustin Elementary School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-50">
    <div class="min-h-screen flex">
        <!-- Include sidebar -->
        <?php include 'includes/sidebar.php'; ?>
        
        <div class="flex-1 flex flex-col">
            <!-- Header -->
            <?php include 'includes/header.php'; ?>
            
            <!-- Main Content -->
            <main class="flex-1 overflow-y-auto p-6">
                <!-- Page Header -->
                <div class="bg-white rounded-xl p-6 mb-6 shadow-sm border border-gray-200">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-800">Grade Distribution Report</h1>
                            <div class="flex items-center text-gray-600 mt-2">
                                <i class="fas fa-chalkboard-teacher mr-2"></i>
                                <span>Teacher: <?php echo htmlspecialchars($teacher_name); ?></span>
                                <?php if (!empty($subject)): ?>
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-book mr-1"></i>
                                    <span><?php echo htmlspecialchars($subject); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($grade_level) && !empty($section)): ?>
                                    <span class="mx-2">•</span>
                                    <i class="fas fa-layer-group mr-1"></i>
                                    <span>Grade <?php echo htmlspecialchars($grade_level); ?> - <?php echo htmlspecialchars($section); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mt-4 md:mt-0 flex space-x-2">
                            <button onclick="window.print()" class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                <i class="fas fa-print mr-2"></i> Print
                            </button>
                            <a href="reports.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md shadow-sm text-white bg-blue-600 hover:bg-blue-700">
                                <i class="fas fa-arrow-left mr-2"></i> Back to Reports
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white p-6 rounded-lg shadow border border-gray-200">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 text-blue-500 mr-4">
                                <i class="fas fa-users text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Total Students</p>
                                <p class="text-2xl font-bold"><?php echo $grade_distribution['total_students'] ?? 0; ?></p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow border border-green-200">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 text-green-500 mr-4">
                                <i class="fas fa-chart-line text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Class Average</p>
                                <p class="text-2xl font-bold">
                                    <?php echo isset($grade_distribution['class_average']) ? number_format($grade_distribution['class_average'], 1) . '%' : 'N/A'; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow border border-yellow-200">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-yellow-100 text-yellow-500 mr-4">
                                <i class="fas fa-chart-bar text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Standard Deviation</p>
                                <p class="text-2xl font-bold">
                                    <?php echo isset($grade_distribution['std_deviation']) ? number_format($grade_distribution['std_deviation'], 2) : 'N/A'; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-white p-6 rounded-lg shadow border border-purple-200">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-purple-100 text-purple-500 mr-4">
                                <i class="fas fa-percentage text-xl"></i>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Passing Rate (70%+)</p>
                                <p class="text-2xl font-bold">
                                    <?php 
                                        $passing = ($grade_distribution['a'] ?? 0) + ($grade_distribution['b'] ?? 0) + ($grade_distribution['c'] ?? 0);
                                        $passing_rate = $grade_distribution['total_students'] > 0 ? 
                                            round(($passing / $grade_distribution['total_students']) * 100) : 0;
                                        echo $passing_rate . '%';
                                    ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Row -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                    <!-- Grade Distribution -->
                    <div class="bg-white p-6 rounded-lg shadow border border-gray-200">
                        <h3 class="text-lg font-medium text-gray-800 mb-4">Grade Distribution</h3>
                        <div class="h-80">
                            <canvas id="gradeDistributionChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Grade Trend -->
                    <div class="bg-white p-6 rounded-lg shadow border border-gray-200">
                        <h3 class="text-lg font-medium text-gray-800 mb-4">Grade Trend (Last 6 Months)</h3>
                        <div class="h-80">
                            <canvas id="gradeTrendChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Grade Distribution by Gender -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden mb-6">
                    <div class="p-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-800">Grade Distribution by Gender</h3>
                    </div>
                    <div class="p-6">
                        <div class="h-96">
                            <canvas id="genderDistributionChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Detailed Grade Breakdown -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="text-lg font-medium text-gray-800">Detailed Grade Breakdown</h3>
                        <div class="flex items-center space-x-2">
                            <button id="exportBtn" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md shadow-sm text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
                                <i class="fas fa-file-export mr-1"></i> Export to Excel
                            </button>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade Range</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Number of Students</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Percentage</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Grade Points</th>
                                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">GPA</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php 
                                    $grade_ranges = [
                                        ['A', '90-100%', 4.0],
                                        ['B', '80-89%', 3.0],
                                        ['C', '70-79%', 2.0],
                                        ['D', '60-69%', 1.0],
                                        ['F', 'Below 60%', 0.0]
                                    ];
                                    $total_grade_points = 0;
                                    $total_grades = 0;
                                    
                                    foreach ($grade_ranges as $index => $range):
                                        $count = $grade_distribution[strtolower($range[0])] ?? 0;
                                        $percentage = $grade_distribution['total_students'] > 0 ? 
                                            round(($count / $grade_distribution['total_students']) * 100, 1) : 0;
                                        $grade_points = $count * $range[2];
                                        $total_grade_points += $grade_points;
                                        $total_grades += $count;
                                        
                                        $row_class = '';
                                        if ($range[0] === 'A') $row_class = 'bg-green-50';
                                        elseif ($range[0] === 'F') $row_class = 'bg-red-50';
                                ?>
                                    <tr class="<?php echo $row_class; ?>">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo $range[0] . ' (' . $range[1] . ')'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">
                                            <?php echo $count; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                            <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                                                <?php echo $percentage; ?>%
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-500">
                                            <?php echo $grade_points; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-center text-sm font-medium">
                                            <?php echo $count > 0 ? number_format($range[2], 1) : '0.0'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <tr class="bg-gray-50 font-medium">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        Total / Average
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                        <?php echo $grade_distribution['total_students'] ?? 0; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                        100%
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                        <?php echo $total_grade_points; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                        <?php 
                                            $gpa = $total_grades > 0 ? $total_grade_points / $total_grades : 0;
                                            echo number_format($gpa, 2);
                                        ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </main>
            
            <!-- Footer -->
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Grade Distribution Chart
            const gradeCtx = document.getElementById('gradeDistributionChart');
            if (gradeCtx) {
                new Chart(gradeCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($grade_labels); ?>,
                        datasets: [{
                            label: 'Number of Students',
                            data: <?php echo json_encode($grade_data); ?>,
                            backgroundColor: [
                                'rgba(16, 185, 129, 0.7)',  // Green for A
                                'rgba(59, 130, 246, 0.7)',   // Blue for B
                                'rgba(245, 158, 11, 0.7)',   // Yellow for C
                                'rgba(249, 115, 22, 0.7)',   // Orange for D
                                'rgba(239, 68, 68, 0.7)'     // Red for F
                            ],
                            borderColor: [
                                'rgba(16, 185, 129, 1)',
                                'rgba(59, 130, 246, 1)',
                                'rgba(245, 158, 11, 1)',
                                'rgba(249, 115, 22, 1)',
                                'rgba(239, 68, 68, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100) || 0;
                                        return `${label}: ${value} (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            }

            // Grade Trend Chart
            const trendCtx = document.getElementById('gradeTrendChart');
            if (trendCtx) {
                new Chart(trendCtx, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($trend_months); ?>,
                        datasets: [{
                            label: 'Average Grade',
                            data: <?php echo json_encode($trend_grades); ?>,
                            borderColor: 'rgba(99, 102, 241, 1)',
                            backgroundColor: 'rgba(99, 102, 241, 0.1)',
                            borderWidth: 2,
                            tension: 0.3,
                            fill: true,
                            pointBackgroundColor: 'white',
                            pointBorderColor: 'rgba(99, 102, 241, 1)',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return `Average: ${context.raw}%`;
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                min: 0,
                                max: 100,
                                ticks: {
                                    callback: function(value) {
                                        return value + '%';
                                    }
                                }
                            }
                        }
                    }
                });
            }

            // Gender Distribution Chart
            const genderCtx = document.getElementById('genderDistributionChart');
            if (genderCtx) {
                new Chart(genderCtx, {
                    type: 'bar',
                    data: {
                        labels: ['A', 'B', 'C', 'D', 'F'],
                        datasets: [
                            {
                                label: 'Male',
                                data: <?php echo json_encode($male_data); ?>,
                                backgroundColor: 'rgba(59, 130, 246, 0.7)',
                                borderColor: 'rgba(59, 130, 246, 1)',
                                borderWidth: 1
                            },
                            {
                                label: 'Female',
                                data: <?php echo json_encode($female_data); ?>,
                                backgroundColor: 'rgba(236, 72, 153, 0.7)',
                                borderColor: 'rgba(236, 72, 153, 1)',
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const dataset = context.dataset;
                                        const label = dataset.label || '';
                                        const value = context.raw || 0;
                                        const total = dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100) || 0;
                                        return `${label}: ${value} (${percentage}%)`;
                                    }
                                }
                            }
                        },
                        scales: {
                            x: {
                                stacked: false,
                                grid: {
                                    display: false
                                }
                            },
                            y: {
                                stacked: false,
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1
                                }
                            }
                        }
                    }
                });
            }

            // Export button
            const exportBtn = document.getElementById('exportBtn');
            if (exportBtn) {
                exportBtn.addEventListener('click', function() {
                    // In a real implementation, this would trigger a server-side export to Excel
                    alert('Export to Excel functionality would be implemented here.');
                });
            }
        });
    </script>
</body>
</html>
