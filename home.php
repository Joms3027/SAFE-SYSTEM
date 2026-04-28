<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';

// CRITICAL: Use absolute paths for all redirects to fix PWA navigation on mobile
$basePath = getBasePath();

// Prevent redirect loops by checking if we're in a loop
$currentUrl = $_SERVER['REQUEST_URI'] ?? '';

// Allow forcing login page even if logged in (useful for port forwarding/dev tunnels)
// Use: home.php?force_login=1
if (isset($_GET['force_login']) && $_GET['force_login'] == '1') {
    unset($_SESSION['index_redirect_count']);
    header("Location: " . clean_url($basePath . "/login.php", $basePath));
    exit();
}

// Reset redirect counter if we're accessing login page directly
if (strpos($currentUrl, 'login.php') !== false) {
    unset($_SESSION['index_redirect_count']);
    header("Location: " . clean_url($basePath . "/login.php", $basePath));
    exit();
}

// If we detect a potential loop (coming from the same page repeatedly), show error
if (isset($_SESSION['index_redirect_count'])) {
    $_SESSION['index_redirect_count']++;
    if ($_SESSION['index_redirect_count'] > 3) {
        // Clear session and show error
        session_destroy();
        session_start();
        unset($_SESSION['index_redirect_count']);
        die('<h1>Redirect Loop Detected</h1><p>Your session has been cleared. <a href="login.php">Click here to login</a></p>');
    }
} else {
    $_SESSION['index_redirect_count'] = 1;
}

// Only redirect if session is complete and valid
// NOTE: When accessing via port forwarding/dev tunnels, if you have a valid session cookie
// from localhost, you'll be redirected to dashboard. To force login, use ?force_login=1
if (isLoggedIn() && isset($_SESSION['user_type']) && !empty($_SESSION['user_type'])) {
    $validUserTypes = ['super_admin', 'admin', 'faculty', 'staff', 'timekeeper', 'station'];
    if (in_array($_SESSION['user_type'], $validUserTypes)) {
        // Reset redirect counter on successful redirect
        unset($_SESSION['index_redirect_count']);
        
        if (isAdmin()) {
            header("Location: " . clean_url($basePath . "/admin/dashboard.php", $basePath));
            exit();
        } else {
            // Faculty and Staff both go to faculty dashboard
            header("Location: " . clean_url($basePath . "/faculty/dashboard.php", $basePath));
            exit();
        }
    } else {
        // Invalid user_type - clear session and redirect to login
        session_destroy();
        session_start();
        unset($_SESSION['index_redirect_count']);
        header("Location: " . clean_url($basePath . "/login.php", $basePath));
        exit();
    }
} else {
    // Not logged in or incomplete session - check if PWA was installed from station_login
    // Check for PWA installation source via JavaScript (client-side check)
    // For server-side, we'll redirect to login by default, but client-side JS will handle station redirect
    
    // Check if this is a PWA launch (standalone mode) and redirect accordingly
    // We'll use a client-side redirect for better control
    unset($_SESSION['index_redirect_count']);
    
    // Output HTML that checks localStorage and redirects if needed
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Loading...</title>
        <script>
            (function() {
                // Check if PWA was installed from station_login.php
                const installSource = localStorage.getItem('pwa_install_source');
                const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
                                    window.navigator.standalone === true;
                
                // If installed from station_login and in standalone mode, redirect to station_login
                if (isStandalone && installSource === 'station_login') {
                    const basePath = '<?php echo $basePath; ?>';
                    window.location.href = basePath + '/station_login';
                    return;
                }
                
                // If installed from qrcode-scanner, redirect there (but only if logged in as station)
                if (isStandalone && installSource === 'qrcode_scanner') {
                    // Check if user is logged in as station via session
                    // If not logged in, go to station_login first
                    const basePath = '<?php echo $basePath; ?>';
                    window.location.href = basePath + '/station_login';
                    return;
                }
                
                // Default: redirect to regular login
                const basePath = '<?php echo $basePath; ?>';
                window.location.href = basePath + '/login';
            })();
        </script>
    </head>
    <body>
        <p>Redirecting...</p>
    </body>
    </html>
    <?php
    exit();
}
?>

