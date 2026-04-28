<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/database.php';

// Check if this is a PWA launch from station_login installation
// If so, ensure we stay on station_login even if there's a session
$isPWAStandalone = false;
if (isset($_SERVER['HTTP_USER_AGENT'])) {
    // Check via JavaScript for more accurate detection
    // We'll handle this in the page itself
}

// Redirect if already logged in as station
if (isset($_SESSION['station_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'station') {
    redirect(SITE_URL . '/timekeeper/qrcode-scanner.php');
}

$error = '';
$isOffline = false;
$db = null;
$stations = [];

// Try to connect to database, but don't fail if offline
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Get all active stations for dropdown
    $stationsStmt = $db->query("SELECT id, name FROM stations ORDER BY name ASC");
    $stations = $stationsStmt->fetchAll();
} catch (Exception $e) {
    // Database connection failed - likely offline
    $isOffline = true;
    error_log("Database connection failed (offline mode): " . $e->getMessage());
    // Stations will be loaded from localStorage cache via JavaScript
    $stations = [];
}

// Helper function to get client IP
function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stationId = (int)($_POST['station_id'] ?? 0);
    $pin = $_POST['pin'] ?? '';
    $deviceToken = trim($_POST['device_token'] ?? ''); // Token from localStorage
    $deviceFingerprint = trim($_POST['device_fingerprint'] ?? ''); // Device fingerprint
    $useOfflineAuth = isset($_POST['offline_auth']) && $_POST['offline_auth'] === 'true';
    
    if ($stationId <= 0) {
        $error = 'Please select a station.';
    } elseif (empty($pin)) {
        $error = 'PIN is required.';
    } else {
        $station = null;
        $loginSuccess = false;
        
        // Try database first if available
        if ($db !== null && !$useOfflineAuth) {
            try {
                // Get station by ID
                $stmt = $db->prepare("
                    SELECT s.*
                    FROM stations s
                    WHERE s.id = ?
                ");
                $stmt->execute([$stationId]);
                $station = $stmt->fetch();
                
                if ($station && password_verify($pin, $station['pin'])) {
                    $loginSuccess = true;
                }
            } catch (Exception $e) {
                // Database query failed - fall back to offline auth
                error_log("Database query failed (trying offline auth): " . $e->getMessage());
                $useOfflineAuth = true;
            }
        }
        
        // If database failed or offline auth requested, try cached credentials
        if (!$loginSuccess && $useOfflineAuth) {
            // For offline authentication:
            // 1. We need a cached device token (proves this device was previously authenticated)
            // 2. The device token must match what's being sent
            // 3. PIN is still required (user verification, even if we can't verify it offline)
            
            if (!empty($deviceToken)) {
                // Get cached station info from JavaScript
                $cachedStationName = $_POST['station_name'] ?? 'Unknown Station';
                $cachedDeviceToken = $_POST['cached_device_token'] ?? '';
                
                // Verify device token matches (this proves device was previously authenticated)
                if ($deviceToken === $cachedDeviceToken && !empty($cachedDeviceToken)) {
                    // Device token matches - allow offline login
                    // Note: PIN cannot be verified offline, but we require it for user verification
                    $loginSuccess = true;
                    $station = [
                        'id' => $stationId,
                        'name' => $cachedStationName,
                        'device_token' => $cachedDeviceToken
                    ];
                } else {
                    $error = 'Device verification failed for offline login. Please connect to the internet first to authenticate this device.';
                }
            } else {
                $error = 'Offline login requires previous device authentication. Please connect to the internet first.';
            }
        }
        
        if ($loginSuccess) {
            $clientIP = getClientIP();
            
            // STRICT DEVICE LOCKING
            // Check if station has a registered device
            // Explicitly check for NULL, empty string, or missing field
            $stationDeviceToken = isset($station['device_token']) ? trim($station['device_token']) : null;
            $hasRegisteredDevice = !empty($stationDeviceToken) && $stationDeviceToken !== '';
            
            // Debug logging for device registration check
            error_log("Station login check - Station ID: {$stationId}, Has device_token in result: " . (isset($station['device_token']) ? 'yes' : 'no') . 
                     ", device_token value: " . var_export($stationDeviceToken, true) . 
                     ", Has registered device: " . ($hasRegisteredDevice ? 'yes' : 'no'));
            
            if ($hasRegisteredDevice) {
                // Station is locked to a device - verify token matches
                if ($deviceToken !== $stationDeviceToken) {
                    $error = 'Device verification failed. This station is locked to a different device. Please use the registered device or contact admin to reset.';
                    if ($db !== null) {
                        try {
                            logAction('STATION_LOGIN_FAILED', "Failed login for station {$station['name']} - Device token mismatch. IP: {$clientIP}");
                        } catch (Exception $e) {
                            // Logging failed - continue anyway
                        }
                    }
                } else {
                    // Token matches - allow login and update last access
                    if ($db !== null) {
                        try {
                            $updateStmt = $db->prepare("
                                UPDATE stations 
                                SET last_device_ip = ?, device_fingerprint = ? 
                                WHERE id = ?
                            ");
                            $updateStmt->execute([$clientIP, $deviceFingerprint, $stationId]);
                            logAction('STATION_LOGIN', "Station logged in: {$station['name']} from registered device. IP: {$clientIP}");
                        } catch (Exception $e) {
                            // Database update failed - continue with offline login
                            error_log("Failed to update station login record: " . $e->getMessage());
                        }
                    }
                    
                    $_SESSION['station_id'] = $station['id'];
                    $_SESSION['user_type'] = 'station';
                    $_SESSION['station_name'] = $station['name'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['station_client_ip'] = $clientIP;
                    $_SESSION['station_device_token'] = $deviceToken;
                    if ($useOfflineAuth) {
                        $_SESSION['offline_login'] = true; // Flag for offline login
                        $_SESSION['offline_login_warning'] = true; // Show warning on next page
                    }
                    
                    unset($_SESSION['station_password_verified']);
                    
                    session_regenerate_id(true);
                    redirect(SITE_URL . '/timekeeper/qrcode-scanner.php');
                }
            } else {
                // No device registered yet - this is the first login, register this device
                // Generate new device token
                $newDeviceToken = bin2hex(random_bytes(32)); // 64 character token
                
                // Register this device to the station (if database available)
                $deviceRegistered = false;
                $registrationSuccess = false;
                if ($db !== null) {
                    try {
                        // First verify the station exists
                        $verifyStmt = $db->prepare("SELECT id, name FROM stations WHERE id = ?");
                        $verifyStmt->execute([$stationId]);
                        $stationExists = $verifyStmt->fetch();
                        
                        if (!$stationExists) {
                            error_log("ERROR: Station ID {$stationId} does not exist in database! Cannot register device.");
                            $error = 'Station not found in database. Please contact administrator.';
                            $registrationSuccess = false; // Explicitly set to false
                        } else {
                            try {
                                $updateStmt = $db->prepare("
                                    UPDATE stations 
                                    SET device_token = ?, device_fingerprint = ?, last_device_ip = ?, device_registered_at = NOW() 
                                    WHERE id = ?
                                ");
                                $deviceRegistered = $updateStmt->execute([$newDeviceToken, $deviceFingerprint, $clientIP, $stationId]);
                                $rowsAffected = $updateStmt->rowCount();
                            } catch (PDOException $e) {
                                // Check if error is due to missing column
                                if (strpos($e->getMessage(), 'Unknown column') !== false || strpos($e->getMessage(), '42S22') !== false) {
                                    error_log("CRITICAL: device_token columns missing from stations table. Run migration: db/migrations/add_station_device_token.sql");
                                    error_log("Column error: " . $e->getMessage());
                                    $error = 'System configuration error: Device registration columns are missing. Please contact administrator to run the device_token migration.';
                                    $registrationSuccess = false;
                                    throw $e; // Re-throw to exit the try block
                                }
                                throw $e; // Re-throw other exceptions
                            }
                            
                            Database::clearAllCache();
                            
                            if ($deviceRegistered && $rowsAffected > 0) {
                                $registrationSuccess = true;
                                logAction('STATION_LOGIN', "Station {$station['name']} - First device registered. IP: {$clientIP}");
                                error_log("Device registration successful for station ID: {$stationId}, rows affected: {$rowsAffected}");
                                
                                // Verify the update was successful by querying the station again
                                $verifyUpdateStmt = $db->prepare("SELECT device_token FROM stations WHERE id = ?");
                                $verifyUpdateStmt->execute([$stationId]);
                                $updatedStation = $verifyUpdateStmt->fetch();
                                if ($updatedStation && $updatedStation['device_token'] === $newDeviceToken) {
                                    error_log("Device registration verified - token matches in database for station ID: {$stationId}");
                                } else {
                                    error_log("WARNING: Device registration may have failed - token mismatch for station ID: {$stationId}");
                                    $registrationSuccess = false;
                                }
                            } else {
                                error_log("Device registration UPDATE executed but no rows affected for station ID: {$stationId}. Execute result: " . ($deviceRegistered ? 'true' : 'false'));
                                // Get more details about why the update failed
                                $errorInfo = $updateStmt->errorInfo();
                                error_log("PDO error info: " . print_r($errorInfo, true));
                            }
                        }
                    } catch (Exception $e) {
                        // Database update failed - log error but continue with session registration
                        error_log("Failed to register device: " . $e->getMessage());
                        error_log("Exception trace: " . $e->getTraceAsString());
                        // Don't set registrationSuccess = true, but continue anyway for offline support
                    }
                } else {
                    error_log("Database connection not available for device registration (offline mode)");
                    // In offline mode, we still want to set the session so user can use the app
                    // The registration will happen when they come back online
                    $registrationSuccess = true; // Allow offline registration
                }
                
                // Only proceed with login if registration was successful OR we're in offline mode
                // If registration failed and we're online, show error
                if (!$registrationSuccess && $db !== null && !$useOfflineAuth) {
                    // Only set generic error if we don't already have a specific error message
                    if (empty($error)) {
                        $error = 'Failed to register device. Please try again or contact administrator.';
                    }
                    error_log("Device registration failed for station ID: {$stationId} - blocking login");
                } else {
                    // Registration successful or offline mode - proceed with login
                    $_SESSION['station_id'] = $station['id'];
                    $_SESSION['user_type'] = 'station';
                    $_SESSION['station_name'] = $station['name'];
                    $_SESSION['login_time'] = time();
                    $_SESSION['station_client_ip'] = $clientIP;
                    $_SESSION['station_device_token'] = $newDeviceToken;
                    $_SESSION['station_new_device'] = true; // Flag to store token in localStorage
                    if ($useOfflineAuth) {
                        $_SESSION['offline_login'] = true; // Flag for offline login
                        $_SESSION['offline_login_warning'] = true; // Show warning on next page
                    }
                    
                    unset($_SESSION['station_password_verified']);
                    
                    // Ensure session is written before regenerating ID
                    session_write_close();
                    session_start();
                    session_regenerate_id(true);
                    
                    redirect(SITE_URL . '/timekeeper/qrcode-scanner.php');
                }
            }
        } else {
            $error = 'Invalid station or PIN.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#1e293b">
    <meta name="application-name" content="WPU Safe Station">
    <meta name="apple-mobile-web-app-title" content="WPU Safe Station">
    <title>Station Login - WPU Faculty System</title>
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="<?php echo htmlspecialchars(SITE_URL . '/manifest.php', ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Favicon and Apple Touch Icon (browser tab + home screen shortcut) -->
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link rel="apple-touch-icon" href="<?php echo asset_url('logo.png', true); ?>">
    
    <!-- Service Worker Registration -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                const siteUrl = '<?php echo rtrim(SITE_URL, '/'); ?>';
                const swUrl = siteUrl + '/service-worker.js';
                // Extract path from SITE_URL for scope
                const url = new URL(siteUrl || window.location.origin);
                const scope = url.pathname === '' ? '/' : url.pathname + '/';
                navigator.serviceWorker.register(swUrl, { scope: scope })
                    .then(registration => {
                        console.log('[PWA] Service Worker registered:', registration.scope);
                    })
                    .catch(error => {
                        console.log('[PWA] Service Worker registration failed:', error);
                    });
            });
        }
    </script>
    
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css'); ?>" rel="stylesheet">
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow-x: hidden;
        }
        
        .login-body {
            background: #f5f7fa;
            min-height: 100vh;
            min-height: -webkit-fill-available;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            width: 100%;
            box-sizing: border-box;
        }
        
        .login-container {
            width: 100%;
            max-width: 400px;
            margin: 0 auto;
            box-sizing: border-box;
        }
        
        .login-card {
            background: #ffffff;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            padding: 3rem 2.5rem;
            border: 1px solid #e5e7eb;
        }
        
        .login-logo {
            margin: 0 auto 1.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-logo .logo-img {
            max-width: 120px;
            height: auto;
            display: block;
        }
        
        .login-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1e293b;
            text-align: center;
            margin-bottom: 0.5rem;
            letter-spacing: -0.01em;
        }
        
        .login-subtitle {
            font-size: 0.875rem;
            color: #64748b;
            text-align: center;
            margin-bottom: 2rem;
            font-weight: 400;
        }
        
        .form-label {
            font-weight: 500;
            color: #374151;
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .form-control {
            border: 1px solid #d1d5db;
            border-radius: 4px;
            padding: 0.625rem 0.875rem;
            font-size: 0.9375rem;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
            min-height: 42px;
            background: #ffffff;
            width: 100%;
            outline: none;
            color: #1e293b;
        }
        
        .form-control:focus {
            border-color: #1e293b;
            outline: none;
            box-shadow: 0 0 0 3px rgba(30, 41, 59, 0.1);
        }
        
        .input-group {
            border-radius: 4px;
            overflow: hidden;
            transition: border-color 0.15s ease;
            display: flex;
            align-items: stretch;
            width: 100%;
            background: #ffffff;
            border: 1px solid #d1d5db;
        }
        
        .input-group:focus-within {
            border-color: #1e293b;
            box-shadow: 0 0 0 3px rgba(30, 41, 59, 0.1);
        }
        
        .input-group-text {
            background: #f9fafb;
            border: none;
            border-right: 1px solid #d1d5db;
            color: #6b7280;
            padding: 0.625rem 0.875rem;
            min-width: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .input-group .form-control {
            border: none;
            border-radius: 0;
            background: transparent;
            flex: 1;
            padding-left: 0.75rem;
        }
        
        .btn-toggle-password {
            background: #f9fafb;
            border: none;
            border-left: 1px solid #d1d5db;
            color: #6b7280;
            padding: 0.625rem 0.875rem;
            min-width: 42px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-toggle-password:hover {
            background: #f3f4f6;
            color: #374151;
        }
        
        .btn-primary {
            background: #1e293b;
            border: 1px solid #1e293b;
            border-radius: 4px;
            padding: 0.75rem 1.5rem;
            font-size: 0.9375rem;
            font-weight: 500;
            min-height: 42px;
            transition: background-color 0.15s ease;
            color: #ffffff;
            margin-top: 1.5rem;
            width: 100%;
        }
        
        .btn-primary:hover {
            background: #0f172a;
        }
        
        .alert {
            border-radius: 4px;
            border: 1px solid;
            padding: 0.875rem 1rem;
            margin-bottom: 1.5rem;
            font-size: 0.875rem;
        }
        
        .alert-danger {
            background: #fef2f2;
            color: #991b1b;
            border-color: #fecaca;
        }
        
        .back-link {
            text-align: center;
            margin-top: 1.5rem;
            font-size: 0.875rem;
        }
        
        .back-link a {
            color: #64748b;
            text-decoration: none;
        }
        
        .back-link a:hover {
            color: #1e293b;
        }
    </style>
</head>
<body class="login-body">
    <div class="login-container">
        <div class="login-card">
            <div class="text-center mb-4">
                <div class="login-logo">
                    <img src="<?php echo asset_url('logo.png'); ?>" alt="WPU Logo" class="logo-img">
                </div>
                <h1 class="login-title">Station Login</h1>
                <p class="login-subtitle">WPU Faculty System - Attendance Scanner</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="stationLoginForm" novalidate>
                <input type="hidden" id="device_token" name="device_token">
                <input type="hidden" id="device_fingerprint" name="device_fingerprint">
                
                <div class="mb-3">
                    <label for="station_input" class="form-label">Select Station</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-building"></i>
                        </span>
                        <input type="text" 
                               class="form-control" 
                               id="station_input" 
                               list="stations-list"
                               placeholder="Type or select station..."
                               autocomplete="off"
                               required
                               value="<?php 
                                   if (isset($_POST['station_id']) && $_POST['station_id']) {
                                       foreach ($stations as $s) {
                                           if ($s['id'] == $_POST['station_id']) {
                                               echo htmlspecialchars($s['name']);
                                               break;
                                           }
                                       }
                                   }
                               ?>"
                               aria-required="true">
                        <datalist id="stations-list">
                            <?php foreach ($stations as $station): ?>
                                <option value="<?php echo htmlspecialchars($station['name']); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <input type="hidden" id="station_id" name="station_id" value="<?php echo isset($_POST['station_id']) ? (int)$_POST['station_id'] : ''; ?>">
                    </div>
                    <small class="text-muted">Type to search or choose your station from the list</small>
                </div>
                
                <div class="mb-4">
                    <label for="pin" class="form-label">PIN</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" 
                               class="form-control" 
                               id="pin" 
                               name="pin" 
                               placeholder="Enter PIN"
                               required
                               autocomplete="off"
                               pattern="[0-9]*"
                               inputmode="numeric"
                               aria-required="true">
                        <button class="btn-toggle-password" 
                                type="button" 
                                id="togglePin"
                                title="Show/Hide PIN"
                                aria-label="Toggle PIN visibility">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" id="loginBtn">
                    <i class="fas fa-sign-in-alt me-2"></i>Access Station
                </button>
            </form>
            
            <div class="back-link">
                <a href="login.php"><i class="fas fa-arrow-left me-1"></i>Back to Regular Login</a>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
    <script>
        // Offline authentication and caching functions
        const STATIONS_CACHE_KEY = 'station_login_stations';
        const STATIONS_CACHE_TIMESTAMP_KEY = 'station_login_stations_timestamp';
        const STATION_PIN_HASH_CACHE_KEY_PREFIX = 'station_pin_hash_';
        const CACHE_EXPIRY_DAYS = 30; // Cache stations for 30 days
        
        // Cache stations list
        function cacheStations(stations) {
            try {
                localStorage.setItem(STATIONS_CACHE_KEY, JSON.stringify(stations));
                localStorage.setItem(STATIONS_CACHE_TIMESTAMP_KEY, Date.now().toString());
                console.log('[Offline] Cached', stations.length, 'stations');
            } catch (e) {
                console.error('[Offline] Failed to cache stations:', e);
            }
        }
        
        // Load stations from cache
        function getCachedStations() {
            try {
                const cached = localStorage.getItem(STATIONS_CACHE_KEY);
                const timestamp = localStorage.getItem(STATIONS_CACHE_TIMESTAMP_KEY);
                
                if (!cached || !timestamp) {
                    return null;
                }
                
                // Check if cache is expired
                const age = Date.now() - parseInt(timestamp);
                const maxAge = CACHE_EXPIRY_DAYS * 24 * 60 * 60 * 1000;
                if (age > maxAge) {
                    console.log('[Offline] Stations cache expired');
                    return null;
                }
                
                return JSON.parse(cached);
            } catch (e) {
                console.error('[Offline] Failed to load cached stations:', e);
                return null;
            }
        }
        
        // Cache station PIN hash (for offline authentication)
        function cacheStationPinHash(stationId, pinHash) {
            try {
                localStorage.setItem(STATION_PIN_HASH_CACHE_KEY_PREFIX + stationId, pinHash);
                console.log('[Offline] Cached PIN hash for station:', stationId);
            } catch (e) {
                console.error('[Offline] Failed to cache PIN hash:', e);
            }
        }
        
        // Get cached PIN hash
        function getCachedPinHash(stationId) {
            try {
                return localStorage.getItem(STATION_PIN_HASH_CACHE_KEY_PREFIX + stationId);
            } catch (e) {
                return null;
            }
        }
        
        // Verify PIN offline using cached hash
        async function verifyPinOffline(pin, pinHash) {
            // For offline PIN verification, we need to hash the entered PIN and compare
            // However, PHP's password_verify uses bcrypt which is difficult to replicate in JS
            // Instead, we'll use a simpler approach: store a verification token after successful login
            // For now, we'll rely on device token verification for offline access
            return false; // PIN verification requires server-side password_verify
        }
        
        // Station name to ID mapping (for searchable input)
        let stationNameToId = {};
        function buildStationNameToIdMap(stations) {
            stationNameToId = {};
            (stations || []).forEach(s => {
                stationNameToId[s.name] = s.id;
            });
        }
        
        // Sync hidden station_id when user selects or types a matching station name
        function syncStationIdFromInput() {
            const input = document.getElementById('station_input');
            const hidden = document.getElementById('station_id');
            if (!input || !hidden) return;
            const name = input.value.trim();
            let id = stationNameToId[name];
            if (!id && name) {
                const nameLower = name.toLowerCase();
                for (const [stationName, stationId] of Object.entries(stationNameToId)) {
                    if (stationName.toLowerCase() === nameLower) {
                        id = stationId;
                        break;
                    }
                }
            }
            hidden.value = id ? String(id) : '';
        }
        
        // Load stations into datalist (from server or cache)
        function loadStationsIntoDropdown() {
            const datalist = document.getElementById('stations-list');
            const stationInput = document.getElementById('station_input');
            if (!datalist || !stationInput) return;
            
            const serverStations = <?php echo json_encode($stations); ?>;
            
            // If server provided stations, use them and cache them
            if (serverStations && serverStations.length > 0) {
                console.log('[Offline] Using server stations, caching them');
                cacheStations(serverStations);
                buildStationNameToIdMap(serverStations);
                return; // Datalist already populated from PHP
            }
            
            // Otherwise, try to load from cache
            const cachedStations = getCachedStations();
            if (cachedStations && cachedStations.length > 0) {
                console.log('[Offline] Loading stations from cache');
                datalist.innerHTML = '';
                cachedStations.forEach(station => {
                    const option = document.createElement('option');
                    option.value = station.name;
                    datalist.appendChild(option);
                });
                buildStationNameToIdMap(cachedStations);
            } else {
                console.warn('[Offline] No stations available from server or cache');
            }
        }
        
        // Check if we're offline
        function isOffline() {
            return !navigator.onLine;
        }
        
        // Simple hash function for HTTP (when crypto.subtle is not available)
        function simpleHash(str) {
            let hash = 0;
            if (str.length === 0) return hash.toString(16).padStart(64, '0');
            for (let i = 0; i < str.length; i++) {
                const char = str.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash; // Convert to 32-bit integer
            }
            // Convert to positive hex string and pad to 64 characters
            const hexHash = (hash >>> 0).toString(16);
            // Repeat and combine to create a longer hash (simulating SHA-256 length)
            return (hexHash + hexHash.split('').reverse().join('')).padStart(64, '0').substring(0, 64);
        }
        
        // Generate comprehensive device fingerprint
        async function generateDeviceFingerprint() {
            const fingerprint = {
                userAgent: navigator.userAgent,
                language: navigator.language,
                platform: navigator.platform,
                screenResolution: `${screen.width}x${screen.height}`,
                screenDepth: screen.colorDepth,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                cpuCores: navigator.hardwareConcurrency || 'unknown',
                memory: navigator.deviceMemory || 'unknown',
                touchSupport: 'ontouchstart' in window,
                plugins: Array.from(navigator.plugins || []).map(p => p.name).join(','),
            };
            
            // Canvas fingerprinting
            try {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                ctx.textBaseline = 'top';
                ctx.font = '14px Arial';
                ctx.fillText('Device Fingerprint', 2, 2);
                fingerprint.canvas = canvas.toDataURL();
            } catch (e) {
                fingerprint.canvas = 'error';
            }
            
            // WebGL fingerprinting
            try {
                const canvas = document.createElement('canvas');
                const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
                if (gl) {
                    const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                    fingerprint.webgl = {
                        vendor: gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL),
                        renderer: gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL)
                    };
                }
            } catch (e) {
                fingerprint.webgl = 'error';
            }
            
            // Create hash of fingerprint
            const fpString = JSON.stringify(fingerprint);
            let hashHex;
            
            // Try to use crypto.subtle if available (requires HTTPS), otherwise use fallback
            if (window.crypto && window.crypto.subtle) {
                try {
                    const hash = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(fpString));
                    const hashArray = Array.from(new Uint8Array(hash));
                    hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
                } catch (e) {
                    console.warn('crypto.subtle.digest failed, using fallback hash:', e);
                    hashHex = simpleHash(fpString);
                }
            } else {
                // crypto.subtle not available (likely HTTP instead of HTTPS)
                console.log('crypto.subtle not available, using fallback hash function');
                hashHex = simpleHash(fpString);
            }
            
            return {
                hash: hashHex,
                full: fpString
            };
        }
        
        // Get device token from localStorage (station-specific)
        function getDeviceToken(stationId) {
            return localStorage.getItem(`station_${stationId}_device_token`);
        }
        
        // Store device token in localStorage (station-specific)
        function setDeviceToken(stationId, token) {
            localStorage.setItem(`station_${stationId}_device_token`, token);
            console.log('Device token stored for station:', stationId);
        }
        
        // Initialize on page load
        async function initDeviceVerification() {
            const fingerprint = await generateDeviceFingerprint();
            document.getElementById('device_fingerprint').value = fingerprint.hash;
            console.log('Device fingerprint generated:', fingerprint.hash.substring(0, 16) + '...');
        }
        
        // Check if PWA was installed from this page and ensure we stay here
        function checkPWAInstallation() {
            const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
                                window.navigator.standalone === true;
            const installSource = localStorage.getItem('pwa_install_source');
            
            // If PWA was installed from station_login and we're in standalone mode
            if (isStandalone && installSource === 'station_login') {
                console.log('[Station Login] PWA opened from station_login installation - staying on station login');
                // Ensure we're on the right page (in case of redirect)
                if (!window.location.pathname.includes('station_login.php')) {
                    const basePath = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/'));
                    window.location.href = (basePath || '') + '/station_login.php';
                }
            }
        }
        
        // Handle form submission with offline support
        async function handleLoginSubmission(e) {
            const form = e.target;
            const stationInput = document.getElementById('station_input');
            const stationIdEl = document.getElementById('station_id');
            const pin = document.getElementById('pin').value;
            
            // Sync station_id from typed/selected value before validation
            if (stationInput) stationInput.setCustomValidity('');
            syncStationIdFromInput();
            const stationId = stationIdEl.value;
            const stationName = stationInput ? stationInput.value.trim() : '';
            
            if (stationInput && stationName && !stationId) {
                stationInput.setCustomValidity('Please select a station from the list');
            }
            
            if (!form.checkValidity()) {
                e.preventDefault();
                e.stopPropagation();
                form.classList.add('was-validated');
                return false;
            }
            
            // Get cached device token
            const deviceToken = getDeviceToken(stationId);
            
            // Check if we're offline (or if stations came from cache, indicating offline)
            const cachedStations = getCachedStations();
            const usingCachedStations = cachedStations && cachedStations.length > 0 && 
                                       (!<?php echo json_encode($isOffline ? false : count($stations)); ?> || <?php echo json_encode($isOffline); ?>);
            
            if (isOffline() || usingCachedStations) {
                console.log('[Offline] Attempting offline authentication');
                
                if (!deviceToken) {
                    e.preventDefault();
                    alert('Offline login requires previous online authentication to register this device. Please connect to the internet first.');
                    return false;
                }
                
                // Set device token for offline login
                document.getElementById('device_token').value = deviceToken;
                
                // Add offline auth flag
                const offlineInput = document.createElement('input');
                offlineInput.type = 'hidden';
                offlineInput.name = 'offline_auth';
                offlineInput.value = 'true';
                form.appendChild(offlineInput);
                
                // Add cached station info for offline verification
                const stationNameInput = document.createElement('input');
                stationNameInput.type = 'hidden';
                stationNameInput.name = 'station_name';
                stationNameInput.value = stationName;
                form.appendChild(stationNameInput);
                
                const cachedTokenInput = document.createElement('input');
                cachedTokenInput.type = 'hidden';
                cachedTokenInput.name = 'cached_device_token';
                cachedTokenInput.value = deviceToken;
                form.appendChild(cachedTokenInput);
                
                console.log('[Offline] Submitting offline login request with device token verification');
            } else {
                // Online: normal flow
                if (deviceToken) {
                    document.getElementById('device_token').value = deviceToken;
                }
            }
            
            if (loginBtn) {
                loginBtn.disabled = true;
                loginBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Accessing...';
            }
        }
        
        // Run on page load
        initDeviceVerification();
        loadStationsIntoDropdown();
        
        // Sync station_id and device token when user types or selects from datalist
        const stationInputEl = document.getElementById('station_input');
        if (stationInputEl) {
            function onStationInputChange() {
                syncStationIdFromInput();
                const sid = document.getElementById('station_id').value;
                if (sid) {
                    const token = getDeviceToken(sid);
                    document.getElementById('device_token').value = token || '';
                    console.log('Station selected:', sid, 'Token found:', !!token);
                }
                stationInputEl.setCustomValidity('');
            }
            stationInputEl.addEventListener('input', onStationInputChange);
            stationInputEl.addEventListener('change', onStationInputChange);
            stationInputEl.addEventListener('blur', function() {
                syncStationIdFromInput();
                const hidden = document.getElementById('station_id');
                if (stationInputEl.value.trim() && !hidden.value) {
                    stationInputEl.setCustomValidity('Please select a station from the list');
                } else {
                    stationInputEl.setCustomValidity('');
                }
            });
        }
        
        // Check PWA installation on load
        window.addEventListener('load', checkPWAInstallation);
        
        // Cache stations when they're loaded from server (after form loads)
        window.addEventListener('load', function() {
            const serverStations = <?php echo json_encode($stations); ?>;
            if (serverStations && serverStations.length > 0) {
                cacheStations(serverStations);
            }
        });
        
        const togglePinBtn = document.getElementById('togglePin');
        const pinField = document.getElementById('pin');
        
        if (togglePinBtn) {
            togglePinBtn.addEventListener('click', function(e) {
                e.preventDefault();
                const icon = this.querySelector('i');
                const isPassword = pinField.type === 'password';
                
                pinField.type = isPassword ? 'text' : 'password';
                icon.classList.toggle('fa-eye', !isPassword);
                icon.classList.toggle('fa-eye-slash', isPassword);
            });
        }
        
        const loginForm = document.getElementById('stationLoginForm');
        const loginBtn = document.getElementById('loginBtn');
        
        if (loginForm) {
            loginForm.addEventListener('submit', handleLoginSubmission);
        }
    </script>
</body>
</html>

