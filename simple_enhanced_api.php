<?php
/**
 * Simple Enhanced Train Creator API 
 * Uses the working collision_detection_api.php for validation
 */

require_once 'config.php';
require_once 'auth_utils.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

function outputJSON($data) {
    echo json_encode($data);
    exit(0);
}

/**
 * Simple route validation using the working collision detection
 */
function validateRoute($conn, $departureStation, $arrivalStation) {
    // Use Dijkstra's algorithm for route calculation (simplified from trains_api.php)
    $stmt = $conn->prepare("
        SELECT from_station_id, to_station_id, distance_km, bidirectional, track_speed_limit
        FROM dcc_station_connections 
        WHERE is_active = 1
    ");
    $stmt->execute();
    $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build adjacency list
    $graph = [];
    $connectionMap = [];
    
    foreach ($connections as $conn_data) {
        $from = $conn_data['from_station_id'];
        $to = $conn_data['to_station_id'];
        $distance = floatval($conn_data['distance_km']);
        
        if (!isset($graph[$from])) $graph[$from] = [];
        $graph[$from][] = ['station' => $to, 'distance' => $distance];
        $connectionMap[$from . '-' . $to] = $conn_data;
        
        if ($conn_data['bidirectional']) {
            if (!isset($graph[$to])) $graph[$to] = [];
            $graph[$to][] = ['station' => $from, 'distance' => $distance];
            $reverseConn = $conn_data;
            $reverseConn['from_station_id'] = $to;
            $reverseConn['to_station_id'] = $from;
            $connectionMap[$to . '-' . $from] = $reverseConn;
        }
    }
    
    // Find path using simplified Dijkstra
    $distances = [];
    $previous = [];
    $unvisited = [];
    
    $stationStmt = $conn->prepare("SELECT id FROM dcc_stations");
    $stationStmt->execute();
    $stations = $stationStmt->fetchAll(PDO::FETCH_COLUMN);
    
    foreach ($stations as $station) {
        $distances[$station] = PHP_FLOAT_MAX;
        $previous[$station] = null;
        $unvisited[$station] = true;
    }
    
    if (!isset($distances[$departureStation]) || !isset($distances[$arrivalStation])) {
        return ['valid' => false, 'error' => 'One or both stations not found'];
    }
    
    $distances[$departureStation] = 0;
    
    while (!empty($unvisited)) {
        $current = null;
        $minDistance = PHP_FLOAT_MAX;
        foreach ($unvisited as $station => $ignored) {
            if ($distances[$station] < $minDistance) {
                $minDistance = $distances[$station];
                $current = $station;
            }
        }
        
        if ($current === null || $distances[$current] === PHP_FLOAT_MAX) {
            break;
        }
        
        unset($unvisited[$current]);
        
        if ($current === $arrivalStation) {
            break;
        }
        
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
    
    if ($distances[$arrivalStation] === PHP_FLOAT_MAX) {
        return ['valid' => false, 'error' => 'No route found between stations'];
    }
    
    // Reconstruct path
    $path = [];
    $current = $arrivalStation;
    while ($current !== null) {
        array_unshift($path, $current);
        $current = $previous[$current];
    }
    
    // Build connections
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
        'valid' => true,
        'route' => [
            'success' => true,
            'path' => $path,
            'total_distance' => $distances[$arrivalStation],
            'connections' => $routeConnections
        ]
    ];
}

/**
 * Check collision using the working collision detection from trains_api.php
 */
function checkCollision($conn, $departureStation, $arrivalStation, $departureTime, $arrivalTime) {
    // This uses the same proven logic from trains_api.php that we know works
    
    // Get route first
    $routeResult = validateRoute($conn, $departureStation, $arrivalStation);
    if (!$routeResult['valid']) {
        return ['available' => false, 'conflicts' => [], 'error' => $routeResult['error']];
    }
    
    $route = $routeResult['route'];
    
    // Calculate station times (simplified but realistic)
    $depMinutes = timeToMinutes($departureTime);
    $arrMinutes = timeToMinutes($arrivalTime);
    $totalTravelTime = $arrMinutes - $depMinutes;
    if ($totalTravelTime <= 0) $totalTravelTime += 24 * 60;
    
    $stationTimes = [];
    $currentTime = $depMinutes;
    $pathLength = count($route['path']);
    
    for ($i = 0; $i < $pathLength; $i++) {
        if ($i === 0) {
            $stationTimes[] = [
                'station_id' => $route['path'][$i],
                'arrival' => minutesToTime($currentTime),
                'departure' => minutesToTime($currentTime)
            ];
        } else {
            $segmentTime = $totalTravelTime / ($pathLength - 1);
            $currentTime += $segmentTime;
            $dwellTime = ($i === $pathLength - 1) ? 0 : 2;
            
            $stationTimes[] = [
                'station_id' => $route['path'][$i],
                'arrival' => minutesToTime($currentTime),
                'departure' => minutesToTime($currentTime + $dwellTime)
            ];
            $currentTime += $dwellTime;
        }
    }
    
    // Check conflicts with existing trains
    $conflicts = [];
    $stmt = $conn->prepare("
        SELECT t.id, t.train_number, t.train_name, t.departure_station_id, t.arrival_station_id, 
               t.departure_time, t.arrival_time
        FROM dcc_trains t
        WHERE t.is_active = 1 
          AND t.departure_station_id IS NOT NULL 
          AND t.arrival_station_id IS NOT NULL
          AND t.departure_time IS NOT NULL 
          AND t.arrival_time IS NOT NULL
    ");
    $stmt->execute();
    $existingTrains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($existingTrains as $train) {
        $existingRouteResult = validateRoute($conn, $train['departure_station_id'], $train['arrival_station_id']);
        if (!$existingRouteResult['valid']) continue;
        
        $existingRoute = $existingRouteResult['route'];
        
        // Calculate existing train station times
        $existingDepMinutes = timeToMinutes($train['departure_time']);
        $existingArrMinutes = timeToMinutes($train['arrival_time']);
        $existingTotalTime = $existingArrMinutes - $existingDepMinutes;
        if ($existingTotalTime <= 0) $existingTotalTime += 24 * 60;
        
        $existingStationTimes = [];
        $existingCurrentTime = $existingDepMinutes;
        $existingPathLength = count($existingRoute['path']);
        
        for ($i = 0; $i < $existingPathLength; $i++) {
            if ($i === 0) {
                $existingStationTimes[] = [
                    'departure' => minutesToTime($existingCurrentTime)
                ];
            } else {
                $existingSegmentTime = $existingTotalTime / ($existingPathLength - 1);
                $existingCurrentTime += $existingSegmentTime;
                $existingDwellTime = ($i === $existingPathLength - 1) ? 0 : 2;
                
                $existingStationTimes[] = [
                    'arrival' => minutesToTime($existingCurrentTime),
                    'departure' => minutesToTime($existingCurrentTime + $existingDwellTime)
                ];
                $existingCurrentTime += $existingDwellTime;
            }
        }
        
        // Check for segment conflicts
        for ($i = 0; $i < count($route['path']) - 1; $i++) {
            $ourFromStation = $route['path'][$i];
            $ourToStation = $route['path'][$i + 1];
            $ourStartTime = $stationTimes[$i]['departure'];
            $ourEndTime = $stationTimes[$i + 1]['arrival'];
            
            for ($j = 0; $j < count($existingRoute['path']) - 1; $j++) {
                $theirFromStation = $existingRoute['path'][$j];
                $theirToStation = $existingRoute['path'][$j + 1];
                
                if (!isset($existingStationTimes[$j]) || !isset($existingStationTimes[$j + 1])) continue;
                
                $theirStartTime = $existingStationTimes[$j]['departure'];
                $theirEndTime = $existingStationTimes[$j + 1]['arrival'];
                
                // Check same segment (bidirectional)
                $sameSegment = ($ourFromStation === $theirFromStation && $ourToStation === $theirToStation) ||
                              ($ourFromStation === $theirToStation && $ourToStation === $theirFromStation);
                
                if ($sameSegment && timesOverlap($ourStartTime, $ourEndTime, $theirStartTime, $theirEndTime)) {
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
    
    return [
        'available' => empty($conflicts),
        'conflicts' => $conflicts,
        'station_times' => $stationTimes
    ];
}

function timeToMinutes($timeStr) {
    if (!$timeStr) return 0;
    $timeStr = preg_replace('/(\d{2}:\d{2}):\d{2}/', '$1', $timeStr);
    $parts = explode(':', $timeStr);
    return count($parts) >= 2 ? intval($parts[0]) * 60 + intval($parts[1]) : 0;
}

function minutesToTime($minutes) {
    $hours = floor($minutes / 60) % 24;
    $mins = $minutes % 60;
    return sprintf('%02d:%02d:00', $hours, $mins);
}

function timesOverlap($start1, $end1, $start2, $end2) {
    $start1_min = timeToMinutes($start1);
    $end1_min = timeToMinutes($end1);
    $start2_min = timeToMinutes($start2);
    $end2_min = timeToMinutes($end2);
    
    if ($end1_min < $start1_min) $end1_min += 24 * 60;
    if ($end2_min < $start2_min) $end2_min += 24 * 60;
    
    return ($start1_min < $end2_min) && ($start2_min < $end1_min);
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $action = $_GET['action'] ?? 'validate_only';
    
    // Require authentication
    $user = getCurrentUser($conn);
    if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
        outputJSON(['status' => 'error', 'error' => 'Authentication required']);
    }
    
    switch ($action) {
        case 'validate_only':
            $departureStation = $_GET['departure_station'] ?? null;
            $arrivalStation = $_GET['arrival_station'] ?? null;
            $departureTime = $_GET['departure_time'] ?? null;
            $arrivalTime = $_GET['arrival_time'] ?? null;
            
            if (!$departureStation || !$arrivalStation || !$departureTime) {
                outputJSON(['status' => 'error', 'error' => 'Missing required parameters']);
            }
            
            // Validate route
            $routeValidation = validateRoute($conn, $departureStation, $arrivalStation);
            if (!$routeValidation['valid']) {
                outputJSON(['status' => 'error', 'error' => $routeValidation['error']]);
            }
            
            // Calculate arrival time if not provided
            if (!$arrivalTime) {
                $total_travel_time_minutes = 0;
                $route_connections = $routeValidation['route']['connections'] ?? [];
                
                foreach ($route_connections as $connection) {
                    $segment_distance_km = floatval($connection['distance_km']);
                    $segment_max_speed = 50; // Default speed
                    $segment_time_minutes = ($segment_distance_km / $segment_max_speed) * 60;
                    if ($connection['to_station_id'] !== $arrivalStation) {
                        $segment_time_minutes += 2; // Dwell time
                    }
                    $total_travel_time_minutes += $segment_time_minutes;
                }
                
                $depTime = DateTime::createFromFormat('H:i', $departureTime);
                if ($depTime) {
                    $travel_minutes = round($total_travel_time_minutes);
                    $depTime->add(new DateInterval('PT' . $travel_minutes . 'M'));
                    $arrivalTime = $depTime->format('H:i');
                } else {
                    outputJSON(['status' => 'error', 'error' => 'Invalid departure time format']);
                }
            }
            
            // Check availability
            $availabilityCheck = checkCollision($conn, $departureStation, $arrivalStation, $departureTime, $arrivalTime);
            
            outputJSON([
                'status' => 'success',
                'data' => [
                    'route_valid' => true,
                    'route' => $routeValidation['route'],
                    'availability' => $availabilityCheck
                ]
            ]);
            break;
            
        default:
            outputJSON(['status' => 'error', 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    outputJSON(['status' => 'error', 'error' => $e->getMessage()]);
}
?>
