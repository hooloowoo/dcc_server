# Highball Railway - Track Capacity Fix Deployment Guide

## Problem Summary
- **Issue**: "Oberritersgrun has 3 trains on 2 tracks" and general station overcrowding
- **Root Cause**: Automated train generator creating more trains than station track capacity allows
- **Solution**: Enhanced track availability checking with ultra-conservative scheduling

## Current Status
✅ **LOCAL CODE**: Enhanced automated_train_generator_api.php with improved conflict checking
❌ **LIVE SERVER**: Getting HTTP 500 errors - needs deployment

## Deployment Steps

### 1. Deploy Enhanced API
Upload the enhanced `automated_train_generator_api.php` to the live server:
- Contains ultra-conservative track availability checking
- Adds 15-minute buffers around train times
- Reserves 1 track for emergencies at multi-track stations
- Includes public conflict monitoring API

### 2. Test Conflict Monitoring
After deployment, test the new conflict checking:
```bash
curl "https://highball.eu/dcc/automated_train_generator_api.php?action=check_conflicts"
```
This should return JSON with conflict analysis.

### 3. Resolve Current Conflicts
Use the authenticated conflict resolution API:
```bash
curl -X POST "https://highball.eu/dcc/automated_train_generator_api.php?action=resolve_conflicts" \
  -H "Authorization: Bearer YOUR_AUTH_TOKEN"
```

## Key Enhancements Made

### Sequential Train Generation with Real-Time Conflict Checking
```php
// BEFORE: Batch generation with potential race conditions
foreach ($routes as $route) {
    $this->generateSingleTrain($route, $departureTime, $input, $stats);
}

// AFTER: Sequential generation with immediate conflict validation
foreach ($routes as $route) {
    $this->generateAndValidateSingleTrain($route, $departureTime, $input, $stats);
    // Each train is fully validated and created before the next one
}
```

### Real-Time Database State Checking
```php
// NEW: Check conflicts against current database state immediately before creation
$conflictCheck = $this->performRealTimeConflictCheck($validation['station_times'], $locomotive['id']);
if (!$conflictCheck['success']) {
    // Reject train immediately - no database inconsistency
    return;
}
$trainId = $this->createMultiHopTrain($trainData); // Only create if no conflicts
```

### Ultra-Conservative Track Checking
```php
// BEFORE: Basic track counting
if ($occupiedTracks < $totalTracks) { /* allow */ }

// AFTER: Ultra-conservative with buffers
$arrivalTimeWithBuffer = date('H:i:s', strtotime($arrivalTime) - 900); // 15 min before
$departureTimeWithBuffer = date('H:i:s', strtotime($departureTime) + 900); // 15 min after
$effectiveAvailableTracks = $totalTracks > 1 ? $totalTracks - 1 : $totalTracks; // Reserve emergency track
```

### Strict Locomotive Location Checking
```php
// BEFORE: Locomotives could "teleport" between stations
$locomotive = $this->getAvailableLocomotive($stationId, $departureTime);

// AFTER: Locomotives must be physically at departure station
$locomotive = $this->getLocomotiveAtStation($stationId, $departureTime);
```

### Public Conflict Monitoring
```php
case 'check_conflicts':
    // Public read-only check for conflicts - no authentication required
    return $this->checkStationConflicts();
```

## Alternative Conflict Checking

If the main API deployment fails, you can use the standalone conflict checker:

1. Edit `simple_conflict_checker.php` with your database credentials
2. Run it on the server to check for conflicts
3. It provides the same conflict analysis without API dependencies

## Expected Results After Deployment

### Before Fix
- Oberrittersgrün: 50+ trains on 2 tracks
- Multiple stations with impossible scheduling
- Trains overlapping in time slots

### After Fix
- Conservative track usage with safety buffers
- Automatic conflict detection and prevention
- Public monitoring API for real-time conflict checking
- Automated resolution of excess AUTO trains

## Troubleshooting

### HTTP 500 Errors
- Check PHP error logs on server
- Verify all required files are uploaded
- Ensure database permissions are correct

### Conflicts Still Detected
- Run `resolve_conflicts` action to remove excess trains
- Consider reducing train generation frequency
- Add more tracks to heavily used stations

## Monitoring Commands

```bash
# Check for conflicts
curl "https://highball.eu/dcc/automated_train_generator_api.php?action=check_conflicts"

# Resolve conflicts (requires auth)
curl -X POST "https://highball.eu/dcc/automated_train_generator_api.php?action=resolve_conflicts" \
  -H "Authorization: Bearer YOUR_TOKEN"

# Clear all AUTO trains if needed (requires admin auth)
curl -X POST "https://highball.eu/dcc/automated_train_generator_api.php?action=clear_auto" \
  -H "Authorization: Bearer ADMIN_TOKEN"
```

## Files Modified
- `automated_train_generator_api.php` - Main API with enhanced conflict checking
- `check_conflicts.php` - Standalone conflict analysis script
- `simple_conflict_checker.php` - Simple standalone checker

Deploy the enhanced `automated_train_generator_api.php` to resolve the overcrowding issues!
