<?php
/**
 * Supervisor Announcements API - For pardon openers to announce to employees in their scope.
 * Sends in-app notifications and emails to scope employees.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/email_queue.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAuth();

if (!isFaculty() && !isStaff()) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

if (!hasPardonOpenerAssignments($_SESSION['user_id'], $db)) {
    echo json_encode(['success' => false, 'message' => 'You do not have pardon opener assignments.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($method === 'GET' && $action === 'list') {
        // List supervisor's announcements
        $stmt = $db->prepare("
            SELECT sa.id, sa.title, sa.content, sa.priority, sa.target_audience, sa.is_active, sa.expires_at, sa.created_at,
                   u.first_name, u.last_name
            FROM supervisor_announcements sa
            JOIN users u ON sa.supervisor_id = u.id
            WHERE sa.supervisor_id = ?
            ORDER BY sa.created_at DESC
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'announcements' => $announcements]);

    } elseif ($method === 'POST' && $action === 'create') {
        $title = sanitizeInput($_POST['title'] ?? '');
        $content = sanitizeInput($_POST['content'] ?? '');
        $targetAudienceRaw = trim($_POST['target_audience'] ?? 'all');
        $targetAudience = 'all';
        if ($targetAudienceRaw !== '' && $targetAudienceRaw !== 'all') {
            $selections = array_map('trim', explode(',', $targetAudienceRaw));
            $valid = [];
            foreach ($selections as $sel) {
                $parts = explode('|', $sel, 2);
                if (count($parts) === 2 && in_array($parts[0], ['faculty', 'staff']) && trim($parts[1]) !== '') {
                    $valid[] = $parts[0] . '|' . trim($parts[1]);
                }
            }
            if (!empty($valid)) {
                $targetAudience = implode(',', array_unique($valid));
            }
        }
        $priority = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high', 'urgent']) ? $_POST['priority'] : 'normal';
        $expiresAt = !empty($_POST['expires_at']) ? $_POST['expires_at'] : null;
        $sendNotifications = isset($_POST['send_notifications']) && $_POST['send_notifications'] === '1';

        if (empty($title) || empty($content)) {
            echo json_encode(['success' => false, 'message' => 'Title and content are required']);
            exit;
        }

        // Insert announcement
        $stmt = $db->prepare("
            INSERT INTO supervisor_announcements (supervisor_id, title, content, priority, target_audience, expires_at)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$_SESSION['user_id'], $title, $content, $priority, $targetAudience, $expiresAt]);
        $announcementId = $db->lastInsertId();

        $notificationCount = 0;
        $emailQueued = 0;

        if ($sendNotifications && function_exists('getEmployeeIdsInScope')) {
            $employeeIds = getEmployeeIdsInScope($_SESSION['user_id'], $db);
            if (!empty($employeeIds)) {
                $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
                // Filter by target_audience: faculty|STATUS,staff|STATUS (multiple allowed - match any)
                $audienceCondition = '';
                $executeParams = $employeeIds;
                if ($targetAudience !== 'all') {
                    $selections = array_map('trim', explode(',', $targetAudience));
                    $orConditions = [];
                    foreach ($selections as $sel) {
                        $parts = explode('|', $sel, 2);
                        if (count($parts) === 2) {
                            $typeFilter = $parts[0];
                            $statusFilter = trim($parts[1]);
                            $typeCond = $typeFilter === 'faculty'
                                ? "(LOWER(TRIM(COALESCE(fp.employment_type, ''))) = 'faculty' OR (COALESCE(TRIM(fp.employment_type), '') = '' AND u.user_type = 'faculty'))"
                                : "(LOWER(TRIM(COALESCE(fp.employment_type, ''))) = 'staff' OR (COALESCE(TRIM(fp.employment_type), '') = '' AND u.user_type = 'staff'))";
                            $orConditions[] = "($typeCond AND TRIM(COALESCE(fp.employment_status, '')) = ?)";
                            $executeParams[] = $statusFilter;
                        }
                    }
                    if (!empty($orConditions)) {
                        $audienceCondition = ' AND (' . implode(' OR ', $orConditions) . ')';
                    }
                }
                $stmt = $db->prepare("
                    SELECT u.id, u.email, u.first_name, u.last_name
                    FROM users u
                    JOIN faculty_profiles fp ON fp.user_id = u.id
                    WHERE fp.employee_id IN ($placeholders) AND u.is_active = 1 AND u.is_verified = 1
                    $audienceCondition
                ");
                $stmt->execute($executeParams);
                $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $notificationManager = getNotificationManager();
                $basePath = getBasePath();
                $announceUrl = $basePath . '/faculty/supervisor_scope_announcements.php';
                $notifMessage = substr($content, 0, 200) . (strlen($content) > 200 ? '...' : '');

                $notificationCount = $notificationManager->createNotificationsBatch($recipients, 'announcement', '📢 ' . $title, $notifMessage, $announceUrl, $priority);

                $emailQueued = 0;
                foreach ($recipients as $r) {
                    if (!empty($r['email']) && function_exists('queueEmail')) {
                        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                        if (empty($name)) $name = 'Employee';
                        if (queueEmail($r['email'], $name, 'announcement', ['title' => $title, 'content' => $content])) {
                            $emailQueued++;
                        }
                    }
                }
            }
        }

        $msg = 'Announcement created successfully';
        if ($sendNotifications) {
            if ($notificationCount > 0) $msg .= " Notifications sent to {$notificationCount} employee(s).";
            if ($emailQueued > 0) $msg .= " Emails queued for delivery.";
        }
        echo json_encode(['success' => true, 'message' => $msg, 'announcement_id' => (int)$announcementId]);

    } elseif ($method === 'POST' && $action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        $stmt = $db->prepare("DELETE FROM supervisor_announcements WHERE id = ? AND supervisor_id = ?");
        if ($stmt->execute([$id, $_SESSION['user_id']])) {
            echo json_encode(['success' => true, 'message' => 'Announcement deleted']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to delete']);
        }

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Supervisor announcements API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
