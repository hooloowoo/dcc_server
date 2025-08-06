<?php
/**
 * Simple conflict checker that can work independently
 * This script checks for station overcrowding without relying on the main API
 */

// Simple database connection (you'll need to adjust these values)
$host = 'localhost'; // or your database host
$dbname = 'dcc_database'; // your database name
$username = 'your_username';
$password = 'your_password';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    echo "=== HIGHBALL RAILWAY CONFLICT ANALYSIS ===\n";
    echo "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";

    // Get all stations and their track counts
    $stmt = $pdo->query("
        SELECT s.id, s.name, COUNT(st.id) as track_count
        FROM dcc_stations s
        LEFT JOIN dcc_station_tracks st ON s.id = st.station_id AND st.is_active = 1
        WHERE s.is_active = 1
        GROUP BY s.id, s.name
        HAVING track_count > 0
        ORDER BY s.name
    ");
    $stations = $stmt->fetchAll();

    $totalConflicts = 0;
    $conflictingStations = [];

    foreach ($stations as $station) {
        $stationId = $station['id'];
        $trackCount = $station['track_count'];
        
        // Find all trains using this station
        $stmt = $pdo->prepare("
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
        $stationTrains = $stmt->fetchAll();

        if (empty($stationTrains)) {
            continue;
        }

        // Group trains by 30-minute time windows
        $timeWindows = [];
        foreach ($stationTrains as $train) {
            $time = $train['usage_time'];
            $window = floor(strtotime($time) / 1800) * 1800; // 30-minute windows
            
            if (!isset($timeWindows[$window])) {
                $timeWindows[$window] = [];
            }
            $timeWindows[$window][] = $train;
        }

        // Check for conflicts
        $stationConflicts = [];
        foreach ($timeWindows as $window => $trains) {
            if (count($trains) > $trackCount) {
                $stationConflicts[] = [
                    'window' => date('H:i', $window) . '-' . date('H:i', $window + 1800),
                    'trains' => count($trains),
                    'excess' => count($trains) - $trackCount,
                    'train_list' => array_map(function($t) {
                        return $t['train_number'] . '(' . $t['usage_type'] . ')';
                    }, $trains)
                ];
            }
        }

        if (!empty($stationConflicts)) {
            $conflictingStations[] = $station;
            $stationTotalExcess = array_sum(array_column($stationConflicts, 'excess'));
            $totalConflicts += $stationTotalExcess;
            
            echo "🚨 CONFLICT: {$station['name']} ({$station['id']})\n";
            echo "   Tracks available: {$trackCount}\n";
            echo "   Total trains: " . count($stationTrains) . "\n";
            echo "   Conflict windows: " . count($stationConflicts) . "\n";
            echo "   Total excess trains: {$stationTotalExcess}\n";
            
            foreach ($stationConflicts as $conflict) {
                echo "   - {$conflict['window']}: {$conflict['trains']} trains (excess: {$conflict['excess']})\n";
                echo "     Trains: " . implode(', ', $conflict['train_list']) . "\n";
            }
            echo "\n";
        } else {
            echo "✅ OK: {$station['name']} - {$trackCount} tracks, " . count($stationTrains) . " trains\n";
        }
    }

    echo "\n=== SUMMARY ===\n";
    echo "Total stations checked: " . count($stations) . "\n";
    echo "Stations with conflicts: " . count($conflictingStations) . "\n";
    echo "Total excess train assignments: {$totalConflicts}\n";

    if ($totalConflicts > 0) {
        echo "\n=== RECOMMENDATIONS ===\n";
        echo "1. Deploy updated automated_train_generator_api.php with enhanced conflict checking\n";
        echo "2. Run conflict resolution API to automatically remove excess AUTO trains\n";
        echo "3. Reduce train generation frequency\n";
        echo "4. Add more tracks to overcrowded stations\n";
        echo "5. Consider staggering train times better\n";
        
        // Output JSON for web interface
        $jsonOutput = [
            'status' => 'success',
            'timestamp' => date('Y-m-d H:i:s'),
            'summary' => [
                'total_stations_checked' => count($stations),
                'stations_with_conflicts' => count($conflictingStations),
                'total_conflicts' => $totalConflicts
            ],
            'conflicting_stations' => array_map(function($station, $conflicts) {
                return [
                    'station_name' => $station['name'],
                    'station_id' => $station['id'],
                    'track_count' => $station['track_count'],
                    'conflicts' => $conflicts
                ];
            }, $conflictingStations, array_keys($conflictingStations))
        ];
        
        echo "\n=== JSON OUTPUT ===\n";
        echo json_encode($jsonOutput, JSON_PRETTY_PRINT);
    } else {
        echo "\n✅ No conflicts detected! All stations have adequate capacity.\n";
    }

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    echo "Please check your database connection settings.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
