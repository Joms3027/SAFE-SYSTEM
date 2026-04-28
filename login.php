<?php
// CRITICAL: DO NOT start session here - let config.php handle it
// config.php configures session cookie parameters BEFORE starting session
// Starting session here would use default settings and ignore config.php's cookie configuration
// This was causing "Invalid form submission" errors in production

require_once 'includes/config.php'; // This will start session with correct cookie settings
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// CRITICAL: Use absolute paths for all redirects to fix PWA navigation on mobile
$basePath = SITE_URL; // Use the configured SITE_URL which includes the correct path

// Clear redirect counter when on login page
unset($_SESSION['index_redirect_count']);

// Only check for existing session and redirect if NOT processing a POST request
// POST requests mean user is actively logging in, so don't interfere
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Only redirect if truly logged in with complete session
    // Check for both user_id and user_type to ensure session is valid
    if (isLoggedIn() && isset($_SESSION['user_type']) && !empty($_SESSION['user_type'])) {
        // Additional validation: ensure user_type is valid
        $validUserTypes = ['super_admin', 'admin', 'faculty', 'staff', 'timekeeper', 'station'];
        if (in_array($_SESSION['user_type'], $validUserTypes)) {
            // User has valid session - redirect to appropriate dashboard
            // But first check if we're in a redirect loop by checking referer
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $currentUri = $_SERVER['REQUEST_URI'] ?? '';
            
            // If we came from a dashboard page, there might be a loop - don't redirect
            // Check for both .php extension and clean URLs
            $isFromDashboard = strpos($referer, 'dashboard.php') !== false || 
                              strpos($referer, '/dashboard') !== false ||
                              strpos($referer, '/admin/dashboard') !== false ||
                              strpos($referer, '/faculty/dashboard') !== false ||
                              strpos($referer, '/timekeeper/dashboard') !== false;
            
            // Only redirect if not coming from dashboard (prevents loops)
            if (!$isFromDashboard) {
                $safeRedirect = trim($_GET['redirect'] ?? '');
                if ($safeRedirect !== '' && strpos($safeRedirect, '//') === false && isset($safeRedirect[0]) && $safeRedirect[0] === '/') {
                    $parsed = parse_url($basePath);
                    $origin = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? 'localhost') . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
                    redirect($origin . $safeRedirect);
                }
                if (isAdmin()) {
                    redirect(clean_url($basePath . '/admin/dashboard.php'));
                } elseif (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'station') {
                    // Station users should go to QR scanner
                    redirect(clean_url($basePath . '/timekeeper/qrcode-scanner.php'));
                } elseif (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'timekeeper') {
                    // Timekeeper users should go to timekeeper dashboard
                    redirect(clean_url($basePath . '/timekeeper/dashboard.php'));
                } else {
                    redirect(clean_url($basePath . '/faculty/dashboard.php'));
                }
            } else {
                // Coming from dashboard but on login page - session might be invalid
                // Clear session to break the loop
                session_destroy();
                session_start();
            }
        } else {
            // Invalid user_type - clear session to prevent loops
            session_destroy();
            session_start();
        }
    } else {
        // Session is incomplete or invalid - ensure clean state
        if (isset($_SESSION['user_id']) && !isset($_SESSION['user_type'])) {
            // Partial session detected - clear it
            session_destroy();
            session_start();
        }
    }
}

$auth = new Auth();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure session is active before processing login
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $email = sanitizeInput($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    
    // Enhanced validation with better error messages
    if (empty($email) || empty($password)) {
        $error = "Please enter both email and password.";
    } elseif (empty($token)) {
        // This usually means the CSRF token wasn't included in the form
        // Could be a session issue or form tampering
        $sessionId = session_id();
        $hasSessionCookie = isset($_COOKIE[session_name()]);
        $error = "Invalid form submission. Please refresh the page and try again.";
        
        // Log for debugging (only in production to help diagnose)
        if (!isLocalhost()) {
            error_log("Login CSRF token missing - Session ID: " . ($sessionId ?: 'none') . 
                     ", Cookie sent: " . ($hasSessionCookie ? 'yes' : 'no') . 
                     ", Host: " . ($_SERVER['HTTP_HOST'] ?? 'unknown'));
        }
    } else {
        $result = $auth->login($email, $password, $token);
        
        if ($result === 'success') {
            // Redirect to requested page (e.g. HR Event check-in) if safe
            $redirect = trim($_POST['redirect'] ?? $_GET['redirect'] ?? '');
            if ($redirect !== '' && strpos($redirect, '//') === false && isset($redirect[0]) && $redirect[0] === '/') {
                $parsed = parse_url($basePath);
                $origin = ($parsed['scheme'] ?? 'http') . '://' . ($parsed['host'] ?? 'localhost') . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
                redirect($origin . $redirect);
            }
            if (isAdmin()) {
                redirect(clean_url($basePath . '/admin/dashboard.php'));
            } else {
                // Faculty and Staff both go to faculty dashboard
                redirect(clean_url($basePath . '/faculty/dashboard.php'));
            }
        } else {
            $error = $result;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- Performance: DNS Prefetch & Preconnect -->
    <link rel="dns-prefetch" href="//fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- PWA Meta Tags - Must be first for full-screen app experience -->
    <?php include_once 'includes/pwa-meta.php'; ?>
    <meta name="description" content="Sign in to WPU Safe System - Faculty and Staff Management">
    <meta http-equiv="x-dns-prefetch-control" content="on">
    
    <title>Sign In - WPU Safe System</title>
    
    <!-- Preload critical resources -->
    <link rel="preload" href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" as="style">
    <link rel="preload" href="<?php echo asset_url('vendor/fontawesome/css/all.min.css'); ?>" as="style">
    
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css'); ?>" rel="stylesheet">
    <style>
        /* Login page - WPU Safe System (aligned with landing) */
        :root {
            --login-primary: #0a2540;
            --login-primary-hover: #0d3a5c;
            --login-accent: #0066cc;
            --login-accent-soft: #e8f4fc;
            --login-text: #1a2b3c;
            --login-text-muted: #5a6c7d;
            --login-border: #e2e8f0;
            --login-bg: #f4f7fa;
            --login-card-bg: #ffffff;
            --login-radius: 12px;
            --login-radius-sm: 8px;
            --login-shadow: 0 4px 20px rgba(10, 37, 64, 0.08);
            --login-shadow-hover: 0 8px 32px rgba(10, 37, 64, 0.12);
            --login-transition: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        @media (prefers-reduced-motion: reduce) {
            .login-card, .login-body *, .btn-primary { animation: none !important; transition-duration: 0.01ms !important; }
        }
        
        html, body {
            height: 100%;
            min-height: 100%;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        .login-body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--login-bg);
            background-image: linear-gradient(160deg, #f4f7fa 0%, #e8eef5 50%, #e2eaf2 100%);
            min-height: 100vh;
            min-height: 100dvh; /* dynamic viewport on mobile */
            min-height: -webkit-fill-available;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            width: 100%;
            max-width: 100vw;
            box-sizing: border-box;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
            margin: 0 auto;
            box-sizing: border-box;
            flex-shrink: 0;
        }
        
        .login-card {
            background: var(--login-card-bg);
            border-radius: var(--login-radius);
            box-shadow: var(--login-shadow);
            padding: 2.75rem 2.5rem;
            border: 1px solid var(--login-border);
            transition: box-shadow var(--login-transition);
            animation: loginCardIn 0.4s ease-out;
        }
        
        .login-card:hover {
            box-shadow: var(--login-shadow-hover);
        }
        
        @keyframes loginCardIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-back-link {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            color: var(--login-text-muted);
            text-decoration: none;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 1.5rem;
            transition: color var(--login-transition);
        }
        
        .login-back-link:hover {
            color: var(--login-accent);
        }
        
        .login-back-link:focus-visible {
            outline: 2px solid var(--login-accent);
            outline-offset: 2px;
            border-radius: var(--login-radius-sm);
        }
        
        .login-logo {
            margin: 0 auto 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-logo .logo-img {
            max-width: 112px;
            height: auto;
            display: block;
        }
        
        .login-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--login-text);
            text-align: center;
            margin-bottom: 0.25rem;
            letter-spacing: -0.02em;
        }
        
        .login-subtitle {
            font-size: 0.9375rem;
            color: var(--login-text-muted);
            text-align: center;
            margin-bottom: 2rem;
            font-weight: 500;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--login-text);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .form-control {
            border: 1px solid var(--login-border);
            border-radius: var(--login-radius-sm);
            padding: 0.75rem 1rem;
            font-size: 0.9375rem;
            transition: border-color var(--login-transition), box-shadow var(--login-transition);
            min-height: 44px;
            background: #fff;
            width: 100%;
            outline: none;
            color: var(--login-text);
            font-family: inherit;
        }
        
        .form-control::placeholder {
            color: #94a3b8;
        }
        
        .form-control:focus {
            border-color: var(--login-accent);
            box-shadow: 0 0 0 3px var(--login-accent-soft);
        }
        
        .input-group {
            border-radius: var(--login-radius-sm);
            overflow: hidden;
            transition: border-color var(--login-transition), box-shadow var(--login-transition);
            display: flex;
            align-items: stretch;
            width: 100%;
            background: #fff;
            border: 1px solid var(--login-border);
        }
        
        .input-group:focus-within {
            border-color: var(--login-accent);
            box-shadow: 0 0 0 3px var(--login-accent-soft);
        }
        
        .input-group-text {
            background: #f8fafc;
            border: none;
            border-right: 1px solid var(--login-border);
            color: var(--login-text-muted);
            padding: 0.75rem 1rem;
            min-width: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .input-group .form-control {
            border: none;
            border-radius: 0;
            background: transparent;
            flex: 1;
            padding-left: 0.75rem;
        }
        
        .input-group:focus-within .input-group-text {
            border-right-color: var(--login-accent);
        }
        
        .btn-toggle-password {
            background: #f8fafc;
            border: none;
            border-left: 1px solid var(--login-border);
            color: var(--login-text-muted);
            padding: 0.75rem 1rem;
            min-width: 44px;
            transition: background-color var(--login-transition), color var(--login-transition);
            cursor: pointer;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-toggle-password:hover {
            background: #f1f5f9;
            color: var(--login-text);
        }
        
        .btn-toggle-password:focus-visible {
            outline: 2px solid var(--login-accent);
            outline-offset: -2px;
        }
        
        .input-group:focus-within .btn-toggle-password {
            border-left-color: var(--login-accent);
        }
        
        .btn-primary {
            background: var(--login-primary);
            border: none;
            border-radius: var(--login-radius-sm);
            padding: 0.75rem 1.5rem;
            font-size: 0.9375rem;
            font-weight: 600;
            min-height: 44px;
            transition: background-color var(--login-transition), transform 0.15s ease;
            color: #fff;
            margin-top: 1.5rem;
            width: 100%;
            font-family: inherit;
        }
        
        #loginBtn {
            min-width: 100%;
        }
        
        .btn-primary:hover:not(:disabled) {
            background: var(--login-primary-hover);
            transform: translateY(-1px);
        }
        
        .btn-primary:active:not(:disabled) {
            transform: translateY(0);
        }
        
        .btn-primary:focus-visible {
            outline: 2px solid var(--login-accent);
            outline-offset: 2px;
        }
        
        .btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .alert {
            border-radius: var(--login-radius-sm);
            border: 1px solid;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        
        .alert i {
            flex-shrink: 0;
            margin-top: 0.1rem;
        }
        
        .alert span {
            flex: 1;
            min-width: 0;
        }
        
        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }
        
        .alert-info {
            background: #eff6ff;
            color: #1e40af;
            border-color: #bfdbfe;
        }
        
        .form-text { font-size: 0.8125rem; color: var(--login-text-muted); margin-top: 0.375rem; }
        .spinner-border-sm { width: 1rem; height: 1rem; border-width: 0.15em; }
        
        .login-footer-links {
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--login-border);
            text-align: center;
        }
        
        .login-footer-links a {
            color: var(--login-accent);
            text-decoration: none;
            font-size: 0.9375rem;
            font-weight: 500;
            transition: color var(--login-transition);
        }
        
        .login-footer-links a:hover {
            color: var(--login-primary);
            text-decoration: underline;
        }
        
        .login-footer-links a:focus-visible {
            outline: 2px solid var(--login-accent);
            outline-offset: 2px;
            border-radius: var(--login-radius-sm);
        }
        
        /* Mobile – full center, fix right offset from global style.css */
        @media (max-width: 768px) {
            html, body {
                min-height: 100vh;
                min-height: 100dvh;
                min-height: -webkit-fill-available;
            }
            .login-body {
                padding: 1.5rem 1rem;
                min-height: 100vh;
                min-height: 100dvh;
                min-height: -webkit-fill-available;
                align-items: center;
                justify-content: center;
            }
            .login-container {
                max-width: 100%;
                width: 100%;
                margin: 0 auto;
                padding: 0 1rem;
            }
            .login-card {
                padding: 2.25rem 1.75rem;
                width: 100% !important;
                max-width: 100% !important;
                margin-left: 0 !important;
                margin-right: 0 !important;
            }
            .login-logo .logo-img { max-width: 100px; }
            .login-title { font-size: 1.375rem; }
        }
        
        @media (max-width: 576px) {
            html, body {
                overflow-x: hidden;
                min-height: 100vh;
                min-height: 100dvh;
                min-height: -webkit-fill-available;
            }
            .login-body {
                padding: 0 1rem;
                min-height: 100vh;
                min-height: 100dvh;
                min-height: -webkit-fill-available;
                align-items: center;
                justify-content: center;
                display: flex;
            }
            .login-container {
                max-width: 100%;
                width: 100%;
                margin: 0 auto;
                padding: 0 1rem;
                box-sizing: border-box;
            }
            .login-card {
                padding: 2rem 1.5rem;
                border-radius: 10px;
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                box-sizing: border-box;
            }
            .login-logo .logo-img { max-width: 90px; }
            .login-title { font-size: 1.25rem; }
            .form-control, .input-group-text, .btn-toggle-password { min-height: 44px; font-size: 16px; }
        }
        
        @supports (-webkit-touch-callout: none) {
            .form-control { font-size: 16px; }
        }
        
        @media (max-width: 576px) {
            .input-group .form-control { border: none !important; box-shadow: none !important; }
        }
        
        /* PWA / Station link */
        #pwa-station-login-link {
            animation: fadeIn 0.3s ease-out;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--login-border);
            text-align: center;
        }
        
        #pwa-station-login-link .btn-outline-secondary {
            border-color: var(--login-border);
            color: var(--login-text-muted);
            background: transparent;
            border-radius: var(--login-radius-sm);
            font-weight: 500;
            transition: all var(--login-transition);
        }
        
        #pwa-station-login-link .btn-outline-secondary:hover {
            border-color: var(--login-primary);
            color: var(--login-primary);
            background: #f8fafc;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (min-width: 768px) {
            #pwa-install-button { display: none !important; }
        }
        
        #pwa-install-container-login { margin-top: 1rem; }
        #pwa-install-btn-login {
            border-radius: 20px;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }
        #pwa-install-btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(10, 37, 64, 0.15);
        }
    </style>
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <a href="<?php echo $basePath; ?>/" class="login-back-link" aria-label="Back to home">
                <i class="fas fa-arrow-left" aria-hidden="true"></i>
                Back to home
            </a>
            <div class="text-center mb-4">
                <div class="login-logo">
                    <img src="<?php echo asset_url('logo.png'); ?>" alt="WPU Logo" class="logo-img">
                </div>
                <h1 class="login-title">Sign In</h1>
                <p class="login-subtitle">WPU Safe System — Faculty &amp; Staff</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <?php displayMessage(); ?>
            
            <form method="POST" id="loginForm" novalidate>
                <?php 
                // CRITICAL: Ensure session is active and cookie is set before generating token
                if (session_status() === PHP_SESSION_NONE) {
                    session_start();
                }
                
                // Verify session cookie will be sent
                $sessionId = session_id();
                $cookieName = session_name();
                $hasCookie = isset($_COOKIE[$cookieName]);
                
                if (empty($sessionId)) {
                    error_log("WARNING: No session ID when generating CSRF token on login page!");
                }
                
                // Log session state for debugging (production only)
                if (!isLocalhost()) {
                    $cookieParams = session_get_cookie_params();
                    error_log("Login page - Session ID: " . substr($sessionId, 0, 16) . 
                             ", Cookie present: " . ($hasCookie ? 'yes' : 'no') . 
                             ", Cookie domain: " . ($cookieParams['domain'] ?: 'empty') . 
                             ", Cookie secure: " . ($cookieParams['secure'] ? 'yes' : 'no') . 
                             ", Cookie path: " . $cookieParams['path']);
                }
                
                addFormToken();
                $safeRedirect = trim($_GET['redirect'] ?? '');
                if ($safeRedirect !== '' && strpos($safeRedirect, '//') === false && isset($safeRedirect[0]) && $safeRedirect[0] === '/') {
                    echo '<input type="hidden" name="redirect" value="' . htmlspecialchars($safeRedirect, ENT_QUOTES, 'UTF-8') . '">';
                }
                ?>
                
                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-envelope"></i>
                        </span>
                        <input type="email" 
                               class="form-control" 
                               id="email" 
                               name="email" 
                               placeholder="your.email@wpu.edu.ph"
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                               required
                               autocomplete="email"
                               aria-required="true">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" 
                               class="form-control" 
                               id="password" 
                               name="password" 
                               placeholder="Enter your password"
                               required
                               autocomplete="current-password"
                               aria-required="true"
                               minlength="1">
                        <button class="btn-toggle-password" 
                                type="button" 
                                id="togglePassword"
                                title="Show/Hide Password"
                                aria-label="Toggle password visibility"
                                aria-pressed="false">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" id="loginBtn">
                    Sign In
                </button>
            </form>
            
            <div class="login-footer-links">
                <a href="<?php echo $basePath; ?>/forgot-password.php">Forgot password?</a>
            </div>
            
            <!-- Station Login Link (PWA Only) -->
            <div id="pwa-station-login-link" style="display: none;">
                <a href="<?php echo $basePath; ?>/station_login.php" 
                   class="btn btn-outline-secondary text-decoration-none d-inline-flex align-items-center gap-2">
                    <i class="fas fa-building" aria-hidden="true"></i>
                    <span>Station Login</span>
                </a>
                <p class="text-muted small mt-2 mb-0" style="font-size: 0.75rem;">For station or timekeeper access</p>
            </div>
            
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
    <script>
        // CRITICAL: Verify session cookie is set (helps diagnose production issues)
        (function() {
            const sessionCookieName = '<?php echo session_name(); ?>';
            const hasSessionCookie = document.cookie.indexOf(sessionCookieName + '=') !== -1;
            const isSecure = window.location.protocol === 'https:';
            const port = window.location.port || (isSecure ? '443' : '80');
            
            if (!hasSessionCookie) {
                console.warn('WARNING: Session cookie not found in browser. This may cause "Invalid form submission" errors.');
                console.log('Debug info:', {
                    cookieName: sessionCookieName,
                    allCookies: document.cookie || '(no cookies)',
                    protocol: window.location.protocol,
                    port: port,
                    isSecure: isSecure
                });
                
                // If on HTTP, this is likely a Secure cookie mismatch issue
                if (!isSecure) {
                    console.warn('POSSIBLE CAUSE: Running on HTTP but server may have set Secure cookie flag.');
                    console.warn('SOLUTION: Check server logs for "CRITICAL: HTTP server but Secure cookie flag is TRUE"');
                }
            } else {
                console.log('Session cookie found - session should persist correctly');
            }
        })();
        
        // Enhanced Login Form Functionality
        // Wait for DOM to be fully loaded before accessing elements
        document.addEventListener('DOMContentLoaded', function() {
            'use strict';
            
            const loginForm = document.getElementById('loginForm');
            const emailField = document.getElementById('email');
            const passwordField = document.getElementById('password');
            const togglePasswordBtn = document.getElementById('togglePassword');
            const loginBtn = document.getElementById('loginBtn');
            
            // Enhanced password visibility toggle
            if (togglePasswordBtn && passwordField) {
                togglePasswordBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    const icon = this.querySelector('i');
                    if (!icon) return; // Safety check: icon element must exist
                    
                    const isPassword = passwordField.type === 'password';
                    
                    passwordField.type = isPassword ? 'text' : 'password';
                    if (icon && icon.classList) {
                        icon.classList.toggle('fa-eye', !isPassword);
                        icon.classList.toggle('fa-eye-slash', isPassword);
                    }
                    this.setAttribute('title', isPassword ? 'Hide Password' : 'Show Password');
                    this.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
                    this.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                    
                });
            }
            
            // Real-time email validation
            if (emailField) {
                const emailInputGroup = emailField.closest('.input-group');
                
                emailField.addEventListener('input', function() {
                    const email = this.value.trim();
                    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    
                    if (email && !emailPattern.test(email)) {
                        this.setCustomValidity('Please enter a valid email address');
                        if (this.classList) {
                            this.classList.add('is-invalid');
                            this.classList.remove('is-valid');
                        }
                        if (emailInputGroup && emailInputGroup.classList) {
                            emailInputGroup.classList.add('is-invalid');
                            emailInputGroup.classList.remove('is-valid');
                        }
                    } else {
                        this.setCustomValidity('');
                        if (this.classList) {
                            this.classList.remove('is-invalid');
                        }
                        if (emailInputGroup && emailInputGroup.classList) {
                            emailInputGroup.classList.remove('is-invalid');
                        }
                        if (email) {
                            if (this.classList) {
                                this.classList.add('is-valid');
                            }
                            if (emailInputGroup && emailInputGroup.classList) {
                                emailInputGroup.classList.add('is-valid');
                            }
                        } else {
                            if (this.classList) {
                                this.classList.remove('is-valid');
                            }
                            if (emailInputGroup && emailInputGroup.classList) {
                                emailInputGroup.classList.remove('is-valid');
                            }
                        }
                    }
                });
                
                emailField.addEventListener('blur', function() {
                    if (this.value.trim() && this.classList) {
                        this.classList.add('was-validated');
                    }
                });
            }
            
            // Password strength indicator (optional enhancement)
            if (passwordField) {
                passwordField.addEventListener('input', function() {
                    const password = this.value;
                    if (password.length > 0 && this.classList) {
                        this.classList.add('was-validated');
                    }
                });
            }
            
            // Enhanced form submission with validation
            if (loginForm) {
                loginForm.addEventListener('submit', function(e) {
                    // Basic HTML5 validation
                    if (!this.checkValidity()) {
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // Focus first invalid field
                        const firstInvalid = this.querySelector(':invalid');
                        if (firstInvalid) {
                            firstInvalid.focus();
                            if (firstInvalid.scrollIntoView) {
                                firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            }
                        }
                        
                        if (this.classList) {
                            this.classList.add('was-validated');
                        }
                        return false;
                    }
                    
                    // Disable button and show loading state
                    if (loginBtn) {
                        loginBtn.disabled = true;
                        loginBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Signing in...';
                    }
                    
                    // Add loading class to form
                    if (this.classList) {
                        this.classList.add('is-submitting');
                    }
                });
            }
            
            // Reset button state on page load/back
            window.addEventListener('pageshow', function(event) {
                if (loginBtn) {
                    loginBtn.disabled = false;
                    loginBtn.innerHTML = 'Sign In';
                }
                
                if (loginForm && loginForm.classList) {
                    loginForm.classList.remove('is-submitting', 'was-validated');
                }
            });
            
            // Auto-focus email field on load (if empty)
            if (emailField && !emailField.value) {
                setTimeout(() => {
                    if (emailField.focus) {
                        emailField.focus();
                    }
                }, 300);
            }
            
            // Keyboard shortcuts
            document.addEventListener('keydown', function(e) {
                // Enter key on password field submits form
                if (e.key === 'Enter' && document.activeElement === passwordField) {
                    if (loginForm && loginForm.checkValidity) {
                        loginForm.requestSubmit();
                    }
                }
            });
            
            // Add smooth scroll to error alerts (only if they exist)
            const alerts = document.querySelectorAll('.alert:not(.alert-info)');
            if (alerts.length > 0 && alerts[0] && alerts[0].scrollIntoView) {
                setTimeout(() => {
                    alerts[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 100);
            }
            
            // Show Station Login link only in PWA (standalone mode)
            function checkPWAStandalone() {
                const stationLoginLink = document.getElementById('pwa-station-login-link');
                if (!stationLoginLink) return;
                
                // Check if running in standalone mode (PWA)
                const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
                                    window.navigator.standalone === true ||
                                    window.matchMedia('(display-mode: fullscreen)').matches ||
                                    window.matchMedia('(display-mode: minimal-ui)').matches;
                
                // Also check if no browser chrome is visible (heuristic)
                const isLikelyPWA = window.innerHeight === screen.height && 
                                   window.innerWidth === screen.width;
                
                if (isStandalone || isLikelyPWA) {
                    stationLoginLink.style.display = 'block';
                    console.log('[Login] PWA mode detected - showing station login link');
                } else {
                    stationLoginLink.style.display = 'none';
                }
            }
            
            // Check on page load
            checkPWAStandalone();
            
            // Also check when display mode changes
            if (window.matchMedia) {
                const mediaQuery = window.matchMedia('(display-mode: standalone)');
                if (mediaQuery && mediaQuery.addEventListener) {
                    mediaQuery.addEventListener('change', checkPWAStandalone);
                }
            }
            
        });
    </script>
    <style>
        /* Validation states (login page) */
        .input-group .form-control.is-valid { background: transparent; }
        .input-group.is-valid { border-color: #059669; }
        .input-group .form-control.is-invalid { background: transparent; }
        .input-group.is-invalid { border-color: #dc2626; }
        .was-validated .form-control:invalid { border-color: #dc2626; }
        .was-validated .form-control:valid { border-color: #059669; }
    </style>
    <!-- Performance optimization -->
    <script src="<?php echo asset_url('js/performance.js', true); ?>" defer></script>
    <!-- PWA Scripts -->
    <?php include_once 'includes/pwa-script.php'; ?>
    <script src="<?php echo asset_url('js/pwa-install-prompt.js', true); ?>" defer></script>
    <script src="<?php echo asset_url('js/pwa-app-behavior.js', true); ?>" defer></script>
    <script>
        // Clear cached pages after logout to prevent serving stale authenticated pages
        (function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('logout') === '1') {
                // Clear service worker cache for dashboard pages
                if ('caches' in window) {
                    caches.keys().then(function(names) {
                        names.forEach(function(name) {
                            caches.open(name).then(function(cache) {
                                cache.keys().then(function(requests) {
                                    requests.forEach(function(request) {
                                        const requestUrl = request.url;
                                        // Delete cached dashboard and authenticated pages
                                        if (requestUrl.includes('/dashboard.php') ||
                                            requestUrl.includes('/faculty/') ||
                                            requestUrl.includes('/admin/') ||
                                            requestUrl.includes('/pds.php') ||
                                            requestUrl.includes('/profile.php')) {
                                            cache.delete(request);
                                        }
                                    });
                                });
                            });
                        });
                    });
                }
                
                // Clear sessionStorage
                sessionStorage.clear();
            }
        })();
    </script>
</body>
</html>
