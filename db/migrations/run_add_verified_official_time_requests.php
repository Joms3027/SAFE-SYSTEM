<?php
/**
 * Run migration: Add verified_at and verified_by to official_time_requests.
 * Run from project root: php db/migrations/run_add_verified_official_time_requests.php
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->query("SHOW COLUMNS FROM official_time_requests LIKE 'verified_at'");
if ($stmt && $stmt->rowCount() > 0) {
    echo "Column verified_at already exists. Skipping.\n";
    exit(0);
}

$sqlFile = __DIR__ . '/20260314_add_verified_to_official_time_requests.sql';
$sql = file_get_contents($sqlFile);
$db->exec($sql);
echo "Successfully added verified_at and verified_by to official_time_requests.\n";
