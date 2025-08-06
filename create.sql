-- =============================================================================
-- DCC Railway Control System - Complete Database Creation Script
-- Database: highball_highball
-- Created: 5 August 2025
-- Purpose: Creates all database objects with dcc_ prefix for clean deployment
-- =============================================================================

-- Set proper character set and collation
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- Use the target database
USE highball_highball;

-- Drop existing tables if they exist (in reverse dependency order)
DROP TABLE IF EXISTS dcc_track_occupancy;
DROP TABLE IF EXISTS dcc_timetable_stops;
DROP TABLE IF EXISTS dcc_train_schedules;
DROP TABLE IF EXISTS dcc_train_locomotives;
DROP TABLE IF EXISTS dcc_trains;
DROP TABLE IF EXISTS dcc_station_tracks;
DROP TABLE IF EXISTS dcc_station_connections;
DROP TABLE IF EXISTS dcc_locomotives;
DROP TABLE IF EXISTS dcc_stations;
DROP TABLE IF EXISTS dcc_user_sessions;
DROP TABLE IF EXISTS dcc_users;
DROP TABLE IF EXISTS dcc_packets;
DROP TABLE IF EXISTS dcc_accessory_states;

-- =============================================================================
-- CORE TABLES
-- =============================================================================

-- Users table for authentication
CREATE TABLE dcc_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100),
    last_name VARCHAR(100),
    role ENUM('viewer', 'operator', 'admin') DEFAULT 'viewer',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_username (username),
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- User sessions for authentication
CREATE TABLE dcc_user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    
    FOREIGN KEY (user_id) REFERENCES dcc_users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Stations table
CREATE TABLE dcc_stations (
    id VARCHAR(8) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    station_type ENUM('passenger', 'freight', 'yard', 'junction') DEFAULT 'passenger',
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    elevation_meters INT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_name (name),
    INDEX idx_type (station_type),
    INDEX idx_active (is_active),
    INDEX idx_location (latitude, longitude)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Station connections (tracks between stations)
CREATE TABLE dcc_station_connections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    from_station_id VARCHAR(8) NOT NULL,
    to_station_id VARCHAR(8) NOT NULL,
    distance_meters DECIMAL(10, 2) NOT NULL,
    max_speed_kmh INT DEFAULT 50,
    track_type ENUM('single', 'double', 'multiple') DEFAULT 'single',
    bidirectional BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (from_station_id) REFERENCES dcc_stations(id) ON DELETE CASCADE,
    FOREIGN KEY (to_station_id) REFERENCES dcc_stations(id) ON DELETE CASCADE,
    
    INDEX idx_from_station (from_station_id),
    INDEX idx_to_station (to_station_id),
    INDEX idx_active (is_active),
    INDEX idx_bidirectional (bidirectional),
    
    UNIQUE KEY uk_connection (from_station_id, to_station_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Station tracks (platforms, sidings, etc.)
CREATE TABLE dcc_station_tracks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id VARCHAR(8) NOT NULL,
    track_number VARCHAR(10) NOT NULL,
    track_name VARCHAR(50),
    track_type ENUM('platform', 'siding', 'yard', 'through') DEFAULT 'platform',
    max_length_meters INT,
    is_electrified BOOLEAN DEFAULT FALSE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (station_id) REFERENCES dcc_stations(id) ON DELETE CASCADE,
    
    INDEX idx_station (station_id),
    INDEX idx_track_type (track_type),
    INDEX idx_active (is_active),
    
    UNIQUE KEY uk_station_track (station_id, track_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Locomotives table
CREATE TABLE dcc_locomotives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    locomotive_number VARCHAR(20) UNIQUE NOT NULL,
    name VARCHAR(100),
    dcc_address INT UNIQUE NOT NULL,
    manufacturer VARCHAR(50),
    model VARCHAR(50),
    locomotive_type ENUM('steam', 'diesel', 'electric', 'hybrid') DEFAULT 'diesel',
    power_rating_kw INT,
    max_speed_kmh INT,
    length_meters DECIMAL(5, 2),
    weight_tons DECIMAL(6, 2),
    is_active BOOLEAN DEFAULT TRUE,
    current_status ENUM('available', 'in_service', 'maintenance', 'retired') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_number (locomotive_number),
    INDEX idx_dcc_address (dcc_address),
    INDEX idx_type (locomotive_type),
    INDEX idx_status (current_status),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- DCC Packets table for monitoring and logging
CREATE TABLE dcc_packets (
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
CREATE TABLE dcc_accessory_states (
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

-- =============================================================================
-- TRAIN AND SCHEDULE TABLES
-- =============================================================================

-- Trains table
CREATE TABLE dcc_trains (
    id INT AUTO_INCREMENT PRIMARY KEY,
    train_number VARCHAR(20) UNIQUE NOT NULL,
    train_name VARCHAR(100),
    train_type ENUM('passenger', 'freight', 'mixed', 'maintenance') DEFAULT 'passenger',
    route TEXT,
    departure_station_id VARCHAR(8) NOT NULL,
    arrival_station_id VARCHAR(8) NOT NULL,
    departure_time TIME NOT NULL,
    arrival_time TIME NOT NULL,
    max_speed_kmh INT,
    consist_notes TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (departure_station_id) REFERENCES dcc_stations(id),
    FOREIGN KEY (arrival_station_id) REFERENCES dcc_stations(id),
    
    INDEX idx_train_number (train_number),
    INDEX idx_train_type (train_type),
    INDEX idx_departure_station (departure_station_id),
    INDEX idx_arrival_station (arrival_station_id),
    INDEX idx_departure_time (departure_time),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Train locomotives assignment
CREATE TABLE dcc_train_locomotives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    train_id INT NOT NULL,
    locomotive_id INT NOT NULL,
    position_in_train TINYINT DEFAULT 1,
    is_lead_locomotive BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (train_id) REFERENCES dcc_trains(id) ON DELETE CASCADE,
    FOREIGN KEY (locomotive_id) REFERENCES dcc_locomotives(id) ON DELETE CASCADE,
    
    INDEX idx_train (train_id),
    INDEX idx_locomotive (locomotive_id),
    INDEX idx_lead (is_lead_locomotive),
    
    UNIQUE KEY uk_train_position (train_id, position_in_train)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Train schedules table
CREATE TABLE dcc_train_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    train_id INT NOT NULL,
    schedule_name VARCHAR(100) NOT NULL,
    effective_date DATE NOT NULL,
    expiry_date DATE NULL,
    schedule_type ENUM('regular', 'seasonal', 'special', 'maintenance') DEFAULT 'regular',
    frequency ENUM('daily', 'weekdays', 'weekends', 'weekly', 'monthly') DEFAULT 'daily',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (train_id) REFERENCES dcc_trains(id) ON DELETE CASCADE,
    
    INDEX idx_train (train_id),
    INDEX idx_effective_date (effective_date),
    INDEX idx_schedule_type (schedule_type),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Timetable stops (individual stops for each schedule)
CREATE TABLE dcc_timetable_stops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NOT NULL,
    station_id VARCHAR(8) NOT NULL,
    track_id INT NULL,
    stop_sequence INT NOT NULL,
    arrival_time TIME NOT NULL,
    departure_time TIME NOT NULL,
    stop_type ENUM('origin', 'intermediate', 'destination', 'technical') DEFAULT 'intermediate',
    dwell_time_minutes INT DEFAULT 2,
    is_conditional BOOLEAN DEFAULT FALSE,
    platform_assignment VARCHAR(10) NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (schedule_id) REFERENCES dcc_train_schedules(id) ON DELETE CASCADE,
    FOREIGN KEY (station_id) REFERENCES dcc_stations(id) ON DELETE CASCADE,
    FOREIGN KEY (track_id) REFERENCES dcc_station_tracks(id) ON DELETE SET NULL,
    
    INDEX idx_schedule_sequence (schedule_id, stop_sequence),
    INDEX idx_station_time (station_id, arrival_time),
    INDEX idx_departure_time (departure_time),
    INDEX idx_stop_type (stop_type),
    INDEX idx_active_stops (is_active, schedule_id),
    
    UNIQUE KEY uk_schedule_sequence (schedule_id, stop_sequence),
    
    CONSTRAINT chk_time_logic CHECK (departure_time >= arrival_time OR departure_time = '00:00:00'),
    CONSTRAINT chk_dwell_time CHECK (dwell_time_minutes >= 0),
    CONSTRAINT chk_stop_sequence CHECK (stop_sequence > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Track occupancy table
CREATE TABLE dcc_track_occupancy (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_id VARCHAR(8) NOT NULL,
    track_id INT NOT NULL,
    schedule_id INT NOT NULL,
    stop_id INT NOT NULL,
    occupied_from DATETIME NOT NULL,
    occupied_until DATETIME NOT NULL,
    occupancy_type ENUM('arrival', 'departure', 'layover', 'maintenance') DEFAULT 'layover',
    is_confirmed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (station_id) REFERENCES dcc_stations(id) ON DELETE CASCADE,
    FOREIGN KEY (track_id) REFERENCES dcc_station_tracks(id) ON DELETE CASCADE,
    FOREIGN KEY (schedule_id) REFERENCES dcc_train_schedules(id) ON DELETE CASCADE,
    
    INDEX idx_station_track (station_id, track_id),
    INDEX idx_schedule (schedule_id),
    INDEX idx_time_range (occupied_from, occupied_until),
    INDEX idx_occupancy_type (occupancy_type),
    INDEX idx_confirmed (is_confirmed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =============================================================================
-- TRIGGERS FOR DATA VALIDATION
-- =============================================================================

DELIMITER //

-- Trigger: Validate stop sequence consistency
CREATE TRIGGER tr_validate_stop_sequence
BEFORE INSERT ON dcc_timetable_stops
FOR EACH ROW
BEGIN
    DECLARE max_seq INT DEFAULT 0;
    
    -- Get current max sequence for this schedule
    SELECT COALESCE(MAX(stop_sequence), 0) INTO max_seq
    FROM dcc_timetable_stops 
    WHERE schedule_id = NEW.schedule_id;
    
    -- If inserting intermediate stop, validate sequence
    IF NEW.stop_sequence > max_seq + 1 THEN
        SIGNAL SQLSTATE '45000' 
        SET MESSAGE_TEXT = 'Stop sequence gap detected - sequences must be consecutive';
    END IF;
END //

DELIMITER ;

-- =============================================================================
-- SAMPLE DATA INSERT
-- =============================================================================

-- Insert sample users
INSERT INTO dcc_users (username, email, password_hash, first_name, last_name, role) VALUES
('admin', 'admin@dcc.local', '$2y$10$example_hash', 'DCC', 'Administrator', 'admin'),
('operator', 'operator@dcc.local', '$2y$10$example_hash', 'Train', 'Operator', 'operator'),
('viewer', 'viewer@dcc.local', '$2y$10$example_hash', 'System', 'Viewer', 'viewer');

-- Insert sample stations
INSERT INTO dcc_stations (id, name, description, station_type, latitude, longitude) VALUES
('STN001', 'Central Station', 'Main passenger terminal', 'passenger', 47.4979, 19.0402),
('STN002', 'East Junction', 'Major railway junction', 'junction', 47.5200, 19.0800),
('STN003', 'North Terminal', 'Northern passenger station', 'passenger', 47.5500, 19.0600),
('STN004', 'Freight Yard', 'Main freight handling facility', 'freight', 47.4800, 19.1000),
('STN005', 'West Station', 'Western passenger terminal', 'passenger', 47.4900, 18.9800);

-- Insert sample station connections
INSERT INTO dcc_station_connections (from_station_id, to_station_id, distance_meters, max_speed_kmh, bidirectional) VALUES
('STN001', 'STN002', 8500, 80, TRUE),
('STN002', 'STN003', 6200, 60, TRUE),
('STN002', 'STN004', 4800, 40, TRUE),
('STN001', 'STN005', 12000, 100, TRUE),
('STN003', 'STN005', 15500, 80, TRUE);

-- Insert sample station tracks
INSERT INTO dcc_station_tracks (station_id, track_number, track_name, track_type, max_length_meters) VALUES
('STN001', '1', 'Platform 1', 'platform', 300),
('STN001', '2', 'Platform 2', 'platform', 300),
('STN001', '3', 'Platform 3', 'platform', 250),
('STN002', '1', 'Through Track 1', 'through', 400),
('STN002', '2', 'Through Track 2', 'through', 400),
('STN003', '1', 'Platform A', 'platform', 200),
('STN003', '2', 'Platform B', 'platform', 200),
('STN004', '1', 'Freight Track 1', 'yard', 800),
('STN004', '2', 'Freight Track 2', 'yard', 800),
('STN005', '1', 'Main Platform', 'platform', 350);

-- Insert sample locomotives
INSERT INTO dcc_locomotives (locomotive_number, name, dcc_address, manufacturer, model, locomotive_type, power_rating_kw, max_speed_kmh) VALUES
('E001', 'Express Runner', 3, 'ElectroTrain', 'ET-2000', 'electric', 4000, 160),
('D101', 'Diesel Hauler', 5, 'PowerLoco', 'PL-1500', 'diesel', 1500, 120),
('D102', 'Freight Master', 8, 'PowerLoco', 'PL-2500', 'diesel', 2500, 100),
('E002', 'City Express', 12, 'ElectroTrain', 'ET-1500', 'electric', 1500, 140),
('S001', 'Heritage Steam', 15, 'ClassicRail', 'CR-460', 'steam', 800, 80);

-- Insert sample trains
INSERT INTO dcc_trains (train_number, train_name, train_type, route, departure_station_id, arrival_station_id, departure_time, arrival_time) VALUES
('IC101', 'InterCity Express', 'passenger', 'STN001 → STN002 → STN003', 'STN001', 'STN003', '08:00:00', '09:15:00'),
('RE201', 'Regional Service', 'passenger', 'STN001 → STN005', 'STN001', 'STN005', '10:30:00', '11:45:00'),
('FR301', 'Freight Express', 'freight', 'STN004 → STN002 → STN005', 'STN004', 'STN005', '14:00:00', '16:30:00');

-- =============================================================================
-- COMPLETION MESSAGE
-- =============================================================================

SELECT 'DCC Railway Control System database created successfully!' as status,
       COUNT(table_name) as tables_created
FROM information_schema.tables 
WHERE table_schema = 'highball_highball' AND table_name LIKE 'dcc_%';

-- Show all created tables
SELECT table_name as created_tables
FROM information_schema.tables 
WHERE table_schema = 'highball_highball' AND table_name LIKE 'dcc_%'
ORDER BY table_name;
