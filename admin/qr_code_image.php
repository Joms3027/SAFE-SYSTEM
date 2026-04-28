<?php
/**
 * QR Code Image Proxy (Admin Version)
 * Serves QR code images for admin users
 * 
 * IMPORTANT: This script handles authentication failures gracefully to prevent
 * broken image issues when session expires or is not synchronized (e.g., dev tunnels)
 */

// Disable error display to prevent corrupting image output
ini_set('display_errors', 0);
error_reporting(0);

require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Initialize session variables (must be done before any session manipulation)
$sessionUserId = null;
$sessionUserType = null;

// Get session data and release lock early to prevent blocking other requests
// This is especially important for parallel image loading on mobile
if (session_status() === PHP_SESSION_ACTIVE) {
    // Store session data we need before closing
    $sessionUserId = $_SESSION['user_id'] ?? null;
    $sessionUserType = $_SESSION['user_type'] ?? null;
    // Release the session lock to allow parallel requests
    session_write_close();
} else {
    // Session not active - try to access session data directly if available
    // This can happen on some mobile browsers with strict cookie policies
    error_log("Admin QR code: Session not active. Status: " . session_status() . ", trying to start session.");
    
    // Try to start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        $sessionUserId = $_SESSION['user_id'] ?? null;
        $sessionUserType = $_SESSION['user_type'] ?? null;
        session_write_close();
    }
}

// For image requests, we need to handle authentication failures gracefully
// Instead of redirecting to login.php (which returns HTML), we return proper HTTP errors

// Check if user is logged in - do NOT use requireAdmin() as it redirects
if (empty($sessionUserId)) {
    error_log("Admin QR code access denied: No session user_id. Session status: " . session_status() . ", Cookie: " . (isset($_COOKIE[session_name()]) ? 'present' : 'missing'));
    http_response_code(401);
    header('Content-Type: text/plain');
    die('Authentication required');
}

// Check if user is admin
if ($sessionUserType !== 'admin' && $sessionUserType !== 'super_admin') {
    error_log("Admin QR code access denied: Invalid user type '$sessionUserType' for user $sessionUserId");
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Access denied');
}

$database = Database::getInstance();
$db = $database->getConnection();

// Get user ID from query parameter
$requestedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if (!$requestedUserId) {
    http_response_code(400);
    header('Content-Type: text/plain');
    die('User ID required');
}

// Get QR code path and employee ID from database
$stmt = $db->prepare("SELECT qr_code, employee_id FROM faculty_profiles WHERE user_id = ?");
$stmt->execute([$requestedUserId]);
$result = $stmt->fetch();

if (!$result || empty($result['qr_code'])) {
    error_log("Admin QR code not found in database for user_id: $requestedUserId");
    http_response_code(404);
    header('Content-Type: text/plain');
    die('QR code not found');
}

$qrCodePath = $result['qr_code'];
$employeeId = $result['employee_id'] ?? $requestedUserId; // Fallback to user ID if no employee ID
$fullPath = UPLOAD_PATH . $qrCodePath;

// Check if file exists
if (!file_exists($fullPath)) {
    error_log("Admin QR code file not found: $fullPath for user_id: $requestedUserId (db path: $qrCodePath)");
    http_response_code(404);
    header('Content-Type: text/plain');
    die('QR code file not found');
}

// Determine MIME type based on file extension
$extension = strtolower(pathinfo($qrCodePath, PATHINFO_EXTENSION));
$mimeTypes = [
    'png' => 'image/png',
    'svg' => 'image/svg+xml',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif'
];

$mimeType = $mimeTypes[$extension] ?? 'image/png';

// Check if this is a download request
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';

// Set headers to prevent caching issues and ensure proper display
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));

if ($isDownload) {
    // For downloads, set attachment header with employee ID in filename
    $safeEmployeeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $employeeId); // Sanitize for filename
    $filename = 'QR_Code_' . $safeEmployeeId . '.' . $extension;
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
} else {
    // For display, set cache headers
    header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
    header('Pragma: public');
}

// Disable output buffering for better performance
if (ob_get_level()) {
    ob_end_clean();
}

// Output the file
readfile($fullPath);
exit();
