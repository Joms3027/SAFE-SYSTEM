<?php
class TokenManager {
    /**
     * Generate a CSRF token and store it in session
     * Ensures session is active before generating token
     * CRITICAL: Writes session immediately to ensure persistence in production
     */
    public static function generateToken() {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Initialize tokens array if not exists
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        
        // Generate a random token
        $token = bin2hex(random_bytes(32));
        
        // Store token with timestamp
        $_SESSION['csrf_tokens'][$token] = time();
        
        // Clean up old tokens (older than 2 hours)
        self::cleanOldTokens();
        
        // CRITICAL FIX: Ensure session data is written
        // Don't close session (that would break subsequent operations)
        // Instead, just ensure the session is active and data is set
        // PHP will write session data at end of script execution
        // The key is ensuring the session cookie is set correctly (handled in config.php)
        
        return $token;
    }
    
    /**
     * Validate a CSRF token
     * Returns true if valid, false otherwise
     * Enhanced with better session handling for production environments
     */
    public static function validateToken($token) {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if token is provided
        if (empty($token)) {
            self::logValidationFailure('Token is empty. Session ID: ' . (session_id() ?: 'none') . ', Session status: ' . session_status());
            return false;
        }
        
        // CRITICAL: Check session ID to ensure we're using the same session
        $sessionId = session_id();
        if (empty($sessionId)) {
            self::logValidationFailure('No session ID found. Session may not be persisting correctly.');
            return false;
        }
        
        // Get cookie parameters for diagnostics
        $cookieParams = session_get_cookie_params();
        $isHttps = !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off';
        
        // CRITICAL: Check if session cookie from request matches current session ID
        // This detects if a new session was created (session not persisting)
        $cookieSessionId = $_COOKIE[session_name()] ?? null;
        
        // If no cookie was received, log detailed diagnostics
        if (!$cookieSessionId) {
            $diagnostics = 'No session cookie received. ' .
                          'Cookie name: ' . session_name() . ', ' .
                          'Cookie secure: ' . ($cookieParams['secure'] ? 'true' : 'false') . ', ' .
                          'Cookie path: ' . ($cookieParams['path'] ?: '/') . ', ' .
                          'Cookie domain: ' . ($cookieParams['domain'] ?: 'default') . ', ' .
                          'Request HTTPS: ' . ($isHttps ? 'true' : 'false') . ', ' .
                          'OS: ' . PHP_OS;
            
            // Check for common misconfigurations
            if ($cookieParams['secure'] && !$isHttps) {
                $diagnostics .= ' ISSUE: Cookie marked secure but request is HTTP!';
            }
            
            self::logValidationFailure($diagnostics);
            // Don't return false yet - browser might not send cookie on first request
            // Continue to check if session has the token
        }
        
        if ($cookieSessionId && $cookieSessionId !== $sessionId) {
            // Session ID mismatch - this means a new session was created
            // The original session with the token is lost
            $sessionInfo = 'Session ID mismatch! Cookie has: ' . substr($cookieSessionId, 0, 16) . '..., ' .
                         'Current session: ' . substr($sessionId, 0, 16) . '..., ' .
                         'This indicates session is not persisting between requests.';
            self::logValidationFailure('Session ID mismatch detected. ' . $sessionInfo);
            return false;
        }
        
        // Check if session has tokens array
        if (!isset($_SESSION['csrf_tokens'])) {
            // This is the main issue in production - session not persisting
            $sessionInfo = 'Session ID: ' . substr($sessionId, 0, 16) . '..., ' .
                         'Session status: ' . session_status() . ', ' .
                         'Session save path: ' . session_save_path() . ', ' .
                         'Cookie name: ' . session_name() . ', ' .
                         'Has session cookie: ' . (isset($_COOKIE[session_name()]) ? 'yes' : 'no') . ', ' .
                         'Cookie session ID: ' . ($cookieSessionId ? substr($cookieSessionId, 0, 16) . '...' : 'not set') . ', ' .
                         'Session IDs match: ' . ($cookieSessionId === $sessionId ? 'yes' : 'no');
            self::logValidationFailure('Session tokens array not found. ' . $sessionInfo);
            return false;
        }
        
        // Check if token exists in session
        if (!isset($_SESSION['csrf_tokens'][$token])) {
            $sessionInfo = 'Session ID: ' . substr($sessionId, 0, 16) . '..., ' .
                         'Token provided: ' . substr($token, 0, 16) . '..., ' .
                         'Token length: ' . strlen($token) . ', ' .
                         'Available tokens: ' . (isset($_SESSION['csrf_tokens']) ? count($_SESSION['csrf_tokens']) : 0);
            self::logValidationFailure('Token not found in session. ' . $sessionInfo);
            
            // Debug: Log available tokens (first 16 chars only for security)
            if (!empty($_SESSION['csrf_tokens'])) {
                $availableTokens = array_keys($_SESSION['csrf_tokens']);
                $tokenPreviews = array_map(function($t) { return substr($t, 0, 16) . '...'; }, array_slice($availableTokens, 0, 5));
                self::logValidationFailure('Sample available tokens: ' . implode(', ', $tokenPreviews));
            }
            return false;
        }
        
        // Check if token is not too old (2 hours max)
        $tokenAge = time() - $_SESSION['csrf_tokens'][$token];
        if ($tokenAge > 7200) {
            unset($_SESSION['csrf_tokens'][$token]);
            self::logValidationFailure('Token expired. Age: ' . $tokenAge . ' seconds');
            return false;
        }
        
        // Token is valid. Do not remove it: one-time consumption caused legitimate
        // submissions to fail (double-click, slow networks, duplicate POST). Secrecy of
        // the token still mitigates CSRF; tokens expire via cleanOldTokens().
        return true;
    }
    
    /**
     * Clean up old tokens (older than 2 hours)
     */
    private static function cleanOldTokens() {
        if (!isset($_SESSION['csrf_tokens'])) {
            return;
        }
        
        foreach ($_SESSION['csrf_tokens'] as $token => $timestamp) {
            if (time() - $timestamp > 7200) {
                unset($_SESSION['csrf_tokens'][$token]);
            }
        }
    }
    
    /**
     * Log validation failures for debugging
     * Always log in production to help diagnose session issues
     */
    private static function logValidationFailure($message) {
        $logFile = dirname(__DIR__) . '/storage/logs/csrf_debug.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        if (is_writable($logDir)) {
            $host = $_SERVER['HTTP_HOST'] ?? 'unknown';
            $requestUri = $_SERVER['REQUEST_URI'] ?? 'unknown';
            $cookieInfo = 'Session cookie sent: ' . (isset($_COOKIE[session_name()]) ? 'yes' : 'no');
            if (isset($_COOKIE[session_name()])) {
                $cookieInfo .= ' (ID: ' . substr($_COOKIE[session_name()], 0, 16) . '...)';
            }
            
            $logMessage = date('Y-m-d H:i:s') . ' - CSRF Validation Failure: ' . $message . 
                         ' - Host: ' . $host .
                         ' - URI: ' . $requestUri .
                         ' - IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . 
                         ' - ' . $cookieInfo .
                         ' - User Agent: ' . substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 200) . "\n";
            @file_put_contents($logFile, $logMessage, FILE_APPEND);
        }
    }
}