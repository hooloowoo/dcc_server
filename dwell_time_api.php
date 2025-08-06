<?php
header('Content-Type: application/json');

require_once 'db_config.php';

// CORS headers for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$conn = getDatabaseConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            switch ($action) {
                case 'get_defaults':
                    echo json_encode(getDefaultDwellTimes($conn));
                    break;
                case 'get_overrides':
                    echo json_encode(getStationOverrides($conn));
                    break;
                case 'preview_changes':
                    echo json_encode(previewScheduleChanges($conn));
                    break;
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'POST':
            $input = json_decode(file_get_contents('php://input'), true);
            $action = $input['action'] ?? '';
            
            switch ($action) {
                case 'save_defaults':
                    echo json_encode(saveDefaultDwellTimes($conn, $input['defaults']));
                    break;
                case 'save_overrides':
                    echo json_encode(saveStationOverrides($conn, $input['overrides']));
                    break;
                case 'update_all_schedules':
                    echo json_encode(updateAllSchedules($conn));
                    break;
                default:
                    echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function getDefaultDwellTimes($conn) {
    try {
        // Check if configuration table exists, create if not
        createConfigTableIfNotExists($conn);
        
        $stmt = $conn->prepare("
            SELECT config_key, config_value 
            FROM dcc_system_config 
            WHERE config_key LIKE 'dwell_time_default_%'
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $defaults = [
            'intermediate' => 2,
            'technical' => 1,
            'origin' => 0,
            'destination' => 0
        ];
        
        foreach ($results as $row) {
            $key = str_replace('dwell_time_default_', '', $row['config_key']);
            $defaults[$key] = (int)$row['config_value'];
        }
        
        return ['success' => true, 'defaults' => $defaults];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function saveDefaultDwellTimes($conn, $defaults) {
    try {
        createConfigTableIfNotExists($conn);
        
        $conn->beginTransaction();
        
        // Delete existing defaults
        $stmt = $conn->prepare("DELETE FROM dcc_system_config WHERE config_key LIKE 'dwell_time_default_%'");
        $stmt->execute();
        
        // Insert new defaults
        $stmt = $conn->prepare("INSERT INTO dcc_system_config (config_key, config_value) VALUES (?, ?)");
        
        foreach ($defaults as $type => $value) {
            $stmt->execute(["dwell_time_default_$type", $value]);
        }
        
        $conn->commit();
        
        return ['success' => true, 'message' => 'Default dwell times saved successfully'];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function getStationOverrides($conn) {
    try {
        createConfigTableIfNotExists($conn);
        
        $stmt = $conn->prepare("
            SELECT config_key, config_value 
            FROM dcc_system_config 
            WHERE config_key LIKE 'dwell_time_station_%'
        ");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $overrides = [];
        
        foreach ($results as $row) {
            // Parse config_key like 'dwell_time_station_ABC_intermediate'
            $parts = explode('_', $row['config_key']);
            if (count($parts) >= 5) {
                $stationId = $parts[3];
                $stopType = $parts[4];
                
                if (!isset($overrides[$stationId])) {
                    $overrides[$stationId] = [];
                }
                
                $overrides[$stationId][$stopType] = (int)$row['config_value'];
            }
        }
        
        return ['success' => true, 'overrides' => $overrides];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function saveStationOverrides($conn, $overrides) {
    try {
        createConfigTableIfNotExists($conn);
        
        $conn->beginTransaction();
        
        // Delete existing station overrides
        $stmt = $conn->prepare("DELETE FROM dcc_system_config WHERE config_key LIKE 'dwell_time_station_%'");
        $stmt->execute();
        
        // Insert new overrides
        $stmt = $conn->prepare("INSERT INTO dcc_system_config (config_key, config_value) VALUES (?, ?)");
        
        foreach ($overrides as $stationId => $types) {
            foreach ($types as $type => $value) {
                $configKey = "dwell_time_station_{$stationId}_{$type}";
                $stmt->execute([$configKey, $value]);
            }
        }
        
        $conn->commit();
        
        return ['success' => true, 'message' => 'Station overrides saved successfully'];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function previewScheduleChanges($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT DISTINCT 
                ts.id as schedule_id,
                t.train_number,
                t.train_name,
                COUNT(stops.id) as stop_count
            FROM dcc_train_schedules ts
            JOIN dcc_trains t ON ts.train_id = t.id
            LEFT JOIN dcc_timetable_stops stops ON ts.id = stops.schedule_id
            WHERE ts.is_active = 1
            GROUP BY ts.id, t.train_number, t.train_name
            ORDER BY t.train_number
        ");
        $stmt->execute();
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return ['success' => true, 'schedules' => $schedules];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function updateAllSchedules($conn) {
    try {
        $defaults = getDefaultDwellTimes($conn);
        if (!$defaults['success']) {
            throw new Exception('Could not load default dwell times');
        }
        
        $overrides = getStationOverrides($conn);
        if (!$overrides['success']) {
            throw new Exception('Could not load station overrides');
        }
        
        $defaultValues = $defaults['defaults'];
        $stationOverrides = $overrides['overrides'];
        
        $conn->beginTransaction();
        
        // Get all timetable stops that need updating
        $stmt = $conn->prepare("
            SELECT id, station_id, stop_type
            FROM dcc_timetable_stops
            WHERE schedule_id IN (
                SELECT id FROM dcc_train_schedules WHERE is_active = 1
            )
        ");
        $stmt->execute();
        $stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $updateStmt = $conn->prepare("
            UPDATE dcc_timetable_stops 
            SET dwell_time_minutes = ? 
            WHERE id = ?
        ");
        
        $updatedCount = 0;
        
        foreach ($stops as $stop) {
            $stationId = $stop['station_id'];
            $stopType = $stop['stop_type'];
            
            // Determine the appropriate dwell time
            $dwellTime = 0;
            
            // Check for station-specific override first
            if (isset($stationOverrides[$stationId][$stopType])) {
                $dwellTime = $stationOverrides[$stationId][$stopType];
            } else {
                // Use default based on stop type
                switch ($stopType) {
                    case 'intermediate':
                        $dwellTime = $defaultValues['intermediate'];
                        break;
                    case 'technical':
                        $dwellTime = $defaultValues['technical'];
                        break;
                    case 'origin':
                        $dwellTime = $defaultValues['origin'];
                        break;
                    case 'destination':
                        $dwellTime = $defaultValues['destination'];
                        break;
                }
            }
            
            $updateStmt->execute([$dwellTime, $stop['id']]);
            $updatedCount++;
        }
        
        $conn->commit();
        
        return [
            'success' => true, 
            'message' => 'All schedules updated successfully',
            'updated_count' => $updatedCount
        ];
    } catch (Exception $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function createConfigTableIfNotExists($conn) {
    $stmt = $conn->prepare("
        CREATE TABLE IF NOT EXISTS dcc_system_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            config_key VARCHAR(100) NOT NULL UNIQUE,
            config_value TEXT,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            
            INDEX idx_config_key (config_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $stmt->execute();
}

// Helper function to get dwell time for a station and stop type
function getDwellTime($conn, $stationId, $stopType) {
    static $defaults = null;
    static $overrides = null;
    
    // Load configuration once
    if ($defaults === null) {
        $result = getDefaultDwellTimes($conn);
        $defaults = $result['success'] ? $result['defaults'] : [
            'intermediate' => 2, 'technical' => 1, 'origin' => 0, 'destination' => 0
        ];
    }
    
    if ($overrides === null) {
        $result = getStationOverrides($conn);
        $overrides = $result['success'] ? $result['overrides'] : [];
    }
    
    // Check for station-specific override
    if (isset($overrides[$stationId][$stopType])) {
        return $overrides[$stationId][$stopType];
    }
    
    // Return default for stop type
    return $defaults[$stopType] ?? 2;
}
?>
