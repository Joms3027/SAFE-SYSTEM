<?php
/**
 * Notifications API Endpoint
 * Handles AJAX requests for notification operations
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/session_optimization.php';

// Ensure session is active before accessing $_SESSION
// config.php should have already started the session, but ensure it's active
if (session_status() === PHP_SESSION_NONE) {
    // If session wasn't started by config.php, start it now
    // This ensures session cookie is read properly
    @session_start();
}

// Refresh session activity to prevent timeout
if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['user_id'])) {
    $_SESSION['last_activity'] = time();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Badge polling: avoid 401 for sessions without notifications (stations, kiosk, edge cases).
// Same behavior as chat_api get_unread_count — removes noisy failures in Network tab/console.
if ($action === 'get_count') {
    $utype = $_SESSION['user_type'] ?? '';
    if (!isLoggedIn()
        || empty($_SESSION['user_id'])
        || $utype === 'station'
        || $utype === 'timekeeper') {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode([
            'success' => true,
            'count' => 0,
            'lastId' => null,
            'latestTitle' => null,
        ]);
        exit;
    }
}

// Set headers before any output
if (!headers_sent()) {
    header('Content-Type: application/json');
    // Allow CORS for same origin (needed for fetch with credentials)
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin && (parse_url($origin, PHP_URL_HOST) === $host || strpos($origin, $host) !== false)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    }
}

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    // Include debug info to help diagnose session issues
    $sessionCookieName = session_name();
    $sessionCookieReceived = isset($_COOKIE[$sessionCookieName]);
    $sessionCookieValue = $sessionCookieReceived ? substr($_COOKIE[$sessionCookieName], 0, 16) . '...' : 'not set';
    $currentSessionId = session_id();
    $sessionIdMatch = $sessionCookieReceived && $currentSessionId && ($_COOKIE[$sessionCookieName] === $currentSessionId);
    
    $debugInfo = [
        'session_status' => session_status() === PHP_SESSION_ACTIVE ? 'active' : 'not_active',
        'session_id_exists' => !empty($currentSessionId),
        'session_id' => $currentSessionId ? substr($currentSessionId, 0, 16) . '...' : 'none',
        'user_id_set' => isset($_SESSION['user_id']),
        'cookies_received' => !empty($_COOKIE),
        'session_cookie_name' => $sessionCookieName,
        'session_cookie_received' => $sessionCookieReceived,
        'session_cookie_value' => $sessionCookieValue,
        'session_ids_match' => $sessionIdMatch,
        'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
    ];
    
    // Only log detailed debug info in development or if explicitly enabled
    // In production, log minimal info to avoid log spam
    $isDevelopment = defined('ENVIRONMENT') && ENVIRONMENT === 'development';
    if ($isDevelopment || getenv('DEBUG_SESSIONS') === '1') {
        error_log("Notifications API 401: " . json_encode($debugInfo));
    } else {
        // Minimal logging in production
        error_log("Notifications API 401: Session not authenticated. Cookie received: " . ($sessionCookieReceived ? 'yes' : 'no') . ", User ID set: " . (isset($_SESSION['user_id']) ? 'yes' : 'no'));
    }
    
    // Don't include debug info in production response (security)
    $response = [
        'success' => false, 
        'message' => 'Unauthorized. Please refresh the page and try again.'
    ];
    
    // Only include debug info in development
    if ($isDevelopment || getenv('DEBUG_SESSIONS') === '1') {
        $response['debug'] = $debugInfo;
    }
    
    echo json_encode($response);
    exit;
}

$userId = $_SESSION['user_id'];
$notificationManager = getNotificationManager();

// Close session early for read-only operations to prevent blocking
// Most notification API calls are read-only (get_unread, get_all, mark_read)
// Only keep session open for write operations (mark_all_read, delete)
if (in_array($action, ['get_unread', 'get_all', 'get_count'])) {
    closeSessionEarly(true);
}

switch ($action) {
    case 'get_unread':
        // Debug logging
        error_log("=== NOTIFICATIONS API DEBUG ===");
        error_log("User ID: " . $userId);
        error_log("Action: get_unread");
        
        $notifications = $notificationManager->getUnreadNotifications($userId);
        $count = $notificationManager->getUnreadCount($userId);
        
        error_log("Notifications count from DB: " . count($notifications));
        error_log("Unread count: " . $count);
        if (!empty($notifications)) {
            error_log("First notification: " . json_encode($notifications[0]));
        } else {
            error_log("Notifications array is empty!");
        }
        
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'count' => $count,
            'debug' => [
                'user_id' => $userId,
                'fetched_count' => count($notifications),
                'unread_count' => $count
            ]
        ]);
        break;
        
    case 'get_all':
        $limit = $_GET['limit'] ?? 50;
        $notifications = $notificationManager->getAllNotifications($userId, $limit);
        echo json_encode([
            'success' => true,
            'notifications' => $notifications
        ]);
        break;
        
    case 'get_count':
        $count = $notificationManager->getUnreadCount($userId);
        $lastId = $_GET['last_id'] ?? 0;
        
        // Get the latest notification title for toast display
        $latestTitle = null;
        $latestNotificationId = null;
        
        try {
            // Check if is_hidden column exists
            $checkColumn = $notificationManager->db->query("SHOW COLUMNS FROM notifications LIKE 'is_hidden'");
            $columnExists = $checkColumn->rowCount() > 0;
            
            if ($columnExists) {
                $stmt = $notificationManager->db->prepare("
                    SELECT id, title FROM notifications 
                    WHERE user_id = ? AND is_read = 0 AND is_hidden = 0
                    ORDER BY created_at DESC 
                    LIMIT 1
                ");
            } else {
                $stmt = $notificationManager->db->prepare("
                    SELECT id, title FROM notifications 
                    WHERE user_id = ? AND is_read = 0
                    ORDER BY created_at DESC 
                    LIMIT 1
                ");
            }
            $stmt->execute([$userId]);
            $latest = $stmt->fetch();
            if ($latest) {
                $latestNotificationId = $latest['id'];
                // Only send title if this is a NEW notification (ID greater than last known)
                if ($latestNotificationId > $lastId) {
                    $latestTitle = $latest['title'];
                }
            }
        } catch (Exception $e) {
            // Ignore errors
        }
        
        echo json_encode([
            'success' => true,
            'count' => $count,
            'lastId' => $latestNotificationId,
            'latestTitle' => $latestTitle
        ]);
        break;
        
    case 'mark_read':
        $notificationId = $_POST['notification_id'] ?? null;
        if (!$notificationId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Notification ID required']);
            exit;
        }
        
        $result = $notificationManager->markAsRead($notificationId, $userId);
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'Marked as read' : 'Failed to mark as read'
        ]);
        break;
        
    case 'mark_all_read':
        $result = $notificationManager->markAllAsRead($userId);
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'All notifications marked as read' : 'Failed to mark all as read'
        ]);
        break;
        
    case 'delete':
        $notificationId = $_POST['notification_id'] ?? null;
        if (!$notificationId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Notification ID required']);
            exit;
        }
        
        error_log("API delete called: notificationId=$notificationId, userId=$userId");
        $result = $notificationManager->deleteNotification($notificationId, $userId);
        
        // Verify the notification was actually hidden/deleted
        if ($result) {
            try {
                $checkColumn = $notificationManager->db->query("SHOW COLUMNS FROM notifications LIKE 'is_hidden'");
                $columnExists = $checkColumn->rowCount() > 0;
                
                if ($columnExists) {
                    $verifyStmt = $notificationManager->db->prepare("SELECT is_hidden FROM notifications WHERE id = ? AND user_id = ?");
                    $verifyStmt->execute([$notificationId, $userId]);
                    $verify = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                    if ($verify && $verify['is_hidden'] == 1) {
                        error_log("Verified: Notification $notificationId is now hidden");
                    } else {
                        error_log("Warning: Notification $notificationId was not found or not hidden");
                    }
                }
            } catch (Exception $e) {
                error_log("Verification error: " . $e->getMessage());
            }
        }
        
        echo json_encode([
            'success' => $result,
            'message' => $result ? 'Notification removed successfully' : 'Failed to remove notification',
            'notification_id' => $notificationId
        ]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?>
