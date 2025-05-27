<?php
// header.php - Enhanced Multi-User Header Component
if (!defined('HEADER_INCLUDED')) {
    define('HEADER_INCLUDED', true);
    
    require_once __DIR__ . '/database.php';
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/auth.php';
    
    // Initialize config and get current user
    Config::init();
    $current_user_for_header = Config::getCurrentUser();
    
    // Check if user is required for this page
    $public_pages = ['login.php', 'register.php', 'forgot-password.php', 'install.php'];
    $current_page_file = basename($_SERVER['PHP_SELF']);
    
    if (!$current_user_for_header && !in_array($current_page_file, $public_pages)) {
        header('Location: login.php');
        exit;
    }
    
    // Determine the current page for menu highlighting
    $current_page = basename($_SERVER['PHP_SELF'], '.php');
    $page_title = isset($pageTitle) ? $pageTitle : ucfirst($current_page);
    $app_name = isset($appName) ? $appName : Config::getSystemSetting('app_name', 'WooCommerce Store Manager');
    
    // Get current store data if user is logged in
    $current_store_for_header = null;
    $connected = false;
    if ($current_user_for_header) {
        $current_store_for_header = Config::getCurrentStore();
        $connected = $current_store_for_header ? $current_store_for_header['connected'] : false;
    }
    
    // Menu items with their configurations (enhanced)
    $menu_items = [
        'dashboard' => [
            'label' => 'Dashboard', 
            'url' => 'dashboard.php', 
            'icon' => 'fas fa-th-large',
            'requires_store' => false
        ],
        'products' => [
            'label' => 'Products', 
            'url' => 'products.php', 
            'icon' => 'fas fa-box-open',
            'requires_store' => true
        ],
        'orders' => [
            'label' => 'Orders', 
            'url' => 'orders.php', 
            'icon' => 'fas fa-receipt',
            'requires_store' => true
        ],
        'customers' => [
            'label' => 'Customers', 
            'url' => 'customers.php', 
            'icon' => 'fas fa-users',
            'requires_store' => true
        ],
        'analytics' => [
            'label' => 'Analytics', 
            'url' => 'analytics.php', 
            'icon' => 'fas fa-chart-line',
            'requires_store' => true
        ],
        'marketing' => [
            'label' => 'Marketing', 
            'url' => 'marketing.php', 
            'icon' => 'fas fa-bullhorn',
            'requires_store' => true
        ]
    ];
    
    // User avatar character
    $userAvatarChar = $current_user_for_header ? strtoupper(substr($current_user_for_header['first_name'] ?: $current_user_for_header['username'], 0, 1)) : '';
?>

<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(Config::getLanguage() ?: 'en'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.gstatic.com/" crossorigin="" />
    <link rel="stylesheet" as="style" onload="this.rel='stylesheet'" 
          href="https://fonts.googleapis.com/css2?display=swap&family=Inter:wght@300;400;500;600;700&display=swap" />
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars($app_name); ?></title>
    <link rel="icon" type="image/x-icon" href="favicon.ico" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.2.0/css/all.min.css" rel="stylesheet">
    
    <style>
        body { font-family: 'Inter', sans-serif; }
        
        /* Enhanced Alert Styles */
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.75rem;
            border-left: 4px solid;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        }
        .alert-error {
            background-color: #fef2f2;
            border-left-color: #ef4444;
            color: #991b1b;
        }
        .alert-success {
            background-color: #f0fdf4;
            border-left-color: #22c55e;
            color: #166534;
        }
        .alert-warning {
            background-color: #fffbeb;
            border-left-color: #f59e0b;
            color: #92400e;
        }
        .alert-info {
            background-color: #eff6ff;
            border-left-color: #3b82f6;
            color: #1e40af;
        }
        
        /* Navigation Styles */
        .dropdown-menu { 
            opacity: 0; 
            visibility: hidden; 
            transform: translateY(10px); 
            transition: all 0.2s ease-out; 
        }
        .dropdown-menu.active { 
            opacity: 1; 
            visibility: visible; 
            transform: translateY(0); 
        }
        
        .nav-link { 
            padding: 0.5rem 1rem; 
            margin: 0 0.125rem; 
            border-radius: 0.375rem; 
            transition: all 0.2s ease-out; 
            font-weight: 500; 
            font-size: 0.875rem; 
            display: inline-flex; 
            align-items: center; 
            color: #4B5563; 
            text-decoration: none;
        }
        .nav-link:hover { 
            background-color: #E5E7EB; 
            color: #1F2937; 
        }
        .nav-link.active { 
            background-color: #DBEAFE; 
            color: #2563EB; 
        }
        .nav-link i { 
            margin-right: 0.625rem; 
            width: 1.1em; 
            text-align: center; 
            opacity: 0.7; 
        }
        .nav-link.active i { 
            opacity: 1; 
        }
        .nav-link:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .user-dropdown-item {
            display: block;
            width: 100%;
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.875rem;
            color: #374151;
            text-decoration: none;
            transition: all 0.15s ease;
            border-bottom: 1px solid #f3f4f6;
        }
        .user-dropdown-item:hover {
            background-color: #F3F4F6;
            color: #111827;
        }
        .user-dropdown-item:last-child {
            border-bottom: none;
        }
        .user-dropdown-item i {
            display: inline-block;
            width: 1.25rem;
            margin-right: 0.75rem;
            text-align: center;
            opacity: 0.7;
        }
        
        /* Loading animations */
        .loading-spinner {
            border: 2px solid #f3f3f3;
            border-top: 2px solid #3b82f6;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Enhanced Modal styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
        }
        
        .modal.show {
            display: flex;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease-out;
        }
        
        .modal-content {
            background-color: white;
            border-radius: 1rem;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            padding: 1.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes slideIn {
            from { transform: translateY(-20px) scale(0.95); opacity: 0; }
            to { transform: translateY(0) scale(1); opacity: 1; }
        }
        
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        
        /* Table responsive helpers */
        .table-responsive {
            overflow-x: auto;
        }
        
        .table-responsive::-webkit-scrollbar {
            height: 8px;
        }
        
        .table-responsive::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb {
            background: #3b82f6;
            border-radius: 4px;
        }
        
        .table-responsive::-webkit-scrollbar-thumb:hover {
            background: #2563eb;
        }
        
        /* Status badges */
        .status-published { background-color: #d1fae5; color: #065f46; }
        .status-draft { background-color: #f3f4f6; color: #374151; }
        .status-private { background-color: #fef3c7; color: #92400e; }
        .status-pending { background-color: #dbeafe; color: #1e40af; }
        
        /* Stock status colors */
        .stock-instock { color: #059669; }
        .stock-outofstock { color: #dc2626; }
        .stock-onbackorder { color: #d97706; }
        
        /* Welcome message */
        .welcome-banner {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.75rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        /* Connection status */
        .connection-status.connected { color: #22c55e; font-weight: 600; }
        .connection-status.disconnected { color: #ef4444; font-weight: 600; }
        
        /* Custom scrollbar for components */
        .custom-scrollbar {
            scrollbar-width: thin;
            scrollbar-color: #d1d5db #f3f4f6;
        }
        .custom-scrollbar::-webkit-scrollbar {
            width: 6px;
        }
        .custom-scrollbar::-webkit-scrollbar-track {
            background: #f3f4f6;
            border-radius: 3px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 3px;
        }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 antialiased">
    <?php if ($current_user_for_header): ?>
    <!-- Enhanced Header -->
    <header class="bg-white shadow-sm sticky top-0 z-50">
    <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16">
            <!-- Logo and Brand -->
            <div class="flex items-center">
                <a href="dashboard.php" class="flex-shrink-0 flex items-center space-x-2 text-blue-600 hover:text-blue-700 transition-colors">
                    <div class="w-8 h-8">
                        <svg viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M13.8261 30.5736C16.7203 29.8826 20.2244 29.4783 24 29.4783C27.7756 29.4783 31.2797 29.8826 34.1739 30.5736C36.9144 31.2278 39.9967 32.7669 41.3563 33.8352L24.8486 7.36089C24.4571 6.73303 23.5429 6.73303 23.1514 7.36089L6.64374 33.8352C8.00331 32.7669 11.0856 31.2278 13.8261 30.5736Z" fill="currentColor"></path>
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M39.998 35.764C39.9944 35.7463 39.9875 35.7155 39.9748 35.6706C39.9436 35.5601 39.8949 35.4259 39.8346 35.2825C39.8168 35.2403 39.7989 35.1993 39.7813 35.1602C38.5103 34.2887 35.9788 33.0607 33.7095 32.5189C30.9875 31.8691 27.6413 31.4783 24 31.4783C20.3587 31.4783 17.0125 31.8691 14.2905 32.5189C12.0012 33.0654 9.44505 34.3104 8.18538 35.1832C8.17384 35.2075 8.16216 35.233 8.15052 35.2592C8.09919 35.3751 8.05721 35.4886 8.02977 35.589C8.00356 35.6848 8.00039 35.7333 8.00004 35.7388C8.00004 35.739 8 35.7393 8.00004 35.7388C8.00004 35.7641 8.0104 36.0767 8.68485 36.6314C9.34546 37.1746 10.4222 37.7531 11.9291 38.2772C14.9242 39.319 19.1919 40 24 40C28.8081 40 33.0758 39.319 36.0709 38.2772C37.5778 37.7531 38.6545 37.1746 39.3151 36.6314C39.9006 36.1499 39.9857 35.8511 39.998 35.764Z" fill="currentColor"></path>
                        </svg>
                    </div>
                    <span class="text-xl font-bold"><?php echo htmlspecialchars($app_name); ?></span>
                </a>
            </div>

            <!-- Navigation Menu -->
            <nav class="hidden md:flex items-center space-x-1">
                <?php foreach ($menu_items as $key => $item): 
                    $is_active = ($current_page === $key);
                    $is_disabled = $item['requires_store'] && (!$connected);
                ?>
                    <?php if (!$is_disabled): ?>
                        <a href="<?php echo $item['url']; ?>" 
                           class="nav-link <?php echo $is_active ? 'active' : ''; ?>"
                           title="<?php echo $item['label']; ?>">
                            <i class="<?php echo $item['icon']; ?>"></i><?php echo $item['label']; ?>
                        </a>
                    <?php else: ?>
                        <span class="nav-link" style="opacity: 0.5; cursor: not-allowed;" 
                              title="<?php echo $item['label']; ?> (requires store connection)">
                            <i class="<?php echo $item['icon']; ?>"></i><?php echo $item['label']; ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <a href="settings.php" class="nav-link <?php echo ($current_page === 'settings') ? 'active' : ''; ?>">
                    <i class="fas fa-cogs"></i>Settings
                </a>
                
                <?php if (Config::isAdmin()): ?>
                    <a href="admin.php" class="nav-link <?php echo ($current_page === 'admin') ? 'active' : ''; ?>">
                        <i class="fas fa-user-shield"></i>Admin
                    </a>
                <?php endif; ?>
            </nav>
            
            <!-- Right Side Controls -->
            <div class="flex items-center space-x-3">
                <!-- Connection Status -->
                <?php if (!$connected): ?>
                    <div class="connection-indicator flex items-center gap-2 px-3 py-1 bg-red-100 rounded-full" 
                         title="Not connected to WooCommerce">
                        <div class="w-2 h-2 bg-red-500 rounded-full"></div>
                        <span class="text-red-700 text-xs font-medium">Offline</span>
                    </div>
                <?php else: ?>
                    <div class="connection-indicator flex items-center gap-2 px-3 py-1 bg-green-100 rounded-full" 
                         title="Connected to WooCommerce">
                        <div class="w-2 h-2 bg-green-500 rounded-full"></div>
                        <span class="text-green-700 text-xs font-medium">Online</span>
                    </div>
                <?php endif; ?>
                
                <!-- User Profile Dropdown -->
                <div class="relative user-dropdown">
                    <button id="user-menu-btn" type="button" onclick="this.parentElement.classList.toggle('active')" 
                            class="flex items-center text-sm rounded-full focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition">
                        <div class="h-9 w-9 rounded-full bg-blue-500 flex items-center justify-center text-white font-semibold text-sm">
                            <?php echo htmlspecialchars($userAvatarChar); ?>
                        </div>
                        <span class="hidden ml-2 lg:block text-sm font-medium text-gray-700 hover:text-blue-600">
                            <?php echo htmlspecialchars($current_user_for_header['first_name'] ?: $current_user_for_header['username']); ?>
                        </span>
                        <i class="fas fa-chevron-down ml-1.5 text-gray-400 fa-xs hidden lg:block"></i>
                    </button>
                    
                    <div id="user-menu-dropdown" class="dropdown-menu absolute right-0 mt-2 w-56 bg-white rounded-md shadow-lg ring-1 ring-black ring-opacity-5 py-1">
                        <div class="px-4 py-3 border-b border-gray-200">
                            <p class="text-sm font-medium text-gray-900 truncate">
                                <?php echo htmlspecialchars(trim(($current_user_for_header['first_name'] ?? '') . ' ' . ($current_user_for_header['last_name'] ?? ''))); ?>
                            </p>
                            <p class="text-xs text-gray-500 truncate"><?php echo htmlspecialchars($current_user_for_header['email']); ?></p>
                        </div>
                        
                        <a href="profile.php" class="user-dropdown-item">
                            <i class="fas fa-user-edit"></i>Profile Settings
                        </a>
                        <a href="settings.php" class="user-dropdown-item">
                            <i class="fas fa-sliders-h"></i>App Settings
                        </a>
                        <?php if (Config::isAdmin()): ?>
                            <a href="admin.php" class="user-dropdown-item">
                                <i class="fas fa-shield-alt"></i>Admin Panel
                            </a>
                        <?php endif; ?>
                        <a href="help.php" class="user-dropdown-item">
                            <i class="fas fa-question-circle"></i>Help & Support
                        </a>
                        
                        <div class="my-1 h-px bg-gray-100"></div>
                        <a href="logout.php" class="user-dropdown-item !text-red-600 hover:!bg-red-50">
                            <i class="fas fa-sign-out-alt"></i>Sign Out
                        </a>
                    </div>
                </div>
                
                <!-- Mobile Menu Button -->
                <div class="md:hidden">
                    <button id="mobile-menu-btn" class="p-2 rounded-md text-gray-400 hover:text-gray-500 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <span class="sr-only">Open menu</span>
                        <i class="fas fa-bars fa-lg"></i>
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Second Row: Welcome Message and Store Status -->
        <div class="border-t border-gray-100 py-2">
            <div class="flex justify-between items-center text-xs">
                <!-- Welcome Message -->
                <div class="text-gray-600">
                    <?php if ($current_user_for_header['first_name'] || $current_user_for_header['last_name']): ?>
                        Welcome back, <?php echo htmlspecialchars(trim($current_user_for_header['first_name'] . ' ' . $current_user_for_header['last_name'])); ?>
                    <?php else: ?>
                        Welcome back, <?php echo htmlspecialchars($current_user_for_header['username']); ?>
                    <?php endif; ?>
                </div>
                
                <!-- Store Status Indicator -->
                <?php if ($current_store_for_header): ?>
                    <div class="flex items-center space-x-2 text-gray-500">
                        <span>Current Store:</span>
                        <span class="px-2 py-1 <?php echo $current_store_for_header['connected'] ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?> rounded text-xs font-medium">
                            <i class="fas <?php echo $current_store_for_header['connected'] ? 'fa-link' : 'fa-unlink'; ?> mr-1 opacity-75"></i>
                            <?php echo htmlspecialchars($current_store_for_header['store_name'] ?: 'N/A'); ?>
                        </span>
                    </div>
                <?php else: ?>
                    <div class="text-gray-500">
                        <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs font-medium">
                            <i class="fas fa-exclamation-triangle mr-1"></i>
                            No store connected
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Mobile Menu Panel -->
    <div id="mobile-menu-panel" class="md:hidden hidden bg-white border-t border-gray-200">
        <div class="px-2 pt-2 pb-3 space-y-1 sm:px-3">
            <?php foreach ($menu_items as $key => $item): 
                $is_active = ($current_page === $key);
                $is_disabled = $item['requires_store'] && (!$connected);
            ?>
                <?php if (!$is_disabled): ?>
                    <a href="<?php echo $item['url']; ?>" 
                       class="nav-link block <?php echo $is_active ? 'active' : ''; ?>">
                        <i class="<?php echo $item['icon']; ?>"></i><?php echo $item['label']; ?>
                    </a>
                <?php else: ?>
                    <span class="nav-link block" style="opacity: 0.5; cursor: not-allowed;">
                        <i class="<?php echo $item['icon']; ?>"></i><?php echo $item['label']; ?>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
            
            <a href="settings.php" class="nav-link block <?php echo ($current_page === 'settings') ? 'active' : ''; ?>">
                <i class="fas fa-cogs"></i>Settings
            </a>
            
            <?php if (Config::isAdmin()): ?>
                <a href="admin.php" class="nav-link block <?php echo ($current_page === 'admin') ? 'active' : ''; ?>">
                    <i class="fas fa-user-shield"></i>Admin
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
    <?php endif; ?>

    <!-- Welcome Banner for New Users -->
    <?php if (isset($_GET['welcome']) && $_GET['welcome'] == '1'): ?>
        <div class="max-w-screen-xl mx-auto px-4 sm:px-6 lg:px-8 pt-4">
            <div class="welcome-banner">
                <h3 class="font-semibold mb-2 flex items-center">
                    <i class="fas fa-party-horn mr-2"></i>
                    Welcome to <?php echo htmlspecialchars($app_name); ?>!
                </h3>
                <p class="text-sm opacity-90 mb-3">Get started by connecting your WooCommerce store and managing your products.</p>
                <div class="flex flex-wrap gap-2">
                    <a href="settings.php" class="inline-flex items-center bg-white/20 hover:bg-white/30 px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                        <i class="fas fa-cog mr-2"></i>Configure Store
                    </a>
                    <button onclick="this.closest('.welcome-banner').style.display='none'" class="inline-flex items-center bg-white/10 hover:bg-white/20 px-3 py-2 rounded-lg text-sm transition-colors">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Enhanced JavaScript Utilities -->
    <script>
        // Global utilities for all pages
        window.ShopManager = {
            // Show loading state
            showLoading: function(element) {
                if (typeof element === 'string') {
                    element = document.querySelector(element);
                }
                if (element) {
                    const spinner = document.createElement('div');
                    spinner.className = 'loading-spinner';
                    spinner.style.display = 'inline-block';
                    spinner.style.marginRight = '8px';
                    element.prepend(spinner);
                    element.disabled = true;
                }
            },
            
            // Hide loading state
            hideLoading: function(element) {
                if (typeof element === 'string') {
                    element = document.querySelector(element);
                }
                if (element) {
                    const spinner = element.querySelector('.loading-spinner');
                    if (spinner) spinner.remove();
                    element.disabled = false;
                }
            },
            
            // Enhanced modal system
            showModal: function(content, title = '', options = {}) {
                const modal = document.createElement('div');
                modal.className = 'modal show';
                modal.innerHTML = `
                    <div class="modal-content">
                        ${title ? `<div class="flex justify-between items-center mb-4"><h3 class="text-lg font-bold text-gray-900">${title}</h3><button onclick="this.closest('.modal').remove()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times fa-lg"></i></button></div>` : ''}
                        <div class="modal-body">${content}</div>
                        ${!options.hideButtons ? `<div class="flex justify-end gap-3 mt-6">
                            <button onclick="this.closest('.modal').remove()" class="px-4 py-2 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg font-medium transition-colors">Close</button>
                        </div>` : ''}
                    </div>
                `;
                modal.onclick = function(e) {
                    if (e.target === modal) modal.remove();
                };
                document.body.appendChild(modal);
                return modal;
            },
            
            // Enhanced notification system
            showNotification: function(message, type = 'info', duration = 5000) {
                const notification = document.createElement('div');
                const icons = {
                    'success': 'fas fa-check-circle',
                    'error': 'fas fa-exclamation-circle',
                    'warning': 'fas fa-exclamation-triangle',
                    'info': 'fas fa-info-circle'
                };
                
                notification.className = `alert alert-${type} fixed top-4 right-4 z-50 max-w-md shadow-lg`;
                notification.innerHTML = `
                    <div class="flex items-start">
                        <i class="${icons[type] || icons.info} fa-lg mr-3 mt-0.5"></i>
                        <div class="flex-1">${message}</div>
                        <button onclick="this.parentElement.parentElement.remove()" class="ml-2 text-current opacity-70 hover:opacity-100">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
                notification.style.animation = 'slideInRight 0.3s ease-out';
                
                document.body.appendChild(notification);
                
                if (duration > 0) {
                    setTimeout(() => {
                        notification.style.animation = 'slideOutRight 0.3s ease-in';
                        setTimeout(() => notification.remove(), 300);
                    }, duration);
                }
                
                return notification;
            },
            
            // Confirm dialog
            confirm: function(message, title = 'Confirm', onConfirm = null, onCancel = null) {
                const modal = this.showModal(`
                    <div class="text-center">
                        <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-yellow-100 mb-4">
                            <i class="fas fa-exclamation-triangle text-yellow-600"></i>
                        </div>
                        <p class="text-gray-700 mb-6">${message}</p>
                        <div class="flex justify-center gap-3">
                            <button id="confirm-yes" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg font-medium transition-colors">
                                Yes, Continue
                            </button>
                            <button id="confirm-no" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-4 py-2 rounded-lg font-medium transition-colors">
                                Cancel
                            </button>
                        </div>
                    </div>
                `, title, { hideButtons: true });
                
                modal.querySelector('#confirm-yes').onclick = function() {
                    modal.remove();
                    if (onConfirm) onConfirm();
                };
                
                modal.querySelector('#confirm-no').onclick = function() {
                    modal.remove();
                    if (onCancel) onCancel();
                };
                
                return modal;
            },
            
            // Format price
            formatPrice: function(price, currency = '<?php echo Config::getSetting('currency', '$'); ?>') {
                const num = parseFloat(price);
                if (isNaN(num)) return currency + '0.00';
                return currency + num.toFixed(2);
            },
            
            // Format date
            formatDate: function(dateString, options = {}) {
                const date = new Date(dateString);
                const defaultOptions = { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric',
                    ...options
                };
                return date.toLocaleDateString('<?php echo Config::getLanguage() ?: 'en-US'; ?>', defaultOptions);
            },
            
            // Format date and time
            formatDateTime: function(dateString) {
                const date = new Date(dateString);
                return date.toLocaleString('<?php echo Config::getLanguage() ?: 'en-US'; ?>', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            },
            
            // Debounce function
            debounce: function(func, wait) {
                let timeout;
                return function executedFunction(...args) {
                    const later = () => {
                        clearTimeout(timeout);
                        func(...args);
                    };
                    clearTimeout(timeout);
                    timeout = setTimeout(later, wait);
                };
            },
            
            // Throttle function
            throttle: function(func, limit) {
                let inThrottle;
                return function() {
                    const args = arguments;
                    const context = this;
                    if (!inThrottle) {
                        func.apply(context, args);
                        inThrottle = true;
                        setTimeout(() => inThrottle = false, limit);
                    }
                }
            },
            
            // Copy to clipboard
            copyToClipboard: function(text) {
                if (navigator.clipboard && window.isSecureContext) {
                    return navigator.clipboard.writeText(text).then(() => {
                        this.showNotification('Copied to clipboard!', 'success', 2000);
                    });
                } else {
                    const textArea = document.createElement('textarea');
                    textArea.value = text;
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    try {
                        document.execCommand('copy');
                        this.showNotification('Copied to clipboard!', 'success', 2000);
                    } catch (err) {
                        this.showNotification('Failed to copy to clipboard', 'error');
                    }
                    document.body.removeChild(textArea);
                }
            }
        };
        
        // Enhanced DOM ready and event handling
        document.addEventListener('DOMContentLoaded', function() {
            // User Menu Dropdown
            const userMenuBtn = document.getElementById('user-menu-btn');
            const userMenuDropdown = document.getElementById('user-menu-dropdown');
            
            if (userMenuBtn && userMenuDropdown) {
                userMenuBtn.addEventListener('click', function(event) {
                    event.stopPropagation();
                    userMenuDropdown.classList.toggle('active');
                    userMenuBtn.setAttribute('aria-expanded', userMenuDropdown.classList.contains('active'));
                });
                
                document.addEventListener('click', function(event) {
                    if (userMenuDropdown.classList.contains('active') && 
                        !userMenuBtn.contains(event.target) && 
                        !userMenuDropdown.contains(event.target)) {
                        userMenuDropdown.classList.remove('active');
                        userMenuBtn.setAttribute('aria-expanded', 'false');
                    }
                });
            }

            // Mobile Menu Toggle
            const mobileMenuBtn = document.getElementById('mobile-menu-btn');
            const mobileMenuPanel = document.getElementById('mobile-menu-panel');
            
            if (mobileMenuBtn && mobileMenuPanel) {
                mobileMenuBtn.addEventListener('click', function() {
                    const expanded = mobileMenuPanel.classList.toggle('hidden');
                    mobileMenuBtn.setAttribute('aria-expanded', !expanded);
                    mobileMenuBtn.innerHTML = !expanded ? 
                        '<i class="fas fa-times fa-lg"></i>' : 
                        '<i class="fas fa-bars fa-lg"></i>';
                });
            }
            
            // Form enhancement - prevent double submission
            document.querySelectorAll('form').forEach(form => {
                form.addEventListener('submit', function(e) {
                    const submitButton = form.querySelector('button[type="submit"]');
                    if (submitButton && !submitButton.disabled) {
                        setTimeout(() => {
                            ShopManager.showLoading(submitButton);
                        }, 100);
                    }
                });
            });
            
            // Enhanced link handling for AJAX
            document.querySelectorAll('a[data-ajax]').forEach(link => {
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    ShopManager.showLoading(this);
                    // Custom AJAX handling can be added here
                });
            });
        });
        
        // Connection status checker
        <?php if ($current_user_for_header): ?>
        setInterval(function() {
            fetch('api/check_connection.php')
                .then(response => response.json())
                .then(data => {
                    const indicators = document.querySelectorAll('.connection-indicator');
                    indicators.forEach(indicator => {
                        if (data.connected) {
                            indicator.className = 'connection-indicator flex items-center gap-2 px-3 py-1 bg-green-100 rounded-full';
                            indicator.innerHTML = '<div class="w-2 h-2 bg-green-500 rounded-full"></div><span class="text-green-700 text-xs font-medium">Online</span>';
                        } else {
                            indicator.className = 'connection-indicator flex items-center gap-2 px-3 py-1 bg-red-100 rounded-full';
                            indicator.innerHTML = '<div class="w-2 h-2 bg-red-500 rounded-full"></div><span class="text-red-700 text-xs font-medium">Offline</span>';
                        }
                    });
                })
                .catch(console.error);
        }, 30000); // Check every 30 seconds
        
        // Session ping to keep alive
        setInterval(function() {
            fetch('api/ping.php').then(r => r.json()).then(d => {
                if (!d.success && d.error === 'not_authenticated') {
                    window.location.href = 'login.php';
                }
            }).catch(e => console.error('Session ping error:', e));
        }, 300000); // Every 5 minutes
        <?php endif; ?>
    </script>

<?php } // End of header include check ?>