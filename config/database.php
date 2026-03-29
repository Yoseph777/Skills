<?php
/**
 * Database Configuration File
 * PennyWise Budget & Expense Tracker
 * 
 * Configure your XAMPP MySQL database connection settings here
 */

// Database credentials - Update these according to your XAMPP setup
define('DB_HOST', 'localhost');        // Database host (usually localhost for XAMPP)
define('DB_NAME', 'pennywise_db');     // Database name
define('DB_USER', 'root');             // Database username (default XAMPP: root)
define('DB_PASS', '');                 // Database password (default XAMPP: empty)
define('DB_CHARSET', 'utf8mb4');       // Character set

// Error reporting for development (set to 0 in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * Database Connection Class
 * Singleton pattern for database connection
 */
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Log error and return generic message
            error_log("Database Connection Error: " . $e->getMessage());
            throw new Exception("Database connection failed. Please check your configuration.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Prevent cloning
    private function __clone() {}
    
    // Prevent unserialization
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

/**
 * Helper function to get database connection
 */
function getDB() {
    return Database::getInstance()->getConnection();
}
?>