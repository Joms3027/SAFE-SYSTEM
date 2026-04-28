<?php
/**
 * Migration Runner: Add key_official column to faculty_profiles
 * Run this file once after key_officials table exists.
 */

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Adding key_official column to faculty_profiles...\n";
    
    $stmt = $db->query("SHOW COLUMNS FROM faculty_profiles LIKE 'key_official'");
    if ($stmt->rowCount() > 0) {
        echo "Column key_official already exists. Skipping.\n";
        exit(0);
    }
    
    $db->exec("ALTER TABLE faculty_profiles ADD COLUMN key_official VARCHAR(150) DEFAULT NULL AFTER designation");
    
    echo "Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
