<?php
/**
 * Migration: tarf_requests for employee Travel Activity Request Form submissions.
 */
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    echo "Running tarf_requests migration...\n";

    $stmt = $db->query("SHOW TABLES LIKE 'tarf_requests'");
    if ($stmt->rowCount() > 0) {
        echo "Table tarf_requests already exists. Skipping.\n";
        exit(0);
    }

    $sqlFile = __DIR__ . '/20260416_create_tarf_requests_table.sql';
    if (!file_exists($sqlFile)) {
        die("Migration file not found: $sqlFile\n");
    }

    $sql = file_get_contents($sqlFile);
    $db->exec($sql);
    echo "Successfully created tarf_requests table.\n";
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
