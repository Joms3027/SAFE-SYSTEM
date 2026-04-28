<?php
/**
 * PWA Script Include
 * Include this file before </body> tag for service worker registration
 */

// Use SITE_URL from config if available
if (defined('SITE_URL')) {
    $siteUrl = SITE_URL;
} else {
    // Fallback
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (function_exists('getBasePath')) {
        $basePath = getBasePath();
    } else {
        $basePath = dirname(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($basePath === '/' || $basePath === '\\') $basePath = '';
    }
    $siteUrl = $protocol . '://' . $host . $basePath;
}

$siteUrl = rtrim($siteUrl, '/');
?>
<!-- Service Worker Registration for PWA -->
<script>
(function() {
    'use strict';
    
    // CRITICAL: Use window.location.origin for service worker registration
    // This ensures it always matches the actual origin (works with port forwarding, dev tunnels, etc.)
    // Service workers MUST be registered from the same origin as the page
    
    // Get the actual origin from the browser (not from PHP SITE_URL)
    const ACTUAL_ORIGIN = window.location.origin;
    
    // Get base path from PHP (e.g., '/SAFE_SYSTEM/FP' or '')
    const BASE_PATH = '<?php 
        if (function_exists("getBasePath")) {
            $path = getBasePath();
            // Normalize: ensure path starts with / and doesn't end with /
            $path = $path ? "/" . trim($path, "/") : "";
            echo addslashes($path);
        } else {
            // Fallback: calculate from current script location
            $scriptPath = dirname($_SERVER["SCRIPT_NAME"] ?? "");
            if (strpos($scriptPath, "/faculty") !== false || strpos($scriptPath, "/admin") !== false) {
                $scriptPath = dirname($scriptPath);
            }
            $path = $scriptPath ? "/" . trim($scriptPath, "/") : "";
            echo addslashes($path);
        }
    ?>';
    
    // Build service worker URL and scope using actual origin + base path
    // Normalize base path: remove trailing slash if present (except for root)
    let normalizedBasePath = BASE_PATH;
    if (normalizedBasePath === '/') {
        normalizedBasePath = ''; // Root installation - no base path
    } else if (normalizedBasePath.endsWith('/')) {
        normalizedBasePath = normalizedBasePath.slice(0, -1);
    }
    
    const SW_PATH = normalizedBasePath + '/service-worker.js';
    const SW_SCOPE = normalizedBasePath + '/';
    
    // Use actual origin to ensure it matches the page origin
    const swUrl = ACTUAL_ORIGIN + SW_PATH;
    const swScope = ACTUAL_ORIGIN + SW_SCOPE;
    
    console.log('[PWA] Actual Origin:', ACTUAL_ORIGIN);
    console.log('[PWA] Base Path:', BASE_PATH);
    console.log('[PWA] Service Worker URL:', swUrl);
    console.log('[PWA] Service Worker Scope:', swScope);
    
    // Register Service Worker
    if ('serviceWorker' in navigator) {
        // Register immediately (don't wait for load) to ensure it's active before homescreen launch
        navigator.serviceWorker.register(swUrl, { scope: swScope })
            .then(function(registration) {
                console.log('[PWA] ✅ Service Worker registered successfully');
                console.log('[PWA] Registration Scope:', registration.scope);
                console.log('[PWA] Active:', registration.active ? 'Yes' : 'No');
                console.log('[PWA] Installing:', registration.installing ? 'Yes' : 'No');
                console.log('[PWA] Waiting:', registration.waiting ? 'Yes' : 'No');
                
                // CRITICAL: If service worker is waiting, skip waiting to activate immediately
                // This ensures the SW is active when launched from homescreen
                if (registration.waiting) {
                    console.log('[PWA] Service worker waiting - sending skip waiting message');
                    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                }
                
                // If service worker is installing, wait for it to activate
                if (registration.installing) {
                    const installingWorker = registration.installing;
                    installingWorker.addEventListener('statechange', function(event) {
                        // Use event.target instead of registration.installing to avoid null reference
                        // registration.installing may become null after state change
                        const worker = event.target || installingWorker;
                        if (worker && worker.state === 'installed') {
                            if (navigator.serviceWorker.controller) {
                                console.log('[PWA] Service worker updated and controlling');
                            } else {
                                console.log('[PWA] Service worker installed - reload to activate');
                                // Force activation by reloading if not controlling
                                if (!navigator.serviceWorker.controller) {
                                    registration.waiting.postMessage({ type: 'SKIP_WAITING' });
                                }
                            }
                        }
                    });
                }
                
                // Wait for service worker to be ready and controlling
                navigator.serviceWorker.ready.then(function(registration) {
                    console.log('[PWA] Service Worker ready and controlling:', !!navigator.serviceWorker.controller);
                    
                    // If not controlling yet, reload to activate
                    if (!navigator.serviceWorker.controller && registration.active) {
                        console.log('[PWA] Service worker active but not controlling - will activate on next load');
                    }
                });
                
                // Check for updates periodically
                setInterval(function() {
                    registration.update();
                }, 60 * 60 * 1000); // Every hour
            })
            .catch(function(error) {
                console.error('[PWA] ❌ Service Worker registration failed:', error);
                console.error('[PWA] Error details:', {
                    message: error.message,
                    swUrl: swUrl,
                    swScope: swScope,
                    actualOrigin: ACTUAL_ORIGIN,
                    currentUrl: window.location.href
                });
            });
    } else {
        console.log('[PWA] Service Workers not supported in this browser');
    }
    
    // Check if running as installed PWA
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
                        window.navigator.standalone === true;
    
    if (isStandalone) {
        console.log('[PWA] ✅ Running as INSTALLED APP');
        document.documentElement.classList.add('pwa-standalone');
        if (document.body && document.body.classList) {
            document.body.classList.add('pwa-standalone');
        }
    } else {
        console.log('[PWA] Running in browser - Install available');
    }
    
    // Listen for display mode changes
    window.matchMedia('(display-mode: standalone)').addEventListener('change', function(e) {
        if (e.matches) {
            console.log('[PWA] Switched to standalone mode');
        }
    });
})();
</script>
