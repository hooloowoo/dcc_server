<?php
require_once 'config.php';
require_once 'auth_utils.php';
require_once 'collision_detection.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "Testing centralized collision detection...\n\n";
    
    // Test the exact same parameters
    $departureStation = 'NADAHAZA';
    $arrivalStation = 'BLAUMITH';
    $departureTime = '01:30';
    $arrivalTime = '02:08';
    
    echo "Test parameters:\n";
    echo "Route: $departureStation → $arrivalStation\n";
    echo "Time: $departureTime → $arrivalTime\n\n";
    
    // Test route calculation
    echo "Step 1: Route calculation\n";
    $route = calculateRoute($conn, $departureStation, $arrivalStation);
    echo "Route success: " . ($route['success'] ? 'YES' : 'NO') . "\n";
    if ($route['success']) {
        echo "Path: " . implode(' → ', $route['path']) . "\n";
        echo "Distance: " . $route['total_distance'] . " km\n";
        echo "Connections: " . count($route['connections']) . "\n";
    }
    echo "\n";
    
    // Test station times calculation
    echo "Step 2: Station times calculation\n";
    $stationTimes = calculateStationTimes($conn, $route, $departureTime, $arrivalTime);
    echo "Station times count: " . count($stationTimes) . "\n";
    foreach ($stationTimes as $i => $time) {
        echo "Station $i: {$time['station_id']} - Arrival: {$time['arrival']}, Departure: {$time['departure']}\n";
    }
    echo "\n";
    
    // Test collision detection
    echo "Step 3: Collision detection\n";
    $conflicts = checkRouteConflicts($conn, $departureStation, $arrivalStation, $departureTime, $arrivalTime);
    echo "Conflicts found: " . count($conflicts) . "\n";
    foreach ($conflicts as $i => $conflict) {
        echo "Conflict $i: Train {$conflict['conflicting_train']} on {$conflict['from_station']}↔{$conflict['to_station']}\n";
        echo "  Our times: {$conflict['our_times']}\n";
        echo "  Their times: {$conflict['their_times']}\n";
        echo "  Overlap: {$conflict['overlap_start']} to {$conflict['overlap_end']}\n";
    }
    echo "\n";
    
    // Test the validateRouteAvailability wrapper
    echo "Step 4: validateRouteAvailability wrapper\n";
    $availability = validateRouteAvailability($conn, $departureStation, $arrivalStation, $departureTime, $arrivalTime);
    echo "Available: " . ($availability['available'] ? 'YES' : 'NO') . "\n";
    echo "Conflicts in wrapper: " . count($availability['conflicts']) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
