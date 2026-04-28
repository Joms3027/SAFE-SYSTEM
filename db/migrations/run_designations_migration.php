<?php
/**
 * Migration Runner for Designations
 * This script creates the designations table and adds the designation field to faculty_profiles
 * 
 * Run this file once to set up designations functionality
 */

require_once '../../includes/config.php';
require_once '../../includes/database.php';

$database = Database::getInstance();
$db = $database->getConnection();

echo "Starting Designations Migration...\n\n";

try {
    // Run migration 1: Create designations table
    echo "1. Creating designations table...\n";
    $sql1 = file_get_contents(__DIR__ . '/20251203_create_designations_table.sql');
    $db->exec($sql1);
    echo "✓ Designations table created successfully!\n\n";
    
    // Run migration 2: Add designation column to faculty_profiles
    echo "2. Adding designation column to faculty_profiles...\n";
    $sql2 = file_get_contents(__DIR__ . '/20251203_add_designation_to_faculty_profiles.sql');
    $db->exec($sql2);
    echo "✓ Designation column added to faculty_profiles!\n\n";
    
    echo "===========================================\n";
    echo "Migration completed successfully! ✓\n";
    echo "===========================================\n";
    echo "\nYou can now:\n";
    echo "1. Go to Admin > Settings to manage designations (Dean, Program Chair, etc.)\n";
    echo "2. Assign designations when creating faculty accounts\n";
    echo "3. Use designations in batch uploads\n\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
    echo "If the error mentions that the table/column already exists, the migration has already been run.\n";
}
?>

