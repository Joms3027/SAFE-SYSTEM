<?php
/**
 * Migration Script: Add QR Code Column to Faculty Profiles
 * Run this script to add the qr_code column to the faculty_profiles table
 */

require_once '../../includes/config.php';
require_once '../../includes/database.php';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    echo "Running QR code migration...\n";
    
    // Read migration SQL file
    $sqlFile = __DIR__ . '/20251117_add_qr_code_to_faculty_profiles.sql';
    if (!file_exists($sqlFile)) {
        die("Migration file not found: $sqlFile\n");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Execute migration (handle IF NOT EXISTS for MySQL compatibility)
    // For MySQL < 8.0, we need to check if column exists first
    $stmt = $db->query("SHOW COLUMNS FROM faculty_profiles LIKE 'qr_code'");
    $columnExists = $stmt->rowCount() > 0;
    
    if (!$columnExists) {
        // Use standard ALTER TABLE (without IF NOT EXISTS for compatibility)
        $db->exec("ALTER TABLE faculty_profiles ADD COLUMN qr_code VARCHAR(255) NULL COMMENT 'Path to QR code image file for attendance'");
        echo "✅ Successfully added qr_code column to faculty_profiles table.\n";
    } else {
        echo "✅ Column qr_code already exists in faculty_profiles table. Skipping.\n";
    }
    
    // Create uploads/qr_codes directory if it doesn't exist
    $qrCodesDir = dirname(__DIR__) . '/../uploads/qr_codes/';
    if (!is_dir($qrCodesDir)) {
        mkdir($qrCodesDir, 0755, true);
        echo "✅ Created uploads/qr_codes directory.\n";
    } else {
        echo "✅ uploads/qr_codes directory already exists.\n";
    }
    
    echo "\nMigration completed successfully!\n";
    echo "Next steps:\n";
    echo "1. Run 'composer require endroid/qr-code' to install the QR code library\n";
    echo "2. QR codes will be automatically generated when admin creates new faculty/staff accounts\n";
    echo "3. Existing faculty/staff can view and download their QR codes from their profile page\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

