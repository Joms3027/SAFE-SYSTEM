<?php
/**
 * PWA Meta Tags Include
 * Include this file in the <head> section of your pages
 * Enables native app experience on Android and iOS
 */

// Use SITE_URL from config if available (should be loaded by page already)
if (defined('SITE_URL')) {
    $baseUrl = SITE_URL;
} else {
    // Fallback: Calculate base URL
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $protocol = 'http';
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $protocol = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $protocol = $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ? 'https' : 'http';
    }
    
    // Get base path using getBasePath() if available
    if (function_exists('getBasePath')) {
        $basePath = getBasePath();
    } else {
        $basePath = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($basePath === '/' || $basePath === '\\') $basePath = '';
    }
    
    $baseUrl = $protocol . '://' . $host . $basePath;
}

$baseUrl = rtrim($baseUrl, '/');
?>
<!-- ===== PWA META TAGS - NATIVE ANDROID APP EXPERIENCE ===== -->

<!-- Theme Color - Status bar color on Android -->
<meta name="theme-color" content="#003366">

<!-- Android PWA - Critical for native app behavior -->
<meta name="mobile-web-app-capable" content="yes">
<meta name="application-name" content="WPU Safe">

<!-- iOS Safari PWA Support -->
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="WPU Safe">

<!-- Viewport - Critical for fullscreen app -->
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover, minimal-ui">

<!-- Favicon and app icons - use logo.png for all (browser tab + home screen shortcut) -->
<link rel="icon" type="image/png" href="<?php echo $baseUrl; ?>/assets/logo.png">

<!-- Microsoft/Windows (tile when pinning to Start) -->
<meta name="msapplication-TileColor" content="#003366">
<meta name="msapplication-TileImage" content="<?php echo $baseUrl; ?>/assets/logo.png">

<!-- Apple Touch Icons (home screen shortcut on iOS) -->
<link rel="apple-touch-icon" href="<?php echo $baseUrl; ?>/assets/logo.png">
<link rel="apple-touch-icon" sizes="180x180" href="<?php echo $baseUrl; ?>/assets/logo.png">
<link rel="apple-touch-icon" sizes="152x152" href="<?php echo $baseUrl; ?>/assets/logo.png">
<link rel="apple-touch-icon" sizes="144x144" href="<?php echo $baseUrl; ?>/assets/logo.png">

<!-- iOS Splash Screens -->
<link rel="apple-touch-startup-image" href="<?php echo $baseUrl; ?>/assets/logo.png">

<!-- PWA Manifest - Use relative path to avoid ad blockers and port forwarding issues -->
<?php 
    // Calculate manifest path - use relative path for browser compatibility
    // This avoids ad blockers and works with any origin (localhost, dev tunnels, etc.)
    $manifestBasePath = '';
    
    // Method 1: Calculate from SCRIPT_NAME (most reliable for determining relative path)
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    if ($scriptName) {
        $scriptDir = str_replace('\\', '/', dirname($scriptName));
        
        // If we're in a subdirectory (admin, faculty, timekeeper), calculate relative path back to root
        if (preg_match('#/(admin|faculty|timekeeper)(?:/|$)#', $scriptDir)) {
            // We're in a subdirectory, need to go up
            $manifestBasePath = '../';
        } else {
            // We're at the root level
            $manifestBasePath = './';
        }
    } else {
        // Fallback to root-relative path
        $manifestBasePath = './';
    }
    
    // Build manifest path - always use relative path from current location
    $manifestPath = $manifestBasePath . 'manifest.php';
    
    // Validate the path doesn't contain protocol (safety check)
    if (preg_match('#^https?://#', $manifestPath)) {
        // Something went wrong, use safe fallback
        $manifestPath = './manifest.php';
    }
?>
<link rel="manifest" href="<?php echo htmlspecialchars($manifestPath, ENT_QUOTES, 'UTF-8'); ?>">

<!-- PWA Styles for Standalone Mode -->
<style>
/* When running as installed PWA (standalone mode) */
@media all and (display-mode: standalone),
       all and (display-mode: fullscreen),
       all and (display-mode: minimal-ui) {
    html, body {
        height: 100%;
        height: 100dvh;
        height: 100vh;
        margin: 0;
        padding: 0;
        overflow-x: hidden;
        overscroll-behavior-x: none; /* Only prevent horizontal overscroll */
        -webkit-overflow-scrolling: touch;
    }
    
    /* Allow vertical scrolling for forms and content - don't use position:fixed on body */
    body {
        width: 100%;
        min-height: 100vh;
        min-height: 100dvh;
        /* NOTE: Removed position:fixed which was breaking form scrolling on mobile */
    }
    
    /* Safe area for notches */
    .navbar, .admin-navbar, header {
        padding-top: max(0.5rem, env(safe-area-inset-top, 0px)) !important;
    }
    
    .bottom-nav, footer {
        padding-bottom: max(0.5rem, env(safe-area-inset-bottom, 0px)) !important;
    }
}

/* Force standalone appearance even if browser doesn't detect it */
.standalone-mode html,
.standalone-mode body {
    height: 100% !important;
    height: 100dvh !important;
    height: 100vh !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow-x: hidden !important;
    overscroll-behavior-x: none !important; /* Only prevent horizontal overscroll - allow vertical for forms */
}
</style>

<!-- Script to detect and apply standalone mode -->
<script>
(function() {
    'use strict';
    
    // Detect if running in standalone mode
    function isStandalone() {
        // Check display mode
        if (window.matchMedia('(display-mode: standalone)').matches) {
            return true;
        }
        if (window.matchMedia('(display-mode: fullscreen)').matches) {
            return true;
        }
        if (window.matchMedia('(display-mode: minimal-ui)').matches) {
            return true;
        }
        
        // Check for iOS standalone mode
        if (window.navigator.standalone === true) {
            return true;
        }
        
        // Check if launched from homescreen (Android)
        if (window.matchMedia('(display-mode: standalone)').matches) {
            return true;
        }
        
        // Check URL parameter
        if (window.location.search.includes('pwa=1')) {
            return true;
        }
        
        // Check if no browser chrome is visible (heuristic)
        if (window.innerHeight === screen.height && window.innerWidth === screen.width) {
            return true;
        }
        
        return false;
    }
    
    // Apply standalone class if detected
    if (isStandalone()) {
        document.documentElement.classList.add('standalone-mode');
        if (document.body && document.body.classList) {
            document.body.classList.add('standalone-mode');
        }
        
        // Hide install prompt if it exists
        if (window.deferredPrompt) {
            window.deferredPrompt = null;
        }
        
        console.log('[PWA] Running in standalone mode');
    } else {
        console.log('[PWA] Running in browser mode');
    }
    
    // Listen for display mode changes
    if (window.matchMedia) {
        const mediaQuery = window.matchMedia('(display-mode: standalone)');
        mediaQuery.addEventListener('change', function(e) {
            if (e.matches) {
                document.documentElement.classList.add('standalone-mode');
                if (document.body && document.body.classList) {
                    document.body.classList.add('standalone-mode');
                }
            }
        });
    }
})();
</script>
