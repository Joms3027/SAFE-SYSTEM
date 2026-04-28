<?php
/**
 * Configuration Template
 * 
 * PORTABILITY: This system is designed to work on any computer/server!
 * 
 * To make this system portable:
 * 1. Copy this file to includes/config.php
 * 2. Update ONLY the database credentials below
 * 3. Everything else (paths, URLs) is auto-detected!
 * 
 * When moving to a new computer:
 * - Copy the entire FP folder
 * - Update database credentials below
 * - Import your database
 * - That's it! No path editing needed.
 * 
 * See PORTABILITY_GUIDE.md for detailed instructions.
 */

// ============================================
// DATABASE CONFIGURATION
// ============================================
// For production: Set environment variables (DB_HOST, DB_NAME, DB_USER, DB_PASS)
// For development: Edit the default values below
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: 'wpu_faculty_system');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');

// ============================================
// SITE CONFIGURATION
// ============================================
// ✅ AUTO-DETECTED: Site URL is automatically detected from server environment
// Usually no need to change - works on any computer/server automatically!
// 
// Optional override (only if auto-detection fails):
// - Set via environment variable: SITE_URL=http://your-domain.com/FP
// - Or uncomment and set manually (not recommended):
//   define('SITE_URL', 'http://localhost/FP');

// Site name (can be customized)
define('SITE_NAME', 'WPU Safe Profile System');

// ============================================
// FILE UPLOAD CONFIGURATION
// ============================================
// ✅ AUTO-DETECTED: Upload path uses relative path (dirname(__DIR__))
// This automatically works on any computer - no path editing needed!
// The path resolves to: [FP folder location]/uploads/
define('UPLOAD_PATH', dirname(__DIR__) . '/uploads/');
define('MAX_FILE_SIZE', 5242880); // 5MB (can be adjusted if needed)
define('ALLOWED_FILE_TYPES', ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);

// ============================================
// EMAIL CONFIGURATION
// ============================================
// SECURITY: Set SMTP credentials via environment variables in production!
// Environment variables: SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME
define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', getenv('SMTP_PORT') ? (int)getenv('SMTP_PORT') : 587);
define('SMTP_USER', getenv('SMTP_USER') ?: '');  // Set via environment variable
define('SMTP_PASS', getenv('SMTP_PASS') ?: '');  // Set via environment variable
define('SMTP_FROM', getenv('SMTP_FROM') ?: 'noreply@wpu.edu.ph');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'WPU Faculty System');

// ============================================
// OFFLINE MODE CONFIGURATION
// ============================================
// Set to true for fully offline operation (no internet required)
// When offline mode is enabled, email sending is automatically disabled
$offlineModeEnv = getenv('OFFLINE_MODE');
$offlineMode = $offlineModeEnv !== false ? filter_var($offlineModeEnv, FILTER_VALIDATE_BOOL) : false;
define('OFFLINE_MODE', $offlineMode);

// Enable or disable email sending
$enableMailEnv = getenv('ENABLE_MAIL');
if (OFFLINE_MODE) {
    $enableMail = false; // Force disable in offline mode
} else {
    $hasSmtpConfig = defined('SMTP_HOST') && SMTP_HOST && defined('SMTP_USER') && SMTP_USER;
    $enableMail = $enableMailEnv !== false ? filter_var($enableMailEnv, FILTER_VALIDATE_BOOL) : $hasSmtpConfig;
}
define('ENABLE_MAIL', $enableMail);

// ============================================
// SESSION CONFIGURATION
// ============================================
// Session settings (usually no change needed)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// ============================================
// ERROR REPORTING
// ============================================
// Development: Show all errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Production: Hide errors (uncomment for production)
// error_reporting(E_ALL);
// ini_set('display_errors', 0);
// ini_set('log_errors', 1);
// ini_set('error_log', dirname(__DIR__) . '/storage/logs/php_errors.log');

// ============================================
// AUTO-LOAD CLASSES
// ============================================
spl_autoload_register(function ($class_name) {
    $path = __DIR__ . '/classes/' . $class_name . '.php';
    if (file_exists($path)) {
        include $path;
    }
});

// Include mailer implementation
$mailerPath = __DIR__ . '/mailer.php';
if (file_exists($mailerPath)) {
    include_once $mailerPath;
}

