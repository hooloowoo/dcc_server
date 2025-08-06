<?php
/**
 * Route Validation and Scheduling API
 * Handles route validation, resource blocking, and schedule management for train creation
 */

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

class RouteValidator {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Validate if a route exists from departure to arrival station
     */
    public function validateRoute($departureStation, $arrivalStation) {
        // Check if both stations exist
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count 
            FROM dcc_stations 
            WHERE id IN (?, ?)
        ");
        $stmt->execute([$departureStation, $arrivalStation]);
        $stationCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($stationCount < 2) {
            return [
                'valid' => false,
                'error' => 'One or both stations do not exist',
                'route' => null
            ];
        }
        
        // Find route using Dijkstra's algorithm
        $route = $this->findShortestPath($departureStation, $arrivalStation);
        
        if (empty($route['path'])) {
            return [
                'valid' => false,
                'error' => 'No route exists between stations',
                'route' => null
            ];
        }
        
        return [
            'valid' => true,
            'route' => $route,
            'connections' => $this->getRouteConnections($route['path'])
        ];
    }
    
    /**
     * Check if station connections and tracks are free at specified times
     */
    public function checkAvailability($route, $departureTime, $arrivalTime, $excludeTrainId = null) {
        $conflicts = [];
        $occupiedResources = [];
        
        // Calculate travel times between stations
        $stationTimes = $this->calculateStationTimes($route, $departureTime, $arrivalTime);
        
        // Check each connection in the route
        for ($i = 0; $i < count($route['path']) - 1; $i++) {
            $fromStation = $route['path'][$i];
            $toStation = $route['path'][$i + 1];
            $timeFrom = $stationTimes[$i]['departure'];
            $timeTo = $stationTimes[$i + 1]['arrival'];
            
            // Check connection availability
            $connectionConflicts = $this->checkConnectionAvailability(
                $fromStation, $toStation, $timeFrom, $timeTo, $excludeTrainId
            );
            $conflicts = array_merge($conflicts, $connectionConflicts);
            
            // Check station track availability
            $stationConflicts = $this->checkStationTrackAvailability(
                $toStation, $timeTo, $stationTimes[$i + 1]['departure'] ?? $timeTo, $excludeTrainId
            );
            $conflicts = array_merge($conflicts, $stationConflicts);
        }
        
        return [
            'available' => empty($conflicts),
            'conflicts' => $conflicts,
            'station_times' => $stationTimes
        ];
    }
    
    /**
     * Block resources for a route and create schedule
     */
    public function blockResources($trainId, $route, $departureTime, $arrivalTime, $trainNumber) {
        $this->conn->beginTransaction();
        
        try {
            // Calculate station times
            $stationTimes = $this->calculateStationTimes($route, $departureTime, $arrivalTime);
            
            // Create timetable schedule
            $scheduleId = $this->createTimetableSchedule($trainId, $trainNumber, $route, $stationTimes);
            
            // Block connections and tracks
            $this->createResourceBlocks($scheduleId, $route, $stationTimes);
            
            $this->conn->commit();
            
            return [
                'success' => true,
                'schedule_id' => $scheduleId,
                'station_times' => $stationTimes
            ];
            
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }
    
    /**
     * Find shortest path using Dijkstra's algorithm
     */
    private function findShortestPath($start, $end) {
        // Get all active connections
        $stmt = $this->conn->prepare("
            SELECT from_station_id, to_station_id, distance_km, bidirectional
            FROM dcc_station_connections 
            WHERE is_active = 1
        ");
        $stmt->execute();
        $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build adjacency graph
        $graph = [];
        foreach ($connections as $conn) {
            $from = $conn['from_station_id'];
            $to = $conn['to_station_id'];
            $distance = floatval($conn['distance_km']);
            
            if (!isset($graph[$from])) $graph[$from] = [];
            $graph[$from][] = ['station' => $to, 'distance' => $distance];
            
            if ($conn['bidirectional']) {
                if (!isset($graph[$to])) $graph[$to] = [];
                $graph[$to][] = ['station' => $from, 'distance' => $distance];
            }
        }
        
        // Dijkstra's algorithm
        $distances = [];
        $previous = [];
        $unvisited = [];
        
        // Initialize
        $stmt = $this->conn->prepare("SELECT id FROM dcc_stations");
        $stmt->execute();
        $stations = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($stations as $station) {
            $distances[$station] = PHP_FLOAT_MAX;
            $previous[$station] = null;
            $unvisited[$station] = true;
        }
        
        $distances[$start] = 0;
        
        while (!empty($unvisited)) {
            // Find unvisited station with minimum distance
            $current = null;
            $minDistance = PHP_FLOAT_MAX;
            
            foreach ($unvisited as $station => $value) {
                if ($distances[$station] < $minDistance) {
                    $minDistance = $distances[$station];
                    $current = $station;
                }
            }
            
            if ($current === null || $distances[$current] === PHP_FLOAT_MAX) {
                break; // No path found
            }
            
            unset($unvisited[$current]);
            
            if ($current === $end) {
                break; // Found destination
            }
            
            // Update distances to neighbors
            if (isset($graph[$current])) {
                foreach ($graph[$current] as $neighbor) {
                    $neighborStation = $neighbor['station'];
                    if (isset($unvisited[$neighborStation])) {
                        $newDistance = $distances[$current] + $neighbor['distance'];
                        if ($newDistance < $distances[$neighborStation]) {
                            $distances[$neighborStation] = $newDistance;
                            $previous[$neighborStation] = $current;
                        }
                    }
                }
            }
        }
        
        // Reconstruct path
        $path = [];
        $current = $end;
        
        while ($current !== null) {
            array_unshift($path, $current);
            $current = $previous[$current];
        }
        
        if (empty($path) || $path[0] !== $start) {
            return ['path' => [], 'distance' => 0];
        }
        
        return [
            'path' => $path,
            'distance' => $distances[$end]
        ];
    }
    
    /**
     * Get connection details for route
     */
    private function getRouteConnections($path) {
        $connections = [];
        
        for ($i = 0; $i < count($path) - 1; $i++) {
            $from = $path[$i];
            $to = $path[$i + 1];
            
            $stmt = $this->conn->prepare("
                SELECT c.*, 
                       fs.name as from_station_name,
                       ts.name as to_station_name
                FROM dcc_station_connections c
                JOIN dcc_stations fs ON c.from_station_id = fs.id
                JOIN dcc_stations ts ON c.to_station_id = ts.id
                WHERE ((c.from_station_id = ? AND c.to_station_id = ?) OR
                       (c.from_station_id = ? AND c.to_station_id = ? AND c.bidirectional = 1))
                  AND c.is_active = 1
                LIMIT 1
            ");
            $stmt->execute([$from, $to, $to, $from]);
            $connection = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($connection) {
                $connections[] = $connection;
            }
        }
        
        return $connections;
    }
    
    /**
     * Calculate arrival and departure times for each station
     */
    private function calculateStationTimes($route, $departureTime, $arrivalTime, $maxSpeed = 80) {
        $stationTimes = [];
        $connections = $this->getRouteConnections($route['path']);
        
        // Validate input parameters
        if (!$route || !isset($route['path']) || !is_array($route['path']) || empty($route['path'])) {
            return $stationTimes;
        }
        
        // Convert departure time to minutes
        $depMinutes = $this->timeToMinutes($departureTime);
        $currentTime = $depMinutes;
        
        // Add first station (origin)
        $stationTimes[] = [
            'station_id' => $route['path'][0],
            'arrival' => $this->minutesToTime($currentTime),
            'departure' => $this->minutesToTime($currentTime),
            'dwell_time' => 0
        ];
        
        // Calculate times for each segment using actual distances and realistic speeds
        for ($i = 0; $i < count($connections); $i++) {
            $connection = $connections[$i];
            $segment_distance_km = floatval($connection['distance_km']);
            
            // Use train's max speed, but respect track speed limits if they are lower
            $track_speed_limit = intval($connection['track_speed_limit'] ?? 80);
            
            // Use the lower of train max speed or track speed limit, but ensure minimum of 30 km/h for realistic calculation
            $segment_max_speed = max(30, min($maxSpeed, $track_speed_limit));
            
            // Calculate realistic travel time: time = distance / speed * 60 (for minutes)
            $segment_time_minutes = ($segment_distance_km / $segment_max_speed) * 60;
            
            // Validate calculated time
            if (!is_finite($segment_time_minutes) || $segment_time_minutes < 0) {
                $segment_time_minutes = 1; // Minimum 1 minute
            }
            
            $currentTime += $segment_time_minutes;
            
            // Add dwell time (2 minutes for intermediate stations, 0 for final)
            $dwellTime = ($i === count($connections) - 1) ? 0 : 2;
            
            $stationTimes[] = [
                'station_id' => $route['path'][$i + 1],
                'arrival' => $this->minutesToTime($currentTime),
                'departure' => $this->minutesToTime($currentTime + $dwellTime),
                'dwell_time' => $dwellTime
            ];
            
            $currentTime += $dwellTime;
        }
        
        return $stationTimes;
    }
    
    /**
     * Check if connection is available at specified time
     */
    private function checkConnectionAvailability($fromStation, $toStation, $timeFrom, $timeTo, $excludeTrainId) {
        // Find all trains that use this specific connection
        $conflicts = [];
        
        // Method 1: Check trains with direct routes on this connection
        $stmt = $this->conn->prepare("
            SELECT t.id, t.train_number, t.train_name, t.departure_time, t.arrival_time,
                   t.departure_station_id, t.arrival_station_id
            FROM dcc_trains t
            WHERE t.is_active = 1
              AND (t.id != ? OR ? IS NULL)
              AND (
                  (t.departure_station_id = ? AND t.arrival_station_id = ?) OR
                  (t.departure_station_id = ? AND t.arrival_station_id = ?)
              )
        ");
        
        $stmt->execute([
            $excludeTrainId, $excludeTrainId,
            $fromStation, $toStation,
            $toStation, $fromStation
        ]);
        
        $directTrains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($directTrains as $train) {
            // Check if there's a time overlap
            $trainStart = $train['departure_time'];
            $trainEnd = $train['arrival_time'];
            
            // Convert times to comparable format (assuming same day)
            if ($this->timesOverlap($timeFrom, $timeTo, $trainStart, $trainEnd)) {
                $conflicts[] = [
                    'type' => 'connection_conflict',
                    'from_station' => $fromStation,
                    'to_station' => $toStation,
                    'time_from' => $timeFrom,
                    'time_to' => $timeTo,
                    'conflicting_train' => $train['train_number'],
                    'train_name' => $train['train_name'],
                    'train_times' => "$trainStart - $trainEnd",
                    'severity' => 'high'
                ];
            }
        }
        
        // Method 2: Check multi-hop trains that pass through this connection
        // Look for trains that have this connection as part of their calculated route
        $stmt = $this->conn->prepare("
            SELECT DISTINCT t.id, t.train_number, t.train_name, t.departure_time, t.arrival_time,
                   t.departure_station_id, t.arrival_station_id
            FROM dcc_trains t
            WHERE t.is_active = 1
              AND (t.id != ? OR ? IS NULL)
              AND t.departure_station_id != t.arrival_station_id
              AND t.departure_station_id IS NOT NULL
              AND t.arrival_station_id IS NOT NULL
        ");
        
        $stmt->execute([$excludeTrainId, $excludeTrainId]);
        $multiHopTrains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($multiHopTrains as $train) {
            // Calculate if this train's route uses our connection
            $trainRoute = $this->calculateTrainRoute($train['departure_station_id'], $train['arrival_station_id']);
            
            if ($this->routeUsesConnection($trainRoute, $fromStation, $toStation)) {
                // Calculate when this train uses this specific connection
                $connectionTimes = $this->calculateConnectionUsageTime(
                    $trainRoute, 
                    $fromStation, 
                    $toStation, 
                    $train['departure_time'], 
                    $train['arrival_time']
                );
                
                if ($connectionTimes && $this->timesOverlap($timeFrom, $timeTo, $connectionTimes['start'], $connectionTimes['end'])) {
                    $conflicts[] = [
                        'type' => 'connection_conflict',
                        'from_station' => $fromStation,
                        'to_station' => $toStation,
                        'time_from' => $timeFrom,
                        'time_to' => $timeTo,
                        'conflicting_train' => $train['train_number'],
                        'train_name' => $train['train_name'],
                        'train_times' => $connectionTimes['start'] . " - " . $connectionTimes['end'],
                        'route_type' => 'multi_hop',
                        'severity' => 'high'
                    ];
                }
            }
        }
        
        return $conflicts;
    }
    
    /**
     * Check if two time ranges overlap
     */
    private function timesOverlap($start1, $end1, $start2, $end2) {
        // Convert times to minutes for easier comparison
        $start1_min = $this->timeToMinutes($start1);
        $end1_min = $this->timeToMinutes($end1);
        $start2_min = $this->timeToMinutes($start2);
        $end2_min = $this->timeToMinutes($end2);
        
        // Handle overnight times
        if ($end1_min < $start1_min) $end1_min += 24 * 60;
        if ($end2_min < $start2_min) $end2_min += 24 * 60;
        
        // Check for overlap: ranges overlap if start1 < end2 AND start2 < end1
        return ($start1_min < $end2_min) && ($start2_min < $end1_min);
    }
    
    /**
     * Check if a route uses a specific connection
     */
    private function routeUsesConnection($route, $fromStation, $toStation) {
        if (!$route || !isset($route['path']) || !is_array($route['path'])) {
            return false;
        }
        
        $path = $route['path'];
        for ($i = 0; $i < count($path) - 1; $i++) {
            $segmentFrom = $path[$i];
            $segmentTo = $path[$i + 1];
            
            // Check both directions since connections can be bidirectional
            if (($segmentFrom === $fromStation && $segmentTo === $toStation) ||
                ($segmentFrom === $toStation && $segmentTo === $fromStation)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Calculate the route for a train
     */
    private function calculateTrainRoute($departureStation, $arrivalStation) {
        return $this->findShortestPath($departureStation, $arrivalStation);
    }
    
    /**
     * Calculate when a train uses a specific connection
     */
    private function calculateConnectionUsageTime($route, $fromStation, $toStation, $departureTime, $arrivalTime) {
        if (!$route || !isset($route['path'])) {
            return null;
        }
        
        $path = $route['path'];
        $stationTimes = $this->calculateStationTimes($route, $departureTime, $arrivalTime);
        
        // Find the connection in the path
        for ($i = 0; $i < count($path) - 1; $i++) {
            $segmentFrom = $path[$i];
            $segmentTo = $path[$i + 1];
            
            if (($segmentFrom === $fromStation && $segmentTo === $toStation) ||
                ($segmentFrom === $toStation && $segmentTo === $fromStation)) {
                
                // Ensure we have valid station times
                if (!isset($stationTimes[$i]) || !isset($stationTimes[$i + 1])) {
                    return null;
                }
                
                // Return the time this train occupies this connection
                // Train departs from first station and arrives at second station
                return [
                    'start' => $stationTimes[$i]['departure'],
                    'end' => $stationTimes[$i + 1]['arrival']
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Check station track availability
     */
    private function checkStationTrackAvailability($stationId, $arrivalTime, $departureTime, $excludeTrainId) {
        // Get available tracks at station
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as track_count
            FROM dcc_station_tracks 
            WHERE station_id = ? AND is_active = 1
        ");
        $stmt->execute([$stationId]);
        $trackCount = $stmt->fetch(PDO::FETCH_ASSOC)['track_count'];
        
        if ($trackCount == 0) {
            return [[
                'type' => 'no_tracks',
                'station_id' => $stationId,
                'severity' => 'critical'
            ]];
        }
        
        // Check concurrent train usage
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as concurrent_trains
            FROM dcc_trains t
            WHERE t.is_active = 1
              AND (t.id != ? OR ? IS NULL)
              AND (
                  (t.departure_station_id = ? AND t.departure_time BETWEEN ? AND ?) OR
                  (t.arrival_station_id = ? AND t.arrival_time BETWEEN ? AND ?)
              )
        ");
        
        $stmt->execute([
            $excludeTrainId, $excludeTrainId,
            $stationId, $arrivalTime, $departureTime,
            $stationId, $arrivalTime, $departureTime
        ]);
        
        $concurrentTrains = $stmt->fetch(PDO::FETCH_ASSOC)['concurrent_trains'];
        
        if ($concurrentTrains >= $trackCount) {
            return [[
                'type' => 'track_capacity_exceeded',
                'station_id' => $stationId,
                'required_tracks' => $concurrentTrains + 1,
                'available_tracks' => $trackCount,
                'severity' => 'high'
            ]];
        }
        
        return [];
    }
    
    /**
     * Create timetable schedule
     */
    private function createTimetableSchedule($trainId, $trainNumber, $route, $stationTimes) {
        // Check if timetable_schedules table exists, if not use simplified approach
        $stmt = $this->conn->prepare("SHOW TABLES LIKE 'timetable_schedules'");
        $stmt->execute();
        $tableExists = $stmt->fetch();
        
        if ($tableExists) {
            // Create schedule entry
            $stmt = $this->conn->prepare("
                INSERT INTO timetable_schedules (
                    train_number, schedule_name, schedule_type, frequency,
                    effective_date, route_description, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $routeDescription = implode(' → ', $route['path']);
            $scheduleName = "Schedule for Train {$trainNumber}";
            
            $stmt->execute([
                $trainNumber,
                $scheduleName,
                'regular',
                'daily',
                date('Y-m-d'),
                $routeDescription,
                1
            ]);
            
            $scheduleId = $this->conn->lastInsertId();
            
            // Create stops
            foreach ($stationTimes as $index => $stationTime) {
                $stopType = 'intermediate';
                if ($index === 0) $stopType = 'origin';
                if ($index === count($stationTimes) - 1) $stopType = 'destination';
                
                $stmt = $this->conn->prepare("
                    INSERT INTO timetable_stops (
                        schedule_id, station_id, stop_sequence, arrival_time, departure_time,
                        stop_type, dwell_time_minutes
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $scheduleId,
                    $stationTime['station_id'],
                    $index + 1,
                    $stationTime['arrival'],
                    $stationTime['departure'],
                    $stopType,
                    $stationTime['dwell_time']
                ]);
            }
            
            return $scheduleId;
        } else {
            // Fallback: return a generated ID
            return time(); // Use timestamp as pseudo-schedule ID
        }
    }
    
    /**
     * Create resource blocks (track occupancy records)
     */
    private function createResourceBlocks($scheduleId, $route, $stationTimes) {
        // Check if track occupancy table exists
        $stmt = $this->conn->prepare("SHOW TABLES LIKE 'dcc_track_occupancy'");
        $stmt->execute();
        $tableExists = $stmt->fetch();
        
        if (!$tableExists) {
            return; // Skip if table doesn't exist
        }
        
        // Create occupancy records for each station stop
        foreach ($stationTimes as $stationTime) {
            // Get available tracks for this station
            $stmt = $this->conn->prepare("
                SELECT id FROM dcc_station_tracks 
                WHERE station_id = ? AND is_active = 1 
                LIMIT 1
            ");
            $stmt->execute([$stationTime['station_id']]);
            $track = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($track) {
                $stmt = $this->conn->prepare("
                    INSERT INTO dcc_track_occupancy (
                        station_id, track_id, schedule_id, stop_id,
                        occupied_from, occupied_until, occupancy_type, is_confirmed
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $occupiedFrom = date('Y-m-d') . ' ' . $stationTime['arrival'];
                $occupiedUntil = date('Y-m-d') . ' ' . $stationTime['departure'];
                
                $stmt->execute([
                    $stationTime['station_id'],
                    $track['id'],
                    $scheduleId, // Using schedule ID as instance ID
                    $scheduleId, // Using schedule ID as stop ID
                    $occupiedFrom,
                    $occupiedUntil,
                    'layover',
                    1
                ]);
            }
        }
    }
    
    /**
     * Helper: Convert time string to minutes
     */
    private function timeToMinutes($timeStr) {
        // Validate input
        if (!$timeStr || !is_string($timeStr)) {
            return 0;
        }
        
        $parts = explode(':', $timeStr);
        
        // Validate time format
        if (count($parts) < 2) {
            return 0;
        }
        
        $hours = intval($parts[0]);
        $minutes = intval($parts[1]);
        
        // Validate time values
        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return 0;
        }
        
        return $hours * 60 + $minutes;
    }
    
    /**
     * Helper: Convert minutes to time string
     */
    private function minutesToTime($minutes) {
        // Validate input to prevent invalid time calculations
        if (!is_finite($minutes) || is_nan($minutes)) {
            return '00:00:00';
        }
        
        // Ensure minutes is a positive number
        $minutes = max(0, $minutes);
        
        $hours = floor($minutes / 60) % 24;
        $mins = $minutes % 60;
        return sprintf('%02d:%02d:00', $hours, $mins);
    }
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $action = $_GET['action'] ?? '';
    
    // Most actions require authentication
    if (in_array($action, ['validate', 'check_availability', 'block_resources'])) {
        $user = getCurrentUser($conn);
        if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
            outputJSON(['status' => 'error', 'error' => 'Authentication required']);
        }
    }
    
    $validator = new RouteValidator($conn);
    
    switch ($action) {
        case 'validate_route':
            $departureStation = $_GET['departure_station'] ?? null;
            $arrivalStation = $_GET['arrival_station'] ?? null;
            
            if (!$departureStation || !$arrivalStation) {
                outputJSON(['status' => 'error', 'error' => 'Both departure and arrival stations are required']);
            }
            
            $result = $validator->validateRoute($departureStation, $arrivalStation);
            outputJSON(['status' => 'success', 'data' => $result]);
            break;
            
        case 'check_availability':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['route']) || !isset($input['departure_time']) || !isset($input['arrival_time'])) {
                outputJSON(['status' => 'error', 'error' => 'Route, departure_time, and arrival_time are required']);
            }
            
            $result = $validator->checkAvailability(
                $input['route'],
                $input['departure_time'],
                $input['arrival_time'],
                $input['exclude_train_id'] ?? null
            );
            
            outputJSON(['status' => 'success', 'data' => $result]);
            break;
            
        case 'block_resources':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($input['train_id']) || !isset($input['route']) || 
                !isset($input['departure_time']) || !isset($input['arrival_time']) ||
                !isset($input['train_number'])) {
                outputJSON(['status' => 'error', 'error' => 'train_id, route, departure_time, arrival_time, and train_number are required']);
            }
            
            $result = $validator->blockResources(
                $input['train_id'],
                $input['route'],
                $input['departure_time'],
                $input['arrival_time'],
                $input['train_number']
            );
            
            outputJSON(['status' => 'success', 'data' => $result]);
            break;
            
        default:
            outputJSON(['status' => 'error', 'error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    outputJSON(['status' => 'error', 'error' => $e->getMessage()]);
}
?>
