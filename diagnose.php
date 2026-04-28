<?php
/**
 * Diagnostic Script for HTTP 500 Error
 * This script helps identify the cause of the 500 error
 * 
 * IMPORTANT: Delete this file after fixing the issue for security!
 */

// Enable error display for diagnostics
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

echo "<h1>WPU Safe System - Diagnostic Report</h1>";
echo "<style>body{font-family:Arial,sans-serif;max-width:800px;margin:20px auto;padding:20px;} .success{color:green;} .error{color:red;} .warning{color:orange;} pre{background:#f5f5f5;padding:10px;border-radius:5px;overflow-x:auto;}</style>";

// 1. Check PHP Version
echo "<h2>1. PHP Version</h2>";
echo "<p class='success'>PHP Version: " . PHP_VERSION . "</p>";

// 2. Check Required Extensions
echo "<h2>2. Required PHP Extensions</h2>";
$required = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'session'];
foreach ($required as $ext) {
    if (extension_loaded($ext)) {
        echo "<p class='success'>✓ $ext is loaded</p>";
    } else {
        echo "<p class='error'>✗ $ext is NOT loaded</p>";
    }
}

// 3. Check File Permissions
echo "<h2>3. File Permissions</h2>";
$dirs = [
    'storage/sessions' => 'Session storage',
    'storage/logs' => 'Log storage',
    'storage/cache' => 'Cache storage',
    'uploads' => 'Upload directory'
];

foreach ($dirs as $dir => $name) {
    $path = __DIR__ . '/' . $dir;
    if (!is_dir($path)) {
        echo "<p class='warning'>⚠ $name directory doesn't exist: $dir</p>";
        // Create parent directories if needed
        $parentDir = dirname($path);
        if (!is_dir($parentDir)) {
            @mkdir($parentDir, 0755, true);
        }
        if (@mkdir($path, 0755, true)) {
            echo "<p class='success'>✓ Created $name directory</p>";
            // Create .htaccess for sessions directory
            if ($dir === 'storage/sessions') {
                $htaccess = $path . '/.htaccess';
                if (!file_exists($htaccess)) {
                    file_put_contents($htaccess, "# Protect session files\nOrder Deny,Allow\nDeny from all\nOptions -Indexes\n");
                }
            }
        } else {
            echo "<p class='error'>✗ Failed to create $name directory</p>";
            echo "<p class='warning'>Check file permissions for: " . dirname($path) . "</p>";
        }
    } else {
        if (is_writable($path)) {
            echo "<p class='success'>✓ $name is writable</p>";
        } else {
            echo "<p class='error'>✗ $name is NOT writable</p>";
            echo "<p class='warning'>Try: chmod 755 " . escapeshellarg($path) . "</p>";
        }
    }
}

// 4. Check Configuration File
echo "<h2>4. Configuration Files</h2>";
$configPath = __DIR__ . '/includes/config.php';
if (file_exists($configPath)) {
    echo "<p class='success'>✓ config.php exists</p>";
    require_once $configPath;
    
    // Check if constants are defined
    if (defined('DB_HOST')) {
        echo "<p class='success'>✓ DB_HOST is defined: " . DB_HOST . "</p>";
    } else {
        echo "<p class='error'>✗ DB_HOST is NOT defined</p>";
    }
    
    if (defined('DB_NAME')) {
        echo "<p class='success'>✓ DB_NAME is defined: " . DB_NAME . "</p>";
    } else {
        echo "<p class='error'>✗ DB_NAME is NOT defined</p>";
    }
    
    if (defined('DB_USER')) {
        echo "<p class='success'>✓ DB_USER is defined: " . DB_USER . "</p>";
    } else {
        echo "<p class='error'>✗ DB_USER is NOT defined</p>";
    }
    
    // Don't show password, just check if it's defined
    if (defined('DB_PASS')) {
        echo "<p class='success'>✓ DB_PASS is defined (hidden for security)</p>";
    } else {
        echo "<p class='error'>✗ DB_PASS is NOT defined</p>";
    }
} else {
    echo "<p class='error'>✗ config.php does NOT exist at: $configPath</p>";
}

// 5. Test Database Connection
echo "<h2>5. Database Connection Test</h2>";
if (defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER') && defined('DB_PASS')) {
    $conn = null;
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        echo "<p>Attempting to connect to database...</p>";
        echo "<pre>Host: " . DB_HOST . "\nDatabase: " . DB_NAME . "\nUser: " . DB_USER . "</pre>";

        $conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        echo "<p class='success'>✓ Database connection successful!</p>";

        // Test a simple query
        $stmt = $conn->query("SELECT 1 as test");
        $result = $stmt->fetch();
        if ($result) {
            echo "<p class='success'>✓ Database query test successful</p>";
        }

    } catch (PDOException $e) {
        echo "<p class='error'>✗ Database connection FAILED</p>";
        echo "<pre>Error: " . htmlspecialchars($e->getMessage()) . "</pre>";
        
        // Common issues and solutions
        echo "<h3>Common Issues & Solutions:</h3>";
        echo "<ul>";
        
        if (strpos($e->getMessage(), 'Access denied') !== false) {
            echo "<li><strong>Access Denied:</strong> Check your database username and password in config.php</li>";
        }
        
        if (strpos($e->getMessage(), "Unknown database") !== false) {
            echo "<li><strong>Unknown Database:</strong> The database '" . DB_NAME . "' doesn't exist. Create it in your hosting control panel.</li>";
        }
        
        if (strpos($e->getMessage(), "Connection refused") !== false || strpos($e->getMessage(), "No connection") !== false) {
            echo "<li><strong>Connection Refused:</strong> The database host might be wrong. On InfinityFree, the host is usually NOT 'localhost'. Check your hosting control panel for the correct database host (usually something like 'sqlXXX.infinityfreeapp.com').</li>";
        }
        
        if (strpos($e->getMessage(), "getaddrinfo failed") !== false) {
            echo "<li><strong>Hostname Resolution Failed:</strong> The database host '" . DB_HOST . "' cannot be resolved. Check if the hostname is correct.</li>";
        }
        
        echo "</ul>";
    } finally {
        $conn = null;
    }
} else {
    echo "<p class='error'>✗ Cannot test database connection - configuration constants are missing</p>";
}

// 6. Check Required Files
echo "<h2>6. Required Include Files</h2>";
$requiredFiles = [
    'includes/config.php' => 'Configuration',
    'includes/database.php' => 'Database class',
    'includes/functions.php' => 'Functions',
    'includes/auth.php' => 'Authentication',
    'includes/security.php' => 'Security functions'
];

foreach ($requiredFiles as $file => $name) {
    $path = __DIR__ . '/' . $file;
    if (file_exists($path)) {
        echo "<p class='success'>✓ $name exists</p>";
    } else {
        echo "<p class='error'>✗ $name does NOT exist: $file</p>";
    }
}

// 7. Check Session Configuration
echo "<h2>7. Session Configuration</h2>";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "<p class='success'>✓ Session started</p>";
echo "<pre>Session ID: " . session_id() . "\nSession Save Path: " . session_save_path() . "\nSession Name: " . session_name() . "</pre>";

// 8. Server Information
echo "<h2>8. Server Information</h2>";
echo "<pre>";
echo "Server Software: " . ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown') . "\n";
echo "Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'Unknown') . "\n";
echo "Script Path: " . __FILE__ . "\n";
echo "HTTP Host: " . ($_SERVER['HTTP_HOST'] ?? 'Unknown') . "\n";
echo "Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'Unknown') . "\n";
echo "HTTPS: " . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'Yes' : 'No') . "\n";
echo "</pre>";

// 9. Test Loading login.php dependencies
echo "<h2>9. Testing login.php Dependencies</h2>";
try {
    require_once __DIR__ . '/includes/config.php';
    echo "<p class='success'>✓ config.php loaded successfully</p>";
    
    require_once __DIR__ . '/includes/functions.php';
    echo "<p class='success'>✓ functions.php loaded successfully</p>";
    
    require_once __DIR__ . '/includes/database.php';
    echo "<p class='success'>✓ database.php loaded successfully</p>";
    
    require_once __DIR__ . '/includes/auth.php';
    echo "<p class='success'>✓ auth.php loaded successfully</p>";
    
    echo "<p class='success'>✓ All dependencies loaded successfully!</p>";
    
} catch (Exception $e) {
    echo "<p class='error'>✗ Error loading dependencies</p>";
    echo "<pre>Error: " . htmlspecialchars($e->getMessage()) . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "</pre>";
}

echo "<hr>";
echo "<p><strong>Next Steps:</strong></p>";
echo "<ol>";
echo "<li>If database connection failed, update DB_HOST, DB_NAME, DB_USER, and DB_PASS in includes/config.php</li>";
echo "<li>On InfinityFree, the database host is usually NOT 'localhost' - check your hosting control panel</li>";
echo "<li>Make sure the database exists and the user has proper permissions</li>";
echo "<li>After fixing the issue, DELETE this diagnose.php file for security</li>";
echo "</ol>";

echo "<p style='color:red;'><strong>⚠️ SECURITY WARNING: Delete this file after fixing the issue!</strong></p>";
?>

