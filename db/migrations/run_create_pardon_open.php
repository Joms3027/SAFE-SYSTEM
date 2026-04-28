<?php
/**
 * Run migration: Create pardon_open table.
 * Pardon button in faculty/view_logs is disabled until the dean opens pardon for that date.
 * Run from project root: php db/migrations/run_create_pardon_open.php
 */
require_once __DIR__ . '/../../includes/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->query("SHOW TABLES LIKE 'pardon_open'");
if ($stmt && $stmt->rowCount() > 0) {
    echo "Table pardon_open already exists.\n";
    exit(0);
}

$sql = file_get_contents(__DIR__ . '/20260227_create_pardon_open_table.sql');
$db->exec($sql);
echo "Table pardon_open created. Deans can now open pardon for specific dates in Department DTR; employees can submit pardon only when opened.\n";
