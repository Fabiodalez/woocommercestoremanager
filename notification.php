<?php
// notifications.php - Notifications Management
session_start();

require_once 'database.php';
require_once 'config.php';
require_once 'auth.php';

// Initialize system
Config::init();
$user = Config::requireAuth();
$db = Database::getInstance();

$errors = [];
$success_messages = [];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'mark_read':
                $notification_id = intval($_POST['notification_id'] ?? 0);
                
                if ($notification_id <= 0) {
                    throw new Exception('Invalid notification ID');
                }
                
                $result = Config::markNotificationRead($notification_id);
                
                if ($result) {
                    $success_messages[] = 'Notification marked as read';
                } else {
                    $errors[] = 'Failed to mark notification as read';
                }
                break;
                
            case 'mark_all_read':
                $db->execute('
                    UPDATE user_notifications 
                    SET is_read = 1, read_at = CURRENT_TIMESTAMP 
                    WHERE user_id = ? AND is_read = 0
                ', [$user['id']]);
                
                $success_messages[] = 'All notifications marked as read';
                break;
                
            case 'dismiss':
                $notification_id = intval($_POST['notification_id'] ?? 0);
                
                if ($notification_id <= 0) {
                    throw new Exception('Invalid notification ID');
                }
                
                $result = Config::dismissNotification($notification_id);
                
                if ($result) {
                    $success_messages[] = 'Notification dismissed';
                } else {
                    $errors[] = 'Failed to dismiss notification';
                }
                break;
                
            case 'dismiss_all_read':
                $db->execute('
                    UPDATE user_notifications 
                    SET is_dismissed = 1 
                    WHERE user_id = ? AND is_read = 1
                ', [$user['id']]);
                
                $success_messages[] = 'All read notifications dismissed';
                break;
                
            case 'create_notification':
                if (Config::isAdmin()) {
                    $title = trim($_POST['title'] ?? '');
                    $message = trim($_POST['message'] ?? '');
                    $type = $_POST['type'] ?? 'info';
                    $target_users = $_POST['target_users'] ?? 'all';
                    $action_url = trim($_POST['action_url'] ?? '');
                    $action_text = trim($_POST['action_text'] ?? '');
                    
                    if (empty($title)) {
                        throw new Exception('Title is required');
                    }
                    
                    // Determine target users
                    $user_ids = [];
                    if ($target_users === 'all') {
                        $users = $db->fetchAll('SELECT id FROM users WHERE is_active = 1');
                        $user_ids = array_column($users, 'id');
                    } else {
                        $user_ids = [$user['id']]; // Self notification for testing
                    }
                    
                    // Create notifications for all target users
                    foreach ($user_ids as $user_id) {
                        $db->execute('
                            INSERT INTO user_notifications 
                            (user_id, title, message, type, category, action_url, action_text) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ', [$user_id, $title, $message, $type, 'system', $action_url ?: null, $action_text ?: null]);
                    }
                    
                    Config::logActivity('notification_created', "Created notification: {$title}", 'admin');
                    $success_messages[] = 'Notification sent to ' . count($user_ids) . ' users';
                } else {
                    throw new Exception('Admin privileges required');
                }
                break;
        }
        
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// Get filter parameters
$filter = $_GET['filter'] ?? 'all';
$type_filter = $_GET['type'] ?? '';

// Build query based on filters
$where_conditions = ['user_id = ?'];
$params = [$user['id']];

if ($filter === 'unread') {
    $where_conditions[] = 'is_read = 0';
} elseif ($filter === 'read') {
    $where_conditions[] = 'is_read = 1';
}

if (!empty($type_filter)) {
    $where_conditions[] = 'type = ?';
    $params[] = $type_filter;
}

// Add expiration filter
$where_conditions[] = '(expires_at IS NULL OR expires_at > datetime("now"))';
$where_conditions[] = 'is_dismissed = 0';

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Get notifications
$notifications = $db->fetchAll("
    SELECT * FROM user_notifications 
    {$where_clause}
    ORDER BY 
        CASE WHEN is_read = 0 THEN 0 ELSE 1 END,
        created_at DESC 
    LIMIT 50
", $params);

// Get notification counts
$unread_count = $db->fetchColumn('
    SELECT COUNT(*) FROM user_notifications 
    WHERE user_id = ? AND is_read = 0 AND is_dismissed = 0 
    AND (expires_at IS NULL OR expires_at > datetime("now"))
', [$user['id']]);

$total_count = $db->fetchColumn('
    SELECT COUNT(*) FROM user_notifications 
    WHERE user_id = ? AND is_dismissed = 0 
    AND (expires_at IS NULL OR expires_at > datetime("now"))
', [$user['id']]);

// Get notification types for filter
$notification_types = $db->fetchAll('
    SELECT DISTINCT type, COUNT(*) as count 
    FROM user_notifications 
    WHERE user_id = ? AND is_dismissed = 0 
    AND (expires_at IS NULL OR expires_at > datetime("now"))
    GROUP BY type
', [$user['id']]);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - WooCommerce Store Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .notification-item {
            transition: all 0.3s ease;
        }
        .notification-item:hover {
            transform: translateX(4px);
        }
        .notification-item.unread {
            border-left: 4px solid #3b82f6;
            background-color: #eff6ff;
        }
        .notification-item.read {
            border-left: 4px solid #e5e7eb;
        }
        .notification-type-info { border-left-color: #3b82f6; }
        .notification-type-success { border-left-color: #10b981; }
        .notification-type-warning { border-left-color: #f59e0b; }
        .notification-type-error { border-left-color: #ef4444; }
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
                    <h1 class="text-xl font-bold text-gray-900">Notifications</h1>
                    <?php if ($unread_count > 0): ?>
                        <span class="ml-3 px-2 py-1 bg-red-500 text-white text-xs font-medium rounded-full">
                            <?php echo $unread_count; ?>
                        </span>
                    <?php endif; ?>
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
            <!-- Sidebar -->
            <div class="lg:col-span-1">
                <!-- Notification Summary -->
                <div class="bg-white shadow rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Summary</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-gray-600">Total Notifications:</span>
                            <span class="font-medium"><?php echo $total_count; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Unread:</span>
                            <span class="font-medium text-blue-600"><?php echo $unread_count; ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-600">Read:</span>
                            <span class="font-medium text-gray-500"><?php echo $total_count - $unread_count; ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="bg-white shadow rounded-lg p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Filters</h3>
                    
                    <!-- Status Filter -->
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
                        <div class="space-y-2">
                            <a href="?filter=all<?php echo !empty($type_filter) ? '&type=' . urlencode($type_filter) : ''; ?>" 
                               class="block px-3 py-2 rounded text-sm <?php echo $filter === 'all' ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                                All Notifications
                            </a>
                            <a href="?filter=unread<?php echo !empty($type_filter) ? '&type=' . urlencode($type_filter) : ''; ?>" 
                               class="block px-3 py-2 rounded text-sm <?php echo $filter === 'unread' ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                                Unread (<?php echo $unread_count; ?>)
                            </a>
                            <a href="?filter=read<?php echo !empty($type_filter) ? '&type=' . urlencode($type_filter) : ''; ?>" 
                               class="block px-3 py-2 rounded text-sm <?php echo $filter === 'read' ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                                Read
                            </a>
                        </div>
                    </div>
                    
                    <!-- Type Filter -->
                    <?php if (!empty($notification_types)): ?>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Type</label>
                            <div class="space-y-2">
                                <a href="?filter=<?php echo urlencode($filter); ?>" 
                                   class="block px-3 py-2 rounded text-sm <?php echo empty($type_filter) ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                                    All Types
                                </a>
                                <?php foreach ($notification_types as $type): ?>
                                    <a href="?filter=<?php echo urlencode($filter); ?>&type=<?php echo urlencode($type['type']); ?>" 
                                       class="block px-3 py-2 rounded text-sm flex justify-between <?php echo $type_filter === $type['type'] ? 'bg-blue-100 text-blue-800' : 'text-gray-600 hover:bg-gray-100'; ?>">
                                        <span><?php echo ucfirst($type['type']); ?></span>
                                        <span class="text-xs bg-gray-200 text-gray-600 px-2 py-1 rounded"><?php echo $type['count']; ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Actions -->
                <div class="bg-white shadow rounded-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Actions</h3>
                    <div class="space-y-3">
                        <?php if ($unread_count > 0): ?>
                            <form method="POST" class="w-full">
                                <input type="hidden" name="action" value="mark_all_read">
                                <button type="submit" class="w-full bg-blue-600 text-white py-2 px-4 rounded hover:bg-blue-700 text-sm">
                                    Mark All as Read
                                </button>
                            </form>
                        <?php endif; ?>
                        
                        <form method="POST" class="w-full">
                            <input type="hidden" name="action" value="dismiss_all_read">
                            <button type="submit" class="w-full bg-gray-600 text-white py-2 px-4 rounded hover:bg-gray-700 text-sm"
                                    onclick="return confirm('Are you sure you want to dismiss all read notifications?')">
                                Dismiss All Read
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            
            <!-- Main Content -->
            <div class="lg:col-span-3">
                <!-- Admin Panel for Creating Notifications -->
                <?php if (Config::isAdmin()): ?>
                    <div class="bg-white shadow rounded-lg p-6 mb-6">
                        <h3 class="text-lg font-semibold text-gray-900 mb-4">Create System Notification</h3>
                        <form method="POST" class="space-y-4">
                            <input type="hidden" name="action" value="create_notification">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="title" class="block text-sm font-medium text-gray-700">Title *</label>
                                    <input type="text" id="title" name="title" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                
                                <div>
                                    <label for="type" class="block text-sm font-medium text-gray-700">Type</label>
                                    <select id="type" name="type"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="info">Info</option>
                                        <option value="success">Success</option>
                                        <option value="warning">Warning</option>
                                        <option value="error">Error</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div>
                                <label for="message" class="block text-sm font-medium text-gray-700">Message</label>
                                <textarea id="message" name="message" rows="3"
                                          class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label for="action_url" class="block text-sm font-medium text-gray-700">Action URL (optional)</label>
                                    <input type="url" id="action_url" name="action_url"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>
                                
                                <div>
                                    <label for="action_text" class="block text-sm font-medium text-gray-700">Action Text (optional)</label>
                                    <input type="text" id="action_text" name="action_text"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>
                            
                            <div>
                                <label for="target_users" class="block text-sm font-medium text-gray-700">Target</label>
                                <select id="target_users" name="target_users"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="all">All Users</option>
                                    <option value="self">Self (Testing)</option>
                                </select>
                            </div>
                            
                            <button type="submit" class="bg-purple-600 text-white py-2 px-4 rounded hover:bg-purple-700">
                                Create Notification
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
                
                <!-- Notifications List -->
                <div class="bg-white shadow rounded-lg">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">
                            Your Notifications
                            <?php if (!empty($type_filter) || $filter !== 'all'): ?>
                                <span class="text-sm font-normal text-gray-500">
                                    (<?php echo $filter === 'all' ? 'All' : ucfirst($filter); ?>
                                    <?php if (!empty($type_filter)): ?>
                                        • <?php echo ucfirst($type_filter); ?>
                                    <?php endif; ?>)
                                </span>
                            <?php endif; ?>
                        </h3>
                    </div>
                    
                    <?php if (empty($notifications)): ?>
                        <div class="p-6 text-center">
                            <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-gray-100 mb-4">
                                <svg class="h-6 w-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-5-5-5 5h5zm0 0v6"></path>
                                </svg>
                            </div>
                            <h3 class="mt-2 text-sm font-medium text-gray-900">No notifications</h3>
                            <p class="mt-1 text-sm text-gray-500">
                                <?php if ($filter === 'unread'): ?>
                                    You have no unread notifications.
                                <?php elseif ($filter === 'read'): ?>
                                    You have no read notifications.
                                <?php else: ?>
                                    You have no notifications yet.
                                <?php endif; ?>
                            </p>
                        </div>
                    <?php else: ?>
                        <div class="divide-y divide-gray-200">
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item notification-type-<?php echo $notification['type']; ?> <?php echo $notification['is_read'] ? 'read' : 'unread'; ?> p-6">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <h4 class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($notification['title']); ?>
                                                </h4>
                                                
                                                <!-- Type Badge -->
                                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                    <?php 
                                                        switch($notification['type']) {
                                                            case 'success': echo 'bg-green-100 text-green-800'; break;
                                                            case 'warning': echo 'bg-yellow-100 text-yellow-800'; break;
                                                            case 'error': echo 'bg-red-100 text-red-800'; break;
                                                            default: echo 'bg-blue-100 text-blue-800';
                                                        }
                                                    ?>">
                                                    <?php echo ucfirst($notification['type']); ?>
                                                </span>
                                                
                                                <?php if (!$notification['is_read']): ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                        New
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <?php if ($notification['message']): ?>
                                                <p class="text-sm text-gray-600 mb-3">
                                                    <?php echo nl2br(htmlspecialchars($notification['message'])); ?>
                                                </p>
                                            <?php endif; ?>
                                            
                                            <div class="flex items-center space-x-4 text-xs text-gray-500">
                                                <span><?php echo Config::formatDateTime($notification['created_at']); ?></span>
                                                
                                                <?php if ($notification['is_read'] && $notification['read_at']): ?>
                                                    <span>Read: <?php echo Config::formatDateTime($notification['read_at']); ?></span>
                                                <?php endif; ?>
                                                
                                                <span class="capitalize"><?php echo $notification['category']; ?></span>
                                            </div>
                                            
                                            <?php if ($notification['action_url'] && $notification['action_text']): ?>
                                                <div class="mt-3">
                                                    <a href="<?php echo htmlspecialchars($notification['action_url']); ?>" 
                                                       class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                                        <?php echo htmlspecialchars($notification['action_text']); ?> →
                                                    </a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Actions -->
                                        <div class="flex items-center space-x-2 ml-4">
                                            <?php if (!$notification['is_read']): ?>
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="action" value="mark_read">
                                                    <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                                    <button type="submit" class="text-blue-600 hover:text-blue-800 text-sm">
                                                        Mark Read
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="dismiss">
                                                <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-800 text-sm"
                                                        onclick="return confirm('Are you sure you want to dismiss this notification?')">
                                                    Dismiss
                                                </button>
                                            </form>
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

    <script>
        // Auto refresh notifications every 30 seconds
        setInterval(function() {
            // Only refresh if no forms are being filled
            if (document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
                const currentUrl = new URL(window.location);
                // Add a cache-busting parameter
                currentUrl.searchParams.set('_t', Date.now());
                
                // Perform a silent AJAX check for new notifications
                fetch(currentUrl.toString())
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');
                        const newUnreadCount = doc.querySelector('nav span.bg-red-500');
                        const currentUnreadBadge = document.querySelector('nav span.bg-red-500');
                        
                        // Update unread count if changed
                        if (newUnreadCount && currentUnreadBadge) {
                            if (newUnreadCount.textContent !== currentUnreadBadge.textContent) {
                                currentUnreadBadge.textContent = newUnreadCount.textContent;
                            }
                        } else if (newUnreadCount && !currentUnreadBadge) {
                            // Add badge if new notifications arrived
                            const title = document.querySelector('nav h1');
                            if (title) {
                                title.insertAdjacentHTML('afterend', newUnreadCount.outerHTML);
                            }
                        } else if (!newUnreadCount && currentUnreadBadge) {
                            // Remove badge if no unread notifications
                            currentUnreadBadge.remove();
                        }
                    })
                    .catch(error => console.log('Notification check failed:', error));
            }
        }, 30000);
        
        // Add loading states to forms
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function() {
                const button = this.querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = true;
                    button.textContent = 'Processing...';
                }
            });
        });
    </script>
</body>
</html>