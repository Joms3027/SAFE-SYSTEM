<?php
/**
 * Session Optimization for Concurrent Users
 * 
 * This file provides optimizations for handling multiple concurrent users
 * without session blocking or conflicts.
 * 
 * Include this file BEFORE session_start() in config.php or early in your application.
 */

/**
 * Configure session settings optimized for concurrent access
 * Should be called BEFORE session_start()
 */
if (!function_exists('configureOptimizedSessions')) {
function configureOptimizedSessions() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Session already started, can't modify settings
        return false;
    }
    
    // Use strict mode to prevent session fixation attacks
    @ini_set('session.use_strict_mode', 1);
    
    // Use cookies only (no URL-based sessions - more secure and avoids conflicts)
    @ini_set('session.use_only_cookies', 1);
    @ini_set('session.use_trans_sid', 0);
    
    // Optimize garbage collection for high traffic
    // Lower probability means less GC overhead during requests
    @ini_set('session.gc_probability', 1);
    @ini_set('session.gc_divisor', 1000); // 0.1% chance per request
    
    // Increase session ID entropy for better uniqueness
    @ini_set('session.sid_length', 48);
    @ini_set('session.sid_bits_per_character', 6);
    
    // Use lazy_write if available (PHP 7.0+)
    // This reduces writes when session data hasn't changed
    if (PHP_VERSION_ID >= 70000) {
        @ini_set('session.lazy_write', 1);
    }
    
    return true;
}
}

/**
 * Custom session handler for better concurrency (optional)
 * Can be used to implement database or Redis-based sessions
 * 
 * Optimized for Windows/IIS with non-blocking reads and short lock timeouts
 * This prevents session blocking when multiple users access simultaneously
 */
class OptimizedSessionHandler implements SessionHandlerInterface {
    private $savePath;
    private $sessionId;
    private $lockHandle;
    private $sessionData;
    private $dataChanged = false;
    private $lockTimeout = 3.0; // Maximum seconds to wait for lock (reduced for responsiveness)
    private $isWindows;
    
    public function __construct() {
        $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }
    
    public function open($savePath, $sessionName): bool {
        $this->savePath = $savePath;
        if (!is_dir($this->savePath)) {
            @mkdir($this->savePath, 0755, true);
        }
        return true;
    }
    
    public function close(): bool {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
        return true;
    }
    
    public function read($sessionId): string|false {
        $this->sessionId = $sessionId;
        $file = $this->getSessionFile($sessionId);
        
        if (!file_exists($file)) {
            return '';
        }
        
        // Try to get a lock (non-blocking first for reads)
        $this->lockHandle = @fopen($file, 'c+');
        if (!$this->lockHandle) {
            return '';
        }
        
        // Try non-blocking shared lock for reading
        if (!flock($this->lockHandle, LOCK_SH | LOCK_NB)) {
            // Can't get lock immediately - wait with timeout
            $startTime = microtime(true);
            
            while (!flock($this->lockHandle, LOCK_SH | LOCK_NB)) {
                if (microtime(true) - $startTime > $this->lockTimeout) {
                    // On Windows/IIS, log warning but try to continue with unlocked read
                    if ($this->isWindows) {
                        error_log("Session lock timeout for: $sessionId (Windows - continuing with unlocked read)");
                        // Try direct file read without lock (better than no session at all)
                        $data = @file_get_contents($file);
                        if ($data !== false) {
                            fclose($this->lockHandle);
                            $this->lockHandle = null;
                            $this->sessionData = $data;
                            return $data;
                        }
                    }
                    error_log("Session lock timeout for: $sessionId");
                    fclose($this->lockHandle);
                    $this->lockHandle = null;
                    return '';
                }
                usleep(5000); // 5ms wait (reduced from 10ms for faster response)
            }
        }
        
        $data = '';
        while (!feof($this->lockHandle)) {
            $data .= fread($this->lockHandle, 8192);
        }
        
        $this->sessionData = $data;
        return $data;
    }
    
    public function write($sessionId, $data): bool {
        // Only write if data changed (optimization)
        if ($this->sessionData === $data) {
            return true;
        }
        
        $file = $this->getSessionFile($sessionId);
        
        // Upgrade to exclusive lock for writing
        if ($this->lockHandle) {
            // Try non-blocking exclusive lock first
            if (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
                // Wait with timeout for exclusive lock
                $startTime = microtime(true);
                while (!flock($this->lockHandle, LOCK_EX | LOCK_NB)) {
                    if (microtime(true) - $startTime > $this->lockTimeout) {
                        error_log("Session write lock timeout for: $sessionId");
                        // Use atomic write fallback
                        return $this->atomicWrite($file, $data);
                    }
                    usleep(5000);
                }
            }
            
            // Truncate and write
            ftruncate($this->lockHandle, 0);
            rewind($this->lockHandle);
            fwrite($this->lockHandle, $data);
            fflush($this->lockHandle);
        } else {
            // No lock handle, use atomic write
            return $this->atomicWrite($file, $data);
        }
        
        return true;
    }
    
    /**
     * Atomic write using temp file and rename
     * This is safer for concurrent access
     */
    private function atomicWrite($file, $data): bool {
        $tempFile = $file . '.tmp.' . getmypid() . '.' . mt_rand();
        $result = @file_put_contents($tempFile, $data, LOCK_EX);
        if ($result === false) {
            @unlink($tempFile);
            return false;
        }
        
        // Rename is atomic on most filesystems
        if (!@rename($tempFile, $file)) {
            // Fallback: copy and delete
            @copy($tempFile, $file);
            @unlink($tempFile);
        }
        return true;
    }
    
    public function destroy($sessionId): bool {
        $file = $this->getSessionFile($sessionId);
        
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            $this->lockHandle = null;
        }
        
        if (file_exists($file)) {
            @unlink($file);
        }
        
        return true;
    }
    
    public function gc($maxLifetime): int|false {
        $count = 0;
        $files = glob($this->savePath . '/sess_*');
        
        if ($files === false) {
            return 0;
        }
        
        $now = time();
        foreach ($files as $file) {
            // Skip temp files
            if (strpos($file, '.tmp.') !== false) {
                // Clean up old temp files (older than 1 hour)
                if (filemtime($file) + 3600 < $now) {
                    @unlink($file);
                }
                continue;
            }
            
            if (filemtime($file) + $maxLifetime < $now) {
                @unlink($file);
                $count++;
            }
        }
        
        return $count;
    }
    
    private function getSessionFile($sessionId): string {
        return $this->savePath . '/sess_' . $sessionId;
    }
}

/**
 * Enable optimized session handler
 * Call this BEFORE session_start() if you want to use the custom handler
 * 
 * Note: This is optional - PHP's default handler works fine for most cases.
 * Use this only if you're experiencing session locking issues.
 */
if (!function_exists('enableOptimizedSessionHandler')) {
function enableOptimizedSessionHandler($savePath = null) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return false;
    }
    
    if ($savePath === null) {
        $savePath = session_save_path();
        if (empty($savePath)) {
            $savePath = sys_get_temp_dir();
        }
    }
    
    $handler = new OptimizedSessionHandler();
    session_set_save_handler($handler, true);
    session_save_path($savePath);
    
    return true;
}
}

/**
 * Close session early for read-only operations
 * CRITICAL: Use this in AJAX/API endpoints that only READ session data
 * This prevents session file locking and allows concurrent access
 * 
 * IMPORTANT: Only use this AFTER you've read all needed session data
 * Once closed, you cannot write to $_SESSION until session_start() is called again
 * 
 * @param bool $readOnly If true, closes session after reading (default: true)
 * @return void
 */
if (!function_exists('closeSessionEarly')) {
function closeSessionEarly($readOnly = true) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Save any pending session data before closing
        // This ensures last_activity and other session updates are persisted
        $_SESSION['last_activity'] = time();
        session_write_close();
    }
}
}

/**
 * Verify session integrity without blocking other requests
 * Use this to check if current session is valid without causing locks
 * 
 * @return bool True if session appears valid
 */
if (!function_exists('verifySessionIntegrity')) {
function verifySessionIntegrity() {
    // Basic validation - check session is active and has expected data
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return false;
    }
    
    // Check for critical session markers
    if (!isset($_SESSION['last_activity'])) {
        // Initialize if missing (shouldn't happen but prevents logout)
        $_SESSION['last_activity'] = time();
    }
    
    // For station/timekeeper sessions, use very long timeout (30 days)
    $isTimekeeperSession = isset($_SESSION['user_type']) && 
        in_array($_SESSION['user_type'], ['station', 'timekeeper']);
    
    // CRITICAL FIX: Use consistent timeout values with security.php
    // All sessions should use very long timeout (10 years) to prevent unexpected logouts
    // This matches the timeout in security.php secureSession() function
    // Timekeeper/station sessions use 30 days, regular users use 10 years (effectively never expire)
    $timeout = $isTimekeeperSession ? 2592000 : 315360000; // 30 days for timekeeper, 10 years for regular users
    
    // Only logout if session is actually expired
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        return false;
    }
    
    return true;
}
}

/**
 * Ensure session is valid and refresh activity timestamp
 * Prevents random logouts during concurrent access
 * 
 * @return bool True if session is valid and refreshed
 */
if (!function_exists('refreshSessionActivity')) {
function refreshSessionActivity() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        // Only update if session has user data
        if (isset($_SESSION['user_id']) || isset($_SESSION['station_id'])) {
            $_SESSION['last_activity'] = time();
            return true;
        }
    }
    return false;
}
}

/**
 * Check if current request is a read-only API call
 * Useful for automatically closing sessions early
 * 
 * @return bool True if this appears to be a read-only API call
 */
if (!function_exists('isReadOnlyApiCall')) {
function isReadOnlyApiCall() {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    
    // GET requests are typically read-only
    if ($method === 'GET') {
        return true;
    }
    
    // Check for specific read-only actions in POST requests
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    $readOnlyActions = [
        'get', 'fetch', 'read', 'list', 'count', 
        'check', 'verify', 'validate', 'search'
    ];
    
    foreach ($readOnlyActions as $readOnlyAction) {
        if (stripos($action, $readOnlyAction) === 0) {
            return true;
        }
    }
    
    return false;
}
}

/**
 * Safely handle concurrent requests to prevent session conflicts
 * Call this at the start of API handlers to ensure proper session management
 * 
 * @param array $requiredSessionKeys Keys that must be present in session
 * @param bool $readOnly If true, close session after reading
 * @return array|false Session data array if valid, false if invalid session
 */
if (!function_exists('handleConcurrentApiRequest')) {
function handleConcurrentApiRequest($requiredSessionKeys = [], $readOnly = false) {
    // Ensure session is started
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Try to start session with short timeout
        $started = @session_start();
        if (!$started) {
            return false;
        }
    }
    
    // Extract required session data
    $sessionData = [];
    $defaultKeys = ['user_id', 'user_type', 'station_id', 'timekeeper_id', 'last_activity'];
    $keysToExtract = array_unique(array_merge($defaultKeys, $requiredSessionKeys));
    
    foreach ($keysToExtract as $key) {
        if (isset($_SESSION[$key])) {
            $sessionData[$key] = $_SESSION[$key];
        }
    }
    
    // Validate session has required data
    $hasUser = isset($sessionData['user_id']) || isset($sessionData['station_id']);
    if (!$hasUser) {
        return false;
    }
    
    // Refresh activity timestamp before potentially closing
    $_SESSION['last_activity'] = time();
    
    // For read-only operations, close session to allow concurrent access
    if ($readOnly) {
        session_write_close();
    }
    
    return $sessionData;
}
}

/**
 * Get session data without blocking
 * Useful for AJAX requests that only need to read session data
 * 
 * @param string $sessionId Optional session ID (uses current if not provided)
 * @return array Session data
 */
if (!function_exists('readSessionNonBlocking')) {
function readSessionNonBlocking($sessionId = null) {
    if ($sessionId === null) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Session already active, just return data
            return $_SESSION;
        }
        
        // Get session ID from cookie
        $sessionId = $_COOKIE[session_name()] ?? null;
        if (!$sessionId) {
            return [];
        }
    }
    
    // Read session file directly (non-blocking)
    $savePath = session_save_path();
    if (empty($savePath)) {
        $savePath = sys_get_temp_dir();
    }
    
    $file = $savePath . '/sess_' . $sessionId;
    if (!file_exists($file)) {
        return [];
    }
    
    // Try to read without blocking
    $fp = @fopen($file, 'r');
    if (!$fp) {
        return [];
    }
    
    if (!flock($fp, LOCK_SH | LOCK_NB)) {
        fclose($fp);
        return []; // File is locked, skip
    }
    
    $data = '';
    while (!feof($fp)) {
        $data .= fread($fp, 8192);
    }
    
    flock($fp, LOCK_UN);
    fclose($fp);
    
    if (empty($data)) {
        return [];
    }
    
    // Parse PHP session format
    // Note: This is a simplified parser, may not handle all edge cases
    $result = [];
    session_start();
    session_decode($data);
    $result = $_SESSION;
    session_write_close();
    
    return $result;
}
}
?>
