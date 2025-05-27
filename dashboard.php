<?php
// dashboard.php - Multi-User Dashboard (Standalone, Fixed API Method Error)

// --- INIZIO BLOCCO DEBUG E CONFIGURAZIONE ERRORI ---
if (!defined('LOGIN_DEBUG_MODE')) {
    define('LOGIN_DEBUG_MODE', true); // Imposta a false o commenta in produzione
}
if (LOGIN_DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}
function dashboard_debug_log($message) {
    if (LOGIN_DEBUG_MODE && function_exists('error_log')) {
        error_log("DASHBOARD.PHP DEBUG: " . $message);
    }
}
dashboard_debug_log("==== Script Start ====");
// --- FINE BLOCCO DEBUG ---

session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (!class_exists('WooCommerceAPI')) {
    $woo_api_path = __DIR__ . '/WooCommerceAPI.php';
    if (file_exists($woo_api_path)) {
        require_once $woo_api_path;
        dashboard_debug_log("WooCommerceAPI.php loaded by dashboard.");
    } else {
        die('FATAL ERROR: WooCommerceAPI.php not found. Please check the path.');
    }
}

Config::init();
$auth_check_dashboard = new Auth();
$user = $auth_check_dashboard->requireAuth(); // Fa il redirect se l'utente non è loggato

$db = Database::getInstance();

$pageTitle = "Dashboard";
$appName = Config::getSystemSetting('app_name', 'WooCommerce Store Manager');
$userAvatarChar = strtoupper(substr($user['first_name'] ?? $user['username'], 0, 1));

// --- Caricamento Dati Dashboard ---
$current_store_data = Config::getCurrentStore();
$accessible_stores = Config::getAccessibleStores();
$notifications = Config::getNotifications(5, true); // Max 5 non lette
$recent_user_activity = $auth_check_dashboard->getUserActivity($user['id'], 5);

$system_stats = [];
$all_users_list_dashboard = [];
if (Config::isAdmin()) {
    dashboard_debug_log("User is Admin. Fetching admin-specific system statistics.");
    try {
        $system_stats = [
            'total_users'           => $db->count('users'),
            'active_users'          => $db->count('users', ['is_active' => 1]),
            'admin_users'           => $db->count('users', ['is_admin' => 1]),
            'total_stores'          => $db->count('user_configs'),
            'connected_stores'      => $db->count('user_configs', ['connected' => 1]),
            'active_sessions'       => $db->count('user_sessions', ['is_active' => 1, 'expires_at >' => date('Y-m-d H:i:s')]),
            'total_activity_logs'   => $db->count('user_activity'),
        ];
    } catch (Exception $e) {
        dashboard_debug_log("EXCEPTION while fetching admin stats for dashboard: " . $e->getMessage());
    }
    $all_users_list_dashboard = $auth_check_dashboard->getAllUsers(5);
}

// Helper function to safely get total count from API response
function getApiTotalCount($api_response, $api_instance = null) {
    if (!$api_response['success']) {
        return 0;
    }
    
    // Try different methods to get total count
    if (isset($api_response['total'])) {
        return (int) $api_response['total'];
    }
    
    if (isset($api_response['data']) && is_array($api_response['data'])) {
        // If we have data, count the items (this might not be accurate for paginated results)
        return count($api_response['data']);
    }
    
    // Try to get from headers if API instance has the method
    if ($api_instance && method_exists($api_instance, 'getLastResponseHeaders')) {
        $headers = $api_instance->getLastResponseHeaders();
        if (isset($headers['X-WP-Total'])) {
            return (int) $headers['X-WP-Total'];
        }
        if (isset($headers['x-wp-total'])) {
            return (int) $headers['x-wp-total'];
        }
    }
    
    // If API instance has getTotalCount method
    if ($api_instance && method_exists($api_instance, 'getTotalCount')) {
        return (int) $api_instance->getTotalCount();
    }
    
    // Default fallback
    return 0;
}

$store_stats = [
    'total_products' => 0, 'total_orders' => 0, 'total_customers' => 0,
    'processing_orders' => 0, 'low_stock_products' => 0,
    'store_currency_symbol' => Config::getSetting('currency', '$'), // Default dall'utente o sistema
];
$store_errors = [];

if ($current_store_data && isset($current_store_data['connected']) && $current_store_data['connected']) {
    dashboard_debug_log("Current store (ID: {$current_store_data['id']}, Name: {$current_store_data['store_name']}) is connected. Fetching store stats via API.");
    try {
        $api = new WooCommerceAPI($user['id'], $current_store_data['id']);
        if ($api->isConfigured()) {
            dashboard_debug_log("API is configured. Fetching data...");
            
            // Get system status for currency
            $status_res = $api->getSystemStatus();
            if ($status_res['success'] && isset($status_res['data']['settings']['currency_symbol'])) {
                $store_stats['store_currency_symbol'] = $status_res['data']['settings']['currency_symbol'];
            }

            // Get products count
            $p_res = $api->getProducts(['per_page' => 1, 'status' => 'publish']);
            $store_stats['total_products'] = getApiTotalCount($p_res, $api);
            if (!$p_res['success']) $store_errors[] = "Products: " . ($p_res['error'] ?? 'N/A');

            // Get orders count
            $o_res = $api->getOrders(['per_page' => 1]);
            $store_stats['total_orders'] = getApiTotalCount($o_res, $api);
            if (!$o_res['success']) $store_errors[] = "Orders: " . ($o_res['error'] ?? 'N/A');

            // Get customers count
            $c_res = $api->getCustomers(['per_page' => 1, 'role' => 'customer']);
            $store_stats['total_customers'] = getApiTotalCount($c_res, $api);
            if (!$c_res['success']) $store_errors[] = "Customers: " . ($c_res['error'] ?? 'N/A');

            // Get processing orders count
            $po_res = $api->getOrders(['per_page' => 1, 'status' => 'processing']);
            $store_stats['processing_orders'] = getApiTotalCount($po_res, $api);
            if (!$po_res['success']) $store_errors[] = "Processing Orders: " . ($po_res['error'] ?? 'N/A');

            // Get low stock products count
            $ls_res = $api->getProducts(['per_page' => 1, 'stock_status' => 'lowstock']);
            $store_stats['low_stock_products'] = getApiTotalCount($ls_res, $api);
            if (!$ls_res['success']) $store_errors[] = "Low Stock Products: " . ($ls_res['error'] ?? 'N/A');
            
        } else {
            $store_errors[] = "API not configured for '" . htmlspecialchars($current_store_data['store_name'] ?? '', ENT_QUOTES, 'UTF-8') . "'.";
        }
    } catch (Exception $e) {
        $store_errors[] = 'API Error: ' . $e->getMessage();
        Config::logActivity('dashboard_api_error', 'Exception: ' . $e->getMessage(), 'error', ['store_id' => $current_store_data['id'] ?? null]);
        dashboard_debug_log("EXCEPTION in API calls: " . $e->getMessage());
    }
} elseif ($current_store_data) {
    $store_errors[] = "Store '" . htmlspecialchars($current_store_data['store_name'] ?? '', ENT_QUOTES, 'UTF-8') . "' is not connected.";
}

$activePageForMenu = 'dashboard';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(Config::getLanguage() ?: 'en'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo htmlspecialchars($appName); ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        .dropdown-menu { opacity: 0; visibility: hidden; transform: translateY(10px); transition: all 0.2s ease-out; }
        .dropdown-menu.active { opacity: 1; visibility: visible; transform: translateY(0); }
        .nav-link { padding: 0.5rem 1rem; margin: 0 0.125rem; border-radius: 0.375rem; transition: all 0.2s ease-out; font-weight: 500; font-size: 0.875rem; display: inline-flex; align-items: center; color: #4B5563; }
        .nav-link:hover { background-color: #E5E7EB; color: #1F2937; }
        .nav-link.active { background-color: #DBEAFE; color: #2563EB; }
        .nav-link i { margin-right: 0.625rem; width:1.1em; text-align:center; opacity: 0.7; }
        .nav-link.active i { opacity: 1; }
        .stat-card { transition: transform 0.25s ease, box-shadow 0.25s ease; }
        .stat-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.08), 0 4px 8px rgba(0,0,0,0.06); }
        .gradient-blue { background-image: linear-gradient(to right, #60A5FA, #3B82F6); }
        .gradient-green { background-image: linear-gradient(to right, #34D399, #10B981); }
        .gradient-orange { background-image: linear-gradient(to right, #FDBA74, #F97316); }
        .gradient-purple { background-image: linear-gradient(to right, #A78BFA, #7C3AED); }
        .gradient-red { background-image: linear-gradient(to right, #F87171, #EF4444); }
        .modal { display: none; }
        .modal.active { display: flex; }
        /* Custom scrollbar */
        .custom-scrollbar::-webkit-scrollbar { width: 7px; background: #f1f1f1; border-radius:6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius:6px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
        /* Field style enhancement */
        .field-height { min-height: 56px; }
        
        /* Enhanced button styles matching settings.php */
        .btn-enhanced {
            @apply bg-gradient-to-r from-blue-600 via-blue-700 to-blue-800 text-white px-8 py-4 rounded-xl 
                   font-bold text-base hover:from-blue-700 hover:via-blue-800 hover:to-blue-900 
                   focus:outline-none focus:ring-4 focus:ring-blue-200 focus:ring-offset-2 
                   transition-all duration-300 ease-in-out shadow-lg hover:shadow-xl 
                   transform hover:-translate-y-1 active:translate-y-0 min-h-[48px] 
                   flex items-center justify-center;
            background-size: 200% 200%;
            animation: gradientShift 3s ease infinite;
        }
        
        .btn-enhanced.btn-purple {
            @apply from-purple-600 via-purple-700 to-purple-800 hover:from-purple-700 hover:via-purple-800 hover:to-purple-900 focus:ring-purple-200;
        }
        
        .btn-enhanced.btn-indigo {
            @apply from-indigo-600 via-indigo-700 to-indigo-800 hover:from-indigo-700 hover:via-indigo-800 hover:to-indigo-900 focus:ring-indigo-200;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-100 via-blue-50 to-gray-100 text-gray-800 antialiased">
    <div class="flex flex-col min-h-screen">
        <!-- Header di Navigazione -->
        <?php include 'header.php'; ?>

        <main class="flex-grow max-w-screen-xl mx-auto py-8 px-4 sm:px-6 lg:px-8 w-full">
            <!-- Welcome & Quick Actions -->
            <div class="mb-8 p-8 bg-white rounded-2xl shadow-xl">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
                    <div>
                        <h1 class="text-3xl sm:text-4xl font-bold text-gray-800">
                            Good <?php 
                                $hour = date('H');
                                if ($hour < 12) echo "morning"; elseif ($hour < 18) echo "afternoon"; else echo "evening";
                            ?>, <?php echo htmlspecialchars($user['first_name'] ?? $user['username']); ?>!
                        </h1>
                        <p class="mt-2 text-base text-gray-500 flex items-center flex-wrap">
                            <i class="far fa-calendar-alt mr-2 opacity-75"></i> <?php echo date('l, F jS, Y'); ?>
                            <span class="mx-3 hidden sm:inline text-gray-300">•</span>
                            <i class="far fa-clock mr-2 opacity-75 sm:ml-0 ml-0 mt-1 sm:mt-0"></i>
                            <span id="current-time-dashboard" class="mt-1 sm:mt-0"><?php echo date('g:i:s A'); ?></span>
                            <span class="text-sm ml-2 text-gray-400">(<?php echo htmlspecialchars(Config::getTimezone()); ?>)</span>
                        </p>
                    </div>
                    <div class="sm:ml-auto flex flex-wrap gap-3 sm:gap-4">
                        <?php if ($current_store_data && !empty($current_store_data['connected'])): ?>
                            <a href="products.php?action=add" class="btn-enhanced">
                                <i class="fas fa-plus-circle mr-3"></i>Add Product
                            </a>
                        <?php endif; ?>
                        <?php if (count($accessible_stores) > 0 || empty($current_store_data)): ?>
                            <button id="switch-store-btn-main-dashboard" class="btn-enhanced btn-purple">
                                <i class="fas fa-exchange-alt mr-3"></i><?php echo empty($current_store_data) ? 'Select Store' : 'Switch Store'; ?>
                            </button>
                        <?php elseif (empty($accessible_stores)): ?>
                            <a href="settings.php#connect-store-section" class="btn-enhanced btn-indigo">
                                <i class="fas fa-link mr-3"></i>Connect Store
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Store API Errors -->
            <?php if (!empty($store_errors)): ?>
            <div class="mb-8 bg-red-50 border-l-4 border-red-500 text-red-700 p-6 rounded-xl shadow" role="alert">
                <div class="flex"><div class="flex-shrink-0"><i class="fas fa-times-circle fa-2x mr-4"></i></div><div>
                    <h3 class="text-lg font-bold">Store Data Issues<?php if($current_store_data) echo ' for ' . htmlspecialchars($current_store_data['store_name'] ?? 'Unnamed Store'); ?></h3>
                    <ul class="mt-2 list-disc list-inside text-base"><?php foreach ($store_errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?></ul>
                    <?php if($current_store_data): ?><p class="mt-3 text-sm">Please <a href="settings.php?store_id=<?php echo $current_store_data['id']; ?>#connect-store-section" class="font-semibold underline hover:text-red-600">check connection settings</a>.</p><?php endif; ?>
                </div></div>
            </div>
            <?php endif; ?>

            <!-- Statistics Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-6 mb-10">
                <?php if ($current_store_data && !empty($current_store_data['connected'])): ?>
                    <div class="stat-card gradient-blue text-white p-6 rounded-2xl shadow-xl">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-base opacity-80 font-medium">Total Products</p>
                            <i class="fas fa-boxes fa-2x opacity-60"></i>
                        </div>
                        <p class="text-4xl font-bold mb-2">
                            <?php echo number_format($store_stats['total_products'] ?? 0); ?>
                        </p>
                        <a href="products.php" class="text-sm opacity-75 hover:opacity-100 transition-opacity block">
                            View Products →
                        </a>
                    </div>
                    <div class="stat-card gradient-green text-white p-6 rounded-2xl shadow-xl">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-base opacity-80 font-medium">Total Orders</p>
                            <i class="fas fa-shopping-cart fa-2x opacity-60"></i>
                        </div>
                        <p class="text-4xl font-bold mb-2">
                            <?php echo number_format($store_stats['total_orders'] ?? 0); ?>
                        </p>
                        <a href="orders.php" class="text-sm opacity-75 hover:opacity-100 transition-opacity block">
                            View Orders →
                        </a>
                    </div>
                    <div class="stat-card gradient-orange text-white p-6 rounded-2xl shadow-xl">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-base opacity-80 font-medium">Processing Orders</p>
                            <i class="fas fa-cogs fa-2x opacity-60"></i>
                        </div>
                        <p class="text-4xl font-bold mb-2">
                            <?php echo number_format($store_stats['processing_orders'] ?? 0); ?>
                        </p>
                        <a href="orders.php?status=processing" class="text-sm opacity-75 hover:opacity-100 transition-opacity block">
                            Manage Orders →
                        </a>
                    </div>
                    <div class="stat-card gradient-purple text-white p-6 rounded-2xl shadow-xl">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-base opacity-80 font-medium">Customers</p>
                            <i class="fas fa-users fa-2x opacity-60"></i>
                        </div>
                        <p class="text-4xl font-bold mb-2">
                            <?php echo number_format($store_stats['total_customers'] ?? 0); ?>
                        </p>
                        <a href="customers.php" class="text-sm opacity-75 hover:opacity-100 transition-opacity block">
                            View Customers →
                        </a>
                    </div>
                    <div class="stat-card gradient-red text-white p-6 rounded-2xl shadow-xl">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-base opacity-80 font-medium">Low Stock Items</p>
                            <i class="fas fa-exclamation-triangle fa-2x opacity-60"></i>
                        </div>
                        <p class="text-4xl font-bold mb-2">
                            <?php echo number_format($store_stats['low_stock_products'] ?? 0); ?>
                        </p>
                        <a href="products.php?stock_status=lowstock" class="text-sm opacity-75 hover:opacity-100 transition-opacity block">
                            Manage Stock →
                        </a>
                    </div>
                <?php elseif ($current_store_data && empty($current_store_data['connected'])): ?>
                    <?php $storeName = $current_store_data['store_name'] ?? ''; ?>
                    <div class="sm:col-span-2 lg:col-span-3 xl:col-span-5 bg-yellow-50 border-l-4 border-yellow-400 p-8 rounded-2xl shadow-lg">
                        <div class="flex items-center">
                            <i class="fas fa-plug fa-2x mr-4 text-yellow-500"></i>
                            <div>
                                <h3 class="text-xl font-semibold text-yellow-800">
                                    Store '<?php echo htmlspecialchars($storeName, ENT_QUOTES, 'UTF-8'); ?>' is Disconnected
                                </h3>
                                <p class="text-base text-yellow-700 mt-2">
                                    API data cannot be fetched. Please 
                                    <a href="settings.php?store_id=<?php echo (int)($current_store_data['id'] ?? 0); ?>#connect-store-section" 
                                       class="font-semibold underline hover:text-yellow-600">
                                        re-configure the connection
                                    </a>.
                                </p>
                            </div>
                        </div>
                    </div>
                <?php elseif (empty($accessible_stores)): ?>
                    <div class="sm:col-span-2 lg:col-span-3 xl:col-span-5 bg-blue-50 border-l-4 border-blue-400 p-8 rounded-2xl shadow-lg">
                        <div class="flex items-center">
                            <i class="fas fa-info-circle fa-2x mr-4 text-blue-500"></i>
                            <div>
                                <h3 class="text-xl font-semibold text-blue-800">No Stores Connected</h3>
                                <p class="text-base text-blue-700 mt-2">
                                    Connect your WooCommerce store in Settings to see dashboard statistics.
                                </p>
                                <a href="settings.php#connect-store-section" 
                                   class="mt-3 inline-block text-base text-blue-600 font-semibold hover:underline">
                                    Go to Settings →
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: // negozi accessibili ma nessuno selezionato ?>
                    <div class="sm:col-span-2 lg:col-span-3 xl:col-span-5 bg-indigo-50 border-l-4 border-indigo-400 p-8 rounded-2xl shadow-lg">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center">
                                <i class="fas fa-store-alt fa-2x mr-4 text-indigo-500"></i>
                                <div>
                                    <h3 class="text-xl font-semibold text-indigo-800">Please Select a Store</h3>
                                    <p class="text-base text-indigo-700 mt-2">
                                        You have access to one or more stores. Select one to view its dashboard.
                                    </p>
                                </div>
                            </div>
                            <button id="select-store-prompt-btn-dashboard-alt" 
                                    class="btn-enhanced btn-indigo flex-shrink-0 ml-4">
                                <i class="fas fa-list-ul mr-3"></i>Choose Store
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- My Stores Section -->
                <div class="lg:col-span-2 bg-white rounded-2xl shadow-xl p-8">
                    <div class="flex justify-between items-center mb-6">
                        <h3 class="text-2xl font-semibold text-gray-800">My Stores</h3>
                        <a href="settings.php#connect-store-section" class="text-base font-medium text-blue-600 hover:text-blue-700 hover:underline flex items-center">
                            <i class="fas fa-plus-circle mr-2"></i>Add/Manage Stores
                        </a>
                    </div>
                    <?php if (empty($accessible_stores)): ?>
                        <div class="text-center py-12 border-2 border-dashed border-gray-200 rounded-xl bg-gray-50">
                            <i class="fas fa-store-slash fa-4x text-gray-400 mb-4"></i>
                            <h4 class="text-lg font-semibold text-gray-600">No Stores Linked</h4>
                            <p class="mt-2 text-base text-gray-500">Link your WooCommerce stores to manage them from here.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4 max-h-[28rem] overflow-y-auto pr-3 -mr-3 custom-scrollbar">
                            <?php foreach ($accessible_stores as $store_item_list): ?>
                                <div class="store-card border-2 rounded-xl p-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 transition-all <?php echo ($current_store_data && $current_store_data['id'] == $store_item_list['id']) ? 'ring-2 ring-blue-500 bg-blue-50 shadow-lg border-blue-200' : 'border-gray-200 hover:border-blue-400 hover:shadow-md'; ?>">
                                    <div class="flex items-center space-x-4 min-w-0">
                                        <div class="flex-shrink-0 w-12 h-12 <?php echo $store_item_list['connected'] ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?> rounded-xl flex items-center justify-center">
                                            <i class="fas <?php echo $store_item_list['connected'] ? 'fa-link' : 'fa-unlink'; ?> fa-xl"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <h4 class="text-lg font-semibold text-gray-800 truncate" title="<?php echo htmlspecialchars($store_item_list['store_name'] ?? 'Unnamed Store'); ?>">
                                                <?php echo htmlspecialchars($store_item_list['store_name'] ?? 'Unnamed Store'); ?>
                                            </h4>
                                            <p class="text-sm text-gray-500 truncate" title="<?php echo htmlspecialchars($store_item_list['store_url'] ?? ''); ?>">
                                                <?php echo htmlspecialchars($store_item_list['store_url'] ?? ''); ?>
                                            </p>
                                            <p class="text-sm text-gray-400 mt-1">Role: <span class="font-medium"><?php echo ucfirst(htmlspecialchars($store_item_list['role'] ?? 'Owner')); ?></span></p>
                                        </div>
                                    </div>
                                    <div class="flex items-center space-x-3 sm:ml-auto flex-shrink-0">
                                        <?php if (!($current_store_data && $current_store_data['id'] == $store_item_list['id'])): ?>
                                            <button onclick="switchToStoreDashboard(<?php echo $store_item_list['id']; ?>)" class="text-sm whitespace-nowrap text-blue-600 hover:text-blue-800 font-semibold py-3 px-5 rounded-xl hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-blue-500 ring-offset-1 transition-all">Set Active</button>
                                        <?php else: ?>
                                            <span class="px-4 py-2 text-sm font-bold text-blue-700 bg-blue-100 rounded-full whitespace-nowrap">Current</span>
                                        <?php endif; ?>
                                        <a href="settings.php?store_id=<?php echo $store_item_list['id']; ?>#connect-store-section" class="text-sm text-gray-400 hover:text-gray-600 p-3 rounded-xl hover:bg-gray-100" title="Configure Store"><i class="fas fa-cog fa-lg"></i></a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Right Sidebar: Activity & Notifications -->
                <div class="space-y-8">
                    <div class="bg-white rounded-2xl shadow-xl p-8">
                        <h3 class="text-xl font-semibold text-gray-800 mb-6">Recent Activity</h3>
                        <?php if (empty($recent_user_activity)): ?>
                            <p class="text-base text-gray-500">No recent activity.</p>
                        <?php else: ?>
                            <ul class="space-y-4 max-h-64 overflow-y-auto pr-2 custom-scrollbar">
                                <?php foreach ($recent_user_activity as $activity_item): ?>
                                    <li class="flex items-start space-x-4 group pb-3 border-b border-gray-100 last:border-b-0">
                                        <div class="flex-shrink-0 mt-2 w-3 h-3 bg-gray-300 rounded-full group-hover:bg-blue-500 transition-colors"></div>
                                        <div>
                                            <p class="text-base text-gray-700 group-hover:text-gray-900 leading-tight">
                                                <?php echo htmlspecialchars($activity_item['description'] ?? ''); ?>
                                            </p>
                                            <p class="text-sm text-gray-400 group-hover:text-gray-500 mt-1">
                                                <?php echo Config::formatDateTime($activity_item['created_at'] ?? ''); ?>
                                            </p>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <div class="mt-6">
                            <a href="activity.php" class="text-base font-medium text-blue-600 hover:text-blue-700 hover:underline">View all activity →</a>
                        </div>
                    </div>

                    <?php if (!empty($notifications)): ?>
                    <div class="bg-white rounded-2xl shadow-xl p-8">
                        <h3 class="text-xl font-semibold text-gray-800 mb-6">Unread Notifications</h3>
                        <ul class="space-y-4 max-h-64 overflow-y-auto pr-2 custom-scrollbar">
                            <?php foreach ($notifications as $notification_item): ?>
                                <li class="notification-item p-4 border-l-4 <?php
                                    echo ($notification_item['type'] === 'error')
                                        ? 'border-red-500 bg-red-50'
                                        : (($notification_item['type'] === 'warning')
                                            ? 'border-yellow-400 bg-yellow-50'
                                            : 'border-blue-500 bg-blue-50');
                                ?> rounded-r-xl shadow-sm hover:shadow-md transition-shadow">
                                    <div class="flex justify-between items-start">
                                        <h4 class="font-medium text-gray-800 text-base"><?php echo htmlspecialchars($notification_item['title']); ?></h4>
                                        <button onclick="dismissNotificationDashboard(<?php echo $notification_item['id']; ?>, this)" class="text-gray-400 hover:text-red-600 text-sm p-2 -mr-2" title="Dismiss"><i class="fas fa-times"></i></button>
                                    </div>
                                    <?php if (!empty($notification_item['message'])): ?>
                                        <p class="text-sm text-gray-600 mt-2"><?php echo nl2br(htmlspecialchars($notification_item['message'])); ?></p>
                                    <?php endif; ?>
                                    <?php if (!empty($notification_item['action_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($notification_item['action_url']); ?>" class="text-sm text-blue-600 hover:underline mt-2 inline-block"><?php echo htmlspecialchars($notification_item['action_text'] ?? 'View Details'); ?> →</a>
                                    <?php endif; ?>
                                    <p class="text-xs text-gray-500 mt-3"><?php echo Config::formatDateTime($notification_item['created_at'] ?? ''); ?></p>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="mt-6"><a href="notifications.php" class="text-base font-medium text-blue-600 hover:text-blue-700 hover:underline">View all notifications →</a></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Admin Quick Overview (se l'utente è admin) -->
            <?php if (Config::isAdmin()): ?>
            <div class="mt-10 bg-white rounded-2xl shadow-xl p-8">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-8 gap-4">
                    <div>
                        <h3 class="text-2xl font-semibold text-gray-800">Admin Quick View</h3>
                        <p class="text-base text-gray-500">System statistics and user overview.</p>
                    </div>
                    <a href="admin.php" class="btn-enhanced btn-indigo">
                        <i class="fas fa-user-shield mr-3"></i>Full Admin Panel
                    </a>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 mb-8">
                    <div class="p-5 bg-gray-50 rounded-xl border-2 border-gray-200 hover:border-gray-300 transition-colors"><p class="text-sm text-gray-500 uppercase tracking-wider font-medium">Total Users</p><p class="text-3xl font-bold text-gray-700"><?php echo number_format($system_stats['total_users'] ?? 0); ?></p></div>
                    <div class="p-5 bg-gray-50 rounded-xl border-2 border-gray-200 hover:border-gray-300 transition-colors"><p class="text-sm text-gray-500 uppercase tracking-wider font-medium">Active Users</p><p class="text-3xl font-bold text-gray-700"><?php echo number_format($system_stats['active_users'] ?? 0); ?></p></div>
                    <div class="p-5 bg-gray-50 rounded-xl border-2 border-gray-200 hover:border-gray-300 transition-colors"><p class="text-sm text-gray-500 uppercase tracking-wider font-medium">Admin Users</p><p class="text-3xl font-bold text-gray-700"><?php echo number_format($system_stats['admin_users'] ?? 0); ?></p></div>
                    <div class="p-5 bg-gray-50 rounded-xl border-2 border-gray-200 hover:border-gray-300 transition-colors"><p class="text-sm text-gray-500 uppercase tracking-wider font-medium">Total Stores</p><p class="text-3xl font-bold text-gray-700"><?php echo number_format($system_stats['total_stores'] ?? 0); ?></p></div>
                    <div class="p-5 bg-gray-50 rounded-xl border-2 border-gray-200 hover:border-gray-300 transition-colors"><p class="text-sm text-gray-500 uppercase tracking-wider font-medium">Connected Stores</p><p class="text-3xl font-bold text-gray-700"><?php echo number_format($system_stats['connected_stores'] ?? 0); ?></p></div>
                    <div class="p-5 bg-gray-50 rounded-xl border-2 border-gray-200 hover:border-gray-300 transition-colors"><p class="text-sm text-gray-500 uppercase tracking-wider font-medium">Live Sessions</p><p class="text-3xl font-bold text-gray-700"><?php echo number_format($system_stats['active_sessions'] ?? 0); ?></p></div>
                </div>
                <h4 class="text-lg font-semibold text-gray-700 mb-4">Recently Joined Users (Latest 5)</h4>
                <?php if(empty($all_users_list_dashboard)): ?>
                    <p class="text-base text-gray-500">No new users recently.</p>
                <?php else: ?>
                    <div class="overflow-x-auto border-2 border-gray-200 rounded-xl">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">Name</th>
                                    <th class="px-6 py-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                    <th class="px-6 py-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">Joined</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach($all_users_list_dashboard as $admin_user_item): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap text-base font-medium text-gray-800">
                                            <?php echo htmlspecialchars(trim(($admin_user_item['first_name'] ?? '') . ' ' . ($admin_user_item['last_name'] ?? '')) ?: $admin_user_item['username']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-base text-gray-500">
                                            <?php echo htmlspecialchars($admin_user_item['email'] ?? ''); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-3 inline-flex text-sm leading-5 font-semibold rounded-full <?php echo !empty($admin_user_item['is_active']) ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo !empty($admin_user_item['is_active']) ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-base text-gray-500">
                                            <?php echo Config::formatDateTime($admin_user_item['created_at'] ?? '', 'M j, Y'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </main>

        <!-- Store Switch Modal -->
        <?php if (count($accessible_stores) > 0 || empty($current_store_data)): ?>
            <div id="store-switch-modal-dashboard" class="modal fixed inset-0 bg-gray-900 bg-opacity-75 flex items-center justify-center p-4 z-[60] transition-opacity duration-300 opacity-0 pointer-events-none" onclick="closeStoreModalDashboardOnClickOutside(event)">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-8 sm:p-10 transform transition-all duration-300 scale-95 opacity-0" id="store-switch-modal-content-dashboard">
                    <div class="flex justify-between items-center mb-8">
                        <h3 class="text-2xl font-semibold text-gray-800">Select a Store</h3>
                        <button onclick="closeStoreModalDashboard()" class="text-gray-400 hover:text-gray-700 transition-colors p-2 rounded-full hover:bg-gray-100"><i class="fas fa-times fa-xl"></i></button>
                    </div>
                    <div class="max-h-[60vh] overflow-y-auto space-y-4 pr-3 -mr-3 custom-scrollbar">
                        <?php if (empty($accessible_stores)): ?>
                            <p class="text-center text-gray-500 py-6">You have no stores configured yet.</p>
                        <?php else: ?>
                            <?php foreach ($accessible_stores as $store_modal_item): ?>
                                <button onclick="switchToStoreDashboard(<?php echo $store_modal_item['id']; ?>)" class="w-full text-left p-5 border-2 rounded-xl hover:shadow-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-opacity-75 transition-all duration-150 <?php echo ($current_store_data && $current_store_data['id'] == $store_modal_item['id']) ? 'border-blue-500 bg-blue-50 ring-2 ring-blue-400' : 'border-gray-300 hover:border-blue-400 hover:bg-gray-50'; ?>">
                                    <div class="flex items-center justify-between">
                                        <div class="min-w-0">
                                            <div class="font-semibold text-gray-700 truncate text-lg"><?php echo htmlspecialchars($store_modal_item['store_name'] ?? 'Unnamed Store'); ?></div>
                                            <div class="text-sm text-gray-500 truncate"><?php echo htmlspecialchars($store_modal_item['store_url'] ?? ''); ?></div>
                                        </div>
                                        <?php if ($current_store_data && $current_store_data['id'] == $store_modal_item['id']): ?>
                                            <i class="fas fa-check-circle text-blue-500 ml-4 fa-2x"></i>
                                        <?php endif; ?>
                                    </div>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <a href="settings.php#connect-store-section" class="block w-full text-center mt-8 p-4 border-2 border-dashed border-gray-300 rounded-xl text-base font-medium text-gray-600 hover:border-blue-500 hover:text-blue-600 transition-colors">
                        <i class="fas fa-plus mr-3"></i>Add or Manage Stores
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="max-w-screen-xl mx-auto py-6 px-4 sm:px-6 lg:px-8 text-center text-sm text-gray-500">
                © <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. All rights reserved.
            </div>
        </footer>
    </div> <!-- Fine flex flex-col min-h-screen -->

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // User Menu Dropdown
        const userMenuBtn = document.getElementById('user-menu-btn-dashboard'); // ID Unico
        const userMenuDropdown = document.getElementById('user-menu-dropdown-dashboard');
        if (userMenuBtn && userMenuDropdown) {
            userMenuBtn.addEventListener('click', function(event) {
                event.stopPropagation(); userMenuDropdown.classList.toggle('active');
                userMenuBtn.setAttribute('aria-expanded', userMenuDropdown.classList.contains('active'));
            });
            document.addEventListener('click', function(event) {
                if (userMenuDropdown.classList.contains('active') && !userMenuBtn.contains(event.target) && !userMenuDropdown.contains(event.target)) {
                    userMenuDropdown.classList.remove('active');
                    userMenuBtn.setAttribute('aria-expanded', 'false');
                }
            });
        }

        // Mobile Menu Toggle
        const mobileMenuBtn = document.getElementById('mobile-menu-btn-dashboard');
        const mobileMenuPanel = document.getElementById('mobile-menu-panel-dashboard');
        if(mobileMenuBtn && mobileMenuPanel){
            mobileMenuBtn.addEventListener('click', function(){
                const expanded = mobileMenuPanel.classList.toggle('hidden');
                mobileMenuBtn.setAttribute('aria-expanded', !expanded);
                mobileMenuBtn.innerHTML = !expanded ? '<i class="fas fa-times fa-lg"></i>' : '<i class="fas fa-bars fa-lg"></i>';
            });
        }

        // Store Switch Modal
        const switchStoreBtnMain = document.getElementById('switch-store-btn-main-dashboard');
        const selectStorePromptBtn = document.getElementById('select-store-prompt-btn-dashboard-alt');
        const storeSwitchModal = document.getElementById('store-switch-modal-dashboard');
        const storeSwitchModalContent = document.getElementById('store-switch-modal-content-dashboard');

        function openStoreModal() {
            if (storeSwitchModal && storeSwitchModalContent) {
                storeSwitchModal.classList.add('active');
                setTimeout(() => {
                    storeSwitchModal.classList.remove('opacity-0', 'pointer-events-none');
                    storeSwitchModalContent.classList.remove('scale-95', 'opacity-0');
                }, 10);
            }
        }
        if (switchStoreBtnMain) switchStoreBtnMain.addEventListener('click', openStoreModal);
        if (selectStorePromptBtn) selectStorePromptBtn.addEventListener('click', openStoreModal);

        // Live Clock
        const timeElement = document.getElementById('current-time-dashboard');
        function updateTime() {
            if (timeElement) {
                const now = new Date();
                timeElement.textContent = now.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', second: '2-digit' });
            }
        }
        if (timeElement) { setInterval(updateTime, 1000); updateTime(); }
    });

    function closeStoreModalDashboard() {
        const storeSwitchModal = document.getElementById('store-switch-modal-dashboard');
        const storeSwitchModalContent = document.getElementById('store-switch-modal-content-dashboard');
        if (storeSwitchModal && storeSwitchModalContent) {
            storeSwitchModalContent.classList.add('scale-95', 'opacity-0');
            storeSwitchModal.classList.add('opacity-0');
            setTimeout(() => {
                storeSwitchModal.classList.remove('active');
                storeSwitchModal.classList.add('pointer-events-none');
            }, 300);
        }
    }
    function closeStoreModalDashboardOnClickOutside(event) {
        if (event.target === document.getElementById('store-switch-modal-dashboard')) {
            closeStoreModalDashboard();
        }
    }

    function switchToStoreDashboard(storeId) {
        const modalContent = document.getElementById('store-switch-modal-content-dashboard');
        if(modalContent) modalContent.innerHTML = '<div class="text-center py-12"><div class="loading-spinner mx-auto mb-4" style="width:40px; height:40px; border-top-color: #3B82F6; border:4px solid #e5e7eb; border-radius:50%; animation:spin 1s linear infinite;"></div><p class="text-gray-600 text-lg">Switching store...</p></div>';
        
        fetch('api/switch_store.php', {
            method: 'POST', headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({ store_id: storeId })
        })
        .then(response => response.ok ? response.json() : Promise.reject(response))
        .then(data => {
            if (data.success) { window.location.reload(); } 
            else { 
                alert('Failed to switch store: ' + (data.message || data.error || 'Unknown error'));
                closeStoreModalDashboard(); 
            }
        })
        .catch(error => {
            console.error('Error switching store:', error);
            alert('An error occurred while switching stores. Please check console or try again.');
            closeStoreModalDashboard();
        });
    }

    function dismissNotificationDashboard(notificationId, buttonElement) {
        const notificationItem = buttonElement.closest('.notification-item');
        if (notificationItem) {
            buttonElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            buttonElement.disabled = true;
        }

        fetch('api/dismiss_notification.php', {
            method: 'POST', headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({ notification_id: notificationId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (notificationItem) {
                    notificationItem.style.transition = 'opacity 0.3s ease, transform 0.3s ease, max-height 0.3s ease, padding 0.3s ease, margin 0.3s ease';
                    notificationItem.style.opacity = '0';
                    notificationItem.style.transform = 'scale(0.95)';
                    notificationItem.style.maxHeight = '0px';
                    notificationItem.style.paddingTop = '0';
                    notificationItem.style.paddingBottom = '0';
                    notificationItem.style.marginTop = '0';
                    notificationItem.style.marginBottom = '0';
                    notificationItem.style.borderWidth = '0';
                    setTimeout(() => notificationItem.remove(), 350);
                }
            } else {
                alert('Failed to dismiss notification: ' + (data.message || 'Error'));
                if (notificationItem) {
                    buttonElement.innerHTML = '<i class="fas fa-times"></i>';
                    buttonElement.disabled = false;
                }
            }
        })
        .catch(error => {
            console.error('Error dismissing notification:', error);
            alert('Error dismissing notification.');
            if (notificationItem) {
                 buttonElement.innerHTML = '<i class="fas fa-times"></i>';
                 buttonElement.disabled = false;
            }
        });
    }
</script>
</body>
</html>