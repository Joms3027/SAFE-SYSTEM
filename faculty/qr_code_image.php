<?php
/**
 * QR Code Image Proxy
 * Serves QR code images to avoid ERR_BLOCKED_BY_CLIENT errors from ad blockers
 * 
 * IMPORTANT: This script handles authentication failures gracefully to prevent
 * broken image issues when session expires or is not synchronized (e.g., dev tunnels)
 */

// Disable error display to prevent corrupting image output
ini_set('display_errors', 0);
error_reporting(0);

// Start output buffering early to catch any stray output from included files
if (!ob_get_level()) {
    ob_start();
}

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
    error_log("QR code: Session not active. Status: " . session_status() . ", trying to start session.");
    
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

// Check if user is logged in - do NOT use requireFaculty() as it redirects
if (empty($sessionUserId)) {
    // Log for debugging
    error_log("QR code access denied: No session user_id. Session status: " . session_status() . ", Cookie: " . (isset($_COOKIE[session_name()]) ? 'present' : 'missing'));
    http_response_code(401);
    header('Content-Type: text/plain');
    die('Authentication required');
}

// Check if user is faculty/staff (allow admin too for flexibility)
$validUserTypes = ['faculty', 'staff', 'admin'];
if (!in_array($sessionUserType, $validUserTypes)) {
    error_log("QR code access denied: Invalid user type '$sessionUserType' for user $sessionUserId");
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Access denied');
}

$database = Database::getInstance();
$db = $database->getConnection();

// Use the session user ID we stored before closing the session
$userId = $sessionUserId;

// Get user ID from query parameter (default to current user)
$requestedUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $userId;

// Security: Only allow users to view their own QR code (unless admin)
$isAdmin = $sessionUserType === 'admin';
if (!$isAdmin && $requestedUserId != $userId) {
    error_log("QR code access denied: User $userId tried to access QR for user $requestedUserId");
    http_response_code(403);
    header('Content-Type: text/plain');
    die('Access denied');
}

// Get QR code path and employee ID from database
$stmt = $db->prepare("SELECT qr_code, employee_id FROM faculty_profiles WHERE user_id = ?");
$stmt->execute([$requestedUserId]);
$result = $stmt->fetch();

if (!$result || empty($result['qr_code'])) {
    error_log("QR code not found in database for user_id: $requestedUserId");
    http_response_code(404);
    header('Content-Type: text/plain');
    die('QR code not found');
}

$qrCodePath = $result['qr_code'];
$employeeId = $result['employee_id'] ?? $requestedUserId; // Fallback to user ID if no employee ID
$fullPath = UPLOAD_PATH . $qrCodePath;

// Check if file exists
if (!file_exists($fullPath)) {
    error_log("QR code file not found: $fullPath for user_id: $requestedUserId (db path: $qrCodePath)");
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

// CRITICAL: Clean ALL output buffers BEFORE setting headers to prevent corrupting image downloads
// This must happen before any headers are sent
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Prevent any output before headers
if (headers_sent($file, $line)) {
    error_log("QR code download: Headers already sent in $file on line $line. Cannot send image headers.");
    http_response_code(500);
    header('Content-Type: text/plain');
    die('Internal error: Headers already sent');
}

// Set headers to prevent caching issues and ensure proper display
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));

if ($isDownload) {
    // For downloads, set attachment header with employee ID in filename
    $safeEmployeeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $employeeId); // Sanitize for filename
    $filename = 'QR_Code_' . $safeEmployeeId . '.' . $extension;
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
} else {
    // For display, set cache headers
    header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');
    header('Pragma: public');
}

// Ensure no output buffering is active before reading file
if (ob_get_level() > 0) {
    ob_end_clean();
}

// Output the file directly
readfile($fullPath);
exit();

