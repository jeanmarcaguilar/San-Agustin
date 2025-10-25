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
$success = null;
$teacher_classes = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_once '../config/database.php';
        $database = new Database();
        $teacher_conn = $database->getConnection('teacher');
        
        // Get teacher info
        $stmt = $teacher_conn->prepare("SELECT * FROM teachers WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception('Teacher record not found');
        }
        
        // Verify ownership
        $stmt = $teacher_conn->prepare("SELECT id FROM announcements WHERE id = ? AND teacher_id = ?");
        $stmt->execute([$announcement_id, $user['teacher_id']]);
        if (!$stmt->fetch()) {
            throw new Exception('Announcement not found or you do not have permission to edit it.');
        }
        
        // Parse target audience
        $target = $_POST['target'] ?? 'all';
        $target_audience = 'all';
        $target_class_id = null;
        $target_grade = null;
        $target_section = null;
        
        if ($target === 'students' || $target === 'all') {
            $target_audience = 'all';
        } elseif (strpos($target, 'class_') === 0) {
            $target_audience = 'specific_class';
            $target_class_id = (int)str_replace('class_', '', $target);
        } elseif (strpos($target, 'grade_') === 0) {
            $target_audience = 'specific_grade';
            $target_grade = (int)str_replace('grade_', '', $target);
        }
        
        // Update announcement
        $stmt = $teacher_conn->prepare("
            UPDATE announcements SET
                title = ?,
                content = ?,
                target_audience = ?,
                target_class_id = ?,
                target_grade = ?,
                target_section = ?,
                is_pinned = ?,
                start_date = ?,
                end_date = ?,
                status = ?,
                updated_at = NOW()
            WHERE id = ? AND teacher_id = ?
        ");
        
        $stmt->execute([
            $_POST['title'],
            $_POST['content'],
            $target_audience,
            $target_class_id,
            $target_grade,
            $target_section,
            isset($_POST['pinned']) ? 1 : 0,
            $_POST['start_date'] ?: date('Y-m-d'),
            $_POST['end_date'] ?: null,
            $_POST['status'] ?? 'published',
            $announcement_id,
            $user['teacher_id']
        ]);
        
        $success = 'Announcement updated successfully!';
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch announcement data
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
    
    // Fetch announcement
    $stmt = $teacher_conn->prepare("SELECT * FROM announcements WHERE id = ? AND teacher_id = ?");
    $stmt->execute([$announcement_id, $user['teacher_id']]);
    $announcement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$announcement) {
        throw new Exception('Announcement not found or you do not have permission to edit it.');
    }
    
    // Fetch teacher's classes
    $stmt = $teacher_conn->prepare("SELECT * FROM classes WHERE teacher_id = ? ORDER BY grade_level, section");
    $stmt->execute([$user['teacher_id']]);
    $teacher_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Announcement - Teacher Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <div class="min-h-screen p-4">
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="mb-6">
                <a href="announcements.php" class="flex items-center text-gray-600 hover:text-gray-800 mb-4">
                    <i class="fas fa-arrow-left mr-2"></i>
                    Back to Announcements
                </a>
                <h1 class="text-3xl font-bold text-gray-800">Edit Announcement</h1>
            </div>

            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 rounded-lg mb-6">
                    <p class="font-medium">Error</p>
                    <p><?php echo htmlspecialchars($error); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 rounded-lg mb-6">
                    <p class="font-medium">Success</p>
                    <p><?php echo htmlspecialchars($success); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($announcement): ?>
                <!-- Edit Form -->
                <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="space-y-6">
                        <!-- Title -->
                        <div>
                            <label for="title" class="block text-sm font-medium text-gray-700 mb-2">
                                Title <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="title" name="title" required
                                   value="<?php echo htmlspecialchars($announcement['title']); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <!-- Content -->
                        <div>
                            <label for="content" class="block text-sm font-medium text-gray-700 mb-2">
                                Content <span class="text-red-500">*</span>
                            </label>
                            <textarea id="content" name="content" rows="8" required
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"><?php echo htmlspecialchars($announcement['content']); ?></textarea>
                        </div>

                        <!-- Target Audience -->
                        <div>
                            <label for="target" class="block text-sm font-medium text-gray-700 mb-2">
                                Target Audience
                            </label>
                            <select id="target" name="target" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="all" <?php echo $announcement['target_audience'] === 'all' ? 'selected' : ''; ?>>All Users</option>
                                <option value="students" <?php echo $announcement['target_audience'] === 'all' ? 'selected' : ''; ?>>Students Only</option>
                                <?php if (!empty($teacher_classes)): ?>
                                    <optgroup label="Specific Classes">
                                        <?php foreach ($teacher_classes as $class): ?>
                                            <option value="class_<?php echo $class['id']; ?>" 
                                                    <?php echo ($announcement['target_audience'] === 'specific_class' && $announcement['target_class_id'] == $class['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($class['subject'] . ' - Grade ' . $class['grade_level'] . $class['section']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>

                        <!-- Dates -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">
                                    Start Date
                                </label>
                                <input type="date" id="start_date" name="start_date"
                                       value="<?php echo htmlspecialchars($announcement['start_date']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">
                                    End Date
                                </label>
                                <input type="date" id="end_date" name="end_date"
                                       value="<?php echo htmlspecialchars($announcement['end_date'] ?? ''); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>

                        <!-- Status and Pinned -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                                    Status
                                </label>
                                <select id="status" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <option value="draft" <?php echo $announcement['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                                    <option value="published" <?php echo $announcement['status'] === 'published' ? 'selected' : ''; ?>>Published</option>
                                    <option value="archived" <?php echo $announcement['status'] === 'archived' ? 'selected' : ''; ?>>Archived</option>
                                </select>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="pinned" name="pinned" value="1"
                                       <?php echo $announcement['is_pinned'] ? 'checked' : ''; ?>
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="pinned" class="ml-2 block text-sm text-gray-700">
                                    Pin this announcement to the top
                                </label>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex justify-end gap-3 pt-4 border-t border-gray-200">
                            <a href="announcements.php" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                                Cancel
                            </a>
                            <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                                <i class="fas fa-save mr-2"></i>Save Changes
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
