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
    
    // Debug logging
    error_log("trains_api.php called with action: '$action', GET params: " . json_encode($_GET));

    switch ($action) {
        case 'list':
            handleList($conn);
            break;
        case 'get':
            handleGet($conn);
            break;
        case 'overview':
            handleOverview($conn);
            break;
        case 'calculate_schedule':
            handleCalculateSchedule($conn);
            break;
        case 'availableLocomotives':
        case 'available_locomotives':
            handleAvailableLocomotives($conn);
            break;
        case 'locomotiveStats':
            handleLocomotiveStats($conn);
            break;
        case 'test_locomotives':
            handleTestLocomotives($conn);
            break;
        case 'stations':
            handleGetStations($conn);
            break;
        case 'create':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleCreate($conn);
            break;
        case 'create_free_running':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleCreateFreeRunning($conn);
            break;
        case 'update':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleUpdate($conn);
            break;
        case 'update_consist':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleUpdateConsist($conn);
            break;
        case 'delete':
            $user = getCurrentUser($conn);
            if (!$user || $user['role'] !== 'admin') {
                outputJSON(['status' => 'error', 'error' => 'Admin authentication required']);
            }
            handleDelete($conn);
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
        $where_clause = "WHERE (t.train_number LIKE ? OR t.train_name LIKE ? OR t.route LIKE ?) AND t.is_active = 1";
        $search_param = "%$search%";
        $params = [$search_param, $search_param, $search_param];
    } else {
        $where_clause = "WHERE t.is_active = 1";
    }
    
    // Get total count
    $count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM dcc_trains t $where_clause");
    $count_stmt->execute($params);
    $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get trains
    $query = "
        SELECT 
            t.id, t.train_number, t.train_name, t.train_type, t.route, 
            t.departure_station_id, t.arrival_station_id, t.departure_time, t.arrival_time,
            t.max_speed_kmh, t.consist_notes, t.is_active,
            ds.name as departure_station_name,
            as_.name as arrival_station_name
        FROM dcc_trains t
        LEFT JOIN dcc_stations ds ON t.departure_station_id = ds.id
        LEFT JOIN dcc_stations as_ ON t.arrival_station_id = as_.id
        $where_clause
        ORDER BY t.train_number
        LIMIT $limit OFFSET $offset
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $trains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get locomotive count for each train
    foreach ($trains as &$train) {
        $loco_stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM dcc_train_locomotives 
            WHERE train_id = ?
        ");
        $loco_stmt->execute([$train['id']]);
        $train['locomotive_count'] = (int)$loco_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    outputJSON([
        'status' => 'success',
        'data' => $trains,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

function handleOverview($conn) {
    $stmt = $conn->prepare("SELECT * FROM dcc_trains_overview ORDER BY train_number");
    $stmt->execute();
    $trains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    outputJSON(['status' => 'success', 'data' => $trains]);
}

function handleGet($conn) {
    $id = $_GET['id'] ?? '';
    
    // Log the received ID for debugging
    error_log("trains_api.php handleGet called with id parameter: '$id'");
    
    if (empty($id) || !is_numeric($id)) {
        throw new Exception("Invalid train ID: '$id'. ID must be a positive number.");
    }
    
    $id = (int)$id;
    if ($id <= 0) {
        throw new Exception("Invalid train ID: $id. ID must be greater than 0.");
    }
    
    // Get train details
    $stmt = $conn->prepare("
        SELECT 
            t.*,
            ds.name as departure_station_name,
            as_.name as arrival_station_name
        FROM dcc_trains t
        LEFT JOIN dcc_stations ds ON t.departure_station_id = ds.id
        LEFT JOIN dcc_stations as_ ON t.arrival_station_id = as_.id
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);
    $train = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$train) {
        throw new Exception('Train not found');
    }
    
    // Get train consist (locomotives)
    $consist_stmt = $conn->prepare("
        SELECT 
            tl.id as train_locomotive_id,
            tl.position_in_train,
            tl.is_lead_locomotive,
            tl.facing_direction,
            tl.notes,
            l.id as locomotive_id,
            l.dcc_address,
            l.class,
            l.number,
            l.name,
            l.manufacturer,
            l.locomotive_type,
            CONCAT(l.class, ' ', l.number, CASE WHEN l.name IS NOT NULL THEN CONCAT(' \"', l.name, '\"') ELSE '' END) as display_name
        FROM dcc_train_locomotives tl
        JOIN dcc_locomotives l ON tl.locomotive_id = l.id
        WHERE tl.train_id = ?
        ORDER BY tl.position_in_train
    ");
    $consist_stmt->execute([$id]);
    $train['consist'] = $consist_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    outputJSON(['status' => 'success', 'data' => $train]);
}

function handleGetStations($conn) {
    $stmt = $conn->prepare("
        SELECT id, name, description 
        FROM dcc_stations 
        ORDER BY name
    ");
    $stmt->execute();
    $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    outputJSON(['status' => 'success', 'data' => $stations]);
}

function handleCalculateSchedule($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['departure_station_id']) || empty($input['arrival_station_id'])) {
        outputJSON(['status' => 'error', 'error' => 'Departure and arrival stations are required']);
        return;
    }
    
    $departure_station = $input['departure_station_id'];
    $arrival_station = $input['arrival_station_id'];
    $departure_time = $input['departure_time'] ?? null;
    $max_speed = isset($input['max_speed_kmh']) ? floatval($input['max_speed_kmh']) : 80;
    
    try {
        // Calculate shortest route and total distance
        $route = calculateRoute($conn, $departure_station, $arrival_station);
        
        if (!$route['success']) {
            outputJSON(['status' => 'error', 'error' => $route['error']]);
            return;
        }
        
        $total_distance_km = $route['total_distance'];
        $route_connections = $route['connections'];
        
        // Calculate travel time based on distance and speed limits
        $total_travel_time_minutes = 0;
        $segments = [];
        $station_times = [];
        $cumulative_time_minutes = 0;
        
        // Add departure station to station times
        if ($departure_time) {
            $station_times[] = [
                'station_id' => $departure_station,
                'arrival_time' => $departure_time,
                'departure_time' => $departure_time,
                'is_departure' => true
            ];
        }
        
        foreach ($route_connections as $connection) {
            $segment_distance_km = floatval($connection['distance_km']);
            
            // Use train's max speed, but respect track speed limits if they are lower
            $track_speed_limit = intval($connection['track_speed_limit'] ?? 80);
            
            // Use the lower of train max speed or track speed limit, but ensure minimum of 30 km/h for realistic calculation
            $segment_max_speed = max(30, min($max_speed, $track_speed_limit));
            
            // Calculate time for this segment (distance/speed * 60 for minutes)
            $segment_travel_time = ($segment_distance_km / $segment_max_speed) * 60;
            
            // Update cumulative time with travel time
            $cumulative_time_minutes += $segment_travel_time;
            
            // Calculate arrival time at this station
            $arrival_time_at_station = null;
            $departure_time_at_station = null;
            if ($departure_time) {
                $departure_datetime = new DateTime($departure_time);
                $departure_datetime->add(new DateInterval('PT' . round($cumulative_time_minutes) . 'M'));
                $arrival_time_at_station = $departure_datetime->format('H:i');
                
                // Add dwell time for intermediate stations
                if ($connection['to_station_id'] !== $arrival_station) {
                    $departure_datetime->add(new DateInterval('PT2M')); // 2 minutes dwell
                    $departure_time_at_station = $departure_datetime->format('H:i');
                    $cumulative_time_minutes += 2; // Add dwell time to running total
                } else {
                    $departure_time_at_station = $arrival_time_at_station; // No dwell at final station
                }
            }
            
            // Add to station times
            $station_times[] = [
                'station_id' => $connection['to_station_id'],
                'arrival_time' => $arrival_time_at_station,
                'departure_time' => $departure_time_at_station,
                'is_arrival' => ($connection['to_station_id'] === $arrival_station)
            ];
            
            // Calculate total segment time including dwell
            $total_segment_time = $segment_travel_time;
            if ($connection['to_station_id'] !== $arrival_station) {
                $total_segment_time += 2; // Add dwell time for intermediate stations
            }
            
            $total_travel_time_minutes += $total_segment_time;
            
            $segments[] = [
                'from_station' => $connection['from_station_id'],
                'to_station' => $connection['to_station_id'],
                'distance_km' => $segment_distance_km,
                'max_speed_kmh' => $segment_max_speed,
                'travel_time_minutes' => round($segment_travel_time, 1),
                'total_time_with_dwell' => round($total_segment_time, 1)
            ];
        }
        
        // Calculate arrival time - use the last station's arrival time for consistency
        $arrival_time = null;
        if ($departure_time && !empty($station_times)) {
            $last_station = end($station_times);
            $arrival_time = $last_station['arrival_time'] . ':00'; // Add seconds for consistency
        }
        
        // Calculate average speed
        $average_speed_kmh = $total_distance_km > 0 ? ($total_distance_km / ($total_travel_time_minutes / 60)) : 0;
        
        $result = [
            'status' => 'success',
            'data' => [
                'total_distance_km' => round($total_distance_km, 3),
                'total_travel_time_minutes' => round($total_travel_time_minutes, 1),
                'average_speed_kmh' => round($average_speed_kmh, 2),
                'departure_time' => $departure_time,
                'calculated_arrival_time' => $arrival_time,
                'route_segments' => $segments,
                'station_times' => $station_times,
                'station_sequence' => array_unique(array_merge(
                    [$departure_station],
                    array_column($route_connections, 'to_station_id')
                ))
            ]
        ];
        
        outputJSON($result);
        
    } catch (Exception $e) {
        outputJSON(['status' => 'error', 'error' => $e->getMessage()]);
    }
}

function calculateRoute($conn, $from_station, $to_station) {
    // Use Dijkstra's algorithm for shortest path calculation with multi-hop support
    
    // Get all active connections
    $stmt = $conn->prepare("
        SELECT from_station_id, to_station_id, 
               distance_km, 
               bidirectional,
               track_speed_limit
        FROM dcc_station_connections 
        WHERE is_active = 1
    ");
    $stmt->execute();
    $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build adjacency list
    $graph = [];
    $connectionMap = []; // Store connection details for path reconstruction
    
    foreach ($connections as $conn_data) {
        $from = $conn_data['from_station_id'];
        $to = $conn_data['to_station_id'];
        $distance = floatval($conn_data['distance_km']);
        
        if (!isset($graph[$from])) $graph[$from] = [];
        $graph[$from][] = ['station' => $to, 'distance' => $distance];
        $connectionMap[$from . '-' . $to] = $conn_data;
        
        // Add reverse connection if bidirectional
        if ($conn_data['bidirectional']) {
            if (!isset($graph[$to])) $graph[$to] = [];
            $graph[$to][] = ['station' => $from, 'distance' => $distance];
            // Create reverse connection data
            $reverseConn = $conn_data;
            $reverseConn['from_station_id'] = $to;
            $reverseConn['to_station_id'] = $from;
            $connectionMap[$to . '-' . $from] = $reverseConn;
        }
    }
    
    // Initialize distances for Dijkstra's algorithm
    $distances = [];
    $previous = [];
    $unvisited = [];
    
    // Get all stations
    $stationStmt = $conn->prepare("SELECT id FROM dcc_stations");
    $stationStmt->execute();
    $stations = $stationStmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($stations as $station) {
        $distances[$station] = PHP_FLOAT_MAX;
        $previous[$station] = null;
        $unvisited[$station] = true;
    }
    
    if (!isset($distances[$from_station]) || !isset($distances[$to_station])) {
        return [
            'success' => false,
            'error' => 'One or both stations not found in network'
        ];
    }
    
    $distances[$from_station] = 0;
    
    // Dijkstra's algorithm
    while (!empty($unvisited)) {
        // Find unvisited node with minimum distance
        $current = null;
        $minDistance = PHP_FLOAT_MAX;
        foreach ($unvisited as $station => $ignored) {
            if ($distances[$station] < $minDistance) {
                $minDistance = $distances[$station];
                $current = $station;
            }
        }
        
        if ($current === null || $distances[$current] === PHP_FLOAT_MAX) {
            break; // No path found
        }
        
        unset($unvisited[$current]);
        
        // If we reached the destination, we can stop
        if ($current === $to_station) {
            break;
        }
        
        // Update distances to neighbors
        if (isset($graph[$current])) {
            foreach ($graph[$current] as $neighbor) {
                $neighborStation = $neighbor['station'];
                if (isset($unvisited[$neighborStation])) {
                    $alt = $distances[$current] + $neighbor['distance'];
                    if ($alt < $distances[$neighborStation]) {
                        $distances[$neighborStation] = $alt;
                        $previous[$neighborStation] = $current;
                    }
                }
            }
        }
    }
    
    // Check if path was found
    if ($distances[$to_station] === PHP_FLOAT_MAX) {
        return [
            'success' => false,
            'error' => 'No route found between stations'
        ];
    }
    
    // Reconstruct path and build connection details
    $path = [];
    $current = $to_station;
    while ($current !== null) {
        array_unshift($path, $current);
        $current = $previous[$current];
    }
    
    // Build connections array for the path
    $routeConnections = [];
    for ($i = 0; $i < count($path) - 1; $i++) {
        $fromSt = $path[$i];
        $toSt = $path[$i + 1];
        $connKey = $fromSt . '-' . $toSt;
        
        if (isset($connectionMap[$connKey])) {
            $routeConnections[] = $connectionMap[$connKey];
        }
    }
    
    return [
        'success' => true,
        'total_distance' => $distances[$to_station],
        'connections' => $routeConnections,
        'path' => $path,
        'stations_count' => count($path)
    ];
}

function handleAvailableLocomotives($conn) {
    // Get departure station and time for filtering locomotives by location
    $departureStation = $_GET['departure_station'] ?? null;
    $departureTime = $_GET['departure_time'] ?? null;
    
    // Debug logging
    error_log("handleAvailableLocomotives called with departure_station: '$departureStation', departure_time: '$departureTime'");
    
    // Get all locomotives with additional utilization data
    $stmt = $conn->prepare("
        SELECT 
            l.id, l.dcc_address, l.class, l.number, l.name, l.manufacturer, l.locomotive_type,
            CONCAT(l.class, ' ', l.number, CASE WHEN l.name IS NOT NULL THEN CONCAT(' \"', l.name, '\"') ELSE '' END) as display_name,
            COUNT(DISTINCT tl.train_id) as total_assignments,
            MAX(t.arrival_time) as last_assignment_end
        FROM dcc_locomotives l
        LEFT JOIN dcc_train_locomotives tl ON l.id = tl.locomotive_id
        LEFT JOIN dcc_trains t ON tl.train_id = t.id AND t.is_active = 1
        WHERE l.is_active = 1 
        GROUP BY l.id, l.dcc_address, l.class, l.number, l.name, l.manufacturer, l.locomotive_type
        ORDER BY l.dcc_address
    ");
    $stmt->execute();
    $locomotives = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Found " . count($locomotives) . " locomotives in database");
    
    if (empty($locomotives)) {
        error_log("No locomotives found in database - returning empty list");
        outputJSON(['status' => 'success', 'data' => [], 'message' => 'No locomotives found in database']);
        return;
    }
    
    $availableLocomotives = [];
    
    foreach ($locomotives as $loco) {
        // Check if locomotive is currently assigned to any active train
        $stmt = $conn->prepare("
            SELECT DISTINCT t.id, t.train_number, t.arrival_station_id, t.arrival_time, t.departure_time
            FROM dcc_trains t
            JOIN dcc_train_locomotives tl ON t.id = tl.train_id
            WHERE tl.locomotive_id = ? AND t.is_active = 1
            ORDER BY t.arrival_time DESC
            LIMIT 1
        ");
        $stmt->execute([$loco['id']]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $loco['availability_status'] = 'available';
        $loco['current_location'] = null;
        $loco['assigned_train'] = null;
        $loco['available_from'] = null;
        $loco['notes'] = '';
        $loco['utilization_score'] = intval($loco['total_assignments']); // Number of current assignments
        $loco['last_used'] = $loco['last_assignment_end'];
        
        if ($assignment) {
            // Locomotive is assigned to a train
            $loco['assigned_train'] = $assignment['train_number'];
            $loco['current_location'] = $assignment['arrival_station_id'];
            $loco['available_from'] = $assignment['arrival_time'];
            
            // Check if locomotive will be available at departure time and location
            if ($departureStation && $departureTime) {
                $departureTimeMinutes = timeToMinutesHelper($departureTime);
                $arrivalTimeMinutes = timeToMinutesHelper($assignment['arrival_time']);
                
                // Handle overnight times
                if ($departureTimeMinutes < $arrivalTimeMinutes) {
                    $departureTimeMinutes += 24 * 60;
                }
                
                if ($arrivalTimeMinutes <= $departureTimeMinutes && $assignment['arrival_station_id'] === $departureStation) {
                    $loco['availability_status'] = 'available';
                    $loco['notes'] = "Available from {$assignment['arrival_time']} at {$assignment['arrival_station_id']}";
                } else {
                    $loco['availability_status'] = 'unavailable';
                    $loco['notes'] = "Busy until {$assignment['arrival_time']}, arrives at {$assignment['arrival_station_id']}";
                    
                    // Skip unavailable locomotives if filtering by departure station/time
                    if ($departureStation) {
                        continue;
                    }
                }
            } else {
                // No departure context provided, show locomotive but mark as potentially unavailable
                $loco['availability_status'] = 'assigned';
                $loco['notes'] = "Currently assigned to train {$assignment['train_number']}, available from {$assignment['arrival_time']} at {$assignment['arrival_station_id']}";
            }
        } else {
            // Locomotive never assigned or not currently assigned
            $loco['availability_status'] = 'available';
            $loco['notes'] = 'Available for assignment';
            
            // Check if locomotive needs maintenance based on usage
            if ($loco['utilization_score'] > 5) {
                $loco['maintenance_alert'] = 'Consider maintenance - high utilization';
            } elseif ($loco['last_used'] && strtotime($loco['last_used']) < strtotime('-30 days')) {
                $loco['maintenance_alert'] = 'Long idle period - check status';
            }
        }
        
        $availableLocomotives[] = $loco;
    }
    
    // Sort locomotives by availability status and utilization for better user experience
    usort($availableLocomotives, function($a, $b) {
        // Priority order: available > assigned > unavailable
        $statusPriority = ['available' => 1, 'assigned' => 2, 'unavailable' => 3];
        $aPriority = $statusPriority[$a['availability_status']] ?? 4;
        $bPriority = $statusPriority[$b['availability_status']] ?? 4;
        
        if ($aPriority !== $bPriority) {
            return $aPriority - $bPriority;
        }
        
        // Within same status, sort by utilization (less busy first)
        if ($a['utilization_score'] !== $b['utilization_score']) {
            return $a['utilization_score'] - $b['utilization_score'];
        }
        
        // Finally sort by DCC address
        return $a['dcc_address'] - $b['dcc_address'];
    });
    
    error_log("Returning " . count($availableLocomotives) . " available locomotives");
    
    outputJSON(['status' => 'success', 'data' => $availableLocomotives]);
}

function handleTestLocomotives($conn) {
    // Simple test to check if locomotives exist in database
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM dcc_locomotives");
        $stmt->execute();
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        $stmt = $conn->prepare("SELECT COUNT(*) as active_count FROM dcc_locomotives WHERE is_active = 1");
        $stmt->execute();
        $activeCount = $stmt->fetch(PDO::FETCH_ASSOC)['active_count'];
        
        $stmt = $conn->prepare("SELECT id, dcc_address, class, number, name, is_active FROM dcc_locomotives LIMIT 5");
        $stmt->execute();
        $sample = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        outputJSON([
            'status' => 'success', 
            'data' => [
                'total_locomotives' => $count,
                'active_locomotives' => $activeCount,
                'sample_locomotives' => $sample
            ]
        ]);
    } catch (Exception $e) {
        outputJSON(['status' => 'error', 'error' => 'Database error: ' . $e->getMessage()]);
    }
}


function handleLocomotiveStats($conn) {
    // Get comprehensive locomotive statistics
    $stmt = $conn->prepare("
        SELECT 
            l.id, l.dcc_address, l.class, l.number, l.name, l.manufacturer, l.locomotive_type,
            CONCAT(l.class, ' ', l.number, CASE WHEN l.name IS NOT NULL THEN CONCAT(' \"', l.name, '\"') ELSE '' END) as display_name,
            COUNT(DISTINCT tl.train_id) as total_assignments,
            COUNT(DISTINCT CASE WHEN t.is_active = 1 THEN tl.train_id END) as active_assignments,
            MAX(t.arrival_time) as last_assignment_end,
            MIN(t.departure_time) as first_assignment_start,
            SUM(CASE WHEN t.is_active = 1 THEN 1 ELSE 0 END) as current_workload,
            AVG(TIME_TO_SEC(TIMEDIFF(t.arrival_time, t.departure_time))/3600) as avg_run_hours
        FROM dcc_locomotives l
        LEFT JOIN dcc_train_locomotives tl ON l.id = tl.locomotive_id
        LEFT JOIN dcc_trains t ON tl.train_id = t.id
        WHERE l.is_active = 1 
        GROUP BY l.id, l.dcc_address, l.class, l.number, l.name, l.manufacturer, l.locomotive_type
        ORDER BY active_assignments DESC, total_assignments DESC, l.dcc_address
    ");
    $stmt->execute();
    $locomotiveStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate efficiency metrics
    foreach ($locomotiveStats as &$loco) {
        $loco['efficiency_score'] = calculateEfficiencyScore($loco);
        $loco['status_category'] = categorizeLocomotiveStatus($loco);
        $loco['avg_run_hours'] = $loco['avg_run_hours'] ? round($loco['avg_run_hours'], 1) : 0;
    }
    
    outputJSON(['status' => 'success', 'data' => $locomotiveStats]);
}

function calculateEfficiencyScore($loco) {
    $totalAssignments = intval($loco['total_assignments']);
    $activeAssignments = intval($loco['active_assignments']);
    $avgRunHours = floatval($loco['avg_run_hours']);
    
    // Base score from total assignments (experience factor)
    $experienceScore = min($totalAssignments * 10, 50); // Max 50 points
    
    // Current utilization score
    $utilizationScore = $activeAssignments * 20; // 20 points per active assignment
    
    // Run time efficiency (balanced - not too short, not too long)
    $runTimeScore = 0;
    if ($avgRunHours >= 2 && $avgRunHours <= 8) {
        $runTimeScore = 30; // Optimal range
    } elseif ($avgRunHours > 0) {
        $runTimeScore = 15; // Some activity but not optimal
    }
    
    return $experienceScore + $utilizationScore + $runTimeScore;
}

function categorizeLocomotiveStatus($loco) {
    $activeAssignments = intval($loco['active_assignments']);
    $totalAssignments = intval($loco['total_assignments']);
    
    if ($activeAssignments >= 3) {
        return 'overworked';
    } elseif ($activeAssignments >= 1) {
        return 'active';
    } elseif ($totalAssignments > 0) {
        return 'idle';
    } else {
        return 'unused';
    }
}

// Helper function for time conversion
function timeToMinutesHelper($timeStr) {
    if (!$timeStr) return 0;
    $timeStr = preg_replace('/(\d{2}:\d{2}):\d{2}/', '$1', $timeStr);
    $parts = explode(':', $timeStr);
    return count($parts) >= 2 ? intval($parts[0]) * 60 + intval($parts[1]) : 0;
}

function handleCreate($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['train_number'])) {
        throw new Exception('Train number is required');
    }
    
    // Validate station ID lengths (must be maximum 8 characters)
    if (!empty($input['departure_station_id']) && strlen($input['departure_station_id']) > 8) {
        throw new Exception('Departure station ID must be maximum 8 characters long');
    }
    
    if (!empty($input['arrival_station_id']) && strlen($input['arrival_station_id']) > 8) {
        throw new Exception('Arrival station ID must be maximum 8 characters long');
    }
    
    // Check if train number already exists
    $check_stmt = $conn->prepare("SELECT id FROM dcc_trains WHERE train_number = ?");
    $check_stmt->execute([$input['train_number']]);
    if ($check_stmt->fetch()) {
        throw new Exception('Train number already exists');
    }
    
    // COLLISION DETECTION: Check for route conflicts
    // Always check for collisions if we have scheduling data, regardless of whether arrival_time is provided
    if (!empty($input['departure_station_id']) && !empty($input['arrival_station_id']) && 
        !empty($input['departure_time'])) {
        
        // Use provided arrival time or calculate it if not provided
        $arrivalTimeForCheck = $input['arrival_time'] ?? null;
        if (!$arrivalTimeForCheck) {
            // Calculate arrival time based on route and departure time
            $scheduleCalc = calculateRoute($conn, $input['departure_station_id'], $input['arrival_station_id']);
            if ($scheduleCalc['success']) {
                $maxSpeed = !empty($input['max_speed_kmh']) ? (int)$input['max_speed_kmh'] : 80;
                $totalTime = 0;
                foreach ($scheduleCalc['connections'] as $connection) {
                    $segmentDistance = floatval($connection['distance_km']);
                    $trackSpeedLimit = intval($connection['track_speed_limit'] ?? 80);
                    $segmentMaxSpeed = max(30, min($maxSpeed, $trackSpeedLimit));
                    $totalTime += ($segmentDistance / $segmentMaxSpeed) * 60; // minutes
                }
                $totalTime += count($scheduleCalc['connections']) * 2; // Add 2 min dwell time per station
                
                $departureDateTime = new DateTime($input['departure_time']);
                $departureDateTime->add(new DateInterval('PT' . round($totalTime) . 'M'));
                $arrivalTimeForCheck = $departureDateTime->format('H:i:s');
            } else {
                throw new Exception('Cannot calculate route for collision detection: ' . $scheduleCalc['error']);
            }
        }
        
        $conflicts = checkRouteConflicts($conn, 
            $input['departure_station_id'], 
            $input['arrival_station_id'], 
            $input['departure_time'], 
            $arrivalTimeForCheck,
            null  // No train to exclude since this is a new train
        );
        
        if (!empty($conflicts)) {
            $conflictDetails = [];
            foreach ($conflicts as $conflict) {
                $conflictDetails[] = sprintf(
                    "Collision with train %s on track segment %s↔%s (overlap: %s to %s)",
                    $conflict['conflicting_train'],
                    $conflict['from_station'],
                    $conflict['to_station'], 
                    $conflict['overlap_start'],
                    $conflict['overlap_end']
                );
            }
            
            throw new Exception('Route conflicts detected: ' . implode('; ', $conflictDetails));
        }
    }
    
    $stmt = $conn->prepare("
        INSERT INTO dcc_trains (
            train_number, train_name, train_type, route, 
            departure_station_id, arrival_station_id, departure_time, arrival_time,
            max_speed_kmh, consist_notes, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $input['train_number'],
        $input['train_name'] ?? null,
        $input['train_type'] ?? 'passenger',
        $input['route'] ?? null,
        !empty($input['departure_station_id']) ? $input['departure_station_id'] : null,
        !empty($input['arrival_station_id']) ? $input['arrival_station_id'] : null,
        $input['departure_time'] ?? null,
        $input['arrival_time'] ?? null,
        !empty($input['max_speed_kmh']) ? (int)$input['max_speed_kmh'] : null,
        $input['consist_notes'] ?? null,
        isset($input['is_active']) ? (!empty($input['is_active']) ? 1 : 0) : 1  // Default to active (1) if not specified
    ]);
    
    $train_id = $conn->lastInsertId();
    
    // Update locomotive availability after successful train creation
    updateLocomotiveAvailability($conn, $train_id, $input);
    
    outputJSON(['status' => 'success', 'message' => 'Train created successfully', 'data' => ['id' => $train_id]]);
}

function handleCreateFreeRunning($conn) {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (empty($input['train_number'])) {
        throw new Exception('Train number is required');
    }
    
    // Validate station ID lengths (must be maximum 8 characters)
    if (!empty($input['origin_station']) && strlen($input['origin_station']) > 8) {
        throw new Exception('Origin station ID must be maximum 8 characters long');
    }
    
    if (!empty($input['destination_station']) && strlen($input['destination_station']) > 8) {
        throw new Exception('Destination station ID must be maximum 8 characters long');
    }
    
    // Check if train number already exists
    $check_stmt = $conn->prepare("SELECT id FROM dcc_trains WHERE train_number = ?");
    $check_stmt->execute([$input['train_number']]);
    if ($check_stmt->fetch()) {
        throw new Exception('Train number already exists');
    }
    
    // Create free running train with minimal timetable constraints
    $stmt = $conn->prepare("
        INSERT INTO dcc_trains (
            train_number, train_name, train_type, route, 
            departure_station_id, arrival_station_id, 
            max_speed_kmh, consist_notes, is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $input['train_number'],
        $input['train_name'] ?? null,
        $input['train_type'] ?? 'passenger',
        $input['route_description'] ?? null,
        !empty($input['origin_station']) ? $input['origin_station'] : null,
        !empty($input['destination_station']) ? $input['destination_station'] : null,
        !empty($input['max_speed_kmh']) ? (int)$input['max_speed_kmh'] : null,
        $input['notes'] ?? 'Free running train - operates independently without fixed timetable',
        isset($input['is_active']) ? (!empty($input['is_active']) ? 1 : 0) : 1  // Default to active (1) if not specified
    ]);
    
    $train_id = $conn->lastInsertId();
    
    // If locomotive is specified, assign it to the train
    if (!empty($input['locomotive_id'])) {
        try {
            $consist_stmt = $conn->prepare("
                INSERT INTO dcc_train_consists (
                    train_id, locomotive_id, position_in_train, is_lead_locomotive, facing_direction
                ) VALUES (?, ?, ?, ?, ?)
            ");
            
            $consist_stmt->execute([
                $train_id,
                $input['locomotive_id'],
                1,
                1, // is_lead_locomotive
                'forward'
            ]);
        } catch (Exception $e) {
            // Continue even if locomotive assignment fails
            error_log("Failed to assign locomotive to free running train: " . $e->getMessage());
        }
    }
    
    outputJSON([
        'status' => 'success', 
        'message' => 'Free running train created successfully', 
        'data' => ['id' => $train_id]
    ]);
}

function handleUpdate($conn) {
    $id = $_GET['id'] ?? '';
    
    if (empty($id) || !is_numeric($id)) {
        throw new Exception("Invalid train ID: '$id'. ID must be a positive number.");
    }
    
    $id = (int)$id;
    if ($id <= 0) {
        throw new Exception("Invalid train ID: $id. ID must be greater than 0.");
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate station ID lengths (must be maximum 8 characters)
    if (isset($input['departure_station_id']) && !empty($input['departure_station_id']) && strlen($input['departure_station_id']) > 8) {
        throw new Exception('Departure station ID must be maximum 8 characters long');
    }
    
    if (isset($input['arrival_station_id']) && !empty($input['arrival_station_id']) && strlen($input['arrival_station_id']) > 8) {
        throw new Exception('Arrival station ID must be maximum 8 characters long');
    }
    
    // Check if train exists and get current data
    $check_stmt = $conn->prepare("SELECT * FROM dcc_trains WHERE id = ?");
    $check_stmt->execute([$id]);
    $currentTrain = $check_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$currentTrain) {
        throw new Exception('Train not found');
    }
    
    // COLLISION DETECTION: Check for route conflicts when updating schedule
    $updatedDepartureStation = $input['departure_station_id'] ?? $currentTrain['departure_station_id'];
    $updatedArrivalStation = $input['arrival_station_id'] ?? $currentTrain['arrival_station_id'];
    $updatedDepartureTime = $input['departure_time'] ?? $currentTrain['departure_time'];
    $updatedArrivalTime = $input['arrival_time'] ?? $currentTrain['arrival_time'];
    $updatedMaxSpeed = isset($input['max_speed_kmh']) ? (int)$input['max_speed_kmh'] : ($currentTrain['max_speed_kmh'] ?? 80);
    
    if (!empty($updatedDepartureStation) && !empty($updatedArrivalStation) && 
        !empty($updatedDepartureTime)) {
        
        // Use provided arrival time or calculate it if not provided
        $arrivalTimeForCheck = $updatedArrivalTime;
        if (!$arrivalTimeForCheck) {
            // Calculate arrival time based on route and departure time
            $scheduleCalc = calculateRoute($conn, $updatedDepartureStation, $updatedArrivalStation);
            if ($scheduleCalc['success']) {
                $totalTime = 0;
                foreach ($scheduleCalc['connections'] as $connection) {
                    $segmentDistance = floatval($connection['distance_km']);
                    $trackSpeedLimit = intval($connection['track_speed_limit'] ?? 80);
                    $segmentMaxSpeed = max(30, min($updatedMaxSpeed, $trackSpeedLimit));
                    $totalTime += ($segmentDistance / $segmentMaxSpeed) * 60; // minutes
                }
                $totalTime += count($scheduleCalc['connections']) * 2; // Add 2 min dwell time per station
                
                $departureDateTime = new DateTime($updatedDepartureTime);
                $departureDateTime->add(new DateInterval('PT' . round($totalTime) . 'M'));
                $arrivalTimeForCheck = $departureDateTime->format('H:i:s');
            } else {
                throw new Exception('Cannot calculate route for collision detection: ' . $scheduleCalc['error']);
            }
        }
        
        $conflicts = checkRouteConflicts($conn, 
            $updatedDepartureStation, 
            $updatedArrivalStation, 
            $updatedDepartureTime, 
            $arrivalTimeForCheck,
            $id  // Exclude current train from conflict check
        );
        
        if (!empty($conflicts)) {
            $conflictDetails = [];
            foreach ($conflicts as $conflict) {
                $conflictDetails[] = sprintf(
                    "Collision with train %s on track segment %s↔%s (overlap: %s to %s)",
                    $conflict['conflicting_train'],
                    $conflict['from_station'],
                    $conflict['to_station'], 
                    $conflict['overlap_start'],
                    $conflict['overlap_end']
                );
            }
            
            throw new Exception('Route conflicts detected: ' . implode('; ', $conflictDetails));
        }
    }
    
    // Build update fields
    $fields = [];
    $params = [];
    
    if (isset($input['train_number'])) {
        $fields[] = "train_number = ?";
        $params[] = $input['train_number'];
    }
    if (isset($input['train_name'])) {
        $fields[] = "train_name = ?";
        $params[] = $input['train_name'];
    }
    if (isset($input['train_type'])) {
        $fields[] = "train_type = ?";
        $params[] = $input['train_type'];
    }
    if (isset($input['route'])) {
        $fields[] = "route = ?";
        $params[] = $input['route'];
    }
    if (isset($input['departure_station_id'])) {
        $fields[] = "departure_station_id = ?";
        $params[] = !empty($input['departure_station_id']) ? $input['departure_station_id'] : null;
    }
    if (isset($input['arrival_station_id'])) {
        $fields[] = "arrival_station_id = ?";
        $params[] = !empty($input['arrival_station_id']) ? $input['arrival_station_id'] : null;
    }
    if (isset($input['departure_time'])) {
        $fields[] = "departure_time = ?";
        $params[] = $input['departure_time'];
    }
    if (isset($input['arrival_time'])) {
        $fields[] = "arrival_time = ?";
        $params[] = $input['arrival_time'];
    }
    if (isset($input['max_speed_kmh'])) {
        $fields[] = "max_speed_kmh = ?";
        $params[] = !empty($input['max_speed_kmh']) ? (int)$input['max_speed_kmh'] : null;
    }
    if (isset($input['consist_notes'])) {
        $fields[] = "consist_notes = ?";
        $params[] = $input['consist_notes'];
    }
    if (isset($input['is_active'])) {
        $fields[] = "is_active = ?";
        $params[] = !empty($input['is_active']) ? 1 : 0;
    }
    
    if (empty($fields)) {
        throw new Exception('No fields to update');
    }
    
    $params[] = $id;
    
    $stmt = $conn->prepare("UPDATE dcc_trains SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($params);
    
    outputJSON(['status' => 'success', 'message' => 'Train updated successfully']);
}

function handleUpdateConsist($conn) {
    $id = $_GET['id'] ?? '';
    
    if (empty($id) || !is_numeric($id)) {
        throw new Exception("Invalid train ID: '$id'. ID must be a positive number.");
    }
    
    $id = (int)$id;
    if ($id <= 0) {
        throw new Exception("Invalid train ID: $id. ID must be greater than 0.");
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['consist']) || !is_array($input['consist'])) {
        throw new Exception('Consist data is required');
    }
    
    // Validate that we have at least one locomotive
    if (empty($input['consist'])) {
        throw new Exception('Train must have at least one locomotive');
    }
    
    // Start transaction
    $conn->beginTransaction();
    
    try {
        // Remove existing consist
        $delete_stmt = $conn->prepare("DELETE FROM dcc_train_locomotives WHERE train_id = ?");
        $delete_stmt->execute([$id]);
        
        // Add new consist
        $insert_stmt = $conn->prepare("
            INSERT INTO dcc_train_locomotives (
                train_id, locomotive_id, position_in_train, is_lead_locomotive, facing_direction, notes
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($input['consist'] as $position => $loco) {
            if (empty($loco['locomotive_id'])) {
                throw new Exception('Locomotive ID is required for position ' . ($position + 1));
            }
            
            $insert_stmt->execute([
                $id,
                (int)$loco['locomotive_id'],
                $position + 1, // Position is 1-based
                !empty($loco['is_lead_locomotive']) ? 1 : 0,
                $loco['facing_direction'] ?? 'forward',
                $loco['notes'] ?? null
            ]);
        }
        
        $conn->commit();
        outputJSON(['status' => 'success', 'message' => 'Train consist updated successfully']);
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

function handleDelete($conn) {
    $id = $_GET['id'] ?? '';
    
    if (empty($id) || !is_numeric($id)) {
        throw new Exception("Invalid train ID: '$id'. ID must be a positive number.");
    }
    
    $id = (int)$id;
    if ($id <= 0) {
        throw new Exception("Invalid train ID: $id. ID must be greater than 0.");
    }
    
    // The foreign key constraint will automatically delete train_locomotives records
    $stmt = $conn->prepare("DELETE FROM dcc_trains WHERE id = ?");
    $stmt->execute([$id]);
    
    outputJSON(['status' => 'success', 'message' => 'Train deleted successfully']);
}

function updateLocomotiveAvailability($conn, $trainId, $trainData) {
    try {
        // Get locomotives assigned to this train
        $stmt = $conn->prepare("
            SELECT locomotive_id FROM dcc_train_locomotives WHERE train_id = ?
        ");
        $stmt->execute([$trainId]);
        $locomotives = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($locomotives)) {
            return;
        }
        
        // Calculate when locomotives will be available (at arrival time + 10 minutes for turnaround)
        $arrivalTime = $trainData['arrival_time'] ?? null;
        if (!$arrivalTime) {
            return; // No arrival time specified
        }
        
        $today = date('Y-m-d');
        $availableFrom = date('Y-m-d H:i:s', strtotime("$today $arrivalTime") + 600); // +10 minutes
        
        // Update or insert locomotive availability records
        foreach ($locomotives as $locomotiveId) {
            $stmt = $conn->prepare("
                INSERT INTO dcc_locomotive_availability 
                (locomotive_id, available_from, current_station_id, status, notes) 
                VALUES (?, ?, ?, 'assigned', ?)
                ON DUPLICATE KEY UPDATE
                available_from = VALUES(available_from),
                current_station_id = VALUES(current_station_id),
                status = VALUES(status),
                notes = VALUES(notes),
                updated_at = CURRENT_TIMESTAMP
            ");
            
            $trainNumber = $trainData['train_number'] ?? 'Unknown';
            $stmt->execute([
                $locomotiveId,
                $availableFrom,
                $trainData['arrival_station_id'] ?? null,
                "Assigned to train $trainNumber, available after arrival"
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Error updating locomotive availability: " . $e->getMessage());
    }
}

/**
 * Check for route conflicts between trains
 */
function checkRouteConflicts($conn, $departureStation, $arrivalStation, $departureTime, $arrivalTime, $excludeTrainId = null) {
    $conflicts = [];
    
    // First, get the route for the new/updated train
    $route = calculateRoute($conn, $departureStation, $arrivalStation);
    if (!$route['success']) {
        return []; // If no route exists, no conflicts to check
    }
    
    // Calculate the realistic station times for this train
    $stationTimes = calculateStationTimes($conn, $route, $departureTime, $arrivalTime);
    
    // Get all other active trains
    $excludeClause = $excludeTrainId ? "AND t.id != ?" : "";
    $stmt = $conn->prepare("
        SELECT t.id, t.train_number, t.train_name, t.departure_station_id, t.arrival_station_id, 
               t.departure_time, t.arrival_time, t.max_speed_kmh
        FROM dcc_trains t
        WHERE t.is_active = 1 
          AND t.departure_station_id IS NOT NULL 
          AND t.arrival_station_id IS NOT NULL
          AND t.departure_time IS NOT NULL 
          AND t.arrival_time IS NOT NULL
          $excludeClause
    ");
    
    $params = $excludeTrainId ? [$excludeTrainId] : [];
    $stmt->execute($params);
    $existingTrains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check each existing train for conflicts
    foreach ($existingTrains as $train) {
        $existingRoute = calculateRoute($conn, $train['departure_station_id'], $train['arrival_station_id']);
        if (!$existingRoute['success']) {
            continue; // Skip trains with invalid routes
        }
        
        $existingStationTimes = calculateStationTimes($conn, $existingRoute, $train['departure_time'], $train['arrival_time'], $train['max_speed_kmh'] ?? 80);
        
        // Check for conflicts on each segment of our route
        for ($i = 0; $i < count($route['path']) - 1; $i++) {
            $ourFromStation = $route['path'][$i];
            $ourToStation = $route['path'][$i + 1];
            $ourStartTime = $stationTimes[$i]['departure'];
            $ourEndTime = $stationTimes[$i + 1]['arrival'];
            
            // Check if existing train uses the same segment
            for ($j = 0; $j < count($existingRoute['path']) - 1; $j++) {
                $theirFromStation = $existingRoute['path'][$j];
                $theirToStation = $existingRoute['path'][$j + 1];
                $theirStartTime = $existingStationTimes[$j]['departure'];
                $theirEndTime = $existingStationTimes[$j + 1]['arrival'];
                
                // Check if it's the same bidirectional track segment
                $sameSegment = ($ourFromStation === $theirFromStation && $ourToStation === $theirToStation) ||
                              ($ourFromStation === $theirToStation && $ourToStation === $theirFromStation);
                
                if ($sameSegment) {
                    // Check for time overlap
                    if (timesOverlap($ourStartTime, $ourEndTime, $theirStartTime, $theirEndTime)) {
                        $conflicts[] = [
                            'conflicting_train' => $train['train_number'],
                            'train_name' => $train['train_name'],
                            'from_station' => $ourFromStation,
                            'to_station' => $ourToStation,
                            'our_times' => "$ourStartTime - $ourEndTime",
                            'their_times' => "$theirStartTime - $theirEndTime",
                            'overlap_start' => max($ourStartTime, $theirStartTime),
                            'overlap_end' => min($ourEndTime, $theirEndTime)
                        ];
                    }
                }
            }
        }
    }
    
    return $conflicts;
}

/**
 * Calculate station times for a route
 */
function calculateStationTimes($conn, $route, $departureTime, $arrivalTime, $maxSpeed = 80) {
    $stationTimes = [];
    $connections = $route['connections'];
    
    // Convert departure time to minutes, handle different time formats
    $depMinutes = timeToMinutes($departureTime);
    $currentTime = $depMinutes;
    
    // Add first station (origin)
    $stationTimes[] = [
        'station_id' => $route['path'][0],
        'arrival' => minutesToTime($currentTime),
        'departure' => minutesToTime($currentTime),
        'dwell_time' => 0
    ];
    
    // Calculate times for each segment using realistic speeds
    for ($i = 0; $i < count($connections); $i++) {
        $connection = $connections[$i];
        $segment_distance_km = floatval($connection['distance_km']);
        
        // Use train's max speed, but respect track speed limits
        $track_speed_limit = intval($connection['track_speed_limit'] ?? 80);
        $segment_max_speed = max(30, min($maxSpeed, $track_speed_limit));
        
        // Calculate realistic travel time: time = distance / speed * 60 (for minutes)
        $segment_time_minutes = ($segment_distance_km / $segment_max_speed) * 60;
        
        $currentTime += $segment_time_minutes;
        
        // Add dwell time (2 minutes for intermediate stations, 0 for final)
        $dwellTime = ($i === count($connections) - 1) ? 0 : 2;
        
        $stationTimes[] = [
            'station_id' => $route['path'][$i + 1],
            'arrival' => minutesToTime($currentTime),
            'departure' => minutesToTime($currentTime + $dwellTime),
            'dwell_time' => $dwellTime
        ];
        
        $currentTime += $dwellTime;
    }
    
    return $stationTimes;
}

/**
 * Check if two time ranges overlap
 */
function timesOverlap($start1, $end1, $start2, $end2) {
    $start1_min = timeToMinutes($start1);
    $end1_min = timeToMinutes($end1);
    $start2_min = timeToMinutes($start2);
    $end2_min = timeToMinutes($end2);
    
    // Handle overnight times
    if ($end1_min < $start1_min) $end1_min += 24 * 60;
    if ($end2_min < $start2_min) $end2_min += 24 * 60;
    
    // Check for overlap: ranges overlap if start1 < end2 AND start2 < end1
    return ($start1_min < $end2_min) && ($start2_min < $end1_min);
}

/**
 * Convert time string to minutes
 */
function timeToMinutes($timeStr) {
    // Handle different time formats: "HH:MM", "HH:MM:SS", or already numeric
    if (is_numeric($timeStr)) {
        return (int)$timeStr;
    }
    
    // Remove seconds if present
    $timeStr = preg_replace('/(\d{2}:\d{2}):\d{2}/', '$1', $timeStr);
    
    $parts = explode(':', $timeStr);
    if (count($parts) >= 2) {
        return intval($parts[0]) * 60 + intval($parts[1]);
    }
    
    return 0; // Default fallback
}

/**
 * Convert minutes to time string
 */
function minutesToTime($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%02d:%02d', $hours, $mins);
}
?>
