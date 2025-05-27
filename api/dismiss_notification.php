<?php
// api/dismiss_notification.php - Dismiss Notification API
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

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
    'message' => '',
    'notification_id' => null,
    'error' => null
];

try {
    // Initialize system
    Config::init();
    
    // Check if user is authenticated
    $current_user = Config::getCurrentUser();
    if (!$current_user) {
        $response['error'] = 'not_authenticated';
        $response['message'] = 'User not authenticated';
        echo json_encode($response);
        exit;
    }
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['notification_id'])) {
        $response['error'] = 'invalid_input';
        $response['message'] = 'Notification ID is required';
        echo json_encode($response);
        exit;
    }
    
    $notification_id = intval($input['notification_id']);
    
    if ($notification_id <= 0) {
        $response['error'] = 'invalid_notification_id';
        $response['message'] = 'Invalid notification ID provided';
        echo json_encode($response);
        exit;
    }
    
    $db = Database::getInstance();
    
    // Check if notification exists and belongs to current user
    $notification = $db->fetch('
        SELECT id, title, user_id, is_dismissed 
        FROM user_notifications 
        WHERE id = ? AND user_id = ?
    ', [$notification_id, $current_user['id']]);
    
    if (!$notification) {
        $response['error'] = 'notification_not_found';
        $response['message'] = 'Notification not found or access denied';
        echo json_encode($response);
        exit;
    }
    
    // Check if already dismissed
    if ($notification['is_dismissed']) {
        $response['success'] = true;
        $response['message'] = 'Notification was already dismissed';
        $response['notification_id'] = $notification_id;
        echo json_encode($response);
        exit;
    }
    
    // Dismiss the notification
    try {
        $db->execute('
            UPDATE user_notifications 
            SET is_dismissed = 1, read_at = ?
            WHERE id = ? AND user_id = ?
        ', [
            date('Y-m-d H:i:s'),
            $notification_id,
            $current_user['id']
        ]);
        
        $response['success'] = true;
        $response['message'] = 'Notification dismissed successfully';
        $response['notification_id'] = $notification_id;
        
        // Optional: Log the activity
        $db->execute('
            INSERT INTO user_activity (user_id, action, description, category, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ', [
            $current_user['id'],
            'notification_dismissed',
            'Dismissed notification: ' . ($notification['title'] ?: 'Untitled'),
            'notification',
            json_encode(['notification_id' => $notification_id, 'title' => $notification['title']]),
            date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $db_error) {
        $response['error'] = 'database_error';
        $response['message'] = 'Failed to dismiss notification';
        error_log("Database error in dismiss_notification API: " . $db_error->getMessage());
        echo json_encode($response);
        exit;
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = 'system_error';
    $response['message'] = 'System error occurred';
    
    // Log the error
    error_log("System error in dismiss_notification API: " . $e->getMessage());
}

// Return JSON response
echo json_encode($response);
exit;
?>