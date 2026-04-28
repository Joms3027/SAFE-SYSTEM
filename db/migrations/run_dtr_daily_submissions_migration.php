<?php
/**
 * Run migration: Create dtr_daily_submissions table for daily DTR submission.
 * Employees submit each day's DTR the next day or after. Attendance is only visible to dean/admin after submission.
 * Run from project root: php db/migrations/run_dtr_daily_submissions_migration.php
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
if ($stmt && $stmt->rowCount() > 0) {
    echo "Table dtr_daily_submissions already exists.\n";
    exit(0);
}

$sqlFile = __DIR__ . '/20260224_create_dtr_daily_submissions.sql';
$sql = file_get_contents($sqlFile);
if ($sql === false) {
    echo "Error: Could not read migration file.\n";
    exit(1);
}

try {
    $db->exec($sql);
    echo "Successfully created dtr_daily_submissions table.\n";
    echo "Employees can now submit DTR daily (each day's logs the next day or after).\n";
    echo "Attendance data is only visible to Dean and Admin after the employee submits.\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
