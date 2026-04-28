<?php
/**
 * Process email queue - run via cron or Task Scheduler every 1-5 minutes.
 * Windows: schtasks /create /tn "SendQueuedEmails" /tr "php C:\inetpub\wwwroot\FP\cron\send_queued_emails.php" /sc minute /mo 2
 */
$baseDir = dirname(__DIR__);
require_once $baseDir . '/includes/config.php';
require_once $baseDir . '/includes/database.php';
require_once $baseDir . '/includes/mailer.php';

$db = Database::getInstance()->getConnection();

$stmt = $db->query("SHOW TABLES LIKE 'email_queue'");
if (!$stmt->rowCount()) exit("email_queue table not found. Run: php db/migrations/run_20260317_email_queue.php\n");

$stmt = $db->prepare("
    SELECT id, to_email, to_name, template_type, template_data, attempts
    FROM email_queue
    WHERE sent_at IS NULL AND attempts < 3
    ORDER BY id ASC
    LIMIT 50
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mailer = new Mailer();
$sent = 0;
$failed = 0;

foreach ($rows as $row) {
    $data = json_decode($row['template_data'], true) ?: [];
    $ok = false;
    try {
        switch ($row['template_type']) {
            case 'announcement':
                $ok = $mailer->sendAnnouncementNotification($row['to_email'], $row['to_name'], $data['title'] ?? '', $data['content'] ?? '');
                break;
            case 'calendar_event':
                $ok = $mailer->sendCalendarEventNotification(
                    $row['to_email'], $row['to_name'],
                    $data['title'] ?? '', $data['event_date'] ?? '', $data['event_time'] ?? null,
                    $data['end_time'] ?? null, $data['location'] ?? null, $data['description'] ?? null, $data['category'] ?? null
                );
                break;
            default:
                $db->prepare("UPDATE email_queue SET last_error = ?, attempts = attempts + 1 WHERE id = ?")
                    ->execute(['Unknown template: ' . $row['template_type'], $row['id']]);
        }
        if ($ok) {
            $db->prepare("UPDATE email_queue SET sent_at = NOW() WHERE id = ?")->execute([$row['id']]);
            $sent++;
        } else {
            $db->prepare("UPDATE email_queue SET attempts = attempts + 1 WHERE id = ?")->execute([$row['id']]);
            $failed++;
        }
    } catch (Exception $e) {
        $db->prepare("UPDATE email_queue SET last_error = ?, attempts = attempts + 1 WHERE id = ?")
            ->execute([substr($e->getMessage(), 0, 500), $row['id']]);
        $failed++;
    }
}

if ($sent > 0 || $failed > 0) {
    echo date('Y-m-d H:i:s') . " - Sent: $sent, Failed: $failed\n";
}
