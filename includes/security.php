<?php
/**
 * Security Helper Functions
 * Provides comprehensive security features for the application
 */

/**
 * Set security headers to protect against common attacks
 */
if (!function_exists('setSecurityHeaders')) {
function setSecurityHeaders() {
    // Skip security headers for file downloads to prevent interference
    if (defined('SKIP_SECURITY_HEADERS') && SKIP_SECURITY_HEADERS) {
        return;
    }
    
    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');
    
    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');
    
    // Enable XSS protection (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');
    
    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Content Security Policy (adjust as needed for your application)
    // Allow external CDNs for libraries like intro.js from jsdelivr.net
    // Allow both HTTP and HTTPS for localhost and dev tunnels
    // For localhost, allow both HTTP and HTTPS to avoid protocol mismatch
    $isLocalhost = in_array($_SERVER['HTTP_HOST'] ?? 'localhost', ['localhost', '127.0.0.1', '::1']) || 
                   strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
                   strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false;
    
    // CRITICAL FIX: manifest-src 'self' should allow relative URLs without issues
    // But to be safe, we'll also allow the current origin explicitly
    // Using 'self' alone should work for relative URLs like ./manifest.php or ../manifest.php
    $manifestSrc = "'self'";
    
    $csp = "default-src 'self'; " .
           "manifest-src {$manifestSrc}; " . // Allow manifest.php from same origin
           "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net; " . // Allow inline scripts + jsdelivr CDN
           "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; " . // Allow inline styles + jsdelivr CDN
           "img-src 'self' data: blob: https: http:; " .
           "font-src 'self' data: https: http:; " .
           "connect-src 'self' https: http: ws: wss: localhost:* 127.0.0.1:* *:443 *:80 *:8080; " . // Allow API connections to same origin, HTTP, HTTPS, WebSockets, and localhost with any port
           "frame-ancestors 'self';";
    
    // Only set CSP header if headers haven't been sent yet
    if (!headers_sent()) {
        header("Content-Security-Policy: $csp");
    }
    
    // Permissions Policy (formerly Feature Policy)
    // camera=(self) allows QR scanner in timekeeper; geolocation/microphone stay restricted
    header("Permissions-Policy: geolocation=(), microphone=(), camera=(self)");
    
    // Only set HSTS in production with HTTPS
    if (!isLocalhost() && isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}
}

/**
 * Check if current request is from localhost
 */
if (!function_exists('isLocalhost')) {
function isLocalhost() {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Remove port from host if present
    $hostWithoutPort = preg_replace('/:\d+$/', '', $host);
    
    return in_array($host, ['localhost', '127.0.0.1', '::1']) || 
           strpos($host, 'localhost') !== false ||
           strpos($host, '127.0.0.1') !== false ||
           strpos($host, '.local') !== false ||
           strpos($host, '.test') !== false ||
           strpos($host, 'xampp') !== false ||
           preg_match('/^169\.254\./', $hostWithoutPort) ||  // Link-local (APIPA) addresses
           preg_match('/^192\.168\./', $hostWithoutPort) ||  // Private network range
           preg_match('/^10\./', $hostWithoutPort) ||        // Private network range
           preg_match('/^172\.(1[6-9]|2[0-9]|3[0-1])\./', $hostWithoutPort); // Private network 172.16-31.x.x
}
}

/**
 * Rate limiting for login attempts using database storage.
 * Database-backed to prevent bypass via cookie/session clearing.
 * Falls back to session-based limiting if DB is unavailable.
 */
if (!function_exists('checkRateLimit')) {
function checkRateLimit($identifier, $maxAttempts = 5, $timeWindow = 900) {
    $key = md5($identifier);
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    try {
        if (class_exists('Database')) {
            $database = Database::getInstance();
            $db = $database->getConnection();
            
            $tableCheck = $db->query("SHOW TABLES LIKE 'login_rate_limits'");
            if ($tableCheck->rowCount() > 0) {
                $db->prepare("DELETE FROM login_rate_limits WHERE first_attempt < DATE_SUB(NOW(), INTERVAL ? SECOND)")->execute([$timeWindow]);
                
                $stmt = $db->prepare("SELECT attempts, locked_until FROM login_rate_limits WHERE identifier_hash = ?");
                $stmt->execute([$key]);
                $record = $stmt->fetch();
                
                if ($record) {
                    if ($record['locked_until'] && strtotime($record['locked_until']) > time()) {
                        return false;
                    }
                    
                    $newAttempts = $record['attempts'] + 1;
                    $lockedUntil = null;
                    if ($newAttempts >= $maxAttempts) {
                        $lockedUntil = date('Y-m-d H:i:s', time() + $timeWindow);
                    }
                    
                    $stmt = $db->prepare("UPDATE login_rate_limits SET attempts = ?, locked_until = ?, ip_address = ? WHERE identifier_hash = ?");
                    $stmt->execute([$newAttempts, $lockedUntil, $ipAddress, $key]);
                    
                    return $newAttempts < $maxAttempts;
                } else {
                    $stmt = $db->prepare("INSERT INTO login_rate_limits (identifier_hash, ip_address, attempts, first_attempt) VALUES (?, ?, 1, NOW())");
                    $stmt->execute([$key, $ipAddress]);
                    return true;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Rate limit DB error: " . $e->getMessage());
    }
    
    $sessionKey = 'rate_limit_' . $key;
    if (!isset($_SESSION[$sessionKey])) {
        $_SESSION[$sessionKey] = ['attempts' => 0, 'first_attempt' => time(), 'locked_until' => 0];
    }
    $rl = &$_SESSION[$sessionKey];
    if ($rl['locked_until'] > time()) return false;
    if (time() - $rl['first_attempt'] > $timeWindow) {
        $_SESSION[$sessionKey] = ['attempts' => 0, 'first_attempt' => time(), 'locked_until' => 0];
        return true;
    }
    $rl['attempts']++;
    if ($rl['attempts'] >= $maxAttempts) { $rl['locked_until'] = time() + $timeWindow; return false; }
    return true;
}
}

/**
 * Get remaining lock time in seconds
 */
if (!function_exists('getRateLimitRemaining')) {
function getRateLimitRemaining($identifier) {
    $key = md5($identifier);
    
    try {
        if (class_exists('Database')) {
            $database = Database::getInstance();
            $db = $database->getConnection();
            $tableCheck = $db->query("SHOW TABLES LIKE 'login_rate_limits'");
            if ($tableCheck->rowCount() > 0) {
                $stmt = $db->prepare("SELECT locked_until FROM login_rate_limits WHERE identifier_hash = ?");
                $stmt->execute([$key]);
                $record = $stmt->fetch();
                if ($record && $record['locked_until']) {
                    $remaining = strtotime($record['locked_until']) - time();
                    return max(0, $remaining);
                }
                return 0;
            }
        }
    } catch (Exception $e) {
        error_log("Rate limit check error: " . $e->getMessage());
    }
    
    $sessionKey = 'rate_limit_' . $key;
    if (isset($_SESSION[$sessionKey]) && $_SESSION[$sessionKey]['locked_until'] > time()) {
        return $_SESSION[$sessionKey]['locked_until'] - time();
    }
    return 0;
}
}

/**
 * Clear rate limit for an identifier (e.g., on successful login)
 */
if (!function_exists('clearRateLimit')) {
function clearRateLimit($identifier) {
    $key = md5($identifier);
    
    try {
        if (class_exists('Database')) {
            $database = Database::getInstance();
            $db = $database->getConnection();
            $tableCheck = $db->query("SHOW TABLES LIKE 'login_rate_limits'");
            if ($tableCheck->rowCount() > 0) {
                $db->prepare("DELETE FROM login_rate_limits WHERE identifier_hash = ?")->execute([$key]);
            }
        }
    } catch (Exception $e) {
        error_log("Rate limit clear error: " . $e->getMessage());
    }
    
    unset($_SESSION['rate_limit_' . $key]);
}
}

/**
 * Regenerate session ID to prevent session fixation
 * CRITICAL: This function now includes safeguards to prevent session loss during regeneration
 */
if (!function_exists('regenerateSessionId')) {
function regenerateSessionId() {
    // Only regenerate if session is already started
    if (session_status() === PHP_SESSION_ACTIVE) {
        // CRITICAL: Backup session data before regeneration
        // This prevents session loss if regeneration fails
        $sessionBackup = $_SESSION;
        
        try {
            // Regenerate session ID but KEEP old session file (false).
            // Deleting the old file (true) causes a race condition: if a concurrent
            // request (e.g. auto-save AJAX) still references the old session ID,
            // PHP creates a new empty session and the user gets logged out.
            // The old file will be cleaned up by session garbage collection.
            $success = @session_regenerate_id(false);
            
            if (!$success) {
                // If regeneration failed, restore session data and log error
                error_log("Session regeneration failed - session data preserved");
                $_SESSION = $sessionBackup;
            } else {
                // Ensure session data is preserved after successful regeneration
                // Sometimes PHP can lose session data during regeneration
                if (empty($_SESSION) && !empty($sessionBackup)) {
                    $_SESSION = $sessionBackup;
                    error_log("Session data restored after regeneration");
                }
            }
        } catch (Exception $e) {
            // If any error occurs, restore session data
            $_SESSION = $sessionBackup;
            error_log("Session regeneration error: " . $e->getMessage());
        }
    }
}
}

/**
 * Validate password strength
 * Returns array with 'valid' => bool and 'errors' => array
 */
if (!function_exists('validatePasswordStrength')) {
function validatePasswordStrength($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}
}

/**
 * Enhanced input sanitization
 * Wrapped in function_exists check to prevent redeclaration
 */
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data, $type = 'string') {
        if (is_array($data)) {
            return array_map(function($item) use ($type) {
                return sanitizeInput($item, $type);
            }, $data);
        }
        
        // Trim whitespace
        $data = trim($data);
        
        switch ($type) {
            case 'email':
                $data = filter_var($data, FILTER_SANITIZE_EMAIL);
                break;
            case 'int':
                $data = filter_var($data, FILTER_SANITIZE_NUMBER_INT);
                break;
            case 'float':
                $data = filter_var($data, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
                break;
            case 'url':
                $data = filter_var($data, FILTER_SANITIZE_URL);
                break;
            case 'string':
            default:
                // Remove null bytes
                $data = str_replace("\0", '', $data);
                // HTML escape for output
                $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
                break;
        }
        
        return $data;
    }
}

/**
 * Validate file upload security
 * Returns array with 'valid' => bool, 'error' => string
 */
if (!function_exists('validateFileUpload')) {
function validateFileUpload($file, $allowedTypes = [], $maxSize = 5242880) {
    // Check if file was uploaded
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return [
            'valid' => false,
            'error' => 'File upload error occurred'
        ];
    }
    
    // Check file size
    if ($file['size'] > $maxSize) {
        return [
            'valid' => false,
            'error' => 'File size exceeds maximum allowed size'
        ];
    }
    
    // Get file extension
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Check extension against allowed types
    if (!empty($allowedTypes) && !in_array($extension, $allowedTypes)) {
        return [
            'valid' => false,
            'error' => 'File type not allowed'
        ];
    }
    
    // Validate MIME type
    if (!function_exists('finfo_open')) {
        return [
            'valid' => false,
            'error' => 'File validation service unavailable. Please contact administrator.'
        ];
    }
    
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo === false) {
        return [
            'valid' => false,
            'error' => 'Failed to initialize file validation service.'
        ];
    }
    
    $mimeType = @finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if ($mimeType === false) {
        return [
            'valid' => false,
            'error' => 'Unable to determine file type.'
        ];
    }
    
    // Map extensions to expected MIME types
    $mimeMap = [
        'jpg' => ['image/jpeg', 'image/jpg'],
        'jpeg' => ['image/jpeg', 'image/jpg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document']
    ];
    
    if (isset($mimeMap[$extension])) {
        if (!in_array($mimeType, $mimeMap[$extension])) {
            return [
                'valid' => false,
                'error' => 'File MIME type does not match file extension'
            ];
        }
    }
    
    // Additional security: Check file content for suspicious patterns
    $fileContent = file_get_contents($file['tmp_name'], false, null, 0, 1024);
    
    // Check for PHP tags in uploaded files
    if (preg_match('/<\?php|<\?=|<script/i', $fileContent)) {
        return [
            'valid' => false,
            'error' => 'File contains potentially dangerous content'
        ];
    }
    
    return [
        'valid' => true,
        'error' => null,
        'mime_type' => $mimeType
    ];
}
}

/**
 * Secure session management
 * Optimized for high-concurrency environments
 * CRITICAL: This function should NEVER destroy a session unexpectedly
 */
if (!function_exists('secureSession')) {
function secureSession() {
    // CRITICAL: First check if session is valid - prevents session operations on inactive sessions
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    
    $currentTime = time();
    
    // Update last_activity FIRST to prevent premature expiration during concurrent requests
    // This ensures sessions don't expire between reads and writes
    $_SESSION['last_activity'] = $currentTime;
    
    // Regenerate session ID periodically (every 100 requests or 60 minutes)
    // INCREASED from 50/45 to further reduce regeneration conflicts during concurrent access
    // Session regeneration can cause issues on Windows/IIS, so we do it less frequently
    if (!isset($_SESSION['session_regenerated'])) {
        $_SESSION['session_regenerated'] = $currentTime;
        $_SESSION['request_count'] = 0;
    }
    
    $requestCount = ($_SESSION['request_count'] ?? 0) + 1;
    $_SESSION['request_count'] = $requestCount;
    
    $timeSinceRegen = $currentTime - $_SESSION['session_regenerated'];
    
    // Only regenerate if NOT an API/AJAX request to prevent concurrent regeneration issues
    // Also check for _api in filename which indicates an API endpoint
    $requestScript = $_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? '';
    $isApiRequest = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) || 
                    (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) ||
                    (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) ||
                    (strpos($requestScript, '_api') !== false) ||
                    (strpos($requestScript, 'auto_save') !== false);
    
    // Regenerate if 100 requests passed or 60 minutes elapsed (but not during API calls)
    // CRITICAL: Only regenerate on full page loads, not AJAX/API requests
    if (!$isApiRequest && ($requestCount >= 100 || $timeSinceRegen >= 3600)) {
        regenerateSessionId();
        $_SESSION['session_regenerated'] = $currentTime;
        $_SESSION['request_count'] = 0;
    }
    
    if (isset($_SESSION['last_activity'])) {
        // Timekeeper/station: 30 days; faculty: 5 days (PDS forms are long); others: 8 hours
        $userType = $_SESSION['user_type'] ?? '';
        $isTimekeeperSession = in_array($userType, ['timekeeper', 'station']);
        $isFacultySession = ($userType === 'faculty');
        $timeout = $isTimekeeperSession ? 2592000 : ($isFacultySession ? 432000 : 28800); // 30 days, 5 days, or 8 hours
        
        // Calculate time since last activity (use previous value before we updated it)
        $lastKnownActivity = $_SESSION['_prev_activity'] ?? ($_SESSION['last_activity'] - 1);
        $timeSinceLastActivity = $currentTime - $lastKnownActivity;
        
        // Only expire if SIGNIFICANTLY over timeout (add 5 minute grace period to prevent abrupt logouts)
        // This prevents edge cases during high load, network delays, or concurrent requests
        // The grace period ensures users aren't logged out while actively using the system
        // NOTE: With 10-year timeout, this should NEVER trigger during normal use
        $gracePeriod = 300; // 5 minutes grace period
        if ($timeSinceLastActivity > ($timeout + $gracePeriod)) {
            // Log the logout reason for debugging
            $userId = $_SESSION['user_id'] ?? 'unknown';
            $userType = $_SESSION['user_type'] ?? 'unknown';
            error_log("Session expired for user $userId (type: $userType). Time since last activity: " . round($timeSinceLastActivity / 60, 2) . " minutes (timeout: " . round($timeout / 60, 2) . " minutes, grace: " . round($gracePeriod / 60, 2) . " minutes)");
            
            session_destroy();
            session_start();
            return false;
        }
    }
    
    // Store current activity time for next comparison
    $_SESSION['_prev_activity'] = $currentTime;
    
    return true;
}
}

/**
 * Log security events
 */
if (!function_exists('logSecurityEvent')) {
function logSecurityEvent($event, $details = '') {
    try {
        // Check if Database class is available
        if (!class_exists('Database')) {
            error_log("Security event: $event - $details");
            return;
        }
        
        $database = Database::getInstance();
        $db = $database->getConnection();
        
        // Check if security_logs table exists
        $tableCheck = $db->query("SHOW TABLES LIKE 'security_logs'");
        if ($tableCheck->rowCount() === 0) {
            // Table doesn't exist, just log to error log
            error_log("Security event: $event - $details");
            return;
        }
        
        $userId = $_SESSION['user_id'] ?? null;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 500);
        
        $stmt = $db->prepare("
            INSERT INTO security_logs (user_id, event_type, details, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([$userId, $event, $details, $ipAddress, $userAgent]);
        
        // Also write to system_logs so all activities are visible in admin/system_logs
        $tableCheck = $db->query("SHOW TABLES LIKE 'system_logs'");
        if ($tableCheck->rowCount() > 0) {
            $desc = $details ?: $event;
            $stmt2 = $db->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
            $stmt2->execute([$userId, $event, $desc, $ipAddress, $userAgent]);
        }
    } catch (Exception $e) {
        // Log to error log if database logging fails
        error_log("Security event logging failed: " . $e->getMessage());
        error_log("Security event: $event - $details");
    }
}
}

/**
 * Escape output for HTML context
 */
if (!function_exists('e')) {
function e($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}
}

/**
 * Escape output for JavaScript context
 */
if (!function_exists('escapeJs')) {
function escapeJs($string) {
    return json_encode($string, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
}

/**
 * Escape output for URL context
 */
if (!function_exists('escapeUrl')) {
function escapeUrl($string) {
    return urlencode($string);
}
}

/**
 * Validate and sanitize SQL input (use with prepared statements)
 */
if (!function_exists('sanitizeForSQL')) {
function sanitizeForSQL($value, $type = 'string') {
    switch ($type) {
        case 'int':
            return filter_var($value, FILTER_VALIDATE_INT);
        case 'float':
            return filter_var($value, FILTER_VALIDATE_FLOAT);
        case 'email':
            return filter_var($value, FILTER_VALIDATE_EMAIL);
        case 'string':
        default:
            // For prepared statements, we don't need to escape
            // But we can validate it's not empty/null
            return $value !== null ? trim($value) : null;
    }
}
}

/**
 * Check for SQL injection patterns (basic detection)
 */
if (!function_exists('detectSQLInjection')) {
function detectSQLInjection($input) {
    $patterns = [
        '/(\bUNION\b.*\bSELECT\b)/i',
        '/(\bSELECT\b.*\bFROM\b)/i',
        '/(\bINSERT\b.*\bINTO\b)/i',
        '/(\bUPDATE\b.*\bSET\b)/i',
        '/(\bDELETE\b.*\bFROM\b)/i',
        '/(\bDROP\b.*\bTABLE\b)/i',
        '/(\bEXEC\b|\bEXECUTE\b)/i',
        '/(\bSCRIPT\b)/i',
        '/(--|\#|\/\*|\*\/)/',
        '/(\bOR\b.*=.*=)/i',
        '/(\bAND\b.*=.*=)/i',
        '/(\')|(\")|(;)/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $input)) {
            return true;
        }
    }
    
    return false;
}
}

/**
 * Generate secure random token
 */
if (!function_exists('generateSecureToken')) {
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}
}

/**
 * Hash sensitive data (one-way)
 * IMPORTANT: Set APP_SECRET environment variable in production!
 */
if (!function_exists('hashSensitiveData')) {
function hashSensitiveData($data) {
    $secret = getenv('APP_SECRET');
    if (!$secret) {
        // Generate a machine-specific fallback (not ideal, but better than hardcoded)
        // In production, always set APP_SECRET environment variable
        $secret = hash('sha256', __DIR__ . php_uname() . 'wpu_faculty_system');
        if (!defined('APP_SECRET_WARNING_LOGGED')) {
            error_log('WARNING: APP_SECRET environment variable not set. Using fallback. Set APP_SECRET for production!');
            define('APP_SECRET_WARNING_LOGGED', true);
        }
    }
    return hash('sha256', $data . $secret);
}
}

/**
 * Verify request origin (basic CSRF check)
 */
if (!function_exists('verifyRequestOrigin')) {
function verifyRequestOrigin() {
    $allowedOrigins = [
        $_SERVER['HTTP_HOST'] ?? 'localhost',
        'localhost',
        '127.0.0.1'
    ];
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    
    if (!empty($origin)) {
        $parsedOrigin = parse_url($origin, PHP_URL_HOST);
        if (!in_array($parsedOrigin, $allowedOrigins)) {
            return false;
        }
    }
    
    return true;
}
}
?>
