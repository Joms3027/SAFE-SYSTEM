<?php
/**
 * Migration Script: Create DTR Submissions Table
 * Run once so employees can submit DTR to Dean and Admin on the 10th and 25th of each month.
 * Run from project root: php db/migrations/run_dtr_submissions_migration.php
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    echo "Running DTR submissions migration...\n";

    $stmt = $db->query("SHOW TABLES LIKE 'dtr_submissions'");
    if ($stmt->rowCount() > 0) {
        echo "Table dtr_submissions already exists.\n";
        exit(0);
    }

    $sqlFile = __DIR__ . '/20260209_create_dtr_submissions_table.sql';
    if (!file_exists($sqlFile)) {
        die("Migration file not found: $sqlFile\n");
    }

    $sql = file_get_contents($sqlFile);
    $db->exec($sql);
    echo "Successfully created dtr_submissions table.\n";
    echo "Employees can now submit DTR from View Attendance Logs on the 10th (1st–15th) and 25th (16th–25th) of each month.\n";
    echo "Set up cron to run includes/notification_scheduler.php daily for email reminders on 10th and 25th.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
