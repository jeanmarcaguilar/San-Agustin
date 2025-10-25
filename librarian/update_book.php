<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if user is logged in and is a librarian
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'librarian') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Set JSON header
header('Content-Type: application/json');

// Check if the request is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    // Include necessary files
    require_once __DIR__ . '/../config/database.php';
    
    // Create database instance
    $database = new Database();
    $librarianConn = $database->getConnection('librarian');
    
    // Get form data
    $book_id = $_POST['book_id'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $isbn = trim($_POST['isbn'] ?? '');
    $publisher = trim($_POST['publisher'] ?? '');
    $publication_year = !empty($_POST['publication_year']) ? (int)$_POST['publication_year'] : null;
    $category = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
    
    // Validate required fields
    if (empty($book_id) || empty($title) || empty($author) || empty($isbn) || $quantity < 1) {
        throw new Exception('Please fill in all required fields');
    }
    
    // Update the book in the database
    $query = "UPDATE books SET 
              title = :title,
              author = :author,
              isbn = :isbn,
              publisher = :publisher,
              publication_year = :publication_year,
              category = :category,
              description = :description,
              quantity = :quantity,
              updated_at = CURRENT_TIMESTAMP
              WHERE id = :id";
    
    $stmt = $librarianConn->prepare($query);
    
    $result = $stmt->execute([
        ':title' => $title,
        ':author' => $author,
        ':isbn' => $isbn,
        ':publisher' => !empty($publisher) ? $publisher : null,
        ':publication_year' => $publication_year,
        ':category' => !empty($category) ? $category : null,
        ':description' => !empty($description) ? $description : null,
        ':quantity' => $quantity,
        ':id' => $book_id
    ]);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Book updated successfully']);
    } else {
        throw new Exception('Failed to update book');
    }
    
} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
