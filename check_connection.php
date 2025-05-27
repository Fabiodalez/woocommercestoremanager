<?php
// check_connection.php
header('Content-Type: application/json');
require_once 'config.php';
require_once 'WooCommerceAPI.php';

try {
    $api = new WooCommerceAPI();
    $connected = Config::get('connected', false);
    
    if ($connected && $api->isConfigured()) {
        // Quick test to verify connection is still valid
        $response = $api->makeRequest('products', ['per_page' => 1]);
        if ($response['success']) {
            echo json_encode(['connected' => true, 'status' => 'ok']);
        } else {
            Config::save(['connected' => false]);
            echo json_encode(['connected' => false, 'error' => $response['error']]);
        }
    } else {
        echo json_encode(['connected' => false, 'status' => 'not_configured']);
    }
} catch (Exception $e) {
    echo json_encode(['connected' => false, 'error' => $e->getMessage()]);
}
?>
