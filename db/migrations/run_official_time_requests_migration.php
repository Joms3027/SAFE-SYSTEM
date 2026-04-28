<?php
/**
 * Migration: Create official_time_requests table for employee-declared official time workflow.
 * Faculty: declare → dean endorses → super_admin approves/rejects.
 * Staff: declare → super_admin approves/rejects.
 * Approved requests become working time (copied to employee_official_times).
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    echo "Running official_time_requests migration...\n";

    $stmt = $db->query("SHOW TABLES LIKE 'official_time_requests'");
    if ($stmt->rowCount() > 0) {
        echo "✅ Table official_time_requests already exists. Skipping.\n";
        exit(0);
    }

    $sqlFile = __DIR__ . '/20250311_create_official_time_requests_table.sql';
    if (!file_exists($sqlFile)) {
        die("Migration file not found: $sqlFile\n");
    }

    $sql = file_get_contents($sqlFile);
    $db->exec($sql);
    echo "✅ Successfully created official_time_requests table.\n";
    echo "Next: Employees declare official time; faculty go through dean then super_admin; staff go directly to super_admin.\n";

} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
