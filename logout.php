<?php
// Session already started in config.php - no need to start again
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/auth.php';

// Get user type before logout (needed for redirect)
$userType = $_SESSION['user_type'] ?? '';
// CRITICAL: Use SITE_URL (absolute URL) instead of getBasePath() for JavaScript redirects
// This ensures the browser can resolve the URL correctly and prevents ERR_NAME_NOT_RESOLVED errors
$baseUrl = defined('SITE_URL') ? SITE_URL : '';
$basePath = function_exists('getBasePath') ? getBasePath() : '';

// Determine login path (use clean URLs without .php extension)
if ($userType === 'timekeeper' || $userType === 'station') {
    if ($baseUrl) {
        // For absolute URLs, remove .php from the path
        $loginPath = rtrim($baseUrl, '/') . '/station_login';
    } else {
        $loginPath = clean_url('/station_login.php', $basePath);
    }
} else {
    if ($baseUrl) {
        // For absolute URLs, remove .php from the path
        $loginPath = rtrim($baseUrl, '/') . '/login';
    } else {
        $loginPath = clean_url('/login.php', $basePath);
    }
}

// Perform logout (clears session but doesn't redirect yet)
$auth = new Auth();
$auth->logout(false); // Don't redirect - we'll handle it after clearing cache

// After logout, show page that clears client-side cache
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging out...</title>
    <script>
        // Clear all client-side storage and service worker cache before redirect
        (function() {
            try {
                // Clear localStorage (except device tokens for stations)
                const keysToKeep = [];
                const allKeys = [];
                for (let i = 0; i < localStorage.length; i++) {
                    const key = localStorage.key(i);
                    if (key) {
                        allKeys.push(key);
                        // Keep station device tokens
                        if (key.startsWith('station_') && key.endsWith('_device_token')) {
                            keysToKeep.push({key: key, value: localStorage.getItem(key)});
                        }
                    }
                }
                
                // Clear all localStorage
                localStorage.clear();
                
                // Restore keys to keep
                keysToKeep.forEach(item => {
                    if (item.value) {
                        localStorage.setItem(item.key, item.value);
                    }
                });
                
                // Clear sessionStorage completely
                sessionStorage.clear();
                
                // Clear service worker cache
                if ('serviceWorker' in navigator) {
                    // Send message to clear caches
                    navigator.serviceWorker.getRegistrations().then(function(registrations) {
                        registrations.forEach(function(registration) {
                            if (registration.active) {
                                registration.active.postMessage({ 
                                    type: 'CLEAR_ALL_CACHES' 
                                });
                            }
                        });
                        
                        // Unregister service worker to force fresh start
                        registrations.forEach(function(registration) {
                            registration.unregister();
                        });
                    });
                }
                
                // Clear all caches directly
                if ('caches' in window) {
                    caches.keys().then(function(names) {
                        return Promise.all(
                            names.map(function(name) {
                                return caches.delete(name);
                            })
                        );
                    });
                }
            } catch (e) {
                console.error('Error clearing cache:', e);
            }
            
            // Redirect to login page with cache-busting parameter
            const loginPath = '<?php echo htmlspecialchars($loginPath, ENT_QUOTES); ?>';
            window.location.href = loginPath + '?logout=1&nocache=' + Date.now();
        })();
    </script>
</head>
<body>
    <p>Logging out...</p>
</body>
</html>
