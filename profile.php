<?php
// profile.php - User Profile Management
session_start();

require_once 'database.php';
require_once 'config.php';
require_once 'auth.php';

// Initialize system
Config::init();
$user = Config::requireAuth();
$auth = new Auth();
$db = Database::getInstance();

$errors = [];
$success_messages = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'update_profile':
                $first_name = trim($_POST['first_name'] ?? '');
                $last_name = trim($_POST['last_name'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $timezone = $_POST['timezone'] ?? 'UTC';
                $language = $_POST['language'] ?? 'en';
                
                $profile_data = [
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'phone' => $phone,
                    'timezone' => $timezone,
                    'language' => $language
                ];
                
                $result = $auth->updateProfile($user['id'], $profile_data);
                
                if ($result) {
                    $success_messages[] = 'Profile updated successfully!';
                    // Reload user data
                    $user = $auth->getUserById($user['id']);
                } else {
                    $errors[] = 'Failed to update profile';
                }
                
                break;
                
            case 'change_password':
                $current_password = $_POST['current_password'] ?? '';
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                    throw new Exception('All password fields are required');
                }
                
                if ($new_password !== $confirm_password) {
                    throw new Exception('New passwords do not match');
                }
                
                $auth->changePassword($user['id'], $current_password, $new_password);
                $success_messages[] = 'Password changed successfully! Other sessions have been logged out.';
                
                break;
                
            case 'terminate_session':
                $session_id = intval($_POST['session_id'] ?? 0);
                
                if ($session_id <= 0) {
                    throw new Exception('Invalid session ID');
                }
                
                $result = $auth->terminateSession($user['id'], $session_id);
                
                if ($result) {
                    $success_messages[] = 'Session terminated successfully';
                } else {
                    $errors[] = 'Failed to terminate session';
                }
                
                break;
                
            case 'terminate_all_sessions':
                $auth->logoutAllSessions($user['id']);
                $success_messages[] = 'All sessions terminated successfully. Please log in again.';
                
                // Redirect to login after a delay
                header('refresh:3;url=login.php');
                
                break;
        }
        
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// Get user sessions
$user_sessions = $auth->getUserSessions($user['id']);

// Get recent activity
$recent_activity = $auth->getUserActivity($user['id'], 20);

// Available timezones
$timezones = [
    'UTC' => 'UTC',
    'America/New_York' => 'Eastern Time',
    'America/Chicago' => 'Central Time',
    'America/Denver' => 'Mountain Time',
    'America/Los_Angeles' => 'Pacific Time',
    'Europe/London' => 'London',
    'Europe/Paris' => 'Paris',
    'Europe/Rome' => 'Rome',
    'Asia/Tokyo' => 'Tokyo',
    'Asia/Shanghai' => 'Shanghai',
    'Australia/Sydney' => 'Sydney'
];

// Available languages
$languages = [
    'en' => 'English',
    'es' => 'Español',
    'fr' => 'Français',
    'de' => 'Deutsch',
    'it' => 'Italiano',
    'pt' => 'Português'
];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - WooCommerce Store Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .profile-tab {
            transition: all 0.3s ease;
        }
        .profile-tab.active {
            border-bottom: 2px solid #3b82f6;
            color: #3b82f6;
            background-color: #eff6ff;
        }
        .profile-section {
            display: none;
        }
        .profile-section.active {
            display: block;
        }
        .avatar-placeholder {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .activity-item {
            transition: all 0.2s ease;
        }
        .activity-item:hover {
            background-color: #f9fafb;
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
                    <h1 class="text-xl font-bold text-gray-900">Profile Settings</h1>
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

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
            <!-- Profile Summary Card -->
            <div class="lg:col-span-1">
                <div class="bg-white shadow rounded-lg p-6">
                    <div class="text-center">
                        <div class="avatar-placeholder w-20 h-20 rounded-full mx-auto flex items-center justify-center text-white text-2xl font-bold mb-4">
                            <?php echo strtoupper(substr($user['first_name'] ?: $user['username'], 0, 1)); ?>
                        </div>
                        
                        <h3 class="text-lg font-medium text-gray-900">
                            <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                        </h3>
                        
                        <p class="text-sm text-gray-500 mb-2">@<?php echo htmlspecialchars($user['username']); ?></p>
                        <p class="text-sm text-gray-500 mb-4"><?php echo htmlspecialchars($user['email']); ?></p>
                        
                        <?php if ($user['is_admin']): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800 mb-4">
                                Administrator
                            </span>
                        <?php endif; ?>
                        
                        <div class="space-y-2 text-sm text-gray-600">
                            <div class="flex items-center justify-between">
                                <span>Member since:</span>
                                <span><?php echo date('M Y', strtotime($user['created_at'])); ?></span>
                            </div>
                            
                            <div class="flex items-center justify-between">
                                <span>Last login:</span>
                                <span>
                                    <?php if ($user['last_login']): ?>
                                        <?php echo Config::formatDateTime($user['last_login']); ?>
                                    <?php else: ?>
                                        Never
                                    <?php endif; ?>
                                </span>
                            </div>
                            
                            <div class="flex items-center justify-between">
                                <span>Login count:</span>
                                <span><?php echo $user['login_count']; ?></span>
                            </div>
                            
                            <div class="flex items-center justify-between">
                                <span>Email verified:</span>
                                <span>
                                    <?php if ($user['email_verified']): ?>
                                        <span class="text-green-600">✓ Yes</span>
                                    <?php else: ?>
                                        <span class="text-red-600">✗ No</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Settings -->
            <div class="lg:col-span-3">
                <div class="bg-white shadow rounded-lg">
                    <!-- Profile Tabs -->
                    <div class="border-b border-gray-200">
                        <div class="flex overflow-x-auto">
                            <button type="button" id="info-tab" 
                                    class="profile-tab flex-shrink-0 py-4 px-6 text-center font-medium text-gray-500 hover:text-gray-700 active"
                                    onclick="showProfileTab('info')">
                                Personal Information
                            </button>
                            <button type="button" id="security-tab" 
                                    class="profile-tab flex-shrink-0 py-4 px-6 text-center font-medium text-gray-500 hover:text-gray-700"
                                    onclick="showProfileTab('security')">
                                Security
                            </button>
                            <button type="button" id="sessions-tab" 
                                    class="profile-tab flex-shrink-0 py-4 px-6 text-center font-medium text-gray-500 hover:text-gray-700"
                                    onclick="showProfileTab('sessions')">
                                Active Sessions
                            </button>
                            <button type="button" id="activity-tab" 
                                    class="profile-tab flex-shrink-0 py-4 px-6 text-center font-medium text-gray-500 hover:text-gray-700"
                                    onclick="showProfileTab('activity')">
                                Activity Log
                            </button>
                        </div>
                    </div>
                    
                    <!-- Personal Information Tab -->
                    <div id="info-section" class="profile-section active p-6">
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">Personal Information</h3>
                            <p class="text-gray-600">Update your personal details and preferences.</p>
                        </div>
                        
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="first_name" class="block text-sm font-medium text-gray-700">First Name</label>
                                    <input type="text" id="first_name" name="first_name" 
                                           value="<?php echo htmlspecialchars($user['first_name'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                
                                <div>
                                    <label for="last_name" class="block text-sm font-medium text-gray-700">Last Name</label>
                                    <input type="text" id="last_name" name="last_name" 
                                           value="<?php echo htmlspecialchars($user['last_name'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>
                            
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email Address</label>
                                <input type="email" id="email" name="email" readonly
                                       value="<?php echo htmlspecialchars($user['email']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm bg-gray-50 text-gray-500">
                                <p class="mt-1 text-sm text-gray-500">Email cannot be changed. Contact administrator if needed.</p>
                            </div>
                            
                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700">Phone Number</label>
                                <input type="tel" id="phone" name="phone" 
                                       value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>"
                                       placeholder="+1 (555) 123-4567"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="timezone" class="block text-sm font-medium text-gray-700">Timezone</label>
                                    <select id="timezone" name="timezone"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <?php foreach ($timezones as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo ($user['timezone'] ?? 'UTC') === $value ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label for="language" class="block text-sm font-medium text-gray-700">Language</label>
                                    <select id="language" name="language"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <?php foreach ($languages as $value => $label): ?>
                                            <option value="<?php echo $value; ?>" <?php echo ($user['language'] ?? 'en') === $value ? 'selected' : ''; ?>>
                                                <?php echo $label; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded hover:bg-blue-700">
                                Update Profile
                            </button>
                        </form>
                    </div>
                    
                    <!-- Security Tab -->
                    <div id="security-section" class="profile-section p-6">
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">Security Settings</h3>
                            <p class="text-gray-600">Manage your password and security preferences.</p>
                        </div>
                        
                        <!-- Change Password Form -->
                        <div class="mb-8">
                            <h4 class="font-medium text-gray-900 mb-4">Change Password</h4>
                            <form method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="change_password">
                                
                                <div>
                                    <label for="current_password" class="block text-sm font-medium text-gray-700">Current Password</label>
                                    <input type="password" id="current_password" name="current_password" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                
                                <div>
                                    <label for="new_password" class="block text-sm font-medium text-gray-700">New Password</label>
                                    <input type="password" id="new_password" name="new_password" required minlength="8"
                                           pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <p class="mt-1 text-sm text-gray-500">Must contain uppercase, lowercase, and number. Minimum 8 characters.</p>
                                </div>
                                
                                <div>
                                    <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label>
                                    <input type="password" id="confirm_password" name="confirm_password" required minlength="8"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                
                                <button type="submit" class="bg-red-600 text-white py-2 px-4 rounded hover:bg-red-700">
                                    Change Password
                                </button>
                            </form>
                        </div>
                        
                        <!-- Security Information -->
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <h4 class="font-medium text-gray-900 mb-2">Security Information</h4>
                            <div class="space-y-2 text-sm text-gray-600">
                                <div class="flex justify-between">
                                    <span>Failed login attempts:</span>
                                    <span><?php echo $user['failed_login_attempts']; ?></span>
                                </div>
                                
                                <?php if ($user['last_failed_login']): ?>
                                    <div class="flex justify-between">
                                        <span>Last failed login:</span>
                                        <span><?php echo Config::formatDateTime($user['last_failed_login']); ?></span>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="flex justify-between">
                                    <span>Account status:</span>
                                    <span class="<?php echo $user['is_active'] ? 'text-green-600' : 'text-red-600'; ?>">
                                        <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Active Sessions Tab -->
                    <div id="sessions-section" class="profile-section p-6">
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">Active Sessions</h3>
                            <p class="text-gray-600">Manage your active login sessions across different devices.</p>
                        </div>
                        
                        <?php if (empty($user_sessions)): ?>
                            <p class="text-gray-500">No active sessions found.</p>
                        <?php else: ?>
                            <div class="space-y-4 mb-6">
                                <?php foreach ($user_sessions as $session): ?>
                                    <div class="border border-gray-200 rounded-lg p-4">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center space-x-3">
                                                <div class="w-10 h-10 bg-gray-200 rounded-lg flex items-center justify-center">
                                                    <?php if ($session['is_mobile']): ?>
                                                        <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                                                            <path d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 011 1v8a1 1 0 01-1 1H5a1 1 0 01-1-1V7zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"></path>
                                                        </svg>
                                                    <?php else: ?>
                                                        <svg class="w-5 h-5 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                                                            <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"></path>
                                                        </svg>
                                                    <?php endif; ?>
                                                </div>
                                                
                                                <div>
                                                    <p class="font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($session['browser']); ?> on <?php echo htmlspecialchars($session['os']); ?>
                                                        <?php if ($session['is_mobile']): ?>
                                                            <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded ml-2">Mobile</span>
                                                        <?php endif; ?>
                                                    </p>
                                                    <p class="text-sm text-gray-500">
                                                        IP: <?php echo htmlspecialchars($session['ip_address']); ?>
                                                    </p>
                                                    <p class="text-xs text-gray-400">
                                                        Created: <?php echo Config::formatDateTime($session['created_at']); ?> • 
                                                        Last active: <?php echo Config::formatDateTime($session['last_activity']); ?>
                                                    </p>
                                                </div>
                                            </div>
                                            
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="terminate_session">
                                                <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-800 text-sm"
                                                        onclick="return confirm('Are you sure you want to terminate this session?')">
                                                    Terminate
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="terminate_all_sessions">
                                <button type="submit" class="bg-red-600 text-white py-2 px-4 rounded hover:bg-red-700"
                                        onclick="return confirm('Are you sure you want to terminate ALL sessions? You will be logged out.')">
                                    Terminate All Sessions
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Activity Log Tab -->
                    <div id="activity-section" class="profile-section p-6">
                        <div class="mb-6">
                            <h3 class="text-lg font-semibold text-gray-900 mb-2">Activity Log</h3>
                            <p class="text-gray-600">Your recent account activity and actions.</p>
                        </div>
                        
                        <?php if (empty($recent_activity)): ?>
                            <p class="text-gray-500">No recent activity found.</p>
                        <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($recent_activity as $activity): ?>
                                    <div class="activity-item p-3 border border-gray-200 rounded-lg">
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
                                                    <span class="text-xs text-blue-600 bg-blue-100 px-2 py-1 rounded">
                                                        <?php echo ucfirst($activity['category']); ?>
                                                    </span>
                                                    <?php if ($activity['ip_address']): ?>
                                                        <p class="text-xs text-gray-400">
                                                            IP: <?php echo htmlspecialchars($activity['ip_address']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching
        function showProfileTab(tab) {
            // Hide all sections
            document.querySelectorAll('.profile-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.profile-tab').forEach(tabButton => {
                tabButton.classList.remove('active');
            });
            
            // Show selected section and activate tab
            document.getElementById(tab + '-section').classList.add('active');
            document.getElementById(tab + '-tab').classList.add('active');
        }
        
        // Password confirmation validation
        document.addEventListener('DOMContentLoaded', function() {
            const newPassword = document.getElementById('new_password');
            const confirmPassword = document.getElementById('confirm_password');
            
            if (newPassword && confirmPassword) {
                function validatePasswords() {
                    if (newPassword.value !== confirmPassword.value) {
                        confirmPassword.setCustomValidity('Passwords do not match');
                    } else {
                        confirmPassword.setCustomValidity('');
                    }
                }
                
                newPassword.addEventListener('input', validatePasswords);
                confirmPassword.addEventListener('input', validatePasswords);
            }
        });
    </script>
</body>
</html>