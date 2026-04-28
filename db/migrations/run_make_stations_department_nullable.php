<?php
/**
 * Migration: Make stations.department_id nullable for open stations
 * Date: 2025-12-04
 * 
 * This migration makes department_id nullable so stations can be open (not tied to a department)
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Starting migration: make_stations_department_nullable\n";
    
    // Step 1: Drop foreign key constraint if it exists
    echo "Dropping foreign key constraint if it exists...\n";
    try {
        $db->exec("ALTER TABLE stations DROP FOREIGN KEY fk_stations_department");
        echo "Foreign key constraint dropped.\n";
    } catch (Exception $e) {
        // Constraint might not exist, that's okay
        echo "Foreign key constraint does not exist or already removed.\n";
    }
    
    // Step 2: Make department_id nullable
    echo "Making department_id nullable...\n";
    $db->exec("ALTER TABLE stations MODIFY COLUMN department_id int(11) NULL COMMENT 'Department ID (NULL for open stations)'");
    
    echo "Migration completed successfully!\n";
    echo "Stations can now be created without a department (open stations).\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
