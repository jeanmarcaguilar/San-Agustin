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
    'email' => '',
    'contact_number' => '',
    'position' => 'Registrar',
    'department' => 'Registrar\'s Office',
    'hire_date' => date('Y-m-d'),
    'bio' => '',
    'profile_image' => ''
];

// Initialize variables for form handling
$success_message = '';
$error_message = '';
$form_errors = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic validation
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $contact_number = trim($_POST['contact_number']);
    $position = trim($_POST['position']);
    $department = trim($_POST['department']);
    $bio = trim($_POST['bio']);
    
    // Validate required fields
    if (empty($first_name)) {
        $form_errors['first_name'] = 'First name is required';
    }
    if (empty($last_name)) {
        $form_errors['last_name'] = 'Last name is required';
    }
    if (empty($email)) {
        $form_errors['email'] = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $form_errors['email'] = 'Please enter a valid email address';
    }
    
    // If no validation errors, update the database
    if (empty($form_errors)) {
        try {
            // Start transaction
            $pdo->beginTransaction();
            
            // Update registrar information in the database
            $stmt = $pdo->prepare("UPDATE registrars SET first_name = ?, last_name = ?, email = ?, contact_number = ?, position = ?, department = ?, bio = ?, updated_at = NOW() WHERE user_id = ?");
            $stmt->execute([$first_name, $last_name, $email, $contact_number, $position, $department, $bio, $registrar_id]);
            
            // Update user email in the users table
            $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
            $stmt->execute([$email, $registrar_id]);
            
            // Handle profile image upload
            if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../uploads/profiles/';
                
                // Create uploads directory if it doesn't exist
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                $filename = 'registrar_' . $registrar_id . '_' . time() . '.' . $file_extension;
                $target_file = $upload_dir . $filename;
                
                // Check if image file is an actual image
                $check = getimagesize($_FILES['profile_image']['tmp_name']);
                if ($check !== false) {
                    // Move uploaded file to destination
                    if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_file)) {
                        // Update profile image path in the database
                        $stmt = $pdo->prepare("UPDATE registrars SET profile_image = ? WHERE user_id = ?");
                        $stmt->execute([$filename, $registrar_id]);
                        $registrar['profile_image'] = $filename;
                    } else {
                        throw new Exception("Sorry, there was an error uploading your file.");
                    }
                } else {
                    throw new Exception("File is not an image.");
                }
            }
            
            // Commit transaction
            $pdo->commit();
            
            // Update session
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            $_SESSION['email'] = $email;
            
            // Refresh registrar data
            $registrar['first_name'] = $first_name;
            $registrar['last_name'] = $last_name;
            $registrar['email'] = $email;
            $registrar['contact_number'] = $contact_number;
            $registrar['position'] = $position;
            $registrar['department'] = $department;
            $registrar['bio'] = $bio;
            
            $success_message = 'Profile updated successfully!';
            
        } catch (Exception $e) {
            // Rollback transaction on error
            $pdo->rollBack();
            error_log("Error updating profile: " . $e->getMessage());
            $error_message = 'An error occurred while updating your profile. Please try again.';
        }
    }
}

// Fetch registrar data if not already set
if (empty($registrar['email'])) {
    try {
        $stmt = $pdo->prepare("SELECT r.*, u.email FROM registrars r JOIN users u ON r.user_id = u.id WHERE r.user_id = ?");
        $stmt->execute([$registrar_id]);
        $db_registrar = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($db_registrar) {
            $registrar = array_merge($registrar, $db_registrar);
        }
    } catch (PDOException $e) {
        error_log("Error fetching registrar data: " . $e->getMessage());
    }
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

// Calculate years of service
$years_of_service = 0;
if (!empty($registrar['hire_date'])) {
    $hire_date = new DateTime($registrar['hire_date']);
    $today = new DateTime();
    $interval = $today->diff($hire_date);
    $years_of_service = $interval->y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Registrar Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        .profile-header {
            background: linear-gradient(135deg, #4299e1 0%, #3b82f6 100%);
        }
        .profile-image-upload {
            position: relative;
            display: inline-block;
        }
        .profile-image-upload input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .profile-image-upload:hover .edit-overlay {
            opacity: 1;
        }
        .edit-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background-color: rgba(0, 0, 0, 0.5);
            color: white;
            padding: 8px;
            text-align: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            border-bottom-left-radius: 0.5rem;
            border-bottom-right-radius: 0.5rem;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-white shadow">
        <div class="max-w-7xl mx-auto px-4 py-4 sm:px-6 lg:px-8 flex justify-between items-center">
            <h1 class="text-2xl font-bold text-gray-900">My Profile</h1>
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
                <a href="reports.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-chart-bar mr-2"></i>Reports
                </a>
                <a href="settings.php" class="block py-2.5 px-4 rounded transition duration-200 hover:bg-gray-700">
                    <i class="fas fa-cog mr-2"></i>Settings
                </a>
            </nav>
        </div>

        <!-- Content -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <main class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100">
                <?php if ($success_message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $success_message; ?></span>
                    <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                        <svg class="fill-current h-6 w-6 text-green-500" role="button" onclick="this.parentElement.parentElement.style.display='none'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <title>Close</title>
                            <path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/>
                        </svg>
                    </span>
                </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
                    <span class="block sm:inline"><?php echo $error_message; ?></span>
                    <span class="absolute top-0 bottom-0 right-0 px-4 py-3">
                        <svg class="fill-current h-6 w-6 text-red-500" role="button" onclick="this.parentElement.parentElement.style.display='none'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                            <title>Close</title>
                            <path d="M14.348 14.849a1.2 1.2 0 0 1-1.697 0L10 11.819l-2.651 3.029a1.2 1.2 0 1 1-1.697-1.697l2.758-3.15-2.759-3.152a1.2 1.2 0 1 1 1.697-1.697L10 8.183l2.651-3.031a1.2 1.2 0 1 1 1.697 1.697l-2.758 3.152 2.758 3.15a1.2 1.2 0 0 1 0 1.698z"/>
                        </svg>
                    </span>
                </div>
                <?php endif; ?>
                
                <!-- Profile Header -->
                <div class="profile-header py-10 px-4 sm:px-6 lg:px-8 text-white">
                    <div class="max-w-7xl mx-auto">
                        <div class="flex flex-col md:flex-row items-center">
                            <div class="profile-image-upload mr-6 mb-4 md:mb-0">
                                <?php if (!empty($registrar['profile_image'])): ?>
                                    <img src="../uploads/profiles/<?php echo htmlspecialchars($registrar['profile_image']); ?>" alt="Profile" class="h-32 w-32 rounded-full border-4 border-white shadow-lg object-cover">
                                <?php else: ?>
                                    <div class="h-32 w-32 rounded-full bg-blue-300 border-4 border-white shadow-lg flex items-center justify-center text-4xl font-bold text-white">
                                        <?php echo $initials; ?>
                                    </div>
                                <?php endif; ?>
                                <div class="edit-overlay">
                                    <i class="fas fa-camera mr-1"></i> Change Photo
                                </div>
                                <input type="file" id="profile_image" name="profile_image" accept="image/*" class="hidden" onchange="previewImage(this)">
                            </div>
                            <div class="text-center md:text-left">
                                <h1 class="text-3xl font-bold"><?php echo htmlspecialchars($registrar['first_name'] . ' ' . $registrar['last_name']); ?></h1>
                                <p class="text-blue-100 text-lg"><?php echo htmlspecialchars($registrar['position']); ?></p>
                                <p class="text-blue-100"><?php echo htmlspecialchars($registrar['department']); ?></p>
                                <div class="mt-2 flex flex-wrap justify-center md:justify-start space-x-4">
                                    <span class="flex items-center">
                                        <i class="fas fa-envelope mr-1"></i> <?php echo htmlspecialchars($registrar['email']); ?>
                                    </span>
                                    <?php if (!empty($registrar['contact_number'])): ?>
                                    <span class="flex items-center">
                                        <i class="fas fa-phone-alt mr-1"></i> <?php echo htmlspecialchars($registrar['contact_number']); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Content -->
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
                    <div class="bg-white shadow rounded-lg overflow-hidden">
                        <div class="p-6">
                            <div class="flex justify-between items-center border-b border-gray-200 pb-4 mb-6">
                                <h2 class="text-xl font-semibold text-gray-800">Profile Information</h2>
                                <button onclick="toggleEditMode()" id="edit-profile-btn" class="inline-flex items-center px-3 py-1.5 border border-transparent text-xs font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    <i class="fas fa-edit mr-1"></i> Edit Profile
                                </button>
                            </div>
                            
                            <form method="POST" action="" enctype="multipart/form-data" id="profile-form" class="space-y-6">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="first_name" class="block text-sm font-medium text-gray-700">First Name</label>
                                        <input type="text" id="first_name" name="first_name" value="<?php echo htmlspecialchars($registrar['first_name']); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" readonly>
                                        <?php if (isset($form_errors['first_name'])): ?>
                                            <p class="mt-1 text-sm text-red-600"><?php echo $form_errors['first_name']; ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
                                        <input type="text" id="last_name" name="last_name" value="<?php echo htmlspecialchars($registrar['last_name']); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" readonly>
                                        <?php if (isset($form_errors['last_name'])): ?>
                                            <p class="mt-1 text-sm text-red-600"><?php echo $form_errors['last_name']; ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($registrar['email']); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" readonly>
                                        <?php if (isset($form_errors['email'])): ?>
                                            <p class="mt-1 text-sm text-red-600"><?php echo $form_errors['email']; ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <label for="contact_number" class="block text-sm font-medium text-gray-700">Contact Number</label>
                                        <input type="tel" id="contact_number" name="contact_number" value="<?php echo htmlspecialchars($registrar['contact_number'] ?? ''); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" readonly>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="position" class="block text-sm font-medium text-gray-700">Position</label>
                                        <input type="text" id="position" name="position" value="<?php echo htmlspecialchars($registrar['position']); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" readonly>
                                    </div>
                                    <div>
                                        <label for="department" class="block text-sm font-medium text-gray-700">Department</label>
                                        <input type="text" id="department" name="department" value="<?php echo htmlspecialchars($registrar['department']); ?>" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" readonly>
                                    </div>
                                </div>
                                
                                <div>
                                    <label for="bio" class="block text-sm font-medium text-gray-700">Bio</label>
                                    <textarea id="bio" name="bio" rows="3" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" readonly><?php echo htmlspecialchars($registrar['bio'] ?? ''); ?></textarea>
                                </div>
                                
                                <div class="hidden" id="form-actions">
                                    <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        Save Changes
                                    </button>
                                    <button type="button" onclick="toggleEditMode()" class="ml-3 bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                        Cancel
                                    </button>
                                </div>
                                
                                <!-- Hidden file input for profile image -->
                                <input type="file" id="profile_image_input" name="profile_image" accept="image/*" class="hidden" onchange="document.getElementById('profile-form').submit()">
                            </form>
                        </div>
                        
                        <!-- Additional Information -->
                        <div class="border-t border-gray-200 px-6 py-4">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Additional Information</h3>
                            <dl class="grid grid-cols-1 gap-x-4 gap-y-4 sm:grid-cols-2">
                                <div class="sm:col-span-1">
                                    <dt class="text-sm font-medium text-gray-500">Employee ID</dt>
                                    <dd class="mt-1 text-sm text-gray-900"><?php echo 'REG-' . str_pad($registrar_id, 5, '0', STR_PAD_LEFT); ?></dd>
                                </div>
                                <div class="sm:col-span-1">
                                    <dt class="text-sm font-medium text-gray-500">Years of Service</dt>
                                    <dd class="mt-1 text-sm text-gray-900"><?php echo $years_of_service; ?> years</dd>
                                </div>
                                <div class="sm:col-span-1">
                                    <dt class="text-sm font-medium text-gray-500">Hire Date</dt>
                                    <dd class="mt-1 text-sm text-gray-900"><?php echo !empty($registrar['hire_date']) ? date('F j, Y', strtotime($registrar['hire_date'])) : 'N/A'; ?></dd>
                                </div>
                                <div class="sm:col-span-1">
                                    <dt class="text-sm font-medium text-gray-500">Last Login</dt>
                                    <dd class="mt-1 text-sm text-gray-900"><?php echo !empty($_SESSION['last_login']) ? date('F j, Y, g:i a', strtotime($_SESSION['last_login'])) : 'N/A'; ?></dd>
                                </div>
                            </dl>
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
        
        // Toggle edit mode
        function toggleEditMode() {
            const formInputs = document.querySelectorAll('#profile-form input:not([type="file"]), #profile-form textarea, #profile-form select');
            const formActions = document.getElementById('form-actions');
            const editButton = document.getElementById('edit-profile-btn');
            
            formInputs.forEach(input => {
                input.readOnly = !input.readOnly;
                if (input.readOnly) {
                    input.classList.remove('bg-white');
                    input.classList.add('bg-gray-100');
                } else {
                    input.classList.remove('bg-gray-100');
                    input.classList.add('bg-white');
                }
            });
            
            formActions.classList.toggle('hidden');
            
            if (editButton.innerHTML.includes('Edit Profile')) {
                editButton.innerHTML = '<i class="fas fa-times mr-1"></i> Cancel Editing';
            } else {
                editButton.innerHTML = '<i class="fas fa-edit mr-1"></i> Edit Profile';
            }
        }
        
        // Handle profile image click
        document.querySelector('.profile-image-upload').addEventListener('click', function() {
            if (!document.getElementById('profile_image_input').readOnly) {
                document.getElementById('profile_image_input').click();
            }
        });
        
        // Preview image before upload
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const imgElement = document.querySelector('.profile-image-upload img');
                    if (imgElement) {
                        imgElement.src = e.target.result;
                    } else {
                        const initialsDiv = document.querySelector('.profile-image-upload div');
                        if (initialsDiv) {
                            initialsDiv.innerHTML = `<img src="${e.target.result}" alt="Preview" class="h-32 w-32 rounded-full border-4 border-white shadow-lg object-cover">`;
                        }
                    }
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }
    </script>
</body>
</html>
