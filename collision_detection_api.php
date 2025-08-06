<?php
/**
 * Collision Detection API
 * Comprehensive collision detection for train operations
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

class CollisionDetector {
    private $conn;
    
    public function __construct($connection) {
        $this->conn = $connection;
    }
    
    /**
     * Check for all potential collisions in the system
     */
    public function checkAllCollisions() {
        $collisions = [];
        
        // Get all active trains
        $stmt = $this->conn->prepare("
            SELECT id, train_number, train_name, departure_station_id, arrival_station_id, 
                   departure_time, arrival_time
            FROM dcc_trains 
            WHERE is_active = 1 
            AND departure_station_id IS NOT NULL 
            AND arrival_station_id IS NOT NULL
            AND departure_time IS NOT NULL 
            AND arrival_time IS NOT NULL
        ");
        $stmt->execute();
        $trains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check each pair of trains for collisions
        for ($i = 0; $i < count($trains); $i++) {
            for ($j = $i + 1; $j < count($trains); $j++) {
                $collision = $this->checkTrainCollision($trains[$i], $trains[$j]);
                if ($collision) {
                    $collisions[] = $collision;
                }
            }
        }
        
        return $collisions;
    }
    
    /**
     * Check for collision between two specific trains
     */
    public function checkTrainCollision($train1, $train2) {
        // Calculate routes for both trains
        $route1 = $this->calculateRoute($train1['departure_station_id'], $train1['arrival_station_id']);
        $route2 = $this->calculateRoute($train2['departure_station_id'], $train2['arrival_station_id']);
        
        if (!$route1 || !$route2) {
            return null; // Can't calculate routes
        }
        
        // Calculate station times for both trains
        $times1 = $this->calculateStationTimes($route1, $train1['departure_time'], $train1['arrival_time']);
        $times2 = $this->calculateStationTimes($route2, $train2['departure_time'], $train2['arrival_time']);
        
        // Check for connection conflicts
        $connectionConflicts = $this->findConnectionConflicts($route1, $times1, $route2, $times2);
        
        if (!empty($connectionConflicts)) {
            return [
                'type' => 'connection_collision',
                'train1' => [
                    'id' => $train1['id'],
                    'number' => $train1['train_number'],
                    'name' => $train1['train_name']
                ],
                'train2' => [
                    'id' => $train2['id'],
                    'number' => $train2['train_number'],
                    'name' => $train2['train_name']
                ],
                'conflicts' => $connectionConflicts,
                'severity' => 'critical'
            ];
        }
        
        return null;
    }
    
    /**
     * Find connection conflicts between two train routes
     */
    private function findConnectionConflicts($route1, $times1, $route2, $times2) {
        $conflicts = [];
        
        // Check each connection in route1 against each connection in route2
        for ($i = 0; $i < count($route1['path']) - 1; $i++) {
            $conn1_from = $route1['path'][$i];
            $conn1_to = $route1['path'][$i + 1];
            $conn1_start = $times1[$i]['departure'];
            $conn1_end = $times1[$i + 1]['arrival'];
            
            for ($j = 0; $j < count($route2['path']) - 1; $j++) {
                $conn2_from = $route2['path'][$j];
                $conn2_to = $route2['path'][$j + 1];
                $conn2_start = $times2[$j]['departure'];
                $conn2_end = $times2[$j + 1]['arrival'];
                
                // Check if same connection (including reverse direction)
                if (($conn1_from === $conn2_from && $conn1_to === $conn2_to) ||
                    ($conn1_from === $conn2_to && $conn1_to === $conn2_from)) {
                    
                    // Check for time overlap
                    if ($this->timesOverlap($conn1_start, $conn1_end, $conn2_start, $conn2_end)) {
                        $conflicts[] = [
                            'connection' => "$conn1_from → $conn1_to",
                            'train1_time' => "$conn1_start - $conn1_end",
                            'train2_time' => "$conn2_start - $conn2_end",
                            'overlap_detected' => true
                        ];
                    }
                }
            }
        }
        
        return $conflicts;
    }
    
    /**
     * Calculate route between stations
     */
    private function calculateRoute($fromStation, $toStation) {
        // Get all active connections
        $stmt = $this->conn->prepare("
            SELECT from_station_id, to_station_id, distance_km, bidirectional, track_speed_limit
            FROM dcc_station_connections 
            WHERE is_active = 1
        ");
        $stmt->execute();
        $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Build graph
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
        
        // Use Dijkstra's algorithm to find shortest path
        return $this->findShortestPath($graph, $fromStation, $toStation);
    }
    
    /**
     * Dijkstra's algorithm implementation
     */
    private function findShortestPath($graph, $start, $end) {
        $distances = [];
        $previous = [];
        $unvisited = [];
        
        // Get all stations
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
            
            if ($current === $end) {
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
        
        if ($distances[$end] === PHP_FLOAT_MAX) {
            return null;
        }
        
        // Reconstruct path
        $path = [];
        $current = $end;
        while ($current !== null) {
            array_unshift($path, $current);
            $current = $previous[$current];
        }
        
        return [
            'path' => $path,
            'distance' => $distances[$end]
        ];
    }
    
    /**
     * Calculate station times for a route
     */
    private function calculateStationTimes($route, $departureTime, $arrivalTime) {
        $stationTimes = [];
        
        if (!$route || !isset($route['path']) || count($route['path']) < 2) {
            return $stationTimes;
        }
        
        $depMinutes = $this->timeToMinutes($departureTime);
        $arrMinutes = $this->timeToMinutes($arrivalTime);
        $totalTravelTime = $arrMinutes - $depMinutes;
        
        if ($totalTravelTime <= 0) {
            $totalTravelTime += 24 * 60; // Handle overnight
        }
        
        $currentTime = $depMinutes;
        $pathLength = count($route['path']);
        
        for ($i = 0; $i < $pathLength; $i++) {
            if ($i === 0) {
                // Origin
                $stationTimes[] = [
                    'station_id' => $route['path'][$i],
                    'arrival' => $this->minutesToTime($currentTime),
                    'departure' => $this->minutesToTime($currentTime)
                ];
            } else {
                // Calculate proportional time for this segment
                $segmentTime = $totalTravelTime / ($pathLength - 1);
                $currentTime += $segmentTime;
                
                $dwellTime = ($i === $pathLength - 1) ? 0 : 2; // 2 min dwell except at destination
                
                $stationTimes[] = [
                    'station_id' => $route['path'][$i],
                    'arrival' => $this->minutesToTime($currentTime),
                    'departure' => $this->minutesToTime($currentTime + $dwellTime)
                ];
                
                $currentTime += $dwellTime;
            }
        }
        
        return $stationTimes;
    }
    
    /**
     * Check if two time ranges overlap
     */
    private function timesOverlap($start1, $end1, $start2, $end2) {
        $start1_min = $this->timeToMinutes($start1);
        $end1_min = $this->timeToMinutes($end1);
        $start2_min = $this->timeToMinutes($start2);
        $end2_min = $this->timeToMinutes($end2);
        
        // Handle overnight times
        if ($end1_min < $start1_min) $end1_min += 24 * 60;
        if ($end2_min < $start2_min) $end2_min += 24 * 60;
        
        return ($start1_min < $end2_min) && ($start2_min < $end1_min);
    }
    
    /**
     * Convert time string to minutes
     */
    private function timeToMinutes($timeStr) {
        if (!$timeStr) return 0;
        $parts = explode(':', $timeStr);
        return intval($parts[0]) * 60 + intval($parts[1]);
    }
    
    /**
     * Convert minutes to time string
     */
    private function minutesToTime($minutes) {
        $hours = floor($minutes / 60) % 24;
        $mins = $minutes % 60;
        return sprintf('%02d:%02d:00', $hours, $mins);
    }
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $action = $_GET['action'] ?? 'check_all';
    
    // Require authentication for collision detection
    $user = getCurrentUser($conn);
    if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
        outputJSON(['status' => 'error', 'error' => 'Authentication required']);
    }
    
    $detector = new CollisionDetector($conn);
    
    switch ($action) {
        case 'check_all':
            $collisions = $detector->checkAllCollisions();
            outputJSON([
                'status' => 'success',
                'data' => [
                    'collisions' => $collisions,
                    'total_collisions' => count($collisions),
                    'system_safe' => empty($collisions)
                ]
            ]);
            break;
            
        case 'check_trains':
            $train1_id = $_GET['train1_id'] ?? null;
            $train2_id = $_GET['train2_id'] ?? null;
            
            if (!$train1_id || !$train2_id) {
                outputJSON(['status' => 'error', 'error' => 'Both train IDs are required']);
            }
            
            // Get train details
            $stmt = $conn->prepare("
                SELECT id, train_number, train_name, departure_station_id, arrival_station_id, 
                       departure_time, arrival_time
                FROM dcc_trains 
                WHERE id IN (?, ?) AND is_active = 1
            ");
            $stmt->execute([$train1_id, $train2_id]);
            $trains = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($trains) !== 2) {
                outputJSON(['status' => 'error', 'error' => 'Could not find both trains']);
            }
            
            $collision = $detector->checkTrainCollision($trains[0], $trains[1]);
            
            outputJSON([
                'status' => 'success',
                'data' => [
                    'collision_detected' => !empty($collision),
                    'collision' => $collision
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
