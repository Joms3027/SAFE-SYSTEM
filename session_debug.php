<?php
/**
 * Session Debug Script
 * Use this to diagnose session issues in production
 * Access: /session_debug.php
 * 
 * WARNING: Remove or protect this file in production after debugging!
 */

// Start session with same config as main app
require_once 'includes/config.php';

if (defined('IS_PRODUCTION') && IS_PRODUCTION) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== SESSION DEBUG INFORMATION ===\n\n";

// Session Status
echo "1. SESSION STATUS:\n";
echo "   Session Status: " . session_status() . " (" . (session_status() === PHP_SESSION_ACTIVE ? 'ACTIVE' : 'INACTIVE') . ")\n";
echo "   Session ID: " . (session_id() ?: 'NOT SET') . "\n";
echo "   Session Name: " . session_name() . "\n\n";

// Cookie Information
echo "2. SESSION COOKIE INFORMATION:\n";
$cookieName = session_name();
$cookieParams = session_get_cookie_params();
echo "   Cookie Name: " . $cookieName . "\n";
echo "   Cookie Present in Request: " . (isset($_COOKIE[$cookieName]) ? 'YES' : 'NO') . "\n";
if (isset($_COOKIE[$cookieName])) {
    echo "   Cookie Value: " . substr($_COOKIE[$cookieName], 0, 32) . "...\n";
    echo "   Cookie Matches Session ID: " . ($_COOKIE[$cookieName] === session_id() ? 'YES' : 'NO') . "\n";
}
echo "   Cookie Domain: " . ($cookieParams['domain'] ?: 'EMPTY (default)') . "\n";
echo "   Cookie Path: " . $cookieParams['path'] . "\n";
echo "   Cookie Secure: " . ($cookieParams['secure'] ? 'YES' : 'NO') . "\n";
echo "   Cookie HttpOnly: " . ($cookieParams['httponly'] ? 'YES' : 'NO') . "\n";
echo "   Cookie SameSite: " . ($cookieParams['samesite'] ?? 'NOT SET') . "\n";
echo "   Cookie Lifetime: " . $cookieParams['lifetime'] . " seconds\n\n";

// HTTPS Detection
echo "3. HTTPS DETECTION:\n";
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
           (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
           (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') ||
           (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
           (!empty($_SERVER['REQUEST_SCHEME']) && $_SERVER['REQUEST_SCHEME'] === 'https');
echo "   HTTPS Detected: " . ($isHttps ? 'YES' : 'NO') . "\n";
echo "   \$_SERVER['HTTPS']: " . ($_SERVER['HTTPS'] ?? 'NOT SET') . "\n";
echo "   \$_SERVER['HTTP_X_FORWARDED_PROTO']: " . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'NOT SET') . "\n";
echo "   \$_SERVER['HTTP_X_FORWARDED_SSL']: " . ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? 'NOT SET') . "\n";
echo "   \$_SERVER['SERVER_PORT']: " . ($_SERVER['SERVER_PORT'] ?? 'NOT SET') . "\n";
echo "   \$_SERVER['REQUEST_SCHEME']: " . ($_SERVER['REQUEST_SCHEME'] ?? 'NOT SET') . "\n";
echo "   Cookie Secure Flag: " . ($cookieParams['secure'] ? 'YES' : 'NO') . "\n";
if ($isHttps && !$cookieParams['secure']) {
    echo "   ⚠️  WARNING: HTTPS detected but cookie Secure flag is FALSE!\n";
}
if (!$isHttps && $cookieParams['secure']) {
    echo "   ⚠️  WARNING: HTTP detected but cookie Secure flag is TRUE!\n";
}
echo "\n";

// Session Data
echo "4. SESSION DATA:\n";
if (isset($_SESSION['csrf_tokens'])) {
    echo "   CSRF Tokens Array: EXISTS (" . count($_SESSION['csrf_tokens']) . " tokens)\n";
    $tokenPreviews = array_slice(array_keys($_SESSION['csrf_tokens']), 0, 3);
    foreach ($tokenPreviews as $token) {
        echo "     - " . substr($token, 0, 16) . "...\n";
    }
} else {
    echo "   CSRF Tokens Array: NOT SET\n";
}
echo "\n";

// Test Token Generation
echo "5. TOKEN GENERATION TEST:\n";
try {
    require_once 'includes/token_manager.php';
    $testToken = TokenManager::generateToken();
    echo "   Token Generated: YES\n";
    echo "   Token: " . substr($testToken, 0, 32) . "...\n";
    echo "   Token in Session: " . (isset($_SESSION['csrf_tokens'][$testToken]) ? 'YES' : 'NO') . "\n";
    
    // Test validation
    $isValid = TokenManager::validateToken($testToken);
    echo "   Token Validation: " . ($isValid ? 'PASSED' : 'FAILED') . "\n";
} catch (Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
}
echo "\n";

// Server Information
echo "6. SERVER INFORMATION:\n";
echo "   HTTP Host: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET') . "\n";
echo "   Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'NOT SET') . "\n";
echo "   Server Name: " . ($_SERVER['SERVER_NAME'] ?? 'NOT SET') . "\n";
echo "   Document Root: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'NOT SET') . "\n";
echo "   Session Save Path: " . session_save_path() . "\n";
echo "   Session Save Path Writable: " . (is_writable(session_save_path()) ? 'YES' : 'NO') . "\n";
echo "\n";

// All Cookies
echo "7. ALL COOKIES:\n";
if (empty($_COOKIE)) {
    echo "   No cookies present in request\n";
} else {
    foreach ($_COOKIE as $name => $value) {
        echo "   $name: " . substr($value, 0, 32) . (strlen($value) > 32 ? '...' : '') . "\n";
    }
}
echo "\n";

// Recommendations
echo "8. RECOMMENDATIONS:\n";
$issues = [];

if (!isset($_COOKIE[$cookieName])) {
    $issues[] = "Session cookie not present in request - check cookie domain, path, and Secure flag";
}

if ($isHttps && !$cookieParams['secure']) {
    $issues[] = "HTTPS detected but cookie Secure flag is false - cookies won't work";
}

if (!$isHttps && $cookieParams['secure']) {
    $issues[] = "HTTP detected but cookie Secure flag is true - cookies won't work";
}

if (!empty($cookieParams['domain']) && $cookieParams['domain'] !== $_SERVER['HTTP_HOST']) {
    $issues[] = "Cookie domain is set to '{$cookieParams['domain']}' but host is '{$_SERVER['HTTP_HOST']}' - may cause issues";
}

if (!is_writable(session_save_path())) {
    $issues[] = "Session save path is not writable - sessions won't persist";
}

if (empty($issues)) {
    echo "   ✓ No obvious issues detected\n";
} else {
    foreach ($issues as $issue) {
        echo "   ⚠️  $issue\n";
    }
}

echo "\n=== END DEBUG ===\n";
?>
