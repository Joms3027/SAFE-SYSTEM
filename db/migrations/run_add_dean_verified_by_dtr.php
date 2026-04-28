<?php
/**
 * Run migration: Add dean_verified_by to dtr_daily_submissions.
 * Run from project root: php db/migrations/run_add_dean_verified_by_dtr.php
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->query("SHOW COLUMNS FROM dtr_daily_submissions LIKE 'dean_verified_by'");
if ($stmt && $stmt->rowCount() > 0) {
    echo "Column dean_verified_by already exists.\n";
    exit(0);
}

$sqlFile = __DIR__ . '/20260314_add_dean_verified_by_to_dtr_daily_submissions.sql';
$sql = file_get_contents($sqlFile);
$db->exec($sql);
echo "Successfully added dean_verified_by to dtr_daily_submissions.\n";
