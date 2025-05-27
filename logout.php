<?php
// logout.php - User Logout
session_start();

require_once 'database.php';
require_once 'config.php';
require_once 'auth.php';

$auth = new Auth();

// Check if user is logged in
$current_user = $auth->getCurrentUser();

// Perform logout
$auth->logout();

// Clear any remaining session data
session_destroy();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out - WooCommerce Store Manager</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <!-- Header -->
            <div class="text-center">
                <h2 class="text-3xl font-extrabold text-gray-900">You've been logged out</h2>
                <p class="mt-2 text-sm text-gray-600">Thank you for using WooCommerce Store Manager</p>
            </div>
            
            <!-- Logout confirmation -->
            <div class="bg-white shadow rounded-lg p-6">
                <div class="text-center">
                    <div class="mx-auto flex items-center justify-center h-12 w-12 rounded-full bg-green-100 mb-4">
                        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Successfully logged out</h3>
                    <p class="text-gray-600 mb-6">Your session has been ended securely.</p>
                    
                    <div class="space-y-3">
                        <a href="login.php" class="w-full bg-blue-600 text-white py-2 px-4 rounded hover:bg-blue-700 block text-center">
                            Sign In Again
                        </a>
                        
                        <a href="index.php" class="w-full bg-gray-200 text-gray-700 py-2 px-4 rounded hover:bg-gray-300 block text-center">
                            Go to Homepage
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="text-center text-xs text-gray-500">
                <p>WooCommerce Store Manager v1.0</p>
                <p>Multi-User System</p>
            </div>
        </div>
    </div>

    <script>
        // Redirect to login after 5 seconds
        setTimeout(function() {
            window.location.href = 'login.php';
        }, 5000);
    </script>
</body>
</html>