<?php
require_once __DIR__ . '/../config/database.php';

// Create database connection instance
$database = new Database();
// Default connection (login_db)
$conn = $database->getConnection();

// Set character set to ensure proper encoding
$conn->exec("SET NAMES 'utf8'");
?>
