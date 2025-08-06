<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';
require_once 'auth_utils.php';

class AutomatedTrainGenerator {
    private $pdo;
    
    public function __construct() {
        try {
            $this->pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            throw new Exception("Database connection failed: " . $e->getMessage());
        }
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? '';
        
        // Get current user for authentication
        $user = getCurrentUser($this->pdo);
        
        switch ($action) {
            case 'preview':
                // Preview requires admin or operator role
                if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                    return ['status' => 'error', 'error' => 'Authentication required. Admin or operator role needed.'];
                }
                return $this->previewGeneration();
            case 'generate':
                // Generate requires admin or operator role
                if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                    return ['status' => 'error', 'error' => 'Authentication required. Admin or operator role needed.'];
                }
                return $this->generateTrains();
            case 'clear_all':
                // Clear all trains requires admin role only
                if (!$user || $user['role'] !== 'admin') {
                    return ['status' => 'error', 'error' => 'Admin authentication required.'];
                }
                return $this->clearAllTrains();
            case 'clear_auto':
                // Clear AUTO trains requires admin or operator role
                if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                    return ['status' => 'error', 'error' => 'Authentication required. Admin or operator role needed.'];
                }
                return $this->clearAutoTrains();
            default:
                throw new Exception("Invalid action: $action");
        }
    }
    
    private function previewGeneration() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        $routes = $this->getAvailableRoutes($input);
        $timeSlots = $this->calculateTimeSlots($input);
        
        // Get locomotive count to calculate realistic limits
        $locomotiveCount = $this->getLocomotiveCount();
        
        // More realistic calculation: Not all locomotives can run at every time slot
        // because they need to be at the right stations and available
        $stationCount = $this->getStationCount();
        $avgLocomotivesPerStation = max(1, floor($locomotiveCount / max(1, $stationCount)));
        
        // Conservative estimate: assume only 60-80% of locomotives are optimally positioned
        $effectiveLocomotives = floor($locomotiveCount * 0.7);
        $maxTrainsPerTimeSlot = min($effectiveLocomotives, count($routes));
        $maxPossibleTrains = count($timeSlots) * $maxTrainsPerTimeSlot;
        
        $totalAttempts = count($routes) * count($timeSlots);
        if ($input['bidirectional'] === 'true') {
            $totalAttempts *= 2; // Double for return trips
        }
        
        // Limit attempts to realistic locomotive availability
        $realisticAttempts = min($totalAttempts, $maxPossibleTrains);
        
        // Estimate success rate based on locomotive constraints and conflicts
        $estimatedSuccessRate = 0.6; // More conservative estimate
        if ($locomotiveCount < 5) {
            $estimatedSuccessRate = 0.4; // Lower success rate with fewer locomotives
        } elseif ($locomotiveCount > 10) {
            $estimatedSuccessRate = 0.7; // Better success rate with more locomotives
        }
        
        $estimatedSuccess = floor($realisticAttempts * $estimatedSuccessRate);
        $estimatedConflicts = $realisticAttempts - $estimatedSuccess;
        
        $warning = null;
        if ($totalAttempts > $maxPossibleTrains) {
            $warning = "Limited by locomotive availability: $locomotiveCount total locomotives across $stationCount stations = estimated $effectiveLocomotives effectively available = maximum ~$maxPossibleTrains trains possible";
        }
        
        if ($locomotiveCount < 3) {
            $warning = "Warning: Very few locomotives ($locomotiveCount) available. Consider using 'specific route' or 'random routes' mode with fewer routes.";
        }
        
        return [
            'status' => 'success',
            'data' => [
                'total_attempts' => $realisticAttempts,
                'estimated_success' => $estimatedSuccess,
                'estimated_conflicts' => $estimatedConflicts,
                'routes' => count($routes),
                'time_slots' => count($timeSlots),
                'locomotive_count' => $locomotiveCount,
                'effective_locomotives' => $effectiveLocomotives,
                'station_count' => $stationCount,
                'max_possible_trains' => $maxPossibleTrains,
                'bidirectional' => $input['bidirectional'] === 'true',
                'warning' => $warning
            ]
        ];
    }
    
    private function generateTrains() {
        // Set up streaming response
        header('Content-Type: application/json');
        header('X-Accel-Buffering: no'); // Disable nginx buffering
        ob_implicit_flush(true);
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $routes = $this->getAvailableRoutes($input);
        $timeSlots = $this->calculateTimeSlots($input);
        
        $stats = ['success' => 0, 'conflicts' => 0, 'skipped' => 0, 'total' => 0];
        $totalAttempts = count($routes) * count($timeSlots);
        if ($input['bidirectional'] === 'true') {
            $totalAttempts *= 2;
        }
        
        $attemptCount = 0;
        
        // Send initial progress
        $this->sendUpdate([
            'type' => 'progress',
            'percentage' => 0,
            'message' => 'Starting train generation...',
            'stats' => $stats
        ]);
        
        foreach ($routes as $route) {
            foreach ($timeSlots as $departureTime) {
                // Generate outbound train
                $this->generateSingleTrain($route, $departureTime, $input, $stats, $attemptCount, $totalAttempts);
                
                // Generate return train if bidirectional
                if ($input['bidirectional'] === 'true') {
                    $returnRoute = $this->createReturnRoute($route);
                    
                    // Calculate return departure time (arrival time + turnaround time)
                    $returnTime = $this->calculateReturnTime($route, $departureTime, $input);
                    if ($returnTime && $returnRoute) {
                        $this->generateSingleTrain($returnRoute, $returnTime, $input, $stats, $attemptCount, $totalAttempts);
                    }
                }
            }
        }
        
        // Send final progress
        $this->sendUpdate([
            'type' => 'progress',
            'percentage' => 100,
            'message' => 'Generation completed!',
            'stats' => $stats
        ]);
        
        return ['status' => 'success', 'stats' => $stats];
    }
    
    private function generateSingleTrain($route, $departureTime, $input, &$stats, &$attemptCount, $totalAttempts) {
        $attemptCount++;
        $stats['total']++;
        
        try {
            // Get departure station from route
            $departureStationId = isset($route['departure_station']) ? $route['departure_station'] : 
                                 (isset($route['stations'][0]['id']) ? $route['stations'][0]['id'] : null);
            
            if (!$departureStationId) {
                $stats['skipped']++;
                $this->sendUpdate([
                    'type' => 'train_skipped',
                    'train' => [
                        'train_number' => $this->generateTrainNumber($input, $attemptCount),
                        'route' => $this->getRouteDisplayName($route),
                        'departure_time' => $departureTime,
                        'locomotive' => 'Invalid route'
                    ],
                    'reason' => 'No valid departure station found in route'
                ]);
                
                $this->updateProgress($attemptCount, $totalAttempts, $stats);
                return;
            }
            
            // STRICT LOCOMOTIVE LOCATION CHECK: Only use locomotives actually at the departure station
            $locomotive = $this->getLocomotiveAtStation($departureStationId, $departureTime);
            
            if (!$locomotive) {
                $stats['skipped']++;
                $this->sendUpdate([
                    'type' => 'train_skipped',
                    'train' => [
                        'train_number' => $this->generateTrainNumber($input, $attemptCount),
                        'route' => $this->getRouteDisplayName($route),
                        'departure_time' => $departureTime,
                        'locomotive' => "No locomotive available at departure station"
                    ],
                    'reason' => "No locomotive available at departure station ${departureStationId} at ${departureTime}"
                ]);
                
                $this->updateProgress($attemptCount, $totalAttempts, $stats);
                return;
            }
            
            // Double-check locomotive isn't already assigned to another train at this time
            $conflictCheck = $this->checkLocomotiveConflict($locomotive['id'], $departureTime);
            if ($conflictCheck) {
                $stats['conflicts']++;
                $this->sendUpdate([
                    'type' => 'train_conflict',
                    'train' => [
                        'train_number' => $this->generateTrainNumber($input, $attemptCount),
                        'route' => $this->getRouteDisplayName($route),
                        'departure_time' => $departureTime,
                        'locomotive' => $locomotive['display_name']
                    ],
                    'conflict_details' => "Locomotive already assigned to train {$conflictCheck['train_number']} at {$conflictCheck['departure_time']}"
                ]);
                
                $this->updateProgress($attemptCount, $totalAttempts, $stats);
                return;
            }
            
            // Validate route and check for conflicts
            $validation = $this->validateTrainRoute($route, $departureTime, $input, $locomotive['id']);
            
            if (!$validation['success']) {
                $stats['conflicts']++;
                $this->sendUpdate([
                    'type' => 'train_conflict',
                    'train' => [
                        'train_number' => $this->generateTrainNumber($input, $attemptCount),
                        'route' => $this->getRouteDisplayName($route),
                        'departure_time' => $departureTime,
                        'locomotive' => $locomotive['display_name']
                    ],
                    'conflict_details' => $validation['error']
                ]);
                
                $this->updateProgress($attemptCount, $totalAttempts, $stats);
                return;
            }
            
            // Create the train
            $trainData = [
                'train_number' => $this->generateTrainNumber($input, $attemptCount),
                'train_name' => $input['train_name_prefix'] . '-' . $attemptCount,
                'train_type' => $input['train_type'],
                'route' => $route,
                'departure_time' => $departureTime,
                'arrival_time' => $validation['arrival_time'],
                'max_speed_kmh' => $input['max_speed'],
                'locomotive_id' => $locomotive['id'],
                'station_times' => $validation['station_times'],
                'waiting_times' => $this->getDefaultWaitingTimes($validation['station_times'], $input['default_waiting_time'])
            ];
            
            $trainId = $this->createMultiHopTrain($trainData);
            
            if ($trainId) {
                $stats['success']++;
                $this->sendUpdate([
                    'type' => 'train_created',
                    'train' => [
                        'train_number' => $trainData['train_number'],
                        'route' => $this->getRouteDisplayName($route),
                        'departure_time' => $departureTime,
                        'locomotive' => $locomotive['display_name'],
                        'stops' => count($validation['station_times'])
                    ]
                ]);
            } else {
                $stats['conflicts']++;
                $this->sendUpdate([
                    'type' => 'train_conflict',
                    'train' => $trainData,
                    'conflict_details' => 'Failed to create train in database'
                ]);
            }
            
        } catch (Exception $e) {
            $stats['conflicts']++;
            $this->sendUpdate([
                'type' => 'train_conflict',
                'train' => [
                    'train_number' => $this->generateTrainNumber($input, $attemptCount),
                    'route' => $this->getRouteDisplayName($route),
                    'departure_time' => $departureTime,
                    'locomotive' => 'Error'
                ],
                'conflict_details' => $e->getMessage()
            ]);
        }
        
        $this->updateProgress($attemptCount, $totalAttempts, $stats);
    }
    
    private function updateProgress($current, $total, $stats) {
        $percentage = ($current / $total) * 100;
        $this->sendUpdate([
            'type' => 'progress',
            'percentage' => round($percentage, 1),
            'message' => "Processing train $current of $total...",
            'stats' => $stats
        ]);
    }
    
    private function sendUpdate($data) {
        echo json_encode($data) . "\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
    
    private function getAvailableRoutes($input) {
        if ($input['route_mode'] === 'specific') {
            if (empty($input['departure_station_id']) || empty($input['arrival_station_id'])) {
                throw new Exception("Departure and arrival stations must be specified for specific route mode");
            }
            
            return [[
                'departure_station' => $input['departure_station_id'],
                'arrival_station' => $input['arrival_station_id']
            ]];
        }
        
        if ($input['route_mode'] === 'all_routes') {
            return $this->getAllPossibleRoutes();
        }
        
        if ($input['route_mode'] === 'random') {
            $numRoutes = $input['num_random_routes'] ?? 5;
            return $this->getRandomRoutes($numRoutes);
        }
        
        throw new Exception("Invalid route mode: " . $input['route_mode']);
    }
    
    private function getAllPossibleRoutes() {
        // Create realistic multi-hop routes through multiple stations
        $stmt = $this->pdo->query("SELECT id, name FROM dcc_stations ORDER BY CASE WHEN nr IS NULL THEN 1 ELSE 0 END, nr, name LIMIT 20");
        $stations = $stmt->fetchAll();
        
        $routes = [];
        $maxRoutes = 50; // Reasonable limit
        $routeCount = 0;
        
        // Generate multi-hop routes (2-4 stations per route)
        foreach ($stations as $startStation) {
            if ($routeCount >= $maxRoutes) break;
            
            // Create routes of varying lengths
            $routeLengths = [2, 3, 4]; // 2-4 stops including start and end
            
            foreach ($routeLengths as $length) {
                if ($routeCount >= $maxRoutes) break;
                
                // Generate a few routes of this length starting from this station
                for ($attempt = 0; $attempt < 2 && $routeCount < $maxRoutes; $attempt++) {
                    $route = $this->generateMultiHopRoute($stations, $startStation, $length);
                    if ($route && count($route['stations']) >= 2) {
                        $routes[] = $route;
                        $routeCount++;
                    }
                }
            }
        }
        
        return $routes;
    }
    
    private function getRandomRoutes($numRoutes) {
        $allRoutes = $this->getAllPossibleRoutes();
        shuffle($allRoutes);
        return array_slice($allRoutes, 0, min($numRoutes, count($allRoutes)));
    }
    
    private function generateMultiHopRoute($allStations, $startStation, $length) {
        $route = [
            'stations' => [$startStation],
            'departure_station' => $startStation['id'],
            'arrival_station' => null,
            'intermediate_stations' => [],
            'total_estimated_time' => 0
        ];
        
        $availableStations = array_filter($allStations, function($station) use ($startStation) {
            return $station['id'] !== $startStation['id'];
        });
        
        $currentStation = $startStation;
        
        // Add intermediate stations
        for ($i = 1; $i < $length; $i++) {
            if (empty($availableStations)) break;
            
            // Randomly select next station
            $nextStationIndex = array_rand($availableStations);
            $nextStation = $availableStations[$nextStationIndex];
            
            // Remove selected station from available options to avoid loops
            $availableStations = array_filter($availableStations, function($station) use ($nextStation) {
                return $station['id'] !== $nextStation['id'];
            });
            
            $route['stations'][] = $nextStation;
            
            // Estimate travel time for this segment
            $segmentTime = $this->estimateTravelTime($currentStation['id'], $nextStation['id'], 80);
            $route['total_estimated_time'] += $segmentTime;
            
            if ($i < $length - 1) {
                // This is an intermediate station
                $route['intermediate_stations'][] = [
                    'station_id' => $nextStation['id'],
                    'station_name' => $nextStation['name'],
                    'dwell_time' => rand(2, 8) // 2-8 minute stops at intermediate stations
                ];
            }
            
            $currentStation = $nextStation;
        }
        
        // Set final destination
        if (count($route['stations']) >= 2) {
            $finalStation = end($route['stations']);
            $route['arrival_station'] = $finalStation['id'];
            $route['route_description'] = $this->generateRouteDescription($route);
        }
        
        return count($route['stations']) >= 2 ? $route : null;
    }
    
    private function generateRouteDescription($route) {
        $description = $route['stations'][0]['name'];
        
        if (!empty($route['intermediate_stations'])) {
            $intermediateNames = array_map(function($stop) {
                return $stop['station_name'];
            }, $route['intermediate_stations']);
            $description .= ' → ' . implode(' → ', $intermediateNames);
        }
        
        $description .= ' → ' . end($route['stations'])['name'];
        
        return $description;
    }
    
    private function calculateTimeSlots($input) {
        $startTime = $input['start_time'];
        $endTime = $input['end_time'];
        $frequency = (int)$input['train_frequency'];
        
        $slots = [];
        $current = strtotime($startTime);
        $end = strtotime($endTime);
        
        while ($current <= $end) {
            $slots[] = date('H:i', $current);
            $current += $frequency * 60; // Convert minutes to seconds
        }
        
        return $slots;
    }
    
    private function generateTrainNumber($input, $attemptCount) {
        $prefix = $input['train_name_prefix'] ?: 'AUTO';
        return $prefix . str_pad($attemptCount, 4, '0', STR_PAD_LEFT);
    }
    
    private function getLocomotiveCount() {
        // Get count of all locomotives
        // For now, we'll count all locomotives since we don't have a status or station column
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM dcc_locomotives");
        return (int)$stmt->fetchColumn();
    }
    
    private function getStationCount() {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM dcc_stations");
        return (int)$stmt->fetchColumn();
    }
    
    private function getAvailableLocomotiveCountForTimeSlot($timeSlot) {
        // Get locomotives available at a specific time slot
        // This considers locomotives not assigned to trains at that time
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT l.id)
            FROM dcc_locomotives l
            WHERE l.id NOT IN (
                SELECT DISTINCT tl.locomotive_id 
                FROM dcc_train_locomotives tl
                JOIN dcc_trains t ON tl.train_id = t.id
                WHERE tl.locomotive_id IS NOT NULL
                AND (
                    (t.departure_time <= ? AND DATE_ADD(STR_TO_DATE(CONCAT(CURDATE(), ' ', t.arrival_time), '%Y-%m-%d %H:%i:%s'), INTERVAL COALESCE(l.turnaround_time_minutes, 10) MINUTE) > ?)
                )
            )
        ");
        $stmt->execute([$timeSlot, $timeSlot]);
        return (int)$stmt->fetchColumn();
    }
    
    private function getAvailableLocomotive($stationId, $departureTime) {
        // Fixed locomotive assignment - check timing, location, AND inter-station travel time
        // A locomotive must be at the correct station and have completed previous journeys
        
        $stmt = $this->pdo->prepare("
            SELECT l.id, 
                   CONCAT(l.class, ' ', l.number, CASE WHEN l.name IS NOT NULL THEN CONCAT(' \"', l.name, '\"') ELSE '' END) as display_name,
                   COALESCE(l.turnaround_time_minutes, 10) as turnaround_time,
                   COUNT(recent_trains.id) as recent_usage
            FROM dcc_locomotives l
            LEFT JOIN dcc_train_locomotives recent_tl ON l.id = recent_tl.locomotive_id
            LEFT JOIN dcc_trains recent_trains ON recent_tl.train_id = recent_trains.id 
                AND recent_trains.is_active = 1 
                AND recent_trains.departure_time BETWEEN DATE_SUB(STR_TO_DATE(?, '%H:%i'), INTERVAL 4 HOUR) AND ?
            WHERE l.id NOT IN (
                SELECT DISTINCT tl.locomotive_id 
                FROM dcc_train_locomotives tl
                JOIN dcc_trains t ON tl.train_id = t.id
                JOIN dcc_locomotives loco ON tl.locomotive_id = loco.id
                WHERE tl.locomotive_id IS NOT NULL
                AND t.is_active = 1
                AND (
                    -- Locomotive is currently traveling
                    (t.departure_time <= ? AND t.arrival_time > ?)
                    OR
                    -- Locomotive hasn't finished previous journey + turnaround time
                    (DATE_ADD(t.arrival_time, INTERVAL COALESCE(loco.turnaround_time_minutes, 10) MINUTE) > ?)
                    OR
                    -- LOCATION CHECK: Locomotive ends at different station than new departure station
                    -- Need minimum 60 minutes for inter-station repositioning when stations differ
                    (t.arrival_station_id != ? 
                     AND DATE_ADD(t.arrival_time, INTERVAL 60 MINUTE) > ?)
                    OR
                    -- SAME STATION CHECK: If same station, still need turnaround time
                    (t.arrival_station_id = ? 
                     AND DATE_ADD(t.arrival_time, INTERVAL COALESCE(loco.turnaround_time_minutes, 10) MINUTE) > ?)
                )
            )
            GROUP BY l.id, l.class, l.number, l.name, l.dcc_address, l.turnaround_time_minutes
            ORDER BY recent_usage ASC, l.dcc_address ASC
            LIMIT 1
        ");
        
        $stmt->execute([$departureTime, $departureTime, $departureTime, $departureTime, $departureTime, $stationId, $departureTime, $stationId, $departureTime]);
        $result = $stmt->fetch();
        
        // If no locomotive found, try with reduced inter-station time (emergency fallback)
        if (!$result) {
            $stmt = $this->pdo->prepare("
                SELECT l.id, 
                       CONCAT(l.class, ' ', l.number, CASE WHEN l.name IS NOT NULL THEN CONCAT(' \"', l.name, '\"') ELSE '' END) as display_name,
                       COALESCE(l.turnaround_time_minutes, 10) as turnaround_time
                FROM dcc_locomotives l
                WHERE l.id NOT IN (
                    SELECT DISTINCT tl.locomotive_id 
                    FROM dcc_train_locomotives tl
                    JOIN dcc_trains t ON tl.train_id = t.id
                    WHERE tl.locomotive_id IS NOT NULL
                    AND t.is_active = 1
                    AND (
                        -- Locomotive is currently traveling
                        (t.departure_time <= ? AND t.arrival_time > ?)
                        OR
                        -- Locomotive arrival time is after new departure time
                        (t.arrival_time > ?)
                    )
                )
                ORDER BY l.dcc_address ASC
                LIMIT 1
            ");
            
            $stmt->execute([$departureTime, $departureTime, $departureTime]);
            $result = $stmt->fetch();
        }
        
        return $result;
    }

    /**
     * Get available locomotives with their current locations
     * This is the key fix - locomotives can only depart from where they currently are!
     */
    private function getAvailableLocomotivesWithLocations($departureTime) {
        $stmt = $this->pdo->prepare("
            SELECT 
                l.id, 
                CONCAT(l.class, ' ', l.number, CASE WHEN l.name IS NOT NULL THEN CONCAT(' \"', l.name, '\"') ELSE '' END) as display_name,
                COALESCE(l.turnaround_time_minutes, 10) as turnaround_time,
                COALESCE(
                    (
                        SELECT t.arrival_station_id 
                        FROM dcc_trains t
                        JOIN dcc_train_locomotives tl ON t.id = tl.train_id
                        WHERE tl.locomotive_id = l.id 
                        AND t.is_active = 1 
                        AND t.arrival_time <= ?
                        ORDER BY t.arrival_time DESC 
                        LIMIT 1
                    ),
                    'DEPOT'
                ) as current_location,
                COALESCE(
                    (
                        SELECT s.name 
                        FROM dcc_stations s 
                        WHERE s.id = COALESCE(
                            (
                                SELECT t.arrival_station_id 
                                FROM dcc_trains t
                                JOIN dcc_train_locomotives tl ON t.id = tl.train_id
                                WHERE tl.locomotive_id = l.id 
                                AND t.is_active = 1 
                                AND t.arrival_time <= ?
                                ORDER BY t.arrival_time DESC 
                                LIMIT 1
                            ),
                            'DEPOT'
                        )
                    ),
                    'Depot'
                ) as location_name
            FROM dcc_locomotives l
            WHERE l.id NOT IN (
                SELECT DISTINCT tl.locomotive_id 
                FROM dcc_train_locomotives tl
                JOIN dcc_trains t ON tl.train_id = t.id
                JOIN dcc_locomotives loco ON tl.locomotive_id = loco.id
                WHERE tl.locomotive_id IS NOT NULL
                AND t.is_active = 1
                AND (
                    -- Locomotive is currently traveling
                    (t.departure_time <= ? AND t.arrival_time > ?)
                    OR
                    -- Locomotive hasn't finished previous journey + turnaround time
                    (DATE_ADD(t.arrival_time, INTERVAL COALESCE(loco.turnaround_time_minutes, 10) MINUTE) > ?)
                )
            )
            ORDER BY l.dcc_address ASC
        ");
        
        $stmt->execute([$departureTime, $departureTime, $departureTime, $departureTime, $departureTime]);
        return $stmt->fetchAll();
    }

    /**
     * Get locomotives that are actually at a specific station - NO TELEPORTATION ALLOWED
     * This ensures locomotives can only depart from where they physically are
     */
    private function getLocomotiveAtStation($stationId, $departureTime) {
        $stmt = $this->pdo->prepare("
            SELECT 
                l.id, 
                CONCAT(l.class, ' ', l.number, CASE WHEN l.name IS NOT NULL THEN CONCAT(' \"', l.name, '\"') ELSE '' END) as display_name,
                COALESCE(l.turnaround_time_minutes, 10) as turnaround_time
            FROM dcc_locomotives l
            WHERE l.id NOT IN (
                SELECT DISTINCT tl.locomotive_id 
                FROM dcc_train_locomotives tl
                JOIN dcc_trains t ON tl.train_id = t.id
                JOIN dcc_locomotives loco ON tl.locomotive_id = loco.id
                WHERE tl.locomotive_id IS NOT NULL
                AND t.is_active = 1
                AND (
                    -- Locomotive is currently traveling
                    (t.departure_time <= ? AND t.arrival_time > ?)
                    OR
                    -- Locomotive hasn't finished previous journey + turnaround time
                    (DATE_ADD(t.arrival_time, INTERVAL COALESCE(loco.turnaround_time_minutes, 10) MINUTE) > ?)
                    OR
                    -- STRICT LOCATION CHECK: Locomotive must be at the exact departure station
                    -- If locomotive's last arrival station is not the departure station, exclude it
                    (COALESCE(
                        (
                            SELECT t2.arrival_station_id 
                            FROM dcc_trains t2
                            JOIN dcc_train_locomotives tl2 ON t2.id = tl2.train_id
                            WHERE tl2.locomotive_id = loco.id 
                            AND t2.is_active = 1 
                            AND t2.arrival_time <= ?
                            ORDER BY t2.arrival_time DESC 
                            LIMIT 1
                        ),
                        ? -- If no previous assignment, assume it's at this station (depot)
                    ) != ?)
                )
            )
            ORDER BY l.dcc_address ASC
            LIMIT 1
        ");
        
        $stmt->execute([$departureTime, $departureTime, $departureTime, $departureTime, $stationId, $stationId]);
        return $stmt->fetch();
    }

    private function checkLocomotiveConflict($locomotiveId, $departureTime) {
        // Check if this locomotive is still traveling, properly handling overnight journeys
        // Example: Train departs 20:54, arrives 01:16 (next day) - locomotive busy from 20:54 to 01:16
        $stmt = $this->pdo->prepare("
            SELECT t.train_number, t.departure_time, t.arrival_time,
                   CASE 
                       -- Same day journey (arrival > departure)
                       WHEN t.arrival_time > t.departure_time AND ? BETWEEN t.departure_time AND t.arrival_time THEN 'Still traveling (same day)'
                       -- Overnight journey (arrival < departure, crossing midnight)
                       WHEN t.arrival_time < t.departure_time AND (? >= t.departure_time OR ? <= t.arrival_time) THEN 'Still traveling (overnight)'
                       -- Same day arrival with insufficient turnaround time
                       WHEN t.arrival_time > t.departure_time AND t.arrival_time <= ? AND 
                            TIME_TO_SEC(?) - TIME_TO_SEC(t.arrival_time) < (COALESCE(l.turnaround_time_minutes, 10) * 60) THEN 'Insufficient turnaround time (same day)'
                       -- Overnight arrival with insufficient turnaround time (next day arrival)
                       WHEN t.arrival_time < t.departure_time AND t.arrival_time <= ? AND 
                            TIME_TO_SEC(?) + 86400 - TIME_TO_SEC(t.arrival_time) < (COALESCE(l.turnaround_time_minutes, 10) * 60) THEN 'Insufficient turnaround time (overnight)'
                       ELSE 'Available'
                   END as conflict_reason,
                   -- Calculate if this is an overnight journey
                   CASE WHEN t.arrival_time < t.departure_time THEN 'YES' ELSE 'NO' END as crosses_midnight
            FROM dcc_trains t
            JOIN dcc_train_locomotives tl ON t.id = tl.train_id
            LEFT JOIN dcc_locomotives l ON tl.locomotive_id = l.id
            WHERE tl.locomotive_id = ?
            AND t.is_active = 1
            AND (
                -- Same day journey conflict
                (t.arrival_time > t.departure_time AND ? BETWEEN t.departure_time AND t.arrival_time)
                OR
                -- Overnight journey conflict
                (t.arrival_time < t.departure_time AND (? >= t.departure_time OR ? <= t.arrival_time))
                OR
                -- Same day turnaround time conflict
                (t.arrival_time > t.departure_time AND t.arrival_time <= ? AND 
                 TIME_TO_SEC(?) - TIME_TO_SEC(t.arrival_time) < (COALESCE(l.turnaround_time_minutes, 10) * 60))
                OR
                -- Overnight turnaround time conflict
                (t.arrival_time < t.departure_time AND t.arrival_time <= ? AND 
                 TIME_TO_SEC(?) + 86400 - TIME_TO_SEC(t.arrival_time) < (COALESCE(l.turnaround_time_minutes, 10) * 60))
            )
            ORDER BY t.departure_time DESC
            LIMIT 1
        ");
        
        $stmt->execute([
            $departureTime, $departureTime, $departureTime, $departureTime, $departureTime, 
            $departureTime, $departureTime, $locomotiveId, $departureTime, $departureTime, 
            $departureTime, $departureTime, $departureTime, $departureTime, $departureTime
        ]);
        $result = $stmt->fetch();
        
        if ($result) {
            // Add detailed conflict information
            $result['detailed_message'] = "Locomotive conflict: {$result['conflict_reason']} - " .
                "Current train {$result['train_number']} departs {$result['departure_time']}, arrives {$result['arrival_time']} " .
                "(Crosses midnight: {$result['crosses_midnight']}), new departure at {$departureTime}";
        }
        
        return $result;
    }
    
    private function validateTrainRoute($route, $departureTime, $input, $locomotiveId) {
        // Handle both simple and multi-hop routes
        $departureStationId = isset($route['departure_station']) ? $route['departure_station'] : 
                             (isset($route['stations'][0]['id']) ? $route['stations'][0]['id'] : null);
        $arrivalStationId = isset($route['arrival_station']) ? $route['arrival_station'] : 
                           (isset($route['stations']) && count($route['stations']) > 1 ? 
                            end($route['stations'])['id'] : null);
        
        if (!$departureStationId || !$arrivalStationId) {
            return [
                'success' => false,
                'error' => 'Invalid route: missing departure or arrival station'
            ];
        }
        
        // Use the enhanced train creator API for validation
        $validation_data = [
            'route' => $route,
            'departure_station' => $departureStationId,
            'arrival_station' => $arrivalStationId,
            'departure_time' => $departureTime,
            'max_speed_kmh' => (float)$input['max_speed'],
            'locomotive_id' => $locomotiveId
        ];
        
        // Call internal validation function
        return $this->performRouteValidation($validation_data);
    }
    
    private function performRouteValidation($data) {
        try {
            $route = $data['route'];
            $departure = $data['departure_station'];
            $arrival = $data['arrival_station'];
            $depTime = $data['departure_time'];
            
            // Check if this is a multi-hop route
            if (isset($route['stations']) && count($route['stations']) > 2) {
                return $this->validateMultiHopRoute($route, $depTime, $data['max_speed_kmh']);
            } else {
                // Simple point-to-point route
                return $this->validateSimpleRoute($departure, $arrival, $depTime, $data['max_speed_kmh']);
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function validateMultiHopRoute($route, $departureTime, $maxSpeed) {
        $stations = $route['stations'];
        $currentTime = strtotime($departureTime);
        $stationTimes = [];
        
        // Generate schedule for each station in the route
        for ($i = 0; $i < count($stations); $i++) {
            $station = $stations[$i];
            $stationId = isset($station['id']) ? $station['id'] : $station;
            
            if ($i == 0) {
                // First station - departure only
                $arrivalTime = date('H:i', $currentTime);
                $departureFromStation = date('H:i', $currentTime);
                $dwellTime = 0;
            } elseif ($i == count($stations) - 1) {
                // Last station - arrival only
                $travelTime = $this->estimateTravelTime($stations[$i-1]['id'] ?? $stations[$i-1], $stationId, $maxSpeed);
                $currentTime += $travelTime * 60;
                $arrivalTime = date('H:i', $currentTime);
                $departureFromStation = $arrivalTime;
                $dwellTime = 0;
                
                // Check if final arrival crosses midnight
                if ($arrivalTime < $departureTime) {
                    return [
                        'success' => false,
                        'error' => 'Multi-hop route would cross midnight (overnight train) - not allowed'
                    ];
                }
            } else {
                // Intermediate station
                $travelTime = $this->estimateTravelTime($stations[$i-1]['id'] ?? $stations[$i-1], $stationId, $maxSpeed);
                $currentTime += $travelTime * 60;
                $arrivalTime = date('H:i', $currentTime);
                
                // Check if intermediate arrival crosses midnight
                if ($arrivalTime < $departureTime) {
                    return [
                        'success' => false,
                        'error' => 'Multi-hop route would cross midnight (overnight train) - not allowed'
                    ];
                }
                
                // Add dwell time at intermediate stations
                $dwellTime = isset($route['intermediate_stations'][$i-1]['dwell_time']) ? 
                           $route['intermediate_stations'][$i-1]['dwell_time'] : 
                           rand(2, 5); // 2-5 minutes default
                
                $currentTime += $dwellTime * 60;
                $departureFromStation = date('H:i', $currentTime);
                
                // Check if departure after dwell time crosses midnight
                if ($departureFromStation < $departureTime) {
                    return [
                        'success' => false,
                        'error' => 'Multi-hop route would cross midnight (overnight train) - not allowed'
                    ];
                }
            }
            
            $stationTimes[] = [
                'station_id' => $stationId,
                'arrival' => $arrivalTime,
                'departure' => $departureFromStation,
                'dwell_time' => $dwellTime,
                'stop_sequence' => $i + 1
            ];
        }
        
        // Check for conflicts along the entire route
        $conflicts = $this->checkMultiHopConflicts($stationTimes);
        
        if (!empty($conflicts)) {
            return [
                'success' => false,
                'error' => 'Route conflicts detected: ' . implode(', ', $conflicts)
            ];
        }
        
        $finalArrival = end($stationTimes)['arrival'];
        
        return [
            'success' => true,
            'arrival_time' => $finalArrival,
            'station_times' => $stationTimes
        ];
    }
    
    private function validateSimpleRoute($departure, $arrival, $depTime, $maxSpeed) {
        // Calculate estimated arrival time (simplified)
        $estimatedTravelTime = $this->estimateTravelTime($departure, $arrival, $maxSpeed);
        $arrivalTime = date('H:i', strtotime($depTime) + ($estimatedTravelTime * 60));
        
        // Prevent overnight trains - reject if arrival is before departure (crossing midnight)
        if ($arrivalTime < $depTime) {
            return [
                'success' => false,
                'error' => 'Route would cross midnight (overnight train) - not allowed'
            ];
        }
        
        // Check for basic conflicts
        $conflicts = $this->checkBasicConflicts($departure, $arrival, $depTime, $arrivalTime);
        
        if (!empty($conflicts)) {
            return [
                'success' => false,
                'error' => 'Route conflicts detected: ' . implode(', ', $conflicts)
            ];
        }
        
        return [
            'success' => true,
            'arrival_time' => $arrivalTime,
            'station_times' => $this->generateStationTimes($departure, $arrival, $depTime, $arrivalTime)
        ];
    }
    
    private function checkMultiHopConflicts($stationTimes) {
        $conflicts = [];
        
        // Check each segment of the multi-hop route for conflicts
        for ($i = 0; $i < count($stationTimes) - 1; $i++) {
            $currentStation = $stationTimes[$i];
            $nextStation = $stationTimes[$i + 1];
            
            // Check for overlapping trains on this segment
            $stmt = $this->pdo->prepare("
                SELECT train_number 
                FROM dcc_trains 
                WHERE departure_station_id = ? 
                AND arrival_station_id = ? 
                AND (
                    (departure_time <= ? AND arrival_time > ?) 
                    OR (departure_time < ? AND arrival_time >= ?)
                    OR (departure_time >= ? AND departure_time < ?)
                )
            ");
            
            $segmentDepTime = $currentStation['departure'];
            $segmentArrTime = $nextStation['arrival'];
            
            $stmt->execute([
                $currentStation['station_id'], 
                $nextStation['station_id'], 
                $segmentDepTime, $segmentDepTime, 
                $segmentArrTime, $segmentArrTime, 
                $segmentDepTime, $segmentArrTime
            ]);
            
            if ($stmt->rowCount() > 0) {
                $conflicts[] = "Overlapping train on segment " . ($i + 1);
            }
        }
        
        return $conflicts;
    }
    
    private function estimateTravelTime($fromStation, $toStation, $maxSpeed) {
        // Simplified travel time estimation
        // In a real implementation, this would use actual route distances
        // Reduced range to prevent overnight trains
        return rand(15, 90); // 15-90 minutes (reduced from 30-120)
    }
    
    private function checkBasicConflicts($departure, $arrival, $depTime, $arrTime) {
        $conflicts = [];
        
        // Check for overlapping trains on the same route
        $stmt = $this->pdo->prepare("
            SELECT train_number 
            FROM dcc_trains 
            WHERE departure_station_id = ? 
            AND arrival_station_id = ? 
            AND (
                (departure_time <= ? AND arrival_time > ?) 
                OR (departure_time < ? AND arrival_time >= ?)
                OR (departure_time >= ? AND departure_time < ?)
            )
        ");
        
        $stmt->execute([$departure, $arrival, $depTime, $depTime, $arrTime, $arrTime, $depTime, $arrTime]);
        
        if ($stmt->rowCount() > 0) {
            $conflicts[] = "Overlapping train schedules detected";
        }
        
        return $conflicts;
    }
    
    private function generateStationTimes($departure, $arrival, $depTime, $arrTime) {
        // Simplified - just return departure and arrival
        return [
            [
                'station_id' => $departure,
                'arrival' => $depTime,
                'departure' => $depTime,
                'dwell_time' => 0,
                'stop_sequence' => 1
            ],
            [
                'station_id' => $arrival,
                'arrival' => $arrTime,
                'departure' => $arrTime,
                'dwell_time' => 0,
                'stop_sequence' => 2
            ]
        ];
    }
    
    private function getDefaultWaitingTimes($stationTimes, $defaultWaitTime) {
        $waitingTimes = [];
        foreach ($stationTimes as $stationTime) {
            $waitingTimes[$stationTime['station_id']] = (int)$defaultWaitTime;
        }
        return $waitingTimes;
    }
    
    private function calculateReturnTime($route, $departureTime, $input) {
        // Calculate when the locomotive will be available for return trip
        $departureStationId = isset($route['departure_station']) ? $route['departure_station'] : 
                             (isset($route['stations'][0]['id']) ? $route['stations'][0]['id'] : null);
        $arrivalStationId = isset($route['arrival_station']) ? $route['arrival_station'] : 
                           (isset($route['stations']) && count($route['stations']) > 1 ? 
                            end($route['stations'])['id'] : null);
        
        if (isset($route['total_estimated_time'])) {
            // Multi-hop route with pre-calculated time
            $travelTime = $route['total_estimated_time'];
        } else {
            // Simple route - estimate travel time
            $travelTime = $this->estimateTravelTime($departureStationId, $arrivalStationId, $input['max_speed']);
        }
        
        $arrivalTime = strtotime($departureTime) + ($travelTime * 60);
        
        // Add turnaround time
        $turnaroundTime = 15; // Default 15 minutes turnaround
        $returnTime = $arrivalTime + ($turnaroundTime * 60);
        
        // Check if return time is within operating hours
        $endTime = strtotime($input['end_time']);
        if ($returnTime > $endTime) {
            return null; // Too late for return trip
        }
        
        return date('H:i', $returnTime);
    }
    
    private function createReturnRoute($originalRoute) {
        if (isset($originalRoute['stations']) && count($originalRoute['stations']) > 1) {
            // Multi-hop route - reverse the stations
            $reversedStations = array_reverse($originalRoute['stations']);
            
            $returnRoute = [
                'stations' => $reversedStations,
                'departure_station' => $reversedStations[0]['id'],
                'arrival_station' => end($reversedStations)['id'],
                'intermediate_stations' => [],
                'total_estimated_time' => isset($originalRoute['total_estimated_time']) ? $originalRoute['total_estimated_time'] : 0
            ];
            
            // Reverse intermediate stations if they exist
            if (isset($originalRoute['intermediate_stations'])) {
                $returnRoute['intermediate_stations'] = array_reverse($originalRoute['intermediate_stations']);
            }
            
            $returnRoute['route_description'] = $this->generateRouteDescription($returnRoute);
            return $returnRoute;
            
        } else {
            // Simple point-to-point route
            return [
                'departure_station' => $originalRoute['arrival_station'],
                'arrival_station' => $originalRoute['departure_station']
            ];
        }
    }
    
    private function createMultiHopTrain($trainData) {
        try {
            $this->pdo->beginTransaction();
            
            // Extract route information
            $route = $trainData['route'];
            $departureStationId = isset($route['departure_station']) ? $route['departure_station'] : 
                                 (isset($route['stations'][0]['id']) ? $route['stations'][0]['id'] : null);
            $arrivalStationId = isset($route['arrival_station']) ? $route['arrival_station'] : 
                               (isset($route['stations']) && count($route['stations']) > 1 ? 
                                end($route['stations'])['id'] : null);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO dcc_trains (
                    train_number, train_name, train_type, route,
                    departure_station_id, arrival_station_id, departure_time, arrival_time,
                    max_speed_kmh, consist_notes, is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Generate route description
            $routeDescription = $this->getRouteDisplayName($route);
            
            $stmt->execute([
                $trainData['train_number'],
                $trainData['train_name'],
                $trainData['train_type'],
                $routeDescription,
                $departureStationId,
                $arrivalStationId,
                $trainData['departure_time'],
                $trainData['arrival_time'],
                $trainData['max_speed_kmh'],
                'Multi-hop route with ' . count($trainData['station_times']) . ' stops', // consist_notes
                1 // is_active
            ]);
            
            $trainId = $this->pdo->lastInsertId();
            
            // Assign locomotive via dcc_train_locomotives table
            if (!empty($trainData['locomotive_id'])) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO dcc_train_locomotives (
                        train_id, locomotive_id, position_in_train, is_lead_locomotive
                    ) VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$trainId, $trainData['locomotive_id'], 1, 1]);
            }
            
            // Create detailed schedule entries for multi-hop route
            if (!empty($trainData['station_times'])) {
                $this->createMultiHopSchedule($trainId, $trainData);
            }
            
            $this->pdo->commit();
            return $trainId;
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
    
    private function createMultiHopSchedule($trainId, $trainData) {
        // First create the schedule record
        $stmt = $this->pdo->prepare("
            INSERT INTO dcc_train_schedules (
                train_id, schedule_name, effective_date, schedule_type, frequency, 
                is_active
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $scheduleName = "Multi-hop schedule for " . $trainData['train_number'];
        
        $stmt->execute([
            $trainId,
            $scheduleName,
            date('Y-m-d'), // effective_date
            'regular',     // schedule_type
            'daily',       // frequency
            1              // is_active
        ]);
        
        $scheduleId = $this->pdo->lastInsertId();
        
        // Create stops for each station in the multi-hop route
        $stmt = $this->pdo->prepare("
            INSERT INTO dcc_timetable_stops (
                schedule_id, station_id, stop_sequence, arrival_time, departure_time,
                stop_type, dwell_time_minutes
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($trainData['station_times'] as $stop) {
            $stopType = 'intermediate';
            if ($stop['stop_sequence'] == 1) {
                $stopType = 'departure';
            } elseif ($stop['stop_sequence'] == count($trainData['station_times'])) {
                $stopType = 'arrival';
            }
            
            $stmt->execute([
                $scheduleId,
                $stop['station_id'],
                $stop['stop_sequence'],
                $stop['arrival'],
                $stop['departure'],
                $stopType,
                $stop['dwell_time']
            ]);
        }
    }
    
    private function createTrainSchedule($trainId, $trainData) {
        // First create the schedule record
        $stmt = $this->pdo->prepare("
            INSERT INTO dcc_train_schedules (
                train_id, schedule_name, effective_date, schedule_type, frequency, 
                is_active
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $scheduleName = "Auto-generated schedule for " . $trainData['train_number'];
        
        $stmt->execute([
            $trainId,
            $scheduleName,
            date('Y-m-d'), // effective_date
            'regular',     // schedule_type
            'daily',       // frequency
            1              // is_active
        ]);
        
        $scheduleId = $this->pdo->lastInsertId();
        
        // Then create the individual stops
        $stmt = $this->pdo->prepare("
            INSERT INTO dcc_timetable_stops (
                schedule_id, station_id, stop_sequence, arrival_time, departure_time,
                stop_type, dwell_time_minutes
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        // Departure station
        $stmt->execute([
            $scheduleId,
            $trainData['departure_station_id'],
            1, // stop_sequence
            $trainData['departure_time'],
            $trainData['departure_time'],
            'departure',
            0 // dwell_time_minutes
        ]);
        
        // Arrival station
        $stmt->execute([
            $scheduleId,
            $trainData['arrival_station_id'],
            2, // stop_sequence
            $trainData['arrival_time'],
            $trainData['arrival_time'],
            'arrival',
            0 // dwell_time_minutes
        ]);
    }
    
    private function getStationName($stationId) {
        $stmt = $this->pdo->prepare("SELECT name FROM dcc_stations WHERE id = ?");
        $stmt->execute([$stationId]);
        $result = $stmt->fetch();
        return $result ? $result['name'] : $stationId;
    }
    
    private function getRouteDisplayName($route) {
        // Handle both simple point-to-point routes and multi-hop routes
        if (isset($route['route_description'])) {
            return $route['route_description'];
        } elseif (isset($route['departure_station']) && isset($route['arrival_station'])) {
            return $this->getStationName($route['departure_station']) . ' → ' . $this->getStationName($route['arrival_station']);
        } elseif (isset($route['stations']) && count($route['stations']) >= 2) {
            $first = $route['stations'][0];
            $last = end($route['stations']);
            $firstName = isset($first['name']) ? $first['name'] : $this->getStationName($first['id']);
            $lastName = isset($last['name']) ? $last['name'] : $this->getStationName($last['id']);
            
            if (count($route['stations']) > 2) {
                return $firstName . ' → ... → ' . $lastName . ' (' . count($route['stations']) . ' stops)';
            } else {
                return $firstName . ' → ' . $lastName;
            }
        } else {
            return 'Unknown route';
        }
    }
    
    private function clearAllTrains() {
        try {
            $this->pdo->beginTransaction();
            
            // Get count before deletion
            $countStmt = $this->pdo->query("SELECT COUNT(*) as count FROM dcc_trains");
            $count = $countStmt->fetch()['count'];
            
            // Delete all related records in the correct order
            $this->pdo->exec("DELETE FROM dcc_train_schedules");
            $this->pdo->exec("DELETE FROM dcc_timetable_stops");
            $this->pdo->exec("DELETE FROM dcc_train_locomotives");
            $this->pdo->exec("DELETE FROM dcc_trains");
            
            $this->pdo->commit();
            
            return [
                'status' => 'success',
                'deleted_count' => $count
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function clearAutoTrains() {
        try {
            $this->pdo->beginTransaction();
            
            // Get count of AUTO trains before deletion
            $countStmt = $this->pdo->prepare("SELECT COUNT(*) as count FROM dcc_trains WHERE train_number LIKE 'AUTO%'");
            $countStmt->execute();
            $count = $countStmt->fetch()['count'];
            
            if ($count === 0) {
                $this->pdo->rollBack();
                return [
                    'status' => 'success',
                    'deleted_count' => 0,
                    'message' => 'No AUTO trains found to delete'
                ];
            }
            
            // Use the working SQL approach from delete_auto_trains.sql
            // Step 1: Delete timetable stops for schedules of AUTO trains
            $this->pdo->exec("
                DELETE ts FROM dcc_timetable_stops ts
                JOIN dcc_train_schedules s ON ts.schedule_id = s.id
                JOIN dcc_trains t ON s.train_id = t.id
                WHERE t.train_number LIKE 'AUTO%'
            ");
            
            // Step 2: Delete train schedules for AUTO trains
            $this->pdo->exec("
                DELETE s FROM dcc_train_schedules s
                JOIN dcc_trains t ON s.train_id = t.id
                WHERE t.train_number LIKE 'AUTO%'
            ");
            
            // Step 3: Delete locomotive assignments for AUTO trains
            $this->pdo->exec("
                DELETE tl FROM dcc_train_locomotives tl
                JOIN dcc_trains t ON tl.train_id = t.id
                WHERE t.train_number LIKE 'AUTO%'
            ");
            
            // Step 4: Finally delete the AUTO trains themselves
            $this->pdo->exec("
                DELETE FROM dcc_trains 
                WHERE train_number LIKE 'AUTO%'
            ");
            
            $this->pdo->commit();
            
            return [
                'status' => 'success',
                'deleted_count' => $count,
                'message' => "Successfully deleted $count AUTO trains"
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}

try {
    $generator = new AutomatedTrainGenerator();
    $result = $generator->handleRequest();
    
    if (!headers_sent()) {
        echo json_encode($result);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => $e->getMessage()
    ]);
}
?>
