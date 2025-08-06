# DCC Railway Control System

A comprehensive digital command control (DCC) railway management system built with PHP, MySQL, and modern web technologies for model railway operations.

## Features

### Core Railway Management
- **Station Management**: Create and manage railway stations with multiple tracks and platforms
- **Train Operations**: Schedule and manage train services with automated route validation
- **Locomotive Fleet**: Track and assign locomotives to train services
- **Route Planning**: Automated pathfinding and route validation between stations using Dijkstra's algorithm
- **Schedule Management**: Comprehensive timetabling with conflict detection and resource blocking

### Enhanced Train Creation
- **Route Validation**: Automatic validation of routes between stations
- **Conflict Detection**: Real-time checking for track and time conflicts
- **Resource Blocking**: Automatic track occupancy management during scheduled times
- **Schedule Generation**: Automated creation of detailed timetables with intermediate stops
- **"No Detailed Schedule Available"**: Enhanced display with actionable options for unscheduled trains

### User Management
- **Role-based Access**: Admin, Operator, and Viewer roles with different permissions
- **Session Management**: Secure authentication with automatic session timeouts
- **Activity Tracking**: Comprehensive logging of user actions and system events

## Installation

### Prerequisites
- PHP 7.4 or higher with PDO MySQL extension
- MySQL 5.7 or higher
- Web server (Apache/Nginx)

### Database Setup

1. **Create the database**:
   ```sql
   CREATE DATABASE highball_highball CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```

2. **Run the installation script**:
   ```bash
   mysql -u username -p highball_highball < create.sql
   ```

3. **Configure database connection**:
   Edit `config.php` and update the database credentials:
   ```php
   private $host = 'your_host';
   private $db_name = 'highball_highball';  
   private $username = 'your_username';
   private $password = 'your_password';
   ```

### Web Server Setup
1. **Deploy files**: Copy all files to your web server document root
2. **Set permissions**: Ensure the web server can read all files  
3. **Configure SSL**: Recommended for production use

## Database Schema

All database objects use the `dcc_` prefix for clean organization:

### Core Tables
- `dcc_users` - User accounts and authentication
- `dcc_user_sessions` - Active user sessions
- `dcc_stations` - Railway stations with coordinates and types
- `dcc_station_connections` - Tracks between stations with distances
- `dcc_station_tracks` - Individual tracks at stations with platform info
- `dcc_locomotives` - Locomotive fleet with DCC addresses

### Operations Tables
- `dcc_trains` - Train services and route definitions
- `dcc_train_locomotives` - Locomotive assignments to trains
- `dcc_train_schedules` - Train schedules with timing and route validation
- `dcc_timetable_stops` - Individual station stops with platform assignments
- `dcc_track_occupancy` - Track usage tracking and conflict prevention

## API Endpoints

### Authentication
- `auth_api.php` - User login/logout and session management

### Core Management
- `stations_api.php` - Station management (CRUD operations)
- `locomotives_api.php` - Locomotive fleet management
- `trains_api.php` - Train service management

### Enhanced Features  
- `enhanced_train_creator_api.php` - Advanced train creation with route validation
- `route_validator_api.php` - Route validation and pathfinding using Dijkstra's algorithm
- `station_network_api.php` - Station network analysis and shortest path calculation

### Support APIs
- `station_connections_api.php` - Station connection management
- `station_tracks_api.php` - Track management with platform assignments
- `timetable_api.php` - Schedule management and timetable operations
- `timetable_management_api.php` - Advanced timetable management features

### Monitoring
- `api.php` - DCC packet monitoring and real-time data collection
- `accessory_states_api.php` - Track accessory state management

## Frontend Pages

### Main Interface
- `index.html` - Main dashboard with system overview
- `dashboard.html` - Comprehensive system status dashboard
- `monitor.html` - Real-time DCC packet monitoring

### Management Pages
- `stations.html` - Station management interface
- `locomotives.html` - Locomotive fleet management
- `trains.html` - Train service management
- `timetable.html` - Schedule management and timetable display

### Enhanced Features
- `enhanced_train_generator.html` - Add Train interface with automatic route validation
- `station_connections.html` - Network connection management
- `station_tracks.html` - Track configuration with platform assignments
- `station_view.html` - Detailed station information and track layout

### Utilities
- `login.html` - User authentication interface
- `register.html` - User registration (admin-controlled)
- `train_schedule.html` - Individual train schedule display
- `timetable_stops.html` - Detailed stop management

## Usage

### Creating a Train Service

1. **Access Add Train**: Navigate to `enhanced_train_generator.html`
2. **Select Route**: Choose departure and arrival stations from dropdown
3. **Set Departure Time**: Specify when the train should depart
4. **Automatic Validation**: System automatically:
   - Calculates optimal route using Dijkstra's algorithm
   - Estimates arrival time based on distance and speed
   - Validates track availability and detects conflicts
   - Creates intermediate stops if needed
5. **Assign Locomotive**: Optionally assign an available locomotive
6. **Create Service**: System creates train with full schedule and track occupancy records

### Managing Stations

1. **Access Station Management**: Navigate to `stations.html`
2. **Add Stations**: Create new stations with names, coordinates, and types
3. **Configure Tracks**: Add platforms and tracks to stations via `station_tracks.html`
4. **Set Connections**: Define track connections between stations with distances

### Route Validation System

The system uses advanced pathfinding to:
- **Find Optimal Routes**: Dijkstra's algorithm calculates shortest paths between stations
- **Detect Conflicts**: Checks for scheduling conflicts and track availability
- **Resource Blocking**: Automatically reserves tracks during scheduled times
- **Time Estimation**: Calculates realistic travel times based on distance

### User Management

1. **Admin Access**: Login with admin credentials
2. **Create Users**: Add operators and viewers with appropriate roles
3. **Manage Sessions**: Monitor active sessions and enforce timeouts
4. **Audit Trail**: Review user actions and system changes

## Security Features

- **Password Hashing**: Secure password storage using PHP's `password_hash()`
- **Session Management**: Secure session handling with automatic timeouts
- **Role-based Access Control**: Different permission levels (Admin, Operator, Viewer)
- **CSRF Protection**: Protection against cross-site request forgery attacks
- **SQL Injection Prevention**: All database queries use prepared statements
- **Input Validation**: Comprehensive validation of all user inputs

## Development

### Code Structure
- **Modular Design**: Separate APIs for different functionalities
- **Clean Database Schema**: Consistent naming with `dcc_` prefix throughout
- **Error Handling**: Comprehensive error logging and user-friendly feedback
- **Documentation**: Inline code documentation and detailed API responses

### Database Design
- **Referential Integrity**: Foreign key constraints ensure data consistency
- **Performance Optimization**: Proper indexing for optimal query performance
- **Scalability**: Designed to handle multiple concurrent users and operations
- **Transaction Safety**: Critical operations wrapped in database transactions

### Route Validation Algorithm
The system implements Dijkstra's shortest path algorithm to:
1. Build a network graph from station connections
2. Calculate optimal routes between any two stations
3. Estimate travel times based on distance and locomotive capabilities
4. Validate route feasibility and detect potential conflicts

## File Structure
```
dcc_server/
├── config.php                          # Database configuration
├── create.sql                          # Complete database creation script
├── auth_api.php                        # Authentication API
├── auth_utils.php                      # Authentication utilities
├── stations_api.php                    # Station management API
├── locomotives_api.php                 # Locomotive management API
├── trains_api.php                      # Train management API
├── enhanced_train_creator_api.php      # Advanced train creation API
├── route_validator_api.php             # Route validation API
├── station_network_api.php             # Network analysis API
├── station_connections_api.php         # Connection management API
├── station_tracks_api.php              # Track management API
├── timetable_api.php                   # Timetable API
├── timetable_management_api.php        # Advanced timetable management
├── api.php                             # DCC monitoring API
├── accessory_states_api.php            # Accessory management API
├── index.html                          # Main dashboard
├── dashboard.html                      # System dashboard
├── monitor.html                        # DCC packet monitor
├── stations.html                       # Station management
├── locomotives.html                    # Locomotive management
├── trains.html                         # Train management
├── timetable.html                      # Timetable display
├── enhanced_train_generator.html       # Add Train interface
├── station_connections.html            # Connection management
├── station_tracks.html                 # Track configuration
├── station_view.html                   # Station details
├── login.html                          # User login
├── register.html                       # User registration
├── train_schedule.html                 # Schedule display
├── timetable_stops.html                # Stop management
├── ENHANCED_TRAIN_SYSTEM.md           # Enhanced system documentation
├── STATIONS_SETUP.md                  # Station setup guide
└── README.md                          # This file
```

## License

This project is proprietary software for DCC Railway Control System.

## Support

For technical support or questions, please contact the development team.
# dcc_server
