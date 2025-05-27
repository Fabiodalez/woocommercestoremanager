<?php
// login.php - Multi-User Login System with Enhanced Debugging

// --- INIZIO BLOCCO DEBUG E CONFIGURAZIONE ERRORI ---
// Metti questo blocco il più in alto possibile.
// Disabilita in produzione!
if (!defined('LOGIN_DEBUG_MODE')) {
    define('LOGIN_DEBUG_MODE', true); // Imposta a false o commenta in produzione
}

if (LOGIN_DEBUG_MODE) {
    ini_set('display_errors', 1); // Mostra errori a schermo (solo per debug)
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
    // Funzione helper per loggare, per evitare ripetizioni
    function login_debug_log($message) {
        if (LOGIN_DEBUG_MODE && function_exists('error_log')) {
            error_log("LOGIN.PHP DEBUG: " . $message);
        }
    }
    login_debug_log("==== Script Start ====");
    login_debug_log("Request Method: " . $_SERVER['REQUEST_METHOD']);
    login_debug_log("Request URI: " . $_SERVER['REQUEST_URI']);
    login_debug_log("GET params: " . json_encode($_GET));
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Non loggare mai la password!
        $safe_post = $_POST;
        if (isset($safe_post['password'])) $safe_post['password'] = '***HIDDEN***';
        if (isset($safe_post['confirm_password'])) $safe_post['confirm_password'] = '***HIDDEN***';
        if (isset($safe_post['new_password'])) $safe_post['new_password'] = '***HIDDEN***';
        login_debug_log("POST params (passwords hidden): " . json_encode($safe_post));
    }
} else {
    // In produzione, assicurati che gli errori non vengano mostrati all'utente
    // ma vengano loggati se il server è configurato per farlo.
    ini_set('display_errors', 0);
    error_reporting(0); // O un livello più restrittivo come E_ALL & ~E_NOTICE & ~E_STRICT
    // Funzione vuota se il debug è disattivato
    function login_debug_log($message) { /* no-op */ }
}
// --- FINE BLOCCO DEBUG E CONFIGURAZIONE ERRORI ---

// session_start() DEVE essere chiamato prima di qualsiasi output HTML o echo.
// Ed anche prima di accedere a $_SESSION o di impostare cookie di sessione implicitamente.
if (session_status() == PHP_SESSION_NONE) {
    session_start();
    login_debug_log("Session started. Session ID: " . session_id());
} else {
    login_debug_log("Session already active. Session ID: " . session_id());
}

login_debug_log("Current \$_SESSION data: " . json_encode($_SESSION));
login_debug_log("Current \$_COOKIE data: " . json_encode($_COOKIE));


// Includi i file necessari. Usa __DIR__ per path robusti.
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

login_debug_log("Included core files (database, config, auth).");

$auth = new Auth(); // Auth() ora dovrebbe avere i suoi log interni
$errors = [];
$success_messages = [];

// Determina l'URL di redirect, sanitizzandolo.
$redirect_param = $_GET['redirect'] ?? ($_POST['redirect'] ?? 'dashboard.php');
$default_redirect = 'dashboard.php';
$redirect_url = $default_redirect;

if (!empty($redirect_param)) {
    // Validazione base per prevenire Open Redirect
    // Assicurati che inizi con '/' o sia un nome file semplice (senza protocolli o domini esterni)
    // Per una maggiore sicurezza, usa una whitelist di URL di redirect validi.
    if (preg_match('/^((\/[a-zA-Z0-9_.-]+)+(\.php)?(\?[a-zA-Z0-9_=&%-]*)?|[a-zA-Z0-9_.-]+\.php(\?[a-zA-Z0-9_=&%-]*)?)$/', $redirect_param)) {
        $redirect_url = $redirect_param;
        login_debug_log("Redirect URL set from parameter: " . $redirect_url);
    } else {
        login_debug_log("INVALID redirect_url parameter: '" . htmlspecialchars($redirect_param) . "'. Defaulting to: " . $default_redirect);
        $redirect_url = $default_redirect;
    }
} else {
    login_debug_log("No redirect parameter. Defaulting redirect URL to: " . $redirect_url);
}


// --- Check if user is already logged in ---
login_debug_log("Checking if user is already logged in via \$auth->getCurrentUser()...");
$current_user = $auth->getCurrentUser(); // getCurrentUser() in Auth.php dovrebbe avere i suoi log

if ($current_user && is_array($current_user) && isset($current_user['id'])) {
    login_debug_log("User (ID: " . $current_user['id'] . ") IS ALREADY LOGGED IN. Preparing to redirect to: " . $redirect_url);
    if (!headers_sent($file_hs, $line_hs)) {
        header('Location: ' . $redirect_url);
        exit;
    } else {
        login_debug_log("CRITICAL ERROR - Headers already sent from {$file_hs} on line {$line_hs}! Cannot redirect logged-in user.");
        echo "<p>You are already logged in. <a href='" . htmlspecialchars($redirect_url) . "'>Click here to continue</a>.</p>";
        exit;
    }
} else {
    login_debug_log("No user currently logged in OR \$auth->getCurrentUser() returned invalid data. Proceeding.");
}

// --- Check if registration is enabled ---
$registration_enabled = true; // Default conservativo
try {
    $db = Database::getInstance(); // Questa chiamata potrebbe lanciare un'eccezione se il DB non è accessibile
    if (method_exists($db, 'getSystemSetting')) {
        $setting_value_reg = $db->getSystemSetting('registration_enabled', true);
        $registration_enabled = filter_var($setting_value_reg, FILTER_VALIDATE_BOOLEAN);
    }
    login_debug_log("Registration enabled status: " . ($registration_enabled ? 'Yes' : 'No'));
} catch (Exception $e) {
    login_debug_log("WARNING: Exception while getting Database instance or 'registration_enabled' setting: " . $e->getMessage() . ". Using default for registration_enabled.");
    // $errors[] = "System configuration error. Please try again later."; // Potrebbe essere troppo presto per mostrare errori all'utente
}


// --- Handle form submissions (POST requests) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    login_debug_log("POST request received.");
    $action = $_POST['action'] ?? '';
    login_debug_log("POST action: '" . htmlspecialchars($action) . "'");
    
    try {
        switch ($action) {
            case 'login':
                $username_or_email = trim($_POST['username_or_email'] ?? '');
                // NON loggare $password
                $password_present = !empty($_POST['password']); // Logga solo se la password è stata inviata
                $remember_me = isset($_POST['remember_me']);
                
                login_debug_log("Processing 'login' action for: '" . htmlspecialchars($username_or_email) . "'. Password present: " . ($password_present ? 'Yes' : 'No') . ". Remember Me: " . ($remember_me ? 'Yes' : 'No'));

                if (empty($username_or_email) || !$password_present) {
                    throw new Exception('Please enter both username/email and password.');
                }
                
                $user_logged_in = $auth->login($username_or_email, $_POST['password'], $remember_me);
                
                if ($user_logged_in && is_array($user_logged_in) && isset($user_logged_in['id'])) {
                    login_debug_log("Login action SUCCESSFUL for User ID: " . $user_logged_in['id'] . ". Preparing to redirect to: " . $redirect_url);
                    if (!headers_sent($file_hs_login, $line_hs_login)) {
                        header('Location: ' . $redirect_url);
                        exit;
                    } else {
                         login_debug_log("CRITICAL ERROR - Headers already sent from {$file_hs_login} on line {$line_hs_login} after login success! Cannot redirect.");
                         echo "<p>Login successful! <a href='" . htmlspecialchars($redirect_url) . "'>Click here to continue</a>.</p>";
                         exit;
                    }
                } else {
                    // login() dovrebbe lanciare un'eccezione in caso di fallimento, quindi questo blocco è più per sicurezza.
                    login_debug_log("Login action returned invalid data or false, but no exception. User: " . json_encode($user_logged_in));
                    throw new Exception('Login failed. Please check your credentials.');
                }
                break; // Non dovrebbe essere raggiunto se il redirect ha successo
                
            case 'register':
                login_debug_log("Processing 'register' action.");
                if (!$registration_enabled) {
                    throw new Exception('Registration is currently disabled.');
                }
                $username = trim($_POST['username'] ?? '');
                $email = trim($_POST['email'] ?? '');
                // Non loggare password
                $confirm_password = $_POST['confirm_password'] ?? '';
                $first_name = trim($_POST['first_name'] ?? '');
                $last_name = trim($_POST['last_name'] ?? '');
                
                if (($_POST['password'] ?? '') !== $confirm_password) {
                    throw new Exception('Passwords do not match.');
                }
                
                $user_id = $auth->register($username, $email, $_POST['password'] ?? '', $first_name, $last_name);
                login_debug_log("Registration successful for User ID: " . $user_id);
                
                $email_verification_required = false; // Default
                if (method_exists($db, 'getSystemSetting')) { // $db dovrebbe essere già istanziato
                     try {
                        $setting_val_ver = $db->getSystemSetting('email_verification_required', false);
                        $email_verification_required = filter_var($setting_val_ver, FILTER_VALIDATE_BOOLEAN);
                    } catch (Exception $e) {
                        login_debug_log("WARNING: register() - Error getting email_verification_required: " . $e->getMessage());
                    }
                }
                    
                if ($email_verification_required) {
                    $success_messages[] = 'Registration successful! Please check your email to verify your account.';
                } else {
                    $success_messages[] = 'Registration successful! You can now log in.';
                }
                // Dopo la registrazione, di solito si rimane sulla pagina di login per permettere il login.
                // Non c'è un redirect immediato qui.
                break;
                
            case 'forgot_password':
                login_debug_log("Processing 'forgot_password' action.");
                $email = trim($_POST['email'] ?? '');
                if (empty($email)) throw new Exception('Please enter your email address.');
                $auth->requestPasswordReset($email);
                $success_messages[] = 'If an account with that email exists, you will receive a password reset link.';
                break;
                
            case 'reset_password': // Questo viene dal form di reset password, non dal link email
                login_debug_log("Processing POST 'reset_password' action.");
                $token = $_POST['token'] ?? '';
                // Non loggare password
                $confirm_password_reset = $_POST['confirm_password'] ?? '';
                if (empty($token)) throw new Exception('Reset token is missing.');
                if (($_POST['new_password'] ?? '') !== $confirm_password_reset) throw new Exception('Passwords do not match.');
                
                $auth->resetPassword($token, $_POST['new_password'] ?? '');
                $success_messages[] = 'Password reset successful! You can now log in with your new password.';
                $show_reset_form = false; // Nascondi il form di reset, mostra quello di login
                // Potrebbe essere utile un redirect a login.php senza token per pulire l'URL.
                // header('Location: login.php?reset=success'); exit;
                break;
                
            // Il case 'verify_email' e 'resend_verification' via POST sono meno comuni,
            // di solito verify_email è via GET (link da email).
            // resend_verification potrebbe essere un form separato o parte del "forgot password".

            default:
                if (!empty($action)) {
                    login_debug_log("Unknown POST action received: '" . htmlspecialchars($action) . "'");
                    throw new Exception('Invalid action requested.');
                }
                // Nessuna azione specificata, potrebbe essere un invio di form vuoto.
                login_debug_log("No specific POST action provided.");
                break;
        }
        
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
        login_debug_log("EXCEPTION caught during POST action '" . htmlspecialchars($action) . "': " . $e->getMessage() . "\nStack Trace (first few lines):\n" . substr($e->getTraceAsString(), 0, 500));
    }
}

// --- Handle GET actions (like email verification or password reset link click) ---
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action_get = $_GET['action'] ?? '';
    $token_get = $_GET['token'] ?? '';

    if ($action_get === 'verify_email' && !empty($token_get)) {
        login_debug_log("Processing GET 'verify_email' action with token (first 8): " . substr($token_get,0,8));
        try {
            $auth->verifyEmail($token_get);
            $success_messages[] = 'Email verified successfully! You can now log in.';
            // È una buona idea reindirizzare per rimuovere i token dall'URL
            if (!headers_sent()) {
                header('Location: login.php?verification_status=success'); // Vai a login.php con un messaggio
                exit;
            } else {
                login_debug_log("Headers sent, cannot redirect after GET email verification.");
            }
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
            login_debug_log("GET 'verify_email' EXCEPTION: " . $e->getMessage());
        }
    } elseif ($action_get === 'reset_password' && !empty($token_get)) {
        login_debug_log("Processing GET 'reset_password' action. Token (first 8): " . substr($token_get,0,8) . ". Setting up to show reset form.");
        $reset_token = $token_get; // Variabile usata dal template HTML
        $show_reset_form = true;   // Flag per il template HTML
    }
}

// Se siamo qui, stiamo per mostrare l'HTML.
login_debug_log("Proceeding to render HTML content. show_reset_form: " . ($show_reset_form ?? 'false'));
?>

<!DOCTYPE html>
<html lang="en"> <!-- Considerare Config::getLanguage() se disponibile e affidabile qui -->
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php
        $app_name_title = 'App'; // Default
        try { $app_name_title = Config::getSystemSetting('app_name', 'WooCommerce Store Manager'); }
        catch (Exception $e) { login_debug_log("Error getting app_name for title: " . $e->getMessage()); }
        echo htmlspecialchars($app_name_title);
    ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        .form-tab { transition: all 0.3s ease; border-bottom: 2px solid transparent; }
        .form-tab.active { border-bottom-color: #3b82f6; color: #3b82f6; }
        .form-section { display: none; }
        .form-section.active { display: block; }
        .password-strength { height: 5px; transition: all 0.3s ease; border-radius: 9999px; }
        .password-strength.weak { background-color: #ef4444; width: 25%; }
        .password-strength.fair { background-color: #f59e0b; width: 50%; }
        .password-strength.good { background-color: #10b981; width: 75%; }
        .password-strength.strong { background-color: #059669; width: 100%; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen text-gray-800">
    <div class="min-h-screen flex flex-col items-center justify-center py-12 px-4 sm:px-6 lg:px-8">
        <div class="max-w-md w-full space-y-8">
            <div class="text-center">
                 <h2 class="mt-6 text-3xl font-extrabold text-gray-900">
                    <?php echo htmlspecialchars($app_name_title); ?>
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    Access your multi-user store management system.
                </p>
            </div>
            
            <?php if (!empty($errors)): ?>
                <div class="bg-red-50 border-l-4 border-red-400 p-4 rounded-md shadow-sm">
                    <div class="flex"><div class="flex-shrink-0"><svg class="h-5 w-5 text-red-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm-.707-4.707a1 1 0 001.414-1.414L10 10.586l.293-.293a1 1 0 00-1.414-1.414L9 10.586l-.293-.293a1 1 0 00-1.414 1.414L8.586 12l-.293.293a1 1 0 101.414 1.414L10 13.414l.707.707z" clip-rule="evenodd" /></svg></div><div class="ml-3"><h3 class="text-sm font-medium text-red-800">Please correct the following errors:</h3><div class="mt-2 text-sm text-red-700"><ul role="list" class="list-disc pl-5 space-y-1"><?php foreach ($errors as $error): ?><li><?php echo $error; // Già htmlspecialchars prima se necessario, ma per sicurezza ?></li><?php endforeach; ?></ul></div></div></div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($success_messages)): ?>
                <div class="bg-green-50 border-l-4 border-green-400 p-4 rounded-md shadow-sm">
                     <div class="flex"><div class="flex-shrink-0"><svg class="h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg></div><div class="ml-3"><div class="text-sm text-green-700"><?php foreach ($success_messages as $message): ?><p><?php echo htmlspecialchars($message); ?></p><?php endforeach; ?></div></div></div>
                </div>
            <?php endif; ?>
            
            <div class="bg-white shadow-xl rounded-lg overflow-hidden">
                <?php if (isset($show_reset_form) && $show_reset_form && !empty($reset_token)): ?>
                    <div class="p-6 sm:p-8">
                        <h3 class="text-xl font-semibold text-gray-800 mb-1">Reset Your Password</h3><p class="text-sm text-gray-500 mb-6">Enter and confirm your new password.</p>
                        <form method="POST" action="login.php" class="space-y-6">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="token" value="<?php echo htmlspecialchars($reset_token); ?>">
                            <div><label for="new_password" class="block text-sm font-medium text-gray-700">New Password</label><input type="password" id="new_password" name="new_password" required minlength="8" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" onkeyup="checkPasswordStrength(this.value, 'password-strength-reset')"><div class="mt-2 bg-gray-200 rounded-full h-1.5"><div id="password-strength-reset" class="password-strength bg-gray-300"></div></div><p class="mt-1 text-xs text-gray-500">Min. 8 characters. Uppercase, lowercase, and number recommended.</p></div>
                            <div><label for="confirm_new_password" class="block text-sm font-medium text-gray-700">Confirm New Password</label><input type="password" id="confirm_new_password" name="confirm_password" required minlength="8" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"></div>
                            <button type="submit" class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition">Reset Password</button>
                        </form>
                        <div class="mt-6 text-center"><a href="login.php" class="text-sm font-medium text-blue-600 hover:text-blue-500 hover:underline">Back to Sign In</a></div>
                    </div>
                <?php else: ?>
                    <div class="border-b border-gray-200"><nav class="flex -mb-px" aria-label="Tabs"><button type="button" id="login-tab" class="form-tab w-1/2 py-4 px-1 text-center border-b-2 font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300 focus:outline-none active" onclick="showTab('login')">Sign In</button><?php if ($registration_enabled): ?><button type="button" id="register-tab" class="form-tab w-1/2 py-4 px-1 text-center border-b-2 font-medium text-sm text-gray-500 hover:text-gray-700 hover:border-gray-300 focus:outline-none" onclick="showTab('register')">Create Account</button><?php endif; ?></nav></div>
                    <div id="login-form" class="form-section active p-6 sm:p-8">
                        <form method="POST" action="login.php" class="space-y-6">
                            <input type="hidden" name="action" value="login">
                            <?php if(isset($_GET['redirect'])): ?><input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_GET['redirect']); ?>"><?php endif; ?>
                            <div><label for="username_or_email" class="block text-sm font-medium text-gray-700">Username or Email</label><input type="text" id="username_or_email" name="username_or_email" required value="<?php echo htmlspecialchars($_POST['username_or_email'] ?? ''); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" autocomplete="username"></div>
                            <div><div class="flex items-center justify-between"><label for="password" class="block text-sm font-medium text-gray-700">Password</label><div class="text-sm"><a href="#" id="forgot-password-link" onclick="event.preventDefault(); showTab('forgot');" class="font-medium text-blue-600 hover:text-blue-500 hover:underline">Forgot password?</a></div></div><input type="password" id="password" name="password" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" autocomplete="current-password"></div>
                            <div class="flex items-center"><input id="remember_me" name="remember_me" type="checkbox" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"><label for="remember_me" class="ml-2 block text-sm text-gray-900">Remember me</label></div>
                            <button type="submit" class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition">Sign In</button>
                        </form>
                    </div>
                    <?php if ($registration_enabled): ?>
                        <div id="register-form" class="form-section p-6 sm:p-8">
                            <form method="POST" action="login.php" class="space-y-6">
                                <input type="hidden" name="action" value="register">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-6"><div><label for="reg_first_name" class="block text-sm font-medium text-gray-700">First Name</label><input type="text" id="reg_first_name" name="first_name" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" autocomplete="given-name"></div><div><label for="reg_last_name" class="block text-sm font-medium text-gray-700">Last Name</label><input type="text" id="reg_last_name" name="last_name" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" autocomplete="family-name"></div></div>
                                <div><label for="reg_username" class="block text-sm font-medium text-gray-700">Username *</label><input type="text" id="reg_username" name="username" required minlength="3" maxlength="50" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" pattern="^[a-zA-Z0-9_-]+$" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" autocomplete="username"><p class="mt-1 text-xs text-gray-500">3-50 characters. Letters, numbers, underscores, hyphens.</p></div>
                                <div><label for="reg_email" class="block text-sm font-medium text-gray-700">Email Address *</label><input type="email" id="reg_email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" autocomplete="email"></div>
                                <div><label for="reg_password" class="block text-sm font-medium text-gray-700">Password *</label><input type="password" id="reg_password" name="password" required minlength="8" pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" onkeyup="checkPasswordStrength(this.value, 'reg-password-strength')" autocomplete="new-password"><div class="mt-2 bg-gray-200 rounded-full h-1.5"><div id="reg-password-strength" class="password-strength bg-gray-300"></div></div><p class="mt-1 text-xs text-gray-500">Min. 8 characters. Must include uppercase, lowercase, and number.</p></div>
                                <div><label for="reg_confirm_password" class="block text-sm font-medium text-gray-700">Confirm Password *</label><input type="password" id="reg_confirm_password" name="confirm_password" required minlength="8" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" autocomplete="new-password"></div>
                                <button type="submit" class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition">Create Account</button>
                            </form>
                        </div>
                    <?php endif; ?>
                    <div id="forgot-form" class="form-section p-6 sm:p-8">
                         <h3 class="text-xl font-semibold text-gray-800 mb-1">Forgot Password?</h3><p class="text-sm text-gray-500 mb-6">Enter your email and we'll send you a reset link.</p>
                        <form method="POST" action="login.php" class="space-y-6">
                            <input type="hidden" name="action" value="forgot_password">
                            <div><label for="forgot_email" class="block text-sm font-medium text-gray-700">Email Address</label><input type="email" id="forgot_email" name="email" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" autocomplete="email"></div>
                            <button type="submit" class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition">Send Password Reset Link</button>
                        </form>
                        <div class="mt-6 text-center"><a href="#" id="back-to-login-link-from-forgot" onclick="event.preventDefault(); showTab('login');" class="text-sm font-medium text-blue-600 hover:text-blue-500 hover:underline">Back to Sign In</a></div>
                        <div class="mt-8 pt-6 border-t border-gray-200"><h4 class="text-sm font-medium text-gray-700 mb-3">Didn't receive verification email?</h4><form method="POST" action="login.php" class="space-y-3"><input type="hidden" name="action" value="resend_verification"><div><label for="resend_email" class="sr-only">Email for resend</label><input type="email" id="resend_email" name="email" placeholder="Enter your email address" required class="block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm"></div><button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-gray-600 hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition">Resend Verification Email</button></form></div>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="text-center text-xs text-gray-500 mt-8">
                <p>© <?php echo date('Y'); ?> <?php echo htmlspecialchars($app_name_title); ?>. All rights reserved.</p>
            </div>
        </div>
    </div>
    
    <script>
        // JavaScript come fornito precedentemente, ma con ID unico per la barra di forza password nel form di reset
        function showTab(tabName) {
            document.querySelectorAll('.form-section').forEach(section => section.classList.remove('active'));
            document.querySelectorAll('.form-tab').forEach(tab => tab.classList.remove('active'));
            const formToShow = document.getElementById(tabName + '-form');
            const tabButton = document.getElementById(tabName + '-tab');
            if (formToShow) formToShow.classList.add('active');
            if (tabButton) tabButton.classList.add('active');
            const firstInput = formToShow ? formToShow.querySelector('input:not([type="hidden"])') : null;
            if (firstInput) firstInput.focus();
        }
        
        function checkPasswordStrength(password, strengthBarId) {
            const strengthBar = document.getElementById(strengthBarId);
            if (!strengthBar) return;
            let score = 0;
            if (!password) { strengthBar.className = 'password-strength '; return; }
            if (password.length >= 8) score++;
            if (password.length >= 12) score++; // Più forte se più lungo
            if (/[a-z]/.test(password)) score++;
            if (/[A-Z]/.test(password)) score++;
            if (/\d/.test(password)) score++;
            if (/[^a-zA-Z0-9]/.test(password)) score++;
            
            strengthBar.className = 'password-strength ';
            if (score <= 2) strengthBar.classList.add('weak');      // rosso
            else if (score <= 3) strengthBar.classList.add('fair'); // arancione
            else if (score <= 5) strengthBar.classList.add('good'); // verde chiaro
            else strengthBar.classList.add('strong');   // verde scuro
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            function validatePasswordMatch(pwId, confirmPwId) { /* ... come prima ... */ 
                const pw = document.getElementById(pwId);
                const confirmPw = document.getElementById(confirmPwId);
                if(pw && confirmPw){
                    const validate = () => { confirmPw.setCustomValidity(pw.value !== confirmPw.value ? 'Passwords do not match.' : ''); };
                    pw.addEventListener('input', validate); confirmPw.addEventListener('input', validate);
                }
            }
            validatePasswordMatch('reg_password', 'reg_confirm_password');
            validatePasswordMatch('new_password', 'confirm_new_password');

            const urlParams = new URLSearchParams(window.location.search);
            const currentAction = urlParams.get('action');
            const successMsg = document.querySelector('.bg-green-50');
            const errorMsgInRegister = document.querySelector('#register-form .bg-red-50');
            const errorMsgInForgot = document.querySelector('#forgot-form .bg-red-50'); // Se avessi errori specifici per forgot

            if (currentAction === 'reset_password' && urlParams.get('token')) {
                // Gestito da PHP con $show_reset_form
            } else if (window.location.hash === '#register' || (errorMsgInRegister && !successMsg) ) {
                if(document.getElementById('register-tab')) showTab('register');
            } else if (window.location.hash === '#forgot' || (errorMsgInForgot && !successMsg)) {
                if(document.getElementById('forgot-form')) showTab('forgot');
            } else {
                 const firstLoginInput = document.querySelector('#login-form input:not([type="hidden"])');
                 if (firstLoginInput) firstLoginInput.focus();
            }

            // Se c'è un messaggio di successo (es. dopo registrazione, verifica email, reset password)
            // e non siamo nel form di reset, mostra il tab di login.
            if (successMsg && !(isset($show_reset_form) && $show_reset_form)) {
                showTab('login');
            }
        });
    </script>
</body>
</html>