<?php
/**
 * Migration Runner: Create password_reset_tokens table
 * Run this file once to create the password_reset_tokens table for password reset functionality.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Starting password_reset_tokens table migration...\n";
    
    // Read the SQL file
    $sqlFile = __DIR__ . '/create_password_reset_tokens_table.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: $sqlFile");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Check if table already exists
    $stmt = $db->query("SHOW TABLES LIKE 'password_reset_tokens'");
    $tableExists = $stmt->rowCount() > 0;
    
    if (!$tableExists) {
        // Execute the migration
        $db->exec($sql);
        echo "✅ Successfully created password_reset_tokens table.\n";
    } else {
        echo "✅ Table password_reset_tokens already exists. Skipping.\n";
    }
    
    echo "\nMigration completed successfully!\n";
    echo "Password reset functionality is now available.\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
