<?php
/**
 * DCC Stations API
 * Manages station data with CRUD operations
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

// Helper function to validate station data
function validateStationData($data, $isUpdate = false) {
    $errors = [];
    
    // Validate station code (only for new stations)
    if (!$isUpdate) {
        if (empty($data['id']) || strlen(trim($data['id'])) > 8) {
            $errors[] = "Station code must be maximum 8 characters long";
        } else {
            $id = trim($data['id']);
            if (!preg_match('/^[A-Z0-9]{1,8}$/', $id)) {
                $errors[] = "Station code must contain only uppercase letters (A-Z) and numbers (0-9) and be 1-8 characters";
            }
        }
    }
    
    if (empty($data['name']) || strlen(trim($data['name'])) < 2) {
        $errors[] = "Station name must be at least 2 characters long";
    }
    
    if (strlen(trim($data['name'])) > 100) {
        $errors[] = "Station name cannot exceed 100 characters";
    }
    
    if (isset($data['description']) && strlen($data['description']) > 1000) {
        $errors[] = "Description cannot exceed 1000 characters";
    }
    
    if (isset($data['svg_icon']) && strlen($data['svg_icon']) > 2000) {
        $errors[] = "SVG icon cannot exceed 2000 characters";
    }
    
    return $errors;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    
    // Get station ID from URL if present (for PUT/DELETE operations)
    $stationId = null;
    if (isset($_GET['id'])) {
        $stationId = $_GET['id'];
    }
    
    switch ($method) {
        case 'GET':
            // GET requests allow guest access for viewing
            $user = getCurrentUser($conn);
            
            if ($stationId) {
                // Get specific station
                $stmt = $conn->prepare("
                    SELECT id, name, description, svg_icon, created_at, updated_at 
                    FROM dcc_stations 
                    WHERE id = ?
                ");
                $stmt->execute([$stationId]);
                $station = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($station) {
                    echo json_encode([
                        'status' => 'success',
                        'data' => $station
                    ]);
                } else {
                    http_response_code(404);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Station not found'
                    ]);
                }
            } else {
                // Get all stations with optional filtering
                $search = $_GET['search'] ?? '';
                $limit = min(intval($_GET['limit'] ?? 100), 500);
                $offset = max(intval($_GET['offset'] ?? 0), 0);
                $orderBy = $_GET['order'] ?? 'name';
                $orderDir = strtoupper($_GET['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
                
                // Validate order by field
                $allowedOrderFields = ['id', 'name', 'created_at', 'updated_at'];
                if (!in_array($orderBy, $allowedOrderFields)) {
                    $orderBy = 'name';
                }
                
                $sql = "SELECT id, name, description, svg_icon, created_at, updated_at FROM dcc_stations";
                $params = [];
                
                if (!empty($search)) {
                    $sql .= " WHERE name LIKE ? OR description LIKE ?";
                    $searchParam = '%' . $search . '%';
                    $params = [$searchParam, $searchParam];
                }
                
                $sql .= " ORDER BY $orderBy $orderDir LIMIT $limit OFFSET $offset";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($params);
                $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Get total count for pagination
                $countSql = "SELECT COUNT(*) FROM dcc_stations";
                if (!empty($search)) {
                    $countSql .= " WHERE name LIKE ? OR description LIKE ?";
                }
                $countStmt = $conn->prepare($countSql);
                $countStmt->execute(!empty($search) ? [$searchParam, $searchParam] : []);
                $totalCount = $countStmt->fetchColumn();
                
                echo json_encode([
                    'status' => 'success',
                    'data' => $stations,
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
            
            // Create new station
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Invalid JSON data'
                ]);
                break;
            }
            
            $errors = validateStationData($input, false);
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Validation failed',
                    'details' => $errors
                ]);
                break;
            }
            
            $id = strtoupper(trim($input['id']));
            $name = trim($input['name']);
            $description = isset($input['description']) ? trim($input['description']) : null;
            $svgIcon = isset($input['svg_icon']) ? trim($input['svg_icon']) : null;
            
            // Check if station ID already exists
            $stmt = $conn->prepare("SELECT COUNT(*) FROM dcc_stations WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Station code already exists'
                ]);
                break;
            }
            
            // Check if station name already exists
            $stmt = $conn->prepare("SELECT COUNT(*) FROM dcc_stations WHERE name = ?");
            $stmt->execute([$name]);
            if ($stmt->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Station name already exists'
                ]);
                break;
            }
            
            $stmt = $conn->prepare("
                INSERT INTO dcc_stations (id, name, description, svg_icon) 
                VALUES (?, ?, ?, ?)
            ");
            
            if ($stmt->execute([$id, $name, $description, $svgIcon])) {
                // Fetch the created station
                $stmt = $conn->prepare("
                    SELECT id, name, description, svg_icon, created_at, updated_at 
                    FROM dcc_stations 
                    WHERE id = ?
                ");
                $stmt->execute([$id]);
                $station = $stmt->fetch(PDO::FETCH_ASSOC);
                
                http_response_code(201);
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Station created successfully',
                    'data' => $station
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Failed to create station'
                ]);
            }
            break;
            
        case 'PUT':
            // PUT requests require at least operator role
            $user = requireAuth($conn, 'operator');
            
            // Update existing station
            if (!$stationId) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Station ID is required for updates'
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
            
            $errors = validateStationData($input, true);
            if (!empty($errors)) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Validation failed',
                    'details' => $errors
                ]);
                break;
            }
            
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
            
            // Check if new name conflicts with existing station (excluding current station)
            $stmt = $conn->prepare("SELECT COUNT(*) FROM dcc_stations WHERE name = ? AND id != ?");
            $stmt->execute([trim($input['name']), $stationId]);
            if ($stmt->fetchColumn() > 0) {
                http_response_code(409);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Station name already exists'
                ]);
                break;
            }
            
            $name = trim($input['name']);
            $description = isset($input['description']) ? trim($input['description']) : null;
            $svgIcon = isset($input['svg_icon']) ? trim($input['svg_icon']) : null;
            
            $stmt = $conn->prepare("
                UPDATE dcc_stations 
                SET name = ?, description = ?, svg_icon = ?, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            
            if ($stmt->execute([$name, $description, $svgIcon, $stationId])) {
                // Fetch the updated station
                $stmt = $conn->prepare("
                    SELECT id, name, description, svg_icon, created_at, updated_at 
                    FROM dcc_stations 
                    WHERE id = ?
                ");
                $stmt->execute([$stationId]);
                $station = $stmt->fetch(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Station updated successfully',
                    'data' => $station
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Failed to update station'
                ]);
            }
            break;
            
        case 'DELETE':
            // DELETE requests require admin role
            $user = requireAuth($conn, 'admin');
            
            // Delete station
            if (!$stationId) {
                http_response_code(400);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Station ID is required for deletion'
                ]);
                break;
            }
            
            // Check if station exists
            $stmt = $conn->prepare("SELECT name FROM dcc_stations WHERE id = ?");
            $stmt->execute([$stationId]);
            $station = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$station) {
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Station not found'
                ]);
                break;
            }
            
            $stmt = $conn->prepare("DELETE FROM dcc_stations WHERE id = ?");
            
            if ($stmt->execute([$stationId])) {
                echo json_encode([
                    'status' => 'success',
                    'message' => "Station '{$station['name']}' deleted successfully"
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Failed to delete station'
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
    error_log("Stations API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => 'Internal server error',
        'details' => $e->getMessage()
    ]);
}
?>
