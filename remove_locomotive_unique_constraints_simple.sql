-- Simple script to remove UNIQUE constraints from locomotive number fields
-- Run this if you know the exact constraint names

USE dcc;

-- Show current indexes to see what exists
SHOW INDEX FROM dcc_locomotives WHERE Column_name IN ('number', 'locomotive_number');

-- Remove UNIQUE constraints - try each one individually
-- If a constraint doesn't exist, the command will show an error but won't break anything

-- Try common constraint names:
ALTER TABLE dcc_locomotives DROP INDEX locomotive_number;
ALTER TABLE dcc_locomotives DROP INDEX number;
ALTER TABLE dcc_locomotives DROP INDEX unique_locomotive_number;
ALTER TABLE dcc_locomotives DROP INDEX unique_number;

-- Show final state
SHOW INDEX FROM dcc_locomotives WHERE Column_name IN ('number', 'locomotive_number');
