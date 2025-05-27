<?php
// api/switch_store.php - Store Switch API
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
    'store_id' => null,
    'store_name' => '',
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
    
    if (!$input || !isset($input['store_id'])) {
        $response['error'] = 'invalid_input';
        $response['message'] = 'Store ID is required';
        echo json_encode($response);
        exit;
    }
    
    $store_id = intval($input['store_id']);
    
    if ($store_id <= 0) {
        $response['error'] = 'invalid_store_id';
        $response['message'] = 'Invalid store ID provided';
        echo json_encode($response);
        exit;
    }
    
    $db = Database::getInstance();
    
    // Check if user has access to this store
    $store = $db->fetch('
        SELECT uc.*, sc.role
        FROM user_configs uc
        LEFT JOIN store_collaborators sc ON uc.id = sc.store_config_id AND sc.user_id = ?
        WHERE uc.id = ? AND (uc.user_id = ? OR (sc.user_id = ? AND sc.status = "accepted"))
    ', [$current_user['id'], $store_id, $current_user['id'], $current_user['id']]);
    
    if (!$store) {
        $response['error'] = 'store_not_found';
        $response['message'] = 'Store not found or access denied';
        echo json_encode($response);
        exit;
    }
    
    // Update user preference for current store
    try {
        $db->execute('
            INSERT OR REPLACE INTO user_preferences (user_id, preference_key, preference_value, preference_type, updated_at)
            VALUES (?, ?, ?, ?, ?)
        ', [
            $current_user['id'],
            'current_store_id',
            $store_id,
            'integer',
            date('Y-m-d H:i:s')
        ]);
        
        $response['success'] = true;
        $response['message'] = 'Store switched successfully';
        $response['store_id'] = $store_id;
        $response['store_name'] = $store['store_name'] ?: 'Unnamed Store';
        
        // Log the activity
        $db->execute('
            INSERT INTO user_activity (user_id, action, description, category, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ', [
            $current_user['id'],
            'store_switched',
            'Switched to store: ' . ($store['store_name'] ?: 'Unnamed Store'),
            'store',
            json_encode(['store_id' => $store_id, 'store_name' => $store['store_name']]),
            date('Y-m-d H:i:s')
        ]);
        
    } catch (Exception $db_error) {
        $response['error'] = 'database_error';
        $response['message'] = 'Failed to update store preference';
        error_log("Database error in switch_store API: " . $db_error->getMessage());
        echo json_encode($response);
        exit;
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = 'system_error';
    $response['message'] = 'System error occurred';
    
    // Log the error
    error_log("System error in switch_store API: " . $e->getMessage());
}

// Return JSON response
echo json_encode($response);
exit;
?>