-- Add turnaround time configuration to locomotives table
ALTER TABLE dcc_locomotives ADD COLUMN IF NOT EXISTS turnaround_time_minutes INT DEFAULT 10 COMMENT 'Minimum time in minutes required between consecutive train assignments';

-- Update existing locomotives with default turnaround time
UPDATE dcc_locomotives SET turnaround_time_minutes = 10 WHERE turnaround_time_minutes IS NULL;
