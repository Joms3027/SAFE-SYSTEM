<?php
/**
 * One-time migration: Add step column to position_salary table.
 * Run this once by visiting: /admin/run_position_step_migration.php
 * Requires admin login.
 */
require_once '../includes/config.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

header('Content-Type: text/html; charset=utf-8');

try {
    // Check if column already exists
    $colCheck = $db->query("SHOW COLUMNS FROM position_salary LIKE 'step'");
    if ($colCheck && $colCheck->rowCount() > 0) {
        echo '<p style="color:green;"><strong>Step column already exists.</strong> No action needed.</p>';
        echo '<p><a href="positions.php">Back to Positions</a></p>';
        exit;
    }

    $db->exec("ALTER TABLE position_salary ADD COLUMN step INT DEFAULT 1 AFTER salary_grade");
    echo '<p style="color:green;"><strong>Migration successful!</strong> Step column has been added to position_salary.</p>';
    echo '<p><a href="positions.php">Go to Positions</a></p>';
} catch (Exception $e) {
    echo '<p style="color:red;"><strong>Migration failed:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p><a href="positions.php">Back to Positions</a></p>';
}
