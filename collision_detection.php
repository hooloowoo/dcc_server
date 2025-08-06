<?php
/**
 * Centralized Collision Detection System
 * 
 * This file contains the proven collision detection logic from trains_api.php
 * that can be used by both the main API and enhanced API to ensure consistency.
 */

require_once 'dwell_time_api.php';

/**
 * Check for route conflicts between trains - Main collision detection function
 * 
 * @param PDO $conn Database connection
 * @param string $departureStation Departure station ID
 * @param string $arrivalStation Arrival station ID  
 * @param string $departureTime Departure time (HH:MM or HH:MM:SS)
 * @param string $arrivalTime Arrival time (HH:MM or HH:MM:SS)
 * @param int|null $excludeTrainId Train ID to exclude from conflict check (for updates)
 * @return array Array of conflicts found
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
 * Calculate station times for a route using the proven algorithm from trains_api.php
 * 
 * @param PDO $conn Database connection
 * @param array $route Route information with path and connections
 * @param string $departureTime Departure time
 * @param string $arrivalTime Arrival time  
 * @param int $maxSpeed Maximum speed in km/h (default 80)
 * @return array Station times with arrival/departure for each station
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
        
        // Add dwell time using configurable values
        $stopType = ($i === count($connections) - 1) ? 'destination' : 'intermediate';
        $dwellTime = getDwellTime($conn, $route['path'][$i + 1], $stopType);
        
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
 * Calculate route using Dijkstra's algorithm - matches trains_api.php implementation
 * 
 * @param PDO $conn Database connection
 * @param string $from_station From station ID
 * @param string $to_station To station ID
 * @return array Route calculation result
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

/**
 * Check if two time ranges overlap - Proven algorithm from trains_api.php
 * 
 * @param string $start1 Start time of first range
 * @param string $end1 End time of first range
 * @param string $start2 Start time of second range
 * @param string $end2 End time of second range
 * @return bool True if times overlap
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
 * Convert time string to minutes - Proven implementation from trains_api.php
 * 
 * @param string $timeStr Time string in format HH:MM or HH:MM:SS
 * @return int Time in minutes from midnight
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
 * Convert minutes to time string - Proven implementation from trains_api.php
 * 
 * @param int $minutes Minutes from midnight
 * @return string Time string in format HH:MM
 */
function minutesToTime($minutes) {
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    return sprintf('%02d:%02d', $hours, $mins);
}

/**
 * Validate route availability using centralized collision detection
 * 
 * @param PDO $conn Database connection
 * @param string $departureStation Departure station ID
 * @param string $arrivalStation Arrival station ID
 * @param string $departureTime Departure time
 * @param string $arrivalTime Arrival time (optional, will be calculated if not provided)
 * @param int $maxSpeed Maximum speed in km/h (default 80)
 * @param int|null $excludeTrainId Train ID to exclude from conflict check
 * @return array Validation result with availability and conflicts
 */
function validateRouteAvailability($conn, $departureStation, $arrivalStation, $departureTime, $arrivalTime = null, $maxSpeed = 80, $excludeTrainId = null) {
    // Calculate arrival time if not provided
    if (!$arrivalTime) {
        $route = calculateRoute($conn, $departureStation, $arrivalStation);
        if (!$route['success']) {
            return [
                'available' => false,
                'conflicts' => [],
                'error' => 'Cannot calculate route: ' . $route['error']
            ];
        }
        
        $totalTime = 0;
        foreach ($route['connections'] as $connection) {
            $segmentDistance = floatval($connection['distance_km']);
            $trackSpeedLimit = intval($connection['track_speed_limit'] ?? 80);
            $segmentMaxSpeed = max(30, min($maxSpeed, $trackSpeedLimit));
            $totalTime += ($segmentDistance / $segmentMaxSpeed) * 60; // minutes
        }
        
        // Add configurable dwell time per station
        $totalDwellTime = 0;
        for ($i = 0; $i < count($route['connections']); $i++) {
            $connection = $route['connections'][$i];
            $stopType = ($i === count($route['connections']) - 1) ? 'destination' : 'intermediate';
            $totalDwellTime += getDwellTime($conn, $connection['to_station_id'], $stopType);
        }
        $totalTime += $totalDwellTime;
        
        $departureDateTime = new DateTime($departureTime);
        $departureDateTime->add(new DateInterval('PT' . round($totalTime) . 'M'));
        $arrivalTime = $departureDateTime->format('H:i:s');
    }
    
    // Check for conflicts using the proven collision detection system
    $conflicts = checkRouteConflicts($conn, $departureStation, $arrivalStation, $departureTime, $arrivalTime, $excludeTrainId);
    
    return [
        'available' => empty($conflicts),
        'conflicts' => $conflicts,
        'calculated_arrival_time' => $arrivalTime
    ];
}

?>
