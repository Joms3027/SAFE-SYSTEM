<?php
/**
 * Session Refresh API for Timekeeper
 * Keeps the timekeeper session alive by refreshing it periodically
 */

require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/database.php';

header('Content-Type: application/json');

// Only allow timekeeper or station sessions
$isTimekeeper = isset($_SESSION['timekeeper_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'timekeeper';
$isStation = isset($_SESSION['station_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'station';

if (!$isTimekeeper && !$isStation) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

// Refresh session by updating last activity time
$_SESSION['last_activity'] = time();

// Regenerate session ID periodically for security (every 10 minutes)
if (!isset($_SESSION['session_regenerated_at']) || 
    (time() - $_SESSION['session_regenerated_at']) > 600) {
    session_regenerate_id(true);
    $_SESSION['session_regenerated_at'] = time();
}

echo json_encode([
    'success' => true,
    'message' => 'Session refreshed',
    'timestamp' => time()
]);

