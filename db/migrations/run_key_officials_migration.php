<?php
/**
 * Migration Runner: Create key_officials table
 * Run this file once to create the key_officials master list table.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Starting key_officials table migration...\n";
    
    $sqlFile = __DIR__ . '/20260306_create_key_officials_table.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    $db->exec($sql);
    
    echo "Migration completed successfully!\n";
    echo "Key officials table created. Go to Admin > Settings > Manage Master Lists to add key officials.\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
