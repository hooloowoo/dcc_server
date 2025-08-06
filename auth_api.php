<?php
/**
 * DCC Authentication API
 * Handles user registration, login, logout, and session management
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'config.php';

session_start();

// Database connection
try {
    $database = new Database();
    $conn = $database->getConnection();
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => 'Database connection failed'
    ]);
    exit();
}

/**
 * Validate user registration data
 */
function validateRegistrationData($data) {
    $errors = [];
    
    if (empty($data['username'])) {
        $errors[] = 'Username is required';
    } elseif (strlen($data['username']) < 3) {
        $errors[] = 'Username must be at least 3 characters';
    } elseif (strlen($data['username']) > 50) {
        $errors[] = 'Username must not exceed 50 characters';
    } elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $data['username'])) {
        $errors[] = 'Username can only contain letters, numbers, underscores, and hyphens';
    }
    
    if (empty($data['email'])) {
        $errors[] = 'Email is required';
    } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email address is required';
    } elseif (strlen($data['email']) > 100) {
        $errors[] = 'Email must not exceed 100 characters';
    }
    
    if (empty($data['password'])) {
        $errors[] = 'Password is required';
    } elseif (strlen($data['password']) < 6) {
        $errors[] = 'Password must be at least 6 characters';
    }
    
    if (isset($data['first_name']) && strlen($data['first_name']) > 50) {
        $errors[] = 'First name must not exceed 50 characters';
    }
    
    if (isset($data['last_name']) && strlen($data['last_name']) > 50) {
        $errors[] = 'Last name must not exceed 50 characters';
    }
    
    return $errors;
}

/**
 * Validate login data
 */
function validateLoginData($data) {
    $errors = [];
    
    if (empty($data['username'])) {
        $errors[] = 'Username is required';
    }
    
    if (empty($data['password'])) {
        $errors[] = 'Password is required';
    }
    
    return $errors;
}

/**
 * Generate secure session token
 */
function generateSessionToken() {
    return bin2hex(random_bytes(64));
}

/**
 * Create user session
 */
function createUserSession($conn, $userId) {
    $sessionId = generateSessionToken();
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $conn->prepare("INSERT INTO dcc_user_sessions (id, user_id, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$sessionId, $userId, $ipAddress, $userAgent, $expiresAt]);
    
    $_SESSION['session_id'] = $sessionId;
    $_SESSION['user_id'] = $userId;
    
    return $sessionId;
}

/**
 * Validate user session
 */
function validateUserSession($conn, $sessionId = null) {
    if (!$sessionId) {
        $sessionId = $_SESSION['session_id'] ?? null;
    }
    
    if (!$sessionId) {
        return false;
    }
    
    $stmt = $conn->prepare("
        SELECT s.*, u.username, u.email, u.role, u.first_name, u.last_name, u.is_active
        FROM dcc_user_sessions s
        JOIN dcc_users u ON s.user_id = u.id
        WHERE s.id = ? AND s.expires_at > NOW() AND u.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($session) {
        // Update last login
        $stmt = $conn->prepare("UPDATE dcc_users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$session['user_id']]);
        
        return $session;
    }
    
    return false;
}

/**
 * Clean expired sessions
 */
function cleanExpiredSessions($conn) {
    $stmt = $conn->prepare("DELETE FROM dcc_user_sessions WHERE expires_at < NOW()");
    $stmt->execute();
}

// Clean expired sessions periodically
cleanExpiredSessions($conn);

$method = $_SERVER['REQUEST_METHOD'];
$endpoint = $_GET['action'] ?? '';

switch ($method) {
    case 'POST':
        switch ($endpoint) {
            case 'register':
                $input = json_decode(file_get_contents('php://input'), true);
                
                if (!$input) {
                    http_response_code(400);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Invalid JSON data'
                    ]);
                    break;
                }
                
                $errors = validateRegistrationData($input);
                if (!empty($errors)) {
                    http_response_code(400);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Validation failed',
                        'details' => $errors
                    ]);
                    break;
                }
                
                // Check if username or email already exists
                $stmt = $conn->prepare("SELECT COUNT(*) FROM dcc_users WHERE username = ? OR email = ?");
                $stmt->execute([$input['username'], $input['email']]);
                if ($stmt->fetchColumn() > 0) {
                    http_response_code(409);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Username or email already exists'
                    ]);
                    break;
                }
                
                // Hash password
                $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);
                
                // Insert new user
                $stmt = $conn->prepare("
                    INSERT INTO dcc_users (username, email, password_hash, role, first_name, last_name) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                $role = $input['role'] ?? 'viewer'; // Default role
                $firstName = $input['first_name'] ?? null;
                $lastName = $input['last_name'] ?? null;
                
                if ($stmt->execute([$input['username'], $input['email'], $passwordHash, $role, $firstName, $lastName])) {
                    $userId = $conn->lastInsertId();
                    $sessionId = createUserSession($conn, $userId);
                    
                    // Fetch complete user data
                    $stmt = $conn->prepare("SELECT id, username, email, role, first_name, last_name, created_at FROM dcc_users WHERE id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    http_response_code(201);
                    echo json_encode([
                        'status' => 'success',
                        'message' => 'User registered successfully',
                        'data' => [
                            'user' => $user,
                            'session_id' => $sessionId
                        ]
                    ]);
                } else {
                    http_response_code(500);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Failed to create user'
                    ]);
                }
                break;
                
            case 'login':
                $input = json_decode(file_get_contents('php://input'), true);
                
                if (!$input) {
                    http_response_code(400);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Invalid JSON data'
                    ]);
                    break;
                }
                
                $errors = validateLoginData($input);
                if (!empty($errors)) {
                    http_response_code(400);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Validation failed',
                        'details' => $errors
                    ]);
                    break;
                }
                
                // Find user
                $stmt = $conn->prepare("SELECT * FROM dcc_users WHERE username = ? AND is_active = 1");
                $stmt->execute([$input['username']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$user || !password_verify($input['password'], $user['password_hash'])) {
                    http_response_code(401);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Invalid username or password'
                    ]);
                    break;
                }
                
                $sessionId = createUserSession($conn, $user['id']);
                
                // Remove sensitive data
                unset($user['password_hash']);
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Login successful',
                    'data' => [
                        'user' => $user,
                        'session_id' => $sessionId
                    ]
                ]);
                break;
                
            case 'logout':
                $sessionId = $_SESSION['session_id'] ?? null;
                
                if ($sessionId) {
                    $stmt = $conn->prepare("DELETE FROM dcc_user_sessions WHERE id = ?");
                    $stmt->execute([$sessionId]);
                }
                
                session_destroy();
                
                echo json_encode([
                    'status' => 'success',
                    'message' => 'Logout successful'
                ]);
                break;
                
            default:
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Endpoint not found'
                ]);
                break;
        }
        break;
        
    case 'GET':
        switch ($endpoint) {
            case 'profile':
                $session = validateUserSession($conn);
                
                if (!$session) {
                    http_response_code(401);
                    echo json_encode([
                        'status' => 'error',
                        'error' => 'Invalid or expired session'
                    ]);
                    break;
                }
                
                echo json_encode([
                    'status' => 'success',
                    'data' => [
                        'id' => $session['user_id'],
                        'username' => $session['username'],
                        'email' => $session['email'],
                        'role' => $session['role'],
                        'first_name' => $session['first_name'],
                        'last_name' => $session['last_name']
                    ]
                ]);
                break;
                
            case 'validate':
                $session = validateUserSession($conn);
                
                if ($session) {
                    echo json_encode([
                        'status' => 'success',
                        'data' => [
                            'valid' => true,
                            'user' => [
                                'id' => $session['user_id'],
                                'username' => $session['username'],
                                'role' => $session['role']
                            ]
                        ]
                    ]);
                } else {
                    echo json_encode([
                        'status' => 'success',
                        'data' => ['valid' => false]
                    ]);
                }
                break;
                
            default:
                http_response_code(404);
                echo json_encode([
                    'status' => 'error',
                    'error' => 'Endpoint not found'
                ]);
                break;
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            'status' => 'error',
            'error' => 'Method not allowed'
        ]);
        break;
}
?>
