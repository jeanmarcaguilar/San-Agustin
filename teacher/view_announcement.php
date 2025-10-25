<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || strtolower($_SESSION['role']) !== 'teacher') {
    $_SESSION['error'] = 'Access denied. Teacher access only.';
    header('Location: ../login.php');
    exit;
}

// Check if announcement ID is provided
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = 'Invalid announcement ID.';
    header('Location: announcements.php');
    exit;
}

$announcement_id = (int)$_GET['id'];
$announcement = null;
$error = null;

try {
    require_once '../config/database.php';
    $database = new Database();
    $teacher_conn = $database->getConnection('teacher');
    
    // Get teacher info
    $login_conn = $database->getLoginConnection();
    $stmt = $login_conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'teacher'");
    $stmt->execute([$_SESSION['user_id']]);
    $login_account = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$login_account) {
        throw new Exception('Invalid user account');
    }
    
    $stmt = $teacher_conn->prepare("SELECT * FROM teachers WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('Teacher record not found');
    }
    
    // Fetch announcement with teacher info
    $stmt = $teacher_conn->prepare("
        SELECT a.*, 
               t.first_name as teacher_first_name,
               t.last_name as teacher_last_name,
               CONCAT(t.first_name, ' ', t.last_name) as teacher_name
        FROM announcements a
        LEFT JOIN teachers t ON a.teacher_id = t.teacher_id
        WHERE a.id = ? AND a.teacher_id = ?
    ");
    $stmt->execute([$announcement_id, $user['teacher_id']]);
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$announcement) {
        throw new Exception('Announcement not found or you do not have permission to view it.');
    }
    
    // Get view count
    $stmt = $teacher_conn->prepare("SELECT COUNT(*) as view_count FROM announcement_views WHERE announcement_id = ?");
    $stmt->execute([$announcement_id]);
    $view_stats = $stmt->fetch(PDO::FETCH_ASSOC);
    $announcement['view_count'] = $view_stats['view_count'] ?? 0;
    
    // Get target info
    if ($announcement['target_audience'] === 'specific_class' && $announcement['target_class_id']) {
        $stmt = $teacher_conn->prepare("SELECT * FROM classes WHERE id = ?");
        $stmt->execute([$announcement['target_class_id']]);
        $target_class = $stmt->fetch(PDO::FETCH_ASSOC);
        $announcement['target_info'] = $target_class ? 
            $target_class['subject'] . ' - Grade ' . $target_class['grade_level'] . $target_class['section'] : 
            'Unknown Class';
    } elseif ($announcement['target_audience'] === 'specific_grade') {
        $announcement['target_info'] = 'Grade ' . $announcement['target_grade'];
    } elseif ($announcement['target_audience'] === 'specific_section') {
        $announcement['target_info'] = 'Grade ' . $announcement['target_grade'] . ' - Section ' . $announcement['target_section'];
    } else {
        $announcement['target_info'] = 'All Users';
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Announcement - Teacher Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <div class="min-h-screen p-4">
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="mb-6 flex items-center justify-between">
                <a href="announcements.php" class="flex items-center text-gray-600 hover:text-gray-800">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to Announcements
                </a>
                <?php if ($announcement): ?>
                    <a href="edit_announcement.php?id=<?php echo $announcement['id']; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        <i class="fas fa-edit mr-2"></i>Edit Announcement
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg mb-6">
                    <p class="font-medium">Error</p>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php elseif ($announcement): ?>
                <!-- Announcement Card -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                    <!-- Header -->
                    <div class="bg-gradient-to-r from-blue-500 to-blue-600 p-6 text-white">
                        <h1 class="text-2xl font-bold mb-2"><?php echo htmlspecialchars($announcement['title']); ?></h1>
                        <div class="flex flex-wrap gap-3 text-sm">
                            <span class="bg-white bg-opacity-20 px-3 py-1 rounded-full">
                                <i class="fas fa-user mr-1"></i>
                                <?php echo htmlspecialchars($announcement['teacher_name']); ?>
                            </span>
                            <span class="bg-white bg-opacity-20 px-3 py-1 rounded-full">
                                <i class="fas fa-calendar mr-1"></i>
                                <?php echo date('F j, Y', strtotime($announcement['start_date'])); ?>
                            </span>
                            <span class="bg-white bg-opacity-20 px-3 py-1 rounded-full">
                                <i class="fas fa-users mr-1"></i>
                                <?php echo htmlspecialchars($announcement['target_info']); ?>
                            </span>
                            <?php if ($announcement['is_pinned']): ?>
                                <span class="bg-yellow-400 text-yellow-900 px-3 py-1 rounded-full">
                                    <i class="fas fa-thumbtack mr-1"></i>Pinned
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Content -->
                    <div class="p-6">
                        <div class="prose max-w-none">
                            <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                        </div>

                        <!-- Metadata -->
                        <div class="mt-6 pt-6 border-t border-gray-200 grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <p class="text-sm text-gray-500">Status</p>
                                <p class="font-medium">
                                    <?php 
                                    $status_colors = [
                                        'published' => 'text-green-600',
                                        'draft' => 'text-yellow-600',
                                        'archived' => 'text-gray-600'
                                    ];
                                    $status_icons = [
                                        'published' => 'fa-check-circle',
                                        'draft' => 'fa-edit',
                                        'archived' => 'fa-archive'
                                    ];
                                    $status = $announcement['status'];
                                    ?>
                                    <span class="<?php echo $status_colors[$status] ?? 'text-gray-600'; ?>">
                                        <i class="fas <?php echo $status_icons[$status] ?? 'fa-circle'; ?> mr-1"></i>
                                        <?php echo ucfirst($status); ?>
                                    </span>
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Views</p>
                                <p class="font-medium">
                                    <i class="fas fa-eye mr-1 text-blue-600"></i>
                                    <?php echo $announcement['view_count']; ?> views
                                </p>
                            </div>
                            <div>
                                <p class="text-sm text-gray-500">Created</p>
                                <p class="font-medium">
                                    <i class="far fa-clock mr-1 text-gray-600"></i>
                                    <?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?>
                                </p>
                            </div>
                        </div>

                        <?php if ($announcement['end_date']): ?>
                            <div class="mt-4 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                                <p class="text-sm text-yellow-800">
                                    <i class="fas fa-exclamation-triangle mr-2"></i>
                                    This announcement will expire on <?php echo date('F j, Y', strtotime($announcement['end_date'])); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-between">
                        <a href="announcements.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-100">
                            <i class="fas fa-arrow-left mr-2"></i>Back
                        </a>
                        <div class="flex gap-2">
                            <a href="edit_announcement.php?id=<?php echo $announcement['id']; ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                <i class="fas fa-edit mr-2"></i>Edit
                            </a>
                            <button onclick="deleteAnnouncement(<?php echo $announcement['id']; ?>)" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                                <i class="fas fa-trash mr-2"></i>Delete
                            </button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function deleteAnnouncement(id) {
            if (confirm('Are you sure you want to delete this announcement? This action cannot be undone.')) {
                // TODO: Implement delete functionality
                alert('Delete functionality will be implemented');
            }
        }
    </script>
</body>
</html>
