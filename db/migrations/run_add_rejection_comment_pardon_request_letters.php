<?php
/**
 * Add rejection_comment column to pardon_request_letters.
 * Run from project root: php db/migrations/run_add_rejection_comment_pardon_request_letters.php
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->query("SHOW COLUMNS FROM pardon_request_letters LIKE 'rejection_comment'");
if ($stmt->rowCount() > 0) {
    echo "Column rejection_comment already exists.\n";
    exit(0);
}

$sql = file_get_contents(__DIR__ . '/20260315_add_rejection_comment_pardon_request_letters.sql');
$db->exec($sql);
echo "Added rejection_comment column to pardon_request_letters.\n";
