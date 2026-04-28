<?php
/**
 * Add 'rejected' status to pardon_request_letters.
 * Run from project root: php db/migrations/run_add_rejected_status_pardon_request_letters.php
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->query("SHOW COLUMNS FROM pardon_request_letters LIKE 'status'");
$col = $stmt->fetch(PDO::FETCH_ASSOC);
if ($col && stripos($col['Type'], "'rejected'") !== false) {
    echo "Status 'rejected' already exists.\n";
    exit(0);
}

$sql = file_get_contents(__DIR__ . '/20260314_add_rejected_status_pardon_request_letters.sql');
$db->exec($sql);
echo "Added 'rejected' status to pardon_request_letters.\n";
