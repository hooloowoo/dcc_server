<?php
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, User-Agent');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Initialize database connection using config.php
try {
    $database = new Database();
    $pdo = $database->getConnection();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Handle POST request from Arduino
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }
    
    try {
        // Clean up old records (older than 10 minutes) before inserting new data
        $cleanup_stmt = $pdo->prepare("DELETE FROM dcc_packets WHERE timestamp < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $cleanup_stmt->execute();
        
        // Extract DCC packet data
        $arduino_timestamp = $data['timestamp'] ?? null;
        $packet_type = $data['packet_type'] ?? null;
        $address = $data['address'] ?? null;
        $instruction = $data['instruction'] ?? null;
        $decoder_address = $data['decoder_address'] ?? null;
        
        // Extract specific instruction data
        $speed = null;
        $direction = null;
        $functions = null;
        $side = null;
        $state = null;
        $additional_data = [];
        
        // Parse speed and direction
        if (isset($data['speed'])) {
            $speed = $data['speed'];
        }
        if (isset($data['direction'])) {
            $direction = $data['direction'];
        }
        
        // Parse functions array
        if (isset($data['functions']) && is_array($data['functions'])) {
            $functions = implode(',', $data['functions']);
        }
        
        // Parse accessory data
        if (isset($data['side'])) {
            $side = $data['side'];
        }
        if (isset($data['state'])) {
            $state = $data['state'];
        }
        
        // Collect any additional data fields
        $ignore_fields = ['timestamp', 'packet_type', 'address', 'instruction', 'decoder_address', 
                         'speed', 'direction', 'functions', 'side', 'state'];
        foreach ($data as $key => $value) {
            if (!in_array($key, $ignore_fields)) {
                if (is_array($value)) {
                    $additional_data[$key] = implode(',', $value);
                } else {
                    $additional_data[$key] = $value;
                }
            }
        }
        
        $data_json = !empty($additional_data) ? json_encode($additional_data) : null;
        
        $stmt = $pdo->prepare("INSERT INTO dcc_packets 
            (arduino_timestamp, packet_type, address, instruction, decoder_address, 
             speed, direction, functions, side, state, data, raw_json) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        
        $stmt->execute([
            $arduino_timestamp,
            $packet_type,
            $address,
            $instruction,
            $decoder_address,
            $speed,
            $direction,
            $functions,
            $side,
            $state,
            $data_json,
            $input
        ]);
        
        echo json_encode([
            'status' => 'success',
            'message' => 'DCC packet saved successfully',
            'id' => $pdo->lastInsertId(),
            'packet_type' => $packet_type,
            'address' => $address
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save data: ' . $e->getMessage()]);
    } finally {
        $database->closeConnection();
    }
}

// Handle GET request for retrieving data
else if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
    $limit = min($limit, 1000); // Maximum 1000 records
    
    $packet_type = $_GET['packet_type'] ?? null;
    $address = $_GET['address'] ?? null;
    
    try {
        $query = "SELECT * FROM dcc_packets WHERE 1=1";
        $params = [];
        
        if ($packet_type) {
            $query .= " AND packet_type = ?";
            $params[] = $packet_type;
        }
        
        if ($address !== null) {
            $query .= " AND address = ?";
            $params[] = (int)$address;
        }
        
        $query .= " ORDER BY timestamp DESC LIMIT " . $limit;
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get statistics
        $stats_query = "SELECT 
            COUNT(*) as total_packets,
            COUNT(CASE WHEN packet_type = 'LOCOMOTIVE' THEN 1 END) as locomotive_packets,
            COUNT(CASE WHEN packet_type = 'ACCESSORY' THEN 1 END) as accessory_packets,
            COUNT(CASE WHEN packet_type = 'RESET' THEN 1 END) as reset_packets,
            COUNT(CASE WHEN packet_type = 'BROADCAST' THEN 1 END) as broadcast_packets,
            COUNT(DISTINCT address) as unique_addresses,
            MAX(timestamp) as last_packet_time
            FROM dcc_packets 
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)";
        
        $stats_stmt = $pdo->prepare($stats_query);
        $stats_stmt->execute();
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'status' => 'success',
            'data' => $results,
            'count' => count($results),
            'stats' => $stats
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to retrieve data: ' . $e->getMessage()]);
    } finally {
        $database->closeConnection();
    }
}


else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
