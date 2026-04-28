<?php
/**
 * Migration Runner: Change employment_status from ENUM to VARCHAR
 * Date: 2025-11-19
 * 
 * Run this file once to change the employment_status column from ENUM to VARCHAR
 * to match the employment_statuses master table.
 * 
 * Usage: php run_20251119_employment_status_migration.php
 * Or access via browser: http://your-site/FP/db/migrations/run_20251119_employment_status_migration.php
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Starting migration: Change employment_status from ENUM to VARCHAR...\n\n";
    
    // Check current column type
    $checkStmt = $db->query("SHOW COLUMNS FROM faculty_profiles WHERE Field = 'employment_status'");
    $columnInfo = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($columnInfo) {
        echo "Current column type: " . $columnInfo['Type'] . "\n";
        
        // Check if it's already VARCHAR
        if (stripos($columnInfo['Type'], 'varchar') !== false) {
            echo "✓ Column is already VARCHAR. Migration may have already been run.\n";
            exit(0);
        }
    } else {
        die("Error: employment_status column not found in faculty_profiles table.\n");
    }
    
    // Read and execute the migration SQL file
    $sqlFile = __DIR__ . '/20251119_change_employment_status_to_varchar.sql';
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
        if (!empty($statement) && stripos($statement, 'ALTER TABLE') !== false) {
            try {
                $db->exec($statement);
                echo "✓ Executed: " . substr($statement, 0, 80) . "...\n";
            } catch (PDOException $e) {
                // Check for specific error messages
                if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                    echo "⚠ Column already exists with this type, skipping...\n";
                } else {
                    throw $e;
                }
            }
        }
    }
    
    // Verify the change
    $verifyStmt = $db->query("SHOW COLUMNS FROM faculty_profiles WHERE Field = 'employment_status'");
    $verifyInfo = $verifyStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($verifyInfo && stripos($verifyInfo['Type'], 'varchar') !== false) {
        echo "\n✓ Migration completed successfully!\n";
        echo "✓ Column type changed to: " . $verifyInfo['Type'] . "\n";
        echo "\nThe employment_status column can now store any value from the employment_statuses master table.\n";
    } else {
        echo "\n⚠ Warning: Column type may not have changed. Please verify manually.\n";
    }
    
} catch (PDOException $e) {
    die("✗ Migration failed: " . $e->getMessage() . "\n");
} catch (Exception $e) {
    die("✗ Error: " . $e->getMessage() . "\n");
}
?>

