-- =============================================================================
-- DCC Railway Control System - Database Update Script
-- Database: highball_highball
-- Created: 5 August 2025
-- Purpose: Adds missing tables and columns to existing database
-- =============================================================================

-- Set proper character set and collation
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Use the target database
USE highball_highball;

-- =============================================================================
-- ADD MISSING TABLES
-- =============================================================================

-- DCC Packets table for monitoring and logging
CREATE TABLE IF NOT EXISTS dcc_packets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    arduino_timestamp BIGINT,
    packet_type ENUM('LOCOMOTIVE', 'ACCESSORY', 'RESET', 'BROADCAST', 'IDLE') NOT NULL,
    address INT,
    instruction VARCHAR(50),
    decoder_address INT,
    speed INT,
    direction ENUM('FWD', 'REV'),
    functions TEXT,
    side INT,
    state INT,
    data TEXT,
    raw_json TEXT,
    
    INDEX idx_timestamp (timestamp),
    INDEX idx_packet_type (packet_type),
    INDEX idx_address (address),
    INDEX idx_arduino_timestamp (arduino_timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Accessory states table for turnouts, signals, etc.
CREATE TABLE IF NOT EXISTS dcc_accessory_states (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id VARCHAR(8) NOT NULL,
    accessory_address INT NOT NULL,
    accessory_type ENUM('turnout', 'signal', 'crossing', 'other') DEFAULT 'turnout',
    accessory_name VARCHAR(100),
    current_state INT DEFAULT 0,
    state_description VARCHAR(100),
    last_command_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (station_id) REFERENCES dcc_stations(id) ON DELETE CASCADE,
    
    INDEX idx_station (station_id),
    INDEX idx_address (accessory_address),
    INDEX idx_type (accessory_type),
    INDEX idx_active (is_active),
    
    UNIQUE KEY uk_station_address (station_id, accessory_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Station Accessories table for turnouts, signals, and other DCC accessories
CREATE TABLE IF NOT EXISTS dcc_station_accessories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id VARCHAR(8) NOT NULL,
    accessory_address INT NOT NULL,
    accessory_name VARCHAR(100) NOT NULL,
    accessory_type VARCHAR(50) DEFAULT NULL,
    description TEXT DEFAULT NULL,
    x_coordinate DECIMAL(10,2) DEFAULT 0.00,
    y_coordinate DECIMAL(10,2) DEFAULT 0.00,
    svg_left TEXT DEFAULT NULL,
    svg_right TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (station_id) REFERENCES dcc_stations(id) ON DELETE CASCADE,
    
    INDEX idx_station (station_id),
    INDEX idx_address (accessory_address),
    INDEX idx_name (accessory_name),
    INDEX idx_type (accessory_type),
    
    UNIQUE KEY uk_station_accessory_address (station_id, accessory_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Track Accessibility table for managing which stations can access which tracks
CREATE TABLE IF NOT EXISTS dcc_track_accessibility (
    id INT AUTO_INCREMENT PRIMARY KEY,
    track_id INT NOT NULL,
    from_station_id VARCHAR(8) NOT NULL,
    from_station_name VARCHAR(100) NOT NULL,
    is_accessible BOOLEAN DEFAULT TRUE,
    default_route BOOLEAN DEFAULT FALSE,
    speed_limit_kmh INT DEFAULT 50,
    route_notes TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (track_id) REFERENCES dcc_station_tracks(id) ON DELETE CASCADE,
    FOREIGN KEY (from_station_id) REFERENCES dcc_stations(id) ON DELETE CASCADE,
    
    INDEX idx_track (track_id),
    INDEX idx_from_station (from_station_id),
    INDEX idx_accessible (is_accessible),
    INDEX idx_default_route (default_route),
    
    UNIQUE KEY uk_track_from_station (track_id, from_station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Track Routing table for storing routing information between tracks
CREATE TABLE IF NOT EXISTS dcc_track_routing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    track_id INT NOT NULL,
    station_id VARCHAR(8) NOT NULL,
    station_name VARCHAR(100) NOT NULL,
    track_number VARCHAR(8) NOT NULL,
    track_name VARCHAR(100) NOT NULL,
    from_station_id VARCHAR(8) NOT NULL,
    from_station_name VARCHAR(100) NOT NULL,
    accessibility_id INT NOT NULL,
    is_accessible BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (track_id) REFERENCES dcc_station_tracks(id) ON DELETE CASCADE,
    FOREIGN KEY (station_id) REFERENCES dcc_stations(id) ON DELETE CASCADE,
    FOREIGN KEY (from_station_id) REFERENCES dcc_stations(id) ON DELETE CASCADE,
    FOREIGN KEY (accessibility_id) REFERENCES dcc_track_accessibility(id) ON DELETE CASCADE,
    
    INDEX idx_track (track_id),
    INDEX idx_station (station_id),
    INDEX idx_from_station (from_station_id),
    INDEX idx_accessibility (accessibility_id),
    
    UNIQUE KEY uk_track_from_station_routing (track_id, from_station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- ADD MISSING COLUMNS
-- =============================================================================

-- Add dcc_address column to locomotives table if it doesn't exist
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'dcc_address'
);

SET @sql = IF(@column_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN dcc_address INT UNIQUE NOT NULL AFTER locomotive_number',
    'SELECT "Column dcc_address already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add number column (alias for locomotive_number) if it doesn't exist
SET @number_column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'number'
);

SET @sql = IF(@number_column_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN number VARCHAR(20) AFTER locomotive_number',
    'SELECT "Column number already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add class column to locomotives table if it doesn't exist
SET @class_column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'class'
);

SET @sql = IF(@class_column_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN class VARCHAR(50) AFTER model',
    'SELECT "Column class already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add picture_filename column if it doesn't exist
SET @picture_filename_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'picture_filename'
);

SET @sql = IF(@picture_filename_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN picture_filename VARCHAR(255) AFTER name',
    'SELECT "Column picture_filename already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add picture_mimetype column if it doesn't exist
SET @picture_mimetype_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'picture_mimetype'
);

SET @sql = IF(@picture_mimetype_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN picture_mimetype VARCHAR(100) AFTER picture_filename',
    'SELECT "Column picture_mimetype already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add picture_size column if it doesn't exist
SET @picture_size_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'picture_size'
);

SET @sql = IF(@picture_size_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN picture_size INT AFTER picture_mimetype',
    'SELECT "Column picture_size already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add picture_blob column if it doesn't exist
SET @picture_blob_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'picture_blob'
);

SET @sql = IF(@picture_blob_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN picture_blob LONGBLOB AFTER picture_size',
    'SELECT "Column picture_blob already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add sound_decoder column if it doesn't exist
SET @sound_decoder_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'sound_decoder'
);

SET @sql = IF(@sound_decoder_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN sound_decoder BOOLEAN DEFAULT FALSE AFTER locomotive_type',
    'SELECT "Column sound_decoder already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add functions_count column if it doesn't exist
SET @functions_count_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'functions_count'
);

SET @sql = IF(@functions_count_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN functions_count INT DEFAULT 0 AFTER sound_decoder',
    'SELECT "Column functions_count already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add function_mapping column if it doesn't exist
SET @function_mapping_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'function_mapping'
);

SET @sql = IF(@function_mapping_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN function_mapping TEXT AFTER functions_count',
    'SELECT "Column function_mapping already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add scale column if it doesn't exist
SET @scale_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'scale'
);

SET @sql = IF(@scale_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN scale VARCHAR(10) AFTER manufacturer',
    'SELECT "Column scale already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add era column if it doesn't exist
SET @era_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'era'
);

SET @sql = IF(@era_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN era VARCHAR(20) AFTER scale',
    'SELECT "Column era already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add country column if it doesn't exist
SET @country_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'country'
);

SET @sql = IF(@country_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN country VARCHAR(50) AFTER era',
    'SELECT "Column country already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add railway_company column if it doesn't exist
SET @railway_company_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'railway_company'
);

SET @sql = IF(@railway_company_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN railway_company VARCHAR(100) AFTER country',
    'SELECT "Column railway_company already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add notes column if it doesn't exist
SET @notes_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_locomotives' 
    AND COLUMN_NAME = 'notes'
);

SET @sql = IF(@notes_exists = 0, 
    'ALTER TABLE dcc_locomotives ADD COLUMN notes TEXT AFTER function_mapping',
    'SELECT "Column notes already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add last_login column to users table if it doesn't exist
SET @last_login_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_users' 
    AND COLUMN_NAME = 'last_login'
);

SET @sql = IF(@last_login_exists = 0, 
    'ALTER TABLE dcc_users ADD COLUMN last_login TIMESTAMP NULL AFTER updated_at',
    'SELECT "Column last_login already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add svg_icon column to stations table if it doesn't exist
SET @svg_icon_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_stations' 
    AND COLUMN_NAME = 'svg_icon'
);

SET @sql = IF(@svg_icon_exists = 0, 
    'ALTER TABLE dcc_stations ADD COLUMN svg_icon TEXT AFTER description',
    'SELECT "Column svg_icon already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add station_type column to stations table if it doesn't exist
SET @station_type_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_stations' 
    AND COLUMN_NAME = 'station_type'
);

SET @sql = IF(@station_type_exists = 0, 
    'ALTER TABLE dcc_stations ADD COLUMN station_type ENUM("passenger", "freight", "yard", "junction") DEFAULT "passenger" AFTER svg_icon',
    'SELECT "Column station_type already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add latitude column to stations table if it doesn't exist
SET @latitude_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_stations' 
    AND COLUMN_NAME = 'latitude'
);

SET @sql = IF(@latitude_exists = 0, 
    'ALTER TABLE dcc_stations ADD COLUMN latitude DECIMAL(10, 8) AFTER station_type',
    'SELECT "Column latitude already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add longitude column to stations table if it doesn't exist
SET @longitude_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_stations' 
    AND COLUMN_NAME = 'longitude'
);

SET @sql = IF(@longitude_exists = 0, 
    'ALTER TABLE dcc_stations ADD COLUMN longitude DECIMAL(11, 8) AFTER latitude',
    'SELECT "Column longitude already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add elevation_meters column to stations table if it doesn't exist
SET @elevation_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_stations' 
    AND COLUMN_NAME = 'elevation_meters'
);

SET @sql = IF(@elevation_exists = 0, 
    'ALTER TABLE dcc_stations ADD COLUMN elevation_meters INT AFTER longitude',
    'SELECT "Column elevation_meters already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add is_active column to stations table if it doesn't exist
SET @station_active_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_stations' 
    AND COLUMN_NAME = 'is_active'
);

SET @sql = IF(@station_active_exists = 0, 
    'ALTER TABLE dcc_stations ADD COLUMN is_active BOOLEAN DEFAULT TRUE AFTER elevation_meters',
    'SELECT "Column is_active already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add platform_height_mm column to station_tracks table if it doesn't exist
SET @platform_height_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_station_tracks' 
    AND COLUMN_NAME = 'platform_height_mm'
);

SET @sql = IF(@platform_height_exists = 0, 
    'ALTER TABLE dcc_station_tracks ADD COLUMN platform_height_mm INT AFTER max_length_meters',
    'SELECT "Column platform_height_mm already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add notes column to station_tracks table if it doesn't exist
SET @track_notes_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_station_tracks' 
    AND COLUMN_NAME = 'notes'
);

SET @sql = IF(@track_notes_exists = 0, 
    'ALTER TABLE dcc_station_tracks ADD COLUMN notes TEXT AFTER platform_height_mm',
    'SELECT "Column notes already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add max_train_length_meters column to station_tracks table if it doesn't exist
SET @max_train_length_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_station_tracks' 
    AND COLUMN_NAME = 'max_train_length_meters'
);

SET @sql = IF(@max_train_length_exists = 0, 
    'ALTER TABLE dcc_station_tracks ADD COLUMN max_train_length_meters DECIMAL(10,2) AFTER max_length_meters',
    'SELECT "Column max_train_length_meters already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add connection_type column to station_connections table if it doesn't exist
SET @connection_type_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_station_connections' 
    AND COLUMN_NAME = 'connection_type'
);

SET @sql = IF(@connection_type_exists = 0, 
    'ALTER TABLE dcc_station_connections ADD COLUMN connection_type ENUM("direct", "junction", "bridge", "tunnel", "ferry", "other") DEFAULT "direct" AFTER distance_meters',
    'SELECT "Column connection_type already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add track_speed_limit column to station_connections table if it doesn't exist
SET @track_speed_limit_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_station_connections' 
    AND COLUMN_NAME = 'track_speed_limit'
);

SET @sql = IF(@track_speed_limit_exists = 0, 
    'ALTER TABLE dcc_station_connections ADD COLUMN track_speed_limit INT DEFAULT 80 AFTER connection_type',
    'SELECT "Column track_speed_limit already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add notes column to station_connections table if it doesn't exist
SET @connection_notes_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_station_connections' 
    AND COLUMN_NAME = 'notes'
);

SET @sql = IF(@connection_notes_exists = 0, 
    'ALTER TABLE dcc_station_connections ADD COLUMN notes TEXT AFTER track_speed_limit',
    'SELECT "Column notes already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add track_condition column to station_connections table if it doesn't exist
SET @track_condition_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_station_connections' 
    AND COLUMN_NAME = 'track_condition'
);

SET @sql = IF(@track_condition_exists = 0, 
    'ALTER TABLE dcc_station_connections ADD COLUMN track_condition ENUM("good", "fair", "poor", "maintenance") DEFAULT "good" AFTER track_speed_limit',
    'SELECT "Column track_condition already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add facing_direction column to train_locomotives table if it doesn't exist
SET @facing_direction_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_train_locomotives' 
    AND COLUMN_NAME = 'facing_direction'
);

SET @sql = IF(@facing_direction_exists = 0, 
    'ALTER TABLE dcc_train_locomotives ADD COLUMN facing_direction ENUM("forward", "reverse") DEFAULT "forward" AFTER is_lead_locomotive',
    'SELECT "Column facing_direction already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add notes column to train_locomotives table if it doesn't exist
SET @train_loco_notes_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_train_locomotives' 
    AND COLUMN_NAME = 'notes'
);

SET @sql = IF(@train_loco_notes_exists = 0, 
    'ALTER TABLE dcc_train_locomotives ADD COLUMN notes TEXT AFTER facing_direction',
    'SELECT "Column notes already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- =============================================================================
-- CREATE VIEWS (after all columns are added)
-- =============================================================================

-- Create trains overview view if it doesn't exist
SET @trains_view_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.VIEWS 
    WHERE TABLE_SCHEMA = 'highball_highball' 
    AND TABLE_NAME = 'dcc_trains_overview'
);

SET @sql = IF(@trains_view_exists = 0, 
    'CREATE VIEW dcc_trains_overview AS
     SELECT 
         t.train_id,
         t.train_number,
         t.train_name,
         t.locomotive_id,
         l.locomotive_name,
         l.model,
         l.dcc_address,
         t.current_route_id,
         r.route_name,
         t.current_station_id,
         s.station_name as current_station,
         t.destination_station_id,
         dest.station_name as destination_station,
         t.status,
         t.speed,
         t.direction,
         t.created_at,
         t.updated_at
     FROM dcc_trains t
     LEFT JOIN dcc_locomotives l ON t.locomotive_id = l.locomotive_id
     LEFT JOIN dcc_routes r ON t.current_route_id = r.route_id
     LEFT JOIN dcc_stations s ON t.current_station_id = s.station_id
     LEFT JOIN dcc_stations dest ON t.destination_station_id = dest.station_id',
    'SELECT "View dcc_trains_overview already exists" as message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Create station tracks detailed view (definitive version)
CREATE OR REPLACE VIEW dcc_station_tracks_detailed AS
SELECT 
    st.id as track_id,
    st.station_id,
    s.name as station_name,
    st.track_number,
    st.track_name,
    st.track_type,
    st.max_length_meters,
    st.max_length_meters as track_length_meters,
    st.max_train_length_meters,
    st.platform_height_mm,
    st.is_electrified as electrified,
    st.notes,
    st.is_active as track_active,
    st.is_active,
    st.created_at,
    st.updated_at,
    COALESCE(accessibility_stats.accessible_from_count, 0) as accessible_from_count,
    COALESCE(accessibility_stats.total_access_rules, 0) as total_access_rules,
    CONCAT(s.name, ' - Track ', st.track_number, COALESCE(CONCAT(' (', st.track_name, ')'), '')) as display_name
FROM dcc_station_tracks st
LEFT JOIN dcc_stations s ON st.station_id = s.id
LEFT JOIN (
    SELECT 
        track_id,
        COUNT(*) as total_access_rules,
        SUM(CASE WHEN is_accessible = 1 THEN 1 ELSE 0 END) as accessible_from_count
    FROM dcc_track_accessibility 
    GROUP BY track_id
) accessibility_stats ON st.id = accessibility_stats.track_id;

-- Update existing station tracks with sample platform heights and notes
UPDATE dcc_station_tracks 
SET platform_height_mm = CASE 
    WHEN track_type = 'platform' THEN 550  -- Standard European platform height
    WHEN track_type = 'siding' THEN 100    -- Lower height for sidings
    WHEN track_type = 'yard' THEN 300      -- Yard track height
    WHEN track_type = 'through' THEN 550   -- Through track with platform
    ELSE 300                               -- Default height
END,
notes = CASE id % 4
    WHEN 0 THEN 'Express service track'
    WHEN 1 THEN 'Local passenger service'
    WHEN 2 THEN 'Freight loading track'
    WHEN 3 THEN 'Maintenance track'
END
WHERE platform_height_mm IS NULL OR notes IS NULL;

-- Update existing tracks to ensure they are active by default
UPDATE dcc_station_tracks 
SET is_active = TRUE 
WHERE is_active IS NULL;

-- Update existing tracks with default lengths if missing
UPDATE dcc_station_tracks 
SET max_length_meters = CASE track_type
    WHEN 'platform' THEN 200.0
    WHEN 'siding' THEN 150.0
    WHEN 'yard' THEN 100.0
    WHEN 'through' THEN 300.0
    WHEN 'freight' THEN 250.0
    WHEN 'maintenance' THEN 80.0
    ELSE 200.0
END
WHERE max_length_meters IS NULL OR max_length_meters = 0;

-- Update existing tracks with default max train lengths if missing
UPDATE dcc_station_tracks 
SET max_train_length_meters = CASE track_type
    WHEN 'platform' THEN 180.0
    WHEN 'siding' THEN 120.0
    WHEN 'yard' THEN 80.0
    WHEN 'through' THEN 250.0
    WHEN 'freight' THEN 200.0
    WHEN 'maintenance' THEN 60.0
    ELSE 150.0
END
WHERE max_train_length_meters IS NULL OR max_train_length_meters = 0;

-- =============================================================================
-- UPDATE EXISTING DATA (only if columns were added)
-- =============================================================================

-- Update existing locomotives with DCC addresses if the column was just added
UPDATE dcc_locomotives 
SET dcc_address = CASE locomotive_number
    WHEN 'E001' THEN 3
    WHEN 'D101' THEN 5
    WHEN 'D102' THEN 8
    WHEN 'E002' THEN 12
    WHEN 'S001' THEN 15
    ELSE id + 100  -- Default DCC addresses for any other locomotives
END
WHERE dcc_address IS NULL OR dcc_address = 0;

-- Update number column to match locomotive_number
UPDATE dcc_locomotives 
SET number = locomotive_number
WHERE number IS NULL OR number = '';

-- Update existing locomotives with class information if the column was just added
UPDATE dcc_locomotives 
SET class = CASE locomotive_number
    WHEN 'E001' THEN 'Class 185'
    WHEN 'D101' THEN 'Class 66'
    WHEN 'D102' THEN 'Class 70'
    WHEN 'E002' THEN 'Class 180'
    WHEN 'S001' THEN 'Class 4-6-0'
    ELSE CONCAT('Class ', LEFT(locomotive_number, 1))  -- Default class based on locomotive prefix
END
WHERE class IS NULL OR class = '';

-- Update scale information (assuming HO scale as default)
UPDATE dcc_locomotives 
SET scale = 'HO'
WHERE scale IS NULL OR scale = '';

-- Update era information based on locomotive type
UPDATE dcc_locomotives 
SET era = CASE locomotive_type
    WHEN 'steam' THEN 'Era III'
    WHEN 'diesel' THEN 'Era IV'
    WHEN 'electric' THEN 'Era V'
    ELSE 'Era IV'
END
WHERE era IS NULL OR era = '';

-- Update country (assuming generic European for sample data)
UPDATE dcc_locomotives 
SET country = 'Europe'
WHERE country IS NULL OR country = '';

-- Update railway company based on locomotive type
UPDATE dcc_locomotives 
SET railway_company = CASE locomotive_type
    WHEN 'steam' THEN 'British Rail'
    WHEN 'diesel' THEN 'DB Cargo'
    WHEN 'electric' THEN 'DB AG'
    ELSE 'European Railways'
END
WHERE railway_company IS NULL OR railway_company = '';

-- Set default sound decoder info
UPDATE dcc_locomotives 
SET sound_decoder = CASE locomotive_type
    WHEN 'steam' THEN TRUE
    ELSE FALSE
END
WHERE sound_decoder IS NULL;

-- Set default functions count based on locomotive type
UPDATE dcc_locomotives 
SET functions_count = CASE locomotive_type
    WHEN 'steam' THEN 8
    WHEN 'diesel' THEN 6
    WHEN 'electric' THEN 4
    ELSE 4
END
WHERE functions_count IS NULL OR functions_count = 0;

-- Set basic function mapping for locomotives
UPDATE dcc_locomotives 
SET function_mapping = CASE locomotive_type
    WHEN 'steam' THEN '{"F0":"Headlight","F1":"Bell","F2":"Horn","F3":"Steam","F4":"Brake","F5":"Coupler","F6":"Dynamic Brake","F7":"Cab Light"}'
    WHEN 'diesel' THEN '{"F0":"Headlight","F1":"Bell","F2":"Horn","F3":"Engine","F4":"Brake","F5":"Coupler"}'
    WHEN 'electric' THEN '{"F0":"Headlight","F1":"Horn","F2":"Brake","F3":"Pantograph"}'
    ELSE '{"F0":"Headlight","F1":"Horn","F2":"Brake","F3":"Light"}'
END
WHERE function_mapping IS NULL OR function_mapping = '';

-- Fix sample user passwords (password is 'password123' for all users)
UPDATE dcc_users 
SET password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
WHERE password_hash = '$2y$10$example_hash';

-- Update station data with default values
UPDATE dcc_stations 
SET station_type = 'passenger'
WHERE station_type IS NULL;

UPDATE dcc_stations 
SET is_active = TRUE
WHERE is_active IS NULL;

-- Set some default coordinates for existing stations if they don't have them
UPDATE dcc_stations 
SET latitude = 47.4979, longitude = 19.0402
WHERE id = 'STN001' AND (latitude IS NULL OR longitude IS NULL);

UPDATE dcc_stations 
SET latitude = 47.5200, longitude = 19.0800
WHERE id = 'STN002' AND (latitude IS NULL OR longitude IS NULL);

UPDATE dcc_stations 
SET latitude = 47.5500, longitude = 19.0600
WHERE id = 'STN003' AND (latitude IS NULL OR longitude IS NULL);

UPDATE dcc_stations 
SET latitude = 47.4800, longitude = 19.1000
WHERE id = 'STN004' AND (latitude IS NULL OR longitude IS NULL);

UPDATE dcc_stations 
SET latitude = 47.4900, longitude = 18.9800
WHERE id = 'STN005' AND (latitude IS NULL OR longitude IS NULL);

-- Update existing station connections with default values
UPDATE dcc_station_connections 
SET connection_type = CASE 
    WHEN distance_meters > 50000 THEN 'ferry'
    WHEN distance_meters > 20000 THEN 'bridge'
    WHEN distance_meters > 10000 THEN 'tunnel'
    WHEN max_speed_kmh < 40 THEN 'junction'
    ELSE 'direct'
END
WHERE connection_type IS NULL;

UPDATE dcc_station_connections 
SET track_speed_limit = CASE connection_type
    WHEN 'direct' THEN 120
    WHEN 'junction' THEN 60
    WHEN 'bridge' THEN 80
    WHEN 'tunnel' THEN 100
    WHEN 'ferry' THEN 15
    ELSE 80
END
WHERE track_speed_limit IS NULL OR track_speed_limit = 0;

UPDATE dcc_station_connections 
SET track_condition = CASE 
    WHEN connection_type = 'ferry' THEN 'fair'
    WHEN connection_type = 'bridge' THEN 'good'
    WHEN connection_type = 'tunnel' THEN 'good'
    WHEN distance_meters > 100000 THEN 'fair'
    ELSE 'good'
END
WHERE track_condition IS NULL;

UPDATE dcc_station_connections 
SET notes = CONCAT('Connection via ', connection_type, ' - Distance: ', distance_meters, 'm - Condition: ', track_condition)
WHERE notes IS NULL;

-- Update existing train locomotives with default values
UPDATE dcc_train_locomotives 
SET facing_direction = 'forward'
WHERE facing_direction IS NULL;

UPDATE dcc_train_locomotives 
SET notes = CASE position_in_train
    WHEN 1 THEN 'Lead locomotive'
    WHEN 2 THEN 'Second unit'
    ELSE CONCAT('Unit ', position_in_train)
END
WHERE notes IS NULL;

-- Add sample track accessibility data if tables are empty
INSERT IGNORE INTO dcc_track_accessibility (track_id, from_station_id, from_station_name, is_accessible, default_route, speed_limit_kmh, route_notes)
SELECT 
    st.id as track_id,
    s.id as from_station_id,
    s.name as from_station_name,
    TRUE as is_accessible,
    CASE WHEN s.id = st.station_id THEN TRUE ELSE FALSE END as default_route,
    CASE 
        WHEN st.track_type = 'platform' THEN 80
        WHEN st.track_type = 'through' THEN 120
        WHEN st.track_type = 'siding' THEN 40
        ELSE 60
    END as speed_limit_kmh,
    CONCAT('Route from ', s.name, ' to ', st.track_name) as route_notes
FROM dcc_station_tracks st
CROSS JOIN dcc_stations s
WHERE s.is_active = TRUE 
  AND st.is_active = TRUE
  AND NOT EXISTS (
      SELECT 1 FROM dcc_track_accessibility ta 
      WHERE ta.track_id = st.id AND ta.from_station_id = s.id
  );

-- Add corresponding routing data
INSERT IGNORE INTO dcc_track_routing (track_id, station_id, station_name, track_number, track_name, from_station_id, from_station_name, accessibility_id, is_accessible)
SELECT 
    ta.track_id,
    st.station_id,
    s1.name as station_name,
    st.track_number,
    st.track_name,
    ta.from_station_id,
    ta.from_station_name,
    ta.id as accessibility_id,
    ta.is_accessible
FROM dcc_track_accessibility ta
JOIN dcc_station_tracks st ON ta.track_id = st.id
JOIN dcc_stations s1 ON st.station_id = s1.id
WHERE NOT EXISTS (
    SELECT 1 FROM dcc_track_routing tr 
    WHERE tr.track_id = ta.track_id AND tr.from_station_id = ta.from_station_id
);

-- =============================================================================
-- VERIFICATION
-- =============================================================================

-- Show status of updates
SELECT 'Database update completed!' as status;

-- Show all tables that should exist
SELECT table_name as existing_tables
FROM information_schema.tables 
WHERE table_schema = 'highball_highball' AND table_name LIKE 'dcc_%'
ORDER BY table_name;

-- Show locomotives with complete information
SELECT locomotive_number, number, name, dcc_address, class, locomotive_type, 
       scale, era, country, railway_company, sound_decoder, functions_count
FROM dcc_locomotives 
ORDER BY dcc_address;

-- Show packet and accessory state table info
SELECT 
    table_name,
    table_rows,
    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size (MB)'
FROM information_schema.tables 
WHERE table_schema = 'highball_highball' 
AND table_name IN ('dcc_packets', 'dcc_accessory_states')
ORDER BY table_name;

-- Show user accounts for authentication testing
SELECT id, username, email, role, is_active, created_at, last_login
FROM dcc_users 
ORDER BY id;

-- Show stations with all fields
SELECT id, name, description, station_type, latitude, longitude, elevation_meters, is_active
FROM dcc_stations
ORDER BY id;

-- Show sample tracks with accessibility counts
SELECT track_id, station_name, track_number, track_name, track_type, 
       max_length_meters, electrified, track_active, accessible_from_count, total_access_rules
FROM dcc_station_tracks_detailed 
ORDER BY station_name, track_number
LIMIT 10;

-- Show raw database electrified values vs view values
SELECT 
    st.id as track_id,
    st.track_number,
    st.track_name,
    st.is_electrified as db_electrified,
    v.electrified as view_electrified,
    st.is_active as db_active,
    v.track_active as view_active
FROM dcc_station_tracks st
JOIN dcc_station_tracks_detailed v ON st.id = v.track_id
ORDER BY st.id
LIMIT 5;

-- Show sample accessibility rules
SELECT ta.track_id, st.track_number, st.track_name, ta.from_station_name, 
       ta.is_accessible, ta.default_route, ta.speed_limit_kmh
FROM dcc_track_accessibility ta
JOIN dcc_station_tracks st ON ta.track_id = st.id
ORDER BY ta.track_id, ta.from_station_name
LIMIT 10;

-- Show station connections with new columns
SELECT 
    sc.id,
    fs.name as from_station,
    ts.name as to_station,
    sc.distance_meters,
    sc.connection_type,
    sc.track_speed_limit,
    sc.track_condition,
    sc.max_speed_kmh,
    sc.bidirectional,
    sc.is_active
FROM dcc_station_connections sc
LEFT JOIN dcc_stations fs ON sc.from_station_id = fs.id
LEFT JOIN dcc_stations ts ON sc.to_station_id = ts.id
ORDER BY sc.id
LIMIT 10;

-- Show train locomotives with new columns
SELECT 
    tl.id,
    tl.train_id,
    tl.position_in_train,
    tl.is_lead_locomotive,
    tl.facing_direction,
    tl.notes,
    l.locomotive_number,
    l.name as locomotive_name
FROM dcc_train_locomotives tl
LEFT JOIN dcc_locomotives l ON tl.locomotive_id = l.id
ORDER BY tl.train_id, tl.position_in_train
LIMIT 10;
