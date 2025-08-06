<?php
/**
 * DCC Station Connections API
 * Manages connections between stations and their distances
 * GET requests allow guest access, POST/PUT/DELETE require authentication
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';
require_once 'auth_utils.php';

// Helper function to validate connection data
function validateConnectionData($data, $isUpdate = false) {
    $errors = [];
    
    if (empty($data['from_station_id']) || strlen(trim($data['from_station_id'])) > 8) {
        $errors[] = "From station ID must be maximum 8 characters long";
    }
    
    if (empty($data['to_station_id']) || strlen(trim($data['to_station_id'])) > 8) {
        $errors[] = "To station ID must be maximum 8 characters long";
    }
    
    if ($data['from_station_id'] === $data['to_station_id']) {
        $errors[] = "From station and to station cannot be the same";
    }
    
    // Support both distance_km (new) and distance_meters (legacy)
    $distance = isset($data['distance_km']) ? $data['distance_km'] : 
                (isset($data['distance_meters']) ? $data['distance_meters'] / 1000 : null);
    

    if (!isset($distance) || !is_numeric($distance) || $distance < 0) {
        $errors[] = "Distance must be a positive number ";
    }

    
    if ($distance > 99.99999) {
        $errors[] = "Distance cannot exceed 99.99999 kilometers";
    }
    
    $validConnectionTypes = ['direct', 'junction', 'siding', 'branch'];
    if (isset($data['connection_type']) && !in_array($data['connection_type'], $validConnectionTypes)) {
        $errors[] = "Connection type must be one of: " . implode(', ', $validConnectionTypes);
    }
    
    if (isset($data['track_speed_limit']) && (!is_numeric($data['track_speed_limit']) || $data['track_speed_limit'] < 1 || $data['track_speed_limit'] > 300)) {
        $errors[] = "Track speed limit must be between 1 and 300 km/h";
    }
    
    $validConditions = ['good', 'fair', 'poor', 'maintenance'];
    if (isset($data['track_condition']) && !in_array($data['track_condition'], $validConditions)) {
        $errors[] = "Track condition must be one of: " . implode(', ', $validConditions);
    }
    
    return $errors;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $connectionId = $_GET['id'] ?? null;
    $stationId = $_GET['station_id'] ?? null;
    
    switch ($method) {
        case 'GET':
            // GET requests allow guest access for viewing
            $user = getCurrentUser($conn);
            
            if ($connectionId) {
                // Get specific connection
                $stmt = $conn->prepare("
                    SELECT c.*, 
                           fs.name as from_station_name,
                           ts.name as to_station_name
                    FROM dcc_station_connections c
                    JOIN dcc_stations fs ON c.from_station_id = fs.id
                    JOIN dcc_stations ts ON c.to_station_id = ts.id
                    WHERE c.id = ?
                ");
                $stmt->execute([$connectionId]);
                $connection = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($connection) {
                    echo json_encode([
                        'status' => 'success',
                        'data' => $connection
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Connection not found'
                    ]);
                }
            } else if ($stationId) {
                // Get all connections for a specific station
                $stmt = $conn->prepare("
                    SELECT c.*, 
                           fs.name as from_station_name,
                           ts.name as to_station_name
                    FROM dcc_station_connections c
                    JOIN dcc_stations fs ON c.from_station_id = fs.id
                    JOIN dcc_stations ts ON c.to_station_id = ts.id
                    WHERE c.from_station_id = ? OR c.to_station_id = ?
                    ORDER BY c.distance_km
                ");
                $stmt->execute([$stationId, $stationId]);
                $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'status' => 'success',
                    'data' => $connections
                ]);
            } else {
                // Get all connections with pagination
                $page = max(1, intval($_GET['page'] ?? 1));
                $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
                $offset = ($page - 1) * $limit;
                $search = $_GET['search'] ?? '';
                
                // Build search condition
                $searchCondition = '';
                $searchParams = [];
                if (!empty($search)) {
                    $searchCondition = " WHERE (fs.name LIKE ? OR ts.name LIKE ? OR c.connection_type LIKE ? OR c.notes LIKE ?)";
                    $searchTerm = "%$search%";
                    $searchParams = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
                }
                
                // Get total count
                $countStmt = $conn->prepare("
                    SELECT COUNT(*) as total 
                    FROM dcc_station_connections c
                    JOIN dcc_stations fs ON c.from_station_id = fs.id
                    JOIN dcc_stations ts ON c.to_station_id = ts.id
                    $searchCondition
                ");
                $countStmt->execute($searchParams);
                $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
                
                // Get connections with details
                $stmt = $conn->prepare("
                    SELECT c.*, 
                           fs.name as from_station_name,
                           ts.name as to_station_name
                    FROM dcc_station_connections c
                    JOIN dcc_stations fs ON c.from_station_id = fs.id
                    JOIN dcc_stations ts ON c.to_station_id = ts.id
                    $searchCondition
                    ORDER BY fs.name, ts.name
                    LIMIT $limit OFFSET $offset
                ");
                
                $stmt->execute($searchParams);
                $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'status' => 'success',
                    'data' => $connections,
                    'pagination' => [
                        'total' => intval($total),
                        'page' => $page,
                        'limit' => $limit,
                        'pages' => ceil($total / $limit)
                    ]
                ]);
            }
            break;
            
        case 'POST':
            // POST requests require operator role or higher
            $user = requireAuth($conn, 'operator');
            
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Invalid JSON data'
                ]);
                break;
            }
            
            $errors = validateConnectionData($data);
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => implode('; ', $errors)
                ]);
                break;
            }
            
            // Check if stations exist
            $stationCheckStmt = $conn->prepare("SELECT COUNT(*) as count FROM dcc_stations WHERE id IN (?, ?)");
            $stationCheckStmt->execute([$data['from_station_id'], $data['to_station_id']]);
            if ($stationCheckStmt->fetch(PDO::FETCH_ASSOC)['count'] != 2) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'One or both stations do not exist'
                ]);
                break;
            }
            
            // Check for duplicate connection
            $duplicateStmt = $conn->prepare("
                SELECT COUNT(*) as count 
                FROM dcc_station_connections 
                WHERE from_station_id = ? AND to_station_id = ?
            ");
            $duplicateStmt->execute([$data['from_station_id'], $data['to_station_id']]);
            if ($duplicateStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Connection already exists between these stations'
                ]);
                break;
            }
            
            // Insert new connection - support both distance_km and distance_meters
            $distance_km = isset($data['distance_km']) ? $data['distance_km'] : 
                          (isset($data['distance_meters']) ? $data['distance_meters'] / 1000 : 0);
            
            $stmt = $conn->prepare("
                INSERT INTO dcc_station_connections (
                    from_station_id, to_station_id, distance_km, connection_type,
                    track_speed_limit, track_condition, bidirectional, is_active, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['from_station_id'],
                $data['to_station_id'],
                $distance_km,
                $data['connection_type'] ?? 'direct',
                $data['track_speed_limit'] ?? 50,
                $data['track_condition'] ?? 'good',
                isset($data['bidirectional']) ? ($data['bidirectional'] ? 1 : 0) : 1,
                isset($data['is_active']) ? ($data['is_active'] ? 1 : 0) : 1,
                $data['notes'] ?? null
            ]);
            
            $connectionId = $conn->lastInsertId();
            
            // Return the created connection
            $stmt = $conn->prepare("
                SELECT c.*, 
                       fs.name as from_station_name,
                       ts.name as to_station_name
                FROM dcc_station_connections c
                JOIN dcc_stations fs ON c.from_station_id = fs.id
                JOIN dcc_stations ts ON c.to_station_id = ts.id
                WHERE c.id = ?
            ");
            $stmt->execute([$connectionId]);
            $connection = $stmt->fetch(PDO::FETCH_ASSOC);
            
            http_response_code(201);
            echo json_encode([
                'status' => 'success',
                'data' => $connection,
                'message' => 'Connection created successfully'
            ]);
            break;
            
        case 'PUT':
            // PUT requests require operator role or higher
            $user = requireAuth($conn, 'operator');
            
            if (!$connectionId) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Connection ID is required for updates'
                ]);
                break;
            }
            
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Invalid JSON data'
                ]);
                break;
            }
            
            // Get current connection
            $currentStmt = $conn->prepare("SELECT * FROM dcc_station_connections WHERE id = ?");
            $currentStmt->execute([$connectionId]);
            $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$current) {
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Connection not found'
                ]);
                break;
            }
            
            // Merge with current data
            $data = array_merge($current, $data);
            
            $errors = validateConnectionData($data, true);
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => implode('; ', $errors)
                ]);
                break;
            }
            
            // Update connection - support both distance_km and distance_meters
            $distance_km = isset($data['distance_km']) ? $data['distance_km'] : 
                          (isset($data['distance_meters']) ? $data['distance_meters'] / 1000 : $current['distance_km']);
            
            $stmt = $conn->prepare("
                UPDATE dcc_station_connections SET
                    distance_km = ?, connection_type = ?, track_speed_limit = ?,
                    track_condition = ?, bidirectional = ?, is_active = ?, notes = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            
            $stmt->execute([
                $distance_km,
                $data['connection_type'],
                $data['track_speed_limit'],
                $data['track_condition'],
                $data['bidirectional'] ? 1 : 0,
                $data['is_active'] ? 1 : 0,
                $data['notes'],
                $connectionId
            ]);
            
            // Return updated connection
            $stmt = $conn->prepare("
                SELECT c.*, 
                       fs.name as from_station_name,
                       ts.name as to_station_name
                FROM dcc_station_connections c
                JOIN dcc_stations fs ON c.from_station_id = fs.id
                JOIN dcc_stations ts ON c.to_station_id = ts.id
                WHERE c.id = ?
            ");
            $stmt->execute([$connectionId]);
            $connection = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'status' => 'success',
                'data' => $connection,
                'message' => 'Connection updated successfully'
            ]);
            break;
            
        case 'DELETE':
            // DELETE requests require admin role
            $user = requireAuth($conn, 'admin');
            
            if (!$connectionId) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Connection ID is required for deletion'
                ]);
                break;
            }
            
            // Check if connection exists
            $stmt = $conn->prepare("SELECT * FROM dcc_station_connections WHERE id = ?");
            $stmt->execute([$connectionId]);
            $connection = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$connection) {
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Connection not found'
                ]);
                break;
            }
            
            // Delete connection
            $stmt = $conn->prepare("DELETE FROM dcc_station_connections WHERE id = ?");
            $stmt->execute([$connectionId]);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Connection deleted successfully'
            ]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'status' => 'error',
                'error' => 'Method not allowed'
            ]);
            break;
    }

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
}
?>
