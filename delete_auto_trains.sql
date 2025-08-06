-- SQL script to delete all trains with names starting with 'AUTO' and their related data
-- This script maintains referential integrity by deleting in the correct order

-- Start transaction to ensure all operations complete successfully
START TRANSACTION;

-- Step 1: Delete timetable stops for schedules of AUTO trains
DELETE ts FROM dcc_timetable_stops ts
JOIN dcc_train_schedules s ON ts.schedule_id = s.id
JOIN dcc_trains t ON s.train_id = t.id
WHERE t.train_name LIKE 'AUTO%' OR t.train_number LIKE 'AUTO%';

-- Step 2: Delete train schedules for AUTO trains
DELETE s FROM dcc_train_schedules s
JOIN dcc_trains t ON s.train_id = t.id
WHERE t.train_name LIKE 'AUTO%' OR t.train_number LIKE 'AUTO%';

-- Step 3: Delete locomotive assignments for AUTO trains
DELETE tl FROM dcc_train_locomotives tl
JOIN dcc_trains t ON tl.train_id = t.id
WHERE t.train_name LIKE 'AUTO%' OR t.train_number LIKE 'AUTO%';

-- Step 4: Finally delete the AUTO trains themselves
DELETE FROM dcc_trains 
WHERE train_name LIKE 'AUTO%' OR train_number LIKE 'AUTO%';

-- Show how many records were affected
SELECT ROW_COUNT() as trains_deleted;

-- Commit the transaction
COMMIT;

-- Optional: Show remaining trains to verify deletion
SELECT train_number, train_name, departure_time, route 
FROM dcc_trains 
ORDER BY departure_time;
