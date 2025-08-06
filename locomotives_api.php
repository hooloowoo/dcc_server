<?php
require_once 'config.php';
require_once 'auth_utils.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function outputJSON($data) {
    echo json_encode($data);
    exit(0);
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $action = $_GET['action'] ?? 'list';

    switch ($action) {
        case 'list':
            handleList($conn);
            break;
        case 'get':
            handleGet($conn);
            break;
        case 'by_address':
            handleGetByAddress($conn);
            break;
        case 'picture':
            handleGetPicture($conn);
            break;
        case 'upload_picture':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleUploadPicture($conn);
            break;
        case 'delete_picture':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleDeletePicture($conn);
            break;
        case 'create':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleCreate($conn);
            break;
        case 'update':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleUpdate($conn);
            break;
        case 'delete':
            $user = getCurrentUser($conn);
            if (!$user || $user['role'] !== 'admin') {
                outputJSON(['status' => 'error', 'error' => 'Admin authentication required']);
            }
            handleDelete($conn);
            break;
        case 'schedule':
            handleSchedule($conn);
            break;
        default:
            outputJSON(['status' => 'error', 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    outputJSON(['status' => 'error', 'error' => $e->getMessage()]);
}

function handleList($conn) {
    $page = (int)($_GET['page'] ?? 1);
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    $offset = ($page - 1) * $limit;
    
    $search = $_GET['search'] ?? '';
    $where_clause = '';
    $params = [];
    
    if (!empty($search)) {
        $where_clause = "WHERE (class LIKE ? OR number LIKE ? OR name LIKE ?) AND is_active = 1";
        $search_param = "%$search%";
        $params = [$search_param, $search_param, $search_param];
    } else {
        $where_clause = "WHERE is_active = 1";
    }
    
    // Get total count
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM dcc_locomotives $where_clause");
    $count_stmt->execute($params);
    $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get locomotives
    $query = "
        SELECT 
            id, dcc_address, class, number, name, picture_filename, picture_mimetype, picture_size,
            manufacturer, locomotive_type, sound_decoder, functions_count, function_mapping,
            CONCAT(class, ' ', number, CASE WHEN name IS NOT NULL THEN CONCAT(' \"', name, '\"') ELSE '' END) as display_name,
            CASE WHEN picture_blob IS NOT NULL THEN 1 ELSE 0 END as has_picture
        FROM dcc_locomotives 
        $where_clause
        ORDER BY dcc_address
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $locomotives = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse function mapping JSON and convert boolean fields
    foreach ($locomotives as &$loco) {
        if (!empty($loco['function_mapping'])) {
            $loco['function_mapping'] = json_decode($loco['function_mapping'], true);
        } else {
            $loco['function_mapping'] = [];
        }
        
        // Convert boolean fields to actual booleans
        $loco['sound_decoder'] = (bool)$loco['sound_decoder'];
        $loco['has_picture'] = (bool)$loco['has_picture'];
    }
    
    outputJSON([
        'status' => 'success',
        'data' => $locomotives,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function handleGet($conn) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Invalid locomotive ID');
    }
    
    $stmt = $conn->prepare("
        SELECT 
            id, dcc_address, class, number, name, picture_filename, picture_mimetype, picture_size,
            manufacturer, scale, era, country, railway_company, locomotive_type, max_speed_kmh, 
            sound_decoder, functions_count, function_mapping, notes, is_active,
            CONCAT(class, ' ', number, CASE WHEN name IS NOT NULL THEN CONCAT(' \"', name, '\"') ELSE '' END) as display_name,
            CASE WHEN picture_blob IS NOT NULL THEN 1 ELSE 0 END as has_picture
        FROM dcc_locomotives 
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    $locomotive = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$locomotive) {
        throw new Exception('Locomotive not found');
    }
    
    // Parse function mapping JSON and convert boolean fields
    if (!empty($locomotive['function_mapping'])) {
        $locomotive['function_mapping'] = json_decode($locomotive['function_mapping'], true);
    } else {
        $locomotive['function_mapping'] = [];
    }
    
    // Convert boolean fields to actual booleans
    $locomotive['sound_decoder'] = (bool)$locomotive['sound_decoder'];
    $locomotive['is_active'] = (bool)$locomotive['is_active'];
    $locomotive['has_picture'] = (bool)$locomotive['has_picture'];
    
    outputJSON(['status' => 'success', 'data' => $locomotive]);
}

function handleGetByAddress($conn) {
    $address = (int)($_GET['address'] ?? 0);
    if ($address <= 0) {
        throw new Exception('Invalid DCC address');
    }
    
    $stmt = $conn->prepare("
        SELECT 
            id, dcc_address, class, number, name, picture_filename, picture_mimetype, picture_size,
            manufacturer, locomotive_type, sound_decoder, functions_count, function_mapping,
            CONCAT(class, ' ', number, CASE WHEN name IS NOT NULL THEN CONCAT(' \"', name, '\"') ELSE '' END) as display_name,
            CASE WHEN picture_blob IS NOT NULL THEN 1 ELSE 0 END as has_picture
        FROM dcc_locomotives 
        WHERE dcc_address = ? AND is_active = 1
    ");
    $stmt->execute([$address]);
    $locomotive = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$locomotive) {
        outputJSON(['status' => 'success', 'data' => null]);
    }
    
    // Parse function mapping JSON and convert boolean fields
    if (!empty($locomotive['function_mapping'])) {
        $locomotive['function_mapping'] = json_decode($locomotive['function_mapping'], true);
    } else {
        $locomotive['function_mapping'] = [];
    }
    
    // Convert boolean fields to actual booleans
    $locomotive['sound_decoder'] = (bool)$locomotive['sound_decoder'];
    $locomotive['has_picture'] = (bool)$locomotive['has_picture'];
    
    outputJSON(['status' => 'success', 'data' => $locomotive]);
}

function handleCreate($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['dcc_address']) || empty($input['class']) || empty($input['number'])) {
        throw new Exception('DCC address, class, and number are required');
    }
    
    // Ensure number is not just whitespace
    $trimmed_number = trim($input['number']);
    if ($trimmed_number === '') {
        throw new Exception('Locomotive number cannot be empty');
    }
    
    $dcc_address = (int)$input['dcc_address'];
    if ($dcc_address < 1 || $dcc_address > 10239) {
        throw new Exception('DCC address must be between 1 and 10239');
    }
    
    // Check if address is already in use
    $check_stmt = $conn->prepare("SELECT id FROM dcc_locomotives WHERE dcc_address = ?");
    $check_stmt->execute([$dcc_address]);
    if ($check_stmt->fetch()) {
        throw new Exception('DCC address is already in use');
    }
    
    // Check if class+number combination is already in use
    $check_stmt = $conn->prepare("SELECT id FROM dcc_locomotives WHERE class = ? AND number = ?");
    $check_stmt->execute([$input['class'], trim($input['number'])]);
    if ($check_stmt->fetch()) {
        throw new Exception('A locomotive with this class and number already exists');
    }
    
    // Prepare function mapping
    $function_mapping = null;
    if (!empty($input['function_mapping']) && is_array($input['function_mapping'])) {
        $function_mapping = json_encode($input['function_mapping']);
    }
    
    $stmt = $conn->prepare("
        INSERT INTO dcc_locomotives (
            dcc_address, class, number, name, manufacturer, scale, era, country, railway_company,
            locomotive_type, max_speed_kmh, sound_decoder, functions_count, function_mapping, notes, is_active, turnaround_time_minutes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $dcc_address,
        $input['class'],
        trim($input['number']),
        $input['name'] ?? null,
        $input['manufacturer'] ?? null,
        $input['scale'] ?? null,
        $input['era'] ?? null,
        $input['country'] ?? null,
        $input['railway_company'] ?? null,
        $input['locomotive_type'] ?? 'electric',
        isset($input['max_speed_kmh']) ? (int)$input['max_speed_kmh'] : null,
        !empty($input['sound_decoder']) ? 1 : 0,
        (int)($input['functions_count'] ?? 0),
        $function_mapping,
        $input['notes'] ?? null,
        !empty($input['is_active']) ? 1 : 0,
        isset($input['turnaround_time_minutes']) ? (int)$input['turnaround_time_minutes'] : 10
    ]);
    
    $locomotive_id = $conn->lastInsertId();
    outputJSON(['status' => 'success', 'message' => 'Locomotive created successfully', 'id' => $locomotive_id]);
}

function handleUpdate($conn) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Invalid locomotive ID');
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Debug logging
    error_log("Update request for locomotive $id: " . print_r($input, true));
    
    if (!$input) {
        throw new Exception('No input data received');
    }
    
    // Check if locomotive exists
    $check_stmt = $conn->prepare("SELECT id FROM dcc_locomotives WHERE id = ?");
    $check_stmt->execute([$id]);
    if (!$check_stmt->fetch()) {
        throw new Exception('Locomotive not found');
    }
    
    // Build update fields
    $fields = [];
    $params = [];
    
    if (isset($input['dcc_address'])) {
        $dcc_address = (int)$input['dcc_address'];
        if ($dcc_address < 1 || $dcc_address > 10239) {
            throw new Exception('DCC address must be between 1 and 10239');
        }
        
        // Check if address is already in use by another locomotive
        $check_stmt = $conn->prepare("SELECT id FROM dcc_locomotives WHERE dcc_address = ? AND id != ?");
        $check_stmt->execute([$dcc_address, $id]);
        if ($check_stmt->fetch()) {
            throw new Exception('DCC address is already in use');
        }
        
        $fields[] = "dcc_address = ?";
        $params[] = $dcc_address;
    }
    if (isset($input['class'])) {
        $fields[] = "class = ?";
        $params[] = $input['class'];
    }
    if (isset($input['number'])) {
        // Validate that number is not empty or just whitespace
        if (trim($input['number']) === '') {
            throw new Exception('Locomotive number cannot be empty');
        }
        
        // Check for duplicate class+number combination (excluding current locomotive)
        $check_stmt = $conn->prepare("SELECT id FROM dcc_locomotives WHERE class = ? AND number = ? AND id != ?");
        $current_class = isset($input['class']) ? $input['class'] : null;
        
        // If class is not being updated, get the current class
        if (!$current_class) {
            $class_stmt = $conn->prepare("SELECT class FROM dcc_locomotives WHERE id = ?");
            $class_stmt->execute([$id]);
            $current_class = $class_stmt->fetch(PDO::FETCH_ASSOC)['class'];
        }
        
        $check_stmt->execute([$current_class, trim($input['number']), $id]);
        if ($check_stmt->fetch()) {
            throw new Exception('A locomotive with this class and number already exists');
        }
        
        $fields[] = "number = ?";
        $params[] = trim($input['number']);
    }
    if (isset($input['name'])) {
        $fields[] = "name = ?";
        $params[] = $input['name'];
    }
    if (isset($input['manufacturer'])) {
        $fields[] = "manufacturer = ?";
        $params[] = $input['manufacturer'];
    }
    if (isset($input['scale'])) {
        $fields[] = "scale = ?";
        $params[] = $input['scale'];
    }
    if (isset($input['era'])) {
        $fields[] = "era = ?";
        $params[] = $input['era'];
    }
    if (isset($input['country'])) {
        $fields[] = "country = ?";
        $params[] = $input['country'];
    }
    if (isset($input['railway_company'])) {
        $fields[] = "railway_company = ?";
        $params[] = $input['railway_company'];
    }
    if (isset($input['locomotive_type'])) {
        $fields[] = "locomotive_type = ?";
        $params[] = $input['locomotive_type'];
    }
    if (isset($input['max_speed_kmh'])) {
        $fields[] = "max_speed_kmh = ?";
        $params[] = (int)$input['max_speed_kmh'];
    }
    if (isset($input['sound_decoder'])) {
        $fields[] = "sound_decoder = ?";
        $params[] = !empty($input['sound_decoder']) ? 1 : 0;
    }
    if (isset($input['functions_count'])) {
        $fields[] = "functions_count = ?";
        $params[] = (int)$input['functions_count'];
    }
    if (isset($input['function_mapping'])) {
        $fields[] = "function_mapping = ?";
        $params[] = is_array($input['function_mapping']) ? json_encode($input['function_mapping']) : null;
    }
    if (isset($input['notes'])) {
        $fields[] = "notes = ?";
        $params[] = $input['notes'];
    }
    if (isset($input['is_active'])) {
        $fields[] = "is_active = ?";
        $params[] = !empty($input['is_active']) ? 1 : 0;
    }
    if (isset($input['turnaround_time_minutes'])) {
        $fields[] = "turnaround_time_minutes = ?";
        $params[] = (int)$input['turnaround_time_minutes'];
    }
    
    if (empty($fields)) {
        throw new Exception('No fields to update');
    }
    
    $params[] = $id;
    
    $stmt = $conn->prepare("UPDATE dcc_locomotives SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($params);
    
    outputJSON(['status' => 'success', 'message' => 'Locomotive updated successfully']);
}

function handleDelete($conn) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Invalid locomotive ID');
    }
    
    $stmt = $conn->prepare("DELETE FROM dcc_locomotives WHERE id = ?");
    $stmt->execute([$id]);
    
    outputJSON(['status' => 'success', 'message' => 'Locomotive deleted successfully']);
}

function handleGetPicture($conn) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(400);
        echo 'Invalid locomotive ID';
        exit;
    }
    
    $stmt = $conn->prepare("SELECT picture_blob, picture_mimetype, picture_filename FROM dcc_locomotives WHERE id = ?");
    $stmt->execute([$id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result || !$result['picture_blob']) {
        http_response_code(404);
        echo 'Picture not found';
        exit;
    }
    
    // Set appropriate headers
    header('Content-Type: ' . ($result['picture_mimetype'] ?? 'image/jpeg'));
    header('Content-Length: ' . strlen($result['picture_blob']));
    header('Content-Disposition: inline; filename="' . ($result['picture_filename'] ?? 'locomotive.jpg') . '"');
    header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
    
    echo $result['picture_blob'];
    exit;
}

function handleUploadPicture($conn) {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Invalid locomotive ID');
    }
    
    // Check if locomotive exists
    $check_stmt = $conn->prepare("SELECT id FROM dcc_locomotives WHERE id = ?");
    $check_stmt->execute([$id]);
    if (!$check_stmt->fetch()) {
        throw new Exception('Locomotive not found');
    }
    
    if (!isset($_FILES['picture']) || $_FILES['picture']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No picture uploaded or upload error');
    }
    
    $file = $_FILES['picture'];
    
    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception('Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.');
    }
    
    // Validate file size (max 5MB)
    $max_size = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $max_size) {
        throw new Exception('File too large. Maximum size is 5MB.');
    }
    
    // Read the file content
    $picture_data = file_get_contents($file['tmp_name']);
    if ($picture_data === false) {
        throw new Exception('Failed to read uploaded file');
    }
    
    // Update the database
    $stmt = $conn->prepare("
        UPDATE dcc_locomotives 
        SET picture_blob = ?, picture_filename = ?, picture_mimetype = ?, picture_size = ?
        WHERE id = ?
    ");
    
    $stmt->execute([
        $picture_data,
        $file['name'],
        $file_type,
        $file['size'],
        $id
    ]);
    
    outputJSON(['status' => 'success', 'message' => 'Picture uploaded successfully']);
}

function handleDeletePicture($conn) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        throw new Exception('Invalid locomotive ID');
    }
    
    $stmt = $conn->prepare("
        UPDATE dcc_locomotives 
        SET picture_blob = NULL, picture_filename = NULL, picture_mimetype = NULL, picture_size = NULL
        WHERE id = ?
    ");
    $stmt->execute([$id]);
    
    outputJSON(['status' => 'success', 'message' => 'Picture deleted successfully']);
}

function handleSchedule($conn) {
    $locomotiveId = (int)($_GET['id'] ?? 0);
    if ($locomotiveId <= 0) {
        throw new Exception('Invalid locomotive ID');
    }
    
    $type = $_GET['type'] ?? 'current';
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    
    try {
        if ($type === 'current') {
            // Get current and upcoming schedules
            $stmt = $conn->prepare("
                SELECT 
                    t.id as train_id,
                    t.train_number,
                    t.train_name,
                    t.consist_notes as train_description,
                    t.departure_time,
                    t.arrival_time,
                    ds.name as departure_station,
                    ars.name as arrival_station,
                    t.route as route_description,
                    'scheduled' as status,
                    DATE(t.departure_time) as departure_date,
                    DATE(t.arrival_time) as arrival_date
                FROM dcc_trains t
                JOIN dcc_train_locomotives tl ON t.id = tl.train_id
                LEFT JOIN dcc_stations ds ON t.departure_station_id = ds.id
                LEFT JOIN dcc_stations ars ON t.arrival_station_id = ars.id
                WHERE tl.locomotive_id = ? 
                    AND t.is_active = 1
                    AND (t.departure_time IS NULL OR t.departure_time >= NOW())
                ORDER BY t.departure_time ASC
                LIMIT " . intval($limit) . "
            ");
            $stmt->execute([$locomotiveId]);
        } else if ($type === 'completed') {
            // Get recently completed schedules
            $stmt = $conn->prepare("
                SELECT 
                    t.id as train_id,
                    t.train_number,
                    t.train_name,
                    t.consist_notes as train_description,
                    t.departure_time,
                    t.arrival_time,
                    ds.name as departure_station,
                    ars.name as arrival_station,
                    t.route as route_description,
                    'completed' as status,
                    DATE(t.departure_time) as departure_date,
                    DATE(t.arrival_time) as arrival_date
                FROM dcc_trains t
                JOIN dcc_train_locomotives tl ON t.id = tl.train_id
                LEFT JOIN dcc_stations ds ON t.departure_station_id = ds.id
                LEFT JOIN dcc_stations ars ON t.arrival_station_id = ars.id
                WHERE tl.locomotive_id = ? 
                    AND t.is_active = 1
                    AND t.arrival_time IS NOT NULL
                    AND t.arrival_time < NOW()
                ORDER BY t.arrival_time DESC
                LIMIT " . intval($limit) . "
            ");
            $stmt->execute([$locomotiveId]);
        } else {
            throw new Exception('Invalid schedule type');
        }
        
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format time fields for display
        foreach ($schedules as &$schedule) {
            if ($schedule['departure_time']) {
                $schedule['departure_time'] = date('H:i', strtotime($schedule['departure_time']));
            }
            if ($schedule['arrival_time']) {
                $schedule['arrival_time'] = date('H:i', strtotime($schedule['arrival_time']));
            }
        }
        
        outputJSON([
            'status' => 'success', 
            'data' => $schedules,
            'total' => count($schedules),
            'type' => $type
        ]);
        
    } catch (Exception $e) {
        outputJSON(['status' => 'error', 'error' => 'Error loading locomotive schedule: ' . $e->getMessage()]);
    }
}
?>
