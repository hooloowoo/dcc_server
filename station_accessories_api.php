<?php
/**
 * DCC Station Accessories API
 * Manages station accessories with CRUD operations
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

// Helper function to validate accessory data
function validateAccessoryData($data, $isUpdate = false) {
    $errors = [];
    
    if (!$isUpdate && (empty($data['station_id']) || strlen(trim($data['station_id'])) !== 8)) {
        $errors[] = "Valid station ID is required";
    }
    
    if (empty($data['accessory_address']) || !is_numeric($data['accessory_address'])) {
        $errors[] = "Valid accessory address is required";
    } else {
        $address = intval($data['accessory_address']);
        if ($address < 1 || $address > 2048) {
            $errors[] = "Accessory address must be between 1 and 2048";
        }
    }
    
    if (empty($data['accessory_name']) || strlen(trim($data['accessory_name'])) < 2) {
        $errors[] = "Accessory name must be at least 2 characters long";
    }
    
    if (strlen(trim($data['accessory_name'])) > 100) {
        $errors[] = "Accessory name cannot exceed 100 characters";
    }
    
    if (!empty($data['accessory_type']) && strlen(trim($data['accessory_type'])) > 50) {
        $errors[] = "Accessory type cannot exceed 50 characters";
    }
    
    if (isset($data['description']) && strlen($data['description']) > 500) {
        $errors[] = "Description cannot exceed 500 characters";
    }
    
    // Validate coordinates
    if (isset($data['x_coordinate']) && (!is_numeric($data['x_coordinate']) || $data['x_coordinate'] < -9999 || $data['x_coordinate'] > 9999)) {
        $errors[] = "X coordinate must be a number between -9999 and 9999";
    }
    
    if (isset($data['y_coordinate']) && (!is_numeric($data['y_coordinate']) || $data['y_coordinate'] < -9999 || $data['y_coordinate'] > 9999)) {
        $errors[] = "Y coordinate must be a number between -9999 and 9999";
    }
    
    // Validate SVG content (basic check)
    if (isset($data['svg_left']) && !empty($data['svg_left']) && strlen($data['svg_left']) > 5000) {
        $errors[] = "SVG Left content cannot exceed 5000 characters";
    }
    
    if (isset($data['svg_right']) && !empty($data['svg_right']) && strlen($data['svg_right']) > 5000) {
        $errors[] = "SVG Right content cannot exceed 5000 characters";
    }
    
    return $errors;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $stationId = $_GET['station_id'] ?? null;
    $accessoryId = $_GET['id'] ?? null;
    
    switch ($method) {
        case 'GET':
            // GET requests allow guest access for viewing
            $user = getCurrentUser($conn);
            
            if ($accessoryId) {
                // Get specific accessory
                $stmt = $conn->prepare("
                    SELECT sa.*, s.name as station_name 
                    FROM dcc_station_accessories sa
                    JOIN dcc_stations s ON sa.station_id = s.id
                    WHERE sa.id = ?
                ");
                $stmt->execute([$accessoryId]);
                $accessory = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($accessory) {
                    echo json_encode([
                        'status' => 'success',
                        'data' => $accessory
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Accessory not found'
                    ]);
                }
            } else if ($stationId) {
                // Get all accessories for a specific station
                $stmt = $conn->prepare("
                    SELECT sa.*, s.name as station_name 
                    FROM dcc_station_accessories sa
                    JOIN dcc_stations s ON sa.station_id = s.id
                    WHERE sa.station_id = ?
                    ORDER BY sa.accessory_address
                ");
                $stmt->execute([$stationId]);
                $accessories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'status' => 'success',
                    'data' => $accessories
                ]);
            } else {
                // Get all accessories with station info
                $search = $_GET['search'] ?? '';
                $limit = min(intval($_GET['limit'] ?? 100), 500);
                $offset = max(intval($_GET['offset'] ?? 0), 0);
                $orderBy = $_GET['order'] ?? 'accessory_address';
                $orderDir = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
                
                // Validate order by field
                $allowedOrderFields = ['accessory_address', 'accessory_name', 'accessory_type', 'station_id', 'created_at'];
                if (!in_array($orderBy, $allowedOrderFields)) {
                    $orderBy = 'accessory_address';
                }
                
                $sql = "
                    SELECT sa.*, s.name as station_name 
                    FROM dcc_station_accessories sa
                    JOIN dcc_stations s ON sa.station_id = s.id
                ";
                $params = [];
                
                if (!empty($search)) {
                    $sql .= " WHERE sa.accessory_name LIKE ? OR sa.accessory_type LIKE ? OR s.name LIKE ?";
                    $searchParam = '%' . $search . '%';
                    $params = [$searchParam, $searchParam, $searchParam];
                }
                
                $sql .= " ORDER BY sa.$orderBy $orderDir LIMIT $limit OFFSET $offset";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $accessories = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get total count for pagination
                $countSql = "
                    SELECT COUNT(*) 
                    FROM dcc_station_accessories sa
                    JOIN dcc_stations s ON sa.station_id = s.id
                ";
                if (!empty($search)) {
                    $countSql .= " WHERE sa.accessory_name LIKE ? OR sa.accessory_type LIKE ? OR s.name LIKE ?";
                }
                $countStmt = $conn->prepare($countSql);
                $countStmt->execute(!empty($search) ? [$searchParam, $searchParam, $searchParam] : []);
                $totalCount = $countStmt->fetchColumn();
                
                echo json_encode([
                    'status' => 'success',
                    'data' => $accessories,
                    'pagination' => [
                        'total' => $totalCount,
                        'limit' => $limit,
                        'offset' => $offset,
                        'has_more' => ($offset + $limit) < $totalCount
                    ]
                ]);
            }
            break;
            
        case 'POST':
            // POST requests require at least operator role
            $user = requireAuth($conn, 'operator');
            
            // Create new station accessory
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Invalid JSON data'
                ]);
                break;
            }
            
            $errors = validateAccessoryData($input, false);
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Validation failed',
                    'details' => $errors
                ]);
                break;
            }
            
            $stationId = trim($input['station_id']);
            $accessoryAddress = intval($input['accessory_address']);
            $accessoryName = trim($input['accessory_name']);
            $accessoryType = isset($input['accessory_type']) ? trim($input['accessory_type']) : null;
            $description = isset($input['description']) ? trim($input['description']) : null;
            $xCoordinate = isset($input['x_coordinate']) ? floatval($input['x_coordinate']) : 0;
            $yCoordinate = isset($input['y_coordinate']) ? floatval($input['y_coordinate']) : 0;
            $svgLeft = isset($input['svg_left']) ? trim($input['svg_left']) : null;
            $svgRight = isset($input['svg_right']) ? trim($input['svg_right']) : null;
            
            // Check if station exists
            $stmt = $conn->prepare("SELECT COUNT(*) FROM dcc_stations WHERE id = ?");
            $stmt->execute([$stationId]);
            if ($stmt->fetchColumn() == 0) {
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Station not found'
                ]);
                break;
            }
            
            // Check if accessory address already exists for this station
            $stmt = $conn->prepare("SELECT COUNT(*) FROM dcc_station_accessories WHERE station_id = ? AND accessory_address = ?");
            $stmt->execute([$stationId, $accessoryAddress]);
            if ($stmt->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Accessory address already exists for this station'
                ]);
                break;
            }
            
            $stmt = $conn->prepare("
                INSERT INTO dcc_station_accessories (station_id, accessory_address, accessory_name, accessory_type, description, x_coordinate, y_coordinate, svg_left, svg_right) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$stationId, $accessoryAddress, $accessoryName, $accessoryType, $description, $xCoordinate, $yCoordinate, $svgLeft, $svgRight])) {
                // Fetch the created accessory
                $accessoryId = $conn->lastInsertId();
                $stmt = $conn->prepare("
                    SELECT sa.*, s.name as station_name 
                    FROM dcc_station_accessories sa
                    JOIN dcc_stations s ON sa.station_id = s.id
                    WHERE sa.id = ?
                ");
                $stmt->execute([$accessoryId]);
                $accessory = $stmt->fetch(PDO::FETCH_ASSOC);
                
                http_response_code(201);
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Accessory added successfully',
                    'data' => $accessory
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Failed to create accessory'
                ]);
            }
            break;
            
        case 'PUT':
            // PUT requests require at least operator role
            $user = requireAuth($conn, 'operator');
            
            // Update existing accessory
            if (!$accessoryId) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Accessory ID is required for updates'
                ]);
                break;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Invalid JSON data'
                ]);
                break;
            }
            
            $errors = validateAccessoryData($input, true);
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Validation failed',
                    'details' => $errors
                ]);
                break;
            }
            
            // Check if accessory exists
            $stmt = $conn->prepare("SELECT station_id, accessory_address FROM dcc_station_accessories WHERE id = ?");
            $stmt->execute([$accessoryId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing) {
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Accessory not found'
                ]);
                break;
            }
            
            $accessoryAddress = intval($input['accessory_address']);
            $accessoryName = trim($input['accessory_name']);
            $accessoryType = isset($input['accessory_type']) ? trim($input['accessory_type']) : null;
            $description = isset($input['description']) ? trim($input['description']) : null;
            $xCoordinate = isset($input['x_coordinate']) ? floatval($input['x_coordinate']) : 0;
            $yCoordinate = isset($input['y_coordinate']) ? floatval($input['y_coordinate']) : 0;
            $svgLeft = isset($input['svg_left']) ? trim($input['svg_left']) : null;
            $svgRight = isset($input['svg_right']) ? trim($input['svg_right']) : null;
            
            // Check if new address conflicts with existing accessory (excluding current one)
            if ($accessoryAddress != $existing['accessory_address']) {
                $stmt = $conn->prepare("SELECT COUNT(*) FROM dcc_station_accessories WHERE station_id = ? AND accessory_address = ? AND id != ?");
                $stmt->execute([$existing['station_id'], $accessoryAddress, $accessoryId]);
                if ($stmt->fetchColumn() > 0) {
                    http_response_code(409);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Accessory address already exists for this station'
                    ]);
                    break;
                }
            }
            
            $stmt = $conn->prepare("
                UPDATE dcc_station_accessories 
                SET accessory_address = ?, accessory_name = ?, accessory_type = ?, description = ?, x_coordinate = ?, y_coordinate = ?, svg_left = ?, svg_right = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            
            if ($stmt->execute([$accessoryAddress, $accessoryName, $accessoryType, $description, $xCoordinate, $yCoordinate, $svgLeft, $svgRight, $accessoryId])) {
                // Fetch the updated accessory
                $stmt = $conn->prepare("
                    SELECT sa.*, s.name as station_name 
                    FROM dcc_station_accessories sa
                    JOIN dcc_stations s ON sa.station_id = s.id
                    WHERE sa.id = ?
                ");
                $stmt->execute([$accessoryId]);
                $accessory = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Accessory updated successfully',
                    'data' => $accessory
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Failed to update accessory'
                ]);
            }
            break;
            
        case 'DELETE':
            // DELETE requests require admin role
            $user = requireAuth($conn, 'admin');
            
            // Delete accessory
            if (!$accessoryId) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Accessory ID is required for deletion'
                ]);
                break;
            }
            
            // Check if accessory exists
            $stmt = $conn->prepare("SELECT accessory_name FROM dcc_station_accessories WHERE id = ?");
            $stmt->execute([$accessoryId]);
            $accessory = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$accessory) {
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Accessory not found'
                ]);
                break;
            }
            
            $stmt = $conn->prepare("DELETE FROM dcc_station_accessories WHERE id = ?");
            
            if ($stmt->execute([$accessoryId])) {
                echo json_encode([
                    'status' => 'success',
                    'message' => "Accessory '{$accessory['accessory_name']}' deleted successfully"
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Failed to delete accessory'
                ]);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode([
                'status' => 'error',
                'error' => 'Method not allowed'
            ]);
            break;
    }
    
} catch (Exception $e) {
    error_log("Station Accessories API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => 'Internal server error',
        'details' => $e->getMessage()
    ]);
}
?>
