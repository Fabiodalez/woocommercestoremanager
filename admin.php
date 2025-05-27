<?php
// admin.php - Administration Panel
session_start();

require_once 'database.php';
require_once 'config.php';
require_once 'auth.php';

// Initialize system and require admin access
Config::init();
$user = Config::requireAdmin();
$auth = new Auth();
$db = Database::getInstance();

$errors = [];
$success_messages = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_system_settings':
                $settings = [
                    'app_name' => trim($_POST['app_name'] ?? ''),
                    'maintenance_mode' => isset($_POST['maintenance_mode']),
                    'registration_enabled' => isset($_POST['registration_enabled']),
                    'email_verification_required' => isset($_POST['email_verification_required']),
                    'session_timeout' => intval($_POST['session_timeout'] ?? 1800),
                    'max_login_attempts' => intval($_POST['max_login_attempts'] ?? 5),
                    'lockout_duration' => intval($_POST['lockout_duration'] ?? 900),
                    'cleanup_sessions_days' => intval($_POST['cleanup_sessions_days'] ?? 30),
                    'cleanup_activity_days' => intval($_POST['cleanup_activity_days'] ?? 90),
                    'api_rate_limit_requests' => intval($_POST['api_rate_limit_requests'] ?? 100),
                    'api_rate_limit_window' => intval($_POST['api_rate_limit_window'] ?? 3600)
                ];
                
                foreach ($settings as $key => $value) {
                    $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string');
                    Config::setSystemSetting($key, $value, $type);
                }
                
                $success_messages[] = 'System settings updated successfully!';
                break;
                
            case 'toggle_user_status':
                $user_id = intval($_POST['user_id'] ?? 0);
                $is_active = isset($_POST['is_active']);
                
                if ($user_id <= 0) {
                    throw new Exception('Invalid user ID');
                }
                
                if ($user_id === $user['id']) {
                    throw new Exception('Cannot disable your own account');
                }
                
                $auth->toggleUserStatus($user_id, $is_active);
                $success_messages[] = 'User status updated successfully!';
                break;
                
            case 'toggle_admin_status':
                $user_id = intval($_POST['user_id'] ?? 0);
                $is_admin = isset($_POST['is_admin']);
                
                if ($user_id <= 0) {
                    throw new Exception('Invalid user ID');
                }
                
                if ($user_id === $user['id']) {
                    throw new Exception('Cannot change your own admin status');
                }
                
                $auth->toggleAdminStatus($user_id, $is_admin);
                $success_messages[] = 'Admin status updated successfully!';
                break;
                
            case 'run_maintenance':
                $results = $db->runMaintenance();
                $total_cleaned = array_sum($results);
                $success_messages[] = "Maintenance completed! Cleaned up {$total_cleaned} records.";
                break;
                
            case 'delete_user':
                $user_id = intval($_POST['user_id'] ?? 0);
                
                if ($user_id <= 0) {
                    throw new Exception('Invalid user ID');
                }
                
                if ($user_id === $user['id']) {
                    throw new Exception('Cannot delete your own account');
                }
                
                // This will cascade delete all related data
                $db->execute('DELETE FROM users WHERE id = ?', [$user_id]);
                $success_messages[] = 'User deleted successfully!';
                break;
        }
        
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// Get system statistics
$stats = [
    'total_users' => $db->count('users'),
    'active_users' => $db->count('users', ['is_active' => 1]),
    'admin_users' => $db->count('users', ['is_admin' => 1]),
    'verified_users' => $db->count('users', ['email_verified' => 1]),
    'total_stores' => $db->count('user_configs'),
    'connected_stores' => $db->count('user_configs', ['connected' => 1]),
    'active_sessions' => $db->count('user_sessions', ['is_active' => 1]),
    'total_collaborations' => $db->count('store_collaborators'),
    'pending_invitations' => $db->count('store_collaborators', ['status' => 'pending']),
    'total_activity' => $db->count('user_activity')
];

// Get recent users
$recent_users = $auth->getAllUsers(10);

// Get recent activity
$recent_activity = $db->fetchAll('
    SELECT ua.*, u.username, u.first_name, u.last_name 
    FROM user_activity ua
    LEFT JOIN users u ON ua.user_id = u.id
    ORDER BY ua.created_at DESC 
    LIMIT 20
');

// Get system info
$system_info = $db->getDatabaseInfo();

// Get system settings
$system_settings = [
    'app_name' => Config::getSystemSetting('app_name', 'WooCommerce Store Manager'),
    'maintenance_mode' => Config::getSystemSetting('maintenance_mode', false),
    'registration_enabled' => Config::getSystemSetting('registration_enabled', true),
    'email_verification_required' => Config::getSystemSetting('email_verification_required', false),
    'session_timeout' => Config::getSystemSetting('session_timeout', 1800),
    'max_login_attempts' => Config::getSystemSetting('max_login_attempts', 5),
    'lockout_duration' => Config::getSystemSetting('lockout_duration', 900),
    'cleanup_sessions_days' => Config::getSystemSetting('cleanup_sessions_days', 30),
    'cleanup_activity_days' => Config::getSystemSetting('cleanup_activity_days', 90),
    'api_rate_limit_requests' => Config::getSystemSetting('api_rate_limit_requests', 100),
    'api_rate_limit_window' => Config::getSystemSetting('api_rate_limit_window', 3600)
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - WooCommerce Store Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .admin-tab {
            transition: all 0.3s ease;
        }
        .admin-tab.active {
            border-bottom: 2px solid #3b82f6;
            color: #3b82f6;
            background-color: #eff6ff;
        }
        .admin-section {
            display: none;
        }
        .admin-section.active {
            display: block;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .stat-card.green {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .stat-card.orange {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        .stat-card.purple {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
        }
        .danger-zone {
            border: 2px dashed #ef4444;
            background-color: #fef2f2;
        }
    </style>
</head>
<body class="bg-gray-100">
    <!-- Navigation Header -->
    <nav class="bg-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800 mr-4">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                        </svg>
                    </a>
                    <h1 class="text-xl font-bold text-gray-900">Administration Panel</h1>
                    <span class="ml-3 px-2 py-1 bg-red-100 text-red-800 text-xs font-medium rounded">Admin</span>
                </div>
                
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600">Welcome, <?php echo htmlspecialchars($user['first_name'] ?: $user['username']); ?></span>
                    <a href="logout.php" class="text-red-600 hover:text-red-800 text-sm">Sign Out</a>
                </div>
            </div>
        </div>
    </nav>

    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <!-- Error Messages -->
        <?php if (!empty($errors)): ?>
            <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo htmlspecialchars($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- Success Messages -->
        <?php if (!empty($success_messages)): ?>
            <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                <?php foreach ($success_messages as $message): ?>
                    <div><?php echo htmlspecialchars($message); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="stat-card text-white p-6 rounded-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/80 text-sm">Total Users</p>
                        <p class="text-2xl font-bold"><?php echo $stats['total_users']; ?></p>
                        <p class="text-xs text-white/60"><?php echo $stats['active_users']; ?> active</p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"></path>
                        </svg>
                    </div>
                </div>
            </div>
            
            <div class="stat-card green text-white p-6 rounded-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/80 text-sm">Stores</p>
                        <p class="text-2xl font-bold"><?php echo $stats['total_stores']; ?></p>
                        <p class="text-xs text-white/60"><?php echo $stats['connected_stores']; ?> connected</p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"></path>
                        </svg>
                    </div>
                </div>
            </div>
            
            <div class="stat-card orange text-white p-6 rounded-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-white/80 text-sm">Active Sessions</p>
                        <p class="text-2xl font-bold"><?php echo $stats['active_sessions']; ?></p>
                        <p class="text-xs text-white/60">Live users</p>
                    </div>
                    <div class="w-12 h-12 bg-white/20 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z"></path>
                            <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z"></path>
                        </svg>
                    </div>
                </div>
            </div>
            
            <div class="stat-card purple text-gray-800 p-6 rounded-lg">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-600 text-sm">Collaborations</p>
                        <p class="text-2xl font-bold"><?php echo $stats['total_collaborations']; ?></p>
                        <p class="text-xs text-gray-500"><?php echo $stats['pending_invitations']; ?> pending</p>
                    </div>
                    <div class="w-12 h-12 bg-gray-800/10 rounded-lg flex items-center justify-center">
                        <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3z"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- Admin Tabs -->
        <div class="bg-white shadow rounded-lg">
            <div class="border-b border-gray-200">
                <div class="flex overflow-x-auto">
                    <button type="button" id="overview-tab" 
                            class="admin-tab flex-shrink-0 py-4 px-6 text-center font-medium text-gray-500 hover:text-gray-700 active"
                            onclick="showAdminTab('overview')">
                        Overview
                    </button>
                    <button type="button" id="users-tab" 
                            class="admin-tab flex-shrink-0 py-4 px-6 text-center font-medium text-gray-500 hover:text-gray-700"
                            onclick="showAdminTab('users')">
                        User Management
                    </button>
                    <button type="button" id="settings-tab" 
                            class="admin-tab flex-shrink-0 py-4 px-6 text-center font-medium text-gray-500 hover:text-gray-700"
                            onclick="showAdminTab('settings')">
                        System Settings
                    </button>
                    <button type="button" id="activity-tab" 
                            class="admin-tab flex-shrink-0 py-4 px-6 text-center font-medium text-gray-500 hover:text-gray-700"
                            onclick="showAdminTab('activity')">
                        Activity Log
                    </button>
                    <button type="button" id="maintenance-tab" 
                            class="admin-tab flex-shrink-0 py-4 px-6 text-center font-medium text-gray-500 hover:text-gray-700"
                            onclick="showAdminTab('maintenance')">
                        Maintenance
                    </button>
                </div>
            </div>
            
            <!-- Overview Tab -->
            <div id="overview-section" class="admin-section active p-6">
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">System Overview</h3>
                    <p class="text-gray-600">Monitor system health and key metrics.</p>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- System Information -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h4 class="font-medium text-gray-900 mb-4">System Information</h4>
                        <div class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Database File:</span>
                                <span class="text-gray-900"><?php echo basename($system_info['file_path']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Database Size:</span>
                                <span class="text-gray-900"><?php echo number_format($system_info['file_size'] / 1024, 2); ?> KB</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Schema Version:</span>
                                <span class="text-gray-900"><?php echo $system_info['schema_version']; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Tables:</span>
                                <span class="text-gray-900"><?php echo $system_info['table_count']; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">PHP Version:</span>
                                <span class="text-gray-900"><?php echo PHP_VERSION; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Statistics Chart -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h4 class="font-medium text-gray-900 mb-4">User Statistics</h4>
                        <canvas id="userStatsChart" width="400" height="200"></canvas>
                    </div>
                </div>
                
                <!-- Recent Activity Summary -->
                <div class="mt-6">
                    <h4 class="font-medium text-gray-900 mb-4">Recent System Activity</h4>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <?php if (empty($recent_activity)): ?>
                            <p class="text-gray-500 text-sm">No recent activity</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach (array_slice($recent_activity, 0, 5) as $activity): ?>
                                    <div class="flex items-start space-x-3">
                                        <div class="w-2 h-2 bg-blue-500 rounded-full mt-2 flex-shrink-0"></div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($activity['description']); ?>
                                                <?php if ($activity['username']): ?>
                                                    <span class="text-gray-500">by <?php echo htmlspecialchars($activity['username']); ?></span>
                                                <?php endif; ?>
                                            </p>
                                            <p class="text-xs text-gray-500"><?php echo Config::formatDateTime($activity['created_at']); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- User Management Tab -->
            <div id="users-section" class="admin-section p-6">
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">User Management</h3>
                    <p class="text-gray-600">Manage user accounts, permissions, and access.</p>
                </div>
                
                <!-- Users Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Role</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Last Login</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_users as $u): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-blue-500 rounded-full flex items-center justify-center text-white text-sm font-medium mr-3">
                                                <?php echo strtoupper(substr($u['first_name'] ?: $u['username'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($u['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?php echo $u['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                        <?php if (!$u['email_verified']): ?>
                                            <span class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                                Unverified
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?php echo $u['is_admin'] ? 'bg-purple-100 text-purple-800' : 'bg-gray-100 text-gray-800'; ?>">
                                            <?php echo $u['is_admin'] ? 'Admin' : 'User'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php if ($u['last_login']): ?>
                                            <?php echo Config::formatDateTime($u['last_login']); ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">Never</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <?php if ($u['id'] !== $user['id']): ?>
                                            <div class="flex space-x-2">
                                                <!-- Toggle Active Status -->
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="toggle_user_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <?php if (!$u['is_active']): ?>
                                                        <input type="hidden" name="is_active" value="1">
                                                        <button type="submit" class="text-green-600 hover:text-green-900">Enable</button>
                                                    <?php else: ?>
                                                        <button type="submit" class="text-red-600 hover:text-red-900" 
                                                                onclick="return confirm('Are you sure you want to disable this user?')">
                                                            Disable
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                                
                                                <!-- Toggle Admin Status -->
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="toggle_admin_status">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <?php if (!$u['is_admin']): ?>
                                                        <input type="hidden" name="is_admin" value="1">
                                                        <button type="submit" class="text-purple-600 hover:text-purple-900"
                                                                onclick="return confirm('Are you sure you want to make this user an admin?')">
                                                            Make Admin
                                                        </button>
                                                    <?php else: ?>
                                                        <button type="submit" class="text-orange-600 hover:text-orange-900"
                                                                onclick="return confirm('Are you sure you want to remove admin privileges?')">
                                                            Remove Admin
                                                        </button>
                                                    <?php endif; ?>
                                                </form>
                                                
                                                <!-- Delete User -->
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                                    <button type="submit" class="text-red-600 hover:text-red-900"
                                                            onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone!')">
                                                        Delete
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400">Current User</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- System Settings Tab -->
            <div id="settings-section" class="admin-section p-6">
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">System Settings</h3>
                    <p class="text-gray-600">Configure global system settings and behavior.</p>
                </div>
                
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="update_system_settings">
                    
                    <!-- Application Settings -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h4 class="font-medium text-gray-900 mb-4">Application Settings</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="app_name" class="block text-sm font-medium text-gray-700">Application Name</label>
                                <input type="text" id="app_name" name="app_name" 
                                       value="<?php echo htmlspecialchars($system_settings['app_name']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="flex items-center">
                                <input id="maintenance_mode" name="maintenance_mode" type="checkbox" 
                                       <?php echo $system_settings['maintenance_mode'] ? 'checked' : ''; ?>
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="maintenance_mode" class="ml-2 block text-sm text-gray-900">
                                    Enable Maintenance Mode
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- User Registration Settings -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h4 class="font-medium text-gray-900 mb-4">User Registration</h4>
                        <div class="space-y-3">
                            <div class="flex items-center">
                                <input id="registration_enabled" name="registration_enabled" type="checkbox" 
                                       <?php echo $system_settings['registration_enabled'] ? 'checked' : ''; ?>
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="registration_enabled" class="ml-2 block text-sm text-gray-900">
                                    Allow new user registrations
                                </label>
                            </div>
                            
                            <div class="flex items-center">
                                <input id="email_verification_required" name="email_verification_required" type="checkbox" 
                                       <?php echo $system_settings['email_verification_required'] ? 'checked' : ''; ?>
                                       class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="email_verification_required" class="ml-2 block text-sm text-gray-900">
                                    Require email verification for new accounts
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Security Settings -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h4 class="font-medium text-gray-900 mb-4">Security Settings</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="session_timeout" class="block text-sm font-medium text-gray-700">Session Timeout (seconds)</label>
                                <input type="number" id="session_timeout" name="session_timeout" min="300" max="86400"
                                       value="<?php echo $system_settings['session_timeout']; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="max_login_attempts" class="block text-sm font-medium text-gray-700">Max Login Attempts</label>
                                <input type="number" id="max_login_attempts" name="max_login_attempts" min="3" max="20"
                                       value="<?php echo $system_settings['max_login_attempts']; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="lockout_duration" class="block text-sm font-medium text-gray-700">Lockout Duration (seconds)</label>
                                <input type="number" id="lockout_duration" name="lockout_duration" min="60" max="3600"
                                       value="<?php echo $system_settings['lockout_duration']; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                    </div>
                    
                    <!-- API Rate Limiting -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h4 class="font-medium text-gray-900 mb-4">API Rate Limiting</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="api_rate_limit_requests" class="block text-sm font-medium text-gray-700">Requests per Window</label>
                                <input type="number" id="api_rate_limit_requests" name="api_rate_limit_requests" min="10" max="1000"
                                       value="<?php echo $system_settings['api_rate_limit_requests']; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="api_rate_limit_window" class="block text-sm font-medium text-gray-700">Window Duration (seconds)</label>
                                <input type="number" id="api_rate_limit_window" name="api_rate_limit_window" min="60" max="86400"
                                       value="<?php echo $system_settings['api_rate_limit_window']; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Cleanup Settings -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h4 class="font-medium text-gray-900 mb-4">Data Cleanup</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="cleanup_sessions_days" class="block text-sm font-medium text-gray-700">Cleanup Sessions After (days)</label>
                                <input type="number" id="cleanup_sessions_days" name="cleanup_sessions_days" min="1" max="365"
                                       value="<?php echo $system_settings['cleanup_sessions_days']; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div>
                                <label for="cleanup_activity_days" class="block text-sm font-medium text-gray-700">Cleanup Activity After (days)</label>
                                <input type="number" id="cleanup_activity_days" name="cleanup_activity_days" min="1" max="365"
                                       value="<?php echo $system_settings['cleanup_activity_days']; ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded hover:bg-blue-700">
                        Save System Settings
                    </button>
                </form>
            </div>
            
            <!-- Activity Log Tab -->
            <div id="activity-section" class="admin-section p-6">
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">System Activity Log</h3>
                    <p class="text-gray-600">Monitor user activities and system events.</p>
                </div>
                
                <?php if (empty($recent_activity)): ?>
                    <p class="text-gray-500">No recent activity found.</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($recent_activity as $activity): ?>
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex items-start justify-between">
                                    <div class="flex items-start space-x-3">
                                        <div class="w-2 h-2 bg-blue-500 rounded-full mt-2 flex-shrink-0"></div>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($activity['description']); ?>
                                            </p>
                                            <div class="flex items-center space-x-4 mt-1">
                                                <p class="text-xs text-gray-500">
                                                    <?php echo Config::formatDateTime($activity['created_at']); ?>
                                                </p>
                                                <?php if ($activity['username']): ?>
                                                    <span class="text-xs text-blue-600 bg-blue-100 px-2 py-1 rounded">
                                                        <?php echo htmlspecialchars($activity['username']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <span class="text-xs text-purple-600 bg-purple-100 px-2 py-1 rounded">
                                                    <?php echo ucfirst($activity['category']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if ($activity['ip_address']): ?>
                                        <span class="text-xs text-gray-400">
                                            <?php echo htmlspecialchars($activity['ip_address']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Maintenance Tab -->
            <div id="maintenance-section" class="admin-section p-6">
                <div class="mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-2">System Maintenance</h3>
                    <p class="text-gray-600">Perform maintenance tasks and system cleanup.</p>
                </div>
                
                <div class="space-y-6">
                    <!-- Database Maintenance -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h4 class="font-medium text-gray-900 mb-4">Database Maintenance</h4>
                        <p class="text-gray-600 mb-4">
                            Clean up expired sessions, old activity logs, and optimize the database.
                        </p>
                        
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="run_maintenance">
                            <button type="submit" class="bg-green-600 text-white py-2 px-4 rounded hover:bg-green-700"
                                    onclick="return confirm('Are you sure you want to run database maintenance?')">
                                Run Database Maintenance
                            </button>
                        </form>
                    </div>
                    
                    <!-- Danger Zone -->
                    <div class="danger-zone p-6 rounded-lg">
                        <h4 class="font-medium text-red-900 mb-4">⚠️ Danger Zone</h4>
                        <p class="text-red-700 mb-4">
                            These actions are irreversible and can cause data loss. Use with extreme caution.
                        </p>
                        
                        <div class="space-y-3">
                            <button type="button" class="bg-red-600 text-white py-2 px-4 rounded hover:bg-red-700"
                                    onclick="alert('This feature requires additional implementation for safety.')">
                                Reset All User Sessions
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function showAdminTab(tab) {
            // Hide all sections
            document.querySelectorAll('.admin-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.admin-tab').forEach(tabButton => {
                tabButton.classList.remove('active');
            });
            
            // Show selected section and activate tab
            document.getElementById(tab + '-section').classList.add('active');
            document.getElementById(tab + '-tab').classList.add('active');
        }
        
        // Initialize charts
        document.addEventListener('DOMContentLoaded', function() {
            // User Statistics Chart
            const ctx = document.getElementById('userStatsChart').getContext('2d');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Active Users', 'Inactive Users', 'Admin Users', 'Unverified Users'],
                    datasets: [{
                        data: [
                            <?php echo $stats['active_users']; ?>,
                            <?php echo $stats['total_users'] - $stats['active_users']; ?>,
                            <?php echo $stats['admin_users']; ?>,
                            <?php echo $stats['total_users'] - $stats['verified_users']; ?>
                        ],
                        backgroundColor: [
                            '#10b981',
                            '#ef4444', 
                            '#8b5cf6',
                            '#f59e0b'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    legend: {
                        position: 'bottom'
                    }
                }
            });
        });
    </script>
</body>
</html>