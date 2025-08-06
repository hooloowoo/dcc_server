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
            case 'debug_tracks':
            // Debug endpoint to analyze track availability at a specific station
            if (!isset($_GET['station_id'])) {
                return $this->sendResponse(['error' => 'station_id parameter required'], 400);
            }
            return $this->debugTrackAvailability($_GET['station_id']);
            
        case 'check_conflicts':
                // Public read-only check for conflicts - no authentication required
                return $this->checkStationConflicts();
            case 'resolve_conflicts':
                // Resolve track conflicts requires admin or operator role
                if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
                    return ['status' => 'error', 'error' => 'Authentication required. Admin or operator role needed.'];
                }
                return $this->resolveTrackConflicts();
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
        
        // COMPREHENSIVE TRACK AVAILABILITY CHECK - Check EVERY station in the route
        foreach ($stationTimes as $index => $stationTime) {
            $stationId = $stationTime['station_id'];
            $arrivalTime = $stationTime['arrival'];
            $departureTime = $stationTime['departure'];
            
            // Get station name for better error messages
            $stationName = $this->getStationName($stationId);
            
            // Check track availability with proper arrival/departure times
            $trackAvailability = $this->checkTrackAvailability($stationId, $arrivalTime, $departureTime);
            
            if (!$trackAvailability['available']) {
                $stationType = 'intermediate';
                if ($index === 0) {
                    $stationType = 'departure';
                } elseif ($index === count($stationTimes) - 1) {
                    $stationType = 'arrival';
                }
                
                $conflicts[] = "TRACK CONFLICT at {$stationType} station '{$stationName}' ({$stationId}): " .
                              "No tracks available from {$arrivalTime} to {$departureTime}. " .
                              "Occupied: {$trackAvailability['occupied_tracks']}/{$trackAvailability['total_tracks']} tracks. " .
                              "Conflicting trains: {$trackAvailability['conflicting_trains']}";
            }
        }
        
        // ADDITIONAL VALIDATION: Check for impossible scheduling scenarios
        for ($i = 0; $i < count($stationTimes) - 1; $i++) {
            $currentStation = $stationTimes[$i];
            $nextStation = $stationTimes[$i + 1];
            
            // Verify departure time is not before arrival time at same station
            if ($currentStation['departure'] < $currentStation['arrival']) {
                $conflicts[] = "SCHEDULING ERROR: Departure time {$currentStation['departure']} is before arrival time {$currentStation['arrival']} at station {$currentStation['station_id']}";
            }
            
            // Verify next arrival is after current departure
            if ($nextStation['arrival'] <= $currentStation['departure']) {
                $conflicts[] = "SCHEDULING ERROR: Next station arrival {$nextStation['arrival']} is not after departure {$currentStation['departure']} from previous station";
            }
        }
        
        // SEGMENT-BY-SEGMENT CONFLICT CHECK - Check each leg of the journey
        for ($i = 0; $i < count($stationTimes) - 1; $i++) {
            $currentStation = $stationTimes[$i];
            $nextStation = $stationTimes[$i + 1];
            
            $segmentDepTime = $currentStation['departure'];
            $segmentArrTime = $nextStation['arrival'];
            
            // Check for overlapping trains on this specific segment
            $stmt = $this->pdo->prepare("
                SELECT t.train_number, t.departure_time, t.arrival_time
                FROM dcc_trains t
                WHERE t.is_active = 1
                AND t.departure_station_id = ? 
                AND t.arrival_station_id = ? 
                AND (
                    -- Direct time overlap
                    (t.departure_time <= ? AND t.arrival_time > ?) 
                    OR (t.departure_time < ? AND t.arrival_time >= ?)
                    OR (t.departure_time >= ? AND t.departure_time < ?)
                    -- Buffer overlap (trains too close together)
                    OR (ABS(TIME_TO_SEC(t.departure_time) - TIME_TO_SEC(?)) < 600)  -- 10 min buffer
                    OR (ABS(TIME_TO_SEC(t.arrival_time) - TIME_TO_SEC(?)) < 600)    -- 10 min buffer
                )
            ");
            
            $stmt->execute([
                $currentStation['station_id'], 
                $nextStation['station_id'], 
                $segmentDepTime, $segmentDepTime, 
                $segmentArrTime, $segmentArrTime, 
                $segmentDepTime, $segmentArrTime,
                $segmentDepTime,
                $segmentArrTime
            ]);
            
            $overlappingTrains = $stmt->fetchAll();
            if (!empty($overlappingTrains)) {
                $trainList = array_map(function($train) {
                    return "{$train['train_number']} ({$train['departure_time']}-{$train['arrival_time']})";
                }, $overlappingTrains);
                
                $fromStation = $this->getStationName($currentStation['station_id']);
                $toStation = $this->getStationName($nextStation['station_id']);
                
                $conflicts[] = "ROUTE SEGMENT CONFLICT: {$fromStation} → {$toStation} segment ({$segmentDepTime}-{$segmentArrTime}) " .
                              "conflicts with existing trains: " . implode(', ', $trainList);
            }
        }
        
        return $conflicts;
    }
    
    private function estimateTravelTime($fromStation, $toStation, $locomotiveMaxSpeed, $locomotiveId = null) {
        // Get the distance and track speed limit between stations
        $stmt = $this->pdo->prepare("
            SELECT distance_km, track_speed_limit 
            FROM dcc_station_connections 
            WHERE (from_station_id = ? AND to_station_id = ?) 
               OR (bidirectional = 1 AND from_station_id = ? AND to_station_id = ?)
            LIMIT 1
        ");
        $stmt->execute([$fromStation, $toStation, $toStation, $fromStation]);
        
        $connection = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$connection) {
            // No direct connection found, return default time
            return 30;
        }
        
        $distance_km = floatval($connection['distance_km']);
        $track_speed_limit = intval($connection['track_speed_limit']) ?: 80; // Default if null
        
        // Get actual locomotive max speed if locomotive ID is provided
        $actual_locomotive_speed = $locomotiveMaxSpeed;
        if ($locomotiveId) {
            $locoStmt = $this->pdo->prepare("SELECT max_speed_kmh FROM dcc_locomotives WHERE id = ?");
            $locoStmt->execute([$locomotiveId]);
            $locoData = $locoStmt->fetch(PDO::FETCH_ASSOC);
            if ($locoData && $locoData['max_speed_kmh']) {
                $actual_locomotive_speed = intval($locoData['max_speed_kmh']);
            }
        }
        
        // Calculate actual speed as minimum of locomotive speed and track speed limit
        $actual_speed = min($actual_locomotive_speed, $track_speed_limit);
        
        // Calculate travel time: Time = Distance ÷ Speed
        $travel_time_hours = $distance_km / $actual_speed;
        $travel_time_minutes = $travel_time_hours * 60;
        
        // Add acceleration/deceleration time (2-5 minutes depending on distance)
        $accel_decel_time = min(5, max(2, $distance_km * 0.5));
        $total_time = $travel_time_minutes + $accel_decel_time;
        
        // Round to nearest minute and ensure minimum time of 5 minutes
        return max(5, round($total_time));
    }
    
    // Helper function to get locomotive max speed
    private function getLocomotiveMaxSpeed($locomotiveId) {
        $stmt = $this->pdo->prepare("SELECT max_speed_kmh FROM dcc_locomotives WHERE id = ? AND is_active = 1");
        $stmt->execute([$locomotiveId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? intval($result['max_speed_kmh']) : 80; // Default to 80 km/h if not found
    }
    
    private function checkBasicConflicts($departure, $arrival, $depTime, $arrTime) {
        $conflicts = [];
        
        // COMPREHENSIVE STATION TRACK AVAILABILITY CHECK
        // Check departure station track availability
        $departureTrackAvailability = $this->checkTrackAvailability($departure, $depTime, $depTime);
        if (!$departureTrackAvailability['available']) {
            $depStationName = $this->getStationName($departure);
            $conflicts[] = "DEPARTURE STATION CONFLICT: Station '{$depStationName}' ({$departure}) " .
                          "has no available tracks at departure time {$depTime}. " .
                          "Occupied: {$departureTrackAvailability['occupied_tracks']}/{$departureTrackAvailability['total_tracks']} tracks. " .
                          "Conflicting trains: {$departureTrackAvailability['conflicting_trains']}";
        }
        
        // Check arrival station track availability
        $arrivalTrackAvailability = $this->checkTrackAvailability($arrival, $arrTime, $arrTime);
        if (!$arrivalTrackAvailability['available']) {
            $arrStationName = $this->getStationName($arrival);
            $conflicts[] = "ARRIVAL STATION CONFLICT: Station '{$arrStationName}' ({$arrival}) " .
                          "has no available tracks at arrival time {$arrTime}. " .
                          "Occupied: {$arrivalTrackAvailability['occupied_tracks']}/{$arrivalTrackAvailability['total_tracks']} tracks. " .
                          "Conflicting trains: {$arrivalTrackAvailability['conflicting_trains']}";
        }
        
        // DIRECT ROUTE CONFLICT CHECK - Look for trains on the same route with overlapping times
        $stmt = $this->pdo->prepare("
            SELECT t.train_number, t.departure_time, t.arrival_time, t.locomotive_number
            FROM dcc_trains t 
            WHERE t.is_active = 1
            AND t.departure_station_id = ? 
            AND t.arrival_station_id = ? 
            AND (
                -- Direct time overlap scenarios
                (t.departure_time <= ? AND t.arrival_time > ?) 
                OR (t.departure_time < ? AND t.arrival_time >= ?)
                OR (t.departure_time >= ? AND t.departure_time < ?)
                -- Safety buffer overlap (trains too close together)
                OR (ABS(TIME_TO_SEC(t.departure_time) - TIME_TO_SEC(?)) < 900)  -- 15 min departure buffer
                OR (ABS(TIME_TO_SEC(t.arrival_time) - TIME_TO_SEC(?)) < 900)    -- 15 min arrival buffer
            )
        ");
        
        $stmt->execute([
            $departure, 
            $arrival, 
            $depTime, $depTime, 
            $arrTime, $arrTime, 
            $depTime, $arrTime,
            $depTime,
            $arrTime
        ]);
        
        $overlappingTrains = $stmt->fetchAll();
        if (!empty($overlappingTrains)) {
            $trainList = array_map(function($train) {
                return "Train {$train['train_number']} (Loco: {$train['locomotive_number']}) " .
                       "running {$train['departure_time']}-{$train['arrival_time']}";
            }, $overlappingTrains);
            
            $depStationName = $this->getStationName($departure);
            $arrStationName = $this->getStationName($arrival);
            
            $conflicts[] = "ROUTE SCHEDULING CONFLICT: Route {$depStationName} → {$arrStationName} " .
                          "({$depTime}-{$arrTime}) conflicts with existing trains: " . 
                          implode('; ', $trainList);
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
    
    private function calculateReturnTime($route, $departureTime, $input, $locomotiveId = null) {
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
            // Simple route - estimate travel time with locomotive speed if available
            $maxSpeed = $input['max_speed'];
            if ($locomotiveId) {
                $locoSpeed = $this->getLocomotiveMaxSpeed($locomotiveId);
                $maxSpeed = min($maxSpeed, $locoSpeed);
            }
            $travelTime = $this->estimateTravelTime($departureStationId, $arrivalStationId, $maxSpeed, $locomotiveId);
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
    
    /**
     * Debug track availability at a specific station
     */
    private function debugTrackAvailability($stationId) {
        try {
            // Get station info
            $stmt = $this->pdo->prepare("SELECT name FROM dcc_stations WHERE id = ?");
            $stmt->execute([$stationId]);
            $station = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$station) {
                return [
                    'error' => "Station {$stationId} not found",
                    'station_id' => $stationId
                ];
            }
            
            // Get track count
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as track_count, 
                       GROUP_CONCAT(CONCAT('Track ', track_number, ' (', track_name, ')') SEPARATOR ', ') as track_list
                FROM dcc_station_tracks 
                WHERE station_id = ? AND is_active = 1
            ");
            $stmt->execute([$stationId]);
            $trackInfo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get all trains using this station
            $stmt = $this->pdo->prepare("
                SELECT 
                    train_number, departure_station_id, arrival_station_id,
                    departure_time, arrival_time,
                    CASE 
                        WHEN departure_station_id = ? THEN 'DEPARTURE'
                        WHEN arrival_station_id = ? THEN 'ARRIVAL'
                        ELSE 'OTHER'
                    END as usage_type
                FROM dcc_trains 
                WHERE is_active = 1 
                AND (departure_station_id = ? OR arrival_station_id = ?)
                ORDER BY 
                    CASE WHEN departure_station_id = ? THEN departure_time ELSE arrival_time END
            ");
            $stmt->execute([$stationId, $stationId, $stationId, $stationId, $stationId]);
            $trains = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Analyze conflicts for each hour of the day
            $hourlyAnalysis = [];
            for ($hour = 0; $hour < 24; $hour++) {
                $timeStart = sprintf('%02d:00:00', $hour);
                $timeEnd = sprintf('%02d:59:59', $hour);
                
                $hourTrains = array_filter($trains, function($train) use ($timeStart, $timeEnd, $stationId) {
                    $relevantTime = ($train['departure_station_id'] === $stationId) ? 
                        $train['departure_time'] : $train['arrival_time'];
                    return $relevantTime >= $timeStart && $relevantTime <= $timeEnd;
                });
                
                if (!empty($hourTrains)) {
                    $hourlyAnalysis[$hour] = [
                        'time_window' => $timeStart . ' - ' . $timeEnd,
                        'train_count' => count($hourTrains),
                        'exceeds_capacity' => count($hourTrains) > $trackInfo['track_count'],
                        'trains' => array_map(function($train) use ($stationId) {
                            $time = ($train['departure_station_id'] === $stationId) ? 
                                $train['departure_time'] : $train['arrival_time'];
                            return $train['train_number'] . ' (' . $train['usage_type'] . ' at ' . $time . ')';
                        }, $hourTrains)
                    ];
                }
            }
            
            return [
                'station_id' => $stationId,
                'station_name' => $station['name'],
                'track_count' => $trackInfo['track_count'],
                'track_list' => $trackInfo['track_list'],
                'total_trains' => count($trains),
                'effective_capacity' => max(1, $trackInfo['track_count'] - 1), // Reserve 1 for emergency
                'hourly_analysis' => $hourlyAnalysis,
                'peak_conflicts' => array_filter($hourlyAnalysis, function($hour) {
                    return $hour['exceeds_capacity'];
                }),
                'all_trains' => $trains
            ];
            
        } catch (Exception $e) {
            return [
                'error' => 'Debug failed: ' . $e->getMessage(),
                'station_id' => $stationId
            ];
        }
    }

    private function checkStationConflicts() {
        try {
            // Public read-only analysis of station conflicts
            $conflictingStations = [];
            $summary = [
                'total_stations_checked' => 0,
                'stations_with_conflicts' => 0,
                'total_trains_affected' => 0,
                'worst_station' => null,
                'worst_conflict_count' => 0
            ];
            
            // Get all stations and their track counts
            $stmt = $this->pdo->query("
                SELECT s.id, s.name, COUNT(st.id) as track_count
                FROM dcc_stations s
                LEFT JOIN dcc_station_tracks st ON s.id = st.station_id AND st.is_active = 1
                WHERE s.is_active = 1
                GROUP BY s.id, s.name
                HAVING track_count > 0
                ORDER BY s.name
            ");
            $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $summary['total_stations_checked'] = count($stations);
            
            foreach ($stations as $station) {
                $stationId = $station['id'];
                $trackCount = $station['track_count'];
                
                // Find all trains using this station in the next 24 hours
                $stmt = $this->pdo->prepare("
                    SELECT 
                        t.id, t.train_number, t.departure_station_id, t.arrival_station_id,
                        t.departure_time, t.arrival_time,
                        CASE 
                            WHEN t.departure_station_id = ? THEN t.departure_time
                            WHEN t.arrival_station_id = ? THEN t.arrival_time
                            ELSE LEAST(t.departure_time, t.arrival_time)
                        END as usage_time,
                        CASE 
                            WHEN t.departure_station_id = ? AND t.arrival_station_id = ? THEN 'transit'
                            WHEN t.departure_station_id = ? THEN 'departure'
                            WHEN t.arrival_station_id = ? THEN 'arrival'
                            ELSE 'unknown'
                        END as usage_type
                    FROM dcc_trains t
                    WHERE t.is_active = 1 
                    AND (t.departure_station_id = ? OR t.arrival_station_id = ?)
                    ORDER BY usage_time ASC
                ");
                $stmt->execute([$stationId, $stationId, $stationId, $stationId, $stationId, $stationId, $stationId, $stationId]);
                $stationTrains = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($stationTrains)) {
                    continue;
                }
                
                // Group trains by hourly time windows for conflict analysis
                $timeWindows = [];
                foreach ($stationTrains as $train) {
                    $time = $train['usage_time'];
                    $window = floor(strtotime($time) / 3600) * 3600; // 1-hour windows
                    
                    if (!isset($timeWindows[$window])) {
                        $timeWindows[$window] = [];
                    }
                    $timeWindows[$window][] = $train;
                }
                
                // Check each time window for overcrowding
                $stationConflicts = [];
                foreach ($timeWindows as $window => $trains) {
                    if (count($trains) > $trackCount) {
                        $stationConflicts[] = [
                            'window_start' => date('H:i', $window),
                            'window_end' => date('H:i', $window + 3600),
                            'trains_count' => count($trains),
                            'tracks_available' => $trackCount,
                            'excess_trains' => count($trains) - $trackCount,
                            'affected_trains' => array_map(function($t) {
                                return $t['train_number'] . ' (' . $t['usage_type'] . ' ' . $t['usage_time'] . ')';
                            }, $trains)
                        ];
                        $summary['total_trains_affected'] += count($trains);
                    }
                }
                
                if (!empty($stationConflicts)) {
                    $totalConflicts = array_sum(array_column($stationConflicts, 'excess_trains'));
                    $conflictingStations[] = [
                        'station_id' => $station['id'],
                        'station_name' => $station['name'],
                        'track_count' => $trackCount,
                        'total_trains' => count($stationTrains),
                        'conflict_windows' => count($stationConflicts),
                        'total_excess_trains' => $totalConflicts,
                        'conflicts' => $stationConflicts
                    ];
                    
                    if ($totalConflicts > $summary['worst_conflict_count']) {
                        $summary['worst_conflict_count'] = $totalConflicts;
                        $summary['worst_station'] = $station['name'];
                    }
                }
            }
            
            $summary['stations_with_conflicts'] = count($conflictingStations);
            
            return [
                'status' => 'success',
                'timestamp' => date('Y-m-d H:i:s'),
                'summary' => $summary,
                'conflicting_stations' => $conflictingStations,
                'recommendations' => $this->generateConflictRecommendations($conflictingStations)
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    private function generateConflictRecommendations($conflictingStations) {
        $recommendations = [];
        
        if (empty($conflictingStations)) {
            $recommendations[] = "✅ No track conflicts detected! All stations have adequate capacity.";
            return $recommendations;
        }
        
        $recommendations[] = "⚠️ Track capacity issues detected at " . count($conflictingStations) . " station(s).";
        
        foreach ($conflictingStations as $station) {
            if ($station['total_excess_trains'] > 10) {
                $recommendations[] = "🚨 CRITICAL: {$station['station_name']} has {$station['total_excess_trains']} excess trains - consider removing AUTO trains or adding more tracks.";
            } elseif ($station['total_excess_trains'] > 5) {
                $recommendations[] = "⚠️ HIGH: {$station['station_name']} has {$station['total_excess_trains']} excess trains - reduce train frequency.";
            } else {
                $recommendations[] = "⚡ MEDIUM: {$station['station_name']} has {$station['total_excess_trains']} excess trains - minor adjustments needed.";
            }
        }
        
        $recommendations[] = "💡 Use action=resolve_conflicts (requires authentication) to automatically remove excess AUTO trains.";
        $recommendations[] = "💡 Consider increasing train frequency intervals or reducing the number of generated routes.";
        
        return $recommendations;
    }
    
    private function resolveTrackConflicts() {
        try {
            $this->pdo->beginTransaction();
            
            // Find all stations with track conflicts
            $conflictingStations = [];
            $removedTrains = [];
            
            // Get all stations and their track counts
            $stmt = $this->pdo->query("
                SELECT s.id, s.name, COUNT(st.id) as track_count
                FROM dcc_stations s
                LEFT JOIN dcc_station_tracks st ON s.id = st.station_id AND st.is_active = 1
                WHERE s.is_active = 1
                GROUP BY s.id, s.name
                HAVING track_count > 0
            ");
            $stations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($stations as $station) {
                $stationId = $station['id'];
                $trackCount = $station['track_count'];
                
                // Find all time periods where trains overlap at this station
                $stmt = $this->pdo->prepare("
                    SELECT 
                        t.id, t.train_number, t.departure_station_id, t.arrival_station_id,
                        t.departure_time, t.arrival_time,
                        CASE 
                            WHEN t.departure_station_id = ? THEN t.departure_time
                            WHEN t.arrival_station_id = ? THEN t.arrival_time
                            ELSE LEAST(t.departure_time, t.arrival_time)
                        END as usage_time,
                        CASE 
                            WHEN t.departure_station_id = ? AND t.arrival_station_id = ? THEN 'transit'
                            WHEN t.departure_station_id = ? THEN 'departure'
                            WHEN t.arrival_station_id = ? THEN 'arrival'
                            ELSE 'unknown'
                        END as usage_type
                    FROM dcc_trains t
                    WHERE t.is_active = 1 
                    AND (t.departure_station_id = ? OR t.arrival_station_id = ?)
                    ORDER BY usage_time ASC
                ");
                $stmt->execute([$stationId, $stationId, $stationId, $stationId, $stationId, $stationId, $stationId, $stationId]);
                $stationTrains = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Group trains by 30-minute time windows and check for conflicts
                $timeWindows = [];
                foreach ($stationTrains as $train) {
                    $time = $train['usage_time'];
                    $window = floor(strtotime($time) / 1800) * 1800; // 30-minute windows
                    
                    if (!isset($timeWindows[$window])) {
                        $timeWindows[$window] = [];
                    }
                    $timeWindows[$window][] = $train;
                }
                
                // Check each time window for overcrowding
                foreach ($timeWindows as $window => $trains) {
                    if (count($trains) > $trackCount) {
                        $conflictingStations[] = [
                            'station' => $station,
                            'window' => date('H:i', $window),
                            'trains' => count($trains),
                            'tracks' => $trackCount,
                            'excess' => count($trains) - $trackCount
                        ];
                        
                        // Remove excess AUTO trains (prioritize keeping non-AUTO trains)
                        $autoTrains = array_filter($trains, fn($t) => strpos($t['train_number'], 'AUTO') === 0);
                        $nonAutoTrains = array_filter($trains, fn($t) => strpos($t['train_number'], 'AUTO') !== 0);
                        
                        // Sort AUTO trains by train number to remove them consistently
                        usort($autoTrains, fn($a, $b) => strcmp($a['train_number'], $b['train_number']));
                        
                        $trainsToRemove = count($trains) - $trackCount;
                        $removed = 0;
                        
                        // Remove AUTO trains first
                        foreach ($autoTrains as $train) {
                            if ($removed >= $trainsToRemove) break;
                            
                            $stmt = $this->pdo->prepare("DELETE FROM dcc_trains WHERE id = ?");
                            $stmt->execute([$train['id']]);
                            
                            $removedTrains[] = [
                                'train_number' => $train['train_number'],
                                'station' => $station['name'],
                                'time' => $train['usage_time'],
                                'type' => $train['usage_type']
                            ];
                            $removed++;
                        }
                        
                        // If still need to remove more, remove non-AUTO trains
                        if ($removed < $trainsToRemove) {
                            foreach ($nonAutoTrains as $train) {
                                if ($removed >= $trainsToRemove) break;
                                
                                $stmt = $this->pdo->prepare("DELETE FROM dcc_trains WHERE id = ?");
                                $stmt->execute([$train['id']]);
                                
                                $removedTrains[] = [
                                    'train_number' => $train['train_number'],
                                    'station' => $station['name'],
                                    'time' => $train['usage_time'],
                                    'type' => $train['usage_type']
                                ];
                                $removed++;
                            }
                        }
                    }
                }
            }
            
            $this->pdo->commit();
            
            return [
                'status' => 'success',
                'message' => 'Track conflicts resolved',
                'conflicts_found' => count($conflictingStations),
                'trains_removed' => count($removedTrains),
                'details' => [
                    'conflicting_stations' => $conflictingStations,
                    'removed_trains' => $removedTrains
                ]
            ];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
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
    
    /**
     * Check if tracks are available at a station during a specific time period
     * SIMPLIFIED AND RELIABLE VERSION - Fixed complex SQL issues
     */
    private function checkTrackAvailability($stationId, $arrivalTime, $departureTime) {
        // Get total number of tracks at the station
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as track_count
            FROM dcc_station_tracks 
            WHERE station_id = ? AND is_active = 1
        ");
        $stmt->execute([$stationId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $totalTracks = $result['track_count'];
        
        if ($totalTracks == 0) {
            return [
                'available' => false,
                'error' => "No tracks configured for station {$stationId}",
                'total_tracks' => 0,
                'occupied_tracks' => 0,
                'conflicting_trains' => 'Station has no tracks configured'
            ];
        }
        
        // SAFE TIME BUFFER CALCULATION - Handle edge cases properly
        $arrivalTimeWithBuffer = $arrivalTime;
        $departureTimeWithBuffer = $departureTime;
        
        // Add 15-minute buffers (safely)
        try {
            $arrivalSeconds = strtotime("1970-01-01 $arrivalTime") - 900; // 15 min before
            $departureSeconds = strtotime("1970-01-01 $departureTime") + 900; // 15 min after
            
            // Handle day boundaries - if buffer goes negative, start from 00:00
            if ($arrivalSeconds < 0) {
                $arrivalTimeWithBuffer = '00:00:00';
            } else {
                $arrivalTimeWithBuffer = date('H:i:s', $arrivalSeconds);
            }
            
            // Handle day boundaries - if buffer exceeds 24 hours, cap at 23:59
            if ($departureSeconds >= 86400) {
                $departureTimeWithBuffer = '23:59:59';
            } else {
                $departureTimeWithBuffer = date('H:i:s', $departureSeconds);
            }
        } catch (Exception $e) {
            // Fallback to original times if time calculation fails
            $arrivalTimeWithBuffer = $arrivalTime;
            $departureTimeWithBuffer = $departureTime;
        }
        
        // For terminus stations (same arrival/departure time), enforce minimum dwell time
        if ($arrivalTime === $departureTime) {
            try {
                $departureSeconds = strtotime("1970-01-01 $arrivalTime") + 1800; // 30 min minimum
                if ($departureSeconds >= 86400) {
                    $departureTimeWithBuffer = '23:59:59';
                } else {
                    $departureTimeWithBuffer = date('H:i:s', $departureSeconds);
                }
            } catch (Exception $e) {
                $departureTimeWithBuffer = $departureTime;
            }
        }
        
        // SIMPLIFIED CONFLICT DETECTION - Count trains using this station during our time window
        $conflictingTrains = [];
        $occupiedTracks = 0;
        
        // Check trains departing from this station
        $stmt = $this->pdo->prepare("
            SELECT train_number, departure_time
            FROM dcc_trains 
            WHERE is_active = 1 
            AND departure_station_id = ? 
            AND departure_time BETWEEN ? AND ?
        ");
        $stmt->execute([$stationId, $arrivalTimeWithBuffer, $departureTimeWithBuffer]);
        $departingTrains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($departingTrains as $train) {
            $conflictingTrains[] = "Train {$train['train_number']} departing at {$train['departure_time']}";
            $occupiedTracks++;
        }
        
        // Check trains arriving at this station
        $stmt = $this->pdo->prepare("
            SELECT train_number, arrival_time
            FROM dcc_trains 
            WHERE is_active = 1 
            AND arrival_station_id = ? 
            AND arrival_time BETWEEN ? AND ?
        ");
        $stmt->execute([$stationId, $arrivalTimeWithBuffer, $departureTimeWithBuffer]);
        $arrivingTrains = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($arrivingTrains as $train) {
            $conflictingTrains[] = "Train {$train['train_number']} arriving at {$train['arrival_time']}";
            $occupiedTracks++;
        }
        
        // CONSERVATIVE APPROACH: Reserve 1 track for emergencies if station has > 1 track
        $effectiveAvailableTracks = $totalTracks > 1 ? $totalTracks - 1 : $totalTracks;
        $availableTracks = $effectiveAvailableTracks - $occupiedTracks;
        
        $conflictingTrainsText = empty($conflictingTrains) ? 'None' : implode('; ', $conflictingTrains);
        
        return [
            'available' => $availableTracks > 0,
            'total_tracks' => $totalTracks,
            'effective_tracks' => $effectiveAvailableTracks,
            'occupied_tracks' => $occupiedTracks,
            'available_tracks' => $availableTracks,
            'conflicting_trains' => $conflictingTrainsText,
            'buffer_times' => [
                'arrival_buffer' => $arrivalTimeWithBuffer,
                'departure_buffer' => $departureTimeWithBuffer,
                'original_arrival' => $arrivalTime,
                'original_departure' => $departureTime
            ],
            'error' => $availableTracks <= 0 ? 
                "Station {$stationId} has {$occupiedTracks} trains during time window {$arrivalTimeWithBuffer}-{$departureTimeWithBuffer}, exceeding {$effectiveAvailableTracks} effective tracks (1 reserved for emergency). Total tracks: {$totalTracks}" : null
        ];
    }
    
    /**
     * Find an available track at a station for a specific time period
     */
    private function findAvailableTrack($stationId, $arrivalTime, $departureTime) {
        // Get all tracks at the station
        $stmt = $this->pdo->prepare("
            SELECT id, track_number, track_name, track_type
            FROM dcc_station_tracks 
            WHERE station_id = ? AND is_active = 1
            ORDER BY track_type = 'platform' DESC, track_number ASC
        ");
        $stmt->execute([$stationId]);
        $tracks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($tracks)) {
            return null;
        }
        
        // Check each track for availability
        foreach ($tracks as $track) {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as conflicts
                FROM dcc_trains t
                WHERE t.is_active = 1
                AND (
                    (t.departure_station_id = ? AND t.departure_time >= ? AND t.departure_time <= ?) OR
                    (t.arrival_station_id = ? AND t.arrival_time >= ? AND t.arrival_time <= ?) OR
                    (t.departure_station_id = ? AND t.arrival_station_id = ? AND t.departure_time <= ? AND t.arrival_time >= ?)
                )
            ");
            
            $stmt->execute([
                $stationId, $arrivalTime, $departureTime,
                $stationId, $arrivalTime, $departureTime,
                $stationId, $stationId, $departureTime, $arrivalTime
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result['conflicts'] == 0) {
                return $track;
            }
        }
        
        return null; // No available tracks
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
