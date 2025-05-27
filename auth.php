<?php
// auth.php - Enhanced Multi-User Authentication System (TUA VERSIONE + DEBUG COOKIE)

// Assicurati che la costante di debug sia definita.
if (!defined('LOGIN_DEBUG_MODE')) {
    define('LOGIN_DEBUG_MODE', false); // Default a false se non definito altrove (es. in login.php)
}

// Funzione helper per il logging all'interno di questa classe
function auth_debug_log($message) {
    if (LOGIN_DEBUG_MODE && function_exists('error_log')) {
        error_log("AUTH.PHP DEBUG: " . $message);
    }
}

require_once __DIR__ . '/database.php'; // Assumendo che database.php sia nella stessa directory

class Auth {
    private $db;
    private $session_duration = 86400 * 30; // 30 days default
    private $max_login_attempts = 5;
    private $lockout_duration = 900; // 15 minutes
    private $cookie_name = 'store_manager_session';
    
    public function __construct() {
        auth_debug_log("Constructor called.");
        try {
            $this->db = Database::getInstance();
            auth_debug_log("Database instance obtained in constructor.");
        } catch (Exception $e) {
            auth_debug_log("CRITICAL - Failed to get Database instance in Auth constructor: " . $e->getMessage());
            throw new Exception("Auth system cannot connect to the database: " . $e->getMessage());
        }
        
        try {
            if (method_exists($this->db, 'getSystemSetting')) {
                $max_attempts_setting = $this->db->getSystemSetting('max_login_attempts', 5);
                $lockout_duration_setting = $this->db->getSystemSetting('lockout_duration', 900);

                $this->max_login_attempts = is_numeric($max_attempts_setting) ? (int)$max_attempts_setting : 5;
                $this->lockout_duration = is_numeric($lockout_duration_setting) ? (int)$lockout_duration_setting : 900;
                
                auth_debug_log("System security settings loaded: max_login_attempts={$this->max_login_attempts}, lockout_duration={$this->lockout_duration}");
            } else {
                auth_debug_log("getSystemSetting method not found on DB object. Using default security settings.");
            }
        } catch (Exception $e) {
            auth_debug_log("Warning - Could not load system settings from DB in constructor: " . $e->getMessage() . ". Using defaults.");
        }
        
        auth_debug_log("Calling cleanupExpiredSessions() from constructor.");
        $this->cleanupExpiredSessions();
    }
    
    public function register($username, $email, $password, $first_name = '', $last_name = '') {
        auth_debug_log("register() called for username: " . htmlspecialchars($username));
        
        $registration_enabled = true; 
        if (method_exists($this->db, 'getSystemSetting')) {
             try {
                $reg_setting = $this->db->getSystemSetting('registration_enabled', true);
                $registration_enabled = filter_var($reg_setting, FILTER_VALIDATE_BOOLEAN);
            } catch (Exception $e) {
                auth_debug_log("register() - Error getting registration_enabled setting: " . $e->getMessage());
            }
        }
        auth_debug_log("Registration enabled status: " . ($registration_enabled ? 'Yes' : 'No'));

        if (!$registration_enabled) {
            throw new Exception('Registration is currently disabled');
        }
        
        $this->validateRegistrationInput($username, $email, $password);
        
        if ($this->userExists($username, $email)) {
            throw new Exception('Username or email already exists');
        }
        
        try {
            $this->db->beginTransaction();
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            
            $email_verification_req = false;
            if (method_exists($this->db, 'getSystemSetting')) {
                try {
                    $email_ver_setting = $this->db->getSystemSetting('email_verification_required', false);
                    $email_verification_req = filter_var($email_ver_setting, FILTER_VALIDATE_BOOLEAN);
                } catch (Exception $e) {
                    auth_debug_log("register() - Error getting email_verification_required setting: " . $e->getMessage());
                }
            }
            auth_debug_log("Email verification required status: " . ($email_verification_req ? 'Yes' : 'No'));
                
            $email_verification_token = $email_verification_req ? bin2hex(random_bytes(32)) : null;
            
            // Lo schema da install.php ha: username, email, password_hash, first_name, last_name, phone, timezone, language, is_active, is_admin, email_verified, email_verification_token, password_reset_token, password_reset_expires, failed_login_attempts, last_failed_login, last_login, login_count
            // Assicurati che le colonne nell'INSERT corrispondano a quelle con valori di default o che permettono NULL se non specificate qui.
            $stmt = $this->db->prepare('
                INSERT INTO users (username, email, password_hash, first_name, last_name, email_verification_token, email_verified, login_count, failed_login_attempts, is_active, is_admin, timezone, language) 
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 1, 0, "UTC", "en") 
            '); // Aggiunte colonne con default sensati
            $stmt->execute([
                $username, $email, $password_hash, $first_name, $last_name, 
                $email_verification_token,
                $email_verification_token ? 0 : 1
            ]);
            
            $user_id = $this->db->lastInsertId();
            $this->createDefaultUserConfig($user_id);
            $this->db->commit();
            auth_debug_log("User registered successfully. User ID: " . $user_id);
            
            $this->logActivity($user_id, 'user_registered', 'User account created', 'auth');
            
            if ($email_verification_token) {
                $this->sendVerificationEmail($email, $email_verification_token);
            }
            return $user_id;
        } catch (Exception $e) {
            $this->db->rollback();
            auth_debug_log("register() exception: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            throw $e;
        }
    }
    
    private function validateRegistrationInput($username, $email, $password) {
        if (empty($username) || empty($email) || empty($password)) throw new Exception('Username, email and password are required');
        if (strlen($username) < 3 || strlen($username) > 50) throw new Exception('Username must be between 3 and 50 characters');
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) throw new Exception('Username can only contain letters, numbers, underscores and hyphens');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('Invalid email address');
        if (strlen($password) < 8) throw new Exception('Password must be at least 8 characters long');
        if (!$this->isPasswordStrong($password)) throw new Exception('Password must contain at least one uppercase letter, one lowercase letter, and one number');
    }
    
    private function isPasswordStrong($password) {
        return preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $password);
    }
    
    private function userExists($username, $email) {
        return (bool) $this->db->fetch('SELECT id FROM users WHERE username = ? OR email = ?', [$username, $email]);
    }
    
    private function createDefaultUserConfig($user_id) {
        $user_data_for_config = $this->db->fetch('SELECT timezone, language FROM users WHERE id = ?', [$user_id]);
        $default_settings_array = [
            'timezone' => $user_data_for_config['timezone'] ?? Config::getSystemSetting('default_timezone', 'UTC'),
            'language' => $user_data_for_config['language'] ?? Config::getSystemSetting('default_language', 'en'),
            'currency' => Config::getSystemSetting('default_currency', 'USD'),
            'date_format' => 'Y-m-d', 'time_format' => 'H:i:s',
            'products_per_page' => 20, 'orders_per_page' => 20, 'theme' => 'light',
            'sidebar_collapsed' => false, 'notifications_enabled' => true,
            'email_notifications' => true, 'auto_sync' => false, 'sync_interval' => 300
        ];
        $default_settings_json = json_encode($default_settings_array);
        $this->db->execute('INSERT INTO user_configs (user_id, settings) VALUES (?, ?)', [$user_id, $default_settings_json]);
        auth_debug_log("Created default user_configs for User ID: {$user_id}");
    }
    
    public function login($username_or_email, $password, $remember_me = false) {
        auth_debug_log("login() called for: " . htmlspecialchars($username_or_email));
        if ($this->isAccountLocked($username_or_email)) {
            auth_debug_log("Account is locked for: " . htmlspecialchars($username_or_email));
            throw new Exception('Account is temporarily locked due to too many failed login attempts.');
        }
        
        $user = $this->db->fetch(
            'SELECT * FROM users WHERE (username = :identifier OR email = :identifier) AND is_active = 1',
            [':identifier' => $username_or_email]
        );
        
        if (!$user) {
            auth_debug_log("User not found or not active for: " . htmlspecialchars($username_or_email));
            $this->recordFailedLogin($username_or_email);
            throw new Exception('Invalid credentials or account not active.');
        }
        auth_debug_log("User found (ID: {$user['id']}). Verifying password...");

        if (!password_verify($password, $user['password_hash'])) {
            auth_debug_log("Password verification FAILED for user ID: {$user['id']}");
            $this->recordFailedLogin($username_or_email, $user['id']);
            throw new Exception('Invalid credentials.');
        }
        auth_debug_log("Password verification SUCCESSFUL for user ID: {$user['id']}");
        
        $email_verification_req_login = false;
        if (method_exists($this->db, 'getSystemSetting')) {
            try {
                $email_ver_setting_login = $this->db->getSystemSetting('email_verification_required', false);
                $email_verification_req_login = filter_var($email_ver_setting_login, FILTER_VALIDATE_BOOLEAN);
            } catch (Exception $e) {
                 auth_debug_log("login() - Error getting email_verification_required setting: " . $e->getMessage());
            }
        }
            
        if (!$user['email_verified'] && $email_verification_req_login) {
            auth_debug_log("Email not verified for user ID: {$user['id']}. Verification is required.");
            throw new Exception('Please verify your email address before logging in. You can request a new verification email if needed.');
        }
        
        $this->resetFailedLoginAttempts($user['id']);
        $session_data = $this->createSession($user['id'], $remember_me);
        
        $this->db->execute('UPDATE users SET last_login = CURRENT_TIMESTAMP, login_count = login_count + 1 WHERE id = ?', [$user['id']]);
        
        $this->logActivity($user['id'], 'user_login', 'User logged in successfully.', 'auth', ['session_token_prefix' => substr($session_data['session_token'],0,8)]);
        auth_debug_log("Login successful. User ID: {$user['id']}. Session token (first 8 chars): " . substr($session_data['session_token'],0,8));
        return $user;
    }
    
    private function isAccountLocked($username_or_email) {
        $user_for_lock_check = $this->db->fetch(
            'SELECT id, failed_login_attempts, last_failed_login FROM users WHERE username = :identifier OR email = :identifier',
            [':identifier' => $username_or_email]
        );
        if (!$user_for_lock_check) {
             auth_debug_log("isAccountLocked: No user found for '{$username_or_email}', so not locked by this check.");
            return false; // No user, so not locked in this specific way
        }
        if ($user_for_lock_check['failed_login_attempts'] < $this->max_login_attempts) {
            return false;
        }
        if (empty($user_for_lock_check['last_failed_login'])) { // Check for empty instead of just !
            return false;
        }
        $lockout_until = strtotime($user_for_lock_check['last_failed_login']) + $this->lockout_duration;
        $is_locked = time() < $lockout_until;
        if ($is_locked) {
            auth_debug_log("Account for '{$username_or_email}' (User ID: {$user_for_lock_check['id']}) IS currently locked until " . date('Y-m-d H:i:s', $lockout_until));
        }
        return $is_locked;
    }
    
    private function recordFailedLogin($username_or_email_identifier, $user_id_if_known = null) {
        if ($user_id_if_known) {
            $this->db->execute('UPDATE users SET failed_login_attempts = failed_login_attempts + 1, last_failed_login = CURRENT_TIMESTAMP WHERE id = ?',[$user_id_if_known]);
            auth_debug_log("Recorded failed login for known User ID: {$user_id_if_known}. Identifier: " . htmlspecialchars($username_or_email_identifier));
            $this->logActivity($user_id_if_known, 'login_failed', 'Failed login attempt', 'auth');
        } else {
             auth_debug_log("Recorded failed login for UNKNOWN user: " . htmlspecialchars($username_or_email_identifier));
        }
        // Log security event regardless of whether user_id is known
        $this->logSecurityEvent('failed_login_attempt', 'Failed login attempt for identifier: ' . htmlspecialchars($username_or_email_identifier) . ( $user_id_if_known ? " (User ID: {$user_id_if_known})" : " (User unknown)"));
    }
    
    private function resetFailedLoginAttempts($user_id) {
        $this->db->execute('UPDATE users SET failed_login_attempts = 0, last_failed_login = NULL WHERE id = ?', [$user_id]);
        auth_debug_log("Reset failed_login_attempts for User ID: {$user_id}");
    }
    
    private function createSession($user_id, $remember_me = false) {
        auth_debug_log("createSession() called for User ID: {$user_id}, Remember Me: " . ($remember_me ? 'Yes' : 'No'));
        $session_token = bin2hex(random_bytes(32));
        $refresh_token = $remember_me ? bin2hex(random_bytes(32)) : null; // Refresh token solo per "remember me"
        
        $current_time = time();
        $session_expiry_duration_config = 3600; // Default 1 ora
        if (class_exists('Config')) { // Controlla se la classe Config è disponibile
            $session_expiry_duration_config = Config::getSystemSetting('session_timeout', 3600);
        }
        $session_expiry_duration = $remember_me ? $this->session_duration : (int)$session_expiry_duration_config;
        $expires_at = date('Y-m-d H:i:s', $current_time + $session_expiry_duration);
        
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $device_info = $this->parseUserAgent($user_agent);
        
        $this->db->execute(
            'INSERT INTO user_sessions 
            (user_id, session_token, refresh_token, user_agent, ip_address, is_mobile, browser, os, expires_at, last_activity) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
            [$user_id, $session_token, $refresh_token, $user_agent, $ip_address, $device_info['is_mobile'] ? 1 : 0, $device_info['browser'], $device_info['os'], $expires_at]
        );
        auth_debug_log("Session inserted into DB for User ID: {$user_id}. Token (first 8): " . substr($session_token,0,8));
        
        $this->setSessionCookie($session_token, $session_expiry_duration);
        
        return ['session_token' => $session_token, 'refresh_token' => $refresh_token, 'expires_at' => $expires_at];
    }
    
    private function parseUserAgent($user_agent_string) {
        $is_mobile = preg_match('/Mobile|Android|iPhone|iPad|iPod|IEMobile|Opera Mini/i', $user_agent_string);
        $browser = 'Unknown'; $os = 'Unknown';
        if (preg_match('/MSIE|Trident/i', $user_agent_string) && !preg_match('/Opera/i', $user_agent_string)) $browser = 'Internet Explorer';
        elseif (preg_match('/Firefox/i', $user_agent_string)) $browser = 'Firefox';
        elseif (preg_match('/Edg/i', $user_agent_string)) $browser = 'Edge'; 
        elseif (preg_match('/Chrome/i', $user_agent_string) && !preg_match('/Chromium/i', $user_agent_string)) $browser = 'Chrome';
        elseif (preg_match('/Safari/i', $user_agent_string) && !preg_match('/Chrome/i', $user_agent_string)) $browser = 'Safari';
        elseif (preg_match('/Opera|OPR/i', $user_agent_string)) $browser = 'Opera';
        if (preg_match('/windows nt|win32/i', $user_agent_string)) $os = 'Windows';
        elseif (preg_match('/android/i', $user_agent_string)) $os = 'Android';
        elseif (preg_match('/iphone|ipad|ipod/i', $user_agent_string)) $os = 'iOS';
        elseif (preg_match('/linux/i', $user_agent_string)) $os = 'Linux';
        elseif (preg_match('/macintosh|mac os x/i', $user_agent_string)) $os = 'macOS';
        return ['is_mobile' => (bool)$is_mobile, 'browser' => $browser, 'os' => $os];
    }
    
    private function setSessionCookie($session_token, $expires_in_seconds) {
        if (headers_sent($file_hs_cookie, $line_hs_cookie)) {
            auth_debug_log("setSessionCookie() FAILED. Headers already sent in {$file_hs_cookie} on line {$line_hs_cookie}. Cannot set cookie '{$this->cookie_name}'.");
            return false; 
        }
        $cookie_path = '/'; $cookie_domain = '';
        $is_secure_cookie = false;
        if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) $is_secure_cookie = true;
        elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') $is_secure_cookie = true;
        elseif (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) === 'on') $is_secure_cookie = true;
        // $is_secure_cookie = true; // Forza se il tuo .htaccess garantisce HTTPS

        $cookie_options = [
            'expires' => time() + $expires_in_seconds, 'path' => $cookie_path, 'domain' => $cookie_domain,
            'secure' => $is_secure_cookie, 'httponly' => true, 'samesite' => 'Lax'
        ];
        auth_debug_log("Attempting to set cookie '{$this->cookie_name}'. Value (first 8): " . substr($session_token,0,8) . ". Options: " . json_encode($cookie_options));
        $cookie_set_result = @setcookie($this->cookie_name, $session_token, $cookie_options);
        auth_debug_log("setcookie() for '{$this->cookie_name}' returned: " . ($cookie_set_result ? 'TRUE' : 'FALSE'));
        return $cookie_set_result;
    }
    
    public function logout($session_token = null) {
        auth_debug_log("logout() called. Provided token (first 8): " . ($session_token ? substr($session_token,0,8) : 'None, will check cookie'));
        if (!$session_token) {
            $session_token = $_COOKIE[$this->cookie_name] ?? null;
            if ($session_token) auth_debug_log("Token for logout found in cookie (first 8): " . substr($session_token,0,8));
        }
        if ($session_token) {
            $session_db_data = $this->db->fetch('SELECT user_id FROM user_sessions WHERE session_token = ?', [$session_token]);
            $deleted_rows = $this->db->execute('DELETE FROM user_sessions WHERE session_token = ?', [$session_token]);
            auth_debug_log("Deleted {$deleted_rows} session(s) from DB for token (first 8): " . substr($session_token,0,8));
            if (headers_sent($file_hs_logout, $line_hs_logout)) {
                 auth_debug_log("logout() - Headers already sent in {$file_hs_logout} on line {$line_hs_logout}. Cannot clear cookie '{$this->cookie_name}'.");
            } else {
                $del_cookie_path = '/'; $del_cookie_domain = '';
                $del_is_secure = false;
                if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) $del_is_secure = true;
                elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') $del_is_secure = true;
                elseif (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) === 'on') $del_is_secure = true;
                // $del_is_secure = true; // Forza se necessario
                setcookie($this->cookie_name, '', time() - 7200, $del_cookie_path, $del_cookie_domain, $del_is_secure, true);
                auth_debug_log("Cookie '{$this->cookie_name}' clear instruction sent.");
            }
            if (isset($_COOKIE[$this->cookie_name])) unset($_COOKIE[$this->cookie_name]);
            if ($session_db_data && isset($session_db_data['user_id'])) {
                $this->logActivity($session_db_data['user_id'], 'user_logout', 'User logged out', 'auth', ['token_prefix' => substr($session_token,0,8)]);
            } else {
                 $this->logSecurityEvent('logout_unknown_user_token', 'Logout for token (user not in DB): ' . substr($session_token,0,8));
            }
        } else {
             auth_debug_log("logout() called, but no session token found to logout.");
        }
    }
    
    public function logoutAllSessions($user_id) {
        $deleted_count_all = $this->db->execute('DELETE FROM user_sessions WHERE user_id = ?', [$user_id]);
        auth_debug_log("Logged out all {$deleted_count_all} sessions for User ID: {$user_id}");
        $this->logActivity($user_id, 'logout_all_sessions', "All {$deleted_count_all} sessions terminated by user.", 'security');
        if (isset($_COOKIE[$this->cookie_name])) { // Se la sessione corrente è tra quelle dell'utente
            if (headers_sent($f_las, $l_las)) {
                auth_debug_log("logoutAllSessions() - Headers sent. Cannot clear current session cookie.");
            } else {
                $del_cookie_path_las = '/'; $del_cookie_domain_las = '';
                $del_is_secure_las = false;
                if (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] == 1)) $del_is_secure_las = true;
                elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') $del_is_secure_las = true;
                elseif (isset($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) === 'on') $del_is_secure_las = true;
                setcookie($this->cookie_name, '', time() - 7200, $del_cookie_path_las, $del_cookie_domain_las, $del_is_secure_las, true);
                auth_debug_log("Current session cookie cleared after logoutAllSessions for user {$user_id}.");
            }
            unset($_COOKIE[$this->cookie_name]);
        }
    }
    
    public function getCurrentUser() {
        $session_token_from_cookie = $_COOKIE[$this->cookie_name] ?? null;
        auth_debug_log("getCurrentUser() called. Cookie '{$this->cookie_name}' value (first 8): " . ($session_token_from_cookie ? substr($session_token_from_cookie,0,8)."..." : "NOT SET"));

        if (empty($session_token_from_cookie)) {
            auth_debug_log("getCurrentUser() - Cookie '{$this->cookie_name}' is empty or not set. Returning null.");
            return null;
        }
        if (!is_string($session_token_from_cookie) || strlen($session_token_from_cookie) !== 64) {
            auth_debug_log("getCurrentUser() - Invalid token format/length in cookie. Token: " . htmlspecialchars($session_token_from_cookie) . ". Logging out this token.");
            $this->logout($session_token_from_cookie);
            return null;
        }

        // La query deve corrispondere esattamente allo schema definito in install.php per users e user_sessions
        $sql = '
            SELECT u.id, u.username, u.email, u.password_hash, u.first_name, u.last_name, u.phone, 
                   u.timezone, u.language, u.is_active as user_is_active, u.is_admin, 
                   u.email_verified, u.last_login, u.login_count, u.created_at as user_created_at, u.updated_at as user_updated_at,
                   s.id as session_id, s.session_token as current_session_token, s.expires_at, s.last_activity,
                   s.user_agent as session_user_agent, s.ip_address as session_ip_address, 
                   s.is_mobile as session_is_mobile, s.browser as session_browser, s.os as session_os
            FROM users u 
            JOIN user_sessions s ON u.id = s.user_id 
            WHERE s.session_token = ? AND s.expires_at > datetime("now") AND s.is_active = 1 AND u.is_active = 1 
        '; 
        // Se expires_at è UTC, datetime('now') (che è UTC) è corretto.
        // Se expires_at fosse localtime, e il server in un altro fuso, datetime('now', 'localtime') potrebbe essere necessario.
        // Ma è meglio salvare expires_at in UTC.
        
        auth_debug_log("getCurrentUser() - Querying DB for token (first 8): " . substr($session_token_from_cookie,0,8));
        $user_session_data = $this->db->fetch($sql, [$session_token_from_cookie]);
        
        if (!$user_session_data) {
            auth_debug_log("getCurrentUser() - No valid, active, non-expired session found in DB for token (first 8): " . substr($session_token_from_cookie,0,8) . ". Logging out this token and returning null.");
            $this->logout($session_token_from_cookie);
            return null;
        }
        
        auth_debug_log("getCurrentUser() - VALID session found for User ID: {$user_session_data['id']}. Token (first 8): " . substr($user_session_data['current_session_token'],0,8) . ". Expires: {$user_session_data['expires_at']}");
        
        $this->db->execute('UPDATE user_sessions SET last_activity = CURRENT_TIMESTAMP WHERE id = ?', [$user_session_data['session_id']]);
        unset($user_session_data['password_hash']);
        return $user_session_data;
    }
    
    public function getUserById($user_id) {
        // Assicurati che le colonne selezionate siano quelle che ti servono e che esistano
        return $this->db->fetch('SELECT id, username, email, first_name, last_name, is_active, is_admin, email_verified, timezone, language FROM users WHERE id = ? AND is_active = 1', [$user_id]);
    }
    
    public function requireAuth($redirect_if_fail = true) {
        auth_debug_log("requireAuth() called. Redirect on fail: " . ($redirect_if_fail ? 'Yes' : 'No'));
        $user = $this->getCurrentUser(); // Questo ora ha un logging dettagliato
        if (!$user) {
            auth_debug_log("requireAuth() - User not authenticated.");
            if ($redirect_if_fail) {
                if (!headers_sent($file_hs_reqauth, $line_hs_reqauth)) {
                    $target_redirect_reqauth = 'login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/dashboard.php');
                    auth_debug_log("requireAuth() - Redirecting to: " . $target_redirect_reqauth);
                    header('Location: ' . $target_redirect_reqauth); exit;
                } else {
                    auth_debug_log("requireAuth() - CRITICAL ERROR - Headers already sent from {$file_hs_reqauth} on line {$line_hs_reqauth}! Cannot redirect.");
                    throw new Exception('Authentication required, but headers already sent. Please log in.');
                }
            }
            throw new Exception('Authentication required.');
        }
        auth_debug_log("requireAuth() - User (ID: {$user['id']}) IS authenticated.");
        return $user;
    }
    
    public function requireAdmin($redirect_if_fail = true) {
        auth_debug_log("requireAdmin() called.");
        $user = $this->requireAuth($redirect_if_fail); // Prima verifica se è loggato
        if (!isset($user['is_admin']) || !$user['is_admin']) { // Controllo più sicuro
            auth_debug_log("requireAdmin() - User (ID: {$user['id']}) is NOT an admin.");
            if ($redirect_if_fail) {
                 if (!headers_sent($file_hs_reqadmin, $line_hs_reqadmin)) {
                    // Reindirizza a dashboard con un errore, non a login.
                    $target_redirect_reqadmin = 'dashboard.php?error_msg=' . urlencode('Admin privileges required.');
                    auth_debug_log("requireAdmin() - Redirecting non-admin to: " . $target_redirect_reqadmin);
                    header('Location: ' . $target_redirect_reqadmin); exit;
                } else {
                     auth_debug_log("requireAdmin() - CRITICAL ERROR - Headers already sent from {$file_hs_reqadmin} on line {$line_hs_reqadmin}!");
                     throw new Exception('Admin privileges required, but headers already sent.');
                }
            }
            throw new Exception('Admin privileges required.');
        }
        auth_debug_log("requireAdmin() - User (ID: {$user['id']}) IS an admin.");
        return $user;
    }
    
    public function updateProfile($user_id, $data) {
        auth_debug_log("updateProfile() called for User ID: {$user_id} with data keys: " . implode(', ', array_keys($data)));
        // Assicurati che la tabella 'users' abbia tutte queste colonne.
        // 'avatar_url' è stata aggiunta in alcune versioni precedenti dello schema Database, verifica la coerenza con install.php
        $allowed_fields = ['first_name', 'last_name', 'phone', 'timezone', 'language'/*, 'avatar_url'*/]; 
        $fields = []; $values = [];
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_fields)) { 
                $fields[] = "`{$key}` = ?"; // Usa backtick per i nomi colonna
                $values[] = $value; 
            }
        }
        if (empty($fields)) {
            auth_debug_log("updateProfile() - No allowed fields to update for User ID: {$user_id}.");
            return false;
        }
        $values[] = $user_id; $sql = 'UPDATE users SET ' . implode(', ', $fields) . ', updated_at = CURRENT_TIMESTAMP WHERE id = ?';
        $result = $this->db->execute($sql, $values);
        if ($result) $this->logActivity($user_id, 'profile_updated', 'User profile updated', 'profile');
        return $result;
    }

    public function changePassword($user_id, $current_password, $new_password) {
        auth_debug_log("changePassword() called for User ID: {$user_id}");
        $user = $this->getUserById($user_id); // Questo ora restituisce meno colonne, assicurati che 'password_hash' sia presente
        if (!$user) throw new Exception('User not found.'); // Aggiunto controllo utente
        if (!isset($user['password_hash']) || !password_verify($current_password, $user['password_hash'])) {
             auth_debug_log("changePassword() - Current password incorrect for User ID: {$user_id}");
            throw new Exception('Current password is incorrect');
        }
        if (!$this->isPasswordStrong($new_password)) throw new Exception('New password does not meet strength requirements');
        $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $this->db->execute('UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$new_hash, $user_id]);
        
        $current_session_cookie_token = $_COOKIE[$this->cookie_name] ?? null;
        if ($current_session_cookie_token) {
            // Invalida tutte le ALTRE sessioni, ma mantieni quella corrente.
            $this->db->execute('DELETE FROM user_sessions WHERE user_id = ? AND session_token != ?', [$user_id, $current_session_cookie_token]);
            auth_debug_log("changePassword() - Invalidated other sessions for User ID: {$user_id}, kept current: " . substr($current_session_cookie_token,0,8));
        } else {
            // Se non c'è un cookie (es. cambio password da admin), invalida tutte le sessioni dell'utente.
            $this->logoutAllSessions($user_id); // Questo cancellerà TUTTE le sessioni.
            auth_debug_log("changePassword() - No current session cookie, invalidated ALL sessions for User ID: {$user_id}");
        }
        
        $this->logActivity($user_id, 'password_changed', 'User changed password', 'security');
        auth_debug_log("Password changed successfully for User ID: {$user_id}");
        return true;
    }

    public function requestPasswordReset($email) {
        auth_debug_log("requestPasswordReset() for email: " . htmlspecialchars($email));
        $user = $this->db->fetch('SELECT id, email FROM users WHERE email = ? AND is_active = 1', [$email]);
        if (!$user) {
            auth_debug_log("No active user found for password reset with email: " . htmlspecialchars($email) . ". Returning true to obscure existence.");
            return true; // Non rivelare se l'email esiste
        }
        $reset_token = bin2hex(random_bytes(32)); $expires = date('Y-m-d H:i:s', time() + 3600); // 1 ora
        $this->db->execute('UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?', [$reset_token, $expires, $user['id']]);
        $this->sendPasswordResetEmail($user['email'], $reset_token);
        $this->logActivity($user['id'], 'password_reset_requested', 'Password reset requested', 'security');
        return true;
    }

    public function resetPassword($token, $new_password) {
        auth_debug_log("resetPassword() attempt with token (first 8): " . substr($token,0,8));
        $user = $this->db->fetch('SELECT id FROM users WHERE password_reset_token = ? AND password_reset_expires > datetime("now") AND is_active = 1', [$token]);
        if (!$user) {
            auth_debug_log("Invalid or expired reset token: " . substr($token,0,8));
            throw new Exception('Invalid or expired password reset token.');
        }
        if (!$this->isPasswordStrong($new_password)) throw new Exception('New password does not meet strength requirements.');
        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
        $this->db->execute('UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$password_hash, $user['id']]);
        $this->logoutAllSessions($user['id']); // Invalida tutte le sessioni dopo il reset
        $this->logActivity($user['id'], 'password_reset_completed', 'Password reset completed successfully.', 'security');
        auth_debug_log("Password reset successful for User ID: {$user['id']}");
        return true;
    }

    public function verifyEmail($token) {
        auth_debug_log("verifyEmail() attempt with token (first 8): " . substr($token,0,8));
        $user = $this->db->fetch('SELECT id FROM users WHERE email_verification_token = ? AND is_active = 1', [$token]);
        if (!$user) {
            auth_debug_log("Invalid email verification token: " . substr($token,0,8));
            throw new Exception('Invalid email verification token.');
        }
        $this->db->execute('UPDATE users SET email_verified = 1, email_verification_token = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$user['id']]);
        $this->logActivity($user['id'], 'email_verified', 'Email address verified successfully.', 'auth');
        auth_debug_log("Email verified successfully for User ID: {$user['id']}");
        return true;
    }

    public function resendVerificationEmail($email) {
        auth_debug_log("resendVerificationEmail() for email: " . htmlspecialchars($email));
        $user = $this->db->fetch('SELECT id, email_verified FROM users WHERE email = ? AND is_active = 1', [$email]);
        if (!$user) {
            auth_debug_log("No active user found for email: " . htmlspecialchars($email));
            return false; // O lancia un'eccezione/messaggio più specifico
        }
        if ($user['email_verified']) {
            auth_debug_log("Email already verified for: " . htmlspecialchars($email));
            throw new Exception('This email address has already been verified.');
        }
        $verification_token = bin2hex(random_bytes(32));
        $this->db->execute('UPDATE users SET email_verification_token = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$verification_token, $user['id']]);
        $this->sendVerificationEmail($email, $verification_token);
        $this->logActivity($user['id'], 'verification_email_resent', 'Verification email resent.', 'auth');
        return true;
    }
    
    public function getUserSessions($user_id) {
        return $this->db->fetchAll('SELECT id, user_agent, ip_address, browser, os, is_mobile, created_at, last_activity, expires_at, is_active FROM user_sessions WHERE user_id = ? AND expires_at > datetime("now") ORDER BY last_activity DESC', [$user_id]);
    }

    public function terminateSession($user_id, $session_id) {
        auth_debug_log("terminateSession() called by User ID: {$user_id} for Session ID: {$session_id}");
        $result = $this->db->execute('DELETE FROM user_sessions WHERE id = ? AND user_id = ?', [$session_id, $user_id]);
        if ($result) $this->logActivity($user_id, 'session_terminated_manual', "User terminated session ID {$session_id}", 'security');
        return $result;
    }

    public function logActivity($user_id, $action, $description = '', $category = 'general', $metadata = []) {
        // Evita di loggare se $this->db non è pronto (es. durante il costruttore se il DB fallisce)
        if (!$this->db) return false;
        try {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown/CLI';
            $request_method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
            $request_uri = $_SERVER['REQUEST_URI'] ?? 'cli_script';

            return $this->db->execute(
                'INSERT INTO user_activity (user_id, action, description, category, ip_address, user_agent, request_method, request_uri, metadata) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$user_id, $action, $description, $category, $ip_address, $user_agent, $request_method, $request_uri, json_encode($metadata)]
            );
        } catch (Exception $e) { 
            auth_debug_log("logActivity failed for user {$user_id}, action {$action}: " . $e->getMessage());
            return false;
        }
    }

    public function logSecurityEvent($action, $description) {
        if (!$this->db) return false;
         try {
            return $this->db->execute(
                'INSERT INTO user_activity (user_id, action, description, category, ip_address, user_agent, request_method, request_uri) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [0, $action, $description, 'security', $_SERVER['REMOTE_ADDR'] ?? 'CLI', $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown/CLI', $_SERVER['REQUEST_METHOD'] ?? 'CLI', $_SERVER['REQUEST_URI'] ?? 'cli_script']
            );
        } catch (Exception $e) { 
            auth_debug_log("logSecurityEvent failed for action {$action}: " . $e->getMessage());
            return false;
        }
    }

    public function getUserActivity($user_id, $limit = 50, $category = null) {
        $where_clause = $category ? 'AND category = :category' : '';
        $params = [':user_id' => $user_id, ':limit' => (int)$limit];
        if ($category) $params[':category'] = $category;
        return $this->db->fetchAll("SELECT action, description, category, ip_address, created_at FROM user_activity WHERE user_id = :user_id {$where_clause} ORDER BY created_at DESC LIMIT :limit", $params);
    }

    public function cleanupExpiredSessions() {
        try { 
            $deleted_count = $this->db->execute('DELETE FROM user_sessions WHERE expires_at < datetime("now")'); 
            if ($deleted_count > 0) auth_debug_log("cleanupExpiredSessions: Deleted {$deleted_count} expired session(s).");
            return $deleted_count;
        } catch (Exception $e) { 
            auth_debug_log("cleanupExpiredSessions failed: ".$e->getMessage()); return false;
        }
    }

    public function cleanupOldActivity($days = 90) {
        try { 
            $deleted_count = $this->db->execute('DELETE FROM user_activity WHERE created_at < datetime("now", "-' . intval($days) . ' days")'); 
            if ($deleted_count > 0) auth_debug_log("cleanupOldActivity: Deleted {$deleted_count} activity log(s) older than {$days} days.");
            return $deleted_count;
        } catch (Exception $e) { 
            auth_debug_log("cleanupOldActivity failed: ".$e->getMessage()); return false;
        }
    }

    private function sendVerificationEmail($email, $token) {
        $app_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $script_path = rtrim(dirname($_SERVER['PHP_SELF'] ?? ''), '/\\'); // Rimuove slash finali
        if ($script_path === '/' || $script_path === '\\') $script_path = ''; // Evita doppio slash se è nella root

        $verification_link = $app_url . $script_path . '/login.php?action=verify_email&token=' . urlencode($token);
        auth_debug_log("[SIMULATING] Sending Verification email to {$email}. Link: {$verification_link}");
        // mail($email, "Verify Your Email - AppName", "Please click here to verify: " . $verification_link, "From: no-reply@" . ($_SERVER['SERVER_NAME'] ?? 'example.com'));
    }

    private function sendPasswordResetEmail($email, $token) {
        $app_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://" . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $script_path = rtrim(dirname($_SERVER['PHP_SELF'] ?? ''), '/\\');
        if ($script_path === '/' || $script_path === '\\') $script_path = '';

        $reset_link = $app_url . $script_path . '/login.php?action=reset_password&token=' . urlencode($token);
        auth_debug_log("[SIMULATING] Sending Password Reset email to {$email}. Link: {$reset_link}");
        // mail($email, "Password Reset Request - AppName", "Please click here to reset your password: " . $reset_link, "From: no-reply@" . ($_SERVER['SERVER_NAME'] ?? 'example.com'));
    }

    public function getAllUsers($limit = 50, $offset = 0) {
        return $this->db->fetchAll('SELECT id, username, email, first_name, last_name, is_active, is_admin, email_verified, last_login, login_count, created_at FROM users ORDER BY created_at DESC LIMIT ? OFFSET ?', [(int)$limit, (int)$offset]);
    }

    public function getUsersCount() {
        return $this->db->fetchColumn('SELECT COUNT(*) FROM users');
    }

    public function toggleUserStatus($user_id, $is_active) {
        $admin_user = $this->getCurrentUser(); // Ottieni l'utente admin che esegue l'azione
        $admin_user_id = $admin_user ? $admin_user['id'] : 0; // 0 se non loggato (improbabile se si arriva qui)
        
        $result = $this->db->execute('UPDATE users SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$is_active ? 1 : 0, $user_id]);
        if ($result) {
            $this->logActivity($admin_user_id, $is_active ? 'admin_user_enabled' : 'admin_user_disabled', "Admin (ID:{$admin_user_id}) " .($is_active ? 'enabled' : 'disabled'). " user account ID {$user_id}", 'admin');
        }
        return $result;
    }

    public function toggleAdminStatus($user_id, $is_admin) {
        $admin_user = $this->getCurrentUser();
        $admin_user_id = $admin_user ? $admin_user['id'] : 0;
        
        $result = $this->db->execute('UPDATE users SET is_admin = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?', [$is_admin ? 1 : 0, $user_id]);
        if ($result) {
            $this->logActivity($admin_user_id, $is_admin ? 'admin_privileges_granted' : 'admin_privileges_revoked', "Admin (ID:{$admin_user_id}) " .($is_admin ? 'granted' : 'revoked'). " admin privileges for User ID {$user_id}", 'admin');
        }
        return $result;
    }
}
?>