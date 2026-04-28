<?php
/**
 * Migration: Add device token columns to stations table
 * Simple version that doesn't rely on config.php
 */

echo "Starting migration...\n";

// Database credentials - same as in config.php
$host = 'localhost';
$dbname = 'wpu_faculty_system';
$user = 'root';
$pass = '';

$exitCode = 0;
$db = null;
try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);

    echo "Connected to database.\n";
    
    // Check and add mac_address column
    $check = $db->query("SHOW COLUMNS FROM stations LIKE 'mac_address'");
    if ($check->rowCount() == 0) {
        echo "Adding mac_address column...\n";
        $db->exec("ALTER TABLE stations ADD COLUMN mac_address VARCHAR(17) NULL COMMENT 'MAC address of device for station verification' AFTER pin");
        echo "mac_address column added.\n";
    } else {
        echo "mac_address column already exists.\n";
    }
    
    // Check and add device_token column
    $check = $db->query("SHOW COLUMNS FROM stations LIKE 'device_token'");
    if ($check->rowCount() == 0) {
        echo "Adding device_token column...\n";
        $db->exec("ALTER TABLE stations ADD COLUMN device_token VARCHAR(64) NULL COMMENT 'Unique token for device binding' AFTER mac_address");
        echo "device_token column added.\n";
    } else {
        echo "device_token column already exists.\n";
    }
    
    // Check and add device_fingerprint column
    $check = $db->query("SHOW COLUMNS FROM stations LIKE 'device_fingerprint'");
    if ($check->rowCount() == 0) {
        echo "Adding device_fingerprint column...\n";
        $db->exec("ALTER TABLE stations ADD COLUMN device_fingerprint TEXT NULL COMMENT 'Device fingerprint data' AFTER device_token");
        echo "device_fingerprint column added.\n";
    } else {
        echo "device_fingerprint column already exists.\n";
    }
    
    // Check and add last_device_ip column
    $check = $db->query("SHOW COLUMNS FROM stations LIKE 'last_device_ip'");
    if ($check->rowCount() == 0) {
        echo "Adding last_device_ip column...\n";
        $db->exec("ALTER TABLE stations ADD COLUMN last_device_ip VARCHAR(45) NULL COMMENT 'Last known device IP' AFTER device_fingerprint");
        echo "last_device_ip column added.\n";
    } else {
        echo "last_device_ip column already exists.\n";
    }
    
    // Check and add device_registered_at column
    $check = $db->query("SHOW COLUMNS FROM stations LIKE 'device_registered_at'");
    if ($check->rowCount() == 0) {
        echo "Adding device_registered_at column...\n";
        $db->exec("ALTER TABLE stations ADD COLUMN device_registered_at DATETIME NULL COMMENT 'When device was first registered' AFTER last_device_ip");
        echo "device_registered_at column added.\n";
    } else {
        echo "device_registered_at column already exists.\n";
    }
    
    echo "\nMigration completed successfully!\n";
    echo "Device registration columns are now available.\n";
    
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    $exitCode = 1;
} finally {
    $db = null;
}
exit($exitCode);
