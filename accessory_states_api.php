<?php
/**
 * Accessory States API
 * Returns the latest state for each accessory address
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';
require_once 'auth_utils.php';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Optional authentication check (uncomment if you want to require auth)
    // $user = requireAuth($conn, 'viewer');
    
    // Get query parameters
    $address = $_GET['address'] ?? null;
    $limit = min(intval($_GET['limit'] ?? 100), 500); // Max 500 addresses
    
    $query = "
        SELECT 
            dp1.address,
            dp1.packet_type,
            dp1.data,
            dp1.instruction,
            dp1.speed,
            dp1.direction,
            dp1.side,
            dp1.state,
            dp1.timestamp,
            dp1.id
        FROM dcc_packets dp1
        WHERE dp1.packet_type = 'ACCESSORY'
        AND dp1.timestamp = (
            SELECT MAX(dp2.timestamp)
            FROM dcc_packets dp2
            WHERE dp2.address = dp1.address
            AND dp2.packet_type = 'ACCESSORY'
        )";
    
    $params = [];
    
    // Filter by specific address if provided
    if ($address !== null) {
        $query .= " AND dp1.address = ?";
        $params[] = intval($address);
    }
    
    $query .= " ORDER BY dp1.address ASC";
    
    // Add limit only if no specific address requested
    if ($address === null) {
        $query .= " LIMIT " . $limit;
    }
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process results to create a cleaner state format
    $accessoryStates = [];
    foreach ($results as $row) {
        $state = [
            'address' => intval($row['address']),
            'state' => $row['state'] ?? $row['side'] ?? $row['data'] ?? '0',
            'side' => $row['side'] ?? $row['state'] ?? $row['data'] ?? '0',
            'timestamp' => $row['timestamp'],
            'instruction' => $row['instruction'],
            'packet_id' => $row['id'],
            'data' => $row['data']
        ];
        
        // Ensure state and side are strings for consistency
        $state['state'] = strval($state['state']);
        $state['side'] = strval($state['side']);
        
        $accessoryStates[] = $state;
    }
    
    // Get statistics
    $statsQuery = "
        SELECT 
            COUNT(DISTINCT latest_per_address.address) as unique_addresses,
            MAX(dcc_packets.timestamp) as last_update,
            MIN(dcc_packets.timestamp) as first_update,
            COUNT(*) as total_latest_states
        FROM (
            SELECT address, MAX(timestamp) as timestamp
            FROM dcc_packets 
            WHERE packet_type = 'ACCESSORY'
            GROUP BY address
        ) latest_per_address
        JOIN dcc_packets ON dcc_packets.address = latest_per_address.address 
        AND dcc_packets.timestamp = latest_per_address.timestamp
        AND dcc_packets.packet_type = 'ACCESSORY'";
    
    $statsStmt = $conn->prepare($statsQuery);
    $statsStmt->execute();
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'status' => 'success',
        'data' => $accessoryStates,
        'count' => count($accessoryStates),
        'stats' => $stats,
        'query_params' => [
            'address' => $address,
            'limit' => $limit
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => 'Server error: ' . $e->getMessage()
    ]);
} finally {
    if (isset($database)) {
        $database->closeConnection();
    }
}
?>
