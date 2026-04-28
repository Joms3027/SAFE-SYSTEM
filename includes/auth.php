<?php
require_once 'config.php';
require_once 'database.php';

class Auth {
    private $db;
    
    public function __construct() {
        $database = Database::getInstance();
        $this->db = $database->getConnection();
    }
    
    // Registration disabled - Faculty accounts are now created by admin only
    public function register($email, $password, $firstName, $lastName, $token) {
        return "Registration is disabled. Please contact administrator to create your account.";
    }
    
    public function login($email, $password, $token) {
        // Ensure session is active before validation
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // CRITICAL DEBUG: Log session state before validation
        $sessionId = session_id();
        $cookieName = session_name();
        $hasSessionCookie = isset($_COOKIE[$cookieName]);
        $cookieValue = $_COOKIE[$cookieName] ?? 'not set';
        $hasTokensArray = isset($_SESSION['csrf_tokens']);
        $tokenCount = $hasTokensArray ? count($_SESSION['csrf_tokens']) : 0;
        $cookieParams = session_get_cookie_params();
        
        // Check if session IDs match (critical for detecting session persistence issues)
        $sessionIdMatch = $hasSessionCookie && ($cookieValue === $sessionId);
        
        // Log session info for debugging (always log in production to diagnose)
        $debugInfo = [
            'session_id' => $sessionId ? substr($sessionId, 0, 16) . '...' : 'none',
            'session_status' => session_status(),
            'cookie_name' => $cookieName,
            'cookie_sent' => $hasSessionCookie ? 'yes' : 'no',
            'cookie_value' => $hasSessionCookie ? substr($cookieValue, 0, 16) . '...' : 'not set',
            'session_ids_match' => $sessionIdMatch ? 'yes' : 'no',
            'cookie_domain' => $cookieParams['domain'] ?: 'empty',
            'cookie_secure' => $cookieParams['secure'] ? 'yes' : 'no',
            'cookie_samesite' => $cookieParams['samesite'] ?? 'not set',
            'has_tokens_array' => $hasTokensArray ? 'yes' : 'no',
            'token_count' => $tokenCount,
            'token_provided' => !empty($token) ? 'yes (' . strlen($token) . ' chars)' : 'no',
            'host' => $_SERVER['HTTP_HOST'] ?? 'unknown',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
            'is_https' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                         (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ];
        
        // Validate CSRF token
        if (!validateFormToken($token)) {
            // Log detailed information for debugging
            $sessionInfo = "Session ID: " . $debugInfo['session_id'] . 
                          ", Session status: " . $debugInfo['session_status'] . 
                          ", Cookie sent: " . $debugInfo['cookie_sent'] .
                          ", Session IDs match: " . $debugInfo['session_ids_match'] .
                          ", Cookie domain: " . $debugInfo['cookie_domain'] .
                          ", Cookie secure: " . $debugInfo['cookie_secure'] .
                          ", HTTPS: " . ($debugInfo['is_https'] ? 'yes' : 'no') .
                          ", Has tokens array: " . $debugInfo['has_tokens_array'] .
                          ", Token count: " . $debugInfo['token_count'] .
                          ", Token provided: " . $debugInfo['token_provided'];
            
            logSecurityEvent('CSRF_TOKEN_INVALID', "Failed CSRF validation for email: $email. $sessionInfo");
            
            // Enhanced error message with actionable advice based on specific issue
            $errorMsg = "Invalid form submission. ";
            
            // Determine specific issue for better error messaging
            $isSecureMismatch = $cookieParams['secure'] && !$debugInfo['is_https'];
            
            if ($isSecureMismatch) {
                // This is the most common cause on HTTP servers
                $errorMsg .= "Cookie security configuration issue. Please contact the system administrator.";
                error_log("CRITICAL FIX NEEDED: Cookie marked as 'secure' but request is HTTP. Update server to use HTTPS or reconfigure session cookies.");
            } elseif (!$hasSessionCookie) {
                $errorMsg .= "Session cookie not found. Please ensure cookies are enabled in your browser. ";
            } elseif (!$sessionIdMatch) {
                $errorMsg .= "Session expired or reset. ";
            } elseif (!$hasTokensArray) {
                $errorMsg .= "Session not persisting between requests. ";
            }
            
            $errorMsg .= "Please refresh the page and try again. If this persists, please clear your browser cookies and try again.";
            
            return $errorMsg;
        }
        
        // Rate limiting - use email and IP address
        $identifier = $email . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        
        if (!checkRateLimit($identifier, 5, 900)) { // 5 attempts per 15 minutes
            $remaining = getRateLimitRemaining($identifier);
            $minutes = ceil($remaining / 60);
            logSecurityEvent('RATE_LIMIT_EXCEEDED', "Too many login attempts for: $email");
            return "Too many login attempts. Please try again in $minutes minute(s).";
        }
        
        // Get all users with this email (since same email can be used for admin and faculty/staff)
        // CRITICAL: Order by user_type to prioritize admin users first when multiple accounts exist
        // This ensures admin users are checked before faculty/staff with the same email
        // PERFORMANCE: Normalize email once and use direct comparison to leverage idx_users_email index
        // Database triggers already ensure emails are stored as LOWER(TRIM(email)), so we can use index
        $normalizedEmail = strtolower(trim($email));
        $stmt = $this->db->prepare("SELECT id, email, password, user_type, first_name, last_name, is_active FROM users WHERE email = ? ORDER BY CASE WHEN user_type = 'super_admin' THEN 0 WHEN user_type = 'admin' THEN 1 WHEN user_type = 'timekeeper' THEN 2 WHEN user_type = 'staff' THEN 3 ELSE 4 END, id");
        $stmt->execute([$normalizedEmail]);
        $users = $stmt->fetchAll();
        
        if (empty($users)) {
            // Don't reveal if email exists - generic error message
            return "Invalid email or password.";
        }
        
        // Try to find a matching user with correct password
        // Admin users will be checked first due to ORDER BY clause above
        foreach ($users as $user) {
            if (password_verify($password, $user['password'])) {
                if (!$user['is_active']) {
                    logSecurityEvent('LOGIN_ATTEMPT_INACTIVE', "Login attempt for inactive account: $email");
                    return "Your account has been deactivated. Please contact administrator.";
                }
                
                // Clear rate limit on successful login
                clearRateLimit($identifier);
                
                // Regenerate session ID to prevent session fixation
                regenerateSessionId();
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['last_activity'] = time();
                
                // Clear redirect counter on successful login
                unset($_SESSION['index_redirect_count']);
                
                logAction('LOGIN', "User logged in: $email (Type: {$user['user_type']})");
                logSecurityEvent('LOGIN_SUCCESS', "Successful login: $email");
                
                // CRITICAL: Write session data synchronously before redirect
                // This prevents race conditions where the redirect happens before
                // the session file is fully written, causing the "login twice" issue
                session_write_close();
                
                // Restart session for any subsequent operations in the same request
                session_start();
                
                return "success";
            }
        }
        
        // Failed login attempt
        logSecurityEvent('LOGIN_FAILED', "Failed login attempt for: $email");
        return "Invalid email or password.";
    }
    
    public function logout($redirect = true) {
        // Log the logout action before clearing session
        $userType = $_SESSION['user_type'] ?? '';
        $userId = $_SESSION['user_id'] ?? null;
        
        if (isset($_SESSION['user_email'])) {
            logAction('LOGOUT', "User logged out: " . $_SESSION['user_email']);
        } elseif (isset($_SESSION['employee_id'])) {
            logAction('TIMEKEEPER_LOGOUT', "Timekeeper logged out: " . $_SESSION['employee_id']);
        }
        
        // Remove from user_activity so green dot disappears immediately for employees
        if ($userId && in_array($userType, ['admin', 'super_admin'])) {
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("DELETE FROM user_activity WHERE user_id = ?");
                $stmt->execute([$userId]);
            } catch (Exception $e) {
                // Silent fail
            }
        }
        
        // Unset all session variables
        $_SESSION = array();
        
        // Destroy the session cookie if it exists
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
            setcookie(session_name(), '', time() - 3600, '/', '', false, true); // Also clear with httponly
        }
        
        // Destroy the session
        session_destroy();
        
        // Redirect to appropriate login page based on user type (if requested)
        if ($redirect) {
            // Use SITE_URL for absolute URLs to ensure redirects work correctly
            $baseUrl = defined('SITE_URL') ? SITE_URL : '';
            if ($userType === 'timekeeper' || $userType === 'station') {
                $loginPath = $baseUrl ? $baseUrl . '/station_login.php' : '/station_login.php';
            } else {
                $loginPath = $baseUrl ? $baseUrl . '/login.php' : '/login.php';
            }
            redirect($loginPath);
        }
        
        return $userType;
    }
    
    public function createAdmin($email, $password, $firstName, $lastName, $userType = 'admin') {
        if (!isSuperAdmin()) {
            return "Access denied. Only super admins can create admin accounts.";
        }
        
        // Only super admins can set admin or super_admin
        $allowedTypes = ['admin', 'super_admin'];
        if (!in_array($userType, $allowedTypes)) {
            $userType = 'admin';
        }
        
        // Normalize email (trim and lowercase)
        $email = trim(strtolower($email));
        
        // Check if email exists for the same user type
        $stmt = $this->db->prepare("SELECT id, user_type FROM users WHERE LOWER(TRIM(email)) = ? AND user_type IN ('admin', 'super_admin')");
        $stmt->execute([$email]);
        $existingUser = $stmt->fetch();
        
        if ($existingUser) {
            return "This email address is already registered as an Admin account. Each email can only be used once per user type.";
        }
        
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $this->db->prepare("INSERT INTO users (email, password, user_type, first_name, last_name, is_verified) VALUES (?, ?, ?, ?, ?, 1)");
        
        if ($stmt->execute([$email, $hashedPassword, $userType, $firstName, $lastName])) {
            logAction('CREATE_ADMIN', "New {$userType} created: $email");
            return "success";
        }
        
        return "Failed to create admin user.";
    }
    
    public function resetPassword($email) {
        $stmt = $this->db->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? AND is_verified = 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            $tempPassword = bin2hex(random_bytes(4));
            $hashedPassword = password_hash($tempPassword, PASSWORD_DEFAULT);
            
            $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE email = ?");
            if ($stmt->execute([$hashedPassword, $email])) {
                // Store password reset info for display
                $_SESSION['password_reset'] = [
                    'email' => $email,
                    'password' => $tempPassword,
                    'user_name' => $user['first_name'] . ' ' . $user['last_name'],
                    'timestamp' => time()
                ];
                
                logAction('PASSWORD_RESET', "Password reset for: $email");
                return "success";
            }
        }
        
        return "Failed to reset password. Please try again.";
    }
    
    /**
     * Request password reset - generates token and sends email
     * Only available for employees (faculty/staff), not for admin users
     */
    public function requestPasswordReset($email) {
        // Normalize email
        $email = trim(strtolower($email));
        
        // Check if user exists and is an employee (faculty or staff), not admin
        $stmt = $this->db->prepare("SELECT id, first_name, last_name, is_active, user_type FROM users WHERE LOWER(TRIM(email)) = ? AND is_verified = 1 AND user_type IN ('faculty', 'staff')");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if (!$user) {
            // Don't reveal if email exists or if it's an admin - generic message
            return "success"; // Return success even if user doesn't exist (security best practice)
        }
        
        if (!$user['is_active']) {
            return "Your account has been deactivated. Please contact administrator.";
        }
        
        // Generate secure token
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiration
        
        // Delete any existing tokens for this email
        $stmt = $this->db->prepare("DELETE FROM password_reset_tokens WHERE email = ?");
        $stmt->execute([$email]);
        
        // Insert new token
        $stmt = $this->db->prepare("INSERT INTO password_reset_tokens (email, token, user_id, expires_at) VALUES (?, ?, ?, ?)");
        if ($stmt->execute([$email, $token, $user['id'], $expiresAt])) {
            logAction('PASSWORD_RESET_REQUESTED', "Password reset requested for: $email");
            return ['success' => true, 'token' => $token, 'user' => $user];
        }
        
        return "Failed to generate reset token. Please try again.";
    }
    
    /**
     * Verify password reset token (marks as verified)
     * Only works for employees (faculty/staff), not admin
     */
    public function verifyPasswordResetToken($token) {
        $stmt = $this->db->prepare("
            SELECT prt.*, u.first_name, u.last_name, u.email, u.user_type
            FROM password_reset_tokens prt
            INNER JOIN users u ON prt.user_id = u.id
            WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.is_verified = 0
            AND u.user_type IN ('faculty', 'staff')
        ");
        $stmt->execute([$token]);
        $resetToken = $stmt->fetch();
        
        if (!$resetToken) {
            return false;
        }
        
        // Mark token as verified
        $stmt = $this->db->prepare("UPDATE password_reset_tokens SET is_verified = 1 WHERE token = ?");
        $stmt->execute([$token]);
        
        return $resetToken;
    }
    
    /**
     * Check if token is verified (without marking it)
     * Only works for employees (faculty/staff), not admin
     */
    public function checkPasswordResetToken($token) {
        $stmt = $this->db->prepare("
            SELECT prt.*, u.first_name, u.last_name, u.email, u.user_type
            FROM password_reset_tokens prt
            INNER JOIN users u ON prt.user_id = u.id
            WHERE prt.token = ? AND prt.expires_at > NOW() AND prt.is_verified = 1
            AND u.user_type IN ('faculty', 'staff')
        ");
        $stmt->execute([$token]);
        return $stmt->fetch();
    }
    
    /**
     * Reset password using verified token
     * Only works for employees (faculty/staff), not admin
     */
    public function resetPasswordWithToken($token, $newPassword) {
        // Verify token is valid and verified, and user is an employee (not admin)
        $stmt = $this->db->prepare("
            SELECT prt.*, u.id as user_id, u.email, u.user_type
            FROM password_reset_tokens prt
            INNER JOIN users u ON prt.user_id = u.id
            WHERE prt.token = ? AND prt.is_verified = 1 AND prt.expires_at > NOW()
            AND u.user_type IN ('faculty', 'staff')
        ");
        $stmt->execute([$token]);
        $resetToken = $stmt->fetch();
        
        if (!$resetToken) {
            return "Invalid or expired reset token. Please request a new password reset.";
        }
        
        // Validate password strength
        if (strlen($newPassword) < 8) {
            return "Password must be at least 8 characters long.";
        }
        
        // Hash new password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        
        // Update user password
        $stmt = $this->db->prepare("UPDATE users SET password = ? WHERE id = ?");
        if ($stmt->execute([$hashedPassword, $resetToken['user_id']])) {
            // Delete the used token
            $stmt = $this->db->prepare("DELETE FROM password_reset_tokens WHERE token = ?");
            $stmt->execute([$token]);
            
            logAction('PASSWORD_RESET_COMPLETED', "Password reset completed for: {$resetToken['email']}");
            return "success";
        }
        
        return "Failed to reset password. Please try again.";
    }
}
?>
