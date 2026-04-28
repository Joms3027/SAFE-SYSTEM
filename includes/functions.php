<?php
require_once 'token_manager.php';
require_once 'mobile_functions.php';

/**
 * Build a fully-qualified asset URL using the local asset base.
 * Generates absolute paths for better mobile compatibility.
 * Works with localhost, dev tunnels, and production domains.
 *
 * @param string $path Relative path within the assets directory.
 * @return string
 */
if (!function_exists('asset_url')) {
function asset_url(string $path = '', bool $addVersion = false): string {
    // Static cache for asset_url calculations (performance optimization)
    static $assetUrlCache = [
        'basePath' => null,
        'protocol' => null,
        'host' => null,
        'isLocalhost' => null
    ];
    
    $normalized = ltrim($path, '/');
    
    // Cache host and localhost detection (calculated once per request)
    if ($assetUrlCache['host'] === null) {
        $assetUrlCache['host'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
        
        // Force HTTP for localhost to avoid HTTPS certificate issues and extension blocking
        // Browsers and extensions often block HTTPS on localhost without proper certificates
        $assetUrlCache['isLocalhost'] = in_array($assetUrlCache['host'], ['localhost', '127.0.0.1', '::1']) || 
                       strpos($assetUrlCache['host'], 'localhost') !== false ||
                       strpos($assetUrlCache['host'], '127.0.0.1') !== false ||
                       strpos($assetUrlCache['host'], '.local') !== false ||
                       strpos($assetUrlCache['host'], '.test') !== false ||
                       strpos($assetUrlCache['host'], 'xampp') !== false;
    }
    
    $host = $assetUrlCache['host'];
    $isLocalhost = $assetUrlCache['isLocalhost'];
    
    // Cache protocol detection (calculated once per request)
    if ($assetUrlCache['protocol'] === null) {
        if ($isLocalhost) {
            // Always use HTTP for localhost to avoid certificate/extension blocking issues
            $assetUrlCache['protocol'] = 'http';
            
            // Normalize port for localhost - remove port 443 (HTTPS port) to avoid blocking
            // Port 443 with HTTP protocol triggers ad blockers and security warnings
            if (strpos($host, ':443') !== false) {
                $host = str_replace(':443', '', $host);
                $assetUrlCache['host'] = $host;
            }
            // Also normalize other common HTTPS ports if used with HTTP
            if (strpos($host, ':8443') !== false) {
                $host = str_replace(':8443', '', $host);
                $assetUrlCache['host'] = $host;
            }
        } else {
            // For non-localhost, detect protocol normally
            $assetUrlCache['protocol'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || 
                         (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) 
                        ? 'https' : 'http';
        }
    }
    
    $protocol = $assetUrlCache['protocol'];
    
    // Cache base path (calculated once per request)
    if ($assetUrlCache['basePath'] === null) {
        // Always use getBasePath() to ensure base path is included (e.g., /your-project/FP)
        // This fixes issues where ASSET_URL might not include the base path
        $assetUrlCache['basePath'] = getBasePath();
    }
    
    $basePath = $assetUrlCache['basePath'];
    // Normalize basePath - ensure it starts with / and doesn't end with /
    if ($basePath && $basePath !== '/') {
        $basePath = '/' . trim($basePath, '/');
    } elseif ($basePath === '/') {
        $basePath = ''; // Empty if root (no subdirectory)
    }
    
    // For localhost, use relative paths to avoid ad blocker blocking
    // Ad blockers often block absolute URLs containing "vendor", "bootstrap", "fontawesome"
    if ($isLocalhost) {
        // Build relative path from current script location
        // Calculate relative path from current directory to assets
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $currentDir = dirname($scriptName);
        
        // Remove basePath from currentDir if present to get relative path
        $relativeDir = $currentDir;
        if ($basePath && $basePath !== '/' && strpos($currentDir, $basePath) === 0) {
            $relativeDir = substr($currentDir, strlen($basePath));
        }
        
        // Count directory depth (e.g., /faculty = 1 level, /admin = 1 level)
        // Remove leading/trailing slashes and count segments
        $relativeDir = trim($relativeDir, '/');
        $depth = $relativeDir ? substr_count($relativeDir, '/') + 1 : 0;
        
        // Build relative path: go up N levels, then to assets
        $relativePrefix = $depth > 0 ? str_repeat('../', $depth) : '';
        $assetsPath = $relativePrefix . 'assets';
        $url = $normalized ? "{$assetsPath}/{$normalized}" : $assetsPath;
        
        // Remove any double slashes
        $url = preg_replace('#([^:])//+#', '$1/', $url);
        
        // Add cache-busting version parameter if requested
        if ($addVersion) {
            $separator = strpos($url, '?') !== false ? '&' : '?';
            $url .= $separator . 'v=' . time();
        }
        
        return $url;
    }
    
    // For non-localhost (production), use absolute URLs
    // Priority 1: Use ASSET_URL constant if available (from config.php)
    // ASSET_URL is set from SITE_URL and already points to the project root's assets folder.
    // Use it as-is: getBasePath() can return a sub-path (e.g. /HR_EVENT) when the script is in
    // a subfolder, which would incorrectly produce /HR_EVENT/assets (404) instead of /assets.
    if (defined('ASSET_URL') && ASSET_URL !== '') {
        $base = rtrim(ASSET_URL, '/');
        
        // If ASSET_URL is absolute, use it directly (config ensures it points to the correct assets)
        if (preg_match('/^https?:\/\//', $base)) {
            $url = $normalized ? "{$base}/{$normalized}" : $base;
        } else {
            // If ASSET_URL is relative, make it absolute using current request context + base path
            // This handles cases where ASSET_URL might be set as a relative path
            $relativePath = ltrim($base, '/');
            // Ensure base path is included
            if ($basePath && $basePath !== '/') {
                $basePathTrimmed = ltrim($basePath, '/');
                if (strpos($relativePath, $basePathTrimmed) !== 0) {
                    $relativePath = $basePathTrimmed . '/' . $relativePath;
                }
            }
            $base = $protocol . '://' . $host . '/' . $relativePath;
            $url = $normalized ? "{$base}/{$normalized}" : $base;
        }
    } else {
        // Priority 2: Use getBasePath() function for consistent path detection
        // This ensures we get the full base path (e.g., /your-project/FP) correctly
        // Construct full absolute asset URL (always use absolute path)
        $assetsPath = $basePath ? $basePath . '/assets' : '/assets';
        $base = $protocol . '://' . $host . $assetsPath;
        $url = $normalized ? "{$base}/{$normalized}" : $base;
    }
    
    // Final safety check: Ensure we always return an absolute URL (never relative)
    if (!preg_match('/^https?:\/\//', $url)) {
        // This should never happen, but as a safety measure
        $url = $protocol . '://' . $host . '/' . ltrim($url, '/');
    }
    
    // Remove any double slashes (except after protocol)
    $url = preg_replace('#([^:])//+#', '$1/', $url);
    
    // Ensure we never return an empty string
    if (empty($url)) {
        // Ultimate fallback - use root assets path
        $url = $protocol . '://' . $host . '/assets/' . $normalized;
    }
    
    // Add cache-busting version parameter if requested (useful for CSS/JS files)
    if ($addVersion) {
        $separator = strpos($url, '?') !== false ? '&' : '?';
        $url .= $separator . 'v=' . time(); // Use timestamp for cache busting
    }
    
    return $url;
}
}

/**
 * Get base path for the application (e.g., /your-project/FP)
 * Used for generating asset URLs and navigation links
 * 
 * @return string Base path with leading slash (e.g., '/your-project/FP')
 */
if (!function_exists('getBasePath')) {
function getBasePath(): string {
    // Priority 1: Calculate from document root (most reliable method)
    // This matches the logic in config.php and handles subdirectories correctly
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])) : '';
    $projectRoot = str_replace('\\', '/', realpath(dirname(__DIR__)));
    
    if ($documentRoot && $projectRoot && strpos($projectRoot, $documentRoot) === 0) {
        $relativeSegment = trim(substr($projectRoot, strlen($documentRoot)), '/');
        if ($relativeSegment) {
            return '/' . $relativeSegment;
        }
    }
    
    // Priority 2: Detect from SCRIPT_NAME (reliable fallback)
    if (isset($_SERVER['SCRIPT_NAME']) && $_SERVER['SCRIPT_NAME'] !== '') {
        $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
        // For paths like /SAFE_SYSTEM/FP/admin/dashboard.php, we need /SAFE_SYSTEM/FP
        // Only strip the last segment when it's a known subdirectory (admin/faculty/timekeeper)
        $pathSegments = array_filter(explode('/', $scriptPath));
        $lastSegment = end($pathSegments);
        if (count($pathSegments) >= 2 && in_array($lastSegment, ['admin', 'faculty', 'timekeeper'])) {
            // We're in a subdirectory - go up one level to get project root
            $baseSegments = array_slice($pathSegments, 0, -1);
            if (count($baseSegments) > 0) {
                return '/' . implode('/', $baseSegments);
            }
        } elseif (count($pathSegments) === 1) {
            // Single segment: admin/faculty/timekeeper at server root = empty base
            $segment = reset($pathSegments);
            if (in_array($segment, ['admin', 'faculty', 'timekeeper'])) {
                return ''; // Root - no base path
            }
            return '/' . $segment; // Subdirectory install (e.g. /myapp)
        } elseif (count($pathSegments) >= 1) {
            // At project root (e.g. /SAFE_SYSTEM/FP for login.php) - return full path
            return '/' . implode('/', $pathSegments);
        }
    }
    
    // Priority 3: Detect from REQUEST_URI (fallback)
    if (isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] !== '') {
        $requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        if ($requestUri && $requestUri !== '/') {
            $pathSegments = array_filter(explode('/', $requestUri));
            // Check if it contains admin or faculty
            if (in_array('admin', $pathSegments) || in_array('faculty', $pathSegments)) {
                $key = array_search('admin', $pathSegments);
                if ($key === false) {
                    $key = array_search('faculty', $pathSegments);
                }
                if ($key !== false && $key > 0) {
                    $baseSegments = array_slice($pathSegments, 0, $key);
                    if (count($baseSegments) > 0) {
                        return '/' . implode('/', $baseSegments);
                    }
                }
            }
        }
    }
    
    // Priority 4: Use SITE_URL constant if available (last resort)
    if (defined('SITE_URL') && SITE_URL !== '') {
        $siteUrl = SITE_URL;
        // Extract path from full URL if it's absolute
        if (preg_match('/https?:\/\/[^\/]+(.+)$/', $siteUrl, $matches)) {
            return $matches[1];
        }
        // If SITE_URL is just a path, return it
        if (strpos($siteUrl, '/') === 0) {
            return $siteUrl;
        }
    }
    
    // Fallback: return empty string (root) - should rarely happen
    return '';
}
}

if (!function_exists('generateFormToken')) {
function generateFormToken() {
    return TokenManager::generateToken();
}
}

if (!function_exists('validateFormToken')) {
function validateFormToken($token) {
    return TokenManager::validateToken($token);
}
}

if (!function_exists('addFormToken')) {
function addFormToken() {
    // Ensure session is active
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $token = generateFormToken();
    
    // CRITICAL: Ensure session is written after token generation
    // This is especially important in production where session might not persist
    // We can't use session_write_close() here because we need the session for the rest of the page
    // But we can ensure the session data is set and will be written at script end
    // The key is making sure the session cookie is set correctly (handled in config.php)
    
    // Verify token was stored in session (debugging)
    if (!isset($_SESSION['csrf_tokens'][$token])) {
        error_log("WARNING: CSRF token generated but not found in session immediately after generation!");
    }
    
    echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}
}

// sanitizeInput() is now defined in security.php (enhanced version)
// This function is kept here for backward compatibility if security.php is not loaded
if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data, $type = 'string') {
        // Basic sanitization
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


if (!function_exists('validateWPUEmail')) {
function validateWPUEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) && preg_match('/@wpu\.edu\.ph$/', $email);
}
}

/**
 * Generate unique employee ID for faculty
 * Format: WPU-YYYY-##### (e.g., WPU-2025-00001)
 * Year is based on current year
 * Number is auto-incremented (5 digits, zero-padded)
 * 
 * @return string The generated employee ID
 */
if (!function_exists('generateEmployeeID')) {
function generateEmployeeID() {
    try {
        $database = Database::getInstance();
        $db = $database->getConnection();
        
        // Get current year
        $currentYear = date('Y');
        
        // Get the last employee ID for the current year
        $stmt = $db->prepare("
            SELECT employee_id 
            FROM faculty_profiles 
            WHERE employee_id LIKE ? 
            ORDER BY employee_id DESC 
            LIMIT 1
        ");
        $stmt->execute(["WPU-{$currentYear}-%"]);
        $lastEmployee = $stmt->fetch();
        
        if ($lastEmployee && $lastEmployee['employee_id']) {
            // Extract the number from the last employee ID
            $parts = explode('-', $lastEmployee['employee_id']);
            $lastNumber = isset($parts[2]) ? intval($parts[2]) : 0;
            $newNumber = $lastNumber + 1;
        } else {
            // First employee for this year
            $newNumber = 1;
        }
        
        // Format: WPU-YYYY-##### (5 digits, zero-padded)
        $employeeID = sprintf("WPU-%s-%05d", $currentYear, $newNumber);
        
        // Verify uniqueness (in case of race condition)
        $stmt = $db->prepare("SELECT id FROM faculty_profiles WHERE employee_id = ?");
        $stmt->execute([$employeeID]);
        
        if ($stmt->rowCount() > 0) {
            // ID already exists, try next number
            return generateEmployeeID();
        }
        
        return $employeeID;
        
    } catch (Exception $e) {
        error_log("Failed to generate employee ID: " . $e->getMessage());
        // Fallback: generate with timestamp to ensure uniqueness
        return sprintf("WPU-%s-%05d", date('Y'), rand(1, 99999));
    }
}
}

if (!function_exists('formatDate')) {
function formatDate($date, $format = 'd/m/Y') {
    if (empty($date) || $date == '0000-00-00') return '';
    return date($format, strtotime($date));
}
}

if (!function_exists('isLoggedIn')) {
function isLoggedIn() {
    // CRITICAL: First ensure session is active
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    
    // Check for station sessions (new method) - they don't have user_id
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'station' && isset($_SESSION['station_id'])) {
        // Refresh last_activity to prevent timeout
        $_SESSION['last_activity'] = time();
        return true;
    }
    
    // Check for regular user sessions (require user_id)
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        return false;
    }
    
    // CRITICAL: Always refresh last_activity on valid session check
    // This prevents session timeout during active use
    $_SESSION['last_activity'] = time();
    
    // Additional validation: verify session is still valid by checking database
    // This prevents stale sessions from persisting across different domains/ports
    // Only perform database check if Database class is available
    // OPTIMIZATION: Only check database periodically to reduce overhead
    // and prevent database connection issues from causing unexpected logouts
    // MOBILE FIX: Use longer interval on mobile devices to prevent timeouts
    // Detect mobile device first (before database check)
    $isMobile = function_exists('isMobileDevice') && isMobileDevice();
    
    if (class_exists('Database')) {
        $lastDbCheck = $_SESSION['last_db_check'] ?? 0;
        
        // Use longer interval on mobile devices (15 minutes) to prevent connection timeouts
        // Desktop uses 5 minutes (300 seconds)
        $dbCheckInterval = $isMobile ? 900 : 300; // 15 minutes on mobile, 5 minutes on desktop
        
        // Only perform database check if enough time has passed since last check
        if ((time() - $lastDbCheck) > $dbCheckInterval) {
            try {
                $database = Database::getInstance();
                $db = $database->getConnection();
                
                // Set a timeout for the query on mobile devices to prevent hanging
                if ($isMobile) {
                    // Set query timeout to 5 seconds on mobile (PDO doesn't support this directly,
                    // but we can catch timeout exceptions)
                    try {
                        $stmt = $db->prepare("SELECT id, is_active FROM users WHERE id = ? LIMIT 1");
                        $stmt->execute([$_SESSION['user_id']]);
                        $user = $stmt->fetch();
                    } catch (PDOException $pdoEx) {
                        // If query times out or fails on mobile, don't logout - just skip this check
                        error_log("Session validation query error on mobile (non-fatal): " . $pdoEx->getMessage());
                        // Update last check time to prevent repeated failed checks
                        $_SESSION['last_db_check'] = time();
                        // Return true based on session data only - don't logout on DB errors
                        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
                    }
                } else {
                    $stmt = $db->prepare("SELECT id, is_active FROM users WHERE id = ? LIMIT 1");
                    $stmt->execute([$_SESSION['user_id']]);
                    $user = $stmt->fetch();
                }
                
                // Update last check time
                $_SESSION['last_db_check'] = time();
                
                // CRITICAL FIX: Only invalidate session if user is explicitly inactive
                // Don't invalidate if user doesn't exist (might be a transient DB issue)
                if ($user && !$user['is_active']) {
                    // User exists but is deactivated - this is a valid reason to logout
                    error_log("Session invalidated: User ID {$_SESSION['user_id']} is deactivated");
                    session_destroy();
                    session_start();
                    return false;
                }
                
                // If user not found, log but don't destroy session immediately
                // This could be a transient database issue
                if (!$user) {
                    error_log("Warning: User ID {$_SESSION['user_id']} not found in database - keeping session for now");
                    // Don't destroy session - could be temporary DB issue
                    // The next check will verify again
                }
                
                return true;
            } catch (Exception $e) {
                // If database check fails, fall back to basic check
                // Log error but don't break the site - don't destroy session on DB errors
                // CRITICAL: Always return true on DB errors to prevent unwanted logouts
                error_log("Session validation error (non-fatal): " . $e->getMessage());
                // Update last check time to prevent repeated failed checks
                // Use longer interval after a failure to reduce retry attempts
                $_SESSION['last_db_check'] = time();
                // Return true based on session data only - don't logout on DB errors
                return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
            }
        } else {
            // Skip database check if checked recently - just return true based on session
            return true;
        }
    }
    
    // Fallback: basic session check if Database class not available
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}
}

if (!function_exists('isAdmin')) {
function isAdmin() {
    return isset($_SESSION['user_type']) && in_array($_SESSION['user_type'], ['admin', 'super_admin']);
}
}

if (!function_exists('isSuperAdmin')) {
function isSuperAdmin() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'super_admin';
}
}

/**
 * Get user IDs of pardon openers who have this employee in their scope (for DTR verification notifications).
 *
 * @param string $employeeId Employee ID (e.g. WPU-2026-00004)
 * @param PDO $db Database connection
 * @return int[] Array of user IDs
 */
if (!function_exists('getOpenerUserIdsForEmployee')) {
function getOpenerUserIdsForEmployee($employeeId, $db) {
    try {
        $tbl = $db->query("SHOW TABLES LIKE 'pardon_opener_assignments'");
        if (!$tbl || $tbl->rowCount() === 0) return [];
    } catch (Exception $e) {
        return [];
    }
    $stmt = $db->prepare("SELECT fp.user_id, fp.department, fp.designation FROM faculty_profiles fp WHERE fp.employee_id = ? LIMIT 1");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) return [];
    $empUserId = (int) ($emp['user_id'] ?? 0);
    $empDept = trim($emp['department'] ?? '');
    $empDesig = trim($emp['designation'] ?? '');
    $openerIds = [];
    $stmt = $db->prepare("SELECT DISTINCT poa.user_id FROM pardon_opener_assignments poa
        WHERE ((poa.scope_type = 'department' AND LOWER(TRIM(poa.scope_value)) = LOWER(?))
           OR (poa.scope_type = 'designation' AND TRIM(poa.scope_value) != '' AND LOWER(TRIM(poa.scope_value)) = LOWER(?)))
        AND poa.user_id != ?");
    $stmt->execute([$empDept, $empDesig, $empUserId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $openerIds[] = (int) $row['user_id'];
    }
    return $openerIds;
}
}

/**
 * Get the display name of the assigned pardon opener for an employee (by department/designation).
 * Used for DTR "In Charge" verification line.
 * When both department and designation have assigned openers, designation takes priority.
 *
 * @param string $employeeId Employee ID (e.g. WPU-2026-00004)
 * @param PDO $db Database connection
 * @return string Pardon opener name or "HR" if none assigned
 */
if (!function_exists('getPardonOpenerDisplayNameForEmployee')) {
function getPardonOpenerDisplayNameForEmployee($employeeId, $db) {
    try {
        $tbl = $db->query("SHOW TABLES LIKE 'pardon_opener_assignments'");
        if (!$tbl || $tbl->rowCount() === 0) return 'HR';
    } catch (Exception $e) {
        return 'HR';
    }
    $stmt = $db->prepare("SELECT fp.user_id, fp.department, fp.designation FROM faculty_profiles fp WHERE fp.employee_id = ? LIMIT 1");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) return 'HR';
    $empUserId = (int) ($emp['user_id'] ?? 0);
    $empDept = trim($emp['department'] ?? '');
    $empDesig = trim($emp['designation'] ?? '');

    // Designation takes priority over department when both have assigned openers
    $openerId = null;
    if ($empDesig !== '') {
        $stmtPo = $db->prepare("SELECT poa.user_id FROM pardon_opener_assignments poa
            WHERE poa.scope_type = 'designation' AND LOWER(TRIM(poa.scope_value)) = LOWER(?) AND poa.user_id != ? LIMIT 1");
        $stmtPo->execute([$empDesig, $empUserId]);
        $row = $stmtPo->fetch(PDO::FETCH_ASSOC);
        if ($row) $openerId = (int) $row['user_id'];
    }
    if ($openerId === null && $empDept !== '') {
        $stmtPo = $db->prepare("SELECT poa.user_id FROM pardon_opener_assignments poa
            WHERE poa.scope_type = 'department' AND LOWER(TRIM(poa.scope_value)) = LOWER(?) AND poa.user_id != ? LIMIT 1");
        $stmtPo->execute([$empDept, $empUserId]);
        $row = $stmtPo->fetch(PDO::FETCH_ASSOC);
        if ($row) $openerId = (int) $row['user_id'];
    }
    if ($openerId === null) {
        return 'HR';
    }

    $stmt = $db->prepare("SELECT u.first_name, u.last_name, fp.department
        FROM users u
        LEFT JOIN faculty_profiles fp ON fp.user_id = u.id
        WHERE u.id = ? AND u.is_active = 1 LIMIT 1");
    $stmt->execute([$openerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 'HR';
    $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    if ($name === '') return 'HR';
    $dept = trim($row['department'] ?? '');
    return $dept !== '' ? $name . ', ' . $dept : $name;
}
}

/**
 * Check if a user can open pardon for an employee based on pardon_opener_assignments.
 * Returns true if user has assignment(s) that match the employee's department or designation.
 *
 * @param int $userId User ID (opener)
 * @param string $employeeId Employee ID (e.g. WPU-2026-00004)
 * @param PDO $db Database connection
 * @return bool
 */
if (!function_exists('canUserOpenPardonForEmployee')) {
function canUserOpenPardonForEmployee($userId, $employeeId, $db) {
    try {
        $tbl = $db->query("SHOW TABLES LIKE 'pardon_opener_assignments'");
        if (!$tbl || $tbl->rowCount() === 0) return false;
    } catch (Exception $e) {
        return false;
    }
    $stmt = $db->prepare("SELECT fp.department, fp.designation FROM faculty_profiles fp WHERE fp.employee_id = ? LIMIT 1");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) return false;
    $empDept = trim($emp['department'] ?? '');
    $empDesig = trim($emp['designation'] ?? '');
    $stmt = $db->prepare("SELECT scope_type, scope_value FROM pardon_opener_assignments WHERE user_id = ?");
    $stmt->execute([$userId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($assignments as $a) {
        $val = trim($a['scope_value'] ?? '');
        if ($a['scope_type'] === 'department' && strcasecmp($empDept, $val) === 0) return true;
        if ($a['scope_type'] === 'designation' && strcasecmp($empDesig, $val) === 0) return true;
    }
    return false;
}
}

/**
 * Check if a faculty user can access department DTR (view employees to open pardon).
 * Returns true if user has pardon_opener_assignments with scope_type=department matching their department.
 *
 * @param int $userId User ID
 * @param string $userDepartment User's department from faculty_profiles
 * @param PDO $db Database connection
 * @return bool
 */
/**
 * Check if a user has any pardon_opener_assignments (department or designation scope).
 *
 * @param int $userId User ID
 * @param PDO|null $db Database connection (optional; will create if not provided)
 * @return bool
 */
if (!function_exists('hasPardonOpenerAssignments')) {
function hasPardonOpenerAssignments($userId, $db = null) {
    if ($userId <= 0) return false;
    try {
        if ($db === null && class_exists('Database')) {
            $database = Database::getInstance();
            $db = $database->getConnection();
        }
        if (!$db) return false;
        $tbl = $db->query("SHOW TABLES LIKE 'pardon_opener_assignments'");
        if (!$tbl || $tbl->rowCount() === 0) return false;
        $stmt = $db->prepare("SELECT 1 FROM pardon_opener_assignments WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return (bool) $stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}
}

/**
 * Get employee IDs in scope for a user with pardon_opener_assignments.
 * Used for DTR verification and official time endorsement by scope (department or designation).
 *
 * @param int $userId User ID (pardon opener)
 * @param PDO $db Database connection
 * @return string[] Array of employee_id values
 */
if (!function_exists('getEmployeeIdsInScope')) {
function getEmployeeIdsInScope($userId, $db) {
    $deptScopes = [];
    $desigScopes = [];
    try {
        $stmt = $db->prepare("SELECT scope_type, scope_value FROM pardon_opener_assignments WHERE user_id = ?");
        $stmt->execute([$userId]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $val = trim($row['scope_value'] ?? '');
            if ($val === '') continue;
            if ($row['scope_type'] === 'department') {
                $deptScopes[] = $val;
            } else {
                $desigScopes[] = $val;
            }
        }
    } catch (Exception $e) {
        return [];
    }
    $employeeIds = [];
    $seen = [];
    if (!empty($deptScopes)) {
        $placeholders = implode(',', array_fill(0, count($deptScopes), '?'));
        $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp
            JOIN users u ON fp.user_id = u.id
            WHERE u.user_type IN ('faculty', 'staff') AND u.is_active = 1
            AND LOWER(TRIM(fp.department)) IN ($placeholders)");
        $stmt->execute(array_map('strtolower', array_map('trim', $deptScopes)));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $eid = trim($row['employee_id'] ?? '');
            if ($eid !== '' && !isset($seen[$eid])) {
                $seen[$eid] = true;
                $employeeIds[] = $eid;
            }
        }
    }
    if (!empty($desigScopes)) {
        $placeholders = implode(',', array_fill(0, count($desigScopes), '?'));
        $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp
            JOIN users u ON fp.user_id = u.id
            WHERE u.user_type IN ('faculty', 'staff') AND u.is_active = 1
            AND TRIM(fp.designation) != ''
            AND LOWER(TRIM(fp.designation)) IN ($placeholders)");
        $stmt->execute(array_map('strtolower', array_map('trim', $desigScopes)));
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $eid = trim($row['employee_id'] ?? '');
            if ($eid !== '' && !isset($seen[$eid])) {
                $seen[$eid] = true;
                $employeeIds[] = $eid;
            }
        }
    }
    return $employeeIds;
}
}

/**
 * Check if an employee is in any pardon opener's scope (department or designation).
 * Used to show the "Submit DTR & Official Time" hub to assigned employees.
 *
 * @param string $employeeId Employee ID (e.g. WPU-2026-00004)
 * @param PDO $db Database connection
 * @return bool
 */
if (!function_exists('isEmployeeInAnyPardonScope')) {
function isEmployeeInAnyPardonScope($employeeId, $db) {
    try {
        $tbl = $db->query("SHOW TABLES LIKE 'pardon_opener_assignments'");
        if (!$tbl || $tbl->rowCount() === 0) return false;
    } catch (Exception $e) {
        return false;
    }
    $stmt = $db->prepare("SELECT fp.department, fp.designation FROM faculty_profiles fp WHERE fp.employee_id = ? LIMIT 1");
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) return false;
    $empDept = trim($emp['department'] ?? '');
    $empDesig = trim($emp['designation'] ?? '');
    $stmt = $db->prepare("SELECT 1 FROM pardon_opener_assignments poa
        WHERE (poa.scope_type = 'department' AND LOWER(TRIM(poa.scope_value)) = LOWER(?))
           OR (poa.scope_type = 'designation' AND TRIM(poa.scope_value) != '' AND LOWER(TRIM(poa.scope_value)) = LOWER(?))
        LIMIT 1");
    $stmt->execute([$empDept, $empDesig]);
    return (bool) $stmt->fetch();
}
}

if (!function_exists('canUserAccessDepartmentDtr')) {
function canUserAccessDepartmentDtr($userId, $userDepartment, $db) {
    $userDepartment = trim($userDepartment ?? '');
    if ($userDepartment === '') return false;
    try {
        $tbl = $db->query("SHOW TABLES LIKE 'pardon_opener_assignments'");
        if (!$tbl || $tbl->rowCount() === 0) {
            // Fallback: legacy dean check
            $stmt = $db->prepare("SELECT designation FROM faculty_profiles WHERE user_id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row && strtolower(trim($row['designation'] ?? '')) === 'dean';
        }
    } catch (Exception $e) {
        return false;
    }
    $stmt = $db->prepare("SELECT 1 FROM pardon_opener_assignments WHERE user_id = ? AND scope_type = 'department' AND LOWER(TRIM(scope_value)) = LOWER(?) LIMIT 1");
    $stmt->execute([$userId, $userDepartment]);
    return (bool) $stmt->fetch();
}
}

if (!function_exists('requireSuperAdmin')) {
function requireSuperAdmin() {
    requireAuth();
    if (!isSuperAdmin()) {
        if (function_exists('isApiRequest') && isApiRequest()) {
            http_response_code(403);
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. Super admin privileges required.',
                'error' => 'insufficient_privileges'
            ]);
            exit;
        }
        $_SESSION['error'] = "Access denied. Super admin privileges required.";
        $basePath = getBasePath();
        redirect(clean_url($basePath . '/admin/dashboard.php', $basePath));
    }
}
}

if (!function_exists('isFaculty')) {
function isFaculty() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'faculty';
}
}

if (!function_exists('isStaff')) {
function isStaff() {
    return isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'staff';
}
}

if (!function_exists('isTimekeeper')) {
function isTimekeeper() {
    // Accept both timekeeper and station user types for backward compatibility
    if (isset($_SESSION['user_type'])) {
        // Old timekeeper with user account
        if ($_SESSION['user_type'] === 'timekeeper' && isset($_SESSION['timekeeper_id'])) {
            return true;
        }
        // New station-based access
        if ($_SESSION['user_type'] === 'station' && isset($_SESSION['station_id'])) {
            return true;
        }
    }
    return false;
}
}

if (!function_exists('redirect')) {
function redirect($url) {
    // CRITICAL: Ensure session is written before redirect
    // This prevents race conditions where the browser requests the new page
    // before the session file is fully written
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    
    // Prevent redirect if headers already sent
    if (headers_sent()) {
        echo "<script>window.location.href='$url';</script>";
        echo "<noscript><meta http-equiv='refresh' content='0;url=$url'></noscript>";
        exit();
    }
    header("Location: " . $url);
    exit();
}
}

/**
 * Generate a clean URL without .php extension
 * This ensures URLs don't show .php in the browser address bar
 * Works with both .htaccess (Apache) and web.config (IIS) rewrite rules
 * 
 * @param string $path Path to PHP file (with or without .php extension) - can be absolute URL or relative path
 * @param string $basePath Optional base path (if not provided, uses getBasePath())
 * @return string Clean URL without .php extension
 */
if (!function_exists('clean_url')) {
function clean_url($path, $basePath = null) {
    // Check if path is an absolute URL (starts with http:// or https://)
    if (preg_match('/^https?:\/\//', $path)) {
        // Handle absolute URL - remove .php from the path part
        $parsed = parse_url($path);
        $scheme = $parsed['scheme'] ?? 'http';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $pathPart = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
        $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
        
        // Remove .php extension from path part
        $cleanPath = preg_replace('/\.php$/', '', $pathPart);
        
        // Rebuild absolute URL
        return $scheme . '://' . $host . $port . $cleanPath . $query . $fragment;
    }
    
    // Handle relative path
    // Remove .php extension if present
    $cleanPath = preg_replace('/\.php$/', '', $path);
    
    // Get base path if not provided
    if ($basePath === null) {
        $basePath = function_exists('getBasePath') ? getBasePath() : '';
    }
    
    // Normalize base path (no trailing slash)
    $basePath = rtrim($basePath, '/');
    
    // CRITICAL: If path already starts with base path, strip it to avoid duplication
    // e.g. /SAFE_SYSTEM/FP/logout + basePath /SAFE_SYSTEM/FP would otherwise become /SAFE_SYSTEM/FP/SAFE_SYSTEM/FP/logout
    $baseTrimmed = $basePath ? ltrim($basePath, '/') : '';
    $cleanTrimmed = ltrim($cleanPath, '/');
    if ($baseTrimmed && $cleanTrimmed !== '') {
        if (strpos($cleanTrimmed, $baseTrimmed . '/') === 0) {
            $cleanPath = substr($cleanPath, strlen($basePath));
        } elseif ($cleanTrimmed === $baseTrimmed) {
            $cleanPath = '';
        }
    }
    $cleanPath = ltrim($cleanPath, '/');
    
    // Build final URL
    if ($basePath && $basePath !== '/') {
        return $cleanPath ? $basePath . '/' . $cleanPath : $basePath;
    }
    
    return $cleanPath ? '/' . $cleanPath : '/';
}
}

/**
 * Get the current page URL for redirects
 * This ensures users stay on the same page after completing actions/transactions
 * 
 * @param bool $includeQueryString Whether to include query string parameters
 * @return string Current page URL
 */
if (!function_exists('getCurrentPageUrl')) {
function getCurrentPageUrl($includeQueryString = true) {
    $basePath = getBasePath();
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    
    // Get the filename without directory
    $filename = basename($scriptName);
    
    // Build URL with query string if requested
    $url = clean_url($basePath . '/' . $filename, $basePath);
    
    if ($includeQueryString && !empty($_SERVER['QUERY_STRING'])) {
        $url .= '?' . $_SERVER['QUERY_STRING'];
    }
    
    return $url;
}
}

if (!function_exists('requireAuth')) {
function requireAuth() {
    // Ensure session is active
    if (session_status() !== PHP_SESSION_ACTIVE) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    // Clear redirect counter when requiring auth (prevents false loop detection)
    unset($_SESSION['index_redirect_count']);
    
    if (!isLoggedIn()) {
        // Check if this is an API/AJAX request - return JSON instead of redirecting
        if (function_exists('isApiRequest') && isApiRequest()) {
            http_response_code(401);
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized - Please log in',
                'error' => 'authentication_required'
            ]);
            exit;
        }
        
        // Always redirect to root login.php, regardless of current directory
        // This prevents redirects to non-existent paths like faculty/login.php
        $basePath = getBasePath();
        $loginPath = clean_url($basePath . '/login.php', $basePath);
        
        // If we're already on the login page, don't redirect
        $currentUri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($currentUri, 'login') !== false || 
            strpos($currentUri, 'emergency_login') !== false) {
            return;
        }
        
        $pathOnly = parse_url($currentUri, PHP_URL_PATH);
        $pathOnly = is_string($pathOnly) ? $pathOnly : '';
        if ($pathOnly !== '' && stripos($pathOnly, 'login') === false && stripos($pathOnly, 'emergency_login') === false) {
            $sep = strpos($loginPath, '?') !== false ? '&' : '?';
            $loginPath .= $sep . 'redirect=' . rawurlencode($currentUri);
        }
        
        redirect($loginPath);
    }
    
    // Also validate that session is complete (has user_type)
    // CRITICAL: For station users, user_type is 'station' and they don't have user_id
    if (!isset($_SESSION['user_type']) || empty($_SESSION['user_type'])) {
        // Check if this is an API/AJAX request - return JSON instead of redirecting
        if (function_exists('isApiRequest') && isApiRequest()) {
            http_response_code(401);
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized - Session incomplete',
                'error' => 'session_incomplete'
            ]);
            exit;
        }
        
        // CRITICAL FIX: Log the incomplete session for debugging instead of silently destroying
        error_log("Incomplete session detected - user_type missing. Session data: " . 
            json_encode(array_keys($_SESSION)));
        
        // Incomplete session - clear it and redirect to login
        // Only destroy if we're sure it's incomplete (not a transient issue)
        if (isset($_SESSION['user_id']) && !isset($_SESSION['user_type'])) {
            // This is a genuine incomplete session - destroy it
            session_destroy();
            session_start();
        }
        
        $basePath = getBasePath();
        $incompleteLogin = clean_url($basePath . '/login.php', $basePath);
        $currentUri = $_SERVER['REQUEST_URI'] ?? '';
        $pathOnly = parse_url($currentUri, PHP_URL_PATH);
        $pathOnly = is_string($pathOnly) ? $pathOnly : '';
        if ($currentUri !== '' && $pathOnly !== '' && stripos($pathOnly, 'login') === false && stripos($pathOnly, 'emergency_login') === false) {
            $sep = strpos($incompleteLogin, '?') !== false ? '&' : '?';
            $incompleteLogin .= $sep . 'redirect=' . rawurlencode($currentUri);
        }
        redirect($incompleteLogin);
    }
}
}

if (!function_exists('requireAdmin')) {
function requireAdmin() {
    requireAuth();
    if (!isAdmin()) {
        // Check if this is an API/AJAX request - return JSON instead of redirecting
        if (function_exists('isApiRequest') && isApiRequest()) {
            http_response_code(403);
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. Admin privileges required.',
                'error' => 'insufficient_privileges'
            ]);
            exit;
        }
        
        $_SESSION['error'] = "Access denied. Admin privileges required.";
        $basePath = getBasePath();
        redirect(clean_url($basePath . '/faculty/dashboard.php', $basePath));
    }
}
}

if (!function_exists('requireFaculty')) {
function requireFaculty() {
    requireAuth();
    // Allow both faculty and staff to access faculty pages
    if (!isFaculty() && !isStaff()) {
        // Check if this is an API/AJAX request - return JSON instead of redirecting
        if (function_exists('isApiRequest') && isApiRequest()) {
            http_response_code(403);
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. Faculty or Staff privileges required.',
                'error' => 'insufficient_privileges'
            ]);
            exit;
        }
        
        $_SESSION['error'] = "Access denied. Faculty or Staff privileges required.";
        $basePath = getBasePath();
        redirect(clean_url($basePath . '/admin/dashboard.php', $basePath));
    }
}
}

if (!function_exists('requireStaff')) {
function requireStaff() {
    requireAuth();
    if (!isStaff()) {
        // Check if this is an API/AJAX request - return JSON instead of redirecting
        if (function_exists('isApiRequest') && isApiRequest()) {
            http_response_code(403);
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            echo json_encode([
                'success' => false,
                'message' => 'Access denied. Staff privileges required.',
                'error' => 'insufficient_privileges'
            ]);
            exit;
        }
        
        $_SESSION['error'] = "Access denied. Staff privileges required.";
        // Staff should be redirected to faculty dashboard on access denial
        $basePath = getBasePath();
        redirect(clean_url($basePath . '/faculty/dashboard.php', $basePath));
    }
}
}

if (!function_exists('requireTimekeeper')) {
function requireTimekeeper() {
    if (!isTimekeeper()) {
        $_SESSION['error'] = "Access denied. Station access required.";
        $basePath = getBasePath();
        redirect(clean_url($basePath . '/station_login.php', $basePath));
    }
    
    // If station user tries to access dashboard, redirect to scanner
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'station') {
        $currentPage = basename($_SERVER['PHP_SELF']);
        if ($currentPage === 'dashboard.php') {
            $basePath = getBasePath();
            redirect(clean_url($basePath . '/timekeeper/qrcode-scanner.php', $basePath));
        }
    }
}
}

if (!function_exists('logAction')) {
function logAction($action, $description = '') {
    if (!isLoggedIn()) return;
    
    try {
        $database = Database::getInstance();
        $db = $database->getConnection();
        
        $stmt = $db->prepare("INSERT INTO system_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            $action,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (Exception $e) {
        error_log("Failed to log action: " . $e->getMessage());
    }
}
}

if (!function_exists('getStats')) {
function getStats() {
    try {
        $database = Database::getInstance();
        $db = $database->getConnection();
        
        $stats = [];
        
        // Total employees (staff and faculty, all active, verification no longer required)
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM users WHERE user_type IN ('faculty', 'staff') AND is_active = 1");
        $stmt->execute();
        $stats['total_faculty'] = $stmt->fetch()['total'];
        
        // Total PDS submitted
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM faculty_pds WHERE status = 'submitted'");
        $stmt->execute();
        $stats['pds_submitted'] = $stmt->fetch()['total'];
        
        // Pending requirements
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM faculty_submissions WHERE status = 'pending'");
        $stmt->execute();
        $stats['pending_submissions'] = $stmt->fetch()['total'];
        
        // Active requirements
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM requirements WHERE is_active = 1");
        $stmt->execute();
        $stats['active_requirements'] = $stmt->fetch()['total'];
        
        return $stats;
    } catch (Exception $e) {
        error_log("Failed to get stats: " . $e->getMessage());
        return [
            'total_faculty' => 0,
            'pds_submitted' => 0,
            'pending_submissions' => 0,
            'active_requirements' => 0
        ];
    }
}
}

if (!function_exists('displayMessage')) {
function displayMessage() {
    $messages = [];
    
    if (isset($_SESSION['success'])) {
        $messages[] = [
            'type' => 'success',
            'message' => $_SESSION['success'],
            'id' => 'msg_' . md5($_SESSION['success'] . time() . rand())
        ];
        unset($_SESSION['success']);
    }
    
    if (isset($_SESSION['error'])) {
        $messages[] = [
            'type' => 'danger',
            'message' => $_SESSION['error'],
            'id' => 'msg_' . md5($_SESSION['error'] . time() . rand())
        ];
        unset($_SESSION['error']);
    }
    
    if (isset($_SESSION['warning'])) {
        $messages[] = [
            'type' => 'warning',
            'message' => $_SESSION['warning'],
            'id' => 'msg_' . md5($_SESSION['warning'] . time() . rand())
        ];
        unset($_SESSION['warning']);
    }
    
    if (isset($_SESSION['info'])) {
        $messages[] = [
            'type' => 'info',
            'message' => $_SESSION['info'],
            'id' => 'msg_' . md5($_SESSION['info'] . time() . rand())
        ];
        unset($_SESSION['info']);
    }
    
    // Output messages as hidden data attributes that will be shown as toasts
    if (!empty($messages)) {
        echo '<div id="flash-messages" style="display: none;" data-messages="' . htmlspecialchars(json_encode($messages), ENT_QUOTES, 'UTF-8') . '"></div>';
    }
}
}

if (!function_exists('formatFileSize')) {
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}
}

/**
 * Ensure the requirement_attachments table exists. Called at runtime before using attachments.
 * Creates a simple table to track files faculty attach to requirements.
 */
if (!function_exists('ensureRequirementAttachmentsTable')) {
function ensureRequirementAttachmentsTable() {
    $database = Database::getInstance();
    $db = $database->getConnection();

    $sql = "CREATE TABLE IF NOT EXISTS requirement_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        requirement_id INT NOT NULL,
        faculty_id INT NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        original_filename VARCHAR(255),
        file_size INT,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (requirement_id) REFERENCES requirements(id) ON DELETE CASCADE,
        FOREIGN KEY (faculty_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    try {
        $db->exec($sql);
    } catch (Exception $e) {
        // If table creation fails, log and continue; uploads will fail later with DB errors.
        error_log('Failed to ensure requirement_attachments table: ' . $e->getMessage());
    }
}
}

/**
 * Simple pagination helper.
 * Returns an array with keys: page, limit, offset, total, totalPages
 * Usage: $p = getPaginationParams($db, $countSql, $params, 10);
 */
if (!function_exists('getPaginationParams')) {
function getPaginationParams($db, $countSql, $params = [], $perPage = 10) {
    $page = max(1, (int)($_GET['page'] ?? 1));

    // Get total count
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $limit = (int)$perPage;
    $totalPages = $limit > 0 ? max(1, (int)ceil($total / $limit)) : 1;

    if ($page > $totalPages) $page = $totalPages;

    $offset = ($page - 1) * $limit;

    return [
        'page' => $page,
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
        'totalPages' => $totalPages
    ];
}
}

/**
 * Render a bootstrap pagination control. Preserves current query params.
 * Example: echo renderPagination($p['page'], $p['totalPages']);
 * @param int $page Current page number
 * @param int $totalPages Total number of pages
 * @param string $fragment Optional URL fragment (e.g. '#section-admin-users') to scroll to after reload
 */
if (!function_exists('renderPagination')) {
function renderPagination($page, $totalPages, $fragment = '') {
    if ($totalPages <= 1) return '';

    $out = '<nav aria-label="pagination"><ul class="pagination justify-content-center">';

    $query = $_GET;
    $hash = $fragment ? htmlspecialchars($fragment, ENT_QUOTES, 'UTF-8') : '';

    if ($page > 1) {
        $query['page'] = $page - 1;
        $out .= '<li class="page-item"><a class="page-link" href="?' . http_build_query($query) . $hash . '">Previous</a></li>';
    }

    $start = max(1, $page - 2);
    $end = min($totalPages, $page + 2);

    for ($i = $start; $i <= $end; $i++) {
        $query['page'] = $i;
        $active = $i === $page ? ' active' : '';
        $out .= '<li class="page-item' . $active . '"><a class="page-link" href="?' . http_build_query($query) . $hash . '">' . $i . '</a></li>';
    }

    if ($page < $totalPages) {
        $query['page'] = $page + 1;
        $out .= '<li class="page-item"><a class="page-link" href="?' . http_build_query($query) . $hash . '">Next</a></li>';
    }

    $out .= '</ul></nav>';
    return $out;
}
}

/**
 * Reject a submission (fully implemented)
 * @param int $submissionId The submission ID to reject
 * @param string $adminNotes Optional admin notes for rejection
 * @return bool Success status
 */
if (!function_exists('rejectSubmission')) {
function rejectSubmission($submissionId, $adminNotes = '') {
    try {
        $database = Database::getInstance();
        $db = $database->getConnection();
        
        $stmt = $db->prepare("UPDATE faculty_submissions SET status = 'rejected', admin_notes = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ?");
        return $stmt->execute([$adminNotes, $_SESSION['user_id'] ?? null, $submissionId]);
    } catch (Exception $e) {
        error_log("Failed to reject submission: " . $e->getMessage());
        return false;
    }
}
}

/**
 * Handle resubmission (fully implemented)
 * @param int $submissionId The original submission ID
 * @param array $file The new file data
 * @return bool Success status
 */
if (!function_exists('resubmitFile')) {
function resubmitFile($submissionId, $file) {
    try {
        $database = Database::getInstance();
        $db = $database->getConnection();
        
        // Get original submission details
        $stmt = $db->prepare("SELECT * FROM faculty_submissions WHERE id = ?");
        $stmt->execute([$submissionId]);
        $original = $stmt->fetch();
        
        if (!$original) {
            return false;
        }
        
        // Create new version
        $newVersion = $original['version'] + 1;
        $stmt = $db->prepare("INSERT INTO faculty_submissions (faculty_id, requirement_id, file_path, original_filename, file_size, version, previous_submission_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        return $stmt->execute([
            $original['faculty_id'],
            $original['requirement_id'],
            $file['file_path'],
            $file['original_filename'],
            $file['file_size'],
            $newVersion,
            $submissionId
        ]);
    } catch (Exception $e) {
        error_log("Failed to resubmit file: " . $e->getMessage());
        return false;
    }
}
}

/**
 * Get the base URL of the application
 */
if (!function_exists('getBaseUrl')) {
function getBaseUrl() {
    // Detect protocol - handle proxies, load balancers, and IIS
    $protocol = 'http';
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        $protocol = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        $protocol = 'https';
    } elseif (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) {
        $protocol = 'https';
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
        $protocol = 'https';
    } elseif (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https') {
        $protocol = 'https';
    }
    
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    
    // Remove /includes, /admin, /faculty, /timekeeper from path if present
    $path = preg_replace('#/(includes|admin|faculty|timekeeper)$#', '', $path);
    
    return $protocol . '://' . $host . $path;
}
}

/**
 * Format time ago (e.g., "5 minutes ago", "2 hours ago")
 */
if (!function_exists('timeAgo')) {
function timeAgo($datetime) {
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = floor($diff / 60);
        return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $weeks = floor($diff / 604800);
        return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $timestamp);
    }
}
}

/**
 * Get all positions from position_salary table
 * Returns array of positions with title, salary grade, step, and annual salary
 * 
 * @return array Array of position records
 */
if (!function_exists('getAllPositions')) {
function getAllPositions() {
    try {
        $database = Database::getInstance();
        $db = $database->getConnection();
        
        $hasStep = false;
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM position_salary LIKE 'step'");
            $hasStep = $colCheck && $colCheck->rowCount() > 0;
        } catch (Exception $e) {}
        
        $cols = $hasStep ? "id, position_title, salary_grade, COALESCE(step, 1) as step, annual_salary" : "id, position_title, salary_grade, annual_salary";
        $order = $hasStep ? "ORDER BY position_title ASC, salary_grade ASC, step ASC" : "ORDER BY position_title ASC, salary_grade ASC";
        $stmt = $db->query("SELECT $cols FROM position_salary $order");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$hasStep) {
            foreach ($rows as &$r) {
                $r['step'] = 1;
            }
        }
        return $rows;
    } catch (Exception $e) {
        error_log("Failed to fetch positions: " . $e->getMessage());
        return [];
    }
}
}

/**
 * Get position details by title
 * Returns salary grade and annual salary for a given position
 * 
 * @param string $positionTitle The position title to look up
 * @return array|null Position details or null if not found
 */
if (!function_exists('getPositionByTitle')) {
function getPositionByTitle($positionTitle) {
    try {
        $database = Database::getInstance();
        $db = $database->getConnection();
        
        $stmt = $db->prepare("SELECT id, position_title, salary_grade, annual_salary FROM position_salary WHERE position_title = ? LIMIT 1");
        $stmt->execute([$positionTitle]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to fetch position details: " . $e->getMessage());
        return null;
    }
}
}

/**
 * Release session lock early and return session data
 * Use this for API endpoints to allow concurrent requests from same user
 * 
 * IMPORTANT: After calling this, $_SESSION will no longer be available!
 * Store needed values in the returned array before using them.
 * 
 * @param array $keysToKeep Optional array of session keys to preserve and return
 * @return array Associative array with session data for the requested keys
 * 
 * Example usage:
 * $sessionData = releaseSessionEarly(['user_id', 'user_type', 'station_id']);
 * // Now use $sessionData['user_id'] instead of $_SESSION['user_id']
 */
if (!function_exists('releaseSessionEarly')) {
function releaseSessionEarly($keysToKeep = []) {
    $sessionData = [];
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        // If no specific keys requested, grab common ones
        if (empty($keysToKeep)) {
            $keysToKeep = [
                'user_id', 'user_type', 'email', 
                'timekeeper_id', 'timekeeper_station_id', 
                'station_id', 'station_name',
                'admin_id', 'is_admin'
            ];
        }
        
        // Extract requested session data
        foreach ($keysToKeep as $key) {
            if (isset($_SESSION[$key])) {
                $sessionData[$key] = $_SESSION[$key];
            }
        }
        
        // Release the session lock
        session_write_close();
    }
    
    return $sessionData;
}
}

/**
 * Check if a request is an API/AJAX request
 * Used to determine if we should release session early
 * 
 * @return bool True if this appears to be an API request
 */
if (!function_exists('isApiRequest')) {
function isApiRequest() {
    // AJAX form/search patterns (employee_logs.php, etc.) — must receive JSON not login HTML
    if ((!empty($_GET['ajax']) && (string)$_GET['ajax'] === '1') ||
        (!empty($_POST['ajax']) && (string)$_POST['ajax'] === '1')) {
        return true;
    }
    // Check for common API indicators
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        return true;
    }
    
    // Check Accept header for JSON
    if (!empty($_SERVER['HTTP_ACCEPT']) && 
        strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        return true;
    }
    
    // Check Content-Type for JSON
    if (!empty($_SERVER['CONTENT_TYPE']) && 
        strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        return true;
    }
    
    // Check if path contains /api/
    if (!empty($_SERVER['REQUEST_URI']) && 
        strpos($_SERVER['REQUEST_URI'], '/api/') !== false) {
        return true;
    }
    
    // Check if filename contains _api (e.g., fetch_logs_api.php, notifications_api.php)
    if (!empty($_SERVER['PHP_SELF']) && 
        strpos($_SERVER['PHP_SELF'], '_api') !== false) {
        return true;
    }
    
    // Check if filename contains _api in REQUEST_URI
    if (!empty($_SERVER['REQUEST_URI']) && 
        preg_match('/[\/_]([^\/]+_api\.php)/', $_SERVER['REQUEST_URI'])) {
        return true;
    }
    
    return false;
}
}

/**
 * Graceful error handler for high-load situations
 * Returns a retry-friendly JSON response
 * 
 * @param Exception $e The exception to handle
 * @param string $context Optional context for logging
 * @return void Outputs JSON and exits
 */
if (!function_exists('handleHighLoadError')) {
function handleHighLoadError($e, $context = 'API') {
    $errorCode = 0;
    
    if ($e instanceof PDOException) {
        $errorCode = $e->errorInfo[1] ?? 0;
    }
    
    // Log the error
    error_log("$context error: " . $e->getMessage() . " (code: $errorCode)");
    
    // Determine if it's a transient error that can be retried
    $retryableErrors = [
        1213, // Deadlock
        1205, // Lock wait timeout
        2006, // MySQL server has gone away
        2013, // Lost connection to MySQL server
        2002, // Connection refused
    ];
    
    $isRetryable = in_array($errorCode, $retryableErrors);
    
    header('Content-Type: application/json');
    
    if ($isRetryable) {
        // Set a retry-after header (in seconds)
        header('Retry-After: 1');
        http_response_code(503); // Service Unavailable
        echo json_encode([
            'success' => false,
            'message' => 'System is busy. Please try again in a moment.',
            'retry' => true,
            'retry_after' => 1
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred. Please try again.',
            'retry' => false
        ]);
    }
    
    exit();
}
}

/**
 * Convert working hours/minutes to fractions of a day (based on 8-hour workday)
 * Implements Table IV: CONVERSION OF WORKING HOURS/MINUTES INTO FRACTIONS OF A DAY
 * 
 * @param int $hours Number of hours (0-8)
 * @param int $minutes Number of minutes (0-59)
 * @return float Decimal fraction of a day
 * 
 * Examples:
 * - hoursToDayFraction(1, 0) returns 0.125
 * - hoursToDayFraction(0, 15) returns 0.031
 * - hoursToDayFraction(8, 0) returns 1.000
 */
if (!function_exists('hoursToDayFraction')) {
function hoursToDayFraction($hours = 0, $minutes = 0) {
    // Validate inputs
    $hours = max(0, min(8, (int)$hours));
    $minutes = max(0, min(59, (int)$minutes));
    
    // Conversion table for hours (1-8 hours)
    $hoursTable = [
        1 => 0.125,
        2 => 0.250,
        3 => 0.375,
        4 => 0.500,
        5 => 0.625,
        6 => 0.750,
        7 => 0.875,
        8 => 1.000
    ];
    
    // Conversion table for minutes (1-59 minutes)
    // Based on the lookup table from the screenshot (Table IV)
    // Formula: minutes / 480 (since 8 hours = 480 minutes = 1.0 day)
    // Values for 28-30 and 58-59 are calculated using the same formula
    $minutesTable = [
        1 => 0.002,  2 => 0.004,  3 => 0.006,  4 => 0.008,  5 => 0.010,
        6 => 0.012,  7 => 0.015,  8 => 0.017,  9 => 0.019,  10 => 0.021,
        11 => 0.023, 12 => 0.025, 13 => 0.027, 14 => 0.029, 15 => 0.031,
        16 => 0.033, 17 => 0.035, 18 => 0.037, 19 => 0.040, 20 => 0.042,
        21 => 0.044, 22 => 0.046, 23 => 0.048, 24 => 0.050, 25 => 0.052,
        26 => 0.054, 27 => 0.056, 28 => 0.058, 29 => 0.060, 30 => 0.062,
        31 => 0.065, 32 => 0.067, 33 => 0.069, 34 => 0.071, 35 => 0.073,
        36 => 0.075, 37 => 0.077, 38 => 0.079, 39 => 0.081, 40 => 0.083,
        41 => 0.085, 42 => 0.087, 43 => 0.090, 44 => 0.092, 45 => 0.094,
        46 => 0.096, 47 => 0.098, 48 => 0.100, 49 => 0.102, 50 => 0.104,
        51 => 0.106, 52 => 0.108, 53 => 0.110, 54 => 0.112, 55 => 0.115,
        56 => 0.117, 57 => 0.119, 58 => 0.121, 59 => 0.123
    ];
    
    $totalFraction = 0.0;
    
    // Add hours fraction
    if ($hours > 0 && isset($hoursTable[$hours])) {
        $totalFraction += $hoursTable[$hours];
    }
    
    // Add minutes fraction
    if ($minutes > 0 && isset($minutesTable[$minutes])) {
        $totalFraction += $minutesTable[$minutes];
    }
    
    // Round to 3 decimal places to match table precision
    return round($totalFraction, 3);
}
}

/**
 * Convert total minutes to day fraction (based on 8-hour workday)
 * Helper function that converts total working minutes to fraction of a day
 * 
 * @param int $totalMinutes Total working minutes
 * @return float Decimal fraction of a day
 * 
 * Example:
 * - minutesToDayFraction(480) returns 1.000 (8 hours = 480 minutes)
 * - minutesToDayFraction(60) returns 0.125 (1 hour)
 * - minutesToDayFraction(75) returns 0.156 (1 hour 15 minutes)
 */
if (!function_exists('minutesToDayFraction')) {
function minutesToDayFraction($totalMinutes) {
    $totalMinutes = max(0, (int)$totalMinutes);
    
    // Calculate hours and remaining minutes
    $hours = floor($totalMinutes / 60);
    $minutes = $totalMinutes % 60;
    
    // Cap at 8 hours (480 minutes) for a full workday
    if ($hours >= 8) {
        return 1.000;
    }
    
    return hoursToDayFraction($hours, $minutes);
}
}

/**
 * Get the conversion table data (for display/reference purposes)
 * Returns the full conversion table as shown in the screenshot
 * 
 * @return array Array with 'hours' and 'minutes' conversion tables
 */
if (!function_exists('getDayFractionConversionTable')) {
function getDayFractionConversionTable() {
    return [
        'hours' => [
            1 => 0.125,
            2 => 0.250,
            3 => 0.375,
            4 => 0.500,
            5 => 0.625,
            6 => 0.750,
            7 => 0.875,
            8 => 1.000
        ],
        'minutes' => [
            1 => 0.002,  2 => 0.004,  3 => 0.006,  4 => 0.008,  5 => 0.010,
            6 => 0.012,  7 => 0.015,  8 => 0.017,  9 => 0.019,  10 => 0.021,
            11 => 0.023, 12 => 0.025, 13 => 0.027, 14 => 0.029, 15 => 0.031,
            16 => 0.033, 17 => 0.035, 18 => 0.037, 19 => 0.040, 20 => 0.042,
            21 => 0.044, 22 => 0.046, 23 => 0.048, 24 => 0.050, 25 => 0.052,
            26 => 0.054, 27 => 0.056, 28 => 0.058, 29 => 0.060, 30 => 0.062,
            31 => 0.065, 32 => 0.067, 33 => 0.069, 34 => 0.071, 35 => 0.073,
            36 => 0.075, 37 => 0.077, 38 => 0.079, 39 => 0.081, 40 => 0.083,
            41 => 0.085, 42 => 0.087, 43 => 0.090, 44 => 0.092, 45 => 0.094,
            46 => 0.096, 47 => 0.098, 48 => 0.100, 49 => 0.102, 50 => 0.104,
            51 => 0.106, 52 => 0.108, 53 => 0.110, 54 => 0.112, 55 => 0.115,
            56 => 0.117, 57 => 0.119, 58 => 0.121, 59 => 0.123
        ]
    ];
}
}

/**
 * Convert decimal total hours (e.g. 10.25) to day fractions using Table IV.
 * Matches assets/js/employee_logs.js hoursToDayFraction for summed hours (full 8h days + remainder).
 * Distinct from hoursToDayFraction($h,$m) which takes whole hours and minutes only.
 */
if (!function_exists('decimalHoursToDayFraction')) {
function decimalHoursToDayFraction($hours) {
    if ($hours === null || $hours <= 0) {
        return 0.0;
    }
    $hoursTable = [1 => 0.125, 2 => 0.25, 3 => 0.375, 4 => 0.5, 5 => 0.625, 6 => 0.75, 7 => 0.875, 8 => 1.0];
    $minutesTable = [
        1 => 0.002, 2 => 0.004, 3 => 0.006, 4 => 0.008, 5 => 0.01, 6 => 0.012, 7 => 0.015, 8 => 0.017, 9 => 0.019, 10 => 0.021,
        11 => 0.023, 12 => 0.025, 13 => 0.027, 14 => 0.029, 15 => 0.031, 16 => 0.033, 17 => 0.035, 18 => 0.037, 19 => 0.04, 20 => 0.042,
        21 => 0.044, 22 => 0.046, 23 => 0.048, 24 => 0.05, 25 => 0.052, 26 => 0.054, 27 => 0.056, 28 => 0.058, 29 => 0.06, 30 => 0.062,
        31 => 0.065, 32 => 0.067, 33 => 0.069, 34 => 0.071, 35 => 0.073, 36 => 0.075, 37 => 0.077, 38 => 0.079, 39 => 0.081, 40 => 0.083,
        41 => 0.085, 42 => 0.087, 43 => 0.09, 44 => 0.092, 45 => 0.094, 46 => 0.096, 47 => 0.098, 48 => 0.1, 49 => 0.102, 50 => 0.104,
        51 => 0.106, 52 => 0.108, 53 => 0.11, 54 => 0.112, 55 => 0.115, 56 => 0.117, 57 => 0.119, 58 => 0.121, 59 => 0.123,
    ];
    $fullDays = (int) floor($hours / 8);
    $fullDaysFraction = $fullDays * 1.0;
    $remainingHours = fmod((float) $hours, 8.0);
    if ($remainingHours < 0) {
        $remainingHours += 8.0;
    }
    $wholeHours = (int) floor($remainingHours);
    $decimalMinutes = ($remainingHours - $wholeHours) * 60;
    $wholeMinutes = (int) round($decimalMinutes);
    $remainingFraction = 0.0;
    if ($wholeHours > 0 && $wholeHours <= 8) {
        $remainingFraction += $hoursTable[$wholeHours] ?? 0;
    }
    if ($wholeMinutes > 0 && $wholeMinutes <= 59) {
        $remainingFraction += $minutesTable[$wholeMinutes] ?? 0;
    }
    return round($fullDaysFraction + $remainingFraction, 3);
}
}
?>