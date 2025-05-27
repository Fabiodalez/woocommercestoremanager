<?php
// install.php - Sistema di Installazione e Setup Completo
session_start();

// Evita reinstallazione se già completata (controlla il file .installed)
if (file_exists(__DIR__ . '/.installed') && !isset($_GET['force'])) { // MODIFICA QUI!
    header('Location: login.php');
    exit;
}

$step = $_GET['step'] ?? 1;
$errors = [];
$success_messages = [];

// Verifica requisiti di sistema
function checkSystemRequirements() {
    return [
        'PHP Version (>=7.4)' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'JSON Extension' => extension_loaded('json'),
        'cURL Extension' => extension_loaded('curl'),
        'OpenSSL Extension' => extension_loaded('openssl'),
        'SQLite3 Extension' => extension_loaded('sqlite3'),
        'PDO Extension' => extension_loaded('pdo'),
        'PDO SQLite Driver' => extension_loaded('pdo_sqlite'),
        'Directory Writable' => is_writable(__DIR__),
        'Memory Limit (>=128M)' => (int)ini_get('memory_limit') >= 128 || ini_get('memory_limit') == -1
    ];
}

// Crea database e tabelle
function createDatabase() {
    $db_path = __DIR__ . '/database.db';
    
    try {
        $pdo = new PDO('sqlite:' . $db_path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Schema del database
        $schema = "
        -- Tabella utenti
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            first_name VARCHAR(50),
            last_name VARCHAR(50),
            phone VARCHAR(20),
            timezone VARCHAR(50) DEFAULT 'UTC',
            language VARCHAR(10) DEFAULT 'en',
            is_active INTEGER DEFAULT 1,
            is_admin INTEGER DEFAULT 0,
            email_verified INTEGER DEFAULT 0,
            email_verification_token VARCHAR(255),
            password_reset_token VARCHAR(255),
            password_reset_expires DATETIME,
            failed_login_attempts INTEGER DEFAULT 0,
            last_failed_login DATETIME,
            last_login DATETIME,
            login_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        -- Tabella configurazioni utente
        CREATE TABLE IF NOT EXISTS user_configs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            store_name VARCHAR(100),
            store_url VARCHAR(255),
            consumer_key VARCHAR(255),
            consumer_secret VARCHAR(255),
            connected INTEGER DEFAULT 0,
            last_test DATETIME,
            last_sync DATETIME,
            connection_errors TEXT,
            api_version VARCHAR(10) DEFAULT 'v3',
            timeout INTEGER DEFAULT 30,
            rate_limit INTEGER DEFAULT 10,
            settings TEXT DEFAULT '{}',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        -- Tabella sessioni utente
        CREATE TABLE IF NOT EXISTS user_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            session_token VARCHAR(255) UNIQUE NOT NULL,
            refresh_token VARCHAR(255),
            user_agent TEXT,
            ip_address VARCHAR(45),
            is_mobile INTEGER DEFAULT 0,
            browser VARCHAR(50),
            os VARCHAR(50),
            is_active INTEGER DEFAULT 1,
            expires_at DATETIME NOT NULL,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        -- Tabella attività utente
        CREATE TABLE IF NOT EXISTS user_activity (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            action VARCHAR(100) NOT NULL,
            description TEXT,
            category VARCHAR(50) DEFAULT 'general',
            ip_address VARCHAR(45),
            user_agent TEXT,
            request_method VARCHAR(10),
            request_uri TEXT,
            metadata TEXT DEFAULT '{}',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        -- Tabella preferenze utente
        CREATE TABLE IF NOT EXISTS user_preferences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            preference_key VARCHAR(100) NOT NULL,
            preference_value TEXT,
            preference_type VARCHAR(20) DEFAULT 'string',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id, preference_key)
        );

        -- Tabella notifiche utente
        CREATE TABLE IF NOT EXISTS user_notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(20) DEFAULT 'info',
            category VARCHAR(50) DEFAULT 'system',
            action_url VARCHAR(255),
            action_text VARCHAR(100),
            is_read INTEGER DEFAULT 0,
            is_dismissed INTEGER DEFAULT 0,
            read_at DATETIME,
            expires_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        -- Tabella impostazioni di sistema
        CREATE TABLE IF NOT EXISTS system_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            setting_type VARCHAR(20) DEFAULT 'string',
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        -- Tabella collaboratori store
        CREATE TABLE IF NOT EXISTS store_collaborators (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            store_config_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            invited_by INTEGER NOT NULL,
            role VARCHAR(20) DEFAULT 'viewer',
            permissions TEXT DEFAULT '[]',
            status VARCHAR(20) DEFAULT 'pending',
            invitation_token VARCHAR(255),
            invitation_expires DATETIME,
            last_access DATETIME,
            access_count INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (store_config_id) REFERENCES user_configs(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(store_config_id, user_id)
        );

        -- Tabella rate limiting API
        CREATE TABLE IF NOT EXISTS api_rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            endpoint VARCHAR(100) NOT NULL,
            requests_count INTEGER DEFAULT 1,
            window_start DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id, endpoint, window_start)
        );

        -- Indici per performance
        CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
        CREATE INDEX IF NOT EXISTS idx_users_username ON users(username);
        CREATE INDEX IF NOT EXISTS idx_sessions_token ON user_sessions(session_token);
        CREATE INDEX IF NOT EXISTS idx_sessions_user ON user_sessions(user_id);
        CREATE INDEX IF NOT EXISTS idx_activity_user ON user_activity(user_id);
        CREATE INDEX IF NOT EXISTS idx_activity_date ON user_activity(created_at);
        CREATE INDEX IF NOT EXISTS idx_preferences_user ON user_preferences(user_id);
        CREATE INDEX IF NOT EXISTS idx_notifications_user ON user_notifications(user_id);
        CREATE INDEX IF NOT EXISTS idx_settings_key ON system_settings(setting_key);
        CREATE INDEX IF NOT EXISTS idx_collaborators_store ON store_collaborators(store_config_id);
        CREATE INDEX IF NOT EXISTS idx_collaborators_user ON store_collaborators(user_id);
        CREATE INDEX IF NOT EXISTS idx_rate_limits_user ON api_rate_limits(user_id);
        ";

        $pdo->exec($schema);
        return $pdo;
        
    } catch (Exception $e) {
        throw new Exception('Database creation failed: ' . $e->getMessage());
    }
}

// Inserisce impostazioni di sistema di default
function insertDefaultSettings($pdo) {
    $default_settings = [
        ['app_name', 'WooCommerce Store Manager', 'string', 'Application name'],
        ['app_version', '1.0.0', 'string', 'Application version'],
        ['maintenance_mode', '0', 'boolean', 'Enable maintenance mode'],
        ['registration_enabled', '1', 'boolean', 'Allow new user registrations'],
        ['email_verification_required', '0', 'boolean', 'Require email verification'],
        ['session_timeout', '1800', 'integer', 'Session timeout in seconds'],
        ['max_login_attempts', '5', 'integer', 'Maximum login attempts before lockout'],
        ['lockout_duration', '900', 'integer', 'Lockout duration in seconds'],
        ['cleanup_sessions_days', '30', 'integer', 'Cleanup expired sessions after days'],
        ['cleanup_activity_days', '90', 'integer', 'Cleanup old activity logs after days'],
        ['api_rate_limit_requests', '100', 'integer', 'API requests per window'],
        ['api_rate_limit_window', '3600', 'integer', 'API rate limit window in seconds'],
        ['debug_mode', '0', 'boolean', 'Enable debug mode'],
        ['default_timezone', 'UTC', 'string', 'Default timezone for new users'],
        ['default_language', 'en', 'string', 'Default language for new users'],
        ['default_currency', 'USD', 'string', 'Default currency for new users'],
        ['backup_retention_days', '30', 'integer', 'Backup retention in days'],
        ['log_level', 'INFO', 'string', 'Logging level'],
        ['security_headers_enabled', '1', 'boolean', 'Enable security headers'],
        ['installation_date', date('Y-m-d H:i:s'), 'string', 'Installation date and time']
    ];

    $stmt = $pdo->prepare('INSERT OR IGNORE INTO system_settings (setting_key, setting_value, setting_type, description) VALUES (?, ?, ?, ?)');
    
    foreach ($default_settings as $setting) {
        $stmt->execute($setting);
    }
}

// Crea utente amministratore
function createAdminUser($pdo, $data) {
    // Valida i dati
    if (empty($data['username']) || empty($data['email']) || empty($data['password'])) {
        throw new Exception('Username, email and password are required');
    }
    
    if (strlen($data['username']) < 3 || strlen($data['username']) > 50) {
        throw new Exception('Username must be between 3 and 50 characters');
    }
    
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $data['username'])) {
        throw new Exception('Username can only contain letters, numbers, underscores and hyphens');
    }
    
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email address');
    }
    
    if (strlen($data['password']) < 8) {
        throw new Exception('Password must be at least 8 characters long');
    }
    
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $data['password'])) {
        throw new Exception('Password must contain at least one uppercase letter, one lowercase letter, and one number');
    }
    
    try {
        $pdo->beginTransaction();
        
        // Crea utente amministratore
        $password_hash = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare('
            INSERT INTO users (username, email, password_hash, first_name, last_name, 
                             timezone, language, is_active, is_admin, email_verified, login_count) 
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, 1, 0)
        ');
        
        $stmt->execute([
            $data['username'],
            $data['email'],
            $password_hash,
            $data['first_name'] ?? '',
            $data['last_name'] ?? '',
            $data['timezone'] ?? 'UTC',
            $data['language'] ?? 'en'
        ]);
        
        $user_id = $pdo->lastInsertId();
        
        // Crea configurazione utente di default
        $default_settings = json_encode([
            'timezone' => $data['timezone'] ?? 'UTC',
            'language' => $data['language'] ?? 'en',
            'currency' => $data['currency'] ?? 'USD',
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i:s',
            'products_per_page' => 20,
            'orders_per_page' => 20,
            'theme' => 'light',
            'sidebar_collapsed' => false,
            'notifications_enabled' => true,
            'email_notifications' => true,
            'auto_sync' => false,
            'sync_interval' => 300
        ]);
        
        $stmt = $pdo->prepare('INSERT INTO user_configs (user_id, settings) VALUES (?, ?)');
        $stmt->execute([$user_id, $default_settings]);
        
        // Log dell'installazione
        $stmt = $pdo->prepare('
            INSERT INTO user_activity (user_id, action, description, category, ip_address) 
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $user_id,
            'system_installed',
            'WooCommerce Store Manager installed successfully',
            'system',
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        
        // Notifica di benvenuto
        $stmt = $pdo->prepare('
            INSERT INTO user_notifications (user_id, title, message, type, category) 
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $user_id,
            'Welcome to WooCommerce Store Manager!',
            'Your account has been created successfully. You can now configure your WooCommerce stores and start managing your products.',
            'success',
            'welcome'
        ]);
        
        $pdo->commit();
        return $user_id;
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
}

// Gestione dei form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['action'] ?? '') {
            case 'check_requirements':
                $requirements = checkSystemRequirements();
                if (array_product($requirements)) {
                    header('Location: install.php?step=2');
                    exit;
                } else {
                    $errors[] = 'System requirements not met. Please fix the issues above.';
                }
                break;
                
            case 'create_database':
                $pdo = createDatabase();
                insertDefaultSettings($pdo);
                $success_messages[] = 'Database created successfully!';
                header('Location: install.php?step=3'); // Reindirizza al passo 3
                exit;
                
            case 'create_admin':
                if (!file_exists(__DIR__ . '/database.db')) {
                    throw new Exception('Database not found. Please run the database setup first.');
                }
                
                $pdo = new PDO('sqlite:' . __DIR__ . '/database.db');
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                
                $admin_data = [
                    'username' => trim($_POST['username'] ?? ''),
                    'email' => trim($_POST['email'] ?? ''),
                    'password' => $_POST['password'] ?? '',
                    'first_name' => trim($_POST['first_name'] ?? ''),
                    'last_name' => trim($_POST['last_name'] ?? ''),
                    'timezone' => $_POST['timezone'] ?? 'UTC',
                    'language' => $_POST['language'] ?? 'en',
                    'currency' => $_POST['currency'] ?? 'USD'
                ];
                
                if ($_POST['password'] !== $_POST['confirm_password']) {
                    throw new Exception('Passwords do not match');
                }
                
                $user_id = createAdminUser($pdo, $admin_data);
                $success_messages[] = 'Administrator account created successfully!';
                header('Location: install.php?step=4'); // Reindirizza al passo 4
                exit;
                
            case 'finalize_installation':
                // Crea file di lock per evitare reinstallazioni
                file_put_contents(__DIR__ . '/.installed', date('Y-m-d H:i:s'));
                
                // Reindirizza al login
                header('Location: login.php?installation=complete');
                exit;
        }
        
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// Ottieni requisiti per la vista
$requirements = checkSystemRequirements();
$all_requirements_met = array_product($requirements);

// Timezones disponibili
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

// Lingue disponibili
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

// Valute disponibili
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

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation - WooCommerce Store Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .step-indicator {
            transition: all 0.3s ease;
        }
        .step-indicator.active {
            background-color: #3b82f6;
            color: white;
        }
        .step-indicator.completed {
            background-color: #10b981;
            color: white;
        }
        .requirement-check {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid #e5e7eb;
        }
        .requirement-check:last-child {
            border-bottom: none;
        }
        .status-ok { color: #10b981; font-weight: 600; }
        .status-error { color: #ef4444; font-weight: 600; }
        .installation-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .password-strength {
            height: 4px;
            transition: all 0.3s ease;
        }
        .password-strength.weak { background-color: #ef4444; width: 25%; }
        .password-strength.fair { background-color: #f59e0b; width: 50%; }
        .password-strength.good { background-color: #10b981; width: 75%; }
        .password-strength.strong { background-color: #059669; width: 100%; }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <div class="installation-header text-white py-8">
        <div class="max-w-4xl mx-auto px-4 text-center">
            <h1 class="text-3xl font-bold mb-2">WooCommerce Store Manager</h1>
            <p class="text-lg opacity-90">Multi-User Installation & Setup Wizard</p>
        </div>
    </div>

    <!-- Step Indicator -->
    <div class="max-w-4xl mx-auto px-4 py-6">
        <div class="flex items-center justify-center space-x-4 mb-8">
            <div class="step-indicator <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?> w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold border-2">
                <?php echo $step > 1 ? '✓' : '1'; ?>
            </div>
            <div class="w-12 h-1 bg-gray-300 <?php echo $step > 1 ? 'bg-green-500' : ''; ?>"></div>
            
            <div class="step-indicator <?php echo $step >= 2 ? ($step > 2 ? 'completed' : 'active') : ''; ?> w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold border-2">
                <?php echo $step > 2 ? '✓' : '2'; ?>
            </div>
            <div class="w-12 h-1 bg-gray-300 <?php echo $step > 2 ? 'bg-green-500' : ''; ?>"></div>
            
            <div class="step-indicator <?php echo $step >= 3 ? ($step > 3 ? 'completed' : 'active') : ''; ?> w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold border-2">
                <?php echo $step > 3 ? '✓' : '3'; ?>
            </div>
            <div class="w-12 h-1 bg-gray-300 <?php echo $step > 3 ? 'bg-green-500' : ''; ?>"></div>
            
            <div class="step-indicator <?php echo $step >= 4 ? 'active' : ''; ?> w-10 h-10 rounded-full flex items-center justify-center text-sm font-bold border-2">
                4
            </div>
        </div>
        
        <div class="text-center text-sm text-gray-600 mb-8">
            Step <?php echo $step; ?> of 4: 
            <?php 
            $step_names = [
                1 => 'System Requirements',
                2 => 'Database Setup',
                3 => 'Administrator Account',
                4 => 'Installation Complete'
            ];
            echo $step_names[$step];
            ?>
        </div>
    </div>

    <div class="max-w-2xl mx-auto px-4 pb-12">
        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
            <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <?php foreach ($errors as $error): ?>
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path>
                        </svg>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Success Messages -->
        <?php if (!empty($success_messages)): ?>
            <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                <?php foreach ($success_messages as $message): ?>
                    <div class="flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                        </svg>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Step Content -->
        <div class="bg-white shadow-lg rounded-lg overflow-hidden">
            <?php if ($step == 1): ?>
                <!-- Step 1: System Requirements -->
                <div class="p-6">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">System Requirements Check</h2>
                    
                    <div class="space-y-1 mb-6">
                        <?php foreach ($requirements as $name => $status): ?>
                            <div class="requirement-check">
                                <span class="text-gray-700"><?php echo $name; ?></span>
                                <span class="<?php echo $status ? 'status-ok' : 'status-error'; ?>">
                                    <?php echo $status ? '✓ Passed' : '✗ Failed'; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mb-6 p-4 bg-blue-50 rounded-lg">
                        <h3 class="font-semibold text-blue-900 mb-2">System Information</h3>
                        <div class="text-sm text-blue-800 space-y-1">
                            <div>PHP Version: <strong><?php echo PHP_VERSION; ?></strong></div>
                            <div>Server: <strong><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></strong></div>
                            <div>Operating System: <strong><?php echo PHP_OS; ?></strong></div>
                            <div>Memory Limit: <strong><?php echo ini_get('memory_limit'); ?></strong></div>
                            <div>Max Execution Time: <strong><?php echo ini_get('max_execution_time'); ?>s</strong></div>
                        </div>
                    </div>

                    <?php if ($all_requirements_met): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="check_requirements">
                            <button type="submit" class="w-full bg-blue-600 text-white py-3 px-4 rounded-lg hover:bg-blue-700 font-semibold">
                                Continue to Database Setup
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="p-4 bg-red-50 rounded-lg">
                            <p class="text-red-800 font-semibold mb-2">Requirements Not Met</p>
                            <p class="text-red-700 text-sm">Please fix the failed requirements above before proceeding with the installation.</p>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($step == 2): ?>
                <!-- Step 2: Database Setup -->
                <div class="p-6">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">Database Setup</h2>
                    
                    <div class="mb-6">
                        <div class="bg-blue-50 p-4 rounded-lg mb-4">
                            <h3 class="font-semibold text-blue-900 mb-2">Database Configuration</h3>
                            <p class="text-blue-800 text-sm">
                                The system will create a SQLite database file with all required tables for the multi-user store management system.
                            </p>
                        </div>
                        
                        <div class="space-y-3 text-sm text-gray-600">
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                                User management and authentication system
                            </div>
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                                Store configurations and WooCommerce connections
                            </div>
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                                Session management and security features
                            </div>
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                                Activity logging and notification system
                            </div>
                            <div class="flex items-center">
                                <svg class="w-4 h-4 mr-2 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                </svg>
                                Store collaboration and sharing features
                            </div>
                        </div>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="create_database">
                        <button type="submit" class="w-full bg-green-600 text-white py-3 px-4 rounded-lg hover:bg-green-700 font-semibold">
                            Create Database & Tables
                        </button>
                    </form>
                </div>

            <?php elseif ($step == 3): ?>
                <!-- Step 3: Administrator Account -->
                <div class="p-6">
                    <h2 class="text-2xl font-bold text-gray-900 mb-6">Create Administrator Account</h2>
                    
                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="action" value="create_admin">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="first_name" class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
                                <input type="text" id="first_name" name="first_name" 
                                       value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            <div>
                                <label for="last_name" class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
                                <input type="text" id="last_name" name="last_name" 
                                       value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                        
                        <div>
                            <label for="username" class="block text-sm font-medium text-gray-700 mb-1">Username *</label>
                            <input type="text" id="username" name="username" required minlength="3" maxlength="50"
                                   value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                                   pattern="^[a-zA-Z0-9_-]+$"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <p class="mt-1 text-xs text-gray-500">3-50 characters, letters, numbers, underscores and hyphens only</p>
                        </div>
                        
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email Address *</label>
                            <input type="email" id="email" name="email" required
                                   value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div>
                            <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Password *</label>
                            <input type="password" id="password" name="password" required minlength="8"
                                   pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                   onkeyup="checkPasswordStrength(this.value)">
                            <div class="mt-2 bg-gray-200 rounded-full h-1">
                                <div id="password-strength" class="password-strength bg-gray-300 rounded-full h-1"></div>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Must contain uppercase, lowercase, and number. Minimum 8 characters.</p>
                        </div>
                        
                        <div>
                            <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirm Password *</label>
                            <input type="password" id="confirm_password" name="confirm_password" required minlength="8"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="timezone" class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
                                <select id="timezone" name="timezone" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <?php foreach ($timezones as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo ($_POST['timezone'] ?? 'UTC') === $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="language" class="block text-sm font-medium text-gray-700 mb-1">Language</label>
                                <select id="language" name="language" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <?php foreach ($languages as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo ($_POST['language'] ?? 'en') === $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div>
                                <label for="currency" class="block text-sm font-medium text-gray-700 mb-1">Currency</label>
                                <select id="currency" name="currency" 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                    <?php foreach ($currencies as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo ($_POST['currency'] ?? 'USD') === $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        
                        <button type="submit" class="w-full bg-purple-600 text-white py-3 px-4 rounded-lg hover:bg-purple-700 font-semibold">
                            Create Administrator Account
                        </button>
                    </form>
                </div>

            <?php elseif ($step == 4): ?>
                <!-- Step 4: Installation Complete -->
                <div class="p-6 text-center">
                    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <svg class="w-10 h-10 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                        </svg>
                    </div>
                    
                    <h2 class="text-2xl font-bold text-gray-900 mb-4">Installation Complete!</h2>
                    
                    <p class="text-gray-600 mb-6">
                        WooCommerce Store Manager has been successfully installed and configured. 
                        Your administrator account is ready to use.
                    </p>
                    
                    <div class="bg-gray-50 p-4 rounded-lg mb-6 text-left">
                        <h3 class="font-semibold text-gray-900 mb-3">What's Next?</h3>
                        <ul class="space-y-2 text-sm text-gray-700">
                            <li class="flex items-start">
                                <span class="flex-shrink-0 w-5 h-5 text-blue-600 mr-2">1.</span>
                                Log in with your administrator account
                            </li>
                            <li class="flex items-start">
                                <span class="flex-shrink-0 w-5 h-5 text-blue-600 mr-2">2.</span>
                                Configure your first WooCommerce store connection
                            </li>
                            <li class="flex items-start">
                                <span class="flex-shrink-0 w-5 h-5 text-blue-600 mr-2">3.</span>
                                Invite team members to collaborate (optional)
                            </li>
                            <li class="flex items-start">
                                <span class="flex-shrink-0 w-5 h-5 text-blue-600 mr-2">4.</span>
                                Start managing your products and orders
                            </li>
                        </ul>
                    </div>
                    
                    <div class="bg-blue-50 p-4 rounded-lg mb-6">
                        <h3 class="font-semibold text-blue-900 mb-2">Security Reminder</h3>
                        <p class="text-sm text-blue-800">
                            For security reasons, consider removing or restricting access to this installation file 
                            after completing the setup.
                        </p>
                    </div>
                    
                    <form method="POST">
                        <input type="hidden" name="action" value="finalize_installation">
                        <button type="submit" class="bg-blue-600 text-white py-3 px-6 rounded-lg hover:bg-blue-700 font-semibold">
                            Go to Login Page
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Password strength checker
        function checkPasswordStrength(password) {
            const strengthBar = document.getElementById('password-strength');
            if (!strengthBar) return;
            
            let strength = 0;
            let strengthClass = '';
            
            // Check length
            if (password.length >= 8) strength++;
            
            // Check for lowercase
            if (/[a-z]/.test(password)) strength++;
            
            // Check for uppercase
            if (/[A-Z]/.test(password)) strength++;
            
            // Check for numbers
            if (/\d/.test(password)) strength++;
            
            // Check for special characters
            if (/[^a-zA-Z0-9]/.test(password)) strength++;
            
            // Set strength class
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
            
            strengthBar.className = 'password-strength ' + strengthClass;
        }
        
        // Password confirmation validation
        document.addEventListener('DOMContentLoaded', function() {
            const password = document.getElementById('password');
            const confirmPassword = document.getElementById('confirm_password');
            
            if (password && confirmPassword) {
                function validatePasswords() {
                    if (password.value !== confirmPassword.value) {
                        confirmPassword.setCustomValidity('Passwords do not match');
                    } else {
                        confirmPassword.setCustomValidity('');
                    }
                }
                
                password.addEventListener('input', validatePasswords);
                confirmPassword.addEventListener('input', validatePasswords);
            }
        });
        
        // Form validation enhancement
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitButton = form.querySelector('button[type="submit"]');
                    if (submitButton) {
                        submitButton.disabled = true;
                        submitButton.innerHTML = submitButton.innerHTML + ' <span class="ml-2">...</span>';
                    }
                });
            });
        });
    </script>
</body>
</html>