-- Database migration script for kilometer-based distances and schedule calculations
-- Run this script to update the database schema

-- 1. Change station connections to use kilometers instead of meters
ALTER TABLE dcc_station_connections CHANGE COLUMN distance_meters distance_km DECIMAL(10, 3) NOT NULL;

-- 2. Create train schedule calculation table
CREATE TABLE IF NOT EXISTS dcc_train_schedule_calculations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    train_id INT NOT NULL,
    total_distance_km DECIMAL(10, 3) NOT NULL,
    average_speed_kmh DECIMAL(5, 2) NOT NULL,
    total_travel_time_minutes INT NOT NULL,
    departure_time TIME NOT NULL,
    arrival_time TIME NOT NULL,
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (train_id) REFERENCES dcc_trains(id) ON DELETE CASCADE,
    INDEX idx_train_schedule (train_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Create locomotive availability tracking table
CREATE TABLE IF NOT EXISTS dcc_locomotive_availability (
    id INT AUTO_INCREMENT PRIMARY KEY,
    locomotive_id INT NOT NULL,
    available_from DATETIME NOT NULL,
    available_until DATETIME NULL,
    current_station_id VARCHAR(8) NULL,
    status ENUM('available', 'in_transit', 'assigned', 'maintenance') DEFAULT 'available',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (locomotive_id) REFERENCES dcc_locomotives(id) ON DELETE CASCADE,
    FOREIGN KEY (current_station_id) REFERENCES dcc_stations(id) ON DELETE SET NULL,
    INDEX idx_locomotive_availability (locomotive_id, available_from),
    INDEX idx_availability_status (status, available_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Update existing connections data from meters to kilometers
UPDATE dcc_station_connections SET distance_km = distance_km / 1000 WHERE distance_km >= 1;

-- 5. Add calculated columns to trains table for schedule optimization
ALTER TABLE dcc_trains ADD COLUMN IF NOT EXISTS calculated_travel_time_minutes INT NULL;
ALTER TABLE dcc_trains ADD COLUMN IF NOT EXISTS average_speed_kmh DECIMAL(5, 2) NULL;
ALTER TABLE dcc_trains ADD COLUMN IF NOT EXISTS total_distance_km DECIMAL(10, 3) NULL;

-- 6. Create view for locomotive availability with schedule integration
CREATE OR REPLACE VIEW dcc_locomotive_schedule_availability AS
SELECT 
    l.id as locomotive_id,
    l.dcc_address,
    l.class,
    l.number,
    l.name,
    CASE 
        WHEN tl.train_id IS NOT NULL AND t.is_active = 1 THEN 'assigned'
        WHEN la.status IS NOT NULL THEN la.status
        ELSE 'available'
    END as availability_status,
    CASE 
        WHEN tl.train_id IS NOT NULL AND t.is_active = 1 THEN t.arrival_time
        WHEN la.available_until IS NOT NULL THEN la.available_until
        ELSE NULL
    END as available_from,
    CASE 
        WHEN tl.train_id IS NOT NULL AND t.is_active = 1 THEN t.arrival_station_id
        WHEN la.current_station_id IS NOT NULL THEN la.current_station_id
        ELSE NULL
    END as current_location,
    t.train_number as assigned_train,
    t.arrival_time as train_arrival_time
FROM dcc_locomotives l
LEFT JOIN dcc_train_locomotives tl ON l.id = tl.locomotive_id
LEFT JOIN dcc_trains t ON tl.train_id = t.id AND t.is_active = 1
LEFT JOIN dcc_locomotive_availability la ON l.id = la.locomotive_id 
    AND (la.available_until IS NULL OR la.available_until > NOW())
WHERE l.is_active = 1
ORDER BY l.dcc_address;

-- Insert initial availability data for all locomotives
INSERT IGNORE INTO dcc_locomotive_availability (locomotive_id, available_from, status, current_station_id)
SELECT 
    l.id,
    NOW(),
    CASE 
        WHEN tl.locomotive_id IS NOT NULL THEN 'assigned'
        ELSE 'available'
    END,
    CASE 
        WHEN t.arrival_station_id IS NOT NULL THEN t.arrival_station_id
        ELSE NULL
    END
FROM dcc_locomotives l
LEFT JOIN dcc_train_locomotives tl ON l.id = tl.locomotive_id
LEFT JOIN dcc_trains t ON tl.train_id = t.id AND t.is_active = 1
WHERE l.is_active = 1;
