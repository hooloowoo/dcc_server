<?php
/**
 * DCC Railway Control System - Database Configuration
 * Clean configuration file without database creation logic
 */

class Database {
    private $host = 'highball.eu';
    private $db_name = 'highball_highball';
    private $username = 'highball_user';
    private $password = 'Hooloowoo123';
    private $conn;

    public function __construct($host = null, $db_name = null, $username = null, $password = null) {
        if ($host) $this->host = $host;
        if ($db_name) $this->db_name = $db_name;
        if ($username) $this->username = $username;
        if ($password) $this->password = $password;
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_general_ci"
                ]
            );
        } catch(PDOException $exception) {
            error_log("Database connection error: " . $exception->getMessage());
            throw new Exception("Database connection failed");
        }

        return $this->conn;
    }

    public function closeConnection() {
        $this->conn = null;
    }
}

// Configuration constants
define('DB_HOST', 'highball.eu');
define('DB_NAME', 'highball_highball');
define('DB_USER', 'highball_user');
define('DB_PASS', 'Hooloowoo123');

// Application Configuration
define('API_BASE_URL', 'https://highball.eu/dcc/');
define('SESSION_TIMEOUT', 3600); // 1 hour in seconds

// Security settings
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_secure', 1);

// Load Composer autoloader for external dependencies (QR code library)
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
?>
