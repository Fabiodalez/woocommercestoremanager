<?php
// index.php - Homepage
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

require_once 'config.php';

// Initialize Config (loads DB, Auth, settings)
Config::init();

// Check if system is installed
try {
    $user_count = Database::getInstance()->count('users');
    $is_installed = ($user_count > 0);
} catch (Exception $e) {
    $is_installed = false;
}

// If not installed, redirect to installation
if (!$is_installed) {
    header('Location: install.php');
    exit;
}

// Check if user is already logged in
$current_user = Config::getCurrentUser();
if ($current_user) {
    header('Location: dashboard.php');
    exit;
}

// Get system settings for homepage
$app_name = Config::getSystemSetting('app_name', 'WooCommerce Store Manager');
$app_version = Config::getSystemSetting('app_version', '1.0.0');
$registration_enabled = Config::getSystemSetting('registration_enabled', true);
$maintenance_mode = Config::getSystemSetting('maintenance_mode', false);

// Check maintenance mode for non-admin users
if ($maintenance_mode && !Config::isAdmin()) {
    include 'maintenance.php';
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($app_name); ?> - Multi-User Store Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .hero-gradient {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .feature-card {
            transition: all 0.3s ease;
        }
        .feature-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        .animate-float {
            animation: float 3s ease-in-out infinite;
        }
        .animate-float-delayed {
            animation: float 3s ease-in-out infinite;
            animation-delay: 1s;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Navigation -->
    <nav class="bg-white shadow-lg fixed w-full top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <h1 class="text-xl font-bold text-gray-900"><?php echo htmlspecialchars($app_name); ?></h1>
                    </div>
                </div>
                <div class="hidden md:block">
                    <div class="ml-10 flex items-baseline space-x-4">
                        <a href="#features" class="text-gray-600 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium transition">Features</a>
                        <a href="#about" class="text-gray-600 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium transition">About</a>
                        <a href="#contact" class="text-gray-600 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium transition">Contact</a>
                    </div>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="login.php" class="text-gray-600 hover:text-gray-900 px-3 py-2 rounded-md text-sm font-medium transition">Sign In</a>
                    <?php if ($registration_enabled): ?>
                        <a href="login.php#register" class="bg-blue-600 text-white px-4 py-2 rounded-md text-sm font-medium hover:bg-blue-700 transition">Get Started</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-gradient pt-20">
        <div class="max-w-7xl mx-auto py-16 px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div class="text-white">
                    <h1 class="text-4xl md:text-6xl font-bold mb-6">
                        Manage Your WooCommerce Stores with <span class="text-yellow-300">Ease</span>
                    </h1>
                    <p class="text-xl md:text-2xl mb-8 text-white/90">
                        A powerful multi-user platform for managing multiple WooCommerce stores from one centralized dashboard.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4 mb-8">
                        <a href="login.php" class="bg-white text-blue-600 px-8 py-3 rounded-lg font-semibold hover:bg-gray-100 transition text-center">Sign In Now</a>
                        <?php if ($registration_enabled): ?>
                            <a href="login.php#register" class="bg-transparent border-2 border-white text-white px-8 py-3 rounded-lg font-semibold hover:bg-white hover:text-blue-600 transition text-center">Create Account</a>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center space-x-6 text-white/80">
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Multi-User Support
                        </div>
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Secure & Reliable
                        </div>
                        <div class="flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="currentColor" viewBox="0 0 20 20"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>Easy Setup
                        </div>
                    </div>
                </div>
                <div class="relative">
                    <div class="animate-float">
                        <div class="bg-white rounded-2xl shadow-2xl p-6 mb-4">
                            <div class="flex items-center space-x-3 mb-4">
                                <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                                <div class="w-3 h-3 bg-yellow-500 rounded-full"></div>
                                <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                            </div>
                            <div class="space-y-3">
                                <div class="h-4 bg-gray-200 rounded w-3/4"></div>
                                <div class="h-4 bg-gray-200 rounded w-1/2"></div>
                                <div class="h-4 bg-blue-200 rounded w-2/3"></div>
                                <div class="grid grid-cols-3 gap-3 mt-4">
                                    <div class="h-16 bg-blue-100 rounded"></div>
                                    <div class="h-16 bg-green-100 rounded"></div>
                                    <div class="h-16 bg-purple-100 rounded"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="animate-float-delayed absolute -right-4 -bottom-4">
                        <div class="bg-white rounded-xl shadow-xl p-4 w-64">
                            <div class="flex items-center space-x-2 mb-2">
                                <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center">
                                    <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                                <span class="font-medium text-gray-900">Store Connected</span>
                            </div>
                            <p class="text-sm text-gray-600">Your WooCommerce store is now connected and ready to manage!</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Features Section -->
    <section id="features" class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16">
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">Powerful Features for Store Management</h2>
                <p class="text-xl text-gray-600 max-w-3xl mx-auto">Everything you need to efficiently manage your WooCommerce stores and collaborate with your team.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <!-- Feature cards (1-6) as in original file -->
                ... (Repeat full feature cards code) ...
            </div>
        </div>
    </section>

    <!-- Statistics Section -->
    <section class="py-16 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-3xl font-bold text-gray-900 mb-4">Trusted by Store Owners Worldwide</h2>
                <p class="text-xl text-gray-600">Join thousands of businesses managing their WooCommerce stores efficiently</p>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
                <!-- Stats 500+, 1200+, 50K+, 99.9% -->
                ... (Repeat full stats code) ...
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="py-16 bg-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <!-- About text and ready to get started panel -->
                ... (Repeat full about section code) ...
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="bg-gray-900 text-white py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <!-- Footer columns -->
                ... (Repeat full footer code) ...
            </div>
            <div class="border-t border-gray-800 mt-8 pt-8 text-center text-gray-400">
                <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($app_name); ?>. All rights reserved.</p>
            </div>
        </div>
    </footer>

    <script>
        // Smooth scrolling for anchor links and fade-in animations
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({behavior: 'smooth', block: 'start'});
                }
            });
        });
        document.addEventListener('DOMContentLoaded', function() {
            const observerOptions = {threshold: 0.1, rootMargin: '0px 0px -50px 0px'};
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);
            document.querySelectorAll('.feature-card').forEach(card => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                card.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
                observer.observe(card);
            });
        });
    </script>
</body>
</html>
