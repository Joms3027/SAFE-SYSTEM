<?php
/**
 * Notification Scheduler
 * Run this script via cron job to send automated notifications
 * Recommended: Run once daily at midnight
 * 
 * Cron example: 0 0 * * * php /path/to/notification_scheduler.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mailer.php';

$database = Database::getInstance();
$db = $database->getConnection();
$notificationManager = getNotificationManager();
$mailer = new Mailer();

echo "=== Notification Scheduler Started at " . date('Y-m-d H:i:s') . " ===\n";

// 1. Send deadline reminders for requirements
echo "\n1. Checking deadline reminders...\n";

$stmt = $db->prepare("
    SELECT 
        r.id as requirement_id,
        r.title,
        r.deadline,
        DATEDIFF(r.deadline, NOW()) as days_left,
        fr.faculty_id,
        u.email,
        u.first_name,
        u.last_name
    FROM requirements r
    JOIN faculty_requirements fr ON r.id = fr.requirement_id
    JOIN users u ON fr.faculty_id = u.id
    LEFT JOIN faculty_submissions fs ON r.id = fs.requirement_id AND fr.faculty_id = fs.faculty_id AND fs.status = 'approved'
    WHERE r.is_active = 1
        AND r.deadline IS NOT NULL
        AND r.deadline > NOW()
        AND DATEDIFF(r.deadline, NOW()) IN (1, 3, 7)
        AND fs.id IS NULL
        AND u.is_active = 1
");
$stmt->execute();
$pendingSubmissions = $stmt->fetchAll();

$remindersSent = 0;
foreach ($pendingSubmissions as $pending) {
    // Check if reminder already sent today
    $stmt = $db->prepare("
        SELECT id FROM notifications 
        WHERE user_id = ? 
            AND type = 'deadline_reminder' 
            AND message LIKE ? 
            AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute([$pending['faculty_id'], "%{$pending['title']}%"]);
    
    if ($stmt->rowCount() == 0) {
        // Send notification
        $notificationManager->notifyDeadlineApproaching(
            $pending['faculty_id'],
            $pending['title'],
            $pending['requirement_id'],
            $pending['days_left']
        );
        
        // Send email
        $mailer->sendDeadlineReminder(
            $pending['email'],
            $pending['first_name'] . ' ' . $pending['last_name'],
            $pending['title'],
            $pending['deadline'],
            $pending['days_left']
        );
        
        $remindersSent++;
        echo "  - Sent reminder to {$pending['first_name']} {$pending['last_name']} for '{$pending['title']}' ({$pending['days_left']} days left)\n";
    }
}
echo "  Total reminders sent: $remindersSent\n";

// 2. Send overdue notifications
echo "\n2. Checking overdue requirements...\n";

$stmt = $db->prepare("
    SELECT 
        r.id as requirement_id,
        r.title,
        r.deadline,
        fr.faculty_id,
        u.email,
        u.first_name,
        u.last_name
    FROM requirements r
    JOIN faculty_requirements fr ON r.id = fr.requirement_id
    JOIN users u ON fr.faculty_id = u.id
    LEFT JOIN faculty_submissions fs ON r.id = fs.requirement_id AND fr.faculty_id = fs.faculty_id AND fs.status IN ('approved', 'pending')
    WHERE r.is_active = 1
        AND r.deadline IS NOT NULL
        AND r.deadline < NOW()
        AND DATEDIFF(NOW(), r.deadline) = 1
        AND fs.id IS NULL
        AND u.is_active = 1
");
$stmt->execute();
$overdueSubmissions = $stmt->fetchAll();

$overdueSent = 0;
foreach ($overdueSubmissions as $overdue) {
    // Check if overdue notice already sent
    $stmt = $db->prepare("
        SELECT id FROM notifications 
        WHERE user_id = ? 
            AND type = 'deadline_reminder' 
            AND message LIKE ? 
            AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute([$overdue['faculty_id'], "%{$overdue['title']}%overdue%"]);
    
    if ($stmt->rowCount() == 0) {
        // Send overdue notification
        $notificationManager->createNotification(
            $overdue['faculty_id'],
            'deadline_reminder',
            '⚠️ Overdue Submission',
            "The deadline for '{$overdue['title']}' has passed. Please submit as soon as possible.",
            "requirements.php?id={$overdue['requirement_id']}",
            'high'
        );
        
        // Send email
        $mailer->sendOverdueNotice(
            $overdue['email'],
            $overdue['first_name'] . ' ' . $overdue['last_name'],
            $overdue['title'],
            $overdue['deadline']
        );
        
        $overdueSent++;
        echo "  - Sent overdue notice to {$overdue['first_name']} {$overdue['last_name']} for '{$overdue['title']}'\n";
    }
}
echo "  Total overdue notices sent: $overdueSent\n";

// 3. DTR reminders
echo "\n3. Checking DTR reminders...\n";
$dayOfMonth = (int) date('j');
$dtrDeadlineAdvanceSent = 0;
$dtrRemindersSent = 0;
$tableCheckDaily = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
$useDailyDTR = ($tableCheckDaily && $tableCheckDaily->rowCount() > 0);

// 3a. Daily DTR: remind employees who haven't submitted yesterday's DTR
if ($useDailyDTR) {
if ($tableCheckDaily && $tableCheckDaily->rowCount() > 0) {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $db->prepare("
        SELECT u.id, u.email, u.first_name, u.last_name, fp.employee_id
        FROM users u
        INNER JOIN faculty_profiles fp ON fp.user_id = u.id
        INNER JOIN attendance_logs al ON al.employee_id = fp.employee_id AND al.log_date = ?
        WHERE u.user_type IN ('faculty', 'staff') AND u.is_active = 1 AND u.is_verified = 1
        AND fp.employee_id IS NOT NULL AND fp.employee_id != ''
        AND NOT EXISTS (
            SELECT 1 FROM dtr_daily_submissions dds
            WHERE dds.user_id = u.id AND dds.log_date = ?
        )
    ");
    $stmt->execute([$yesterday, $yesterday]);
    $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $yesterdayLabel = date('F j, Y', strtotime($yesterday));
    foreach ($recipients as $r) {
        $mailer->sendDTRReminderEmail(
            $r['email'],
            trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
            $yesterdayLabel,
            $yesterdayLabel
        );
        $notificationManager->createNotification(
            $r['id'],
            'dtr_reminder',
            'DTR Submission Reminder',
            "Reminder: Please submit your DTR for " . $yesterdayLabel . " to the Dean and Admin. Submit each day's DTR the next day or after.",
            'view_logs.php',
            'normal'
        );
        $dtrRemindersSent++;
        echo "  - Sent daily DTR reminder to {$r['first_name']} {$r['last_name']} for {$yesterdayLabel}\n";
    }
    echo "  Total daily DTR reminders sent: $dtrRemindersSent\n";
}
if (!$useDailyDTR && in_array($dayOfMonth, [9, 24], true)) {
// 3b. Legacy period-based: DTR deadline advance notice (on 9th and 24th)
    $tableCheck = $db->query("SHOW TABLES LIKE 'dtr_submissions'");
    if ($tableCheck && $tableCheck->rowCount() > 0) {
        $period = ($dayOfMonth === 9) ? 1 : 2;
        $deadlineDay = $period === 1 ? 10 : 25;
        $year = (int) date('Y');
        $month = (int) date('n');
        $periodLabel = ($period === 1) ? '1st–15th' : '16th–25th';
        $deadlineDate = date('F j', mktime(0, 0, 0, $month, $deadlineDay, $year));
        $stmt = $db->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name
            FROM users u
            INNER JOIN faculty_profiles fp ON fp.user_id = u.id
            WHERE u.user_type IN ('faculty', 'staff') AND u.is_active = 1 AND u.is_verified = 1
            AND fp.employee_id IS NOT NULL AND fp.employee_id != ''
            AND NOT EXISTS (
                SELECT 1 FROM dtr_submissions ds
                WHERE ds.user_id = u.id AND ds.year = ? AND ds.month = ? AND ds.period = ?
            )
        ");
        $stmt->execute([$year, $month, $period]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recipients as $r) {
            $mailer->sendDTRDeadlineReminderEmail(
                $r['email'],
                trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                $periodLabel,
                $deadlineDate
            );
            $notificationManager->createNotification(
                $r['id'],
                'dtr_reminder',
                'DTR Submission Deadline Tomorrow',
                "Reminder: Tomorrow (" . $deadlineDate . ") is the deadline to submit your DTR for the period " . $periodLabel . " to the Dean and Admin.",
                'view_logs.php',
                'high'
            );
            $dtrDeadlineAdvanceSent++;
            echo "  - Sent DTR deadline reminder (tomorrow) to {$r['first_name']} {$r['last_name']} ({$periodLabel})\n";
        }
        echo "  Total DTR deadline advance emails sent: $dtrDeadlineAdvanceSent\n";
    }
}

// 3c. Legacy period-based: DTR submission deadline — on the day (10th and 25th)
if (!$useDailyDTR && in_array($dayOfMonth, [10, 25], true)) {
    $tableCheck = $db->query("SHOW TABLES LIKE 'dtr_submissions'");
    if ($tableCheck && $tableCheck->rowCount() > 0) {
        $period = ($dayOfMonth === 10) ? 1 : 2;
        $year = (int) date('Y');
        $month = (int) date('n');
        $periodLabel = ($period === 1) ? '1st–15th' : '16th–25th';
        $submitDate = date('F j', mktime(0, 0, 0, $month, $dayOfMonth));
        $stmt = $db->prepare("
            SELECT u.id, u.email, u.first_name, u.last_name
            FROM users u
            INNER JOIN faculty_profiles fp ON fp.user_id = u.id
            WHERE u.user_type IN ('faculty', 'staff') AND u.is_active = 1 AND u.is_verified = 1
            AND fp.employee_id IS NOT NULL AND fp.employee_id != ''
            AND NOT EXISTS (
                SELECT 1 FROM dtr_submissions ds
                WHERE ds.user_id = u.id AND ds.year = ? AND ds.month = ? AND ds.period = ?
            )
        ");
        $stmt->execute([$year, $month, $period]);
        $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($recipients as $r) {
            $mailer->sendDTRReminderEmail(
                $r['email'],
                trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
                $periodLabel,
                $submitDate
            );
            $notificationManager->createNotification(
                $r['id'],
                'dtr_reminder',
                'DTR Submission Deadline Today',
                "Today (" . $submitDate . ") is the deadline. Please submit your DTR for the period " . $periodLabel . " to the Dean and Admin. If you miss today, you will not be able to submit for this period.",
                'view_logs.php',
                'high'
            );
            $dtrRemindersSent++;
            echo "  - Sent DTR deadline (today) email to {$r['first_name']} {$r['last_name']} ({$periodLabel})\n";
        }
        echo "  Total DTR deadline (today) emails sent: $dtrRemindersSent\n";
    } else {
        echo "  Skipped (dtr_submissions table not found). Run migration 20260209_create_dtr_submissions_table.sql\n";
    }
}
if ($useDailyDTR && $dtrRemindersSent === 0) {
    echo "  No employees needed daily DTR reminder today.\n";
} elseif (!$useDailyDTR && !in_array($dayOfMonth, [9, 10, 24, 25], true)) {
    echo "  No DTR deadline email day today (legacy: 9th, 10th, 24th, 25th).\n";
}

// 4. Cleanup old read notifications (older than 30 days)
echo "\n4. Cleaning up old notifications...\n";
$cleaned = $notificationManager->cleanupOldNotifications(30);
echo "  Cleaned up old notifications\n";

// 5. Summary for admins (weekly digest on Mondays)
if (date('N') == 1) { // Monday
    echo "\n5. Sending weekly digest to admins...\n";
    
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT u.id) as total_faculty,
            COUNT(DISTINCT CASE WHEN fp.status = 'submitted' THEN fp.id END) as pending_pds,
            COUNT(DISTINCT CASE WHEN fs.status = 'pending' THEN fs.id END) as pending_submissions,
            COUNT(DISTINCT CASE WHEN r.deadline < NOW() AND r.is_active = 1 THEN r.id END) as overdue_requirements
        FROM users u
        LEFT JOIN faculty_pds fp ON u.id = fp.faculty_id
        LEFT JOIN faculty_submissions fs ON u.id = fs.faculty_id
        LEFT JOIN requirements r ON 1=1
        WHERE u.user_type = 'faculty' AND u.is_active = 1
    ");
    $stmt->execute();
    $summary = $stmt->fetch();
    
    // Get all admins
    $stmt = $db->prepare("SELECT id, email, first_name, last_name FROM users WHERE user_type IN ('admin', 'super_admin') AND is_active = 1");
    $stmt->execute();
    $admins = $stmt->fetchAll();
    
    foreach ($admins as $admin) {
        $message = "Weekly System Summary:\n\n";
        $message .= "• Total Active Faculty: {$summary['total_faculty']}\n";
        $message .= "• Pending PDS Reviews: {$summary['pending_pds']}\n";
        $message .= "• Pending Submissions: {$summary['pending_submissions']}\n";
        $message .= "• Overdue Requirements: {$summary['overdue_requirements']}\n";
        
        $notificationManager->createNotification(
            $admin['id'],
            'system',
            '📊 Weekly System Summary',
            $message,
            'analytics.php',
            'normal'
        );
        
        echo "  - Sent weekly digest to {$admin['first_name']} {$admin['last_name']}\n";
    }
}

echo "\n=== Notification Scheduler Completed at " . date('Y-m-d H:i:s') . " ===\n";
?>
