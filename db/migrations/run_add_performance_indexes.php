<?php
/**
 * Migration: Add performance optimization indexes for frequently queried columns.
 * Safe to run multiple times - uses IF NOT EXISTS where supported, skips duplicates otherwise.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

$sqlFile = __DIR__ . '/add_performance_indexes.sql';
if (!file_exists($sqlFile)) {
    die("Migration file not found: $sqlFile\n");
}

$sql = file_get_contents($sqlFile);
// Extract CREATE INDEX statements (skip comments and empty lines)
$lines = preg_split('/\r?\n/', $sql);
$statements = [];
$current = '';
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || strpos($line, '--') === 0) continue;
    $current .= ($current ? ' ' : '') . $line;
    if (substr(rtrim($line), -1) === ';') {
        $statements[] = trim($current);
        $current = '';
    }
}
if ($current !== '') $statements[] = trim($current);

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    echo "Running performance indexes migration...\n";
    $ok = 0;
    $skipped = 0;

    foreach ($statements as $stmt) {
        if (empty($stmt)) continue;
        try {
            $db->exec($stmt);
            $ok++;
            echo "  ✓ " . preg_replace('/^CREATE INDEX[^O]+ON\s+(\w+)[^;]+/', '$1', $stmt) . "\n";
        } catch (PDOException $e) {
            $msg = $e->getMessage();
            if (strpos($msg, 'Duplicate key name') !== false || strpos($msg, 'already exists') !== false || strpos($msg, '1061') !== false) {
                $skipped++;
            } else {
                echo "  ✗ " . $msg . "\n";
            }
        }
    }

    echo "\n✅ Done. Created: $ok, Skipped (already exist): $skipped\n";

} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
