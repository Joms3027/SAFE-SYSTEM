<?php
/**
 * Migration Runner: Create absent_cleared table
 * Run this file once to create the absent_cleared table.
 */

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    $sql = file_get_contents(__DIR__ . '/20260308_create_absent_cleared_table.sql');
    $db->exec($sql);
    echo "absent_cleared table created successfully.\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
