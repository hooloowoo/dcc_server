-- Add nr column to dcc_stations table for ordering
ALTER TABLE dcc_stations 
ADD COLUMN nr INT DEFAULT NULL AFTER id,
ADD INDEX idx_nr (nr);
