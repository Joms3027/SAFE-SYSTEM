<?php
/**
 * Front Controller / Router
 * This file handles all incoming requests and routes them to appropriate PHP files
 * Allows clean URLs without showing file extensions or paths
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get the requested path from URL
$request_uri = $_SERVER['REQUEST_URI'];
$script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

// Parse the URL and get the path
$parsed_url = parse_url($request_uri);
$path = $parsed_url['path'] ?? '/';

// Remove the script directory from path if present
if ($script_dir !== '' && strpos($path, $script_dir) === 0) {
    $path = substr($path, strlen($script_dir));
}

// Clean up the path
$path = trim($path, '/');

// If there's a query string, preserve it
$query_string = $parsed_url['query'] ?? '';
if ($query_string) {
    parse_str($query_string, $_GET);
}

// Define route mappings (clean URL => actual file)
$routes = [
    '' => 'landing.php',                    // Homepage
    'home' => 'home.php',
    'login' => 'login.php',
    'logout' => 'logout.php',
    'station-login' => 'station_login.php',
    'timekeeper-login' => 'timekeeper_login.php',
    'admin-access' => 'admin_access.php',
    'diagnose' => 'diagnose.php',
    'session-debug' => 'session_debug.php',
    'session-test' => 'session_test.php',
    'manifest' => 'manifest.php',
    'feedback' => 'feedback.php',
    'faculty/feedback' => 'faculty/feedback.php',
    
    // Faculty routes
    'faculty/dashboard' => 'faculty/dashboard.php',
    'faculty/profile' => 'faculty/profile.php',
    'faculty/pds' => 'faculty/pds.php',
    'faculty/pds-edit' => 'faculty/pds_edit.php',
    'faculty/pds-view' => 'faculty/pds_view.php',
    'faculty/attendance' => 'faculty/attendance.php',
    'faculty/calendar' => 'faculty/calendar.php',
    'faculty/requirements' => 'faculty/requirements.php',
    'faculty/submit-requirement' => 'faculty/submit_requirement.php',
    'faculty/payslip' => 'faculty/payslip.php',
    'faculty/leave-application' => 'faculty/leave_application.php',
    'faculty/pardon-request' => 'faculty/pardon_request.php',
    'faculty/change-password' => 'faculty/change_password.php',
    'faculty/dtr-submissions' => 'faculty/dtr_submissions.php',
    'faculty/dtr_submissions' => 'faculty/dtr_submissions.php',
    
    // Admin routes
    'admin/dashboard' => 'admin/dashboard.php',
    'admin/faculty' => 'admin/faculty.php',
    'admin/attendance' => 'admin/attendance.php',
    'admin/calendar' => 'admin/calendar.php',
    'admin/requirements' => 'admin/requirements.php',
    'admin/payroll' => 'admin/payroll.php',
    'admin/reports' => 'admin/reports.php',
    'admin/settings' => 'admin/settings.php',
    
    // Timekeeper routes
    'timekeeper/dashboard' => 'timekeeper/dashboard.php',
    'timekeeper/qrcode-scanner' => 'timekeeper/qrcode-scanner.php',
    'timekeeper/verify-password' => 'timekeeper/verify-password.php',
];

// Function to serve a file
function serveFile($file) {
    if (file_exists($file)) {
        // Set proper content type
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if ($ext === 'php') {
            include $file;
        } else {
            readfile($file);
        }
        exit;
    }
    return false;
}

// Check if route exists in our mapping
if (isset($routes[$path])) {
    $file = $routes[$path];
    if (serveFile($file)) {
        exit;
    }
}

// Try direct file access for unmapped routes
// This handles existing .php files and directories automatically
$possible_files = [
    $path . '.php',           // Try adding .php extension
    $path . '/index.php',     // Try directory with index.php
    $path . '/dashboard.php', // Try directory with dashboard.php
    $path,                    // Try exact path (for static files)
];

foreach ($possible_files as $file) {
    if (serveFile($file)) {
        exit;
    }
}

// Check if it's a static asset (CSS, JS, images, etc.)
$static_extensions = ['css', 'js', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot', 'mp3', 'mp4', 'pdf', 'zip'];
$ext = pathinfo($path, PATHINFO_EXTENSION);

if (in_array($ext, $static_extensions)) {
    if (file_exists($path)) {
        // Set appropriate content type
        $mime_types = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            'eot' => 'application/vnd.ms-fontobject',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
        ];
        
        if (isset($mime_types[$ext])) {
            header('Content-Type: ' . $mime_types[$ext]);
        }
        readfile($path);
        exit;
    }
}

// If nothing found, show 404 error
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found | WPU Safe System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #003366 0%, #0066cc 100%);
            color: white;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 2rem;
        }

        .error-container {
            text-align: center;
            max-width: 600px;
            background: rgba(255, 255, 255, 0.1);
            padding: 3rem;
            border-radius: 15px;
            backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .error-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        h1 {
            font-size: 6rem;
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        h2 {
            font-size: 1.8rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }

        p {
            font-size: 1.1rem;
            margin-bottom: 2rem;
            opacity: 0.8;
            line-height: 1.6;
        }

        .btn {
            display: inline-block;
            padding: 0.9rem 2rem;
            background: white;
            color: #003366;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            transition: all 0.3s ease;
            margin: 0.5rem;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(255, 255, 255, 0.3);
        }

        .btn-secondary {
            background: transparent;
            border: 2px solid white;
            color: white;
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .requested-path {
            background: rgba(0, 0, 0, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 5px;
            font-family: monospace;
            margin: 1rem 0;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <h1>404</h1>
        <h2>Page Not Found</h2>
        <p>The page you're looking for doesn't exist or has been moved.</p>
        <div class="requested-path">
            <strong>Requested:</strong> /<?php echo htmlspecialchars($path); ?>
        </div>
        <div>
            <?php
$basePath = (function() {
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])) : '';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($scriptDir !== '' && $scriptDir !== '/') return rtrim($scriptDir, '/');
    return '';
})();
?>
            <a href="<?php echo htmlspecialchars($basePath); ?>/" class="btn">
                <i class="fas fa-home"></i> Return to Home
            </a>
            <a href="<?php echo htmlspecialchars($basePath); ?>/login" class="btn btn-secondary">
                <i class="fas fa-sign-in-alt"></i> Login
            </a>
        </div>
    </div>
</body>
</html>
