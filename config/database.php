<?php
class Database {
    private $host;
    private $username;
    private $password;
    private $db_name;
    private $port;
    public $conn;

    public function __construct() {
        // XAMPP default configuration
        $this->host = 'localhost';
        $this->username = 'root';
        $this->password = '';
        $this->port = '3306';
        
        // Allow root for local development only
        if (($this->username === 'root' && empty($this->password)) && 
            ($_SERVER['HTTP_HOST'] ?? '') === 'localhost') {
            // Allow empty root password for local development
            error_log("Running with development database credentials - NOT FOR PRODUCTION");
        }
    }

    // Get the database connection based on role
    public function getConnection($role = '') {
        $this->conn = null;

        // Set the database name based on role
        switch($role) {
            case 'student':
                $this->db_name = "student_db";
                break;
            case 'teacher':
                $this->db_name = "teacher_db";
                break;
            case 'librarian':
                $this->db_name = "librarian_db";
                break;
            case 'registrar':
                $this->db_name = "registrar_db";
                break;
            default:
                $this->db_name = "login_db";
        }

        try {
            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            
            $this->conn = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]);
            
        } catch(PDOException $exception) {
            $this->logDatabaseError($exception, $this->db_name);
            
            // More friendly error for development
            if (($_SERVER['HTTP_HOST'] ?? '') === 'localhost') {
                echo "<div style='background: #fee; border: 1px solid #f00; padding: 20px; margin: 20px;'>
                        <h3>Database Connection Error</h3>
                        <p><strong>Make sure:</strong></p>
                        <ul>
                            <li>XAMPP MySQL is running</li>
                            <li>Database '{$this->db_name}' exists</li>
                            <li>MySQL username: root (no password)</li>
                        </ul>
                        <p><small>Technical details: " . htmlspecialchars($exception->getMessage()) . "</small></p>
                      </div>";
            } else {
                echo "Service temporarily unavailable. Please try again later.";
            }
            exit();
        }

        return $this->conn;
    }

    // Get the login database connection
    public function getLoginConnection() {
        return $this->getConnection('');
    }

    private function logDatabaseError(PDOException $exception, $dbName) {
        error_log("Database error [{$dbName}]: " . $exception->getMessage());
    }
}