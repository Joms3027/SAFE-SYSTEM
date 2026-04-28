<?php
/**
 * Add TARF workflow columns: supervisor → applicable endorser routing.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    echo "Running TARF workflow columns migration...\n";

    $tbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
    if (!$tbl || $tbl->rowCount() === 0) {
        echo "Table tarf_requests does not exist. Run run_tarf_requests_migration.php first.\n";
        exit(1);
    }

    $add = [
        'endorser_target_user_id' => 'INT(11) NULL DEFAULT NULL',
        'supervisor_endorsed_at' => 'DATETIME NULL DEFAULT NULL',
        'supervisor_endorsed_by' => 'INT(11) NULL DEFAULT NULL',
        'supervisor_comment' => 'TEXT NULL',
        'endorser_endorsed_at' => 'DATETIME NULL DEFAULT NULL',
        'endorser_endorsed_by' => 'INT(11) NULL DEFAULT NULL',
        'endorser_comment' => 'TEXT NULL',
        'rejected_at' => 'DATETIME NULL DEFAULT NULL',
        'rejected_by' => 'INT(11) NULL DEFAULT NULL',
        'rejection_reason' => 'TEXT NULL',
        'rejection_stage' => 'VARCHAR(24) NULL DEFAULT NULL',
    ];

    foreach ($add as $col => $def) {
        if (!preg_match('/^[a-z0-9_]+$/i', $col)) {
            continue;
        }
        $check = $db->query("SHOW COLUMNS FROM tarf_requests LIKE " . $db->quote($col));
        if ($check->rowCount() === 0) {
            $db->exec("ALTER TABLE tarf_requests ADD COLUMN `$col` $def");
            echo "Added column $col\n";
        } else {
            echo "Column $col already exists.\n";
        }
    }

    $db->exec("UPDATE tarf_requests SET status = 'pending_supervisor' WHERE status = 'pending'");
    echo "Normalized legacy status pending → pending_supervisor where applicable.\n";

    echo "Done.\n";
} catch (PDOException $e) {
    die('Migration failed: ' . $e->getMessage() . "\n");
}
