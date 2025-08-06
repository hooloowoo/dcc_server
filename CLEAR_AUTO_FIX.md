# Fix for "Clear AUTO Trains" HTTP 500 Error

## Problem
The "Clear AUTO trains" function is returning HTTP 500 error on the live server.

## Root Cause
The enhanced `automated_train_generator_api.php` hasn't been deployed to the live server yet, or there's a syntax error.

## Fixed Issues
✅ **Fixed typo in checkStationConflicts method**: `$conflictingSt ations` → `$conflictingStations`
✅ **Verified all methods are complete**: `clearAutoTrains`, `clearAllTrains`, `resolveTrackConflicts`
✅ **All SQL statements are properly formed**

## Deployment Steps

### 1. Upload Fixed File
Upload the corrected `automated_train_generator_api.php` to your live server at:
```
https://highball.eu/dcc/automated_train_generator_api.php
```

### 2. Test the API
After uploading, test each endpoint:

```bash
# Test clear AUTO trains (requires authentication)
curl -X POST "https://highball.eu/dcc/automated_train_generator_api.php?action=clear_auto" \
  -H "Authorization: Bearer YOUR_AUTH_TOKEN"

# Test conflict checking (public)
curl "https://highball.eu/dcc/automated_train_generator_api.php?action=check_conflicts"

# Test conflict resolution (requires authentication)  
curl -X POST "https://highball.eu/dcc/automated_train_generator_api.php?action=resolve_conflicts" \
  -H "Authorization: Bearer YOUR_AUTH_TOKEN"
```

### 3. Expected Results

#### Clear AUTO Trains Success:
```json
{
  "status": "success",
  "deleted_count": 42,
  "message": "Successfully deleted 42 AUTO trains"
}
```

#### Clear AUTO Trains (No Trains):
```json
{
  "status": "success", 
  "deleted_count": 0,
  "message": "No AUTO trains found to delete"
}
```

## Alternative: Manual Cleanup

If the API still doesn't work, you can manually clean AUTO trains via SQL:

```sql
-- Step 1: Delete timetable stops
DELETE ts FROM dcc_timetable_stops ts
JOIN dcc_train_schedules s ON ts.schedule_id = s.id
JOIN dcc_trains t ON s.train_id = t.id
WHERE t.train_number LIKE 'AUTO%';

-- Step 2: Delete schedules
DELETE s FROM dcc_train_schedules s
JOIN dcc_trains t ON s.train_id = t.id
WHERE t.train_number LIKE 'AUTO%';

-- Step 3: Delete locomotive assignments
DELETE tl FROM dcc_train_locomotives tl
JOIN dcc_trains t ON tl.train_id = t.id
WHERE t.train_number LIKE 'AUTO%';

-- Step 4: Delete AUTO trains
DELETE FROM dcc_trains WHERE train_number LIKE 'AUTO%';
```

## Troubleshooting

### Still Getting HTTP 500?
1. Check PHP error logs on your server
2. Verify file permissions (should be readable by web server)
3. Ensure `config.php` and `auth_utils.php` exist and are accessible
4. Test with the `test_clear_auto.php` script for detailed error information

### File Not Updating?
1. Clear any server-side cache (if using caching)
2. Check file timestamps to confirm upload
3. Try renaming the file and uploading again

### Authentication Issues?
The clear_auto action requires admin or operator role. Ensure you're properly authenticated.

## Files Modified
- `automated_train_generator_api.php` - Fixed typo in checkStationConflicts method
- `test_clear_auto.php` - Debug script for testing locally

Once deployed, the "Clear AUTO trains" functionality should work properly and help resolve the overcrowding issues!
