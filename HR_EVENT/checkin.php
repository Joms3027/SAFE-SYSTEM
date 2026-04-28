<?php
/**
 * HR Event Check-in (landing when employee scans event QR or opens event check-in URL)
 * GET e=event_id&t=token - validates token, requires login, records attendance.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
// Note: HR_EVENT is under project root; __DIR__ = .../FP/HR_EVENT, so ../ = FP

$basePath = getBasePath();
$loginPath = clean_url($basePath . '/login.php', $basePath);

// If not logged in, redirect to login with return URL
if (!isLoggedIn() || !isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    $returnUrl = $basePath . '/HR_EVENT/checkin.php?' . http_build_query([
        'e' => $_GET['e'] ?? '',
        't' => $_GET['t'] ?? ''
    ]);
    $redirect = $loginPath . '?redirect=' . urlencode($returnUrl);
    header('Location: ' . $redirect);
    exit;
}

// Only faculty and staff can check in
if (!isFaculty() && !isStaff()) {
    $_SESSION['error'] = 'Only employees (faculty/staff) can check in to events.';
    header('Location: ' . clean_url($basePath . '/faculty/dashboard.php', $basePath));
    exit;
}

$eventId = isset($_GET['e']) ? (int) $_GET['e'] : 0;
$token = isset($_GET['t']) ? trim($_GET['t']) : '';

if (!$eventId || $token === '') {
    $message = 'Invalid check-in link. Please scan the event QR code displayed at the venue.';
    $messageType = 'danger';
} else {
    $database = Database::getInstance();
    $db = $database->getConnection();

    try {
        $stmt = $db->prepare("
            SELECT id, title, description, event_date, event_time, end_time, location, is_active
            FROM hr_events
            WHERE id = ? AND qr_token = ? AND is_active = 1
        ");
        $stmt->execute([$eventId, $token]);
        $event = $stmt->fetch();

        if (!$event) {
            $message = 'Invalid or expired event QR. Please use the QR displayed at the event.';
            $messageType = 'danger';
        } else {
            $userId = (int) $_SESSION['user_id'];
            $stmtEmp = $db->prepare("SELECT employee_id FROM faculty_profiles WHERE user_id = ?");
            $stmtEmp->execute([$userId]);
            $emp = $stmtEmp->fetch();
            $employeeId = $emp ? trim($emp['employee_id'] ?? '') : null;

            // 4-point attendance: IN morning → OUT 12PM → IN 1PM → OUT afternoon
            $checkOrder = ['in_morning', 'out_noon', 'in_afternoon', 'out_afternoon'];
            $checkLabels = [
                'in_morning' => 'IN (morning)',
                'out_noon' => 'OUT (12:00 PM)',
                'in_afternoon' => 'IN (1:00 PM)',
                'out_afternoon' => 'OUT (afternoon)'
            ];

            $stmtCheck = $db->prepare("
                SELECT check_type FROM hr_event_attendances
                WHERE event_id = ? AND user_id = ?
                ORDER BY FIELD(check_type, 'in_morning', 'out_noon', 'in_afternoon', 'out_afternoon')
            ");
            $stmtCheck->execute([$eventId, $userId]);
            $existing = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);

            $nextType = null;
            foreach ($checkOrder as $t) {
                if (!in_array($t, $existing, true)) {
                    $nextType = $t;
                    break;
                }
            }

            if (!$nextType) {
                $message = 'You have completed all check-ins for this event.';
                $messageType = 'info';
            } else {
                $stmtIns = $db->prepare("
                    INSERT INTO hr_event_attendances (event_id, user_id, employee_id, check_type, scanned_at)
                    VALUES (?, ?, ?, ?, NOW())
                ");
                $stmtIns->execute([$eventId, $userId, $employeeId, $nextType]);
                $message = $checkLabels[$nextType] . ' recorded. Welcome to ' . htmlspecialchars($event['title']) . '.';
                $messageType = 'success';
            }
            $eventTitle = $event['title'];
            $eventDate = $event['event_date'];
            $eventTime = $event['event_time'];
            $eventLocation = $event['location'];
        }
    } catch (Exception $e) {
        error_log('HR Event checkin error: ' . $e->getMessage());
        $message = 'Could not record check-in. Please try again.';
        $messageType = 'danger';
    }
}

$pageTitle = isset($eventTitle) ? 'Event Check-in' : 'Event Check-in';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - HR Events</title>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background: #f0f4f8; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .checkin-card { max-width: 420px; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,.08); }
        .checkin-card .card-body { padding: 2rem; }
        .alert-success { border-left: 4px solid #198754; }
        .alert-info { border-left: 4px solid #0dcaf0; }
        .alert-danger { border-left: 4px solid #dc3545; }
        .event-details { background: #f8f9fa; border-radius: 8px; padding: 1rem; margin-top: 1rem; }
    </style>
</head>
<body>
    <div class="checkin-card card">
        <div class="card-body text-center">
            <?php if (isset($messageType) && isset($message)): ?>
                <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> text-start" role="alert">
                    <?php if ($messageType === 'success'): ?>
                        <i class="bi bi-check-circle-fill me-2"></i>
                    <?php elseif ($messageType === 'info'): ?>
                        <i class="bi bi-info-circle-fill me-2"></i>
                    <?php else: ?>
                        <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php endif; ?>
                    <?php echo htmlspecialchars($message); ?>
                </div>
                <?php if (!empty($eventTitle)): ?>
                    <div class="event-details text-start">
                        <strong><?php echo htmlspecialchars($eventTitle); ?></strong><br>
                        <?php if (!empty($eventDate)): ?>
                            <small class="text-muted">
                                <i class="bi bi-calendar3"></i> <?php echo date('M j, Y', strtotime($eventDate)); ?>
                                <?php if (!empty($eventTime)): ?>
                                    &nbsp;&bull;&nbsp; <i class="bi bi-clock"></i> <?php echo date('g:i A', strtotime($eventTime)); ?>
                                <?php endif; ?>
                            </small><br>
                        <?php endif; ?>
                        <?php if (!empty($eventLocation)): ?>
                            <small class="text-muted"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($eventLocation); ?></small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <a href="<?php echo clean_url($basePath . '/HR_EVENT/index.php', $basePath); ?>" class="btn btn-outline-primary mt-3">
                <i class="bi bi-qr-code-scan me-1"></i> Event Check-in
            </a>
            <a href="<?php echo clean_url($basePath . '/faculty/dashboard.php', $basePath); ?>" class="btn btn-link mt-2 d-block text-muted">Back to Dashboard</a>
        </div>
    </div>
</body>
</html>
