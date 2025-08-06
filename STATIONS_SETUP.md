# DCC Stations Management Setup Instructions

Since PHP is not available locally, you'll need to set up the database directly on your server.

## Database Setup

### Option 1: Complete Setup (Recommended)
Run the complete database setup script on your MySQL server:

```sql
-- Execute this file on your MySQL server
SOURCE setup_complete_database.sql;
```

### Option 2: Stations Only
If you only need to add the stations table to an existing DCC system:

```sql
-- Execute this file on your MySQL server
SOURCE setup_stations.sql;
```

## Manual Database Setup via Web Interface

If you're using a web-based MySQL interface (like phpMyAdmin, cPanel, etc.):

1. Open your database management interface
2. Select your database: `highball_highball`
3. Go to the SQL tab
4. Copy and paste the contents of `setup_complete_database.sql`
5. Execute the SQL

## Manual SQL Commands

If you prefer to run commands individually:

### Create Stations Table
```sql
CREATE TABLE IF NOT EXISTS dcc_stations (
    id VARCHAR(8) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### Add Sample Data
```sql
INSERT IGNORE INTO dcc_stations (id, name, description) VALUES
('CTRL001A', 'Central Station', 'Main terminal station with 12 platforms'),
('NJCT002B', 'North Junction', 'Major junction connecting northern lines'),
('SDPT003C', 'South Depot', 'Locomotive maintenance and storage facility'),
('ETRM004D', 'East Terminal', 'Passenger terminal for eastern routes'),
('WFRD005E', 'West Freight Yard', 'Primary freight handling facility'),
('MTVW006F', 'Mountain View', 'Scenic station in the mountain region'),
('RVSD007G', 'Riverside', 'Station alongside the main river crossing');
```

## File Upload Instructions

Upload these files to your web server:

### Required Files
- `stations_api.php` - API endpoint for station management
- `stations.html` - Station management web interface
- `setup_stations.php` - PHP setup script (optional, for server-side execution)

### Updated Files
- `index.html` - Updated with navigation link to stations page

## Testing the Setup

1. Upload all files to your web server
2. Execute the database setup SQL
3. Navigate to `https://your-domain.com/path/to/stations.html`
4. You should see the stations management interface
5. Try adding, editing, and deleting stations

## API Endpoints

The stations API provides the following endpoints:

- `GET stations_api.php` - List all stations (with pagination, search, sorting)
- `GET stations_api.php?id=STATIONID` - Get specific station
- `POST stations_api.php` - Create new station
- `PUT stations_api.php?id=STATIONID` - Update existing station
- `DELETE stations_api.php?id=STATIONID` - Delete station

## Station Code Format

Station codes must be set by the user when creating a new station. The system provides helpful hints and validation:

- **Length:** Exactly 8 characters
- **Characters:** Only uppercase letters (A-Z) and numbers (0-9)
- **Examples:** `CTRL001A`, `NJCT002B`, `SDPT003C`, `TERM004D`

### Suggested Naming Convention:
- **First 4 characters:** Abbreviation of station name (e.g., CTRL for Central)
- **Next 3 characters:** Sequential numbers (001, 002, 003...)
- **Last character:** Letter variant (A, B, C... for different areas/platforms)

### Station Code Examples:
- `CTRL001A` - Central Station, Platform A
- `NJCT001A` - North Junction, Main Junction
- `YARD001A` - Main Yard, Section A
- `TERM001A` - Terminal, Arrival Platform
- `DPOT001A` - Depot, Building A

## Database Schema

The `dcc_stations` table includes:
- `id` (VARCHAR(8)) - User-defined 8-character station code (primary key)
- `name` (VARCHAR(100)) - Station name
- `description` (TEXT) - Optional description
- `created_at` (TIMESTAMP) - Creation timestamp
- `updated_at` (TIMESTAMP) - Last modification timestamp

### Station Code Validation:
- Must be exactly 8 characters
- Only uppercase letters (A-Z) and numbers (0-9) allowed
- Must be unique across all stations
- Cannot be changed after creation

## Troubleshooting

### Common Issues:

1. **Database Connection Errors**
   - Verify database credentials in `config.php`
   - Ensure MySQL server is accessible

2. **Table Already Exists**
   - The SQL uses `IF NOT EXISTS` so it's safe to run multiple times
   - Existing data won't be affected

3. **Permission Errors**
   - Ensure your database user has CREATE, INSERT, UPDATE, DELETE privileges
   - Check file permissions on uploaded PHP files

4. **API Not Working**
   - Verify `stations_api.php` is uploaded correctly
   - Check server error logs for PHP errors
   - Ensure your server supports PHP and PDO MySQL extension

## Integration with Existing DCC System

The stations system is designed to work alongside your existing DCC locomotive and accessory monitoring. The stations page is accessible via the navigation menu on the main DCC Layout Control page.

Future enhancements could include:
- Linking locomotives to stations
- Station-based route planning
- Integration with DCC block detection
- Station scheduling and timetables
