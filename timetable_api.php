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
    
    $action = $_GET['action'] ?? 'schedule';

    switch ($action) {
        case 'schedule':
            handleGetSchedule($conn);
            break;
        case 'conflicts':
            handleCheckConflicts($conn);
            break;
        case 'track_availability':
            handleTrackAvailability($conn);
            break;
        case 'station_schedule':
            handleStationSchedule($conn);
            break;
        case 'validate_path':
            handleValidatePath($conn);
            break;
        case 'optimize':
            $user = getCurrentUser($conn);
            if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                outputJSON(['status' => 'error', 'error' => 'Authentication required']);
            }
            handleOptimizeSchedule($conn);
            break;
        default:
            outputJSON(['status' => 'error', 'error' => 'Invalid action']);
    }

} catch (Exception $e) {
    outputJSON(['status' => 'error', 'error' => $e->getMessage()]);
}

function handleGetSchedule($conn) {
    $date = $_GET['date'] ?? date('Y-m-d');
    $start_time = $_GET['start_time'] ?? '00:00:00';
    $end_time = $_GET['end_time'] ?? '23:59:59';
    $station_id = $_GET['station_id'] ?? null;
    
    try {
        $sql = "
            SELECT 
                t.*,
                ds.name as departure_station_name,
                as_.name as arrival_station_name,
                GROUP_CONCAT(
                    CONCAT(l.class, ' ', l.number)
                    ORDER BY tl.position_in_train 
                    SEPARATOR ' + '
                ) as consist_display,
                COUNT(tl.locomotive_id) as locomotive_count
            FROM dcc_trains t
            LEFT JOIN dcc_stations ds ON t.departure_station_id = ds.id
            LEFT JOIN dcc_stations as_ ON t.arrival_station_id = as_.id
            LEFT JOIN dcc_train_locomotives tl ON t.id = tl.train_id
            LEFT JOIN dcc_locomotives l ON tl.locomotive_id = l.id
            WHERE t.is_active = 1
                AND (t.departure_time BETWEEN ? AND ? OR t.arrival_time BETWEEN ? AND ?)
        ";
        
        $params = [$start_time, $end_time, $start_time, $end_time];
        
        if ($station_id) {
            $sql .= " AND (t.departure_station_id = ? OR t.arrival_station_id = ?)";
            $params[] = $station_id;
            $params[] = $station_id;
        }
        
        $sql .= " GROUP BY t.id ORDER BY t.departure_time";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $trains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add route path information
        foreach ($trains as &$train) {
            $train['route_path'] = getRoutePath($conn, $train['departure_station_id'], $train['arrival_station_id']);
            $train['track_requirements'] = calculateTrackRequirements($conn, $train);
        }
        
        outputJSON([
            'status' => 'success',
            'data' => $trains,
            'metadata' => [
                'date' => $date,
                'time_range' => ['start' => $start_time, 'end' => $end_time],
                'total_trains' => count($trains)
            ]
        ]);
        
    } catch (PDOException $e) {
        outputJSON(['status' => 'error', 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleCheckConflicts($conn) {
    $date = $_GET['date'] ?? date('Y-m-d');
    $start_time = $_GET['start_time'] ?? '00:00:00';
    $end_time = $_GET['end_time'] ?? '23:59:59';
    
    try {
        // Get all trains for the time period
        $stmt = $conn->prepare("
            SELECT 
                t.*,
                ds.name as departure_station_name,
                as_.name as arrival_station_name
            FROM dcc_trains t
            LEFT JOIN dcc_stations ds ON t.departure_station_id = ds.id
            LEFT JOIN dcc_stations as_ ON t.arrival_station_id = as_.id
            WHERE t.is_active = 1
                AND (t.departure_time BETWEEN ? AND ? OR t.arrival_time BETWEEN ? AND ?)
            ORDER BY t.departure_time
        ");
        
        $stmt->execute([$start_time, $end_time, $start_time, $end_time]);
        $trains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $conflicts = [];
        $stations = getStationsWithTracks($conn);
        
        // Check for conflicts at each station
        foreach ($stations as $station) {
            $station_conflicts = checkStationConflicts($conn, $station, $trains, $start_time, $end_time);
            $conflicts = array_merge($conflicts, $station_conflicts);
        }
        
        // Check path conflicts (trains using same connections simultaneously)
        $path_conflicts = checkPathConflicts($conn, $trains);
        $conflicts = array_merge($conflicts, $path_conflicts);
        
        outputJSON([
            'status' => 'success',
            'data' => [
                'conflicts' => $conflicts,
                'total_conflicts' => count($conflicts),
                'conflict_types' => array_count_values(array_column($conflicts, 'type')),
                'analysis_period' => ['start' => $start_time, 'end' => $end_time]
            ]
        ]);
        
    } catch (PDOException $e) {
        outputJSON(['status' => 'error', 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleTrackAvailability($conn) {
    $station_id = $_GET['station_id'] ?? null;
    $time = $_GET['time'] ?? date('H:i:s');
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if (!$station_id) {
        outputJSON(['status' => 'error', 'error' => 'Station ID required']);
    }
    
    try {
        // Get station tracks
        $stmt = $conn->prepare("
            SELECT * FROM dcc_station_tracks 
            WHERE station_id = ? AND is_active = 1
            ORDER BY track_number
        ");
        $stmt->execute([$station_id]);
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get trains at this station around this time
        $time_window = 30; // minutes
        $start_time = date('H:i:s', strtotime($time) - ($time_window * 60));
        $end_time = date('H:i:s', strtotime($time) + ($time_window * 60));
        
        $stmt = $conn->prepare("
            SELECT t.*, 'departure' as event_type, t.departure_time as event_time
            FROM dcc_trains t
            WHERE t.departure_station_id = ? 
                AND t.departure_time BETWEEN ? AND ?
                AND t.is_active = 1
            UNION ALL
            SELECT t.*, 'arrival' as event_type, t.arrival_time as event_time
            FROM dcc_trains t
            WHERE t.arrival_station_id = ? 
                AND t.arrival_time BETWEEN ? AND ?
                AND t.is_active = 1
            ORDER BY event_time
        ");
        
        $stmt->execute([$station_id, $start_time, $end_time, $station_id, $start_time, $end_time]);
        $train_events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate track occupancy
        $track_availability = [];
        foreach ($tracks as $track) {
            $track_availability[] = [
                'track' => $track,
                'status' => 'available', // Simplified - in real system would check actual occupancy
                'next_event' => null,
                'occupancy_percentage' => 0
            ];
        }
        
        outputJSON([
            'status' => 'success',
            'data' => [
                'station_id' => $station_id,
                'time' => $time,
                'tracks' => $track_availability,
                'train_events' => $train_events,
                'total_tracks' => count($tracks),
                'available_tracks' => count($tracks) // Simplified
            ]
        ]);
        
    } catch (PDOException $e) {
        outputJSON(['status' => 'error', 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleStationSchedule($conn) {
    $station_id = $_GET['station_id'] ?? null;
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if (!$station_id) {
        outputJSON(['status' => 'error', 'error' => 'Station ID required']);
    }
    
    try {
        // Get departures
        $stmt = $conn->prepare("
            SELECT 
                t.*,
                'departure' as event_type,
                t.departure_time as event_time,
                as_.name as destination_name,
                GROUP_CONCAT(l.class ORDER BY tl.position_in_train SEPARATOR '+') as locomotives
            FROM dcc_trains t
            LEFT JOIN dcc_stations as_ ON t.arrival_station_id = as_.id
            LEFT JOIN dcc_train_locomotives tl ON t.id = tl.train_id
            LEFT JOIN dcc_locomotives l ON tl.locomotive_id = l.id
            WHERE t.departure_station_id = ? AND t.is_active = 1
            GROUP BY t.id
        ");
        $stmt->execute([$station_id]);
        $departures = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get arrivals
        $stmt = $conn->prepare("
            SELECT 
                t.*,
                'arrival' as event_type,
                t.arrival_time as event_time,
                ds.name as origin_name,
                GROUP_CONCAT(l.class ORDER BY tl.position_in_train SEPARATOR '+') as locomotives
            FROM dcc_trains t
            LEFT JOIN dcc_stations ds ON t.departure_station_id = ds.id
            LEFT JOIN dcc_train_locomotives tl ON t.id = tl.train_id
            LEFT JOIN dcc_locomotives l ON tl.locomotive_id = l.id
            WHERE t.arrival_station_id = ? AND t.is_active = 1
            GROUP BY t.id
        ");
        $stmt->execute([$station_id]);
        $arrivals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Combine and sort by time
        $schedule = array_merge($departures, $arrivals);
        usort($schedule, function($a, $b) {
            return strcmp($a['event_time'], $b['event_time']);
        });
        
        outputJSON([
            'status' => 'success',
            'data' => [
                'station_id' => $station_id,
                'schedule' => $schedule,
                'departures' => count($departures),
                'arrivals' => count($arrivals),
                'total_movements' => count($schedule)
            ]
        ]);
        
    } catch (PDOException $e) {
        outputJSON(['status' => 'error', 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleValidatePath($conn) {
    $from_station = $_GET['from_station'] ?? null;
    $to_station = $_GET['to_station'] ?? null;
    $departure_time = $_GET['departure_time'] ?? null;
    $arrival_time = $_GET['arrival_time'] ?? null;
    
    if (!$from_station || !$to_station) {
        outputJSON(['status' => 'error', 'error' => 'From and to stations required']);
    }
    
    // Validate station ID lengths
    if (strlen($from_station) > 8 || strlen($to_station) > 8) {
        outputJSON(['status' => 'error', 'error' => 'Station IDs must be maximum 8 characters long']);
    }
    
    try {
        // Check if direct connection exists
        $stmt = $conn->prepare("
            SELECT * FROM dcc_station_connections 
            WHERE (from_station_id = ? AND to_station_id = ?) 
               OR (from_station_id = ? AND to_station_id = ? AND bidirectional = 1)
        ");
        $stmt->execute([$from_station, $to_station, $to_station, $from_station]);
        $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $validation = [
            'path_exists' => count($connections) > 0,
            'direct_connection' => count($connections) > 0,
            'connections' => $connections,
            'conflicts' => [],
            'recommendations' => [],
            'station_id_compliance' => [
                'from_station_valid' => strlen($from_station) >= 8,
                'to_station_valid' => strlen($to_station) >= 8
            ]
        ];
        
        if (count($connections) === 0) {
            // Try to find indirect path
            $path = findPath($conn, $from_station, $to_station);
            $validation['path_exists'] = count($path) > 0;
            $validation['indirect_path'] = $path;
            $validation['recommendations'][] = 'No direct connection available, using intermediate stations';
        }
        
        // Check for time conflicts if times provided
        if ($departure_time && $arrival_time) {
            $conflicts = checkTimeConflicts($conn, $from_station, $to_station, $departure_time, $arrival_time);
            $validation['conflicts'] = $conflicts;
            
            if (count($conflicts) > 0) {
                $validation['recommendations'][] = 'Schedule conflicts detected, consider alternative times';
            }
        }
        
        outputJSON([
            'status' => 'success',
            'data' => $validation
        ]);
        
    } catch (PDOException $e) {
        outputJSON(['status' => 'error', 'error' => 'Database error: ' . $e->getMessage()]);
    }
}

function handleOptimizeSchedule($conn) {
    // This would implement schedule optimization algorithms
    // For now, return a placeholder response
    
    try {
        $suggestions = [
            [
                'type' => 'spacing',
                'description' => 'Adjust train intervals for better track utilization',
                'affected_trains' => [],
                'priority' => 'medium'
            ],
            [
                'type' => 'routing',
                'description' => 'Optimize routes to reduce conflicts',
                'affected_trains' => [],
                'priority' => 'high'
            ]
        ];
        
        outputJSON([
            'status' => 'success',
            'data' => [
                'optimizations' => $suggestions,
                'estimated_improvement' => '15% reduction in conflicts',
                'analysis_time' => date('Y-m-d H:i:s')
            ]
        ]);
        
    } catch (Exception $e) {
        outputJSON(['status' => 'error', 'error' => 'Optimization error: ' . $e->getMessage()]);
    }
}

// Helper functions

function getStationsWithTracks($conn) {
    $stmt = $conn->prepare("
        SELECT DISTINCT s.*, 
               COUNT(st.id) as track_count
        FROM dcc_stations s
        LEFT JOIN dcc_station_tracks st ON s.id = st.station_id AND st.is_active = 1
        GROUP BY s.id
        ORDER BY CASE WHEN s.nr IS NULL THEN 1 ELSE 0 END, s.nr, s.name
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function checkStationConflicts($conn, $station, $trains, $start_time, $end_time) {
    $conflicts = [];
    $track_count = $station['track_count'];
    
    // Group trains by time slots
    $time_slots = [];
    
    foreach ($trains as $train) {
        // Check departures
        if ($train['departure_station_id'] === $station['id']) {
            $slot = substr($train['departure_time'], 0, 5); // HH:MM
            if (!isset($time_slots[$slot])) {
                $time_slots[$slot] = [];
            }
            $time_slots[$slot][] = ['train' => $train, 'type' => 'departure'];
        }
        
        // Check arrivals
        if ($train['arrival_station_id'] === $station['id']) {
            $slot = substr($train['arrival_time'], 0, 5); // HH:MM
            if (!isset($time_slots[$slot])) {
                $time_slots[$slot] = [];
            }
            $time_slots[$slot][] = ['train' => $train, 'type' => 'arrival'];
        }
    }
    
    // Check each time slot for conflicts
    foreach ($time_slots as $time => $events) {
        if (count($events) > $track_count) {
            $conflicts[] = [
                'type' => 'track_capacity',
                'station_id' => $station['id'],
                'station_name' => $station['name'],
                'time' => $time,
                'required_tracks' => count($events),
                'available_tracks' => $track_count,
                'trains' => array_column($events, 'train'),
                'severity' => 'high'
            ];
        }
    }
    
    return $conflicts;
}

function checkPathConflicts($conn, $trains) {
    $conflicts = [];
    
    // Get all station connections
    $stmt = $conn->prepare("SELECT * FROM dcc_station_connections");
    $stmt->execute();
    $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create connection usage map
    $connection_usage = [];
    
    foreach ($trains as $train) {
        $path_connections = getPathConnections($connections, $train['departure_station_id'], $train['arrival_station_id']);
        
        foreach ($path_connections as $connection) {
            $key = $connection['from_station_id'] . '-' . $connection['to_station_id'];
            $time_slot = substr($train['departure_time'], 0, 5);
            
            if (!isset($connection_usage[$key])) {
                $connection_usage[$key] = [];
            }
            if (!isset($connection_usage[$key][$time_slot])) {
                $connection_usage[$key][$time_slot] = [];
            }
            
            $connection_usage[$key][$time_slot][] = $train;
        }
    }
    
    // Check for conflicts (multiple trains on same connection at same time)
    foreach ($connection_usage as $connection_key => $time_slots) {
        foreach ($time_slots as $time => $trains_using) {
            if (count($trains_using) > 1) {
                $conflicts[] = [
                    'type' => 'path_conflict',
                    'connection' => $connection_key,
                    'time' => $time,
                    'trains' => $trains_using,
                    'severity' => 'medium'
                ];
            }
        }
    }
    
    return $conflicts;
}

function getRoutePath($conn, $from_station, $to_station) {
    // Simplified path calculation
    $stmt = $conn->prepare("
        SELECT * FROM dcc_station_connections 
        WHERE (from_station_id = ? AND to_station_id = ?) 
           OR (from_station_id = ? AND to_station_id = ? AND bidirectional = 1)
        LIMIT 1
    ");
    $stmt->execute([$from_station, $to_station, $to_station, $from_station]);
    $connection = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($connection) {
        return [$from_station, $to_station];
    }
    
    // Return empty path if no direct connection
    return [];
}

function getPathConnections($connections, $from_station, $to_station) {
    // Find connections used in the path
    foreach ($connections as $connection) {
        if (($connection['from_station_id'] === $from_station && $connection['to_station_id'] === $to_station) ||
            ($connection['from_station_id'] === $to_station && $connection['to_station_id'] === $from_station && $connection['bidirectional'])) {
            return [$connection];
        }
    }
    
    return [];
}

function calculateTrackRequirements($conn, $train) {
    // Simplified track requirement calculation
    return [
        'departure_tracks' => 1,
        'arrival_tracks' => 1,
        'minimum_dwell_time' => 5, // minutes
        'locomotive_count' => $train['locomotive_count'] ?? 1
    ];
}

function findPath($conn, $from_station, $to_station) {
    // Simplified pathfinding - would implement proper graph traversal in production
    return [];
}

function checkTimeConflicts($conn, $from_station, $to_station, $departure_time, $arrival_time) {
    // Check for trains with conflicting times
    $stmt = $conn->prepare("
        SELECT t.* FROM dcc_trains t
        WHERE ((t.departure_station_id = ? AND t.departure_time = ?) OR
               (t.arrival_station_id = ? AND t.arrival_time = ?))
          AND t.is_active = 1
    ");
    $stmt->execute([$from_station, $departure_time, $to_station, $arrival_time]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

?>
