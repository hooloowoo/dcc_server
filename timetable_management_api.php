<?php
/**
 * DCC Timetable Management API
 * Manages train schedules, stops, and conflict detection
 * Requires authentication for all operations
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

// Helper function to validate schedule data
function validateScheduleData($data, $isUpdate = false) {
    $errors = [];
    
    if (!$isUpdate && (empty($data['train_id']) || !is_numeric($data['train_id']))) {
        $errors[] = "Valid train ID is required";
    }
    
    if (empty($data['schedule_name']) || strlen(trim($data['schedule_name'])) < 2) {
        $errors[] = "Schedule name must be at least 2 characters long";
    }
    
    if (empty($data['effective_date']) || !strtotime($data['effective_date'])) {
        $errors[] = "Valid effective date is required";
    }
    
    if (isset($data['expiry_date']) && !empty($data['expiry_date']) && !strtotime($data['expiry_date'])) {
        $errors[] = "Expiry date must be a valid date if provided";
    }
    
    $validScheduleTypes = ['regular', 'special', 'maintenance', 'charter'];
    if (isset($data['schedule_type']) && !in_array($data['schedule_type'], $validScheduleTypes)) {
        $errors[] = "Schedule type must be one of: " . implode(', ', $validScheduleTypes);
    }
    
    $validFrequencies = ['daily', 'weekly', 'weekdays', 'weekends', 'custom'];
    if (isset($data['frequency']) && !in_array($data['frequency'], $validFrequencies)) {
        $errors[] = "Frequency must be one of: " . implode(', ', $validFrequencies);
    }
    
    return $errors;
}

// Helper function to validate stop data
function validateStopData($data, $isUpdate = false) {
    $errors = [];
    
    if (!$isUpdate && (empty($data['schedule_id']) || !is_numeric($data['schedule_id']))) {
        $errors[] = "Valid schedule ID is required";
    }
    
    if (empty($data['station_id']) || strlen(trim($data['station_id'])) > 8) {
        $errors[] = "Station ID must be maximum 8 characters long";
    }
    
    if (!isset($data['stop_sequence']) || !is_numeric($data['stop_sequence']) || $data['stop_sequence'] < 1) {
        $errors[] = "Stop sequence must be a positive number";
    }
    
    if (empty($data['arrival_time']) || !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $data['arrival_time'])) {
        $errors[] = "Valid arrival time is required (HH:MM format)";
    }
    
    if (empty($data['departure_time']) || !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $data['departure_time'])) {
        $errors[] = "Valid departure time is required (HH:MM format)";
    }
    
    // Check that departure is after arrival (or same for quick stops)
    if (!empty($data['arrival_time']) && !empty($data['departure_time'])) {
        $arrivalTime = strtotime($data['arrival_time']);
        $departureTime = strtotime($data['departure_time']);
        if ($departureTime < $arrivalTime) {
            $errors[] = "Departure time must be after or equal to arrival time";
        }
    }
    
    $validStopTypes = ['origin', 'intermediate', 'destination', 'technical'];
    if (isset($data['stop_type']) && !in_array($data['stop_type'], $validStopTypes)) {
        $errors[] = "Stop type must be one of: " . implode(', ', $validStopTypes);
    }
    
    if (isset($data['dwell_time_minutes']) && (!is_numeric($data['dwell_time_minutes']) || $data['dwell_time_minutes'] < 0 || $data['dwell_time_minutes'] > 120)) {
        $errors[] = "Dwell time must be between 0 and 120 minutes";
    }
    
    return $errors;
}

// Helper function to detect conflicts
function detectScheduleConflicts($conn, $scheduleId, $operationDate = null) {
    $conflicts = [];
    
    if (!$operationDate) {
        $operationDate = date('Y-m-d');
    }
    
    try {
        // Get all stops for this schedule
        $stmt = $conn->prepare("
            SELECT s.*, st.name as station_name, tracks.track_name
            FROM dcc_timetable_stops s
            JOIN dcc_stations st ON s.station_id = st.id
            LEFT JOIN dcc_station_tracks tracks ON s.track_id = tracks.id
            WHERE s.schedule_id = ?
            ORDER BY s.stop_sequence
        ");
        $stmt->execute([$scheduleId]);
        $stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($stops as $stop) {
            if ($stop['track_id']) {
                // Check for track conflicts
                $conflictStmt = $conn->prepare("
                    SELECT 
                        other_stops.*, 
                        other_sched.schedule_name,
                        train.train_number,
                        train.train_name
                    FROM dcc_timetable_stops other_stops
                    JOIN dcc_train_schedules other_sched ON other_stops.schedule_id = other_sched.id
                    JOIN dcc_trains train ON other_sched.train_id = train.id
                    WHERE other_stops.station_id = ?
                      AND other_stops.track_id = ?
                      AND other_stops.schedule_id != ?
                      AND other_sched.is_active = 1
                      AND (
                          (other_stops.arrival_time <= ? AND other_stops.departure_time > ?) OR
                          (other_stops.arrival_time < ? AND other_stops.departure_time >= ?)
                      )
                ");
                $conflictStmt->execute([
                    $stop['station_id'], 
                    $stop['track_id'], 
                    $scheduleId,
                    $stop['departure_time'], $stop['arrival_time'],
                    $stop['departure_time'], $stop['arrival_time']
                ]);
                $conflictingStops = $conflictStmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($conflictingStops as $conflictStop) {
                    $conflicts[] = [
                        'type' => 'track_occupancy',
                        'severity' => 'error',
                        'station_id' => $stop['station_id'],
                        'station_name' => $stop['station_name'],
                        'track_id' => $stop['track_id'],
                        'track_name' => $stop['track_name'],
                        'our_arrival' => $stop['arrival_time'],
                        'our_departure' => $stop['departure_time'],
                        'conflicting_train' => $conflictStop['train_number'] . ' (' . $conflictStop['train_name'] . ')',
                        'conflicting_schedule' => $conflictStop['schedule_name'],
                        'conflicting_arrival' => $conflictStop['arrival_time'],
                        'conflicting_departure' => $conflictStop['departure_time'],
                        'description' => "Track conflict at {$stop['station_name']} on track {$stop['track_name']} with {$conflictStop['train_number']}"
                    ];
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Conflict detection error: " . $e->getMessage());
    }
    
    return $conflicts;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $id = $_GET['id'] ?? null;
    
    switch ($method) {
        case 'GET':
            // All GET requests require at least viewer role
            $user = requireAuth($conn, 'viewer');
            
            switch ($action) {
                case 'schedules':
                    // Get all train schedules
                    $search = $_GET['search'] ?? '';
                    $train_id = $_GET['train_id'] ?? null;
                    $active_only = isset($_GET['active_only']) ? (bool)$_GET['active_only'] : true;
                    $limit = min(intval($_GET['limit'] ?? 100), 500);
                    $offset = max(intval($_GET['offset'] ?? 0), 0);
                    
                    $sql = "
                        SELECT 
                            ts.*,
                            t.train_number,
                            t.train_name,
                            COUNT(stops.id) as stop_count,
                            MIN(stops.departure_time) as first_departure,
                            MAX(stops.arrival_time) as last_arrival
                        FROM dcc_train_schedules ts
                        JOIN dcc_trains t ON ts.train_id = t.id
                        LEFT JOIN dcc_timetable_stops stops ON ts.id = stops.schedule_id
                        WHERE 1=1
                    ";
                    $params = [];
                    
                    if ($active_only) {
                        $sql .= " AND ts.is_active = 1";
                    }
                    
                    if ($train_id && is_numeric($train_id)) {
                        $sql .= " AND ts.train_id = ?";
                        $params[] = $train_id;
                    }
                    
                    if (!empty($search)) {
                        $sql .= " AND (ts.schedule_name LIKE ? OR t.train_number LIKE ? OR t.train_name LIKE ?)";
                        $searchParam = '%' . $search . '%';
                        $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
                    }
                    
                    $sql .= " GROUP BY ts.id ORDER BY ts.created_at DESC LIMIT $limit OFFSET $offset";
                    
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($params);
                    $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'status' => 'success',
                        'data' => $schedules
                    ]);
                    break;
                    
                case 'schedule':
                    // Get specific schedule with stops
                    if (!$id) {
                        throw new Exception('Schedule ID is required');
                    }
                    
                    $stmt = $conn->prepare("
                        SELECT 
                            ts.*,
                            t.train_number,
                            t.train_name
                        FROM dcc_train_schedules ts
                        JOIN dcc_trains t ON ts.train_id = t.id
                        WHERE ts.id = ?
                    ");
                    $stmt->execute([$id]);
                    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$schedule) {
                        throw new Exception('Schedule not found');
                    }
                    
                    // Get stops for this schedule
                    $stmt = $conn->prepare("
                        SELECT 
                            s.*,
                            st.name as station_name,
                            tracks.track_name
                        FROM dcc_timetable_stops s
                        JOIN dcc_stations st ON s.station_id = st.id
                        LEFT JOIN dcc_station_tracks tracks ON s.track_id = tracks.id
                        WHERE s.schedule_id = ?
                        ORDER BY s.stop_sequence
                    ");
                    $stmt->execute([$id]);
                    $stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $schedule['stops'] = $stops;
                    
                    echo json_encode([
                        'status' => 'success',
                        'data' => $schedule
                    ]);
                    break;
                    
                case 'conflicts':
                    // Get conflicts for a schedule
                    $scheduleId = $_GET['schedule_id'] ?? null;
                    $operationDate = $_GET['date'] ?? date('Y-m-d');
                    
                    if (!$scheduleId) {
                        throw new Exception('Schedule ID is required');
                    }
                    
                    $conflicts = detectScheduleConflicts($conn, $scheduleId, $operationDate);
                    
                    echo json_encode([
                        'status' => 'success',
                        'data' => $conflicts
                    ]);
                    break;
                    
                case 'station_timetable':
                    // Get timetable for a specific station
                    $stationId = $_GET['station_id'] ?? null;
                    $date = $_GET['date'] ?? date('Y-m-d');
                    
                    if (!$stationId) {
                        throw new Exception('Station ID is required');
                    }
                    
                    $stmt = $conn->prepare("
                        SELECT 
                            s.*,
                            st.name as station_name,
                            tracks.track_name,
                            ts.schedule_name,
                            t.train_number,
                            t.train_name,
                            ti.status as instance_status,
                            ti.delay_minutes
                        FROM dcc_timetable_stops s
                        JOIN dcc_stations st ON s.station_id = st.id
                        JOIN dcc_train_schedules ts ON s.schedule_id = ts.id
                        JOIN dcc_trains t ON ts.train_id = t.id
                        LEFT JOIN dcc_station_tracks tracks ON s.track_id = tracks.id
                        LEFT JOIN dcc_timetable_instances ti ON ts.id = ti.schedule_id AND ti.operation_date = ?
                        WHERE s.station_id = ? AND ts.is_active = 1
                        ORDER BY s.arrival_time
                    ");
                    $stmt->execute([$date, $stationId]);
                    $timetable = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode([
                        'status' => 'success',
                        'data' => $timetable
                    ]);
                    break;
                    
                default:
                    throw new Exception('Invalid action');
            }
            break;
            
        case 'POST':
            // POST requests require at least operator role
            $user = requireAuth($conn, 'operator');
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception('Invalid JSON data');
            }
            
            switch ($action) {
                case 'create_schedule':
                    $errors = validateScheduleData($input, false);
                    if (!empty($errors)) {
                        throw new Exception('Validation failed: ' . implode(', ', $errors));
                    }
                    
                    $stmt = $conn->prepare("
                        INSERT INTO dcc_train_schedules 
                        (train_id, schedule_name, effective_date, expiry_date, schedule_type, frequency, frequency_pattern, is_active) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $result = $stmt->execute([
                        $input['train_id'],
                        trim($input['schedule_name']),
                        $input['effective_date'],
                        $input['expiry_date'] ?? null,
                        $input['schedule_type'] ?? 'regular',
                        $input['frequency'] ?? 'daily',
                        $input['frequency_pattern'] ?? null,
                        $input['is_active'] ?? true
                    ]);
                    
                    if ($result) {
                        $scheduleId = $conn->lastInsertId();
                        
                        // Get the created schedule
                        $stmt = $conn->prepare("
                            SELECT ts.*, t.train_number, t.train_name
                            FROM dcc_train_schedules ts
                            JOIN dcc_trains t ON ts.train_id = t.id
                            WHERE ts.id = ?
                        ");
                        $stmt->execute([$scheduleId]);
                        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        echo json_encode([
                            'status' => 'success',
                            'message' => 'Schedule created successfully',
                            'data' => $schedule
                        ]);
                    } else {
                        throw new Exception('Failed to create schedule');
                    }
                    break;
                    
                case 'add_stop':
                    $errors = validateStopData($input, false);
                    if (!empty($errors)) {
                        throw new Exception('Validation failed: ' . implode(', ', $errors));
                    }
                    
                    $stmt = $conn->prepare("
                        INSERT INTO dcc_timetable_stops 
                        (schedule_id, station_id, track_id, stop_sequence, arrival_time, departure_time, 
                         stop_type, dwell_time_minutes, is_conditional, platform_assignment, notes) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $result = $stmt->execute([
                        $input['schedule_id'],
                        trim($input['station_id']),
                        $input['track_id'] ?? null,
                        $input['stop_sequence'],
                        $input['arrival_time'],
                        $input['departure_time'],
                        $input['stop_type'] ?? 'intermediate',
                        $input['dwell_time_minutes'] ?? 2,
                        $input['is_conditional'] ?? false,
                        $input['platform_assignment'] ?? null,
                        $input['notes'] ?? null
                    ]);
                    
                    if ($result) {
                        $stopId = $conn->lastInsertId();
                        
                        // Get the created stop
                        $stmt = $conn->prepare("
                            SELECT s.*, st.name as station_name, tracks.track_name
                            FROM dcc_timetable_stops s
                            JOIN dcc_stations st ON s.station_id = st.id
                            LEFT JOIN dcc_station_tracks tracks ON s.track_id = tracks.id
                            WHERE s.id = ?
                        ");
                        $stmt->execute([$stopId]);
                        $stop = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        echo json_encode([
                            'status' => 'success',
                            'message' => 'Stop added successfully',
                            'data' => $stop
                        ]);
                    } else {
                        throw new Exception('Failed to add stop');
                    }
                    break;
                    
                case 'create_complete_schedule':
                    // Create a complete schedule with train, schedule, and stops in one operation
                    try {
                        $conn->beginTransaction();
                        
                        // First, ensure train exists in timetable_trains
                        $train_check = $conn->prepare("SELECT COUNT(*) as count FROM timetable_trains WHERE train_number = ?");
                        $train_check->execute([$input['train_number']]);
                        
                        if ($train_check->fetch(PDO::FETCH_ASSOC)['count'] == 0) {
                            // Create timetable_trains entry
                            $train_stmt = $conn->prepare("
                                INSERT INTO timetable_trains (train_number, train_name, train_type)
                                VALUES (?, ?, ?)
                            ");
                            $train_stmt->execute([
                                $input['train_number'],
                                $input['train_name'] ?? $input['train_number'],
                                $input['train_type'] ?? 'passenger'
                            ]);
                        }
                        
                        // Create schedule
                        $schedule_stmt = $conn->prepare("
                            INSERT INTO timetable_schedules (
                                train_number, schedule_name, schedule_type, frequency, 
                                effective_date, route_description
                            ) VALUES (?, ?, ?, ?, ?, ?)
                        ");
                        
                        $schedule_stmt->execute([
                            $input['train_number'],
                            $input['schedule_name'] ?? ($input['train_name'] . ' - Daily Service'),
                            'regular',
                            'daily',
                            date('Y-m-d'),
                            $input['route_description'] ?? null
                        ]);
                        
                        $schedule_id = $conn->lastInsertId();
                        
                        // Create origin stop
                        $origin_stop_stmt = $conn->prepare("
                            INSERT INTO timetable_stops (
                                schedule_id, station_id, stop_sequence, arrival_time, departure_time,
                                stop_type, dwell_time_minutes
                            ) VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $origin_stop_stmt->execute([
                            $schedule_id,
                            $input['departure_station_id'],
                            1,
                            $input['departure_time'],
                            $input['departure_time'],
                            'origin',
                            2
                        ]);
                        
                        // Create destination stop
                        $dest_stop_stmt = $conn->prepare("
                            INSERT INTO timetable_stops (
                                schedule_id, station_id, stop_sequence, arrival_time, departure_time,
                                stop_type, dwell_time_minutes
                            ) VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        
                        $dest_stop_stmt->execute([
                            $schedule_id,
                            $input['arrival_station_id'],
                            2,
                            $input['arrival_time'],
                            $input['arrival_time'],
                            'destination',
                            0
                        ]);
                        
                        $conn->commit();
                        
                        echo json_encode([
                            'status' => 'success',
                            'message' => 'Complete schedule created successfully',
                            'data' => ['schedule_id' => $schedule_id]
                        ]);
                        
                    } catch (Exception $e) {
                        $conn->rollback();
                        throw $e;
                    }
                    break;
                    
                default:
                    throw new Exception('Invalid action');
            }
            break;
            
        case 'PUT':
            // PUT requests require at least operator role
            $user = requireAuth($conn, 'operator');
            
            if (!$id) {
                throw new Exception('ID is required for updates');
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception('Invalid JSON data');
            }
            
            switch ($action) {
                case 'update_schedule':
                    $errors = validateScheduleData($input, true);
                    if (!empty($errors)) {
                        throw new Exception('Validation failed: ' . implode(', ', $errors));
                    }
                    
                    $stmt = $conn->prepare("
                        UPDATE dcc_train_schedules 
                        SET schedule_name = ?, effective_date = ?, expiry_date = ?, 
                            schedule_type = ?, frequency = ?, frequency_pattern = ?, 
                            is_active = ?, updated_at = CURRENT_TIMESTAMP
                        WHERE id = ?
                    ");
                    
                    $result = $stmt->execute([
                        trim($input['schedule_name']),
                        $input['effective_date'],
                        $input['expiry_date'] ?? null,
                        $input['schedule_type'] ?? 'regular',
                        $input['frequency'] ?? 'daily',
                        $input['frequency_pattern'] ?? null,
                        $input['is_active'] ?? true,
                        $id
                    ]);
                    
                    if ($result) {
                        echo json_encode([
                            'status' => 'success',
                            'message' => 'Schedule updated successfully'
                        ]);
                    } else {
                        throw new Exception('Failed to update schedule');
                    }
                    break;
                    
                default:
                    throw new Exception('Invalid action');
            }
            break;
            
        case 'DELETE':
            // DELETE requests require admin role
            $user = requireAuth($conn, 'admin');
            
            if (!$id) {
                throw new Exception('ID is required for deletion');
            }
            
            switch ($action) {
                case 'schedule':
                    $stmt = $conn->prepare("DELETE FROM dcc_train_schedules WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        echo json_encode([
                            'status' => 'success',
                            'message' => 'Schedule deleted successfully'
                        ]);
                    } else {
                        throw new Exception('Failed to delete schedule');
                    }
                    break;
                    
                case 'stop':
                    $stmt = $conn->prepare("DELETE FROM dcc_timetable_stops WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        echo json_encode([
                            'status' => 'success',
                            'message' => 'Stop deleted successfully'
                        ]);
                    } else {
                        throw new Exception('Failed to delete stop');
                    }
                    break;
                    
                default:
                    throw new Exception('Invalid action');
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
    error_log("Timetable API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage()
    ]);
}
?>
