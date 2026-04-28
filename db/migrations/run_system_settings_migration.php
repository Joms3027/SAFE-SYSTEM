<?php
/**
 * Migration Script: Create System Settings Table
 * Run this script to create the system_settings table and set default pardon limit
 */

require_once '../../includes/config.php';
require_once '../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Running system settings migration...\n";
    
    // Read migration SQL file
    $sqlFile = __DIR__ . '/20260205_create_system_settings_table.sql';
    if (!file_exists($sqlFile)) {
        die("Migration file not found: $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Check if table already exists
    $stmt = $db->query("SHOW TABLES LIKE 'system_settings'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        // Execute migration
        $db->exec($sql);
        echo "✅ Successfully created system_settings table.\n";
    } else {
        echo "✅ Table system_settings already exists.\n";
        // Still run the INSERT to ensure default value exists
        $insertSql = "INSERT INTO system_settings (setting_key, setting_value, description) 
                      VALUES ('pardon_weekly_limit', '3', 'Maximum number of pardon requests allowed per employee per week (3 or 5)')
                      ON DUPLICATE KEY UPDATE setting_value = setting_value";
        try {
            $db->exec($insertSql);
            echo "✅ Default pardon limit setting ensured.\n";
        } catch (PDOException $e) {
            echo "⚠️  Note: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\nMigration completed successfully!\n";
    echo "Next steps:\n";
    echo "1. Go to Admin > Settings to configure the pardon weekly limit (3 or 5 times per week)\n";
    echo "2. Employees will be limited to the configured number of approved pardons per week\n";
    echo "3. Once limit is reached, employees must wait until the next week to submit new requests\n";
    
} catch (PDOException $e) {
    die("Migration failed: " . $e->getMessage() . "\n");
}
?>
