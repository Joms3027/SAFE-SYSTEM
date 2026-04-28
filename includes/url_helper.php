<?php
/**
 * URL Helper Functions
 * Include this file in your PHP pages to generate clean URLs
 * Usage: require_once 'includes/url_helper.php';
 */

/**
 * Get the base URL of the application
 * @param string $path Optional path to append
 * @return string Full URL
 */
function base_url($path = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' 
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) 
                ? 'https://' : 'http://';
    
    $host = $_SERVER['HTTP_HOST'];
    $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    
    // Clean up the path
    $path = ltrim($path, '/');
    
    if ($script_dir === '' || $script_dir === '/') {
        return $protocol . $host . '/' . $path;
    }
    
    return $protocol . $host . $script_dir . '/' . $path;
}

/**
 * Generate a clean URL without .php extension
 * @param string $page Page name (with or without .php)
 * @return string Clean URL
 */
function url($page) {
    // Remove .php extension if present
    $page = str_replace('.php', '', $page);
    
    // Convert underscores to dashes for cleaner URLs
    $page = str_replace('_', '-', $page);
    
    return base_url($page);
}

/**
 * Redirect to a page with clean URL
 * @param string $page Page to redirect to
 * @param array $params Optional query parameters
 */
function redirect($page, $params = []) {
    $url = url($page);
    
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    header('Location: ' . $url);
    exit;
}

/**
 * Get the current page name without extension
 * @return string Current page name
 */
function current_page() {
    $page = basename($_SERVER['PHP_SELF']);
    return str_replace('.php', '', $page);
}

/**
 * Check if the current page matches the given page
 * @param string $page Page to check
 * @return bool True if current page matches
 */
function is_current_page($page) {
    return current_page() === str_replace('.php', '', $page);
}

/**
 * Generate an asset URL (for CSS, JS, images)
 * @param string $asset Asset path
 * @return string Asset URL
 */
function asset($asset) {
    return base_url($asset);
}

/**
 * Safe redirect with message
 * @param string $page Page to redirect to
 * @param string $message Message to display
 * @param string $type Message type (success, error, warning, info)
 */
function redirect_with_message($page, $message, $type = 'info') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    
    redirect($page);
}

/**
 * Get and clear flash message
 * @return array|null Array with 'message' and 'type' or null
 */
function get_flash_message() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['flash_message'])) {
        $message = [
            'message' => $_SESSION['flash_message'],
            'type' => $_SESSION['flash_type'] ?? 'info'
        ];
        
        unset($_SESSION['flash_message']);
        unset($_SESSION['flash_type']);
        
        return $message;
    }
    
    return null;
}

/**
 * Create a navigation link with active state
 * @param string $page Page URL
 * @param string $text Link text
 * @param string $activeClass Class to add when active
 * @return string HTML link
 */
function nav_link($page, $text, $activeClass = 'active') {
    $url = url($page);
    $current = current_page();
    $pageName = str_replace('.php', '', str_replace('_', '-', $page));
    
    $class = ($current === $pageName) ? $activeClass : '';
    
    return sprintf('<a href="%s" class="%s">%s</a>', 
        htmlspecialchars($url), 
        htmlspecialchars($class), 
        htmlspecialchars($text)
    );
}
