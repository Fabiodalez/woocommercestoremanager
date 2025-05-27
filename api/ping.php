<?php
// api/ping.php - Session Ping API
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../database.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Initialize response
$response = [
    'success' => false,
    'authenticated' => false,
    'message' => '',
    'server_time' => date('Y-m-d H:i:s'),
    'session_expires' => null
];

try {
    // Initialize system
    Config::init();
    
    // Check if user is authenticated
    $current_user = Config::getCurrentUser();
    
    if (!$current_user) {
        $response['success'] = false;
        $response['authenticated'] = false;
        $response['error'] = 'not_authenticated';
        $response['message'] = 'Session expired or user not logged in';
        echo json_encode($response);
        exit;
    }
    
    // User is authenticated
    $response['success'] = true;
    $response['authenticated'] = true;
    $response['message'] = 'Session active';
    $response['user_id'] = $current_user['id'];
    $response['username'] = $current_user['username'];
    
    // Calculate session expiration if available
    if (isset($_SESSION['login_time'])) {
        $session_timeout = Config::getSystemSetting('session_timeout', 1800); // 30 minutes default
        $session_expires = $_SESSION['login_time'] + $session_timeout;
        $response['session_expires'] = date('Y-m-d H:i:s', $session_expires);
        $response['seconds_until_expiry'] = max(0, $session_expires - time());
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    
    // Optional: Update database with last activity
    try {
        $db = Database::getInstance();
        $db->execute('UPDATE users SET updated_at = ? WHERE id = ?', [
            date('Y-m-d H:i:s'),
            $current_user['id']
        ]);
        
        // Update session record if it exists
        if (isset($_SESSION['session_token'])) {
            $db->execute('UPDATE user_sessions SET last_activity = ? WHERE session_token = ?', [
                date('Y-m-d H:i:s'),
                $_SESSION['session_token']
            ]);
        }
        
    } catch (Exception $db_error) {
        // Database update failed, but session is still valid
        // Log error but don't fail the ping
        error_log("Database update failed in ping API: " . $db_error->getMessage());
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['authenticated'] = false;
    $response['error'] = 'system_error';
    $response['message'] = 'System error occurred during ping';
    
    // Log the error
    error_log("System error in ping API: " . $e->getMessage());
}

// Return JSON response
echo json_encode($response);
exit;
?>