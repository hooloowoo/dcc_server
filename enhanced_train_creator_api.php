<?php
/**
 * Enhanced Train Creation API with Route Validation
 * Validates routes and checks availability before creating trains
 */

require_once 'config.php';
require_once 'auth_utils.php';
// require_once 'collision_detection.php';  // Using embedded functions instead

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
 * Enhanced train creation with comprehensive validation
 */
function createTrainWithValidation($conn, $trainData) {
    $conn->beginTransaction();
    
    try {
        // 1. Validate basic train data
        if (empty($trainData['train_number'])) {
            throw new Exception('Train number is required');
        }
        
        if (empty($trainData['departure_station_id']) || empty($trainData['arrival_station_id'])) {
            throw new Exception('Both departure and arrival stations are required');
        }
        
        if (empty($trainData['departure_time']) || empty($trainData['arrival_time'])) {
            throw new Exception('Both departure and arrival times are required');
        }
        
        // Require locomotive assignment
        if (empty($trainData['locomotive_id'])) {
            throw new Exception('Locomotive assignment is required for train creation');
        }
        
        // Validate station ID lengths
        if (strlen($trainData['departure_station_id']) > 8) {
            throw new Exception('Departure station ID must be maximum 8 characters');
        }
        
        if (strlen($trainData['arrival_station_id']) > 8) {
            throw new Exception('Arrival station ID must be maximum 8 characters');
        }
        
        // Check if train number already exists
        $stmt = $conn->prepare("SELECT id FROM dcc_trains WHERE train_number = ?");
        $stmt->execute([$trainData['train_number']]);
        if ($stmt->fetch()) {
            throw new Exception('Train number already exists');
        }
        
        // 2. Validate route exists
        $routeValidation = validateRoute($conn, $trainData['departure_station_id'], $trainData['arrival_station_id']);
        if (!$routeValidation['valid']) {
            throw new Exception('Route validation failed: ' . $routeValidation['error']);
        }
        
        // 3. Validate locomotive availability
        $locomotiveAvailable = validateLocomotiveAvailability($conn, $trainData['locomotive_id'], $trainData['departure_station_id'], $trainData['departure_time']);
        if (!$locomotiveAvailable['available']) {
            throw new Exception('Locomotive not available: ' . $locomotiveAvailable['reason']);
        }
        
        // 4. Check availability using embedded collision detection (proven from trains_api.php)
        $conflicts = checkRouteConflictsEmbedded(
            $conn,
            $trainData['departure_station_id'],
            $trainData['arrival_station_id'],
            $trainData['departure_time'],
            $trainData['arrival_time']
        );
        
        if (!empty($conflicts)) {
            $conflictMessages = [];
            foreach ($conflicts as $conflict) {
                $conflictMessages[] = sprintf(
                    "Collision with train %s on track segment %s↔%s (overlap: %s to %s)",
                    $conflict['conflicting_train'],
                    $conflict['from_station'],
                    $conflict['to_station'], 
                    $conflict['overlap_start'],
                    $conflict['overlap_end']
                );
            }
            throw new Exception('Route conflicts detected: ' . implode('; ', $conflictMessages));
        }
        
        // 5. Calculate final arrival time with custom waiting times if provided
        $waitingTimes = $trainData['waiting_times'] ?? [];
        $finalArrivalTime = $trainData['arrival_time'];
        
        if (!empty($waitingTimes)) {
            // Calculate station times with custom waiting times to get the accurate final arrival time
            $stationTimesWithWaiting = calculateStationTimesWithCustomWaiting(
                $conn, 
                $routeValidation['route'], 
                $trainData['departure_time'], 
                $waitingTimes, 
                $trainData['max_speed_kmh'] ?? 80
            );
            
            // Get the arrival time from the last station
            if (!empty($stationTimesWithWaiting)) {
                $lastStation = end($stationTimesWithWaiting);
                $finalArrivalTime = $lastStation['arrival'];
            }
        }
        
        // 6. Create train record with correct arrival time
        $stmt = $conn->prepare("
            INSERT INTO dcc_trains (
                train_number, train_name, train_type, route,
                departure_station_id, arrival_station_id, departure_time, arrival_time,
                max_speed_kmh, consist_notes, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $routeDescription = implode(' → ', $routeValidation['route']['path']);
        
        $stmt->execute([
            $trainData['train_number'],
            $trainData['train_name'] ?? null,
            $trainData['train_type'] ?? 'passenger',
            $routeDescription,
            $trainData['departure_station_id'],
            $trainData['arrival_station_id'],
            $trainData['departure_time'],
            $finalArrivalTime,  // Use the calculated final arrival time
            $trainData['max_speed_kmh'] ?? null,
            $trainData['consist_notes'] ?? null,
            1
        ]);
        
        $trainId = $conn->lastInsertId();
        
        // 7. Block resources and create schedule
        try {
            $blockResult = blockRouteResourcesWithWaitingTimes(
                $conn,
                $trainId,
                $routeValidation['route'],
                $trainData['departure_time'],
                $finalArrivalTime,  // Use the calculated final arrival time
                $trainData['train_number'],
                $waitingTimes,
                $trainData['max_speed_kmh'] ?? 80
            );
            
            if (!$blockResult || !$blockResult['schedule_id']) {
                throw new Exception('Failed to create schedule - blockRouteResources returned no schedule_id');
            }
        } catch (Exception $e) {
            error_log("Schedule creation failed for train {$trainData['train_number']}: " . $e->getMessage());
            throw new Exception('Failed to create train schedule: ' . $e->getMessage());
        }
        
        // 7. Assign locomotive (now guaranteed to be provided)
        $stmt = $conn->prepare("
            INSERT INTO dcc_train_locomotives (
                train_id, locomotive_id, position_in_train, is_lead_locomotive
            ) VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$trainId, $trainData['locomotive_id'], 1, 1]);
        
        $conn->commit();
        
        return [
            'status' => 'success',
            'train_id' => $trainId,
            'schedule_id' => $blockResult['schedule_id'],
            'route' => $routeValidation['route'],
            'station_times' => $blockResult['station_times'],
            'message' => 'Train created successfully with validated route and schedule'
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
}

/**
 * Validate route between two stations using centralized route calculation
 */
function validateRoute($conn, $departureStation, $arrivalStation) {
    // Use the embedded route calculation - fallback to calculateRoute if needed
    if (function_exists('calculateRouteEmbedded')) {
        $result = calculateRouteEmbedded($conn, $departureStation, $arrivalStation);
    } else {
        $result = calculateRoute($conn, $departureStation, $arrivalStation);
    }
    
    if (!$result['success']) {
        return [
            'valid' => false,
            'error' => $result['error']
        ];
    }
    
    return [
        'valid' => true,
        'route' => $result
    ];
}

/**
 * Legacy wrapper for checkRouteAvailability - now uses centralized collision detection
 * @deprecated Use validateRouteAvailability from collision_detection.php directly
 */
function checkRouteAvailability($conn, $route, $departureTime, $arrivalTime) {
    // Extract station IDs from route
    $departureStation = $route['path'][0];
    $arrivalStation = end($route['path']);
    
    // Use centralized collision detection
    return validateRouteAvailability($conn, $departureStation, $arrivalStation, $departureTime, $arrivalTime);
}

/**
 * Block route resources and create schedule with validated route information
 */
function blockRouteResources($conn, $trainId, $route, $departureTime, $arrivalTime, $trainNumber) {
    // Use centralized station time calculation
    $stationTimes = calculateStationTimes($conn, $route, $departureTime, $arrivalTime);
    
    // Prepare detailed route information for storage
    $routeInfo = json_encode([
        'path' => $route['path'],
        'distance' => $route['distance'],
        'connections' => $route['connections'] ?? [],
        'validation_time' => date('Y-m-d H:i:s'),
        'total_stations' => count($route['path']),
        'estimated_travel_time' => calculateTotalTravelTime($departureTime, $arrivalTime)
    ]);
    
    // Create schedule in the new dcc_train_schedules table with route information
    $stmt = $conn->prepare("
        INSERT INTO dcc_train_schedules (
            train_id, schedule_name, effective_date, schedule_type, frequency, 
            is_active
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $scheduleName = "Schedule for Train {$trainNumber} - Route: " . implode(' → ', $route['path']);
    
    $stmt->execute([
        $trainId,
        $scheduleName,
        date('Y-m-d'),
        'regular',
        'daily',
        1
    ]);
    
    $scheduleId = $conn->lastInsertId();
    
    // Create stops in dcc_timetable_stops with enhanced information
    foreach ($stationTimes as $index => $stationTime) {
        $stopType = 'intermediate';
        if ($index === 0) $stopType = 'origin';
        if ($index === count($stationTimes) - 1) $stopType = 'destination';
        
        // Add route validation notes for each stop
        $stopNotes = "Sequence: " . ($index + 1) . "/" . count($stationTimes);
        if (isset($route['connections']) && $index > 0) {
            $prevStation = $route['path'][$index - 1];
            $currentStation = $route['path'][$index];
            $connection = findConnectionInfo($route['connections'], $prevStation, $currentStation);
            if ($connection) {
                $stopNotes .= "\nConnection from {$prevStation}: {$connection['distance']}km";
            }
        }
        
        $stmt = $conn->prepare("
            INSERT INTO dcc_timetable_stops (
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
    
    // Create track occupancy records for route segments
    $stmt = $conn->prepare("SHOW TABLES LIKE 'dcc_track_occupancy'");
    $stmt->execute();
    if ($stmt->fetch()) {
        createRouteOccupancyRecords($conn, $scheduleId, $route, $stationTimes);
    }
    
    return [
        'schedule_id' => $scheduleId,
        'station_times' => $stationTimes,
        'route_info' => $routeInfo,
        'route_info' => $routeInfo,
        'total_stops' => count($stationTimes)
    ];
}

/**
 * Block route resources and create schedule with custom waiting times
 */
function blockRouteResourcesWithWaitingTimes($conn, $trainId, $route, $departureTime, $arrivalTime, $trainNumber, $waitingTimes = [], $maxSpeed = 80) {
    // Use custom waiting times calculation if provided, otherwise use standard calculation
    if (!empty($waitingTimes)) {
        $stationTimes = calculateStationTimesWithCustomWaiting($conn, $route, $departureTime, $waitingTimes, $maxSpeed);
    } else {
        $stationTimes = calculateStationTimes($conn, $route, $departureTime, $arrivalTime);
    }
    
    // Prepare detailed route information for storage
    $routeInfo = json_encode([
        'path' => $route['path'],
        'distance' => $route['distance'],
        'connections' => $route['connections'] ?? [],
        'validation_time' => date('Y-m-d H:i:s'),
        'total_stations' => count($route['path']),
        'estimated_travel_time' => calculateTotalTravelTime($departureTime, $arrivalTime),
        'custom_waiting_times' => $waitingTimes // Store the custom waiting times
    ]);
    
    // Create schedule in the new dcc_train_schedules table with route information
    $stmt = $conn->prepare("
        INSERT INTO dcc_train_schedules (
            train_id, schedule_name, effective_date, schedule_type, frequency, 
            is_active
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    $scheduleName = "Schedule for Train {$trainNumber} - Route: " . implode(' → ', $route['path']);
    
    $stmt->execute([
        $trainId,
        $scheduleName,
        date('Y-m-d'),
        'regular',
        'daily',
        1
    ]);
    
    $scheduleId = $conn->lastInsertId();
    
    // Create stops in dcc_timetable_stops with custom waiting times
    foreach ($stationTimes as $index => $stationTime) {
        $stopType = 'intermediate';
        if ($index === 0) $stopType = 'origin';
        if ($index === count($stationTimes) - 1) $stopType = 'destination';
        
        // Add route validation notes for each stop
        $stopNotes = "Sequence: " . ($index + 1) . "/" . count($stationTimes);
        if (isset($route['connections']) && $index > 0) {
            $prevStation = $route['path'][$index - 1];
            $currentStation = $route['path'][$index];
            $connection = findConnectionInfo($route['connections'], $prevStation, $currentStation);
            if ($connection) {
                $stopNotes .= "\nConnection from {$prevStation}: {$connection['distance']}km";
            }
        }
        
        $stmt = $conn->prepare("
            INSERT INTO dcc_timetable_stops (
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
    
    // Create track occupancy records for route segments
    $stmt = $conn->prepare("SHOW TABLES LIKE 'dcc_track_occupancy'");
    $stmt->execute();
    if ($stmt->fetch()) {
        createRouteOccupancyRecords($conn, $scheduleId, $route, $stationTimes);
    }
    
    return [
        'schedule_id' => $scheduleId,
        'station_times' => $stationTimes,
        'route_info' => $routeInfo,
        'total_stops' => count($stationTimes),
        'custom_waiting_times_used' => !empty($waitingTimes)
    ];
}

/**
 * Create track occupancy records
 */
function createTrackOccupancy($conn, $scheduleId, $stationTimes) {
    foreach ($stationTimes as $stationTime) {
        // Find available track at station
        $stmt = $conn->prepare("
            SELECT id FROM dcc_station_tracks 
            WHERE station_id = ? AND is_active = 1 
            ORDER BY track_type = 'platform' DESC, track_number
            LIMIT 1
        ");
        $stmt->execute([$stationTime['station_id']]);
        $track = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($track) {
            $stmt = $conn->prepare("
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
                $scheduleId,
                $scheduleId,
                $occupiedFrom,
                $occupiedUntil,
                'layover',
                1
            ]);
        }
    }
}

/**
 * Check for connection conflicts - Enhanced version using robust collision detection
 */
function checkConnectionConflicts($conn, $fromStation, $toStation, $startTime, $endTime) {
    $conflicts = [];
    
    // Get all active trains that have scheduling information
    $stmt = $conn->prepare("
        SELECT t.id, t.train_number, t.train_name, t.departure_station_id, t.arrival_station_id, 
               t.departure_time, t.arrival_time, t.max_speed_kmh
        FROM dcc_trains t
        WHERE t.is_active = 1 
          AND t.departure_station_id IS NOT NULL 
          AND t.arrival_station_id IS NOT NULL
          AND t.departure_time IS NOT NULL 
          AND t.arrival_time IS NOT NULL
    ");
    
    $stmt->execute();
    $existingTrains = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check each existing train for conflicts on this specific connection
    foreach ($existingTrains as $train) {
        $existingRoute = findShortestPath([], $train['departure_station_id'], $train['arrival_station_id'], $conn);
        if (!$existingRoute || !isset($existingRoute['path'])) {
            continue; // Skip trains with invalid routes
        }
        
        $existingStationTimes = calculateStationTimes($existingRoute, $train['departure_time'], $train['arrival_time']);
        
        // Check if this train uses our connection segment
        for ($i = 0; $i < count($existingRoute['path']) - 1; $i++) {
            $theirFromStation = $existingRoute['path'][$i];
            $theirToStation = $existingRoute['path'][$i + 1];
            
            // Check if it's the same bidirectional track segment
            $sameSegment = ($fromStation === $theirFromStation && $toStation === $theirToStation) ||
                          ($fromStation === $theirToStation && $toStation === $theirFromStation);
            
            if ($sameSegment && isset($existingStationTimes[$i]) && isset($existingStationTimes[$i + 1])) {
                $theirStartTime = $existingStationTimes[$i]['departure'];
                $theirEndTime = $existingStationTimes[$i + 1]['arrival'];
                
                // Check for time overlap
                if (timesOverlap($startTime, $endTime, $theirStartTime, $theirEndTime)) {
                    $conflicts[] = [
                        'type' => 'connection_conflict',
                        'from_station' => $fromStation,
                        'to_station' => $toStation,
                        'conflicting_train' => $train['train_number'],
                        'train_name' => $train['train_name'],
                        'our_times' => "$startTime - $endTime",
                        'their_times' => "$theirStartTime - $theirEndTime", 
                        'overlap_start' => max($startTime, $theirStartTime),
                        'overlap_end' => min($endTime, $theirEndTime),
                        'severity' => 'high'
                    ];
                }
            }
        }
    }
    
    return $conflicts;
}

/**
 * Check if two time ranges overlap
 */
function timesOverlap($start1, $end1, $start2, $end2) {
    // Convert times to minutes for easier comparison
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
 * Check if a route uses a specific connection
 */
function routeUsesConnection($route, $fromStation, $toStation) {
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
 * Calculate when a train uses a specific connection
 */
function calculateConnectionUsageTime($route, $fromStation, $toStation, $departureTime, $arrivalTime) {
    if (!$route || !isset($route['path'])) {
        return null;
    }
    
    $path = $route['path'];
    $stationTimes = calculateStationTimes($route, $departureTime, $arrivalTime);
    
    // Find the connection in the path
    for ($i = 0; $i < count($path) - 1; $i++) {
        $segmentFrom = $path[$i];
        $segmentTo = $path[$i + 1];
        
        if (($segmentFrom === $fromStation && $segmentTo === $toStation) ||
            ($segmentFrom === $toStation && $segmentTo === $fromStation)) {
            
            // Return the time this train occupies this connection
            return [
                'start' => $stationTimes[$i]['departure'],
                'end' => $stationTimes[$i + 1]['arrival']
            ];
        }
    }
    
    return null;
}

/**
 * Check for station track conflicts
 */
function checkStationConflicts($conn, $stationTime) {
    // Get track count
    $stmt = $conn->prepare("
        SELECT COUNT(*) as track_count
        FROM dcc_station_tracks 
        WHERE station_id = ? AND is_active = 1
    ");
    $stmt->execute([$stationTime['station_id']]);
    $trackCount = $stmt->fetch(PDO::FETCH_ASSOC)['track_count'];
    
    if ($trackCount == 0) {
        return [[
            'type' => 'no_tracks',
            'station_id' => $stationTime['station_id'],
            'severity' => 'critical'
        ]];
    }
    
    // Check concurrent usage
    $stmt = $conn->prepare("
        SELECT COUNT(*) as concurrent_trains
        FROM dcc_trains t
        WHERE t.is_active = 1
          AND (
              (t.departure_station_id = ? AND t.departure_time BETWEEN ? AND ?) OR
              (t.arrival_station_id = ? AND t.arrival_time BETWEEN ? AND ?)
          )
    ");
    
    $stmt->execute([
        $stationTime['station_id'], $stationTime['arrival'], $stationTime['departure'],
        $stationTime['station_id'], $stationTime['arrival'], $stationTime['departure']
    ]);
    
    $concurrentTrains = $stmt->fetch(PDO::FETCH_ASSOC)['concurrent_trains'];
    
    if ($concurrentTrains >= $trackCount) {
        return [[
            'type' => 'track_capacity',
            'station_id' => $stationTime['station_id'],
            'required' => $concurrentTrains + 1,
            'available' => $trackCount,
            'severity' => 'high'
        ]];
    }
    
    return [];
}

/**
 * Legacy functions - these are now provided by collision_detection.php
 * Keeping minimal versions for backward compatibility
 */

/**
 * Embedded collision detection from trains_api.php (proven working version)
 */
function checkRouteConflictsEmbedded($conn, $departureStation, $arrivalStation, $departureTime, $arrivalTime, $excludeTrainId = null) {
    $conflicts = [];
    
    // First, get the route for the new/updated train
    $route = calculateRouteEmbedded($conn, $departureStation, $arrivalStation);
    if (!$route['success']) {
        return []; // If no route exists, no conflicts to check
    }
    
    // Calculate the realistic station times for this train
    $stationTimes = calculateStationTimesEmbedded($conn, $route, $departureTime, $arrivalTime);
    
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
        $existingRoute = calculateRouteEmbedded($conn, $train['departure_station_id'], $train['arrival_station_id']);
        if (!$existingRoute['success']) {
            continue; // Skip trains with invalid routes
        }
        
        $existingStationTimes = calculateStationTimesEmbedded($conn, $existingRoute, $train['departure_time'], $train['arrival_time'], $train['max_speed_kmh'] ?? 80);
        
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
                    if (timesOverlapEmbedded($ourStartTime, $ourEndTime, $theirStartTime, $theirEndTime)) {
                        // Get station names for better display
                        $fromStationName = getStationName($conn, $ourFromStation);
                        $toStationName = getStationName($conn, $ourToStation);
                        
                        $conflicts[] = [
                            'type' => 'track_segment_conflict',
                            'conflicting_train_number' => $train['train_number'] ?? 'Unknown',
                            'conflicting_train_name' => $train['train_name'] ?? $train['train_number'] ?? 'Unknown',
                            'from_station_id' => $ourFromStation,
                            'to_station_id' => $ourToStation,
                            'from_station_name' => $fromStationName,
                            'to_station_name' => $toStationName,
                            'track_segment' => $fromStationName . ' ↔ ' . $toStationName,
                            'our_departure_time' => $ourStartTime,
                            'our_arrival_time' => $ourEndTime,
                            'their_departure_time' => $theirStartTime,
                            'their_arrival_time' => $theirEndTime,
                            'overlap_start_time' => max($ourStartTime, $theirStartTime),
                            'overlap_end_time' => min($ourEndTime, $theirEndTime),
                            'conflict_duration_minutes' => calculateTimeDifference(max($ourStartTime, $theirStartTime), min($ourEndTime, $theirEndTime)),
                            'severity' => 'critical',
                            'message' => sprintf(
                                "Track conflict with train %s (%s) on segment %s. Overlap from %s to %s.",
                                $train['train_number'] ?? 'Unknown',
                                $train['train_name'] ?? 'Unknown',
                                $fromStationName . ' ↔ ' . $toStationName,
                                max($ourStartTime, $theirStartTime),
                                min($ourEndTime, $theirEndTime)
                            )
                        ];
                    }
                }
            }
        }
    }
    
    return $conflicts;
}

/**
 * Check route conflicts with custom waiting times support
 */
function checkRouteConflictsWithWaitingTimes($conn, $departureStation, $arrivalStation, $departureTime, $arrivalTime, $waitingTimes = [], $maxSpeed = 80, $excludeTrainId = null) {
    $conflicts = [];
    
    // First, get the route for the new/updated train
    $route = calculateRouteEmbedded($conn, $departureStation, $arrivalStation);
    if (!$route['success']) {
        return []; // If no route exists, no conflicts to check
    }
    
    // Calculate the realistic station times for this train WITH CUSTOM WAITING TIMES
    $stationTimes = calculateStationTimesWithCustomWaiting($conn, $route, $departureTime, $waitingTimes, $maxSpeed);
    
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
        $existingRoute = calculateRouteEmbedded($conn, $train['departure_station_id'], $train['arrival_station_id']);
        if (!$existingRoute['success']) {
            continue; // Skip trains with invalid routes
        }
        
        // For existing trains, use the embedded calculation (no custom waiting times)
        $existingStationTimes = calculateStationTimesEmbedded($conn, $existingRoute, $train['departure_time'], $train['arrival_time'], $train['max_speed_kmh'] ?? 80);
        
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
                    if (timesOverlapEmbedded($ourStartTime, $ourEndTime, $theirStartTime, $theirEndTime)) {
                        // Get station names for better display
                        $fromStationName = getStationName($conn, $ourFromStation);
                        $toStationName = getStationName($conn, $ourToStation);
                        
                        $conflicts[] = [
                            'type' => 'track_segment_conflict',
                            'conflicting_train_number' => $train['train_number'] ?? 'Unknown',
                            'conflicting_train_name' => $train['train_name'] ?? $train['train_number'] ?? 'Unknown',
                            'from_station_id' => $ourFromStation,
                            'to_station_id' => $ourToStation,
                            'from_station_name' => $fromStationName,
                            'to_station_name' => $toStationName,
                            'track_segment' => $fromStationName . ' ↔ ' . $toStationName,
                            'our_departure_time' => $ourStartTime,
                            'our_arrival_time' => $ourEndTime,
                            'their_departure_time' => $theirStartTime,
                            'their_arrival_time' => $theirEndTime,
                            'overlap_start_time' => max($ourStartTime, $theirStartTime),
                            'overlap_end_time' => min($ourEndTime, $theirEndTime),
                            'conflict_duration_minutes' => calculateTimeDifference(max($ourStartTime, $theirStartTime), min($ourEndTime, $theirEndTime)),
                            'severity' => 'critical',
                            'message' => sprintf(
                                "Track conflict with train %s (%s) on segment %s. Overlap from %s to %s.",
                                $train['train_number'] ?? 'Unknown',
                                $train['train_name'] ?? 'Unknown',
                                $fromStationName . ' ↔ ' . $toStationName,
                                max($ourStartTime, $theirStartTime),
                                min($ourEndTime, $theirEndTime)
                            )
                        ];
                    }
                }
            }
        }
    }
    
    return $conflicts;
}

/**
 * Calculate route using Dijkstra's algorithm - same as in trains_api.php
 */
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

function calculateRouteEmbedded($conn, $from_station, $to_station) {
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

function calculateStationTimesEmbedded($conn, $route, $departureTime, $arrivalTime, $maxSpeed = 80) {
    $stationTimes = [];
    $connections = $route['connections'];
    
    // Convert departure time to minutes, handle different time formats
    $depMinutes = timeToMinutesEmbedded($departureTime);
    $currentTime = $depMinutes;
    
    // Add first station (origin)
    $stationTimes[] = [
        'station_id' => $route['path'][0],
        'arrival' => minutesToTimeEmbedded($currentTime),
        'departure' => minutesToTimeEmbedded($currentTime),
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
            'arrival' => minutesToTimeEmbedded($currentTime),
            'departure' => minutesToTimeEmbedded($currentTime + $dwellTime),
            'dwell_time' => $dwellTime
        ];
        
        $currentTime += $dwellTime;
    }
    
    return $stationTimes;
}

/**
 * Calculate station times with custom waiting times per station
 */
function calculateStationTimesWithCustomWaiting($conn, $route, $departureTime, $waitingTimes = [], $maxSpeed = 80) {
    $stationTimes = [];
    $connections = $route['connections'];
    
    // Convert departure time to minutes, handle different time formats
    $depMinutes = timeToMinutesEmbedded($departureTime);
    $currentTime = $depMinutes;
    
    // Add first station (origin)
    $stationTimes[] = [
        'station_id' => $route['path'][0],
        'arrival' => minutesToTimeEmbedded($currentTime),
        'departure' => minutesToTimeEmbedded($currentTime),
        'dwell_time' => 0
    ];
    
    // Calculate times for each segment using realistic speeds and custom waiting times
    for ($i = 0; $i < count($connections); $i++) {
        $connection = $connections[$i];
        $segment_distance_km = floatval($connection['distance_km']);
        
        // Use train's max speed, but respect track speed limits
        $track_speed_limit = intval($connection['track_speed_limit'] ?? 80);
        $segment_max_speed = max(30, min($maxSpeed, $track_speed_limit));
        
        // Calculate realistic travel time: time = distance / speed * 60 (for minutes)
        $segment_time_minutes = ($segment_distance_km / $segment_max_speed) * 60;
        
        $currentTime += $segment_time_minutes;
        
        // Get custom dwell time for this station, or use default
        $stationId = $route['path'][$i + 1];
        $isLastStation = ($i === count($connections) - 1);
        
        if ($isLastStation) {
            $dwellTime = 0; // No dwell time at final destination
        } else {
            $dwellTime = isset($waitingTimes[$stationId]) ? intval($waitingTimes[$stationId]) : 2; // Default 2 minutes
        }
        
        $stationTimes[] = [
            'station_id' => $stationId,
            'arrival' => minutesToTimeEmbedded($currentTime),
            'departure' => minutesToTimeEmbedded($currentTime + $dwellTime),
            'dwell_time' => $dwellTime
        ];
        
        $currentTime += $dwellTime;
    }
    
    return $stationTimes;
}

function timesOverlapEmbedded($start1, $end1, $start2, $end2) {
    $start1_min = timeToMinutesEmbedded($start1);
    $end1_min = timeToMinutesEmbedded($end1);
    $start2_min = timeToMinutesEmbedded($start2);
    $end2_min = timeToMinutesEmbedded($end2);
    
    // Handle overnight times
    if ($end1_min < $start1_min) $end1_min += 24 * 60;
    if ($end2_min < $start2_min) $end2_min += 24 * 60;
    
    // Check for overlap: ranges overlap if start1 < end2 AND start2 < end1
    return ($start1_min < $end2_min) && ($start2_min < $end1_min);
}

function timeToMinutesEmbedded($timeStr) {
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

function minutesToTimeEmbedded($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%02d:%02d', $hours, $mins);
}

// Non-embedded versions for backward compatibility
function timeToMinutes($timeStr) {
    return timeToMinutesEmbedded($timeStr);
}

function minutesToTime($minutes) {
    return minutesToTimeEmbedded($minutes);
}

function calculateStationTimes($conn, $route, $departureTime, $arrivalTime, $maxSpeed = 80) {
    return calculateStationTimesEmbedded($conn, $route, $departureTime, $arrivalTime, $maxSpeed);
}

/**
 * Validate locomotive availability for a specific departure
 */
function validateLocomotiveAvailability($conn, $locomotiveId, $departureStation, $departureTime) {
    // Check if locomotive exists and is active
    $stmt = $conn->prepare("
        SELECT id, dcc_address, class, number, name, is_active
        FROM dcc_locomotives 
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$locomotiveId]);
    $locomotive = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$locomotive) {
        return [
            'available' => false,
            'reason' => 'Locomotive not found or inactive'
        ];
    }
    
    // Check for ALL assignments that could conflict with the requested time
    $stmt = $conn->prepare("
        SELECT 
            t.id, t.train_number, t.departure_station_id, t.arrival_station_id, 
            t.departure_time, t.arrival_time,
            ds.name as departure_station_name,
            as_table.name as arrival_station_name
        FROM dcc_trains t
        JOIN dcc_train_locomotives tl ON t.id = tl.train_id
        LEFT JOIN dcc_stations ds ON t.departure_station_id = ds.id
        LEFT JOIN dcc_stations as_table ON t.arrival_station_id = as_table.id
        WHERE tl.locomotive_id = ? AND t.is_active = 1
        ORDER BY t.departure_time
    ");
    $stmt->execute([$locomotiveId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug logging
    error_log("validateLocomotiveAvailability: Locomotive $locomotiveId, Departure: $departureStation at $departureTime");
    error_log("Found " . count($assignments) . " assignments: " . json_encode($assignments));
    
    if (empty($assignments)) {
        // Locomotive never assigned or not currently assigned
        return [
            'available' => true,
            'reason' => 'Locomotive available for assignment'
        ];
    }
    
    $departureTimeMinutes = timeToMinutes($departureTime);
    
    // First, check for time conflicts with any assignment
    foreach ($assignments as $assignment) {
        $assignDepartureMinutes = timeToMinutes($assignment['departure_time']);
        $assignArrivalMinutes = timeToMinutes($assignment['arrival_time']);
        
        // Handle overnight scenarios
        if ($assignArrivalMinutes < $assignDepartureMinutes) {
            $assignArrivalMinutes += 24 * 60; // Add 24 hours in minutes
        }
        
        if ($departureTimeMinutes < $assignDepartureMinutes) {
            $departureTimeMinutes += 24 * 60; // Handle overnight departure
        }
        
        // Check if the requested departure time conflicts with any existing assignment
        if ($departureTimeMinutes >= $assignDepartureMinutes && $departureTimeMinutes <= $assignArrivalMinutes) {
            return [
                'available' => false,
                'reason' => "Locomotive busy from {$assignment['departure_time']} to {$assignment['arrival_time']} on train {$assignment['train_number']} ({$assignment['departure_station_name']} → {$assignment['arrival_station_name']})"
            ];
        }
    }
    
    // Find the most recent assignment that completes before our departure time
    $mostRecentCompletedAssignment = null;
    $mostRecentArrivalTime = -1;
    
    foreach ($assignments as $assignment) {
        $assignArrivalMinutes = timeToMinutes($assignment['arrival_time']);
        
        // Handle overnight scenarios
        $assignDepartureMinutes = timeToMinutes($assignment['departure_time']);
        if ($assignArrivalMinutes < $assignDepartureMinutes) {
            $assignArrivalMinutes += 24 * 60;
        }
        
        // Check if this assignment completes before our departure
        if ($assignArrivalMinutes <= $departureTimeMinutes) {
            // This is a completed assignment - check if it's the most recent
            if ($assignArrivalMinutes > $mostRecentArrivalTime) {
                $mostRecentArrivalTime = $assignArrivalMinutes;
                $mostRecentCompletedAssignment = $assignment;
            }
        }
    }
    
    // If we have a most recent completed assignment, check its location and turnaround time
    if ($mostRecentCompletedAssignment) {
        $assignment = $mostRecentCompletedAssignment;
        $assignArrivalMinutes = $mostRecentArrivalTime;
        
        error_log("Most recent completed assignment: Train {$assignment['train_number']}, arrives at {$assignment['arrival_station_id']} ({$assignment['arrival_station_name']}) at {$assignment['arrival_time']}");
        
        // Check location - locomotive must be at the departure station
        if ($assignment['arrival_station_id'] !== $departureStation) {
            error_log("Location mismatch: locomotive at {$assignment['arrival_station_id']}, departure from $departureStation");
            return [
                'available' => false,
                'reason' => "Locomotive will be at {$assignment['arrival_station_name']}, not at departure station {$departureStation}"
            ];
        }
        
        // Add minimum turnaround time check (e.g., 30 minutes)
        $turnaroundMinutes = 30;
        if (($departureTimeMinutes - $assignArrivalMinutes) < $turnaroundMinutes) {
            return [
                'available' => false,
                'reason' => "Insufficient turnaround time - locomotive arrives at {$assignment['arrival_time']}, minimum 30 minutes required"
            ];
        }
        
        return [
            'available' => true,
            'reason' => "Locomotive available from {$assignment['arrival_time']} at {$assignment['arrival_station_name']}"
        ];
    }
    
    // If locomotive has no completed assignments before departure time, it's available
    return [
        'available' => true,
        'reason' => 'Locomotive available for assignment'
    ];
}

// Helper function to get station name by ID
function getStationName($conn, $stationId) {
    static $stationCache = [];
    
    if (isset($stationCache[$stationId])) {
        return $stationCache[$stationId];
    }
    
    $stmt = $conn->prepare("SELECT name FROM dcc_stations WHERE id = ?");
    $stmt->execute([$stationId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $name = $result ? $result['name'] : $stationId;
    $stationCache[$stationId] = $name;
    
    return $name;
}

// Helper function to calculate time difference in minutes
function calculateTimeDifference($time1, $time2) {
    $minutes1 = timeToMinutes($time1);
    $minutes2 = timeToMinutes($time2);
    
    $diff = abs($minutes2 - $minutes1);
    
    // Handle overnight time differences
    if ($diff > 12 * 60) {
        $diff = 24 * 60 - $diff;
    }
    
    return $diff;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $action = $_GET['action'] ?? 'create';
    
    // Require authentication
    $user = getCurrentUser($conn);
    if (!$user || !in_array($user['role'], ['admin', 'operator'])) {
        outputJSON(['status' => 'error', 'error' => 'Authentication required']);
    }
    
    switch ($action) {
        case 'create_validated':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                outputJSON(['status' => 'error', 'error' => 'Invalid JSON input']);
            }
            
            $result = createTrainWithValidation($conn, $input);
            outputJSON($result);
            break;
            
        case 'validate_only':
            $departureStation = $_GET['departure_station'] ?? null;
            $arrivalStation = $_GET['arrival_station'] ?? null;
            $departureTime = $_GET['departure_time'] ?? null;
            $arrivalTime = $_GET['arrival_time'] ?? null;
            
            if (!$departureStation || !$arrivalStation || !$departureTime) {
                outputJSON(['status' => 'error', 'error' => 'Missing required parameters (departure_station, arrival_station, departure_time)']);
            }
            
            // Validate route
            $routeValidation = validateRoute($conn, $departureStation, $arrivalStation);
            if (!$routeValidation['valid']) {
                outputJSON(['status' => 'error', 'error' => $routeValidation['error']]);
            }
            
            // If no arrival time provided, calculate it using the same logic as trains_api.php
            if (!$arrivalTime) {
                // Calculate travel time based on actual connections and speeds
                $total_travel_time_minutes = 0;
                $route_connections = $routeValidation['route']['connections'] ?? [];
                
                foreach ($route_connections as $connection) {
                    $segment_distance_km = floatval($connection['distance_km']);
                    
                    // Get speed from connection, with proper fallbacks
                    $segment_max_speed = 50; // Default speed
                    if (isset($connection['track_speed_limit']) && is_numeric($connection['track_speed_limit'])) {
                        $segment_max_speed = max(30, floatval($connection['track_speed_limit']));
                    } elseif (isset($connection['max_speed_kmh']) && is_numeric($connection['max_speed_kmh'])) {
                        $segment_max_speed = max(30, floatval($connection['max_speed_kmh']));
                    }
                    
                    // Ensure we have valid values to prevent NaN
                    if ($segment_distance_km <= 0 || $segment_max_speed <= 0) {
                        continue; // Skip invalid connections
                    }
                    
                    // Calculate realistic travel time: time = distance / speed * 60 (for minutes)
                    $segment_time_minutes = ($segment_distance_km / $segment_max_speed) * 60;
                    
                    // Add station dwell time (2 minutes for intermediate stations)
                    if ($connection['to_station_id'] !== $arrivalStation) {
                        $segment_time_minutes += 2;
                    }
                    
                    $total_travel_time_minutes += $segment_time_minutes;
                }
                
                // Parse departure time and add travel time
                $depTime = DateTime::createFromFormat('H:i', $departureTime);
                if ($depTime) {
                    // Ensure travel time is finite and positive
                    $travel_minutes = round($total_travel_time_minutes);
                    if (!is_finite($travel_minutes) || $travel_minutes < 0 || $travel_minutes > 1440) {
                        $travel_minutes = 60; // Default to 1 hour if calculation fails
                    }
                    
                    $depTime->add(new DateInterval('PT' . $travel_minutes . 'M'));
                    $arrivalTime = $depTime->format('H:i');
                } else {
                    outputJSON(['status' => 'error', 'error' => 'Invalid departure time format']);
                }
            }
            
            // Generate detailed station times for the frontend
            $stationTimes = [];
            $route_connections = $routeValidation['route']['connections'] ?? [];
            $route_path = $routeValidation['route']['path'] ?? [];
            
            if (!empty($route_path) && !empty($route_connections)) {
                $currentTime = DateTime::createFromFormat('H:i', $departureTime);
                
                // Add departure station
                $stationTimes[] = [
                    'station_id' => $departureStation,
                    'arrival' => $departureTime,
                    'departure' => $departureTime,
                    'dwell_time' => 0
                ];
                
                // Calculate times for each connection
                foreach ($route_connections as $i => $connection) {
                    $segment_distance_km = floatval($connection['distance_km']);
                    $segment_max_speed = 50; // Default speed
                    
                    if (isset($connection['track_speed_limit']) && is_numeric($connection['track_speed_limit'])) {
                        $segment_max_speed = max(30, floatval($connection['track_speed_limit']));
                    }
                    
                    if ($segment_distance_km > 0 && $segment_max_speed > 0) {
                        $segment_time_minutes = ($segment_distance_km / $segment_max_speed) * 60;
                        $currentTime->add(new DateInterval('PT' . round($segment_time_minutes) . 'M'));
                    }
                    
                    $arrivalTimeStr = $currentTime->format('H:i');
                    $dwellTime = ($connection['to_station_id'] !== $arrivalStation) ? 2 : 0;
                    
                    if ($dwellTime > 0) {
                        $currentTime->add(new DateInterval('PT' . $dwellTime . 'M'));
                    }
                    
                    $departureTimeStr = $currentTime->format('H:i');
                    
                    $stationTimes[] = [
                        'station_id' => $connection['to_station_id'],
                        'arrival' => $arrivalTimeStr,
                        'departure' => $departureTimeStr,
                        'dwell_time' => $dwellTime
                    ];
                }
            }
            
            // Check availability using embedded collision detection (proven from trains_api.php)
            $conflicts = checkRouteConflictsEmbedded(
                $conn,
                $departureStation,
                $arrivalStation,
                $departureTime,
                $arrivalTime
            );
            
            // Create elegant conflict summary
            $conflictSummary = [];
            $conflictCount = count($conflicts);
            
            if ($conflictCount > 0) {
                $affectedTrains = array_unique(array_map(function($c) {
                    return $c['conflicting_train_number'] ?? $c['conflicting_train'] ?? 'Unknown';
                }, $conflicts));
                
                $conflictSummary = [
                    'total_conflicts' => $conflictCount,
                    'affected_trains_count' => count($affectedTrains),
                    'affected_trains' => $affectedTrains,
                    'summary_message' => sprintf(
                        "Route validation failed: %d conflict%s detected with %d train%s (%s) on the requested route %s → %s.",
                        $conflictCount,
                        $conflictCount === 1 ? '' : 's',
                        count($affectedTrains),
                        count($affectedTrains) === 1 ? '' : 's',
                        implode(', ', $affectedTrains),
                        getStationName($conn, $departureStation),
                        getStationName($conn, $arrivalStation)
                    )
                ];
            }
            
            $availabilityCheck = [
                'available' => empty($conflicts),
                'conflict_count' => $conflictCount,
                'conflicts' => $conflicts,
                'summary' => $conflictSummary,
                'station_times' => $stationTimes  // Add station times for frontend
            ];
            
            outputJSON([
                'status' => 'success',
                'data' => [
                    'route_valid' => true,
                    'route' => [
                        'success' => $routeValidation['route']['success'],
                        'total_distance' => round($routeValidation['route']['total_distance'] ?? 0, 1),
                        'distance_unit' => 'km',
                        'stations_count' => $routeValidation['route']['stations_count'] ?? count($routeValidation['route']['path'] ?? []),
                        'path' => $routeValidation['route']['path'] ?? [],
                        'connections' => $routeValidation['route']['connections'] ?? [],
                        'route_description' => implode(' → ', array_map(function($stationId) use ($conn) {
                            return getStationName($conn, $stationId);
                        }, $routeValidation['route']['path'] ?? []))
                    ],
                    'schedule' => [
                        'departure_time' => $departureTime,
                        'arrival_time' => $arrivalTime,
                        'travel_duration_minutes' => calculateTimeDifference($departureTime, $arrivalTime)
                    ],
                    'calculated_arrival_time' => $arrivalTime,  // Add for backward compatibility
                    'availability' => $availabilityCheck
                ]
            ]);
            break;
            
        case 'validate_with_waiting_times':
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                outputJSON(['status' => 'error', 'error' => 'Invalid JSON input']);
            }
            
            $departureStation = $input['departure_station'] ?? null;
            $arrivalStation = $input['arrival_station'] ?? null;
            $departureTime = $input['departure_time'] ?? null;
            $waitingTimes = $input['waiting_times'] ?? [];
            $maxSpeed = $input['max_speed_kmh'] ?? 80;
            
            if (!$departureStation || !$arrivalStation || !$departureTime) {
                outputJSON(['status' => 'error', 'error' => 'Missing required parameters (departure_station, arrival_station, departure_time)']);
            }
            
            // Validate route first
            $routeValidation = validateRoute($conn, $departureStation, $arrivalStation);
            if (!$routeValidation['valid']) {
                outputJSON(['status' => 'error', 'error' => $routeValidation['error']]);
            }
            
            // Calculate station times with custom waiting times
            $stationTimes = calculateStationTimesWithCustomWaiting(
                $conn, 
                $routeValidation['route'], 
                $departureTime, 
                $waitingTimes, 
                $maxSpeed
            );
            
            // Calculate arrival time based on final station time
            $arrivalTime = end($stationTimes)['arrival'] ?? $departureTime;
            
            // Check availability with new times and custom waiting times
            $conflicts = checkRouteConflictsWithWaitingTimes(
                $conn,
                $departureStation,
                $arrivalStation,
                $departureTime,
                $arrivalTime,
                $waitingTimes,
                $maxSpeed
            );
            
            // Create conflict summary
            $conflictSummary = [];
            $conflictCount = count($conflicts);
            
            if ($conflictCount > 0) {
                $affectedTrains = array_unique(array_map(function($c) {
                    return $c['conflicting_train_number'] ?? $c['conflicting_train'] ?? 'Unknown';
                }, $conflicts));
                
                $conflictSummary = [
                    'total_conflicts' => $conflictCount,
                    'affected_trains_count' => count($affectedTrains),
                    'affected_trains' => $affectedTrains,
                    'summary_message' => sprintf(
                        "Route validation with custom waiting times failed: %d conflict%s detected with %d train%s (%s).",
                        $conflictCount,
                        $conflictCount === 1 ? '' : 's',
                        count($affectedTrains),
                        count($affectedTrains) === 1 ? '' : 's',
                        implode(', ', $affectedTrains)
                    )
                ];
            }
            
            $availabilityCheck = [
                'available' => empty($conflicts),
                'conflict_count' => $conflictCount,
                'conflicts' => $conflicts,
                'summary' => $conflictSummary,
                'station_times' => $stationTimes
            ];
            
            outputJSON([
                'status' => 'success',
                'data' => [
                    'route_valid' => true,
                    'route' => [
                        'success' => $routeValidation['route']['success'],
                        'total_distance' => round($routeValidation['route']['total_distance'] ?? 0, 1),
                        'distance_unit' => 'km',
                        'stations_count' => $routeValidation['route']['stations_count'] ?? count($routeValidation['route']['path'] ?? []),
                        'path' => $routeValidation['route']['path'] ?? [],
                        'connections' => $routeValidation['route']['connections'] ?? [],
                        'route_description' => implode(' → ', array_map(function($stationId) use ($conn) {
                            return getStationName($conn, $stationId);
                        }, $routeValidation['route']['path'] ?? []))
                    ],
                    'schedule' => [
                        'departure_time' => $departureTime,
                        'arrival_time' => $arrivalTime,
                        'travel_duration_minutes' => calculateTimeDifference($departureTime, $arrivalTime)
                    ],
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

/**
 * Calculate total travel time in minutes
 */
function calculateTotalTravelTime($departureTime, $arrivalTime) {
    $depMinutes = timeToMinutes($departureTime);
    $arrMinutes = timeToMinutes($arrivalTime);
    $totalTime = $arrMinutes - $depMinutes;
    
    if ($totalTime <= 0) {
        $totalTime += 24 * 60; // Handle overnight travel
    }
    
    return $totalTime;
}

/**
 * Find connection information between two stations
 */
function findConnectionInfo($connections, $fromStation, $toStation) {
    foreach ($connections as $connection) {
        if (($connection['from_station'] === $fromStation && $connection['to_station'] === $toStation) ||
            ($connection['from_station'] === $toStation && $connection['to_station'] === $fromStation)) {
            return $connection;
        }
    }
    return null;
}

/**
 * Create track occupancy records for route segments
 */
function createRouteOccupancyRecords($conn, $scheduleId, $route, $stationTimes) {
    // Create occupancy records for each station stop (using existing table structure)
    foreach ($stationTimes as $index => $stationTime) {
        // Find available track at station
        $stmt = $conn->prepare("
            SELECT id FROM dcc_station_tracks 
            WHERE station_id = ? AND is_active = 1 
            ORDER BY track_type = 'platform' DESC, track_number
            LIMIT 1
        ");
        $stmt->execute([$stationTime['station_id']]);
        $track = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($track) {
            $stmt = $conn->prepare("
                INSERT INTO dcc_track_occupancy (
                    station_id, track_id, schedule_id, stop_id,
                    occupied_from, occupied_until, occupancy_type, is_confirmed
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $occupiedFrom = date('Y-m-d') . ' ' . $stationTime['arrival'];
            $occupiedUntil = date('Y-m-d') . ' ' . $stationTime['departure'];
            $occupancyType = ($index === 0) ? 'departure' : (($index === count($stationTimes) - 1) ? 'arrival' : 'layover');
            
            $stmt->execute([
                $stationTime['station_id'],
                $track['id'],
                $scheduleId,
                $scheduleId, // Using schedule_id as stop_id for now
                $occupiedFrom,
                $occupiedUntil,
                $occupancyType,
                1
            ]);
        }
    }
}

?>
