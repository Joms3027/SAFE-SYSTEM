<?php
/**
 * Email Queue - Queue emails for deferred sending to avoid blocking API responses.
 * Process via: php cron/send_queued_emails.php (run every 1-5 min via Task Scheduler/cron)
 */

if (!function_exists('queueEmail')) {
function queueEmail($toEmail, $toName, $templateType, $templateData) {
    if (empty($toEmail)) return false;
    try {
        $db = \Database::getInstance()->getConnection();
        $tbl = $db->query("SHOW TABLES LIKE 'email_queue'");
        if (!$tbl || $tbl->rowCount() === 0) return false;
        $stmt = $db->prepare("INSERT INTO email_queue (to_email, to_name, template_type, template_data) VALUES (?, ?, ?, ?)");
        $json = json_encode($templateData);
        return $stmt->execute([$toEmail, $toName ?: '', $templateType, $json]);
    } catch (Exception $e) {
        error_log("Email queue insert error: " . $e->getMessage());
        return false;
    }
}
}
