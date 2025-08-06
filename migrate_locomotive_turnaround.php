<?php
require_once 'config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "Connected to database successfully.\n";
    
    // Add turnaround_time_minutes column if it doesn't exist
    $sql = "ALTER TABLE dcc_locomotives ADD COLUMN IF NOT EXISTS turnaround_time_minutes INT DEFAULT 10 COMMENT 'Minimum time in minutes required between consecutive train assignments'";
    
    $pdo->exec($sql);
    echo "Added turnaround_time_minutes column to dcc_locomotives table.\n";
    
    // Update existing locomotives with default turnaround time
    $sql2 = "UPDATE dcc_locomotives SET turnaround_time_minutes = 10 WHERE turnaround_time_minutes IS NULL";
    $updated = $pdo->exec($sql2);
    echo "Updated $updated locomotives with default turnaround time.\n";
    
    echo "Database migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
