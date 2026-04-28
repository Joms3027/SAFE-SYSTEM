<?php
/**
 * Migration: Add PIN column to stations table
 * Date: 2025-12-04
 * 
 * This migration adds a PIN column to the stations table for station-based authentication
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Starting migration: add_station_pin_column\n";
    
    // Check if column already exists
    $checkStmt = $db->query("SHOW COLUMNS FROM stations LIKE 'pin'");
    if ($checkStmt->rowCount() > 0) {
        echo "PIN column already exists. Skipping migration.\n";
        exit(0);
    }
    
    // Add PIN column
    echo "Adding PIN column to stations table...\n";
    $db->exec("ALTER TABLE stations 
               ADD COLUMN pin VARCHAR(255) NULL COMMENT 'Hashed PIN for station access' 
               AFTER department_id");
    
    // Update existing stations with a default hashed PIN
    // Default PIN: "1234" (hashed with PASSWORD_DEFAULT)
    echo "Setting default PIN for existing stations...\n";
    $defaultPin = password_hash('1234', PASSWORD_DEFAULT);
    $updateStmt = $db->prepare("UPDATE stations SET pin = ? WHERE pin IS NULL");
    $updateStmt->execute([$defaultPin]);
    
    echo "Updated " . $updateStmt->rowCount() . " stations with default PIN.\n";
    
    // Make PIN NOT NULL
    echo "Making PIN column NOT NULL...\n";
    $db->exec("ALTER TABLE stations MODIFY COLUMN pin VARCHAR(255) NOT NULL");
    
    echo "Migration completed successfully!\n";
    echo "\nIMPORTANT: Default PIN is '1234' for all stations. Please update PINs through the admin interface.\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}

