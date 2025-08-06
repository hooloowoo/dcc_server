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
    

    // Insert default config if table is empty
    $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM model_time_config WHERE id = 1");
    $check_stmt->execute();
    $count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($count == 0) {
        $conn->exec("
            INSERT INTO model_time_config (id, start_time, real_start_timestamp, time_multiplier, is_running) 
            VALUES (1, '06:00:00', NOW(), 1.0, TRUE)
        ");
    }
    
    $action = $_GET['action'] ?? 'get_current_time';

    switch ($action) {
        case 'get_current_time':
            handleGetCurrentTime($conn);
            break;
        case 'get_config':
            handleGetConfig($conn);
            break;
        case 'update_config':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleUpdateConfig($conn);
            break;
        case 'start_stop':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleStartStop($conn);
            break;
        case 'reset':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleReset($conn);
            break;
        default:
            outputJSON(['status' => 'error', 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    outputJSON(['status' => 'error', 'error' => $e->getMessage()]);
}

function handleGetCurrentTime($conn) {
    $stmt = $conn->prepare("SELECT * FROM model_time_config WHERE id = 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        throw new Exception('Model time configuration not found');
    }
    
    $current_model_time = calculateCurrentModelTime($config);
    
    outputJSON([
        'status' => 'success',
        'data' => [
            'current_model_time' => $current_model_time,
            'model_time_12h' => date('g:i A', strtotime($current_model_time)),
            'model_time_24h' => date('H:i', strtotime($current_model_time)),
            'start_time' => $config['start_time'],
            'time_multiplier' => (float)$config['time_multiplier'],
            'is_running' => (bool)$config['is_running'],
            'real_start_timestamp' => $config['real_start_timestamp']
        ]
    ]);
}

function handleGetConfig($conn) {
    $stmt = $conn->prepare("SELECT * FROM model_time_config WHERE id = 1");
    $stmt->execute();
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$config) {
        throw new Exception('Model time configuration not found');
    }
    
    outputJSON([
        'status' => 'success',
        'data' => [
            'start_time' => $config['start_time'],
            'time_multiplier' => (float)$config['time_multiplier'],
            'is_running' => (bool)$config['is_running'],
            'real_start_timestamp' => $config['real_start_timestamp']
        ]
    ]);
}

function handleUpdateConfig($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('No input data received');
    }
    
    $fields = [];
    $params = [];
    
    if (isset($input['start_time'])) {
        // Validate time format
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9](:[0-5][0-9])?$/', $input['start_time'])) {
            throw new Exception('Invalid time format. Use HH:MM or HH:MM:SS');
        }
        $fields[] = "start_time = ?";
        $params[] = $input['start_time'];
        
        // Reset the real start timestamp when start time changes
        $fields[] = "real_start_timestamp = NOW()";
    }
    
    if (isset($input['time_multiplier'])) {
        $multiplier = (float)$input['time_multiplier'];
        if ($multiplier <= 0 || $multiplier > 100) {
            throw new Exception('Time multiplier must be between 0.01 and 100');
        }
        $fields[] = "time_multiplier = ?";
        $params[] = $multiplier;
        
        // Reset the real start timestamp when multiplier changes
        if (!in_array("real_start_timestamp = NOW()", $fields)) {
            $fields[] = "real_start_timestamp = NOW()";
        }
    }
    
    if (isset($input['is_running'])) {
        $fields[] = "is_running = ?";
        $params[] = $input['is_running'] ? 1 : 0;
        
        // Reset the real start timestamp when starting/stopping
        if (!in_array("real_start_timestamp = NOW()", $fields)) {
            $fields[] = "real_start_timestamp = NOW()";
        }
    }
    
    if (empty($fields)) {
        throw new Exception('No fields to update');
    }
    
    $fields[] = "updated_at = NOW()";
    $params[] = 1; // WHERE id = 1
    
    $stmt = $conn->prepare("UPDATE model_time_config SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($params);
    
    outputJSON(['status' => 'success', 'message' => 'Model time configuration updated successfully']);
}

function handleStartStop($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    $is_running = isset($input['is_running']) ? (bool)$input['is_running'] : null;
    
    if ($is_running === null) {
        // Toggle current state
        $stmt = $conn->prepare("SELECT is_running FROM model_time_config WHERE id = 1");
        $stmt->execute();
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        $is_running = !$current['is_running'];
    }
    
    $stmt = $conn->prepare("
        UPDATE model_time_config 
        SET is_running = ?, real_start_timestamp = NOW(), updated_at = NOW() 
        WHERE id = 1
    ");
    $stmt->execute([$is_running ? 1 : 0]);
    
    $status = $is_running ? 'started' : 'stopped';
    outputJSON(['status' => 'success', 'message' => "Model time $status successfully"]);
}

function handleReset($conn) {
    $stmt = $conn->prepare("
        UPDATE model_time_config 
        SET start_time = '00:00:00', real_start_timestamp = NOW(), updated_at = NOW() 
        WHERE id = 1
    ");
    $stmt->execute();
    
    outputJSON(['status' => 'success', 'message' => 'Model time reset successfully']);
}

function calculateCurrentModelTime($config) {
    if (!$config['is_running']) {
        return $config['start_time'];
    }
    
    // Calculate elapsed real time in seconds
    $start_timestamp = new DateTime($config['real_start_timestamp']);
    $current_timestamp = new DateTime();
    $elapsed_real_seconds = $current_timestamp->getTimestamp() - $start_timestamp->getTimestamp();
    
    // Calculate elapsed model time (accelerated by multiplier)
    $elapsed_model_seconds = $elapsed_real_seconds * $config['time_multiplier'];
    
    // Add elapsed model time to start time
    $start_time = new DateTime($config['start_time']);
    $start_time->add(new DateInterval('PT' . round($elapsed_model_seconds) . 'S'));
    
    return $start_time->format('H:i:s');
}
?>
