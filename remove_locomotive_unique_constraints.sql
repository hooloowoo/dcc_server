-- Remove UNIQUE constraints from locomotive number fields
-- This script removes UNIQUE constraints that are causing duplicate entry errors

USE dcc;

-- First, let's check what constraints exist
-- (This is informational - you can run this separately to see current constraints)
-- SHOW INDEX FROM dcc_locomotives WHERE Column_name IN ('number', 'locomotive_number');

-- Remove UNIQUE constraint from 'number' column if it exists
-- The constraint name might vary, so we'll try common names
SET @sql = (SELECT CONCAT('ALTER TABLE dcc_locomotives DROP INDEX ', CONSTRAINT_NAME, ';')
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = 'dcc' 
            AND TABLE_NAME = 'dcc_locomotives' 
            AND CONSTRAINT_TYPE = 'UNIQUE'
            AND CONSTRAINT_NAME LIKE '%number%'
            AND CONSTRAINT_NAME NOT LIKE '%locomotive_number%'
            LIMIT 1);

SET @sql = IFNULL(@sql, 'SELECT "No UNIQUE constraint found on number column" as message;');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Remove UNIQUE constraint from 'locomotive_number' column if it exists
SET @sql = (SELECT CONCAT('ALTER TABLE dcc_locomotives DROP INDEX ', CONSTRAINT_NAME, ';')
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = 'dcc' 
            AND TABLE_NAME = 'dcc_locomotives' 
            AND CONSTRAINT_TYPE = 'UNIQUE'
            AND CONSTRAINT_NAME LIKE '%locomotive_number%'
            LIMIT 1);

SET @sql = IFNULL(@sql, 'SELECT "No UNIQUE constraint found on locomotive_number column" as message;');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Alternative approach - try to drop by common index names
-- These are fallback attempts in case the above dynamic approach doesn't work

-- Try dropping common constraint names
SET @sql = 'ALTER TABLE dcc_locomotives DROP INDEX locomotive_number;';
SET @sql_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                   WHERE table_schema = 'dcc' 
                   AND table_name = 'dcc_locomotives' 
                   AND index_name = 'locomotive_number'
                   AND non_unique = 0);

SET @sql = IF(@sql_exists > 0, @sql, 'SELECT "locomotive_number index does not exist or is not unique" as message;');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Try dropping number constraint
SET @sql = 'ALTER TABLE dcc_locomotives DROP INDEX number;';
SET @sql_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                   WHERE table_schema = 'dcc' 
                   AND table_name = 'dcc_locomotives' 
                   AND index_name = 'number'
                   AND non_unique = 0);

SET @sql = IF(@sql_exists > 0, @sql, 'SELECT "number index does not exist or is not unique" as message;');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Try dropping other possible constraint names
SET @sql = 'ALTER TABLE dcc_locomotives DROP INDEX unique_number;';
SET @sql_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                   WHERE table_schema = 'dcc' 
                   AND table_name = 'dcc_locomotives' 
                   AND index_name = 'unique_number'
                   AND non_unique = 0);

SET @sql = IF(@sql_exists > 0, @sql, 'SELECT "unique_number index does not exist" as message;');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql = 'ALTER TABLE dcc_locomotives DROP INDEX unique_locomotive_number;';
SET @sql_exists = (SELECT COUNT(*) FROM information_schema.statistics 
                   WHERE table_schema = 'dcc' 
                   AND table_name = 'dcc_locomotives' 
                   AND index_name = 'unique_locomotive_number'
                   AND non_unique = 0);

SET @sql = IF(@sql_exists > 0, @sql, 'SELECT "unique_locomotive_number index does not exist" as message;');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Show final state
SELECT "UNIQUE constraints removal completed. Here are the remaining indexes:" as message;
SHOW INDEX FROM dcc_locomotives WHERE Column_name IN ('number', 'locomotive_number');
