<?php
// Disable error display to prevent HTML in JSON response
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/session_optimization.php';

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

// Ensure session is active before accessing $_SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get action early to handle get_unread_count gracefully for unauthenticated users
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// For get_unread_count and passive conversation list polls, succeed with empty data instead of 401
if ($action === 'get_unread_count' && !isset($_SESSION['user_type'])) {
    echo json_encode(['success' => true, 'unread_count' => 0]);
    exit;
}
if ($action === 'get_conversations' && !isset($_SESSION['user_type'])) {
    echo json_encode(['success' => true, 'conversations' => []]);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_type'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Station sessions don't have chat access - return empty/success for read-only polls
if ($_SESSION['user_type'] === 'station' || $_SESSION['user_type'] === 'timekeeper') {
    if ($action === 'get_unread_count') {
        echo json_encode(['success' => true, 'unread_count' => 0]);
        exit;
    }
    if ($action === 'get_conversations') {
        echo json_encode(['success' => true, 'conversations' => []]);
        exit;
    }
    // For other actions, return unauthorized
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chat is not available for stations']);
    exit;
}

// Check user_id exists for chat-enabled users
if (!isset($_SESSION['user_id'])) {
    if ($action === 'get_unread_count') {
        echo json_encode(['success' => true, 'unread_count' => 0]);
        exit;
    }
    if ($action === 'get_conversations') {
        echo json_encode(['success' => true, 'conversations' => []]);
        exit;
    }
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Restrict chat to only faculty, staff, admin and super_admin users
if (!in_array($_SESSION['user_type'], ['admin', 'super_admin', 'faculty', 'staff'])) {
    echo json_encode(['success' => false, 'message' => 'Chat is only available for faculty, staff and admin users']);
    exit;
}

// Get database connection
try {
    $database = Database::getInstance();
    $pdo = $database->getConnection();
    
    // Check if chat_messages table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'chat_messages'");
    if ($stmt->rowCount() === 0) {
        echo json_encode([
            'success' => false, 
            'message' => 'Chat system not initialized. Please contact the administrator.'
        ]);
        exit;
    }
} catch (Exception $e) {
    error_log('Chat API: Database error - ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
// Chat DB stores admin/super_admin as 'admin' (enum doesn't have super_admin)
$db_user_type = ($user_type === 'super_admin') ? 'admin' : $user_type;

// Get faculty_id for faculty users
$sender_id = $user_id;
if ($user_type === 'faculty') {
    $stmt = $pdo->prepare("SELECT id FROM faculty_profiles WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $faculty = $stmt->fetch();
    if ($faculty) {
        $sender_id = $faculty['id'];
    }
}

// Close session early for read-only operations to prevent session file blocking
// This is critical for preventing user logouts when many users access chat simultaneously
$readOnlyActions = ['get_messages', 'get_conversations', 'get_unread_count', 'get_admin_list', 'get_staff_list', 'get_faculty_list', 'get_online_admin_count'];
if (in_array($action, $readOnlyActions)) {
    closeSessionEarly(true);
}

try {
    switch ($action) {
        case 'send_message':
            $message = trim($_POST['message'] ?? '');
            $receiver_id = intval($_POST['receiver_id'] ?? 0);
            $receiver_type = $_POST['receiver_type'] ?? '';
            
            if (empty($message)) {
                echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
                exit;
            }
            
            // Only allow known receiver types (faculty, staff, admin)
            if (!in_array($receiver_type, ['faculty', 'admin', 'staff'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid receiver type. Only faculty, staff and admin can use chat.']);
                exit;
            }

            // Only allow faculty, staff, admin and super_admin to send messages
            if (!in_array($user_type, ['faculty', 'admin', 'super_admin', 'staff'])) {
                echo json_encode(['success' => false, 'message' => 'Only faculty, staff and admin can send messages.']);
                exit;
            }

            // Faculty/staff can message admins, or (if pardon opener) their assigned employees
            if (in_array($user_type, ['faculty', 'staff']) && in_array($receiver_type, ['faculty', 'staff'])) {
                $receiverEmployeeId = null;
                if ($receiver_type === 'faculty') {
                    $stmtEmp = $pdo->prepare("SELECT employee_id FROM faculty_profiles WHERE id = ? LIMIT 1");
                    $stmtEmp->execute([$receiver_id]);
                    $row = $stmtEmp->fetch(PDO::FETCH_ASSOC);
                    $receiverEmployeeId = $row['employee_id'] ?? null;
                } else {
                    $stmtEmp = $pdo->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
                    $stmtEmp->execute([$receiver_id]);
                    $row = $stmtEmp->fetch(PDO::FETCH_ASSOC);
                    $receiverEmployeeId = $row['employee_id'] ?? null;
                }
                $senderUserId = $user_id;
                if ($user_type === 'faculty') {
                    $stmtFac = $pdo->prepare("SELECT user_id FROM faculty_profiles WHERE id = ? LIMIT 1");
                    $stmtFac->execute([$sender_id]);
                    $rowFac = $stmtFac->fetch(PDO::FETCH_ASSOC);
                    $senderUserId = $rowFac['user_id'] ?? 0;
                }
                if (!$receiverEmployeeId || !$senderUserId
                    || !function_exists('canUserOpenPardonForEmployee')
                    || !canUserOpenPardonForEmployee($senderUserId, $receiverEmployeeId, $pdo)) {
                    echo json_encode(['success' => false, 'message' => ucfirst($user_type) . " members can only chat with administrators or assigned employees."]);
                    exit;
                }
            } elseif (in_array($user_type, ['faculty', 'staff']) && !in_array($receiver_type, ['admin', 'super_admin'])) {
                echo json_encode(['success' => false, 'message' => 'You can only send messages to administrators or assigned employees.']);
                exit;
            }
            
            // Sanitize message
            $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
            
            $stmt = $pdo->prepare("
                INSERT INTO chat_messages 
                (sender_id, sender_type, receiver_id, receiver_type, message, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            
            $db_receiver = ($receiver_type === 'super_admin') ? 'admin' : $receiver_type;
            $stmt->execute([$sender_id, $db_user_type, $receiver_id, $db_receiver, $message]);
            
            echo json_encode([
                'success' => true, 
                'message' => 'Message sent successfully',
                'message_id' => $pdo->lastInsertId()
            ]);
            break;
            
        case 'get_messages':
            $receiver_id = intval($_GET['receiver_id'] ?? 0);
            $receiver_type = $_GET['receiver_type'] ?? '';
            $last_id = intval($_GET['last_id'] ?? 0);
            
            // Only allow known receiver types (faculty, staff, admin, super_admin)
            if (!in_array($receiver_type, ['faculty', 'admin', 'super_admin', 'staff'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid receiver type. Only faculty, staff and admin can use chat.']);
                exit;
            }

            if (!in_array($user_type, ['faculty', 'admin', 'super_admin', 'staff'])) {
                echo json_encode(['success' => false, 'message' => 'Only faculty, staff and admin can view messages.']);
                exit;
            }

            // Faculty/staff can view messages with admins, or (if pardon opener) with assigned employees
            if (in_array($user_type, ['faculty', 'staff']) && in_array($receiver_type, ['faculty', 'staff'])) {
                $receiverEmployeeId = null;
                if ($receiver_type === 'faculty') {
                    $stmtEmp = $pdo->prepare("SELECT employee_id FROM faculty_profiles WHERE id = ? LIMIT 1");
                    $stmtEmp->execute([$receiver_id]);
                    $row = $stmtEmp->fetch(PDO::FETCH_ASSOC);
                    $receiverEmployeeId = $row['employee_id'] ?? null;
                } else {
                    $stmtEmp = $pdo->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
                    $stmtEmp->execute([$receiver_id]);
                    $row = $stmtEmp->fetch(PDO::FETCH_ASSOC);
                    $receiverEmployeeId = $row['employee_id'] ?? null;
                }
                $viewerUserId = $user_id;
                if ($user_type === 'faculty') {
                    $stmtFac = $pdo->prepare("SELECT user_id FROM faculty_profiles WHERE id = ? LIMIT 1");
                    $stmtFac->execute([$sender_id]);
                    $rowFac = $stmtFac->fetch(PDO::FETCH_ASSOC);
                    $viewerUserId = $rowFac['user_id'] ?? 0;
                }
                if (!$receiverEmployeeId || !$viewerUserId
                    || !function_exists('canUserOpenPardonForEmployee')
                    || !canUserOpenPardonForEmployee($viewerUserId, $receiverEmployeeId, $pdo)) {
                    echo json_encode(['success' => false, 'message' => ucfirst($user_type) . " members can only chat with administrators or assigned employees."]);
                    exit;
                }
            } elseif (in_array($user_type, ['faculty', 'staff']) && !in_array($receiver_type, ['admin', 'super_admin'])) {
                echo json_encode(['success' => false, 'message' => 'You can only view messages with administrators or assigned employees.']);
                exit;
            }
            
            // Get messages between current user and specified receiver
            // If last_id is 0, load all messages. Otherwise, load only new messages.
            if ($last_id == 0) {
                // Initial load - get all messages
                $stmt = $pdo->prepare("
                    SELECT 
                        cm.*,
                        CASE 
                            WHEN cm.sender_type = 'faculty' THEN COALESCE(
                                CONCAT(fpds.first_name, ' ', fpds.last_name),
                                CONCAT(u_fac.first_name, ' ', u_fac.last_name),
                                'Faculty User'
                            )
                            WHEN cm.sender_type = 'admin' THEN CONCAT(u.first_name, ' ', u.last_name)
                            WHEN cm.sender_type = 'staff' THEN CONCAT(u_staff.first_name, ' ', u_staff.last_name)
                        END AS sender_name,
                        CASE 
                            WHEN cm.sender_type = 'faculty' THEN fp.profile_picture
                            ELSE NULL
                        END AS sender_avatar
                    FROM chat_messages cm
                    LEFT JOIN faculty_profiles fp ON cm.sender_id = fp.id AND cm.sender_type = 'faculty'
                    LEFT JOIN faculty_pds fpds ON fp.id = fpds.faculty_id
                    LEFT JOIN users u_fac ON fp.user_id = u_fac.id
                    LEFT JOIN users u ON cm.sender_id = u.id AND cm.sender_type = 'admin'
                    LEFT JOIN users u_staff ON cm.sender_id = u_staff.id AND cm.sender_type = 'staff'
                    WHERE (
                        (cm.sender_id = ? AND cm.sender_type = ? AND cm.receiver_id = ? AND cm.receiver_type = ?)
                        OR
                        (cm.sender_id = ? AND cm.sender_type = ? AND cm.receiver_id = ? AND cm.receiver_type = ?)
                    )
                    ORDER BY cm.created_at ASC
                ");
                
                $stmt->execute([
                    $sender_id, $db_user_type, $receiver_id, ($receiver_type === 'super_admin' ? 'admin' : $receiver_type),
                    $receiver_id, ($receiver_type === 'super_admin' ? 'admin' : $receiver_type), $sender_id, $db_user_type
                ]);
            } else {
                // Poll for new messages - only get messages after last_id
                $stmt = $pdo->prepare("
                    SELECT 
                        cm.*,
                        CASE 
                            WHEN cm.sender_type = 'faculty' THEN COALESCE(
                                CONCAT(fpds.first_name, ' ', fpds.last_name),
                                CONCAT(u_fac.first_name, ' ', u_fac.last_name),
                                'Faculty User'
                            )
                            WHEN cm.sender_type = 'admin' THEN CONCAT(u.first_name, ' ', u.last_name)
                            WHEN cm.sender_type = 'staff' THEN CONCAT(u_staff.first_name, ' ', u_staff.last_name)
                        END AS sender_name,
                        CASE 
                            WHEN cm.sender_type = 'faculty' THEN fp.profile_picture
                            ELSE NULL
                        END AS sender_avatar
                    FROM chat_messages cm
                    LEFT JOIN faculty_profiles fp ON cm.sender_id = fp.id AND cm.sender_type = 'faculty'
                    LEFT JOIN faculty_pds fpds ON fp.id = fpds.faculty_id
                    LEFT JOIN users u_fac ON fp.user_id = u_fac.id
                    LEFT JOIN users u ON cm.sender_id = u.id AND cm.sender_type = 'admin'
                    LEFT JOIN users u_staff ON cm.sender_id = u_staff.id AND cm.sender_type = 'staff'
                    WHERE (
                        (cm.sender_id = ? AND cm.sender_type = ? AND cm.receiver_id = ? AND cm.receiver_type = ?)
                        OR
                        (cm.sender_id = ? AND cm.sender_type = ? AND cm.receiver_id = ? AND cm.receiver_type = ?)
                    )
                    AND cm.id > ?
                    ORDER BY cm.created_at ASC
                ");
                
                $stmt->execute([
                    $sender_id, $db_user_type, $receiver_id, ($receiver_type === 'super_admin' ? 'admin' : $receiver_type),
                    $receiver_id, ($receiver_type === 'super_admin' ? 'admin' : $receiver_type), $sender_id, $db_user_type,
                    $last_id
                ]);
            }
            
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Mark messages as read
            if (!empty($messages)) {
                $message_ids = array_column($messages, 'id');
                $placeholders = str_repeat('?,', count($message_ids) - 1) . '?';
                $updateStmt = $pdo->prepare("
                    UPDATE chat_messages 
                    SET is_read = 1 
                    WHERE id IN ($placeholders) 
                    AND receiver_id = ? 
                    AND receiver_type = ?
                    AND is_read = 0
                ");
                $updateStmt->execute(array_merge($message_ids, [$sender_id, $db_user_type]));
            }
            
            echo json_encode(['success' => true, 'messages' => $messages]);
            break;
            
        case 'get_conversations':
            // Only faculty, staff and admin can view conversations
            if (!in_array($user_type, ['faculty', 'admin', 'super_admin', 'staff'])) {
                echo json_encode(['success' => false, 'message' => 'Only faculty, staff and admin can view conversations.']);
                exit;
            }

            // Get list of users the current user has chatted with
            if (in_array($user_type, ['faculty', 'staff'])) {
                // Faculty and staff can chat with admins
                $stmt = $pdo->prepare(" 
                    SELECT DISTINCT
                        u.id,
                        CONCAT(u.first_name, ' ', u.last_name) AS name,
                        'admin' AS type,
                        (SELECT COUNT(*) FROM chat_messages 
                         WHERE sender_id = u.id 
                         AND sender_type = 'admin' 
                         AND receiver_id = ? 
                         AND receiver_type = ? 
                         AND is_read = 0) AS unread_count,
                        (SELECT message FROM chat_messages 
                         WHERE (sender_id = u.id AND sender_type = 'admin' AND receiver_id = ? AND receiver_type = ?)
                         OR (sender_id = ? AND sender_type = ? AND receiver_id = u.id AND receiver_type = 'admin')
                         ORDER BY created_at DESC LIMIT 1) AS last_message,
                        (SELECT created_at FROM chat_messages 
                         WHERE (sender_id = u.id AND sender_type = 'admin' AND receiver_id = ? AND receiver_type = ?)
                         OR (sender_id = ? AND sender_type = ? AND receiver_id = u.id AND receiver_type = 'admin')
                         ORDER BY created_at DESC LIMIT 1) AS last_message_time
                    FROM users u
                    WHERE u.user_type IN ('admin', 'super_admin')
                    AND EXISTS (
                        SELECT 1 FROM chat_messages cm 
                        WHERE (cm.sender_id = u.id AND cm.sender_type = 'admin' AND cm.receiver_id = ? AND cm.receiver_type = ?)
                        OR (cm.sender_id = ? AND cm.sender_type = ? AND cm.receiver_id = u.id AND cm.receiver_type = 'admin')
                    )
                    ORDER BY last_message_time DESC
                ");
                $stmt->execute([$sender_id, $db_user_type, $sender_id, $db_user_type, $sender_id, $db_user_type, $sender_id, $db_user_type, $sender_id, $db_user_type, $sender_id, $db_user_type, $sender_id, $db_user_type]);
                $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // If pardon opener, also include conversations with assigned employees
                if (function_exists('hasPardonOpenerAssignments') && hasPardonOpenerAssignments($user_id, $pdo)
                    && function_exists('getEmployeeIdsInScope')) {
                    $employeeIds = getEmployeeIdsInScope($user_id, $pdo);
                    if (!empty($employeeIds)) {
                        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
                        // Faculty in scope
                        $stmtFac = $pdo->prepare("
                            SELECT DISTINCT fp.id, COALESCE(CONCAT(fpds.first_name, ' ', fpds.last_name), CONCAT(u.first_name, ' ', u.last_name), 'Faculty User') AS name,
                                'faculty' AS type, fp.profile_picture AS avatar,
                                (SELECT COUNT(*) FROM chat_messages WHERE sender_id = fp.id AND sender_type = 'faculty' AND receiver_id = ? AND receiver_type = ? AND is_read = 0) AS unread_count,
                                (SELECT message FROM chat_messages WHERE (sender_id = fp.id AND sender_type = 'faculty' AND receiver_id = ? AND receiver_type = ?)
                                 OR (sender_id = ? AND sender_type = ? AND receiver_id = fp.id AND receiver_type = 'faculty') ORDER BY created_at DESC LIMIT 1) AS last_message,
                                (SELECT created_at FROM chat_messages WHERE (sender_id = fp.id AND sender_type = 'faculty' AND receiver_id = ? AND receiver_type = ?)
                                 OR (sender_id = ? AND sender_type = ? AND receiver_id = fp.id AND receiver_type = 'faculty') ORDER BY created_at DESC LIMIT 1) AS last_message_time
                            FROM faculty_profiles fp
                            LEFT JOIN faculty_pds fpds ON fp.id = fpds.faculty_id
                            LEFT JOIN users u ON fp.user_id = u.id
                            WHERE fp.employee_id IN ($placeholders) AND u.user_type = 'faculty' AND fp.user_id != ?
                            AND EXISTS (SELECT 1 FROM chat_messages cm WHERE (cm.sender_id = fp.id AND cm.sender_type = 'faculty' AND cm.receiver_id = ? AND cm.receiver_type = ?)
                             OR (cm.sender_id = ? AND cm.sender_type = ? AND cm.receiver_id = fp.id AND cm.receiver_type = 'faculty'))
                            ORDER BY last_message_time DESC
                        ");
                        $subParams = array_merge($employeeIds, [$user_id]);
                        for ($i = 0; $i < 7; $i++) {
                            $subParams[] = $sender_id;
                            $subParams[] = $db_user_type;
                        }
                        $stmtFac->execute($subParams);
                        $facultyConvs = $stmtFac->fetchAll(PDO::FETCH_ASSOC);
                        // Staff in scope
                        $stmtSt = $pdo->prepare("
                            SELECT DISTINCT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, 'staff' AS type, NULL AS avatar,
                                (SELECT COUNT(*) FROM chat_messages WHERE sender_id = u.id AND sender_type = 'staff' AND receiver_id = ? AND receiver_type = ? AND is_read = 0) AS unread_count,
                                (SELECT message FROM chat_messages WHERE (sender_id = u.id AND sender_type = 'staff' AND receiver_id = ? AND receiver_type = ?)
                                 OR (sender_id = ? AND sender_type = ? AND receiver_id = u.id AND receiver_type = 'staff') ORDER BY created_at DESC LIMIT 1) AS last_message,
                                (SELECT created_at FROM chat_messages WHERE (sender_id = u.id AND sender_type = 'staff' AND receiver_id = ? AND receiver_type = ?)
                                 OR (sender_id = ? AND sender_type = ? AND receiver_id = u.id AND receiver_type = 'staff') ORDER BY created_at DESC LIMIT 1) AS last_message_time
                            FROM faculty_profiles fp
                            JOIN users u ON fp.user_id = u.id
                            WHERE fp.employee_id IN ($placeholders) AND u.user_type = 'staff' AND u.id != ?
                            AND EXISTS (SELECT 1 FROM chat_messages cm WHERE (cm.sender_id = u.id AND cm.sender_type = 'staff' AND cm.receiver_id = ? AND cm.receiver_type = ?)
                             OR (cm.sender_id = ? AND cm.sender_type = ? AND cm.receiver_id = u.id AND cm.receiver_type = 'staff'))
                            ORDER BY last_message_time DESC
                        ");
                        $subParamsSt = array_merge($employeeIds, [$user_id]);
                        for ($i = 0; $i < 7; $i++) {
                            $subParamsSt[] = $sender_id;
                            $subParamsSt[] = $db_user_type;
                        }
                        $stmtSt->execute($subParamsSt);
                        $staffConvs = $stmtSt->fetchAll(PDO::FETCH_ASSOC);
                        $conversations = array_merge($conversations, $facultyConvs, $staffConvs);
                        usort($conversations, function ($a, $b) {
                            $ta = strtotime($a['last_message_time'] ?? '0');
                            $tb = strtotime($b['last_message_time'] ?? '0');
                            return $tb <=> $ta;
                        });
                    }
                }
                echo json_encode(['success' => true, 'conversations' => $conversations]);
                break;
            } else {
                // Admin can chat with all faculty
                $stmt = $pdo->prepare("
                    SELECT DISTINCT
                        fp.id,
                        COALESCE(
                            CONCAT(fpds.first_name, ' ', fpds.last_name),
                            CONCAT(u.first_name, ' ', u.last_name),
                            'Faculty User'
                        ) AS name,
                        'faculty' AS type,
                        fp.profile_picture AS avatar,
                        (SELECT COUNT(*) FROM chat_messages 
                         WHERE sender_id = fp.id 
                         AND sender_type = 'faculty' 
                         AND receiver_id = ? 
                         AND receiver_type = 'admin' 
                         AND is_read = 0) AS unread_count,
                        (SELECT message FROM chat_messages 
                         WHERE (sender_id = fp.id AND sender_type = 'faculty' AND receiver_id = ? AND receiver_type = 'admin')
                         OR (sender_id = ? AND sender_type = 'admin' AND receiver_id = fp.id AND receiver_type = 'faculty')
                         ORDER BY created_at DESC LIMIT 1) AS last_message,
                        (SELECT created_at FROM chat_messages 
                         WHERE (sender_id = fp.id AND sender_type = 'faculty' AND receiver_id = ? AND receiver_type = 'admin')
                         OR (sender_id = ? AND sender_type = 'admin' AND receiver_id = fp.id AND receiver_type = 'faculty')
                         ORDER BY created_at DESC LIMIT 1) AS last_message_time
                    FROM faculty_profiles fp
                    LEFT JOIN faculty_pds fpds ON fp.id = fpds.faculty_id
                    LEFT JOIN users u ON fp.user_id = u.id
                    WHERE EXISTS (
                        SELECT 1 FROM chat_messages cm 
                        WHERE (cm.sender_id = fp.id AND cm.sender_type = 'faculty' AND cm.receiver_id = ? AND cm.receiver_type = 'admin')
                        OR (cm.sender_id = ? AND cm.sender_type = 'admin' AND cm.receiver_id = fp.id AND cm.receiver_type = 'faculty')
                    )
                    ORDER BY last_message_time DESC
                ");
                $stmt->execute([$sender_id, $sender_id, $sender_id, $sender_id, $sender_id, $sender_id, $sender_id]);
                $faculty_conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Also include staff conversations (staff users are stored in users table)
                $stmt2 = $pdo->prepare(" 
                    SELECT DISTINCT
                        u.id,
                        CONCAT(u.first_name, ' ', u.last_name) AS name,
                        'staff' AS type,
                        NULL AS avatar,
                        (SELECT COUNT(*) FROM chat_messages 
                         WHERE sender_id = u.id 
                         AND sender_type = 'staff' 
                         AND receiver_id = ? 
                         AND receiver_type = 'admin' 
                         AND is_read = 0) AS unread_count,
                        (SELECT message FROM chat_messages 
                         WHERE (sender_id = u.id AND sender_type = 'staff' AND receiver_id = ? AND receiver_type = 'admin')
                         OR (sender_id = ? AND sender_type = 'admin' AND receiver_id = u.id AND receiver_type = 'staff')
                         ORDER BY created_at DESC LIMIT 1) AS last_message,
                        (SELECT created_at FROM chat_messages 
                         WHERE (sender_id = u.id AND sender_type = 'staff' AND receiver_id = ? AND receiver_type = 'admin')
                         OR (sender_id = ? AND sender_type = 'admin' AND receiver_id = u.id AND receiver_type = 'staff')
                         ORDER BY created_at DESC LIMIT 1) AS last_message_time
                    FROM users u
                    WHERE u.user_type = 'staff' AND u.is_active = 1
                    AND EXISTS (
                        SELECT 1 FROM chat_messages cm 
                        WHERE (cm.sender_id = u.id AND cm.sender_type = 'staff' AND cm.receiver_id = ? AND cm.receiver_type = 'admin')
                        OR (cm.sender_id = ? AND cm.sender_type = 'admin' AND cm.receiver_id = u.id AND cm.receiver_type = 'staff')
                    )
                    ORDER BY last_message_time DESC
                ");
                $stmt2->execute([$sender_id, $sender_id, $sender_id, $sender_id, $sender_id, $sender_id, $sender_id]);

                $staff_conversations = $stmt2->fetchAll(PDO::FETCH_ASSOC);

                // Merge faculty and staff conversations for admin
                $conversations = array_merge($faculty_conversations, $staff_conversations);
                echo json_encode(['success' => true, 'conversations' => $conversations]);
                break;
            }
            
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'conversations' => $conversations]);
            break;
            
        case 'get_unread_count':
            // Get total unread message count
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count
                FROM chat_messages
                WHERE receiver_id = ? 
                AND receiver_type = ?
                AND is_read = 0
            ");
            $stmt->execute([$sender_id, $db_user_type]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'count' => intval($result['count'])]);
            break;
            
        case 'mark_as_read':
            $message_ids = $_POST['message_ids'] ?? [];
            
            if (empty($message_ids)) {
                echo json_encode(['success' => false, 'message' => 'No message IDs provided']);
                exit;
            }
            
            $placeholders = str_repeat('?,', count($message_ids) - 1) . '?';
            $stmt = $pdo->prepare("
                UPDATE chat_messages 
                SET is_read = 1 
                WHERE id IN ($placeholders) 
                AND receiver_id = ? 
                AND receiver_type = ?
            ");
            $stmt->execute(array_merge($message_ids, [$sender_id, $user_type]));
            
            echo json_encode(['success' => true, 'message' => 'Messages marked as read']);
            break;
            
        case 'get_admin_list':
            // For faculty or staff to get list of admins to chat with
            if (!in_array($user_type, ['faculty', 'staff'])) {
                echo json_encode(['success' => false, 'message' => 'Only faculty or staff can access admin list']);
                exit;
            }
            
            // Only return active admin users with is_online flag (last_activity within 5 minutes)
            $onlineThreshold = time() - 300; // 5 minutes
            $stmt = $pdo->prepare("
                SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, 'admin' AS type,
                    (ua.last_activity >= ?) AS is_online
                FROM users u
                LEFT JOIN user_activity ua ON u.id = ua.user_id
                WHERE u.user_type IN ('admin', 'super_admin') AND u.is_active = 1
                ORDER BY u.first_name, u.last_name
            ");
            $stmt->execute([$onlineThreshold]);
            $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Convert is_online to boolean (PDO returns string '0'/'1')
            foreach ($admins as &$a) {
                $a['is_online'] = !empty($a['is_online']);
            }
            unset($a);
            
            // If user has pardon opener assignments, also include assigned employees in New Chat modal
            $assignedUsers = [];
            if (function_exists('hasPardonOpenerAssignments') && hasPardonOpenerAssignments($user_id, $pdo)
                && function_exists('getEmployeeIdsInScope')) {
                $employeeIds = getEmployeeIdsInScope($user_id, $pdo);
                if (!empty($employeeIds)) {
                    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
                    // Get faculty in scope (use faculty_profiles.id for chat, exclude self)
                    $stmt = $pdo->prepare("
                        SELECT fp.id, COALESCE(CONCAT(fpds.first_name, ' ', fpds.last_name), CONCAT(u.first_name, ' ', u.last_name), 'Faculty User') AS name,
                            fp.profile_picture AS avatar, 'faculty' AS type, 0 AS is_online
                        FROM faculty_profiles fp
                        LEFT JOIN faculty_pds fpds ON fp.id = fpds.faculty_id
                        LEFT JOIN users u ON fp.user_id = u.id
                        WHERE fp.employee_id IN ($placeholders) AND u.user_type = 'faculty' AND u.is_active = 1 AND fp.user_id != ?
                    ");
                    $stmt->execute(array_merge($employeeIds, [$user_id]));
                    $facultyAssigned = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    // Get staff in scope (use user.id for chat, exclude self)
                    $stmt = $pdo->prepare("
                        SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) AS name, NULL AS avatar, 'staff' AS type, 0 AS is_online
                        FROM faculty_profiles fp
                        JOIN users u ON fp.user_id = u.id
                        WHERE fp.employee_id IN ($placeholders) AND u.user_type = 'staff' AND u.is_active = 1 AND u.id != ?
                    ");
                    $stmt->execute(array_merge($employeeIds, [$user_id]));
                    $staffAssigned = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $assignedUsers = array_merge($facultyAssigned, $staffAssigned);
                }
            }
            
            $allUsers = array_merge($admins, $assignedUsers);
            echo json_encode(['success' => true, 'admins' => $allUsers]);
            break;

        case 'get_online_admin_count':
            // For faculty or staff to check if any admin is online (for bubble green dot)
            if (!in_array($user_type, ['faculty', 'staff'])) {
                echo json_encode(['success' => false, 'message' => 'Only faculty or staff can access']);
                exit;
            }
            $onlineThreshold = time() - 300; // 5 minutes
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt FROM user_activity ua
                INNER JOIN users u ON u.id = ua.user_id
                WHERE u.user_type IN ('admin', 'super_admin') AND u.is_active = 1 AND ua.last_activity >= ?
            ");
            $stmt->execute([$onlineThreshold]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'count' => (int)($row['cnt'] ?? 0)]);
            break;

        case 'get_staff_list':
            // For admin to get list of staff to chat with
            if (!in_array($user_type, ['admin', 'super_admin'])) {
                echo json_encode(['success' => false, 'message' => 'Only admin can access staff list']);
                exit;
            }

            // Only return active staff users
            $stmt = $pdo->query("
                SELECT id, CONCAT(first_name, ' ', last_name) AS name, NULL AS avatar, 'staff' AS type
                FROM users
                WHERE user_type = 'staff' AND is_active = 1
                ORDER BY first_name, last_name
            ");

            $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'staff' => $staff]);
            break;
            
        case 'get_faculty_list':
            // For admin to get list of faculty to chat with
            if (!in_array($user_type, ['admin', 'super_admin'])) {
                echo json_encode(['success' => false, 'message' => 'Only admin can access faculty list']);
                exit;
            }
            
            // Only return active faculty users
            $stmt = $pdo->query("
                SELECT 
                    fp.id, 
                    COALESCE(
                        CONCAT(fpds.first_name, ' ', fpds.last_name),
                        CONCAT(u.first_name, ' ', u.last_name),
                        'Faculty User'
                    ) AS name,
                    fp.profile_picture AS avatar,
                    'faculty' AS type
                FROM faculty_profiles fp
                LEFT JOIN faculty_pds fpds ON fp.id = fpds.faculty_id
                LEFT JOIN users u ON fp.user_id = u.id
                WHERE u.is_active = 1 AND u.user_type = 'faculty'
                ORDER BY name
            ");
            
            $faculty = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'faculty' => $faculty]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
