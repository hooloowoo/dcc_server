<?php
/**
 * DCC Authentication Utilities
 * Common authentication functions for use across the application
 */

/**
 * Check if user is authenticated and has required role
 */
function requireAuth($conn, $requiredRole = null) {
    session_start();
    
    $sessionId = $_SESSION['session_id'] ?? null;
    
    if (!$sessionId) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'error' => 'Authentication required'
        ]);
        exit();
    }
    
    $stmt = $conn->prepare("
        SELECT s.*, u.username, u.email, u.role, u.first_name, u.last_name, u.is_active
        FROM dcc_user_sessions s
        JOIN dcc_users u ON s.user_id = u.id
        WHERE s.id = ? AND s.expires_at > NOW() AND u.is_active = 1
    ");
    $stmt->execute([$sessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'error' => 'Invalid or expired session'
        ]);
        exit();
    }
    
    // Check role if specified
    if ($requiredRole) {
        $roleHierarchy = ['viewer' => 1, 'operator' => 2, 'admin' => 3];
        $userLevel = $roleHierarchy[$session['role']] ?? 0;
        $requiredLevel = $roleHierarchy[$requiredRole] ?? 999;
        
        if ($userLevel < $requiredLevel) {
            http_response_code(403);
            echo json_encode([
                'status' => 'error',
                'error' => 'Insufficient permissions'
            ]);
            exit();
        }
    }
    
    return $session;
}

/**
 * Get current user if authenticated (non-blocking)
 */
function getCurrentUser($conn) {
    // First try session-based authentication
    session_start();
    
    $sessionId = $_SESSION['session_id'] ?? null;
    
    if ($sessionId) {
        $stmt = $conn->prepare("
            SELECT s.*, u.username, u.email, u.role, u.first_name, u.last_name, u.is_active
            FROM dcc_user_sessions s
            JOIN dcc_users u ON s.user_id = u.id
            WHERE s.id = ? AND s.expires_at > NOW() AND u.is_active = 1
        ");
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($session) {
            return $session;
        }
    }
    
    // Try Basic Auth if session auth failed
    if (isset($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
        $username = $_SERVER['PHP_AUTH_USER'];
        $password = $_SERVER['PHP_AUTH_PW'];
        
        $stmt = $conn->prepare("
            SELECT id, username, email, role, first_name, last_name, password_hash, is_active
            FROM dcc_users 
            WHERE username = ? AND is_active = 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password_hash'])) {
            return $user;
        }
    }
    
    return null;
}

/**
 * Check if user has specific role or higher
 */
function hasRole($userRole, $requiredRole) {
    $roleHierarchy = ['viewer' => 1, 'operator' => 2, 'admin' => 3];
    $userLevel = $roleHierarchy[$userRole] ?? 0;
    $requiredLevel = $roleHierarchy[$requiredRole] ?? 999;
    
    return $userLevel >= $requiredLevel;
}
?>
