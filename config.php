<?php
// config.php - Enhanced Multi-User Configuration System
require_once 'database.php';

class Config {
    private static $user_config = null;
    private static $current_user_id = null;
    private static $current_user = null;
    private static $auth = null;
    private static $db = null;
    private static $system_settings = [];
    
    public static function init($user_id = null) {
        if (!self::$db) {
            self::$db = Database::getInstance();
        }
        
        if (!self::$auth) {
            require_once 'auth.php';
            self::$auth = new Auth();
        }
        
        // Load system settings
        self::loadSystemSettings();
        
        // Set current user
        if ($user_id) {
            self::$current_user_id = $user_id;
            self::$current_user = self::$auth->getUserById($user_id);
        } else {
            self::$current_user = self::$auth->getCurrentUser();
            self::$current_user_id = self::$current_user ? self::$current_user['id'] : null;
        }
        
        // Load user configuration
        if (self::$current_user_id) {
            self::loadUserConfig();
        }
    }
    
    private static function loadSystemSettings() {
        try {
            $settings = self::$db->fetchAll('SELECT setting_key, setting_value, setting_type FROM system_settings');
            foreach ($settings as $setting) {
                $value = $setting['setting_value'];
                switch ($setting['setting_type']) {
                    case 'boolean':
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                        break;
                    case 'integer':
                        $value = (int) $value;
                        break;
                    case 'float':
                        $value = (float) $value;
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }
                self::$system_settings[$setting['setting_key']] = $value;
            }
        } catch (Exception $e) {
            error_log('Failed to load system settings: ' . $e->getMessage());
            self::$system_settings = [];
        }
    }
    
    private static function loadUserConfig() {
        if (!self::$current_user_id) {
            return;
        }
        
        try {
            self::$user_config = self::$db->fetch('SELECT * FROM user_configs WHERE user_id = ?', [self::$current_user_id]);
            
            // Create default configuration if none exists
            if (!self::$user_config) {
                self::createDefaultUserConfig();
            }
        } catch (Exception $e) {
            error_log('Failed to load user config: ' . $e->getMessage());
            self::$user_config = null;
        }
    }
    
    private static function createDefaultUserConfig() {
        if (!self::$current_user_id) {
            return false;
        }
        
        try {
            $default_settings = [
                'timezone' => self::$current_user['timezone'] ?? 'UTC',
                'language' => self::$current_user['language'] ?? 'en',
                'currency' => 'USD',
                'date_format' => 'Y-m-d',
                'time_format' => 'H:i:s',
                'products_per_page' => 20,
                'orders_per_page' => 20,
                'theme' => 'light',
                'sidebar_collapsed' => false,
                'notifications_enabled' => true,
                'email_notifications' => true,
                'auto_sync' => false,
                'sync_interval' => 300, // 5 minutes
            ];
            
            self::$db->execute('
                INSERT INTO user_configs (user_id, settings) VALUES (?, ?)
            ', [self::$current_user_id, json_encode($default_settings)]);
            
            // Reload config
            self::loadUserConfig();
            
            return true;
        } catch (Exception $e) {
            error_log('Failed to create default user config: ' . $e->getMessage());
            return false;
        }
    }
    
    // User configuration methods
    public static function get($key, $default = null) {
        if (!self::$current_user_id) {
            self::init();
        }
        
        if (!self::$user_config) {
            return $default;
        }
        
        // Handle settings JSON field
        if ($key === 'settings') {
            return json_decode(self::$user_config['settings'] ?? '{}', true);
        }
        
        return isset(self::$user_config[$key]) ? self::$user_config[$key] : $default;
    }
    
    public static function save($data) {
        if (!self::$current_user_id) {
            throw new Exception('No user logged in');
        }
        
        try {
            // Handle settings separately
            if (isset($data['settings'])) {
                $current_settings = self::get('settings', []);
                $new_settings = array_merge($current_settings, $data['settings']);
                $data['settings'] = json_encode($new_settings);
            }
            
            // Build update query
            $fields = [];
            $values = [];
            $allowed_fields = [
                'store_name', 'store_url', 'consumer_key', 'consumer_secret', 
                'connected', 'last_test', 'last_sync', 'connection_errors',
                'api_version', 'timeout', 'rate_limit', 'settings'
            ];
            
            foreach ($data as $key => $value) {
                if (in_array($key, $allowed_fields)) {
                    $fields[] = $key . ' = ?';
                    $values[] = $value;
                }
            }
            
            if (empty($fields)) {
                return false;
            }
            
            $values[] = self::$current_user_id;
            $sql = 'UPDATE user_configs SET ' . implode(', ', $fields) . ' WHERE user_id = ?';
            
            $result = self::$db->execute($sql, $values);
            
            if ($result) {
                // Log configuration change
                self::logActivity('config_updated', 'User configuration updated');
                // Reload config
                self::loadUserConfig();
            }
            
            return $result;
        } catch (Exception $e) {
            error_log('Failed to save user config: ' . $e->getMessage());
            return false;
        }
    }
    
    // Settings helper methods
    public static function getSetting($key, $default = null) {
        $settings = self::get('settings', []);
        return isset($settings[$key]) ? $settings[$key] : $default;
    }
    
    public static function setSetting($key, $value) {
        try {
            $settings = self::get('settings', []);
            $settings[$key] = $value;
            return self::save(['settings' => $settings]);
        } catch (Exception $e) {
            error_log('Failed to set setting: ' . $e->getMessage());
            return false;
        }
    }
    
    public static function setSettings($new_settings) {
        try {
            return self::save(['settings' => $new_settings]);
        } catch (Exception $e) {
            error_log('Failed to set settings: ' . $e->getMessage());
            return false;
        }
    }
    
    // User preferences (separate from settings)
    public static function getPreference($key, $default = null) {
        if (!self::$current_user_id) {
            return $default;
        }
        
        try {
            $result = self::$db->fetch('
                SELECT preference_value, preference_type FROM user_preferences 
                WHERE user_id = ? AND preference_key = ?
            ', [self::$current_user_id, $key]);
            
            if (!$result) {
                return $default;
            }
            
            $value = $result['preference_value'];
            switch ($result['preference_type']) {
                case 'bool':
                case 'boolean':
                    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
                case 'int':
                case 'integer':
                    return (int) $value;
                case 'float':
                    return (float) $value;
                case 'json':
                    return json_decode($value, true);
                default:
                    return $value;
            }
        } catch (Exception $e) {
            error_log('Failed to get preference: ' . $e->getMessage());
            return $default;
        }
    }
    
    public static function setPreference($key, $value, $type = 'string') {
        if (!self::$current_user_id) {
            return false;
        }
        
        try {
            // Convert value based on type
            if ($type === 'json') {
                $value = json_encode($value);
            } elseif ($type === 'boolean' || $type === 'bool') {
                $value = $value ? '1' : '0';
            }
            
            $result = self::$db->execute('
                INSERT OR REPLACE INTO user_preferences (user_id, preference_key, preference_value, preference_type) 
                VALUES (?, ?, ?, ?)
            ', [self::$current_user_id, $key, $value, $type]);
            
            if ($result) {
                self::logActivity('preference_updated', "Preference '{$key}' updated");
            }
            
            return $result;
        } catch (Exception $e) {
            error_log('Failed to set preference: ' . $e->getMessage());
            return false;
        }
    }
    
    // System settings methods
    public static function getSystemSetting($key, $default = null) {
        return isset(self::$system_settings[$key]) ? self::$system_settings[$key] : $default;
    }
    
    public static function setSystemSetting($key, $value, $type = 'string') {
        if (!self::isAdmin()) {
            throw new Exception('Admin privileges required');
        }
        
        try {
            $result = self::$db->setSystemSetting($key, $value, $type);
            
            if ($result) {
                // Reload system settings
                self::loadSystemSettings();
                self::logActivity('system_setting_updated', "System setting '{$key}' updated");
            }
            
            return $result;
        } catch (Exception $e) {
            error_log('Failed to set system setting: ' . $e->getMessage());
            return false;
        }
    }
    
    // Store collaboration methods
    public static function getAccessibleStores() {
        if (!self::$current_user_id) {
            return [];
        }
        
        try {
            // Get stores owned by user
            $owned_stores = self::$db->fetchAll('
                SELECT uc.*, "owner" as role, NULL as permissions, NULL as last_access, 0 as access_count
                FROM user_configs uc 
                WHERE uc.user_id = ?
            ', [self::$current_user_id]);
            
            // Get stores user has been invited to
            $shared_stores = self::$db->fetchAll('
                SELECT uc.*, sc.role, sc.permissions, sc.last_access, sc.access_count
                FROM user_configs uc
                JOIN store_collaborators sc ON uc.id = sc.store_config_id
                WHERE sc.user_id = ? AND sc.status = "accepted"
            ', [self::$current_user_id]);
            
            return array_merge($owned_stores, $shared_stores);
        } catch (Exception $e) {
            error_log('Failed to get accessible stores: ' . $e->getMessage());
            return [];
        }
    }
    
    public static function switchToStore($store_config_id) {
        if (!self::$current_user_id) {
            return false;
        }
        
        try {
            // Check if user has access to this store
            $access = self::$db->fetch('
                SELECT uc.*, sc.role, sc.permissions, sc.status
                FROM user_configs uc
                LEFT JOIN store_collaborators sc ON uc.id = sc.store_config_id AND sc.user_id = ?
                WHERE uc.id = ? AND (uc.user_id = ? OR (sc.status = "accepted" AND sc.user_id = ?))
            ', [self::$current_user_id, $store_config_id, self::$current_user_id, self::$current_user_id]);
            
            if (!$access) {
                throw new Exception('Access denied to this store');
            }
            
            // Update session preference to remember current store
            self::setPreference('current_store_id', $store_config_id, 'integer');
            
            // Update last access if it's a shared store
            if (isset($access['status']) && $access['status'] === 'accepted') {
                self::$db->execute('
                    UPDATE store_collaborators 
                    SET last_access = CURRENT_TIMESTAMP, access_count = access_count + 1 
                    WHERE store_config_id = ? AND user_id = ?
                ', [$store_config_id, self::$current_user_id]);
            }
            
            self::logActivity('store_switched', "Switched to store: {$access['store_name']}", 'navigation');
            
            return true;
        } catch (Exception $e) {
            error_log('Failed to switch store: ' . $e->getMessage());
            return false;
        }
    }
    
    public static function getCurrentStore() {
        if (!self::$current_user_id) {
            return null;
        }
        
        try {
            $store_id = self::getPreference('current_store_id');
            if (!$store_id) {
                return null;
            }
            
            return self::$db->fetch('
                SELECT uc.*, sc.role, sc.permissions, sc.status
                FROM user_configs uc
                LEFT JOIN store_collaborators sc ON uc.id = sc.store_config_id AND sc.user_id = ?
                WHERE uc.id = ? AND (uc.user_id = ? OR (sc.status = "accepted" AND sc.user_id = ?))
            ', [self::$current_user_id, $store_id, self::$current_user_id, self::$current_user_id]);
        } catch (Exception $e) {
            error_log('Failed to get current store: ' . $e->getMessage());
            return null;
        }
    }
    
    // User methods
    public static function getCurrentUserId() {
        if (!self::$current_user_id) {
            self::init();
        }
        return self::$current_user_id;
    }
    
    public static function getCurrentUser() {
        if (!self::$current_user) {
            self::init();
        }
        return self::$current_user;
    }
    
    public static function isLoggedIn() {
        return self::getCurrentUserId() !== null;
    }
    
    public static function isAdmin() {
        $user = self::getCurrentUser();
        return $user && $user['is_admin'];
    }
    
    public static function requireAuth() {
        if (!self::$auth) {
            self::init();
        }
        return self::$auth->requireAuth();
    }
    
    public static function requireAdmin() {
        self::requireAuth();
        if (!self::isAdmin()) {
            throw new Exception('Admin privileges required');
        }
        return true;
    }
    
    // Activity logging
    public static function logActivity($action, $description = '', $category = 'config', $metadata = []) {
        if (!self::$current_user_id || !self::$db) {
            return false;
        }
        
        try {
            return self::$db->execute('
                INSERT INTO user_activity 
                (user_id, action, description, category, ip_address, user_agent, request_method, request_uri, metadata) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ', [
                self::$current_user_id,
                $action,
                $description,
                $category,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $_SERVER['REQUEST_METHOD'] ?? '',
                $_SERVER['REQUEST_URI'] ?? '',
                json_encode($metadata)
            ]);
        } catch (Exception $e) {
            error_log('Failed to log activity: ' . $e->getMessage());
            return false;
        }
    }
    
    // Notification methods
    public static function addNotification($title, $message, $type = 'info', $category = 'system', $action_url = null, $action_text = null, $expires_at = null) {
        if (!self::$current_user_id) {
            return false;
        }
        
        try {
            return self::$db->execute('
                INSERT INTO user_notifications 
                (user_id, title, message, type, category, action_url, action_text, expires_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ', [self::$current_user_id, $title, $message, $type, $category, $action_url, $action_text, $expires_at]);
        } catch (Exception $e) {
            error_log('Failed to add notification: ' . $e->getMessage());
            return false;
        }
    }
    
    public static function getNotifications($limit = 10, $unread_only = false) {
        if (!self::$current_user_id) {
            return [];
        }
        
        try {
            $where_clause = $unread_only ? 'AND is_read = 0' : '';
            
            return self::$db->fetchAll("
                SELECT * FROM user_notifications 
                WHERE user_id = ? {$where_clause}
                AND (expires_at IS NULL OR expires_at > datetime('now'))
                AND is_dismissed = 0
                ORDER BY created_at DESC 
                LIMIT ?
            ", [self::$current_user_id, $limit]);
        } catch (Exception $e) {
            error_log('Failed to get notifications: ' . $e->getMessage());
            return [];
        }
    }
    
    public static function markNotificationRead($notification_id) {
        if (!self::$current_user_id) {
            return false;
        }
        
        try {
            return self::$db->execute('
                UPDATE user_notifications 
                SET is_read = 1, read_at = CURRENT_TIMESTAMP 
                WHERE id = ? AND user_id = ?
            ', [$notification_id, self::$current_user_id]);
        } catch (Exception $e) {
            error_log('Failed to mark notification read: ' . $e->getMessage());
            return false;
        }
    }
    
    public static function dismissNotification($notification_id) {
        if (!self::$current_user_id) {
            return false;
        }
        
        try {
            return self::$db->execute('
                UPDATE user_notifications 
                SET is_dismissed = 1 
                WHERE id = ? AND user_id = ?
            ', [$notification_id, self::$current_user_id]);
        } catch (Exception $e) {
            error_log('Failed to dismiss notification: ' . $e->getMessage());
            return false;
        }
    }
    
    // Backward compatibility methods
    public static function load() {
        if (!self::$current_user_id) {
            self::init();
        }
        return self::$user_config ?: [];
    }
    
    // Utility methods
    public static function formatDateTime($datetime, $format = null) {
        if (!$datetime) {
            return '';
        }
        
        try {
            $format = $format ?: (self::getSetting('date_format', 'Y-m-d') . ' ' . self::getSetting('time_format', 'H:i:s'));
            $timezone = new DateTimeZone(self::getSetting('timezone', 'UTC'));
            
            $dt = new DateTime($datetime);
            $dt->setTimezone($timezone);
            return $dt->format($format);
        } catch (Exception $e) {
            error_log('Failed to format datetime: ' . $e->getMessage());
            return $datetime;
        }
    }
    
    public static function formatCurrency($amount, $currency = null) {
        $currency = $currency ?: self::getSetting('currency', 'USD');
        
        // Simple currency formatting - in production, use proper localization
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$'
        ];
        
        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($amount, 2);
    }
    
    public static function getTimezone() {
        return self::getSetting('timezone', 'UTC');
    }
    
    public static function getCurrency() {
        return self::getSetting('currency', 'USD');
    }
    
    public static function getLanguage() {
        return self::getSetting('language', 'en');
    }
    
    // Maintenance mode
    public static function isMaintenanceMode() {
        return self::getSystemSetting('maintenance_mode', false);
    }
    
    public static function canBypassMaintenance() {
        return self::isAdmin();
    }
    
    // Registration settings
    public static function isRegistrationEnabled() {
        return self::getSystemSetting('registration_enabled', true);
    }
    
    public static function isEmailVerificationRequired() {
        return self::getSystemSetting('email_verification_required', false);
    }
    
    // Rate limiting
    public static function checkRateLimit($endpoint, $max_requests = null, $window = null) {
        if (!self::$current_user_id) {
            return true; // Allow anonymous requests for now
        }
        
        try {
            $max_requests = $max_requests ?: self::getSystemSetting('api_rate_limit_requests', 100);
            $window = $window ?: self::getSystemSetting('api_rate_limit_window', 3600);
            
            $window_start = date('Y-m-d H:i:s', floor(time() / $window) * $window);
            
            // Get current request count
            $current_count = self::$db->fetchColumn('
                SELECT COALESCE(requests_count, 0) FROM api_rate_limits 
                WHERE user_id = ? AND endpoint = ? AND window_start = ?
            ', [self::$current_user_id, $endpoint, $window_start]);
            
            if ($current_count >= $max_requests) {
                return false;
            }
            
            // Increment counter
            self::$db->execute('
                INSERT OR REPLACE INTO api_rate_limits (user_id, endpoint, requests_count, window_start) 
                VALUES (?, ?, COALESCE((SELECT requests_count FROM api_rate_limits WHERE user_id = ? AND endpoint = ? AND window_start = ?), 0) + 1, ?)
            ', [self::$current_user_id, $endpoint, self::$current_user_id, $endpoint, $window_start, $window_start]);
            
            return true;
        } catch (Exception $e) {
            error_log('Failed to check rate limit: ' . $e->getMessage());
            return true; // Allow on error to avoid blocking legitimate requests
        }
    }
    
    // Cache management
    private static $cache = [];
    
    public static function cache($key, $value = null, $ttl = 3600) {
        if ($value === null) {
            // Get from cache
            if (isset(self::$cache[$key])) {
                $item = self::$cache[$key];
                if ($item['expires'] > time()) {
                    return $item['value'];
                } else {
                    unset(self::$cache[$key]);
                }
            }
            return null;
        } else {
            // Set cache
            self::$cache[$key] = [
                'value' => $value,
                'expires' => time() + $ttl
            ];
            return $value;
        }
    }
    
    public static function clearCache($key = null) {
        if ($key === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$key]);
        }
    }
    
    // Error handling
    public static function handleError($message, $context = []) {
        $error_data = [
            'message' => $message,
            'context' => $context,
            'user_id' => self::$current_user_id,
            'timestamp' => date('Y-m-d H:i:s'),
            'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ];
        
        error_log('Config Error: ' . json_encode($error_data));
        
        // Log to database if possible
        if (self::$current_user_id && self::$db) {
            self::logActivity('error', $message, 'error', $context);
        }
    }
    
    // Debug methods
    public static function debug($data, $label = 'DEBUG') {
        if (self::getSystemSetting('debug_mode', false)) {
            error_log($label . ': ' . print_r($data, true));
        }
    }
    
    public static function getDiagnostics() {
        return [
            'user_id' => self::$current_user_id,
            'user_config_loaded' => !empty(self::$user_config),
            'system_settings_count' => count(self::$system_settings),
            'current_store' => self::getCurrentStore() ? 'loaded' : 'none',
            'accessible_stores_count' => count(self::getAccessibleStores()),
            'cache_items' => count(self::$cache),
            'memory_usage' => memory_get_usage(true),
            'php_version' => PHP_VERSION,
            'database_path' => self::$db ? self::$db->getDatabaseInfo()['file_path'] : 'not loaded'
        ];
    }
}
?>