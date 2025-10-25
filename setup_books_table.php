<?php
/**
 * Setup Books Table for Librarian Database
 * Run this file once to ensure the books table is properly configured
 */

require_once __DIR__ . '/config/database.php';

try {
    $database = new Database();
    $conn = $database->getConnection('librarian');
    
    echo "<h2>Setting up Books Table...</h2>";
    
    // Check if books table exists
    $checkTable = $conn->query("SHOW TABLES LIKE 'books'");
    
    if ($checkTable->rowCount() == 0) {
        echo "<p>Creating books table...</p>";
        
        // Create books table
        $createTable = "CREATE TABLE `books` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `isbn` varchar(20) NOT NULL,
            `title` varchar(255) NOT NULL,
            `author` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `publisher` varchar(100) DEFAULT NULL,
            `publication_year` year(4) DEFAULT NULL,
            `category` varchar(50) DEFAULT NULL,
            `quantity` int(11) NOT NULL DEFAULT 1,
            `available` int(11) NOT NULL DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `isbn` (`isbn`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $conn->exec($createTable);
        echo "<p style='color: green;'>✓ Books table created successfully!</p>";
    } else {
        echo "<p style='color: blue;'>Books table already exists.</p>";
        
        // Check if description column exists
        $checkDesc = $conn->query("SHOW COLUMNS FROM books LIKE 'description'");
        if ($checkDesc->rowCount() == 0) {
            echo "<p>Adding description column...</p>";
            $conn->exec("ALTER TABLE books ADD COLUMN description text DEFAULT NULL AFTER author");
            echo "<p style='color: green;'>✓ Description column added!</p>";
        }
    }
    
    // Insert sample books if table is empty
    $countBooks = $conn->query("SELECT COUNT(*) as total FROM books")->fetch(PDO::FETCH_ASSOC);
    
    if ($countBooks['total'] == 0) {
        echo "<p>Adding sample books...</p>";
        
        $sampleBooks = [
            [
                'isbn' => '978-0-439-02348-1',
                'title' => 'Harry Potter and the Philosopher\'s Stone',
                'author' => 'J.K. Rowling',
                'description' => 'The first book in the Harry Potter series',
                'publisher' => 'Bloomsbury',
                'publication_year' => 1997,
                'category' => 'Fiction',
                'quantity' => 5
            ],
            [
                'isbn' => '978-0-06-112008-4',
                'title' => 'To Kill a Mockingbird',
                'author' => 'Harper Lee',
                'description' => 'A classic of modern American literature',
                'publisher' => 'J.B. Lippincott & Co.',
                'publication_year' => 1960,
                'category' => 'Fiction',
                'quantity' => 3
            ],
            [
                'isbn' => '978-0-14-028329-5',
                'title' => '1984',
                'author' => 'George Orwell',
                'description' => 'A dystopian social science fiction novel',
                'publisher' => 'Secker & Warburg',
                'publication_year' => 1949,
                'category' => 'Science Fiction',
                'quantity' => 4
            ]
        ];
        
        $stmt = $conn->prepare("INSERT INTO books (isbn, title, author, description, publisher, publication_year, category, quantity, available) 
                               VALUES (:isbn, :title, :author, :description, :publisher, :publication_year, :category, :quantity, :quantity)");
        
        foreach ($sampleBooks as $book) {
            $stmt->execute($book);
        }
        
        echo "<p style='color: green;'>✓ Sample books added successfully!</p>";
    }
    
    // Display current books
    $books = $conn->query("SELECT * FROM books ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Current Books in Database (" . count($books) . " total):</h3>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr style='background-color: #1d98b0; color: white;'>
            <th>ID</th>
            <th>ISBN</th>
            <th>Title</th>
            <th>Author</th>
            <th>Publisher</th>
            <th>Year</th>
            <th>Category</th>
            <th>Quantity</th>
            <th>Available</th>
          </tr>";
    
    foreach ($books as $book) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($book['id']) . "</td>";
        echo "<td>" . htmlspecialchars($book['isbn']) . "</td>";
        echo "<td>" . htmlspecialchars($book['title']) . "</td>";
        echo "<td>" . htmlspecialchars($book['author']) . "</td>";
        echo "<td>" . htmlspecialchars($book['publisher'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($book['publication_year'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($book['category'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($book['quantity']) . "</td>";
        echo "<td>" . htmlspecialchars($book['available']) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    
    echo "<br><br>";
    echo "<p style='color: green; font-weight: bold; font-size: 18px;'>✓ Setup completed successfully!</p>";
    echo "<p><a href='librarian/add_book.php' style='color: #1d98b0; text-decoration: underline;'>Go to Add New Book</a> | ";
    echo "<a href='librarian/books.php' style='color: #1d98b0; text-decoration: underline;'>View Book Catalog</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<p>File: " . $e->getFile() . "</p>";
    echo "<p>Line: " . $e->getLine() . "</p>";
}
?>
