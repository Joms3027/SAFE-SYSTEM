<?php
/**
 * Hourly Database Backup Script
 *
 * Creates a full mysqldump backup of the database and retains backups for 48 hours.
 * Schedule via Windows Task Scheduler to run every hour.
 *
 * SETUP:
 * 1. Ensure MySQL bin is in system PATH, or set MYSQLDUMP_PATH in db/backup.env
 * 2. Set DB credentials via:
 *    - System environment variables (DB_HOST, DB_NAME, DB_USER, DB_PASS), OR
 *    - db/backup.env file (copy from db/backup.env.example)
 * 3. Schedule: cron/run_backup_database.bat via Task Scheduler (hourly)
 */

// Run from project root
$projectRoot = dirname(__DIR__);
chdir($projectRoot);

// Load backup.env if it exists (for Task Scheduler when system env vars aren't available)
$backupEnv = $projectRoot . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'backup.env';
if (file_exists($backupEnv) && is_readable($backupEnv)) {
    $lines = file($backupEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#' && strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if ($key !== '') {
                putenv("$key=$val");
            }
        }
    }
}

// Get DB credentials from environment
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'wpu_faculty_system';
$dbUser = getenv('DB_USER') ?: '';
$dbPass = getenv('DB_PASS') ?: '';

if (empty($dbUser)) {
    fwrite(STDERR, "ERROR: DB_USER not set. Set DB_HOST, DB_NAME, DB_USER, DB_PASS via environment or db/backup.env\n");
    exit(1);
}

// Backup directory (inside db/ to keep backups with schema)
$backupDir = $projectRoot . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'backups';
if (!is_dir($backupDir)) {
    if (!@mkdir($backupDir, 0755, true)) {
        fwrite(STDERR, "ERROR: Cannot create backup directory: $backupDir\n");
        exit(1);
    }
}

// mysqldump path - check env first, then common Windows locations
$mysqldumpPath = getenv('MYSQLDUMP_PATH');
if (empty($mysqldumpPath)) {
    $candidates = [
        'mysqldump',  // Assume in PATH
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe',
    ];
    foreach ($candidates as $candidate) {
        if ($candidate === 'mysqldump') {
            $out = [];
            exec('where mysqldump 2>nul', $out);
            if (!empty($out)) {
                $mysqldumpPath = 'mysqldump';
                break;
            }
        } elseif (file_exists($candidate)) {
            $mysqldumpPath = $candidate;
            break;
        }
    }
}
if (empty($mysqldumpPath)) {
    $mysqldumpPath = 'mysqldump';  // Last resort - hope it's in PATH
}

// Timestamp for filename
$timestamp = date('Y-m-d_H-i');
$backupFile = $backupDir . DIRECTORY_SEPARATOR . 'wpu_faculty_' . $timestamp . '.sql';

// Use temp config file for credentials (avoids shell escaping issues with special chars in password)
$tmpConfig = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mysql_backup_' . uniqid('', true) . '.cnf';
$cnfContent = "[client]\nuser=" . $dbUser . "\npassword=" . $dbPass . "\nhost=" . $dbHost . "\n";
file_put_contents($tmpConfig, $cnfContent);
register_shutdown_function(function () use ($tmpConfig) {
    if (file_exists($tmpConfig)) {
        @unlink($tmpConfig);
    }
});

// Build mysqldump command (escape path for spaces in "Program Files")
$mysqldumpEscaped = ($mysqldumpPath === 'mysqldump') ? 'mysqldump' : escapeshellarg($mysqldumpPath);
$defaultsArg = '--defaults-extra-file=' . escapeshellarg($tmpConfig);
$cmd = $mysqldumpEscaped . ' ' . $defaultsArg . ' --single-transaction --routines --triggers --events ' . escapeshellarg($dbName) . ' > ' . escapeshellarg($backupFile);

// Run backup
$errOutput = [];
$returnVar = 0;
exec($cmd, $errOutput, $returnVar);
@unlink($tmpConfig);

if ($returnVar !== 0 || !file_exists($backupFile) || filesize($backupFile) < 100) {
    fwrite(STDERR, "ERROR: Backup failed. Return code: $returnVar\n");
    if (!empty($errOutput)) {
        fwrite(STDERR, implode("\n", $errOutput) . "\n");
    }
    if (file_exists($backupFile)) {
        @unlink($backupFile);
    }
    exit(1);
}

// Retention: delete backups older than 48 hours
$retentionHours = (int)(getenv('BACKUP_RETENTION_HOURS') ?: 48);
$cutoff = time() - ($retentionHours * 3600);
$deleted = 0;
$files = glob($backupDir . DIRECTORY_SEPARATOR . 'wpu_faculty_*.sql');
foreach ($files as $f) {
    if (filemtime($f) < $cutoff) {
        if (@unlink($f)) {
            $deleted++;
        }
    }
}

// Log success (optional - to a log file)
$logFile = $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'backup.log';
$logDir = dirname($logFile);
if (is_dir($logDir) && is_writable($logDir)) {
    $logLine = date('Y-m-d H:i:s') . " Backup OK: " . basename($backupFile) . " (" . number_format(filesize($backupFile) / 1024, 1) . " KB)";
    if ($deleted > 0) {
        $logLine .= " - Deleted $deleted old backup(s)";
    }
    $logLine .= "\n";
    @file_put_contents($logFile, $logLine, FILE_APPEND | LOCK_EX);
}

exit(0);
