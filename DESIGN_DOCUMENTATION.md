# DCC Railway Control System - Design Documentation

## 📋 Table of Contents
1. [System Overview](#system-overview)
2. [Architecture Overview](#architecture-overview)
3. [Database Design](#database-design)
4. [Component Architecture](#component-architecture)
5. [API Design](#api-design)
6. [User Interface Architecture](#user-interface-architecture)
7. [Security Model](#security-model)
8. [Data Flow Diagrams](#data-flow-diagrams)
9. [Deployment Architecture](#deployment-architecture)
10. [UML Diagrams](#uml-diagrams)

---

## 📖 System Overview

The DCC Railway Control System is a comprehensive web-based application for managing digital model railway operations. It provides functionality for locomotive management, train scheduling, station network management, and route planning with collision detection.

### Key Features
- **Locomotive Management**: Digital locomotive inventory with DCC addressing
- **Train Scheduling**: Advanced train creation with route validation and conflict detection
- **Station Network**: Complete station and track connection management
- **Timetable System**: Traditional railway timetables and German-style Buchfahrplan
- **User Authentication**: Role-based access control (Admin, Operator, Viewer)
- **Real-time Operations**: Live monitoring and control capabilities

---

## 🏗️ Architecture Overview

### System Architecture Pattern
The system follows a **3-Tier Architecture** pattern:

```
┌─────────────────────────────────────────────────────┐
│                PRESENTATION TIER                    │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐   │
│  │   Web UI    │ │  Dashboard  │ │   Mobile    │   │
│  │  (HTML/JS)  │ │   (HTML)    │ │   (PWA)     │   │
│  └─────────────┘ └─────────────┘ └─────────────┘   │
└─────────────────────────────────────────────────────┘
                          │ HTTP/AJAX
┌─────────────────────────────────────────────────────┐
│                 BUSINESS LOGIC TIER                 │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐   │
│  │   Auth API  │ │  Trains API │ │  Stations   │   │
│  │     PHP     │ │     PHP     │ │    API      │   │
│  └─────────────┘ └─────────────┘ └─────────────┘   │
│  ┌─────────────┐ ┌─────────────┐ ┌─────────────┐   │
│  │Locomotives  │ │ Route Val.  │ │  Enhanced   │   │
│  │    API      │ │     API     │ │ Train API   │   │
│  └─────────────┘ └─────────────┘ └─────────────┘   │
└─────────────────────────────────────────────────────┘
                          │ PDO/MySQL
┌─────────────────────────────────────────────────────┐
│                   DATA TIER                         │
│                                                     │
│              MySQL Database                         │
│          (highball_highball)                        │
│                                                     │
└─────────────────────────────────────────────────────┘
```

### Technology Stack
- **Frontend**: HTML5, CSS3, JavaScript (ES6+), PWA capabilities
- **Backend**: PHP 8.x with PDO
- **Database**: MySQL 8.0+ with utf8mb4 collation
- **Authentication**: Session-based with secure token management
- **APIs**: RESTful JSON APIs

---

## 🗄️ Database Design

### Entity Relationship Diagram (ERD)

```mermaid
erDiagram
    dcc_users ||--o{ dcc_user_sessions : has
    dcc_users {
        int id PK
        varchar username UK
        varchar email UK
        varchar password_hash
        varchar first_name
        varchar last_name
        enum role
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }
    
    dcc_user_sessions {
        varchar id PK
        int user_id FK
        varchar ip_address
        text user_agent
        timestamp created_at
        timestamp expires_at
    }
    
    dcc_stations ||--o{ dcc_station_connections : from_station
    dcc_stations ||--o{ dcc_station_connections : to_station
    dcc_stations ||--o{ dcc_station_tracks : belongs_to
    dcc_stations ||--o{ dcc_trains : departure_station
    dcc_stations ||--o{ dcc_trains : arrival_station
    
    dcc_stations {
        varchar id PK
        varchar name
        text description
        enum station_type
        decimal latitude
        decimal longitude
        int elevation_meters
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }
    
    dcc_station_connections {
        int id PK
        varchar from_station_id FK
        varchar to_station_id FK
        decimal distance_meters
        int max_speed_kmh
        enum track_type
        boolean bidirectional
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }
    
    dcc_station_tracks {
        int id PK
        varchar station_id FK
        varchar track_number
        varchar track_name
        enum track_type
        decimal length_meters
        boolean electrified
        int max_train_length
        int platform_height
        text access_rules
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }
    
    dcc_locomotives ||--o{ dcc_train_locomotives : assigned_to
    dcc_locomotives {
        int id PK
        int dcc_address UK
        varchar class
        varchar number
        varchar name
        varchar manufacturer
        varchar scale
        varchar era
        varchar country
        varchar railway_company
        enum locomotive_type
        int max_speed_kmh
        boolean sound_decoder
        int functions_count
        json function_mapping
        blob picture_blob
        varchar picture_filename
        varchar picture_mimetype
        int picture_size
        text notes
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }
    
    dcc_trains ||--o{ dcc_train_locomotives : consists_of
    dcc_trains ||--o{ dcc_train_schedules : has_schedule
    dcc_trains ||--o{ dcc_timetable_stops : has_stops
    
    dcc_trains {
        int id PK
        varchar train_number UK
        varchar train_name
        enum train_type
        text route
        varchar departure_station_id FK
        varchar arrival_station_id FK
        time departure_time
        time arrival_time
        int max_speed_kmh
        text consist_notes
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }
    
    dcc_train_locomotives {
        int id PK
        int train_id FK
        int locomotive_id FK
        int position_in_train
        boolean is_lead_locomotive
        enum facing_direction
        text notes
        timestamp created_at
        timestamp updated_at
    }
    
    dcc_train_schedules {
        int id PK
        int train_id FK
        varchar schedule_name
        date effective_date
        date expiry_date
        enum schedule_type
        enum frequency
        boolean is_active
        timestamp created_at
        timestamp updated_at
    }
    
    dcc_timetable_stops {
        int id PK
        int schedule_id FK
        varchar station_id FK
        time arrival_time
        time departure_time
        int stop_order
        int dwell_time_minutes
        boolean is_operational_stop
        text notes
        timestamp created_at
        timestamp updated_at
    }
    
    dcc_track_occupancy {
        int id PK
        varchar station_id FK
        int track_id FK
        int schedule_id FK
        int stop_id FK
        datetime occupied_from
        datetime occupied_until
        enum occupancy_type
        boolean is_confirmed
        text notes
        timestamp created_at
        timestamp updated_at
    }
```

### Database Schema Summary

#### Core Entities
1. **Users & Authentication**
   - `dcc_users`: User accounts with role-based access
   - `dcc_user_sessions`: Session management for authentication

2. **Railway Infrastructure**
   - `dcc_stations`: Station definitions with geographic data
   - `dcc_station_connections`: Track connections between stations
   - `dcc_station_tracks`: Individual tracks within stations

3. **Rolling Stock**
   - `dcc_locomotives`: Digital locomotive inventory
   - `dcc_trains`: Train service definitions
   - `dcc_train_locomotives`: Locomotive consists (many-to-many)

4. **Operations**
   - `dcc_train_schedules`: Train scheduling information
   - `dcc_timetable_stops`: Individual station stops
   - `dcc_track_occupancy`: Resource management and conflict prevention

---

## 🧩 Component Architecture

### System Components Diagram

```mermaid
graph TB
    subgraph "Frontend Layer"
        UI1[Dashboard HTML]
        UI2[Trains Management]
        UI3[Locomotives Management]
        UI4[Stations Management]
        UI5[Railway Timetable]
        UI6[Train Schedule/Buchfahrplan]
        UI7[Authentication]
    end
    
    subgraph "API Layer"
        API1[auth_api.php]
        API2[trains_api.php]
        API3[locomotives_api.php]
        API4[stations_api.php]
        API5[enhanced_train_creator_api.php]
        API6[route_validator_api.php]
        API7[collision_detection_api.php]
        API8[station_connections_api.php]
        API9[station_tracks_api.php]
    end
    
    subgraph "Business Logic Layer"
        BL1[Authentication Manager]
        BL2[Route Calculator]
        BL3[Collision Detector]
        BL4[Schedule Generator]
        BL5[Locomotive Manager]
        BL6[Station Network Manager]
    end
    
    subgraph "Data Access Layer"
        DAL1[Database Connection]
        DAL2[PDO Wrapper]
        DAL3[Transaction Manager]
    end
    
    subgraph "Database Layer"
        DB[(MySQL Database)]
    end
    
    subgraph "Shared Utilities"
        UTIL1[config.php]
        UTIL2[auth_utils.php]
        UTIL3[collision_detection.php]
    end
    
    UI1 --> API1
    UI2 --> API2
    UI2 --> API5
    UI3 --> API3
    UI4 --> API4
    UI4 --> API8
    UI4 --> API9
    UI5 --> API2
    UI6 --> API2
    UI7 --> API1
    
    API1 --> BL1
    API2 --> BL2
    API2 --> BL3
    API2 --> BL4
    API3 --> BL5
    API4 --> BL6
    API5 --> BL2
    API5 --> BL3
    API5 --> BL4
    API6 --> BL2
    API7 --> BL3
    API8 --> BL6
    API9 --> BL6
    
    BL1 --> DAL1
    BL2 --> DAL1
    BL3 --> DAL1
    BL4 --> DAL1
    BL5 --> DAL1
    BL6 --> DAL1
    
    DAL1 --> DAL2
    DAL2 --> DAL3
    DAL3 --> DB
    
    API1 --> UTIL1
    API1 --> UTIL2
    API2 --> UTIL1
    API2 --> UTIL2
    API2 --> UTIL3
    API3 --> UTIL1
    API3 --> UTIL2
    API4 --> UTIL1
    API4 --> UTIL2
    API5 --> UTIL1
    API5 --> UTIL2
    API5 --> UTIL3
```

### Component Responsibilities

#### Frontend Components
- **Dashboard**: System overview and quick access
- **Train Management**: CRUD operations for trains and consists
- **Locomotive Management**: Digital locomotive inventory
- **Station Management**: Station and track configuration
- **Timetable Views**: Classical and German-style timetables
- **Authentication**: User login and session management

#### API Components
- **Authentication API**: User management and session handling
- **Trains API**: Train operations and schedule calculations
- **Locomotives API**: Locomotive management and assignments
- **Stations API**: Station and infrastructure management
- **Enhanced Train Creator**: Advanced train creation with validation
- **Route Validator**: Path finding and route validation
- **Collision Detection**: Conflict prevention and resource management

#### Business Logic Components
- **Route Calculator**: Dijkstra's algorithm for optimal path finding
- **Collision Detector**: Time-based conflict detection
- **Schedule Generator**: Automatic timetable generation
- **Locomotive Manager**: Consist management and availability tracking
- **Station Network Manager**: Infrastructure topology management

---

## 🔌 API Design

### REST API Endpoints

#### Authentication API (`auth_api.php`)
```
POST /auth_api.php?action=register    # User registration
POST /auth_api.php?action=login       # User login
POST /auth_api.php?action=logout      # User logout
GET  /auth_api.php?action=profile     # Get user profile
GET  /auth_api.php?action=validate    # Validate session
```

#### Trains API (`trains_api.php`)
```
GET    /trains_api.php?action=list              # List trains
GET    /trains_api.php?action=get&id={id}       # Get train details
POST   /trains_api.php?action=create            # Create train
PUT    /trains_api.php?action=update&id={id}    # Update train
DELETE /trains_api.php?action=delete&id={id}    # Delete train
POST   /trains_api.php?action=calculate_schedule # Calculate route schedule
GET    /trains_api.php?action=available_locomotives # Get available locomotives
POST   /trains_api.php?action=update_consist&id={id} # Update train consist
```

#### Locomotives API (`locomotives_api.php`)
```
GET    /locomotives_api.php?action=list                    # List locomotives
GET    /locomotives_api.php?action=get&id={id}             # Get locomotive details
GET    /locomotives_api.php?action=by_address&address={n}  # Get by DCC address
POST   /locomotives_api.php?action=create                  # Create locomotive
PUT    /locomotives_api.php?action=update&id={id}          # Update locomotive
DELETE /locomotives_api.php?action=delete&id={id}          # Delete locomotive
GET    /locomotives_api.php?action=picture&id={id}         # Get locomotive picture
POST   /locomotives_api.php?action=upload_picture&id={id}  # Upload picture
DELETE /locomotives_api.php?action=delete_picture&id={id}  # Delete picture
```

#### Enhanced Train Creator API (`enhanced_train_creator_api.php`)
```
POST /enhanced_train_creator_api.php?action=validate_only     # Validate route only
POST /enhanced_train_creator_api.php?action=create_validated  # Create with validation
```

#### Route Validator API (`route_validator_api.php`)
```
GET /route_validator_api.php?action=validate_route   # Validate route between stations
GET /route_validator_api.php?action=find_route       # Find optimal route
```

### API Response Format
All APIs follow a consistent JSON response format:

```json
{
  "status": "success|error",
  "message": "Human readable message",
  "data": { ... },           // For successful responses
  "error": "Error message",  // For error responses
  "details": [ ... ],        // Additional error details
  "pagination": {            // For paginated responses
    "page": 1,
    "limit": 50,
    "total": 123,
    "pages": 3
  }
}
```

---

## 🎨 User Interface Architecture

### UI Component Hierarchy

```mermaid
graph TD
    subgraph "Main Navigation"
        NAV1[Dashboard]
        NAV2[Trains]
        NAV3[Locomotives]
        NAV4[Stations]
        NAV5[Timetables]
    end
    
    subgraph "Train Management"
        TM1[Train List View]
        TM2[Train Detail View]
        TM3[Train Creator]
        TM4[Consist Manager]
        TM5[Schedule Viewer]
        TM6[Buchfahrplan Viewer]
    end
    
    subgraph "Locomotive Management"
        LM1[Locomotive List]
        LM2[Locomotive Detail]
        LM3[Picture Manager]
        LM4[Function Mapping]
    end
    
    subgraph "Station Management"
        SM1[Station List]
        SM2[Station Detail]
        SM3[Track Configuration]
        SM4[Connection Manager]
        SM5[Network Visualizer]
    end
    
    subgraph "Timetable Views"
        TV1[Railway Timetable]
        TV2[Train Schedule]
        TV3[Schedule Generator]
    end
    
    subgraph "Authentication"
        AUTH1[Login Form]
        AUTH2[Registration Form]
        AUTH3[User Profile]
    end
    
    NAV1 --> TM1
    NAV1 --> LM1
    NAV1 --> SM1
    NAV2 --> TM1
    TM1 --> TM2
    TM1 --> TM3
    TM2 --> TM4
    TM2 --> TM5
    TM2 --> TM6
    NAV3 --> LM1
    LM1 --> LM2
    LM2 --> LM3
    LM2 --> LM4
    NAV4 --> SM1
    SM1 --> SM2
    SM2 --> SM3
    SM1 --> SM4
    SM4 --> SM5
    NAV5 --> TV1
    NAV5 --> TV2
    TV2 --> TV3
```

### UI Design Patterns

#### 1. Responsive Grid Layout
- Mobile-first design approach
- CSS Grid and Flexbox for layouts
- Breakpoints: Mobile (768px), Tablet (1024px), Desktop (1200px+)

#### 2. Component-Based Architecture
- Reusable UI components
- Consistent styling with CSS custom properties
- Progressive enhancement

#### 3. State Management
- Client-side state management with JavaScript
- Session storage for user preferences
- Real-time updates via AJAX polling

---

## 🔒 Security Model

### Authentication & Authorization

```mermaid
sequenceDiagram
    participant User
    participant Frontend
    participant AuthAPI
    participant Database
    
    User->>Frontend: Login Request
    Frontend->>AuthAPI: POST /auth_api.php?action=login
    AuthAPI->>Database: Validate Credentials
    Database-->>AuthAPI: User Data
    AuthAPI->>Database: Create Session
    Database-->>AuthAPI: Session Token
    AuthAPI-->>Frontend: Session Token + User Data
    Frontend-->>User: Login Success
    
    Note over Frontend,AuthAPI: Subsequent Requests
    Frontend->>AuthAPI: API Request + Session Token
    AuthAPI->>Database: Validate Session
    Database-->>AuthAPI: Session Valid
    AuthAPI-->>Frontend: API Response
```

### Security Features

#### 1. Role-Based Access Control (RBAC)
- **Admin**: Full system access, user management
- **Operator**: Train and locomotive management
- **Viewer**: Read-only access to all data

#### 2. Session Management
- Secure session tokens (128-character random)
- 24-hour session expiration
- IP and User-Agent validation
- Automatic cleanup of expired sessions

#### 3. Input Validation
- SQL injection prevention with PDO prepared statements
- XSS protection with input sanitization
- CSRF protection with token validation
- File upload validation for locomotive pictures

#### 4. Database Security
- UTF-8 character encoding
- Parameterized queries
- Foreign key constraints
- Data integrity validation

---

## 📊 Data Flow Diagrams

### Train Creation Flow

```mermaid
flowchart TD
    A[User Initiates Train Creation] --> B[Enhanced Train Creator Form]
    B --> C[Input Validation]
    C --> D{Valid Input?}
    D -->|No| E[Display Validation Errors]
    E --> B
    D -->|Yes| F[Route Validation]
    F --> G{Route Exists?}
    G -->|No| H[Display Route Error]
    H --> B
    G -->|Yes| I[Locomotive Availability Check]
    I --> J{Locomotive Available?}
    J -->|No| K[Display Availability Error]
    K --> B
    J -->|Yes| L[Collision Detection]
    L --> M{Conflicts Found?}
    M -->|Yes| N[Display Conflict Details]
    N --> B
    M -->|No| O[Calculate Station Times]
    O --> P[Create Train Record]
    P --> Q[Assign Locomotive]
    Q --> R[Create Schedule Records]
    R --> S[Create Track Occupancy]
    S --> T[Update Locomotive Availability]
    T --> U[Return Success Response]
    U --> V[Display Success Message]
```

### Schedule Calculation Flow

```mermaid
flowchart TD
    A[Schedule Calculation Request] --> B[Load Route Data]
    B --> C[Apply Dijkstra Algorithm]
    C --> D[Calculate Segment Distances]
    D --> E[Apply Speed Limits]
    E --> F[Calculate Travel Times]
    F --> G[Add Dwell Times]
    G --> H[Generate Station Times]
    H --> I[Validate Time Constraints]
    I --> J{Times Valid?}
    J -->|No| K[Adjust Parameters]
    K --> F
    J -->|Yes| L[Return Schedule Data]
```

### Collision Detection Flow

```mermaid
flowchart TD
    A[New Train Schedule] --> B[Load Existing Trains]
    B --> C[Calculate Route for New Train]
    C --> D[Calculate Route for Each Existing Train]
    D --> E[Compare Route Segments]
    E --> F{Segments Overlap?}
    F -->|No| G[Check Next Train]
    G --> H{More Trains?}
    H -->|Yes| D
    H -->|No| I[No Conflicts Found]
    F -->|Yes| J[Compare Time Windows]
    J --> K{Times Overlap?}
    K -->|No| G
    K -->|Yes| L[Record Conflict]
    L --> G
    I --> M[Allow Train Creation]
    L --> N[Return Conflict Details]
```

---

## 🚀 Deployment Architecture

### Production Environment

```mermaid
graph TB
    subgraph "Load Balancer"
        LB[Nginx Load Balancer]
    end
    
    subgraph "Web Servers"
        WS1[Web Server 1<br/>PHP-FPM + Nginx]
        WS2[Web Server 2<br/>PHP-FPM + Nginx]
    end
    
    subgraph "Database Cluster"
        DB1[(Primary MySQL)]
        DB2[(Replica MySQL)]
    end
    
    subgraph "Storage"
        FS[File Storage<br/>Locomotive Pictures]
        LOGS[Log Storage]
    end
    
    subgraph "Monitoring"
        MON[Application Monitoring]
        METRICS[Performance Metrics]
    end
    
    Internet --> LB
    LB --> WS1
    LB --> WS2
    WS1 --> DB1
    WS2 --> DB1
    DB1 --> DB2
    WS1 --> FS
    WS2 --> FS
    WS1 --> LOGS
    WS2 --> LOGS
    WS1 --> MON
    WS2 --> MON
    DB1 --> METRICS
    DB2 --> METRICS
```

### Deployment Specifications

#### Web Server Configuration
- **Server**: Nginx 1.20+ with PHP-FPM 8.1+
- **SSL**: TLS 1.3 with Let's Encrypt certificates
- **Caching**: Browser caching for static assets
- **Compression**: Gzip compression enabled

#### Database Configuration
- **Engine**: MySQL 8.0+ with InnoDB storage engine
- **Charset**: utf8mb4 with utf8mb4_general_ci collation
- **Backup**: Daily automated backups with point-in-time recovery
- **Replication**: Master-slave replication for read scaling

#### Security Configuration
- **Firewall**: UFW with restricted port access
- **SSL/TLS**: A+ grade SSL configuration
- **Headers**: Security headers (HSTS, CSP, X-Frame-Options)
- **Updates**: Automatic security updates enabled

---

## 📐 UML Diagrams

### Class Diagram - Core System Classes

```mermaid
classDiagram
    class Database {
        -string host
        -string db_name
        -string username
        -string password
        -PDO conn
        +getConnection() PDO
        +closeConnection() void
    }
    
    class AuthenticationManager {
        -Database database
        +validateUser(username, password) User
        +createSession(userId) string
        +validateSession(sessionId) Session
        +getCurrentUser() User
        +logout(sessionId) bool
    }
    
    class User {
        +int id
        +string username
        +string email
        +string role
        +bool isActive
        +DateTime createdAt
        +validate() bool
        +hasPermission(action) bool
    }
    
    class Session {
        +string id
        +int userId
        +string ipAddress
        +string userAgent
        +DateTime expiresAt
        +isValid() bool
        +refresh() void
    }
    
    class Train {
        +int id
        +string trainNumber
        +string trainName
        +string trainType
        +string departureStationId
        +string arrivalStationId
        +Time departureTime
        +Time arrivalTime
        +int maxSpeedKmh
        +bool isActive
        +validate() bool
        +calculateSchedule() Schedule
    }
    
    class Locomotive {
        +int id
        +int dccAddress
        +string class
        +string number
        +string name
        +string manufacturer
        +string locomotiveType
        +int maxSpeedKmh
        +bool soundDecoder
        +json functionMapping
        +bool isActive
        +isAvailable(date, time) bool
        +assign(trainId) bool
    }
    
    class Station {
        +string id
        +string name
        +string description
        +string stationType
        +decimal latitude
        +decimal longitude
        +bool isActive
        +getConnections() Connection[]
        +getTracks() Track[]
    }
    
    class StationConnection {
        +int id
        +string fromStationId
        +string toStationId
        +decimal distanceMeters
        +int maxSpeedKmh
        +string trackType
        +bool bidirectional
        +bool isActive
        +calculateTravelTime(speed) int
    }
    
    class RouteCalculator {
        -Database database
        +findRoute(fromStation, toStation) Route
        +validateRoute(fromStation, toStation) bool
        +calculateDistance(route) decimal
        +calculateTravelTime(route, speed) int
    }
    
    class CollisionDetector {
        -Database database
        +checkConflicts(train) Conflict[]
        +validateTimeSlot(station, time) bool
        +findAvailableSlots(route, duration) TimeSlot[]
    }
    
    class TrainManager {
        -Database database
        -RouteCalculator routeCalculator
        -CollisionDetector collisionDetector
        +createTrain(trainData) Train
        +updateTrain(id, trainData) bool
        +deleteTrain(id) bool
        +getTrainSchedule(id) Schedule
    }
    
    class LocomotiveManager {
        -Database database
        +createLocomotive(locoData) Locomotive
        +updateLocomotive(id, locoData) bool
        +assignToTrain(locoId, trainId) bool
        +getAvailableLocomotives(date, time) Locomotive[]
    }
    
    Database --> AuthenticationManager
    Database --> TrainManager
    Database --> LocomotiveManager
    Database --> RouteCalculator
    Database --> CollisionDetector
    
    AuthenticationManager --> User
    AuthenticationManager --> Session
    
    TrainManager --> Train
    TrainManager --> RouteCalculator
    TrainManager --> CollisionDetector
    
    LocomotiveManager --> Locomotive
    
    RouteCalculator --> Station
    RouteCalculator --> StationConnection
    
    Train --> Station : departureStation
    Train --> Station : arrivalStation
    Train --> Locomotive : consists of
```

### Sequence Diagram - Enhanced Train Creation

```mermaid
sequenceDiagram
    participant User
    participant UI as Train Creator UI
    participant API as Enhanced Train API
    participant RouteVal as Route Validator
    participant CollDet as Collision Detector
    participant TrainMgr as Train Manager
    participant LocoMgr as Locomotive Manager
    participant DB as Database
    
    User->>UI: Fill train creation form
    User->>UI: Click "Create Train"
    UI->>API: POST /enhanced_train_creator_api.php
    
    Note over API: Input Validation
    API->>API: Validate train data
    alt Invalid Data
        API-->>UI: Validation errors
        UI-->>User: Display errors
    else Valid Data
        API->>RouteVal: Validate route
        RouteVal->>DB: Query station connections
        DB-->>RouteVal: Connection data
        RouteVal->>RouteVal: Apply Dijkstra algorithm
        
        alt Route Not Found
            RouteVal-->>API: Route invalid
            API-->>UI: Route error
            UI-->>User: Display route error
        else Route Valid
            API->>LocoMgr: Check locomotive availability
            LocoMgr->>DB: Query locomotive assignments
            DB-->>LocoMgr: Assignment data
            
            alt Locomotive Unavailable
                LocoMgr-->>API: Locomotive unavailable
                API-->>UI: Availability error
                UI-->>User: Display availability error
            else Locomotive Available
                API->>CollDet: Check for conflicts
                CollDet->>DB: Query existing trains
                DB-->>CollDet: Train data
                CollDet->>CollDet: Calculate route overlaps
                CollDet->>CollDet: Check time conflicts
                
                alt Conflicts Found
                    CollDet-->>API: Conflict details
                    API-->>UI: Conflict information
                    UI-->>User: Display conflicts
                else No Conflicts
                    API->>TrainMgr: Create train
                    TrainMgr->>DB: Insert train record
                    DB-->>TrainMgr: Train ID
                    TrainMgr->>LocoMgr: Assign locomotive
                    LocoMgr->>DB: Update locomotive assignment
                    TrainMgr->>DB: Create schedule records
                    TrainMgr->>DB: Create track occupancy
                    API-->>UI: Success response
                    UI-->>User: Display success
                end
            end
        end
    end
```

### State Diagram - Train Lifecycle

```mermaid
stateDiagram-v2
    [*] --> Planning
    
    Planning --> Validating : Submit for validation
    Validating --> Planning : Validation failed
    Validating --> Scheduled : Validation passed
    
    Scheduled --> Active : Departure time reached
    Scheduled --> Cancelled : Manual cancellation
    Scheduled --> Planning : Modification requested
    
    Active --> EnRoute : Train departed
    EnRoute --> AtStation : Arrived at station
    AtStation --> EnRoute : Departed from station
    EnRoute --> Completed : Arrived at destination
    
    Active --> Delayed : Schedule disruption
    Delayed --> EnRoute : Delay resolved
    Delayed --> Cancelled : Cannot proceed
    
    Completed --> [*]
    Cancelled --> [*]
    
    note right of Validating
        Route validation
        Conflict detection
        Resource availability
    end note
    
    note right of Active
        Real-time tracking
        Position updates
        Schedule monitoring
    end note
```

### Component Diagram - API Layer

```mermaid
graph TB
    subgraph "API Layer Components"
        subgraph "Authentication"
            AUTH_API[auth_api.php]
            AUTH_UTILS[auth_utils.php]
        end
        
        subgraph "Core APIs"
            TRAINS_API[trains_api.php]
            LOCOS_API[locomotives_api.php]
            STATIONS_API[stations_api.php]
            CONNECTIONS_API[station_connections_api.php]
            TRACKS_API[station_tracks_api.php]
        end
        
        subgraph "Enhanced Features"
            ENHANCED_API[enhanced_train_creator_api.php]
            ROUTE_API[route_validator_api.php]
            COLLISION_API[collision_detection_api.php]
            NETWORK_API[station_network_api.php]
        end
        
        subgraph "Utilities"
            CONFIG[config.php]
            COLLISION_UTIL[collision_detection.php]
        end
    end
    
    subgraph "External Dependencies"
        PDO[PDO Database Layer]
        MYSQL[(MySQL Database)]
        SESSION[PHP Session Management]
    end
    
    AUTH_API --> AUTH_UTILS
    AUTH_API --> CONFIG
    AUTH_API --> SESSION
    
    TRAINS_API --> AUTH_UTILS
    TRAINS_API --> CONFIG
    TRAINS_API --> COLLISION_UTIL
    
    LOCOS_API --> AUTH_UTILS
    LOCOS_API --> CONFIG
    
    STATIONS_API --> AUTH_UTILS
    STATIONS_API --> CONFIG
    
    ENHANCED_API --> AUTH_UTILS
    ENHANCED_API --> CONFIG
    ENHANCED_API --> COLLISION_UTIL
    
    ROUTE_API --> CONFIG
    COLLISION_API --> CONFIG
    COLLISION_API --> COLLISION_UTIL
    
    CONFIG --> PDO
    PDO --> MYSQL
```

---

## 📝 Summary

This design documentation provides a comprehensive overview of the DCC Railway Control System architecture. The system implements modern software engineering principles including:

- **Separation of Concerns**: Clear separation between presentation, business logic, and data layers
- **RESTful API Design**: Consistent, stateless API interfaces
- **Security Best Practices**: Role-based access control and secure session management
- **Scalable Architecture**: Component-based design supporting horizontal scaling
- **Data Integrity**: Comprehensive validation and conflict detection
- **User Experience**: Responsive, accessible web interfaces

The system successfully balances the complexity of railway operations management with modern web application development practices, providing a robust platform for digital model railway control and management.

---

*Document Version: 1.0*  
*Last Updated: August 5, 2025*  
*Author: DCC Railway Control System Development Team*
