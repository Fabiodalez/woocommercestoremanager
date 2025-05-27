<?php
// api/check_connection.php - Connection Status API
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
    'connected' => false,
    'message' => '',
    'store_name' => '',
    'last_test' => null,
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
    
    // Get current store configuration
    $current_store = Config::getCurrentStore();
    
    if (!$current_store) {
        $response['success'] = true;
        $response['connected'] = false;
        $response['message'] = 'No store configured';
        echo json_encode($response);
        exit;
    }
    
    // Store basic info
    $response['store_name'] = $current_store['store_name'] ?: 'Unnamed Store';
    $response['last_test'] = $current_store['last_test'];
    
    // Check if store has required credentials
    if (empty($current_store['store_url']) || 
        empty($current_store['consumer_key']) || 
        empty($current_store['consumer_secret'])) {
        
        $response['success'] = true;
        $response['connected'] = false;
        $response['message'] = 'Store credentials incomplete';
        echo json_encode($response);
        exit;
    }
    
    // Test WooCommerce connection
    if (class_exists('WooCommerceAPI')) {
        try {
            $api = new WooCommerceAPI($current_user['id'], $current_store['id']);
            
            if ($api->isConfigured()) {
                // Quick connection test - try to get system status
                $test_result = $api->testConnection();
                
                if ($test_result['success']) {
                    $response['success'] = true;
                    $response['connected'] = true;
                    $response['message'] = 'Store connected successfully';
                    
                    // Update last test time in database
                    $db = Database::getInstance();
                    $db->execute('UPDATE user_configs SET last_test = ?, connected = 1 WHERE id = ?', [
                        date('Y-m-d H:i:s'), 
                        $current_store['id']
                    ]);
                    
                } else {
                    $response['success'] = true;
                    $response['connected'] = false;
                    $response['message'] = $test_result['error'] ?: 'Connection test failed';
                    
                    // Update database with failed connection
                    $db = Database::getInstance();
                    $db->execute('UPDATE user_configs SET last_test = ?, connected = 0, connection_errors = ? WHERE id = ?', [
                        date('Y-m-d H:i:s'),
                        $test_result['error'] ?: 'Connection test failed',
                        $current_store['id']
                    ]);
                }
            } else {
                $response['success'] = true;
                $response['connected'] = false;
                $response['message'] = 'API not properly configured';
            }
            
        } catch (Exception $api_error) {
            $response['success'] = true;
            $response['connected'] = false;
            $response['message'] = 'API Error: ' . $api_error->getMessage();
            
            // Log the error
            error_log("WooCommerce API Error in check_connection: " . $api_error->getMessage());
            
            // Update database with error
            try {
                $db = Database::getInstance();
                $db->execute('UPDATE user_configs SET last_test = ?, connected = 0, connection_errors = ? WHERE id = ?', [
                    date('Y-m-d H:i:s'),
                    'API Error: ' . $api_error->getMessage(),
                    $current_store['id']
                ]);
            } catch (Exception $db_error) {
                // Ignore database update errors in this context
            }
        }
    } else {
        // WooCommerceAPI class not available
        $response['success'] = true;
        $response['connected'] = false;
        $response['message'] = 'WooCommerce API class not available';
    }
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['error'] = 'system_error';
    $response['message'] = 'System error occurred';
    
    // Log the error but don't expose internal details
    error_log("System error in check_connection API: " . $e->getMessage());
}

// Return JSON response
echo json_encode($response);
exit;
?>