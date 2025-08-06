<?php
/**
 * DCC Station Tracks API
 * Manages tracks within stations and their accessibility from neighboring stations
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

// Helper function to validate track data
function validateTrackData($data, $isUpdate = false) {
    $errors = [];
    
    if (!$isUpdate && (empty($data['station_id']) || strlen(trim($data['station_id'])) > 8)) {
        $errors[] = "Station ID must be maximum 8 characters long";
    }
    
    if (empty($data['track_number']) || strlen(trim($data['track_number'])) > 8) {
        $errors[] = "Track number is required and must be 8 characters or less";
    }
    
    if (empty($data['track_name']) || strlen(trim($data['track_name'])) < 2) {
        $errors[] = "Track name must be at least 2 characters long";
    }
    
    $validTrackTypes = ['platform', 'through', 'siding', 'freight', 'maintenance', 'storage'];
    if (isset($data['track_type']) && !in_array($data['track_type'], $validTrackTypes)) {
        $errors[] = "Track type must be one of: " . implode(', ', $validTrackTypes);
    }
    
    if (isset($data['max_length_meters']) && (!is_numeric($data['max_length_meters']) || $data['max_length_meters'] <= 0)) {
        $errors[] = "Track length must be a positive number";
    }
    
    if (isset($data['max_train_length_meters']) && (!is_numeric($data['max_train_length_meters']) || $data['max_train_length_meters'] <= 0)) {
        $errors[] = "Max train length must be a positive number";
    }
    
    if (isset($data['platform_height_mm']) && (!is_numeric($data['platform_height_mm']) || $data['platform_height_mm'] <= 0)) {
        $errors[] = "Platform height must be a positive number";
    }
    
    return $errors;
}

// Helper function to validate accessibility data
function validateAccessibilityData($data) {
    $errors = [];
    
    if (empty($data['station_id']) || strlen(trim($data['station_id'])) > 8) {
        $errors[] = "Station ID must be maximum 8 characters long";
    }
    
    if (empty($data['track_id']) || !is_numeric($data['track_id'])) {
        $errors[] = "Track ID is required and must be numeric";
    }
    
    if (empty($data['from_station_id']) || strlen(trim($data['from_station_id'])) > 8) {
        $errors[] = "From station ID must be maximum 8 characters long";
    }
    
    if (isset($data['speed_limit_kmh']) && (!is_numeric($data['speed_limit_kmh']) || $data['speed_limit_kmh'] <= 0 || $data['speed_limit_kmh'] > 200)) {
        $errors[] = "Speed limit must be between 1 and 200 km/h";
    }
    
    return $errors;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $pathParts = explode('/', trim($path, '/'));
    
    // Determine what we're working with
    $action = $_GET['action'] ?? 'tracks';
    $trackId = $_GET['track_id'] ?? null;
    $stationId = $_GET['station_id'] ?? null;
    
    switch ($method) {
        case 'GET':
            // Allow guest access for viewing
            $user = getCurrentUser($conn);
            
            if ($action === 'accessibility') {
                // Get track accessibility rules
                if ($trackId) {
                    // Get accessibility for specific track
                    $stmt = $conn->prepare("
                        SELECT * FROM dcc_track_routing 
                        WHERE track_id = ?
                        ORDER BY from_station_name
                    ");
                    $stmt->execute([$trackId]);
                    $accessibility = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'status' => 'success',
                        'data' => $accessibility
                    ]);
                } else {
                    // Get all accessibility rules with pagination
                    $page = max(1, intval($_GET['page'] ?? 1));
                    $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
                    $offset = ($page - 1) * $limit;
                    
                    $stmt = $conn->prepare("
                        SELECT * FROM dcc_track_routing
                        ORDER BY station_name, track_number, from_station_name
                        LIMIT $limit OFFSET $offset
                    ");
                    $stmt->execute();
                    $accessibility = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get total count
                    $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM dcc_track_accessibility");
                    $countStmt->execute();
                    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
                    
                    echo json_encode([
                        'status' => 'success',
                        'data' => $accessibility,
                        'pagination' => [
                            'total' => intval($total),
                            'page' => $page,
                            'limit' => $limit,
                            'pages' => ceil($total / $limit)
                        ]
                    ]);
                }
            } elseif ($action === 'available') {
                // Get available tracks for a specific route
                $fromStation = $_GET['from_station'] ?? null;
                $toStation = $_GET['to_station'] ?? null;
                
                if (!$fromStation || !$toStation) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Both from_station and to_station parameters are required'
                    ]);
                    exit;
                }
                
                $stmt = $conn->prepare("
                    SELECT 
                        tr.*,
                        ta.is_accessible,
                        ta.default_route,
                        ta.speed_limit_kmh as access_speed_limit,
                        ta.route_notes
                    FROM dcc_track_routing tr
                    JOIN dcc_track_accessibility ta ON tr.accessibility_id = ta.id
                    WHERE tr.station_id = ? 
                    AND tr.from_station_id = ?
                    AND tr.is_accessible = 1
                    ORDER BY ta.default_route DESC, tr.track_number
                ");
                $stmt->execute([$toStation, $fromStation]);
                $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode([
                    'status' => 'success',
                    'data' => $tracks
                ]);
            } else {
                // Get station tracks
                if ($trackId) {
                    // Get specific track with accessibility info
                    $stmt = $conn->prepare("
                        SELECT * FROM dcc_station_tracks_detailed 
                        WHERE track_id = ?
                    ");
                    $stmt->execute([$trackId]);
                    $track = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($track) {
                        echo json_encode([
                            'status' => 'success',
                            'data' => $track
                        ]);
                    } else {
                        echo json_encode([
                            'status' => 'error',
                            'error' => 'Track not found'
                        ]);
                    }
                } elseif ($stationId) {
                    // Get all tracks for a station
                    $stmt = $conn->prepare("
                        SELECT * FROM dcc_station_tracks_detailed 
                        WHERE station_id = ?
                        ORDER BY track_number
                    ");
                    $stmt->execute([$stationId]);
                    $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'status' => 'success',
                        'data' => $tracks
                    ]);
                } else {
                    // Get all tracks with pagination
                    $page = max(1, intval($_GET['page'] ?? 1));
                    $limit = min(100, max(1, intval($_GET['limit'] ?? 20)));
                    $offset = ($page - 1) * $limit;
                    $search = $_GET['search'] ?? '';
                    
                    // Build search condition
                    $searchCondition = '';
                    $searchParams = [];
                    if (!empty($search)) {
                        $searchCondition = " WHERE (station_name LIKE ? OR track_number LIKE ? OR track_name LIKE ? OR track_type LIKE ?)";
                        $searchTerm = "%$search%";
                        $searchParams = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];
                    }
                    
                    // Get total count
                    $countStmt = $conn->prepare("
                        SELECT COUNT(*) as total 
                        FROM dcc_station_tracks_detailed 
                        $searchCondition
                    ");
                    $countStmt->execute($searchParams);
                    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
                    
                    // Get tracks
                    $stmt = $conn->prepare("
                        SELECT * FROM dcc_station_tracks_detailed 
                        $searchCondition
                        ORDER BY station_name, track_number
                        LIMIT $limit OFFSET $offset
                    ");
                    $stmt->execute($searchParams);
                    $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'status' => 'success',
                        'data' => $tracks,
                        'pagination' => [
                            'total' => intval($total),
                            'page' => $page,
                            'limit' => $limit,
                            'pages' => ceil($total / $limit)
                        ]
                    ]);
                }
            }
            break;
            
        case 'POST':
            // Require authentication for creating
            $user = requireAuth($conn, 'operator');
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if ($action === 'accessibility') {
                // Create accessibility rule
                $errors = validateAccessibilityData($input);
                if (!empty($errors)) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Validation failed',
                        'details' => $errors
                    ]);
                    exit;
                }
                
                // Check if track exists
                $trackStmt = $conn->prepare("SELECT station_id FROM dcc_station_tracks WHERE id = ?");
                $trackStmt->execute([$input['track_id']]);
                $track = $trackStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$track || $track['station_id'] !== $input['station_id']) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Invalid track ID for the specified station'
                    ]);
                    exit;
                }
                
                // Check if from_station exists
                $stationStmt = $conn->prepare("SELECT COUNT(*) as count FROM dcc_stations WHERE id = ?");
                $stationStmt->execute([$input['from_station_id']]);
                if ($stationStmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'From station does not exist'
                    ]);
                    exit;
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO dcc_track_accessibility 
                    (station_id, track_id, from_station_id, is_accessible, default_route, speed_limit_kmh, route_notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $input['station_id'],
                    $input['track_id'],
                    $input['from_station_id'],
                    $input['is_accessible'] ?? true,
                    $input['default_route'] ?? false,
                    $input['speed_limit_kmh'] ?? 50,
                    $input['route_notes'] ?? ''
                ]);
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Track accessibility rule created successfully',
                    'id' => $conn->lastInsertId()
                ]);
            } else {
                // Create new track
                $errors = validateTrackData($input);
                if (!empty($errors)) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Validation failed',
                        'details' => $errors
                    ]);
                    exit;
                }
                
                // Check if station exists
                $stationStmt = $conn->prepare("SELECT COUNT(*) as count FROM dcc_stations WHERE id = ?");
                $stationStmt->execute([$input['station_id']]);
                if ($stationStmt->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Station does not exist'
                    ]);
                    exit;
                }
                
                // Check for duplicate track number
                $duplicateStmt = $conn->prepare("
                    SELECT COUNT(*) as count FROM dcc_station_tracks 
                    WHERE station_id = ? AND track_number = ?
                ");
                $duplicateStmt->execute([$input['station_id'], $input['track_number']]);
                if ($duplicateStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Track number already exists for this station'
                    ]);
                    exit;
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO dcc_station_tracks 
                    (station_id, track_number, track_name, track_type, max_length_meters, 
                     is_electrified, max_train_length_meters, platform_height_mm, is_active, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $input['station_id'],
                    $input['track_number'],
                    $input['track_name'],
                    $input['track_type'] ?? 'platform',
                    $input['max_length_meters'] ?? null,
                    $input['is_electrified'] ?? true,
                    $input['max_train_length_meters'] ?? null,
                    $input['platform_height_mm'] ?? null,
                    $input['is_active'] ?? true,
                    $input['notes'] ?? ''
                ]);
                
                $trackId = $conn->lastInsertId();
                
                // Auto-create accessibility rules for all neighboring stations if requested
                if ($input['auto_accessibility'] ?? true) {
                    $neighborStmt = $conn->prepare("
                        SELECT DISTINCT 
                            CASE 
                                WHEN from_station_id = ? THEN to_station_id 
                                ELSE from_station_id 
                            END as neighbor_id
                        FROM dcc_station_connections 
                        WHERE (from_station_id = ? OR to_station_id = ?) 
                        AND is_active = 1
                    ");
                    $neighborStmt->execute([$input['station_id'], $input['station_id'], $input['station_id']]);
                    $neighbors = $neighborStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($neighbors as $neighbor) {
                        $accessStmt = $conn->prepare("
                            INSERT IGNORE INTO dcc_track_accessibility 
                            (station_id, track_id, from_station_id, is_accessible, default_route, speed_limit_kmh, route_notes)
                            VALUES (?, ?, ?, 1, 0, 50, 'Auto-generated default accessibility')
                        ");
                        $accessStmt->execute([$input['station_id'], $trackId, $neighbor['neighbor_id']]);
                    }
                }
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Track created successfully',
                    'id' => $trackId
                ]);
            }
            break;
            
        case 'PUT':
            // Require authentication for updating
            $user = requireAuth($conn, 'operator');
            
            $input = json_decode(file_get_contents('php://input'), true);
            
            if ($action === 'accessibility') {
                // Update accessibility rule
                $accessibilityId = $_GET['accessibility_id'] ?? null;
                if (!$accessibilityId) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Accessibility ID is required'
                    ]);
                    exit;
                }
                
                $errors = validateAccessibilityData($input);
                if (!empty($errors)) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Validation failed',
                        'details' => $errors
                    ]);
                    exit;
                }
                
                $stmt = $conn->prepare("
                    UPDATE dcc_track_accessibility 
                    SET is_accessible = ?, default_route = ?, speed_limit_kmh = ?, route_notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                $result = $stmt->execute([
                    $input['is_accessible'] ?? true,
                    $input['default_route'] ?? false,
                    $input['speed_limit_kmh'] ?? 50,
                    $input['route_notes'] ?? '',
                    $accessibilityId
                ]);
                
                if ($result && $stmt->rowCount() > 0) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Track accessibility updated successfully'
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Accessibility rule not found or no changes made'
                    ]);
                }
            } else {
                // Update track
                if (!$trackId) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Track ID is required'
                    ]);
                    exit;
                }
                
                $errors = validateTrackData($input, true);
                if (!empty($errors)) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Validation failed',
                        'details' => $errors
                    ]);
                    exit;
                }
                
                // Check if track exists
                $existsStmt = $conn->prepare("SELECT station_id FROM dcc_station_tracks WHERE id = ?");
                $existsStmt->execute([$trackId]);
                $existingTrack = $existsStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$existingTrack) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Track not found'
                    ]);
                    exit;
                }
                
                // Check for duplicate track number if changing it
                if (isset($input['track_number'])) {
                    $duplicateStmt = $conn->prepare("
                        SELECT COUNT(*) as count FROM dcc_station_tracks 
                        WHERE station_id = ? AND track_number = ? AND id != ?
                    ");
                    $duplicateStmt->execute([$existingTrack['station_id'], $input['track_number'], $trackId]);
                    if ($duplicateStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                        echo json_encode([
                            'status' => 'error',
                            'error' => 'Track number already exists for this station'
                        ]);
                        exit;
                    }
                }
                
                $stmt = $conn->prepare("
                    UPDATE dcc_station_tracks 
                    SET track_number = ?, track_name = ?, track_type = ?, max_length_meters = ?,
                        is_electrified = ?, max_train_length_meters = ?, platform_height_mm = ?,
                        is_active = ?, notes = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                
                $result = $stmt->execute([
                    $input['track_number'] ?? null,
                    $input['track_name'] ?? null,
                    $input['track_type'] ?? null,
                    $input['max_length_meters'] ?? null,
                    $input['is_electrified'] ?? null,
                    $input['max_train_length_meters'] ?? null,
                    $input['platform_height_mm'] ?? null,
                    $input['is_active'] ?? null,
                    $input['notes'] ?? null,
                    $trackId
                ]);
                
                if ($result) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Track updated successfully'
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Failed to update track'
                    ]);
                }
            }
            break;
            
        case 'DELETE':
            // Require admin authentication for deletion
            $user = requireAuth($conn, 'admin');
            
            if ($action === 'accessibility') {
                // Delete accessibility rule
                $accessibilityId = $_GET['accessibility_id'] ?? null;
                if (!$accessibilityId) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Accessibility ID is required'
                    ]);
                    exit;
                }
                
                $stmt = $conn->prepare("DELETE FROM dcc_track_accessibility WHERE id = ?");
                $result = $stmt->execute([$accessibilityId]);
                
                if ($result && $stmt->rowCount() > 0) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'Track accessibility rule deleted successfully'
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Accessibility rule not found'
                    ]);
                }
            } else {
                // Delete track
                if (!$trackId) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Track ID is required'
                    ]);
                    exit;
                }
                
                // Get track info for the response
                $infoStmt = $conn->prepare("SELECT track_number, track_name FROM dcc_station_tracks WHERE id = ?");
                $infoStmt->execute([$trackId]);
                $trackInfo = $infoStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$trackInfo) {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Track not found'
                    ]);
                    exit;
                }
                
                // Delete the track (will cascade delete accessibility rules)
                $stmt = $conn->prepare("DELETE FROM dcc_station_tracks WHERE id = ?");
                $result = $stmt->execute([$trackId]);
                
                if ($result && $stmt->rowCount() > 0) {
                    echo json_encode([
                        'status' => 'success',
                        'message' => "Track '{$trackInfo['track_number']} - {$trackInfo['track_name']}' deleted successfully"
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Failed to delete track'
                    ]);
                }
            }
            break;
            
        default:
            echo json_encode([
                'status' => 'error',
                'error' => 'Method not allowed'
            ]);
            http_response_code(405);
            break;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'error' => 'Database error: ' . $e->getMessage()
    ]);
    http_response_code(500);
}
?>
