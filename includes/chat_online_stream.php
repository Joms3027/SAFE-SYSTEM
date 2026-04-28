<?php
/**
 * Server-Sent Events stream for real-time online admin count.
 * Faculty/staff connect to get instant updates when admins log in/out.
 */
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Only faculty and staff can subscribe
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['faculty', 'staff'])) {
    http_response_code(403);
    header('Content-Type: text/event-stream'); // Prevent IIS from returning HTML error page
    echo "data: " . json_encode(['error' => 'unauthorized']) . "\n\n";
    exit;
}

// Close session so we don't block other requests
session_write_close();

// Allow long-running connection
set_time_limit(0);

// SSE headers
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable nginx buffering
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) {
    ob_end_flush();
}
ob_implicit_flush(true);

// Send initial comment to establish connection
echo ": connected\n\n";
flush();

$lastCount = -1;
$lastKeepalive = time();
$onlineThreshold = 300; // 5 minutes

try {
    $pdo = Database::getInstance()->getConnection();
    
    while (!connection_aborted()) {
        $threshold = time() - $onlineThreshold;
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS cnt FROM user_activity ua
            INNER JOIN users u ON u.id = ua.user_id
            WHERE u.user_type IN ('admin', 'super_admin') AND u.is_active = 1 AND ua.last_activity >= ?
        ");
        $stmt->execute([$threshold]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $count = (int)($row['cnt'] ?? 0);
        
        if ($count !== $lastCount) {
            $lastCount = $count;
            echo 'data: ' . json_encode(['count' => $count]) . "\n\n";
            flush();
        }
        
        // Keepalive every 30 sec to prevent proxy/timeout closure
        if (time() - $lastKeepalive >= 30) {
            echo ": keepalive\n\n";
            flush();
            $lastKeepalive = time();
        }
        
        sleep(1); // Check every second for real-time feel
    }
} catch (Exception $e) {
    // Send error and exit
    echo 'data: ' . json_encode(['count' => 0, 'error' => true]) . "\n\n";
    flush();
}
