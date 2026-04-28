<?php
/**
 * Run migration: Create pardon_request_letters table.
 * Employees can submit pardon request letters (letter + day) to pardon openers.
 * Run from project root: php db/migrations/run_pardon_request_letters_migration.php
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->query("SHOW TABLES LIKE 'pardon_request_letters'");
if ($stmt && $stmt->rowCount() > 0) {
    echo "Table pardon_request_letters already exists.\n";
    exit(0);
}

$sql = file_get_contents(__DIR__ . '/20260314_create_pardon_request_letters_table.sql');
$db->exec($sql);
echo "Table pardon_request_letters created. Employees can now submit pardon request letters.\n";
