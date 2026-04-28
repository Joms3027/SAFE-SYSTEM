<?php
/**
 * Add TARF President (final) approval columns on tarf_requests.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    echo "Running TARF president columns migration...\n";

    $tbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
    if (!$tbl || $tbl->rowCount() === 0) {
        echo "Table tarf_requests does not exist.\n";
        exit(1);
    }

    $add = [
        'president_endorsed_at' => 'DATETIME NULL DEFAULT NULL',
        'president_endorsed_by' => 'INT(11) NULL DEFAULT NULL',
        'president_comment' => 'TEXT NULL',
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

    echo "Done.\n";
} catch (PDOException $e) {
    die('Migration failed: ' . $e->getMessage() . "\n");
}
