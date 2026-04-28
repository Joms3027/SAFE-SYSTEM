<?php
/**
 * Supervisor Calendar API - For pardon openers to create events/meetings for their team.
 * Events are visible only to employees in the supervisor's scope.
 * Sends notifications and emails to scope employees.
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
    if ($method === 'POST' && $action === 'create') {
        $title = sanitizeInput($_POST['title'] ?? '');
        $description = sanitizeInput($_POST['description'] ?? '');
        $eventDate = $_POST['event_date'] ?? '';
        $eventTime = !empty($_POST['event_time']) ? $_POST['event_time'] : null;
        $endTime = !empty($_POST['end_time']) ? $_POST['end_time'] : null;
        $location = sanitizeInput($_POST['location'] ?? '');
        $category = sanitizeInput($_POST['category'] ?? 'university_event');
        $sendNotifications = isset($_POST['send_notifications']) && $_POST['send_notifications'] === '1';

        if (empty($title) || empty($eventDate)) {
            echo json_encode(['success' => false, 'message' => 'Title and event date are required']);
            exit;
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date format']);
            exit;
        }
        if ($eventTime && !preg_match('/^\d{2}:\d{2}$/', $eventTime)) {
            echo json_encode(['success' => false, 'message' => 'Invalid time format']);
            exit;
        }
        if ($endTime && !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            echo json_encode(['success' => false, 'message' => 'Invalid end time format']);
            exit;
        }

        // Check if scope_supervisor_id column exists
        $hasScopeCol = false;
        try {
            $stmt = $db->query("SHOW COLUMNS FROM calendar_events LIKE 'scope_supervisor_id'");
            $hasScopeCol = $stmt->rowCount() > 0;
        } catch (Exception $e) {}

        if ($hasScopeCol) {
            $stmt = $db->prepare("
                INSERT INTO calendar_events (title, description, event_date, event_time, end_time, location, category, event_type, color, created_by, scope_supervisor_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'university_event', ?, ?, ?)
            ");
            $stmt->execute([$title, $description, $eventDate, $eventTime, $endTime, $location, $category, '#17a2b8', $_SESSION['user_id'], $_SESSION['user_id']]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO calendar_events (title, description, event_date, event_time, end_time, location, category, event_type, color, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'university_event', ?, ?)
            ");
            $stmt->execute([$title, $description, $eventDate, $eventTime, $endTime, $location, $category, '#17a2b8', $_SESSION['user_id']]);
        }
        $eventId = $db->lastInsertId();

        $notificationCount = 0;
        $emailQueued = 0;

        if ($sendNotifications && function_exists('getEmployeeIdsInScope')) {
            $employeeIds = getEmployeeIdsInScope($_SESSION['user_id'], $db);
            if (!empty($employeeIds)) {
                $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
                $stmt = $db->prepare("
                    SELECT u.id, u.email, u.first_name, u.last_name
                    FROM users u
                    JOIN faculty_profiles fp ON fp.user_id = u.id
                    WHERE fp.employee_id IN ($placeholders) AND u.is_active = 1 AND u.is_verified = 1
                ");
                $stmt->execute($employeeIds);
                $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $notificationManager = getNotificationManager();
                $basePath = getBasePath();
                $calendarUrl = $basePath . '/faculty/calendar.php';

                $dateObj = new DateTime($eventDate);
                $formattedDate = $dateObj->format('F j, Y');
                $notificationMessage = "New event: {$title} on {$formattedDate}";
                if ($eventTime) {
                    $timeDisplay = date('g:i A', strtotime($eventTime));
                    if ($endTime) $timeDisplay .= ' - ' . date('g:i A', strtotime($endTime));
                    $notificationMessage .= " at {$timeDisplay}";
                }
                if ($location) $notificationMessage .= ". Location: {$location}";

                $notificationCount = $notificationManager->createNotificationsBatch($recipients, 'calendar_event', '📅 New Team Event', $notificationMessage, $calendarUrl, 'normal');

                $emailQueued = 0;
                foreach ($recipients as $r) {
                    if (!empty($r['email']) && function_exists('queueEmail')) {
                        $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                        if (empty($name)) $name = 'Employee';
                        if (queueEmail($r['email'], $name, 'calendar_event', [
                            'title' => $title, 'event_date' => $eventDate, 'event_time' => $eventTime,
                            'end_time' => $endTime, 'location' => $location, 'description' => $description, 'category' => $category
                        ])) {
                            $emailQueued++;
                        }
                    }
                }
            }
        }

        $msg = 'Event created successfully';
        if ($sendNotifications) {
            if ($notificationCount > 0) $msg .= " Notifications sent to {$notificationCount} employee(s).";
            if ($emailQueued > 0) $msg .= " Emails queued for delivery.";
        }
        echo json_encode(['success' => true, 'message' => $msg, 'event_id' => (int)$eventId]);

    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("Supervisor calendar API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
