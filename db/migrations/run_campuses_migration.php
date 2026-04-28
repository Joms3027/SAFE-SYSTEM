<?php
/**
 * Migration Runner: Create campuses table
 * Run this file once to create the campuses table and populate it with default values.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Starting campuses table migration...\n";
    
    // Read the SQL file
    $sqlFile = __DIR__ . '/20251203_create_campuses_table.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Execute the migration
    $db->exec($sql);
    
    echo "Migration completed successfully!\n";
    echo "Campuses table created and populated with default values.\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>

