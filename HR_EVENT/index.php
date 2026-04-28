<?php
/**
 * HR Events - Employee landing: present your SAFE QR at the event to be scanned.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireFaculty();

$basePath = getBasePath();

// Optional: list upcoming/today events (read-only for employees)
$events = [];
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    $stmt = $db->query("
        SELECT id, title, event_date, event_time, end_time, location
        FROM hr_events
        WHERE is_active = 1 AND event_date >= CURDATE()
        ORDER BY event_date ASC, event_time ASC
        LIMIT 10
    ");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ignore
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Check-in - HR Events</title>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <style>
        body { background: #f0f4f8; min-height: 100vh; padding: 1.5rem 0; }
        .hero-card { border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,.08); overflow: hidden; }
        .btn-scan { border-radius: 10px; padding: 0.9rem 1.5rem; font-weight: 600; }
        .event-list .list-group-item { border-left: 0; border-right: 0; }
    </style>
</head>
<body>
    <div class="container" style="max-width: 480px;">
        <div class="hero-card card mb-4">
            <div class="card-body text-center py-4">
                <div class="mb-3">
                    <span class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width: 64px; height: 64px;">
                        <i class="bi bi-qr-code-scan text-primary" style="font-size: 1.8rem;"></i>
                    </span>
                </div>
                <h5 class="card-title">Event Check-in</h5>
                <p class="text-muted small mb-4">At the event, present the <strong>same QR code from your SAFE account</strong> (profile or ID) so staff can scan it to check you in.</p>
                <a href="<?php echo clean_url($basePath . '/faculty/profile.php', $basePath); ?>" class="btn btn-outline-primary btn-scan w-100">
                    <i class="bi bi-qr-code me-2"></i>View my SAFE QR code
                </a>
            </div>
        </div>
        <?php if (!empty($events)): ?>
        <div class="card hero-card">
            <div class="card-header bg-transparent border-0 pt-3">
                <h6 class="mb-0"><i class="bi bi-calendar-event me-2"></i>Upcoming events</h6>
            </div>
            <div class="card-body pt-0">
                <ul class="list-group list-group-flush event-list">
                    <?php foreach ($events as $ev): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-start">
                        <div>
                            <strong><?php echo htmlspecialchars($ev['title']); ?></strong>
                            <br><small class="text-muted">
                                <i class="bi bi-calendar3"></i> <?php echo date('M j, Y', strtotime($ev['event_date'])); ?>
                                <?php if (!empty($ev['event_time'])): ?>
                                    &nbsp;&bull;&nbsp; <?php echo date('g:i A', strtotime($ev['event_time'])); ?>
                                <?php endif; ?>
                                <?php if (!empty($ev['location'])): ?>
                                    <br><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($ev['location']); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>
        <div class="text-center mt-3">
            <a href="<?php echo clean_url($basePath . '/faculty/dashboard.php', $basePath); ?>" class="btn btn-link text-muted">Back to Dashboard</a>
        </div>
    </div>
</body>
</html>
