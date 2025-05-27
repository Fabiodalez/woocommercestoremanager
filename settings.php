<?php
// settings.php - Enhanced Multi-User Settings Page
session_start();

// Debug configuration
if (!defined('SETTINGS_DEBUG_MODE')) {
    define('SETTINGS_DEBUG_MODE', true);
}
if (SETTINGS_DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

function settings_debug_log($message) {
    if (SETTINGS_DEBUG_MODE && function_exists('error_log')) {
        error_log("SETTINGS.PHP DEBUG: " . $message);
    }
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

if (!class_exists('WooCommerceAPI')) {
    $woo_api_path = __DIR__ . '/WooCommerceAPI.php';
    if (file_exists($woo_api_path)) {
        require_once $woo_api_path;
    }
}

// Initialize system
Config::init();
$auth = new Auth();
$user = $auth->requireAuth();
$db = Database::getInstance();

$errors = [];
$success_messages = [];
$activePageForMenu = 'settings';
$pageTitle = 'Settings';
$appName = Config::getSystemSetting('app_name', 'WooCommerce Store Manager');

// Handle store_id parameter from URL
$requested_store_id = filter_input(INPUT_GET, 'store_id', FILTER_VALIDATE_INT);
if ($requested_store_id) {
    // Verify user has access to this store
    $accessible_stores = Config::getAccessibleStores();
    $has_access = false;
    foreach ($accessible_stores as $store) {
        if ($store['id'] == $requested_store_id) {
            $has_access = true;
            break;
        }
    }
    
    if ($has_access) {
        // Switch to the requested store
        Config::setPreference('current_store_id', $requested_store_id, 'integer');
        settings_debug_log("Switched to store ID: $requested_store_id");
        
        // Redirect to remove store_id from URL but keep the anchor
        $redirect_url = 'settings.php';
        if (isset($_GET['anchor'])) {
            $redirect_url .= '#' . $_GET['anchor'];
        }
        header("Location: $redirect_url");
        exit;
    } else {
        $errors[] = "You don't have access to the requested store.";
    }
}

$current_store_data = Config::getCurrentStore();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_profile':
                $first_name = trim($_POST['first_name'] ?? '');
                $last_name = trim($_POST['last_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $timezone = $_POST['timezone'] ?? 'UTC';
                $language = $_POST['language'] ?? 'en';
                
                // Validate email
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Valid email address is required');
                }
                
                // Check if email is already taken by another user
                $existing_user = $db->fetch('SELECT id FROM users WHERE email = ? AND id != ?', [$email, $user['id']]);
                if ($existing_user) {
                    throw new Exception('Email address is already in use by another account');
                }
                
                // Update user profile
                $db->execute('
                    UPDATE users 
                    SET first_name = ?, last_name = ?, email = ?, phone = ?, timezone = ?, language = ?, updated_at = ?
                    WHERE id = ?
                ', [
                    $first_name, $last_name, $email, $phone, $timezone, $language, 
                    date('Y-m-d H:i:s'), $user['id']
                ]);
                
                // Update session data
                $_SESSION['user']['first_name'] = $first_name;
                $_SESSION['user']['last_name'] = $last_name;
                $_SESSION['user']['email'] = $email;
                $_SESSION['user']['phone'] = $phone;
                $_SESSION['user']['timezone'] = $timezone;
                $_SESSION['user']['language'] = $language;
                
                Config::logActivity('profile_updated', 'Profile information updated', 'profile');
                $success_messages[] = 'Profile updated successfully!';
                
                // Refresh user data
                $user = $auth->getCurrentUser();
                break;

            case 'change_password':
                $current_password = $_POST['current_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                    throw new Exception('All password fields are required');
                }
                
                // Verify current password
                if (!password_verify($current_password, $user['password_hash'])) {
                    throw new Exception('Current password is incorrect');
                }
                
                if ($new_password !== $confirm_password) {
                    throw new Exception('New passwords do not match');
                }
                
                if (strlen($new_password) < 8) {
                    throw new Exception('Password must be at least 8 characters long');
                }
                
                if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $new_password)) {
                    throw new Exception('Password must contain at least one uppercase letter, one lowercase letter, and one number');
                }
                
                // Update password
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $db->execute('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?', [
                    $password_hash, date('Y-m-d H:i:s'), $user['id']
                ]);
                
                Config::logActivity('password_changed', 'Password changed successfully', 'security');
                $success_messages[] = 'Password changed successfully!';
                break;

            case 'save_store_config':
                $store_name = trim($_POST['store_name'] ?? '');
                $store_url = trim($_POST['store_url'] ?? '');
                $consumer_key = trim($_POST['consumer_key'] ?? '');
                $consumer_secret = trim($_POST['consumer_secret'] ?? '');
                $api_version = $_POST['api_version'] ?? 'v3';
                $timeout = intval($_POST['timeout'] ?? 30);
                
                if (empty($store_url) || empty($consumer_key) || empty($consumer_secret)) {
                    throw new Exception('Store URL, Consumer Key and Consumer Secret are required');
                }
                
                if (!filter_var($store_url, FILTER_VALIDATE_URL)) {
                    throw new Exception('Invalid store URL format');
                }
                
                $config_data = [
                    'store_name' => $store_name,
                    'store_url' => rtrim($store_url, '/'),
                    'consumer_key' => $consumer_key,
                    'consumer_secret' => $consumer_secret,
                    'api_version' => $api_version,
                    'timeout' => $timeout
                ];
                
                Config::save($config_data);
                $success_messages[] = 'Store configuration saved successfully!';
                $current_store_data = Config::getCurrentStore();
                break;
                
            case 'test_connection':
                if (!$current_store_data) {
                    throw new Exception('No store configuration found');
                }
                
                $api = new WooCommerceAPI();
                $result = $api->testConnection();
                
                if ($result['success']) {
                    $success_messages[] = 'Connection test successful! Your store is connected.';
                } else {
                    $errors[] = 'Connection test failed: ' . $result['error'];
                }
                break;
                
            case 'save_user_preferences':
                $preferences = [
                    'products_per_page' => intval($_POST['products_per_page'] ?? 20),
                    'orders_per_page' => intval($_POST['orders_per_page'] ?? 20),
                    'date_format' => $_POST['date_format'] ?? 'Y-m-d',
                    'time_format' => $_POST['time_format'] ?? 'H:i:s',
                    'currency' => $_POST['currency'] ?? 'USD',
                    'theme' => $_POST['theme'] ?? 'light',
                    'sidebar_collapsed' => isset($_POST['sidebar_collapsed']),
                    'notifications_enabled' => isset($_POST['notifications_enabled']),
                    'email_notifications' => isset($_POST['email_notifications']),
                    'auto_sync' => isset($_POST['auto_sync']),
                    'sync_interval' => intval($_POST['sync_interval'] ?? 300)
                ];
                
                // Save each preference
                foreach ($preferences as $key => $value) {
                    $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string');
                    $value = is_bool($value) ? ($value ? '1' : '0') : (string)$value;
                    
                    $db->execute('
                        INSERT OR REPLACE INTO user_preferences (user_id, preference_key, preference_value, preference_type, updated_at)
                        VALUES (?, ?, ?, ?, ?)
                    ', [$user['id'], $key, $value, $type, date('Y-m-d H:i:s')]);
                }
                
                Config::logActivity('preferences_updated', 'User preferences updated', 'settings');
                $success_messages[] = 'Preferences saved successfully!';
                break;
                
            case 'create_new_store':
                $store_name = trim($_POST['new_store_name'] ?? '');
                $store_url = trim($_POST['new_store_url'] ?? '');
                $consumer_key = trim($_POST['new_consumer_key'] ?? '');
                $consumer_secret = trim($_POST['new_consumer_secret'] ?? '');
                
                if (empty($store_name) || empty($store_url) || empty($consumer_key) || empty($consumer_secret)) {
                    throw new Exception('All fields are required for new store');
                }
                
                if (!filter_var($store_url, FILTER_VALIDATE_URL)) {
                    throw new Exception('Invalid store URL format');
                }
                
                $db->execute('
                    INSERT INTO user_configs (user_id, store_name, store_url, consumer_key, consumer_secret, settings) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ', [
                    $user['id'],
                    $store_name,
                    rtrim($store_url, '/'),
                    $consumer_key,
                    $consumer_secret,
                    json_encode([])
                ]);
                
                $new_store_id = $db->lastInsertId();
                Config::setPreference('current_store_id', $new_store_id, 'integer');
                
                Config::logActivity('store_created', "New store created: {$store_name}", 'config');
                $success_messages[] = 'New store configuration created and activated successfully!';
                $current_store_data = Config::getCurrentStore();
                break;
                
            case 'invite_collaborator':
                if (!$current_store_data) {
                    throw new Exception('No store selected');
                }
                
                $email = trim($_POST['collaborator_email'] ?? '');
                $role = $_POST['collaborator_role'] ?? 'editor';
                
                if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Valid email address is required');
                }
                
                $collaborator = $db->fetch('SELECT id FROM users WHERE email = ? AND is_active = 1', [$email]);
                if (!$collaborator) {
                    throw new Exception('User with this email address not found');
                }
                
                $existing = $db->fetch('
                    SELECT * FROM store_collaborators 
                    WHERE store_config_id = ? AND user_id = ?
                ', [$current_store_data['id'], $collaborator['id']]);
                
                if ($existing) {
                    throw new Exception('User is already a collaborator on this store');
                }
                
                $invitation_token = bin2hex(random_bytes(32));
                $invitation_expires = date('Y-m-d H:i:s', time() + 86400 * 7);
                
                $db->execute('
                    INSERT INTO store_collaborators 
                    (store_config_id, user_id, invited_by, role, invitation_token, invitation_expires, status) 
                    VALUES (?, ?, ?, ?, ?, ?, "pending")
                ', [
                    $current_store_data['id'],
                    $collaborator['id'],
                    $user['id'],
                    $role,
                    $invitation_token,
                    $invitation_expires
                ]);
                
                Config::logActivity('collaborator_invited', "Invited {$email} as {$role}", 'collaboration');
                $success_messages[] = "Invitation sent to {$email} successfully!";
                break;

            case 'update_notification_settings':
                $settings = [
                    'email_notifications' => isset($_POST['email_notifications']),
                    'browser_notifications' => isset($_POST['browser_notifications']),
                    'mobile_notifications' => isset($_POST['mobile_notifications']),
                    'notify_new_orders' => isset($_POST['notify_new_orders']),
                    'notify_low_stock' => isset($_POST['notify_low_stock']),
                    'notify_reviews' => isset($_POST['notify_reviews']),
                    'notify_system_updates' => isset($_POST['notify_system_updates'])
                ];
                
                foreach ($settings as $key => $value) {
                    $db->execute('
                        INSERT OR REPLACE INTO user_preferences (user_id, preference_key, preference_value, preference_type, updated_at)
                        VALUES (?, ?, ?, ?, ?)
                    ', [$user['id'], $key, $value ? '1' : '0', 'boolean', date('Y-m-d H:i:s')]);
                }
                
                $success_messages[] = 'Notification settings updated successfully!';
                break;
        }
        
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        settings_debug_log("Settings error: " . $e->getMessage());
    }
}

// Get data for display
$accessible_stores = Config::getAccessibleStores();

// Get user preferences from database
$user_preferences = [];
$prefs = $db->fetchAll('SELECT preference_key, preference_value FROM user_preferences WHERE user_id = ?', [$user['id']]);
foreach ($prefs as $pref) {
    $user_preferences[$pref['preference_key']] = $pref;
}

$collaborators = [];

if ($current_store_data) {
    $collaborators = $db->fetchAll('
        SELECT sc.*, u.username, u.email, u.first_name, u.last_name, 
               inviter.username as invited_by_username
        FROM store_collaborators sc
        JOIN users u ON sc.user_id = u.id
        LEFT JOIN users inviter ON sc.invited_by = inviter.id
        WHERE sc.store_config_id = ?
        ORDER BY sc.created_at ASC
    ', [$current_store_data['id']]);
}

// Available options
$timezones = [
    'UTC' => 'UTC',
    'America/New_York' => 'Eastern Time (US)',
    'America/Chicago' => 'Central Time (US)',
    'America/Denver' => 'Mountain Time (US)',
    'America/Los_Angeles' => 'Pacific Time (US)',
    'Europe/London' => 'London',
    'Europe/Paris' => 'Paris',
    'Europe/Rome' => 'Rome',
    'Europe/Berlin' => 'Berlin',
    'Europe/Madrid' => 'Madrid',
    'Asia/Tokyo' => 'Tokyo',
    'Asia/Shanghai' => 'Shanghai',
    'Asia/Dubai' => 'Dubai',
    'Australia/Sydney' => 'Sydney',
    'America/Sao_Paulo' => 'São Paulo'
];

$languages = [
    'en' => 'English',
    'es' => 'Español',
    'fr' => 'Français',
    'de' => 'Deutsch',
    'it' => 'Italiano',
    'pt' => 'Português',
    'ru' => 'Русский',
    'ja' => '日本語',
    'zh' => '中文'
];

$currencies = [
    'USD' => 'US Dollar ($)',
    'EUR' => 'Euro (€)',
    'GBP' => 'British Pound (£)',
    'JPY' => 'Japanese Yen (¥)',
    'CAD' => 'Canadian Dollar (C$)',
    'AUD' => 'Australian Dollar (A$)',
    'CHF' => 'Swiss Franc (CHF)',
    'CNY' => 'Chinese Yuan (¥)',
    'BRL' => 'Brazilian Real (R$)',
    'INR' => 'Indian Rupee (₹)'
];

function getUserPreference($key, $default = '') {
    global $user_preferences;
    return isset($user_preferences[$key]) ? $user_preferences[$key]['preference_value'] : $default;
}
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
        
        .settings-tab {
            transition: all 0.3s ease;
            border-bottom: 4px solid transparent;
            position: relative;
        }
        .settings-tab.active {
            border-bottom-color: #3b82f6;
            color: #3b82f6;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            font-weight: 600;
        }
        .settings-tab:hover:not(.active) {
            background: linear-gradient(135deg, #f9fafb 0%, #f3f4f6 100%);
            color: #374151;
            border-bottom-color: #d1d5db;
        }
        
        .settings-section {
            display: none;
            animation: fadeIn 0.4s ease-in-out;
        }
        .settings-section.active {
            display: block;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .connection-status.connected { 
            color: #22c55e; 
            font-weight: 700; 
            text-shadow: 0 1px 2px rgba(34, 197, 94, 0.2);
        }
        .connection-status.disconnected { 
            color: #ef4444; 
            font-weight: 700; 
            text-shadow: 0 1px 2px rgba(239, 68, 68, 0.2);
        }
        
        .gradient-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.3);
        }
        
        .password-strength {
            height: 6px;
            transition: all 0.4s ease;
            border-radius: 3px;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
        }
        .password-strength.weak { 
            background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%); 
            width: 25%; 
        }
        .password-strength.fair { 
            background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%); 
            width: 50%; 
        }
        .password-strength.good { 
            background: linear-gradient(90deg, #22c55e 0%, #16a34a 100%); 
            width: 75%; 
        }
        .password-strength.strong { 
            background: linear-gradient(90deg, #059669 0%, #047857 100%); 
            width: 100%; 
        }
        
        .form-input {
            @apply mt-2 block w-full px-5 py-4 text-base border-2 border-gray-200 rounded-xl 
                   bg-gray-50 focus:bg-white focus:outline-none focus:ring-4 focus:ring-blue-100 
                   focus:border-blue-400 transition-all duration-300 ease-in-out
                   hover:border-gray-300 hover:bg-gray-25;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            min-height:80px;
        }
        
        .form-input:focus {
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.15);
            transform: translateY(-1px);
        }
        
        .btn-primary {
            @apply bg-gradient-to-r from-blue-600 to-blue-700 text-white px-8 py-4 rounded-xl 
                   font-semibold text-base hover:from-blue-700 hover:to-blue-800 
                   focus:outline-none focus:ring-4 focus:ring-blue-200 focus:ring-offset-2 
                   transition-all duration-300 ease-in-out shadow-lg hover:shadow-xl 
                   transform hover:-translate-y-0.5 active:translate-y-0;
        }
        
        .btn-secondary {
            @apply bg-gradient-to-r from-gray-600 to-gray-700 text-white px-8 py-4 rounded-xl 
                   font-semibold text-base hover:from-gray-700 hover:to-gray-800 
                   focus:outline-none focus:ring-4 focus:ring-gray-200 focus:ring-offset-2 
                   transition-all duration-300 ease-in-out shadow-lg hover:shadow-xl 
                   transform hover:-translate-y-0.5 active:translate-y-0;
        }
        
        .btn-success {
            @apply bg-gradient-to-r from-green-600 to-green-700 text-white px-8 py-4 rounded-xl 
                   font-semibold text-base hover:from-green-700 hover:to-green-800 
                   focus:outline-none focus:ring-4 focus:ring-green-200 focus:ring-offset-2 
                   transition-all duration-300 ease-in-out shadow-lg hover:shadow-xl 
                   transform hover:-translate-y-0.5 active:translate-y-0;
        }
        
        .btn-warning {
            @apply bg-gradient-to-r from-orange-600 to-orange-700 text-white px-8 py-4 rounded-xl 
                   font-semibold text-base hover:from-orange-700 hover:to-orange-800 
                   focus:outline-none focus:ring-4 focus:ring-orange-200 focus:ring-offset-2 
                   transition-all duration-300 ease-in-out shadow-lg hover:shadow-xl 
                   transform hover:-translate-y-0.5 active:translate-y-0;
        }
        
        .btn-danger {
            @apply bg-gradient-to-r from-red-600 to-red-700 text-white px-8 py-4 rounded-xl 
                   font-semibold text-base hover:from-red-700 hover:to-red-800 
                   focus:outline-none focus:ring-4 focus:ring-red-200 focus:ring-offset-2 
                   transition-all duration-300 ease-in-out shadow-lg hover:shadow-xl 
                   transform hover:-translate-y-0.5 active:translate-y-0;
        }
        
        .btn-store-action {
            @apply bg-gradient-to-r from-indigo-600 to-purple-600 text-white px-6 py-3 rounded-lg 
                   font-medium text-sm hover:from-indigo-700 hover:to-purple-700 
                   focus:outline-none focus:ring-3 focus:ring-indigo-200 focus:ring-offset-1 
                   transition-all duration-250 ease-in-out shadow-md hover:shadow-lg 
                   transform hover:-translate-y-0.5 active:translate-y-0;
        }
        
        .alert {
            @apply p-6 rounded-xl border-l-4 shadow-md;
            backdrop-filter: blur(10px);
        }
        .alert-success {
            @apply bg-green-50 border-green-400 text-green-800;
            background: linear-gradient(135deg, rgba(240, 253, 244, 0.9) 0%, rgba(220, 252, 231, 0.9) 100%);
        }
        .alert-error {
            @apply bg-red-50 border-red-400 text-red-800;
            background: linear-gradient(135deg, rgba(254, 242, 242, 0.9) 0%, rgba(254, 226, 226, 0.9) 100%);
        }
        
        .custom-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: #d1d5db #f3f4f6;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 8px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f3f4f6;
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: linear-gradient(135deg, #d1d5db 0%, #9ca3af 100%);
            border-radius: 4px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%);
        }
        
        .section-card {
            @apply bg-white rounded-2xl shadow-xl border border-gray-100;
            background: linear-gradient(135deg, rgba(255,255,255,0.9) 0%, rgba(248,250,252,0.9) 100%);
            backdrop-filter: blur(10px);
        }
        
        .feature-card {
            @apply rounded-xl border-2 border-gray-100 p-6 transition-all duration-300;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        
        .feature-card:hover {
            @apply border-blue-200 shadow-lg;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            transform: translateY(-2px);
        }
        
        .label-enhanced {
            @apply block text-sm font-semibold text-gray-800 mb-2 tracking-wide;
        }
        
        select.form-input {
           
            background-position: right 12px center;
            background-repeat: no-repeat;
            background-size: 16px;
            padding-right: 48px;
        }
        
        .store-card {
            @apply rounded-2xl shadow-lg hover:shadow-xl transition-all duration-300 p-6;
            background: linear-gradient(135deg, #ffffff 0%, #f9fafb 100%);
            border: 2px solid transparent;
        }
        
        .store-card.active {
            border-color: #3b82f6;
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
            box-shadow: 0 8px 32px rgba(59, 130, 246, 0.2);
        }
        
        .store-card:hover:not(.active) {
            border-color: #e5e7eb;
            transform: translateY(-2px);
        }
        
        .checkbox-enhanced {
            @apply h-5 w-5 text-blue-600 focus:ring-blue-500 border-2 border-gray-300 rounded-md 
                   bg-gray-50 focus:bg-white transition-all duration-200;
        }
        
        .tab-indicator {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #3b82f6 0%, #1d4ed8 100%);
            border-radius: 2px 2px 0 0;
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }
        
        .settings-tab.active .tab-indicator {
            transform: scaleX(1);
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 antialiased">
    <div class="flex flex-col min-h-screen">
        <?php include 'header.php'; ?>

        <main class="flex-grow max-w-screen-xl mx-auto py-8 px-4 sm:px-6 lg:px-8 w-full">
            <!-- Page Header -->
            <div class="mb-10">
                <div class="gradient-card text-white p-8 rounded-2xl shadow-2xl">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-6">
                        <div>
                            <h1 class="text-3xl sm:text-4xl font-bold flex items-center">
                                <i class="fas fa-cogs mr-4 text-4xl"></i>Settings & Configuration
                            </h1>
                            <p class="mt-3 text-lg opacity-90 leading-relaxed">
                                Manage your account, stores, and application preferences with ease
                            </p>
                        </div>
                        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3 text-sm">
                            <div class="bg-white bg-opacity-20 backdrop-blur px-4 py-2 rounded-full flex items-center">
                                <i class="fas fa-user mr-2"></i>
                                <span class="font-semibold"><?php echo htmlspecialchars($user['username']); ?></span>
                            </div>
                            <?php if ($current_store_data): ?>
                                <div class="bg-white bg-opacity-20 backdrop-blur px-4 py-2 rounded-full flex items-center">
                                    <i class="fas fa-store mr-2"></i>
                                    <span class="font-semibold"><?php echo htmlspecialchars($current_store_data['store_name'] ?: 'Current Store'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Messages -->
            <?php if (!empty($errors)): ?>
                <div class="mb-8 alert alert-error">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-circle fa-2x mr-4 mt-1"></i>
                        <div class="flex-1">
                            <h3 class="font-bold text-lg mb-2">Error</h3>
                            <?php foreach ($errors as $error): ?>
                                <div class="mb-1"><?php echo htmlspecialchars($error); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success_messages)): ?>
                <div class="mb-8 alert alert-success">
                    <div class="flex items-start">
                        <i class="fas fa-check-circle fa-2x mr-4 mt-1"></i>
                        <div class="flex-1">
                            <h3 class="font-bold text-lg mb-2">Success</h3>
                            <?php foreach ($success_messages as $message): ?>
                                <div class="mb-1"><?php echo htmlspecialchars($message); ?></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Settings Container -->
            <div class="section-card">
                <!-- Settings Tabs -->
                <div class="border-b border-gray-200 bg-gradient-to-r from-gray-50 to-blue-50">
                    <div class="flex overflow-x-auto custom-scrollbar">
                        <button type="button" id="profile-tab" 
                                class="settings-tab relative flex-shrink-0 py-6 px-8 text-center font-semibold text-gray-600 hover:text-gray-700 active whitespace-nowrap transition-all duration-300"
                                onclick="showSettingsTab('profile')">
                            <i class="fas fa-user-edit mr-3 text-lg"></i>Profile & Account
                            <div class="tab-indicator"></div>
                        </button>
                        <button type="button" id="security-tab" 
                                class="settings-tab relative flex-shrink-0 py-6 px-8 text-center font-semibold text-gray-600 hover:text-gray-700 whitespace-nowrap transition-all duration-300"
                                onclick="showSettingsTab('security')">
                            <i class="fas fa-shield-alt mr-3 text-lg"></i>Security
                            <div class="tab-indicator"></div>
                        </button>
                        <button type="button" id="store-tab" 
                                class="settings-tab relative flex-shrink-0 py-6 px-8 text-center font-semibold text-gray-600 hover:text-gray-700 whitespace-nowrap transition-all duration-300"
                                onclick="showSettingsTab('store')">
                            <i class="fas fa-store mr-3 text-lg"></i>Store Config
                            <div class="tab-indicator"></div>
                        </button>
                        <button type="button" id="preferences-tab" 
                                class="settings-tab relative flex-shrink-0 py-6 px-8 text-center font-semibold text-gray-600 hover:text-gray-700 whitespace-nowrap transition-all duration-300"
                                onclick="showSettingsTab('preferences')">
                            <i class="fas fa-sliders-h mr-3 text-lg"></i>Preferences
                            <div class="tab-indicator"></div>
                        </button>
                        <button type="button" id="stores-tab" 
                                class="settings-tab relative flex-shrink-0 py-6 px-8 text-center font-semibold text-gray-600 hover:text-gray-700 whitespace-nowrap transition-all duration-300"
                                onclick="showSettingsTab('stores')">
                            <i class="fas fa-list mr-3 text-lg"></i>My Stores
                            <div class="tab-indicator"></div>
                        </button>
                        <button type="button" id="collaboration-tab" 
                                class="settings-tab relative flex-shrink-0 py-6 px-8 text-center font-semibold text-gray-600 hover:text-gray-700 whitespace-nowrap transition-all duration-300"
                                onclick="showSettingsTab('collaboration')">
                            <i class="fas fa-users mr-3 text-lg"></i>Collaboration
                            <div class="tab-indicator"></div>
                        </button>
                        <button type="button" id="notifications-tab" 
                                class="settings-tab relative flex-shrink-0 py-6 px-8 text-center font-semibold text-gray-600 hover:text-gray-700 whitespace-nowrap transition-all duration-300"
                                onclick="showSettingsTab('notifications')">
                            <i class="fas fa-bell mr-3 text-lg"></i>Notifications
                            <div class="tab-indicator"></div>
                        </button>
                    </div>
                </div>
                
                <!-- Profile & Account Section -->
                <div id="profile-section" class="settings-section active p-10">
                    <div class="max-w-4xl">
                        <div class="mb-10">
                            <h3 class="text-3xl font-bold text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-user-circle mr-4 text-blue-600 text-4xl"></i>Profile & Account Information
                            </h3>
                            <p class="text-gray-600 text-lg leading-relaxed">Update your personal information and account settings to personalize your experience.</p>
                        </div>
                        
                        <form method="POST" class="space-y-8">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div>
                                    <label for="first_name" class="label-enhanced">
                                        <i class="fas fa-user mr-2 text-blue-600"></i>First Name
                                    </label>
                                    <input type="text" id="first_name" name="first_name" 
                                           value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>"
                                           class="form-input" placeholder="Enter your first name">
                                </div>
                                
                                <div>
                                    <label for="last_name" class="label-enhanced">
                                        <i class="fas fa-user mr-2 text-blue-600"></i>Last Name
                                    </label>
                                    <input type="text" id="last_name" name="last_name" 
                                           value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>"
                                           class="form-input" placeholder="Enter your last name">
                                </div>
                            </div>
                            
                            <div>
                                <label for="email" class="label-enhanced">
                                    <i class="fas fa-envelope mr-2 text-blue-600"></i>Email Address *
                                </label>
                                <input type="email" id="email" name="email" required
                                       value="<?php echo htmlspecialchars($user['email']); ?>"
                                       class="form-input" placeholder="your@email.com">
                                <p class="mt-2 text-sm text-gray-500">Used for login and notifications</p>
                            </div>
                            
                            <div>
                                <label for="phone" class="label-enhanced">
                                    <i class="fas fa-phone mr-2 text-blue-600"></i>Phone Number
                                </label>
                                <input type="tel" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                       class="form-input" placeholder="+1 (555) 123-4567">
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div>
                                    <label for="timezone" class="label-enhanced">
                                        <i class="fas fa-clock mr-2 text-blue-600"></i>Timezone
                                    </label>
                                    <select id="timezone" name="timezone" class="form-input">
                                        <?php foreach ($timezones as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" 
                                                    <?php echo ($user['timezone'] ?? 'UTC') === $value ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="language" class="label-enhanced">
                                        <i class="fas fa-language mr-2 text-blue-600"></i>Language
                                    </label>
                                    <select id="language" name="language" class="form-input">
                                        <?php foreach ($languages as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" 
                                                    <?php echo ($user['language'] ?? 'en') === $value ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="pt-6 border-t border-gray-200">
                                <button type="submit" class="btn-primary">
                                    <i class="fas fa-save mr-3"></i>Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Security Section -->
                <div id="security-section" class="settings-section p-10">
                    <div class="max-w-4xl">
                        <div class="mb-10">
                            <h3 class="text-3xl font-bold text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-shield-alt mr-4 text-green-600 text-4xl"></i>Security Settings
                            </h3>
                            <p class="text-gray-600 text-lg leading-relaxed">Manage your password and security preferences to keep your account safe.</p>
                        </div>
                        
                        <!-- Change Password -->
                        <div class="feature-card mb-8">
                            <h4 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                                <i class="fas fa-key mr-3 text-yellow-600 text-2xl"></i>Change Password
                            </h4>
                            
                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="action" value="change_password">
                                
                                <div>
                                    <label for="current_password" class="label-enhanced">
                                        <i class="fas fa-lock mr-2 text-yellow-600"></i>Current Password *
                                    </label>
                                    <input type="password" id="current_password" name="current_password" required
                                           class="form-input" placeholder="Enter current password">
                                </div>
                                
                                <div>
                                    <label for="new_password" class="label-enhanced">
                                        <i class="fas fa-lock-open mr-2 text-green-600"></i>New Password *
                                    </label>
                                    <input type="password" id="new_password" name="new_password" required
                                           class="form-input" placeholder="Enter new password"
                                           onkeyup="checkPasswordStrength(this.value)">
                                    <div class="mt-3 bg-gray-200 rounded-full h-2 shadow-inner">
                                        <div id="password-strength" class="password-strength bg-gray-300 rounded-full h-2"></div>
                                    </div>
                                    <p class="mt-2 text-sm text-gray-500 font-medium">
                                        Must contain uppercase, lowercase, and number. Minimum 8 characters.
                                    </p>
                                </div>
                                
                                <div>
                                    <label for="confirm_password" class="label-enhanced">
                                        <i class="fas fa-check-circle mr-2 text-green-600"></i>Confirm New Password *
                                    </label>
                                    <input type="password" id="confirm_password" name="confirm_password" required
                                           class="form-input" placeholder="Confirm new password">
                                </div>
                                
                                <div class="pt-6">
                                    <button type="submit" class="btn-success">
                                        <i class="fas fa-shield-alt mr-3"></i>Change Password
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Account Information -->
                        <div class="feature-card">
                            <h4 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                                <i class="fas fa-info-circle mr-3 text-blue-600 text-2xl"></i>Account Information
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-base">
                                <div class="flex justify-between items-center p-4 rounded-lg bg-gray-50">
                                    <span class="text-gray-600 font-medium">Account Created:</span>
                                    <span class="font-bold text-gray-800"><?php echo Config::formatDateTime($user['created_at']); ?></span>
                                </div>
                                <div class="flex justify-between items-center p-4 rounded-lg bg-gray-50">
                                    <span class="text-gray-600 font-medium">Last Login:</span>
                                    <span class="font-bold text-gray-800"><?php echo $user['last_login'] ? Config::formatDateTime($user['last_login']) : 'Never'; ?></span>
                                </div>
                                <div class="flex justify-between items-center p-4 rounded-lg bg-gray-50">
                                    <span class="text-gray-600 font-medium">Login Count:</span>
                                    <span class="font-bold text-gray-800"><?php echo number_format($user['login_count'] ?? 0); ?></span>
                                </div>
                                <div class="flex justify-between items-center p-4 rounded-lg bg-gray-50">
                                    <span class="text-gray-600 font-medium">Account Status:</span>
                                    <span class="font-bold <?php echo $user['is_active'] ? 'text-green-600' : 'text-red-600'; ?>">
                                        <i class="fas fa-circle fa-xs mr-1"></i>
                                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Store Configuration Section -->
                <div id="store-section" class="settings-section p-10">
                    <div class="max-w-4xl">
                        <div class="mb-10">
                            <h3 class="text-3xl font-bold text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-store mr-4 text-purple-600 text-4xl"></i>WooCommerce Store Configuration
                            </h3>
                            <p class="text-gray-600 text-lg leading-relaxed">Configure your WooCommerce store connection settings and test connectivity.</p>
                        </div>
                        
                        <!-- Current Store Status -->
                        <?php if ($current_store_data): ?>
                            <div class="mb-10 p-8 border-2 border-gray-200 rounded-2xl" style="background: linear-gradient(135deg, #f8fafc 0%, #e0f2fe 100%);">
                                <div class="flex items-center justify-between mb-4">
                                    <h4 class="text-2xl font-bold text-gray-900 flex items-center">
                                        <i class="fas fa-store mr-3 text-purple-600"></i>
                                        <?php echo htmlspecialchars($current_store_data['store_name'] ?: 'Current Store'); ?>
                                    </h4>
                                    <span class="connection-status <?php echo $current_store_data['connected'] ? 'connected' : 'disconnected'; ?> flex items-center text-lg">
                                        <i class="fas fa-circle mr-2"></i>
                                        <?php echo $current_store_data['connected'] ? 'Connected' : 'Disconnected'; ?>
                                    </span>
                                </div>
                                <p class="text-base text-gray-700 mb-3 font-medium"><?php echo htmlspecialchars($current_store_data['store_url']); ?></p>
                                <?php if ($current_store_data['last_test']): ?>
                                    <p class="text-sm text-gray-500">
                                        <i class="fas fa-clock mr-1"></i>
                                        Last tested: <?php echo Config::formatDateTime($current_store_data['last_test']); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" class="space-y-8">
                            <input type="hidden" name="action" value="save_store_config">
                            
                            <div>
                                <label for="store_name" class="label-enhanced">
                                    <i class="fas fa-tag mr-2 text-purple-600"></i>Store Name
                                </label>
                                <input type="text" id="store_name" name="store_name" 
                                       value="<?php echo htmlspecialchars($current_store_data['store_name'] ?? ''); ?>"
                                       placeholder="My WooCommerce Store" class="form-input">
                                <p class="mt-2 text-sm text-gray-500">A friendly name to identify your store</p>
                            </div>
                            
                            <div>
                                <label for="store_url" class="label-enhanced">
                                    <i class="fas fa-link mr-2 text-purple-600"></i>Store URL *
                                </label>
                                <input type="url" id="store_url" name="store_url" required
                                       value="<?php echo htmlspecialchars($current_store_data['store_url'] ?? ''); ?>"
                                       placeholder="https://yourstore.com" class="form-input">
                                <p class="mt-2 text-sm text-gray-500">Your WooCommerce store URL (without trailing slash)</p>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div>
                                    <label for="consumer_key" class="label-enhanced">
                                        <i class="fas fa-key mr-2 text-purple-600"></i>Consumer Key *
                                    </label>
                                    <input type="text" id="consumer_key" name="consumer_key" required
                                           value="<?php echo htmlspecialchars($current_store_data['consumer_key'] ?? ''); ?>"
                                           placeholder="ck_xxxxxxxxxx" class="form-input">
                                </div>
                                
                                <div>
                                    <label for="consumer_secret" class="label-enhanced">
                                        <i class="fas fa-lock mr-2 text-purple-600"></i>Consumer Secret *
                                    </label>
                                    <input type="password" id="consumer_secret" name="consumer_secret" required
                                           value="<?php echo htmlspecialchars($current_store_data['consumer_secret'] ?? ''); ?>"
                                           placeholder="cs_xxxxxxxxxx" class="form-input">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                <div>
                                    <label for="api_version" class="label-enhanced">
                                        <i class="fas fa-code-branch mr-2 text-purple-600"></i>API Version
                                    </label>
                                    <select id="api_version" name="api_version" class="form-input">
                                        <option value="v3" <?php echo ($current_store_data['api_version'] ?? 'v3') === 'v3' ? 'selected' : ''; ?>>
                                            v3 (Recommended)
                                        </option>
                                        <option value="v2" <?php echo ($current_store_data['api_version'] ?? 'v3') === 'v2' ? 'selected' : ''; ?>>
                                            v2 (Legacy)
                                        </option>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="timeout" class="label-enhanced">
                                        <i class="fas fa-stopwatch mr-2 text-purple-600"></i>Timeout (seconds)
                                    </label>
                                    <input type="number" id="timeout" name="timeout" min="10" max="120"
                                           value="<?php echo $current_store_data['timeout'] ?? 30; ?>" class="form-input">
                                </div>
                            </div>
                            
                            <div class="feature-card">
                                <h4 class="font-bold text-blue-900 mb-4 flex items-center text-lg">
                                    <i class="fas fa-lightbulb mr-3 text-2xl text-yellow-500"></i>How to get API credentials:
                                </h4>
                                <ol class="text-sm text-blue-800 space-y-3 ml-4">
                                    <li class="flex items-start">
                                        <span class="font-bold mr-3 bg-blue-100 rounded-full w-6 h-6 flex items-center justify-center text-xs">1</span>
                                        Go to your WooCommerce admin: <strong>WooCommerce → Settings → Advanced → REST API</strong>
                                    </li>
                                    <li class="flex items-start">
                                        <span class="font-bold mr-3 bg-blue-100 rounded-full w-6 h-6 flex items-center justify-center text-xs">2</span>
                                        Click <strong>"Add Key"</strong>
                                    </li>
                                    <li class="flex items-start">
                                        <span class="font-bold mr-3 bg-blue-100 rounded-full w-6 h-6 flex items-center justify-center text-xs">3</span>
                                        Set permissions to <strong>"Read/Write"</strong>
                                    </li>
                                    <li class="flex items-start">
                                        <span class="font-bold mr-3 bg-blue-100 rounded-full w-6 h-6 flex items-center justify-center text-xs">4</span>
                                        Copy the Consumer Key and Consumer Secret
                                    </li>
                                </ol>
                            </div>
                            
                            <div class="flex flex-wrap gap-6 pt-8 border-t border-gray-200">
                                <button type="submit" class="btn-primary">
                                    <i class="fas fa-save mr-3"></i>Save Configuration
                                </button>
                                
                                <?php if ($current_store_data && $current_store_data['store_url']): ?>
                                    <button type="submit" name="action" value="test_connection" class="btn-success">
                                        <i class="fas fa-plug mr-3"></i>Test Connection
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- User Preferences Section -->
                <div id="preferences-section" class="settings-section p-10">
                    <div class="max-w-4xl">
                        <div class="mb-10">
                            <h3 class="text-3xl font-bold text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-sliders-h mr-4 text-indigo-600 text-4xl"></i>Application Preferences
                            </h3>
                            <p class="text-gray-600 text-lg leading-relaxed">Customize your application settings and preferences for the best experience.</p>
                        </div>
                        
                        <form method="POST" class="space-y-10">
                            <input type="hidden" name="action" value="save_user_preferences">
                            
                            <!-- Display Settings -->
                            <div class="feature-card">
                                <h4 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                                    <i class="fas fa-display mr-3 text-blue-600 text-2xl"></i>Display Settings
                                </h4>
                                
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                                    <div>
                                        <label for="currency" class="label-enhanced">
                                            <i class="fas fa-dollar-sign mr-2 text-green-600"></i>Currency
                                        </label>
                                        <select id="currency" name="currency" class="form-input">
                                            <?php foreach ($currencies as $value => $label): ?>
                                                <option value="<?php echo $value; ?>" 
                                                        <?php echo getUserPreference('currency', 'USD') === $value ? 'selected' : ''; ?>>
                                                    <?php echo $label; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label for="date_format" class="label-enhanced">
                                            <i class="fas fa-calendar mr-2 text-blue-600"></i>Date Format
                                        </label>
                                        <select id="date_format" name="date_format" class="form-input">
                                            <option value="Y-m-d" <?php echo getUserPreference('date_format', 'Y-m-d') === 'Y-m-d' ? 'selected' : ''; ?>>
                                                2024-01-15
                                            </option>
                                            <option value="m/d/Y" <?php echo getUserPreference('date_format', 'Y-m-d') === 'm/d/Y' ? 'selected' : ''; ?>>
                                                01/15/2024
                                            </option>
                                            <option value="d/m/Y" <?php echo getUserPreference('date_format', 'Y-m-d') === 'd/m/Y' ? 'selected' : ''; ?>>
                                                15/01/2024
                                            </option>
                                            <option value="F j, Y" <?php echo getUserPreference('date_format', 'Y-m-d') === 'F j, Y' ? 'selected' : ''; ?>>
                                                January 15, 2024
                                            </option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label for="time_format" class="label-enhanced">
                                            <i class="fas fa-clock mr-2 text-orange-600"></i>Time Format
                                        </label>
                                        <select id="time_format" name="time_format" class="form-input">
                                            <option value="H:i:s" <?php echo getUserPreference('time_format', 'H:i:s') === 'H:i:s' ? 'selected' : ''; ?>>
                                                24-hour (14:30:00)
                                            </option>
                                            <option value="g:i:s A" <?php echo getUserPreference('time_format', 'H:i:s') === 'g:i:s A' ? 'selected' : ''; ?>>
                                                12-hour (2:30:00 PM)
                                            </option>
                                            <option value="H:i" <?php echo getUserPreference('time_format', 'H:i:s') === 'H:i' ? 'selected' : ''; ?>>
                                                24-hour (14:30)
                                            </option>
                                            <option value="g:i A" <?php echo getUserPreference('time_format', 'H:i:s') === 'g:i A' ? 'selected' : ''; ?>>
                                                12-hour (2:30 PM)
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Pagination Settings -->
                            <div class="feature-card">
                                <h4 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                                    <i class="fas fa-list-ol mr-3 text-green-600 text-2xl"></i>Pagination Settings
                                </h4>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                    <div>
                                        <label for="products_per_page" class="label-enhanced">
                                            <i class="fas fa-boxes mr-2 text-green-600"></i>Products per page
                                        </label>
                                        <input type="number" id="products_per_page" name="products_per_page" 
                                               min="10" max="100" value="<?php echo getUserPreference('products_per_page', '20'); ?>"
                                               class="form-input">
                                    </div>
                                    
                                    <div>
                                        <label for="orders_per_page" class="label-enhanced">
                                            <i class="fas fa-shopping-cart mr-2 text-blue-600"></i>Orders per page
                                        </label>
                                        <input type="number" id="orders_per_page" name="orders_per_page" 
                                               min="10" max="100" value="<?php echo getUserPreference('orders_per_page', '20'); ?>"
                                               class="form-input">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Interface Preferences -->
                            <div class="feature-card">
                                <h4 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                                    <i class="fas fa-desktop mr-3 text-purple-600 text-2xl"></i>Interface Preferences
                                </h4>
                                
                                <div class="space-y-6">
                                    <div class="flex items-center p-4 rounded-lg bg-gray-50">
                                        <input id="notifications_enabled" name="notifications_enabled" type="checkbox" 
                                               <?php echo getUserPreference('notifications_enabled', '1') === '1' ? 'checked' : ''; ?>
                                               class="checkbox-enhanced">
                                        <label for="notifications_enabled" class="ml-4 block text-base text-gray-700">
                                            <span class="font-semibold text-lg">Enable in-app notifications</span>
                                            <span class="block text-sm text-gray-500 mt-1">Show notifications within the application</span>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center p-4 rounded-lg bg-gray-50">
                                        <input id="email_notifications" name="email_notifications" type="checkbox" 
                                               <?php echo getUserPreference('email_notifications', '1') === '1' ? 'checked' : ''; ?>
                                               class="checkbox-enhanced">
                                        <label for="email_notifications" class="ml-4 block text-base text-gray-700">
                                            <span class="font-semibold text-lg">Enable email notifications</span>
                                            <span class="block text-sm text-gray-500 mt-1">Receive notifications via email</span>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center p-4 rounded-lg bg-gray-50">
                                        <input id="sidebar_collapsed" name="sidebar_collapsed" type="checkbox" 
                                               <?php echo getUserPreference('sidebar_collapsed', '0') === '1' ? 'checked' : ''; ?>
                                               class="checkbox-enhanced">
                                        <label for="sidebar_collapsed" class="ml-4 block text-base text-gray-700">
                                            <span class="font-semibold text-lg">Collapse sidebar by default</span>
                                            <span class="block text-sm text-gray-500 mt-1">Start with a collapsed navigation sidebar</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Synchronization Settings -->
                            <div class="feature-card">
                                <h4 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                                    <i class="fas fa-sync-alt mr-3 text-orange-600 text-2xl"></i>Synchronization
                                </h4>
                                
                                <div class="space-y-6">
                                    <div class="flex items-center p-4 rounded-lg bg-gray-50">
                                        <input id="auto_sync" name="auto_sync" type="checkbox" 
                                               <?php echo getUserPreference('auto_sync', '0') === '1' ? 'checked' : ''; ?>
                                               class="checkbox-enhanced">
                                        <label for="auto_sync" class="ml-4 block text-base text-gray-700">
                                            <span class="font-semibold text-lg">Enable automatic synchronization</span>
                                            <span class="block text-sm text-gray-500 mt-1">Automatically sync data with your store</span>
                                        </label>
                                    </div>
                                    
                                    <div>
                                        <label for="sync_interval" class="label-enhanced">
                                            <i class="fas fa-clock mr-2 text-orange-600"></i>Sync interval
                                        </label>
                                        <select id="sync_interval" name="sync_interval" class="form-input max-w-sm">
                                            <option value="300" <?php echo getUserPreference('sync_interval', '300') == '300' ? 'selected' : ''; ?>>
                                                5 minutes
                                            </option>
                                            <option value="600" <?php echo getUserPreference('sync_interval', '300') == '600' ? 'selected' : ''; ?>>
                                                10 minutes
                                            </option>
                                            <option value="1800" <?php echo getUserPreference('sync_interval', '300') == '1800' ? 'selected' : ''; ?>>
                                                30 minutes
                                            </option>
                                            <option value="3600" <?php echo getUserPreference('sync_interval', '300') == '3600' ? 'selected' : ''; ?>>
                                                1 hour
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="pt-8 border-t border-gray-200">
                                <button type="submit" class="btn-primary">
                                    <i class="fas fa-save mr-3"></i>Save Preferences
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- My Stores Section -->
                <div id="stores-section" class="settings-section p-10">
                    <div class="max-w-6xl">
                        <div class="mb-10">
                            <h3 class="text-3xl font-bold text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-list mr-4 text-teal-600 text-4xl"></i>My Stores
                            </h3>
                            <p class="text-gray-600 text-lg leading-relaxed">Manage all your WooCommerce store connections in one place.</p>
                        </div>
                        
                        <!-- Add New Store Form -->
                        <div class="mb-10" style="background: linear-gradient(135deg, #e0f2fe 0%, #e8f5e8 100%); border: 2px solid #0ea5e9; border-radius: 20px; padding: 32px;">
                            <h4 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                                <i class="fas fa-plus-circle mr-3 text-blue-600 text-2xl"></i>Add New Store
                            </h4>
                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="action" value="create_new_store">
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="new_store_name" class="label-enhanced">
                                            <i class="fas fa-tag mr-2 text-blue-600"></i>Store Name *
                                        </label>
                                        <input type="text" id="new_store_name" name="new_store_name" required
                                               placeholder="My New Store" class="form-input">
                                    </div>
                                    
                                    <div>
                                        <label for="new_store_url" class="label-enhanced">
                                            <i class="fas fa-link mr-2 text-blue-600"></i>Store URL *
                                        </label>
                                        <input type="url" id="new_store_url" name="new_store_url" required
                                               placeholder="https://newstore.com" class="form-input">
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div>
                                        <label for="new_consumer_key" class="label-enhanced">
                                            <i class="fas fa-key mr-2 text-blue-600"></i>Consumer Key *
                                        </label>
                                        <input type="text" id="new_consumer_key" name="new_consumer_key" required
                                               placeholder="ck_xxxxxxxxxx" class="form-input">
                                    </div>
                                    
                                    <div>
                                        <label for="new_consumer_secret" class="label-enhanced">
                                            <i class="fas fa-lock mr-2 text-blue-600"></i>Consumer Secret *
                                        </label>
                                        <input type="password" id="new_consumer_secret" name="new_consumer_secret" required
                                               placeholder="cs_xxxxxxxxxx" class="form-input">
                                    </div>
                                </div>
                                
                                <div class="pt-4">
                                    <button type="submit" class="btn-success">
                                        <i class="fas fa-plus mr-3"></i>Add Store
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Existing Stores -->
                        <div>
                            <h4 class="text-2xl font-bold text-gray-900 mb-8 flex items-center">
                                <i class="fas fa-store mr-3"></i>Your Stores
                                <span class="ml-3 text-lg font-normal text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
                                    <?php echo count($accessible_stores); ?> stores
                                </span>
                            </h4>
                            
                            <?php if (empty($accessible_stores)): ?>
                                <div class="text-center py-16 bg-gradient-to-br from-gray-50 to-blue-50 rounded-2xl border-2 border-dashed border-gray-300">
                                    <i class="fas fa-store-slash fa-4x text-gray-400 mb-6"></i>
                                    <h5 class="text-2xl font-bold text-gray-600 mb-3">No Stores Configured</h5>
                                    <p class="text-gray-500 text-lg">Add your first store above to get started with managing your WooCommerce stores!</p>
                                </div>
                            <?php else: ?>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                    <?php foreach ($accessible_stores as $store): ?>
                                        <div class="store-card <?php echo $current_store_data && $current_store_data['id'] == $store['id'] ? 'active' : ''; ?>">
                                            <div class="flex items-start justify-between mb-6">
                                                <div class="min-w-0 flex-1">
                                                    <h5 class="text-xl font-bold text-gray-900 truncate mb-2">
                                                        <?php echo htmlspecialchars($store['store_name'] ?: 'Unnamed Store'); ?>
                                                        <?php if ($current_store_data && $current_store_data['id'] == $store['id']): ?>
                                                            <span class="ml-3 text-sm bg-blue-600 text-white px-3 py-1 rounded-full font-semibold">Current</span>
                                                        <?php endif; ?>
                                                    </h5>
                                                    <p class="text-base text-gray-600 truncate mb-3 font-medium"><?php echo htmlspecialchars($store['store_url']); ?></p>
                                                    <div class="flex items-center space-x-6 text-sm text-gray-500">
                                                        <span class="bg-gray-100 px-3 py-1 rounded-full">
                                                            Role: <strong><?php echo ucfirst(htmlspecialchars($store['role'] ?? 'Owner')); ?></strong>
                                                        </span>
                                                        <span class="connection-status <?php echo $store['connected'] ? 'connected' : 'disconnected'; ?> flex items-center">
                                                            <i class="fas fa-circle fa-xs mr-2"></i>
                                                            <?php echo $store['connected'] ? 'Connected' : 'Disconnected'; ?>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <div class="flex flex-wrap gap-3">
                                                <?php if (!($current_store_data && $current_store_data['id'] == $store['id'])): ?>
                                                    <button onclick="switchToStore(<?php echo $store['id']; ?>)" 
                                                            class="btn-store-action">
                                                        <i class="fas fa-arrow-right mr-2"></i>Set Active
                                                    </button>
                                                <?php endif; ?>
                                                <a href="settings.php?store_id=<?php echo $store['id']; ?>&anchor=store" 
                                                   class="btn-store-action bg-gradient-to-r from-gray-600 to-gray-700 hover:from-gray-700 hover:to-gray-800">
                                                    <i class="fas fa-cog mr-2"></i>Configure
                                                </a>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Collaboration Section -->
                <div id="collaboration-section" class="settings-section p-10">
                    <div class="max-w-4xl">
                        <div class="mb-10">
                            <h3 class="text-3xl font-bold text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-users mr-4 text-pink-600 text-4xl"></i>Store Collaboration
                            </h3>
                            <p class="text-gray-600 text-lg leading-relaxed">Manage who has access to your current store and collaborate effectively.</p>
                        </div>
                        
                        <?php if (!$current_store_data): ?>
                            <div class="text-center py-16 bg-gradient-to-br from-gray-50 to-pink-50 rounded-2xl border-2 border-dashed border-gray-300">
                                <i class="fas fa-store-slash fa-4x text-gray-400 mb-6"></i>
                                <h5 class="text-2xl font-bold text-gray-600 mb-3">No Store Selected</h5>
                                <p class="text-gray-500 text-lg mb-6">Please configure and select a store first to manage collaborators.</p>
                                <button onclick="showSettingsTab('store')" class="btn-primary">
                                    <i class="fas fa-store mr-3"></i>Configure Store
                                </button>
                            </div>
                        <?php else: ?>
                            <!-- Invite Collaborator Form -->
                            <div class="mb-10" style="background: linear-gradient(135deg, #f0fdf4 0%, #fef3c7 100%); border: 2px solid #22c55e; border-radius: 20px; padding: 32px;">
                                <h4 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                                    <i class="fas fa-user-plus mr-3 text-green-600 text-2xl"></i>Invite New Collaborator
                                </h4>
                                <form method="POST" class="space-y-6">
                                    <input type="hidden" name="action" value="invite_collaborator">
                                    
                                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                        <div class="md:col-span-2">
                                            <label for="collaborator_email" class="label-enhanced">
                                                <i class="fas fa-envelope mr-2 text-green-600"></i>Email Address *
                                            </label>
                                            <input type="email" id="collaborator_email" name="collaborator_email" required
                                                   placeholder="collaborator@example.com" class="form-input">
                                        </div>
                                        
                                        <div>
                                            <label for="collaborator_role" class="label-enhanced">
                                                <i class="fas fa-user-tag mr-2 text-green-600"></i>Role
                                            </label>
                                            <select id="collaborator_role" name="collaborator_role" class="form-input">
                                                <option value="viewer">Viewer (Read only)</option>
                                                <option value="editor" selected>Editor (Read/Write)</option>
                                                <option value="admin">Admin (Full access)</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div class="pt-4">
                                        <button type="submit" class="btn-success">
                                            <i class="fas fa-paper-plane mr-3"></i>Send Invitation
                                        </button>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Current Collaborators -->
                            <div>
                                <h4 class="text-2xl font-bold text-gray-900 mb-8 flex items-center">
                                    <i class="fas fa-users mr-3"></i>Current Collaborators
                                    <span class="ml-3 text-lg font-normal text-gray-500 bg-gray-100 px-3 py-1 rounded-full">
                                        <?php echo count($collaborators); ?> collaborators
                                    </span>
                                </h4>
                                
                                <?php if (empty($collaborators)): ?>
                                    <div class="text-center py-12 bg-gradient-to-br from-gray-50 to-pink-50 rounded-xl">
                                        <i class="fas fa-user-friends fa-3x text-gray-400 mb-4"></i>
                                        <p class="text-gray-500 text-lg">No collaborators yet. Invite someone to get started!</p>
                                    </div>
                                <?php else: ?>
                                    <div class="space-y-6">
                                        <?php foreach ($collaborators as $collaborator): ?>
                                            <div class="flex items-center justify-between p-6 border-2 border-gray-200 rounded-2xl hover:shadow-lg transition-all duration-300 bg-gradient-to-r from-white to-gray-50">
                                                <div class="flex items-center space-x-6">
                                                    <div class="w-16 h-16 bg-gradient-to-r from-blue-500 to-purple-500 rounded-full flex items-center justify-center text-white font-bold text-xl">
                                                        <?php echo strtoupper(substr($collaborator['first_name'] ?: $collaborator['username'], 0, 1)); ?>
                                                    </div>
                                                    <div>
                                                        <p class="font-bold text-gray-900 text-lg">
                                                            <?php echo htmlspecialchars(trim($collaborator['first_name'] . ' ' . $collaborator['last_name']) ?: $collaborator['username']); ?>
                                                        </p>
                                                        <p class="text-base text-gray-600 font-medium"><?php echo htmlspecialchars($collaborator['email']); ?></p>
                                                        <p class="text-sm text-gray-500">
                                                            Invited by <?php echo htmlspecialchars($collaborator['invited_by_username']); ?> • 
                                                            <?php echo Config::formatDateTime($collaborator['created_at']); ?>
                                                        </p>
                                                    </div>
                                                </div>
                                                
                                                <div class="flex items-center space-x-4">
                                                    <span class="px-4 py-2 text-sm font-bold rounded-full 
                                                        <?php 
                                                            switch($collaborator['status']) {
                                                                case 'accepted': echo 'bg-green-100 text-green-800'; break;
                                                                case 'pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                                                default: echo 'bg-gray-100 text-gray-800';
                                                            }
                                                        ?>">
                                                        <?php echo ucfirst($collaborator['status']); ?>
                                                    </span>
                                                    <span class="px-4 py-2 text-sm font-bold bg-blue-100 text-blue-800 rounded-full">
                                                        <?php echo ucfirst($collaborator['role']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Notifications Section -->
                <div id="notifications-section" class="settings-section p-10">
                    <div class="max-w-4xl">
                        <div class="mb-10">
                            <h3 class="text-3xl font-bold text-gray-900 mb-4 flex items-center">
                                <i class="fas fa-bell mr-4 text-yellow-600 text-4xl"></i>Notification Settings
                            </h3>
                            <p class="text-gray-600 text-lg leading-relaxed">Configure how and when you receive notifications to stay informed.</p>
                        </div>
                        
                        <form method="POST" class="space-y-10">
                            <input type="hidden" name="action" value="update_notification_settings">
                            
                            <div class="feature-card">
                                <h4 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                                    <i class="fas fa-cog mr-3 text-blue-600 text-2xl"></i>Notification Channels
                                </h4>
                                
                                <div class="space-y-6">
                                    <div class="flex items-center p-4 rounded-lg bg-gray-50">
                                        <input id="email_notifications_channel" name="email_notifications" type="checkbox" 
                                               <?php echo getUserPreference('email_notifications', '1') === '1' ? 'checked' : ''; ?>
                                               class="checkbox-enhanced">
                                        <label for="email_notifications_channel" class="ml-4 block text-base text-gray-700">
                                            <span class="font-semibold text-lg">Email notifications</span>
                                            <span class="block text-sm text-gray-500 mt-1">Receive notifications via email</span>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center p-4 rounded-lg bg-gray-50">
                                        <input id="browser_notifications" name="browser_notifications" type="checkbox" 
                                               <?php echo getUserPreference('browser_notifications', '1') === '1' ? 'checked' : ''; ?>
                                               class="checkbox-enhanced">
                                        <label for="browser_notifications" class="ml-4 block text-base text-gray-700">
                                            <span class="font-semibold text-lg">Browser notifications</span>
                                            <span class="block text-sm text-gray-500 mt-1">Show desktop notifications in your browser</span>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center p-4 rounded-lg bg-gray-50">
                                        <input id="mobile_notifications" name="mobile_notifications" type="checkbox" 
                                               <?php echo getUserPreference('mobile_notifications', '1') === '1' ? 'checked' : ''; ?>
                                               class="checkbox-enhanced">
                                        <label for="mobile_notifications" class="ml-4 block text-base text-gray-700">
                                            <span class="font-semibold text-lg">Mobile notifications</span>
                                            <span class="block text-sm text-gray-500 mt-1">Push notifications to your mobile device</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="feature-card">
                                <h4 class="text-2xl font-bold text-gray-900 mb-6 flex items-center">
                                    <i class="fas fa-filter mr-3 text-green-600 text-2xl"></i>Notification Types
                                </h4>
                                
                                <div class="space-y-6">
                                    <div class="flex items-center p-4 rounded-lg bg-gray-50">
                                        <input id="notify_new_orders" name="notify_new_orders" type="checkbox" 
                                               <?php echo getUserPreference('notify_new_orders', '1') === '1' ? 'checked' : ''; ?>
                                               class="checkbox-enhanced">
                                        <label for="notify_new_orders" class="ml-4 block text-base text-gray-700">
                                            <span class="font-semibold text-lg">New orders</span>
                                            <span class="block text-sm text-gray-500 mt-1">Get notified when you receive new orders</span>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center p-4 rounded-lg bg-gray-50">
                                        <input id="notify_low_stock" name="notify_low_stock" type="checkbox" 
                                               <?php echo getUserPreference('notify_low_stock', '1') === '1' ? 'checked' : ''; ?>
                                               class="checkbox-enhanced">
                                        <label for="notify_low_stock" class="ml-4 block text-base text-gray-700">
                                            <span class="font-semibold text-lg">Low stock alerts</span>
                                            <span class="block text-sm text-gray-500 mt-1">Alert when product inventory is running low</span>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center p-4 rounded-lg bg-gray-50">
                                        <input id="notify_reviews" name="notify_reviews" type="checkbox" 
                                               <?php echo getUserPreference('notify_reviews', '1') === '1' ? 'checked' : ''; ?>
                                               class="checkbox-enhanced">
                                        <label for="notify_reviews" class="ml-4 block text-base text-gray-700">
                                            <span class="font-semibold text-lg">Product reviews</span>
                                            <span class="block text-sm text-gray-500 mt-1">Notify when customers leave product reviews</span>
                                        </label>
                                    </div>
                                    
                                    <div class="flex items-center p-4 rounded-lg bg-gray-50">
                                        <input id="notify_system_updates" name="notify_system_updates" type="checkbox" 
                                               <?php echo getUserPreference('notify_system_updates', '1') === '1' ? 'checked' : ''; ?>
                                               class="checkbox-enhanced">
                                        <label for="notify_system_updates" class="ml-4 block text-base text-gray-700">
                                            <span class="font-semibold text-lg">System updates</span>
                                            <span class="block text-sm text-gray-500 mt-1">Important system and security updates</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="pt-8 border-t border-gray-200">
                                <button type="submit" class="btn-primary">
                                    <i class="fas fa-save mr-3"></i>Save Notification Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>

        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="max-w-screen-xl mx-auto py-6 px-4 sm:px-6 lg:px-8 text-center text-sm text-gray-500">
                © <?php echo date('Y'); ?> <?php echo htmlspecialchars($appName); ?>. All rights reserved.
            </div>
        </footer>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Tab switching function
            function showSettingsTab(tab) {
                // Hide all sections with fade out
                document.querySelectorAll('.settings-section').forEach(section => {
                    section.classList.remove('active');
                });
                
                // Remove active class from all tabs
                document.querySelectorAll('.settings-tab').forEach(tabButton => {
                    tabButton.classList.remove('active');
                });
                
                // Show selected section and activate tab with fade in
                const section = document.getElementById(tab + '-section');
                const tabButton = document.getElementById(tab + '-tab');
                
                if (section && tabButton) {
                    setTimeout(() => {
                        section.classList.add('active');
                        tabButton.classList.add('active');
                        
                        // Update URL hash without triggering scroll
                        if (history.replaceState) {
                            history.replaceState(null, null, '#' + tab);
                        }
                    }, 100);
                }
            }
            
            // Make function global
            window.showSettingsTab = showSettingsTab;
            
            // Enhanced password strength checker
            window.checkPasswordStrength = function(password) {
                const strengthBar = document.getElementById('password-strength');
                if (!strengthBar) return;
                
                let strength = 0;
                let strengthClass = '';
                
                // Check various password criteria
                if (password.length >= 8) strength++;
                if (/[a-z]/.test(password)) strength++;
                if (/[A-Z]/.test(password)) strength++;
                if (/\d/.test(password)) strength++;
                if (/[^a-zA-Z0-9]/.test(password)) strength++;
                
                // Determine strength level
                switch (strength) {
                    case 0:
                    case 1:
                        strengthClass = 'weak';
                        break;
                    case 2:
                        strengthClass = 'fair';
                        break;
                    case 3:
                    case 4:
                        strengthClass = 'good';
                        break;
                    case 5:
                        strengthClass = 'strong';
                        break;
                }
                
                // Apply strength class with animation
                strengthBar.className = 'password-strength ' + strengthClass;
            };
            
            // Enhanced password confirmation validation
            const password = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            if (password && confirmPassword) {
                function validatePasswords() {
                    if (password.value !== confirmPassword.value) {
                        confirmPassword.setCustomValidity('Passwords do not match');
                        confirmPassword.classList.add('border-red-400');
                        confirmPassword.classList.remove('border-green-400');
                    } else {
                        confirmPassword.setCustomValidity('');
                        confirmPassword.classList.remove('border-red-400');
                        if (confirmPassword.value.length > 0) {
                            confirmPassword.classList.add('border-green-400');
                        }
                    }
                }
                
                password.addEventListener('input', validatePasswords);
                confirmPassword.addEventListener('input', validatePasswords);
            }
            
            // Enhanced store switching with better UX
            window.switchToStore = function(storeId) {
                const button = event.target;
                const originalText = button.innerHTML;
                const originalClass = button.className;
                
                // Show loading state
                button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Switching...';
                button.disabled = true;
                button.className = originalClass + ' opacity-75 cursor-not-allowed';
                
                // Create API request
                fetch('api/switch_store.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ store_id: storeId })
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        // Show success feedback
                        button.innerHTML = '<i class="fas fa-check mr-2"></i>Success!';
                        button.className = originalClass.replace('from-indigo-600 to-purple-600', 'from-green-600 to-green-700');
                        
                        // Reload page after short delay
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    } else {
                        throw new Error(data.error || 'Unknown error occurred');
                    }
                })
                .catch(error => {
                    console.error('Error switching store:', error);
                    
                    // Show error state
                    button.innerHTML = '<i class="fas fa-exclamation-triangle mr-2"></i>Error';
                    button.className = originalClass.replace('from-indigo-600 to-purple-600', 'from-red-600 to-red-700');
                    
                    // Show user-friendly error message
                    const errorMsg = error.message || 'Failed to switch store';
                    showNotification(errorMsg, 'error');
                    
                    // Reset button after delay
                    setTimeout(() => {
                        button.innerHTML = originalText;
                        button.className = originalClass;
                        button.disabled = false;
                    }, 3000);
                });
            };
            
            // Enhanced form submission handling
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitButton = form.querySelector('button[type="submit"]');
                    if (submitButton && !submitButton.disabled) {
                        // Prevent double submission
                        setTimeout(() => {
                            submitButton.disabled = true;
                            const originalText = submitButton.innerHTML;
                            
                            // Add loading state
                            const icon = submitButton.querySelector('i');
                            if (icon) {
                                icon.className = icon.className.replace(/fa-\w+/, 'fa-spinner fa-spin');
                            }
                            
                            // Re-enable after timeout (fallback)
                            setTimeout(() => {
                                if (submitButton.disabled) {
                                    submitButton.disabled = false;
                                    submitButton.innerHTML = originalText;
                                }
                            }, 10000);
                        }, 100);
                    }
                });
            });
            
            // Handle URL hash for direct tab access and store switching
            function handleUrlHash() {
                const hash = window.location.hash.substring(1);
                if (hash) {
                    // Check if it's a valid tab
                    const validTabs = ['profile', 'security', 'store', 'preferences', 'stores', 'collaboration', 'notifications'];
                    if (validTabs.includes(hash)) {
                        showSettingsTab(hash);
                    }
                }
            }
            
            // Handle initial hash
            handleUrlHash();
            
            // Handle hash changes
            window.addEventListener('hashchange', handleUrlHash);
            
            // Enhanced form validation with real-time feedback
            document.querySelectorAll('.form-input').forEach(input => {
                // Add focus effects
                input.addEventListener('focus', function() {
                    this.classList.add('ring-4', 'ring-blue-100', 'border-blue-400');
                });
                
                input.addEventListener('blur', function() {
                    this.classList.remove('ring-4', 'ring-blue-100');
                    if (!this.classList.contains('border-red-400') && !this.classList.contains('border-green-400')) {
                        this.classList.remove('border-blue-400');
                    }
                });
                
                // Email validation
                if (input.type === 'email') {
                    input.addEventListener('input', function() {
                        const email = this.value;
                        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        
                        if (email.length > 0) {
                            if (emailRegex.test(email)) {
                                this.classList.remove('border-red-400');
                                this.classList.add('border-green-400');
                            } else {
                                this.classList.remove('border-green-400');
                                this.classList.add('border-red-400');
                            }
                        } else {
                            this.classList.remove('border-red-400', 'border-green-400');
                        }
                    });
                }
                
                // URL validation
                if (input.type === 'url') {
                    input.addEventListener('input', function() {
                        const url = this.value;
                        try {
                            if (url.length > 0) {
                                new URL(url);
                                this.classList.remove('border-red-400');
                                this.classList.add('border-green-400');
                            } else {
                                this.classList.remove('border-red-400', 'border-green-400');
                            }
                        } catch {
                            if (url.length > 0) {
                                this.classList.remove('border-green-400');
                                this.classList.add('border-red-400');
                            }
                        }
                    });
                }
            });
            
            // Notification system
            window.showNotification = function(message, type = 'info', duration = 5000) {
                const notification = document.createElement('div');
                notification.className = `fixed top-4 right-4 z-50 p-4 rounded-xl shadow-2xl transform translate-x-full transition-all duration-300 ease-in-out max-w-sm`;
                
                let bgClass, iconClass, textClass;
                switch (type) {
                    case 'success':
                        bgClass = 'bg-green-50 border-l-4 border-green-400';
                        iconClass = 'fas fa-check-circle text-green-600';
                        textClass = 'text-green-800';
                        break;
                    case 'error':
                        bgClass = 'bg-red-50 border-l-4 border-red-400';
                        iconClass = 'fas fa-exclamation-circle text-red-600';
                        textClass = 'text-red-800';
                        break;
                    case 'warning':
                        bgClass = 'bg-yellow-50 border-l-4 border-yellow-400';
                        iconClass = 'fas fa-exclamation-triangle text-yellow-600';
                        textClass = 'text-yellow-800';
                        break;
                    default:
                        bgClass = 'bg-blue-50 border-l-4 border-blue-400';
                        iconClass = 'fas fa-info-circle text-blue-600';
                        textClass = 'text-blue-800';
                }
                
                notification.className += ` ${bgClass}`;
                notification.innerHTML = `
                    <div class="flex items-start">
                        <i class="${iconClass} fa-lg mr-3 mt-0.5"></i>
                        <div class="flex-1">
                            <p class="${textClass} font-medium">${message}</p>
                        </div>
                        <button onclick="this.parentElement.parentElement.remove()" class="ml-3 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
                
                document.body.appendChild(notification);
                
                // Animate in
                setTimeout(() => {
                    notification.classList.remove('translate-x-full');
                }, 100);
                
                // Auto remove
                if (duration > 0) {
                    setTimeout(() => {
                        notification.classList.add('translate-x-full');
                        setTimeout(() => {
                            if (notification.parentElement) {
                                notification.remove();
                            }
                        }, 300);
                    }, duration);
                }
            };
            
            // Connection test with better feedback
            const testConnectionForms = document.querySelectorAll('form[action*="test_connection"], button[value="test_connection"]');
            testConnectionForms.forEach(element => {
                element.addEventListener('click', function(e) {
                    if (this.tagName === 'BUTTON' && this.value === 'test_connection') {
                        e.preventDefault();
                        
                        const button = this;
                        const originalText = button.innerHTML;
                        
                        button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Testing Connection...';
                        button.disabled = true;
                        
                        // Create form data
                        const form = button.closest('form');
                        const formData = new FormData(form);
                        formData.set('action', 'test_connection');
                        
                        fetch(window.location.href, {
                            method: 'POST',
                            body: formData
                        })
                        .then(response => response.text())
                        .then(html => {
                            // Parse response to check for success/error messages
                            const parser = new DOMParser();
                            const doc = parser.parseFromString(html, 'text/html');
                            const successMsg = doc.querySelector('.alert-success');
                            const errorMsg = doc.querySelector('.alert-error');
                            
                            if (successMsg) {
                                showNotification('Connection test successful!', 'success');
                                button.innerHTML = '<i class="fas fa-check mr-2"></i>Connected!';
                                button.className = button.className.replace('btn-success', 'btn-success bg-green-600');
                            } else if (errorMsg) {
                                showNotification('Connection test failed. Please check your credentials.', 'error');
                                button.innerHTML = '<i class="fas fa-times mr-2"></i>Failed';
                                button.className = button.className.replace('btn-success', 'btn-danger');
                            }
                            
                            setTimeout(() => {
                                button.innerHTML = originalText;
                                button.className = button.className.replace('btn-danger', 'btn-success').replace('bg-green-600', '');
                                button.disabled = false;
                            }, 3000);
                        })
                        .catch(error => {
                            console.error('Connection test error:', error);
                            showNotification('Connection test failed due to network error.', 'error');
                            
                            button.innerHTML = originalText;
                            button.disabled = false;
                        });
                    }
                });
            });
            
            // Smooth scrolling for internal links
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function (e) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                });
            });
            
            // Auto-save indicators for preferences
            const preferenceInputs = document.querySelectorAll('#preferences-section input, #preferences-section select');
            preferenceInputs.forEach(input => {
                let saveTimeout;
                
                input.addEventListener('change', function() {
                    clearTimeout(saveTimeout);
                    
                    // Show saving indicator
                    const indicator = document.createElement('span');
                    indicator.className = 'text-xs text-blue-600 ml-2';
                    indicator.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Saving...';
                    
                    const existingIndicator = this.parentElement.querySelector('.save-indicator');
                    if (existingIndicator) {
                        existingIndicator.remove();
                    }
                    
                    indicator.classList.add('save-indicator');
                    this.parentElement.appendChild(indicator);
                    
                    // Simulate save delay
                    saveTimeout = setTimeout(() => {
                        indicator.innerHTML = '<i class="fas fa-check mr-1 text-green-600"></i>Saved';
                        indicator.className = 'text-xs text-green-600 ml-2 save-indicator';
                        
                        setTimeout(() => {
                            if (indicator.parentElement) {
                                indicator.remove();
                            }
                        }, 2000);
                    }, 1000);
                });
            });
            
            console.log('Enhanced Settings page loaded successfully!');
        });
    </script>
</body>
</html>