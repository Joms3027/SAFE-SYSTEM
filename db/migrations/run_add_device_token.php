<?php
/**
 * Migration: Add device token columns to stations table
 * Date: 2025-12-04
 * 
 * This migration adds device registration columns for strict device locking
 */

// Create a log file for output
$logFile = __DIR__ . '/migration_log.txt';
file_put_contents($logFile, "Migration started at " . date('Y-m-d H:i:s') . "\n");

function log_msg($msg) {
    global $logFile;
    file_put_contents($logFile, $msg, FILE_APPEND);
    // Also try to output
    @fwrite(STDOUT, $msg);
    @fwrite(STDERR, $msg);
}

// Clear any output buffering for CLI
while (ob_get_level()) {
    ob_end_clean();
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/database.php';

// Clear any output buffering again after includes
while (ob_get_level()) {
    ob_end_clean();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    log_msg("Starting device token migration...\n");
    
    // Check and add mac_address column
    $check = $db->query("SHOW COLUMNS FROM stations LIKE 'mac_address'");
    if ($check->rowCount() == 0) {
        log_msg("Adding mac_address column...\n");
        $db->exec("ALTER TABLE stations ADD COLUMN mac_address VARCHAR(17) NULL COMMENT 'MAC address of device for station verification' AFTER pin");
        log_msg("mac_address column added.\n");
    } else {
        log_msg("mac_address column already exists.\n");
    }
    
    // Check and add device_token column
    $check = $db->query("SHOW COLUMNS FROM stations LIKE 'device_token'");
    if ($check->rowCount() == 0) {
        log_msg("Adding device_token column...\n");
        $db->exec("ALTER TABLE stations ADD COLUMN device_token VARCHAR(64) NULL COMMENT 'Unique token for device binding' AFTER mac_address");
        log_msg("device_token column added.\n");
    } else {
        log_msg("device_token column already exists.\n");
    }
    
    // Check and add device_fingerprint column
    $check = $db->query("SHOW COLUMNS FROM stations LIKE 'device_fingerprint'");
    if ($check->rowCount() == 0) {
        log_msg("Adding device_fingerprint column...\n");
        $db->exec("ALTER TABLE stations ADD COLUMN device_fingerprint TEXT NULL COMMENT 'Device fingerprint data' AFTER device_token");
        log_msg("device_fingerprint column added.\n");
    } else {
        log_msg("device_fingerprint column already exists.\n");
    }
    
    // Check and add last_device_ip column
    $check = $db->query("SHOW COLUMNS FROM stations LIKE 'last_device_ip'");
    if ($check->rowCount() == 0) {
        log_msg("Adding last_device_ip column...\n");
        $db->exec("ALTER TABLE stations ADD COLUMN last_device_ip VARCHAR(45) NULL COMMENT 'Last known device IP' AFTER device_fingerprint");
        log_msg("last_device_ip column added.\n");
    } else {
        log_msg("last_device_ip column already exists.\n");
    }
    
    // Check and add device_registered_at column
    $check = $db->query("SHOW COLUMNS FROM stations LIKE 'device_registered_at'");
    if ($check->rowCount() == 0) {
        log_msg("Adding device_registered_at column...\n");
        $db->exec("ALTER TABLE stations ADD COLUMN device_registered_at DATETIME NULL COMMENT 'When device was first registered' AFTER last_device_ip");
        log_msg("device_registered_at column added.\n");
    } else {
        log_msg("device_registered_at column already exists.\n");
    }
    
    log_msg("\nMigration completed successfully!\n");
    log_msg("Device registration columns are now available.\n");
    
} catch (Exception $e) {
    log_msg("Migration failed: " . $e->getMessage() . "\n");
    exit(1);
}

log_msg("\nFinished at " . date('Y-m-d H:i:s') . "\n");
