<?php
/**
 * Migration Runner: Add is_hidden field to notifications table
 * Date: 2025-11-07
 * 
 * Run this file once to add the is_hidden field to the notifications table.
 * This allows notifications to be hidden instead of deleted.
 * 
 * Usage: php run_20251107_migration.php
 * Or access via browser: http://your-site/FP/db/migrations/run_20251107_migration.php
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Starting migration: Add is_hidden field to notifications table...\n\n";
    
    // Check if column already exists
    $checkStmt = $db->query("SHOW COLUMNS FROM notifications LIKE 'is_hidden'");
    if ($checkStmt->rowCount() > 0) {
        echo "Column 'is_hidden' already exists. Migration may have already been run.\n";
        echo "Checking if index exists...\n";
        
        // Check if index exists
        $indexCheck = $db->query("SHOW INDEX FROM notifications WHERE Key_name = 'idx_is_hidden'");
        if ($indexCheck->rowCount() > 0) {
            echo "Index 'idx_is_hidden' already exists. Migration complete!\n";
            exit(0);
        }
    }
    
    // Read and execute the migration SQL file
    $sqlFile = __DIR__ . '/20251107_add_is_hidden_to_notifications.sql';
    if (!file_exists($sqlFile)) {
        die("Error: Migration SQL file not found: $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', 
            explode(';', $sql)
        )
    );
    
    // Execute each statement
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $db->exec($statement);
                echo "✓ Executed: " . substr($statement, 0, 80) . "...\n";
            } catch (PDOException $e) {
                // If column already exists, that's okay
                if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                    echo "⚠ Column already exists, skipping...\n";
                } elseif (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                    echo "⚠ Index already exists, skipping...\n";
                } else {
                    throw $e;
                }
            }
        }
    }
    
    echo "\n✓ Migration completed successfully!\n";
    echo "Notifications can now be hidden instead of deleted.\n";
    
} catch (PDOException $e) {
    die("✗ Migration failed: " . $e->getMessage() . "\n");
} catch (Exception $e) {
    die("✗ Error: " . $e->getMessage() . "\n");
}
?>
