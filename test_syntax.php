<?php
/**
 * Test file to check collision_detection.php syntax
 */

// Test basic PHP syntax
echo "Testing collision_detection.php syntax...\n";

try {
    // Include and test the file
    require_once 'config.php';
    require_once 'collision_detection.php';
    
    echo "File included successfully!\n";
    
    // Test a simple function
    if (function_exists('timeToMinutes')) {
        $result = timeToMinutes('01:30');
        echo "timeToMinutes test: 01:30 = $result minutes\n";
    } else {
        echo "Function timeToMinutes not found\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
} catch (ParseError $e) {
    echo "Parse Error: " . $e->getMessage() . "\n";
} catch (Error $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}

echo "Test complete.\n";
?>
