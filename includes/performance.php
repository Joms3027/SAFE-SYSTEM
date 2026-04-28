<?php
/**
 * Performance Optimization Helper
 * Provides functions for optimizing asset loading, caching, and page performance
 */

// Performance configuration
define('PERF_ENABLE_MINIFICATION', true);
define('PERF_ENABLE_GZIP', true);
define('PERF_CACHE_VERSION', '1.0.0');
define('PERF_LAZY_LOAD_IMAGES', true);
define('PERF_DEFER_JS', true);
define('PERF_PRELOAD_FONTS', true);

/**
 * Get cache-busting version string
 */
function getCacheVersion() {
    return PERF_CACHE_VERSION;
}

/**
 * Generate optimized CSS loading with critical CSS inline
 * @param array $cssFiles Array of CSS file paths
 * @param bool $inline Whether to inline critical CSS
 */
function loadOptimizedCSS($cssFiles, $inline = false) {
    $basePath = function_exists('getBasePath') ? getBasePath() : '';
    $version = getCacheVersion();
    
    $output = '';
    
    // Preload critical fonts (using prefetch instead of preload to avoid unused resource warnings)
    // The font will be loaded by FontAwesome CSS when needed
    if (PERF_PRELOAD_FONTS) {
        $output .= '<!-- Prefetch fonts for future use -->' . "\n";
        $output .= '<link rel="prefetch" href="' . $basePath . '/assets/vendor/fontawesome/webfonts/fa-solid-900.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
    }
    
    foreach ($cssFiles as $index => $cssFile) {
        $fullPath = $basePath . '/assets/css/' . $cssFile;
        
        if ($index === 0) {
            // First CSS file loads normally (critical)
            $output .= '<link rel="stylesheet" href="' . $fullPath . '?v=' . $version . '">' . "\n";
        } else {
            // Non-critical CSS loads with media="print" trick for async loading
            $output .= '<link rel="stylesheet" href="' . $fullPath . '?v=' . $version . '" media="print" onload="this.media=\'all\'">' . "\n";
            $output .= '<noscript><link rel="stylesheet" href="' . $fullPath . '?v=' . $version . '"></noscript>' . "\n";
        }
    }
    
    return $output;
}

/**
 * Generate optimized JavaScript loading
 * @param array $jsFiles Array of JS file paths
 * @param bool $defer Whether to defer loading
 */
function loadOptimizedJS($jsFiles, $defer = true) {
    $basePath = function_exists('getBasePath') ? getBasePath() : '';
    $version = getCacheVersion();
    
    $output = '';
    
    foreach ($jsFiles as $jsFile) {
        $fullPath = $basePath . '/assets/js/' . $jsFile;
        $deferAttr = ($defer && PERF_DEFER_JS) ? ' defer' : '';
        $output .= '<script src="' . $fullPath . '?v=' . $version . '"' . $deferAttr . '></script>' . "\n";
    }
    
    return $output;
}

/**
 * Generate resource hints for faster loading
 */
function generateResourceHints() {
    $hints = '';
    
    // DNS prefetch for external resources
    $hints .= '<!-- DNS Prefetch -->' . "\n";
    $hints .= '<link rel="dns-prefetch" href="//fonts.googleapis.com">' . "\n";
    $hints .= '<link rel="dns-prefetch" href="//cdn.jsdelivr.net">' . "\n";
    
    // Preconnect for resources we'll definitely use
    $hints .= '<!-- Preconnect -->' . "\n";
    $hints .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
    
    return $hints;
}

/**
 * Output inline critical CSS for above-the-fold content
 */
function inlineCriticalCSS() {
    return <<<CSS
<style>
/* Critical CSS - Inline for fastest first paint */
:root{--primary-blue:#003366;--secondary-blue:#005599;--light-blue:#e6f3ff;--office-gray:#f8fafc;--text-dark:#0f172a;--text-medium:#475569;--white:#ffffff;--border-color:#e2e8f0;--shadow-sm:0 1px 2px 0 rgba(0,0,0,.05);--radius-md:.5rem}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,'Roboto','Helvetica Neue',Arial,sans-serif;background:var(--office-gray);color:var(--text-dark);line-height:1.6;font-size:14px}
.header{height:56px;background:var(--white);box-shadow:var(--shadow-sm);border-bottom:1px solid var(--border-color);padding:0 .5rem;position:fixed;top:0;left:0;right:0;z-index:1030;display:flex;align-items:center}
.sidebar{background:var(--white);box-shadow:0 10px 15px -3px rgba(0,0,0,.1);height:100vh;position:fixed;top:0;left:0;width:280px;z-index:1028;transform:translateX(-100%);transition:transform .3s ease}
.sidebar.show{transform:translateX(0)}
@media(min-width:992px){.sidebar{transform:translateX(0)}.header{left:280px;width:calc(100% - 280px)}}
.main-content{margin-left:0;padding:.5rem;min-height:calc(100vh - 56px);background:var(--office-gray);width:100%;margin-top:56px}
@media(min-width:992px){.main-content{margin-left:280px;width:calc(100% - 280px);padding:1rem}}
.card{background:var(--white);border:1px solid var(--border-color);border-radius:var(--radius-md);box-shadow:var(--shadow-sm)}
.btn{padding:.5rem 1rem;font-size:.9rem;font-weight:500;border-radius:.375rem;border:1px solid transparent;display:inline-flex;align-items:center;justify-content:center;gap:.5rem;cursor:pointer}
.btn-primary{background:linear-gradient(135deg,var(--primary-blue),var(--secondary-blue));border-color:var(--primary-blue);color:var(--white)}
</style>
CSS;
}

/**
 * Generate lazy loading attributes for images
 * @param string $src Image source
 * @param string $alt Alt text
 * @param string $class CSS classes
 * @param array $attrs Additional attributes
 */
function lazyImage($src, $alt = '', $class = '', $attrs = []) {
    $loading = PERF_LAZY_LOAD_IMAGES ? 'loading="lazy"' : '';
    $decoding = 'decoding="async"';
    $classAttr = $class ? 'class="' . htmlspecialchars($class) . '"' : '';
    
    $extraAttrs = '';
    foreach ($attrs as $key => $value) {
        $extraAttrs .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($value) . '"';
    }
    
    return '<img src="' . htmlspecialchars($src) . '" alt="' . htmlspecialchars($alt) . '" ' . $classAttr . ' ' . $loading . ' ' . $decoding . $extraAttrs . '>';
}

/**
 * Output performance meta tags
 */
function performanceMetaTags() {
    return <<<HTML
<!-- Performance Meta Tags -->
<meta http-equiv="x-dns-prefetch-control" content="on">
<meta name="format-detection" content="telephone=no">
HTML;
}

/**
 * Generate service worker registration script (lightweight)
 */
function lightweightSWRegistration() {
    $basePath = function_exists('getBasePath') ? getBasePath() : '';
    return <<<JS
<script>
if('serviceWorker' in navigator){window.addEventListener('load',function(){navigator.serviceWorker.register('{$basePath}/service-worker.js').catch(function(){})});}
</script>
JS;
}

/**
 * Add gzip compression headers if enabled
 */
function enableGzipCompression() {
    if (PERF_ENABLE_GZIP && !headers_sent()) {
        if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) {
            if (ob_start("ob_gzhandler")) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Set cache headers for static content
 * @param int $maxAge Cache duration in seconds (default 1 week)
 */
function setCacheHeaders($maxAge = 604800) {
    if (!headers_sent()) {
        header('Cache-Control: public, max-age=' . $maxAge);
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
        header('Vary: Accept-Encoding');
    }
}

/**
 * Optimize page output - call at start of page
 */
function startPageOptimization() {
    enableGzipCompression();
}

/**
 * Get optimized head content for a page
 * @param string $pageType 'faculty' or 'admin'
 * @param string $title Page title
 */
function getOptimizedHead($pageType = 'faculty', $title = 'WPU Faculty System') {
    $basePath = function_exists('getBasePath') ? getBasePath() : '';
    $version = getCacheVersion();
    
    // Define CSS files based on page type
    $criticalCSS = ['style.css'];
    $asyncCSS = ['mobile.css'];
    
    if ($pageType === 'faculty') {
        $criticalCSS[] = 'faculty-portal.css';
    } elseif ($pageType === 'admin') {
        $criticalCSS[] = 'admin-portal.css';
    }
    
    $output = '';
    $output .= performanceMetaTags() . "\n";
    $output .= generateResourceHints() . "\n";
    $output .= inlineCriticalCSS() . "\n";
    
    // Load main CSS files
    $output .= '<!-- Main Stylesheets -->' . "\n";
    $output .= '<link rel="stylesheet" href="' . $basePath . '/assets/vendor/bootstrap/css/bootstrap.min.css?v=' . $version . '">' . "\n";
    $output .= '<link rel="stylesheet" href="' . $basePath . '/assets/vendor/fontawesome/css/all.min.css?v=' . $version . '">' . "\n";
    
    foreach ($criticalCSS as $css) {
        $output .= '<link rel="stylesheet" href="' . $basePath . '/assets/css/' . $css . '?v=' . $version . '">' . "\n";
    }
    
    // Async load non-critical CSS
    $output .= '<!-- Async Non-Critical CSS -->' . "\n";
    foreach ($asyncCSS as $css) {
        $output .= '<link rel="stylesheet" href="' . $basePath . '/assets/css/' . $css . '?v=' . $version . '" media="print" onload="this.media=\'all\'">' . "\n";
    }
    
    return $output;
}

/**
 * Get optimized scripts for end of body
 * @param string $pageType 'faculty' or 'admin'
 * @param array $additionalScripts Additional scripts to load
 */
function getOptimizedScripts($pageType = 'faculty', $additionalScripts = []) {
    $basePath = function_exists('getBasePath') ? getBasePath() : '';
    $version = getCacheVersion();
    
    $output = '<!-- Optimized Scripts -->' . "\n";
    
    // Core scripts (not deferred - needed immediately)
    $output .= '<script src="' . $basePath . '/assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=' . $version . '"></script>' . "\n";
    
    // Deferred scripts
    $deferredScripts = ['main.js'];
    
    if ($pageType === 'faculty') {
        $deferredScripts[] = 'mobile-interactions-unified.js';
    } else {
        $deferredScripts[] = 'mobile.js';
    }
    
    foreach ($deferredScripts as $script) {
        $output .= '<script src="' . $basePath . '/assets/js/' . $script . '?v=' . $version . '" defer></script>' . "\n";
    }
    
    // Additional page-specific scripts
    foreach ($additionalScripts as $script) {
        if (strpos($script, 'http') === 0) {
            // External script
            $output .= '<script src="' . $script . '" defer></script>' . "\n";
        } else {
            $output .= '<script src="' . $basePath . '/assets/js/' . $script . '?v=' . $version . '" defer></script>' . "\n";
        }
    }
    
    // Lightweight SW registration
    $output .= lightweightSWRegistration();
    
    return $output;
}

// ============================================
// HIGH-LOAD SCENARIO HANDLING
// ============================================

/**
 * Check if system is under high load
 * Uses PHP memory and database connection metrics
 * 
 * @return array Load status information
 */
function getSystemLoadStatus() {
    $status = [
        'high_load' => false,
        'memory_usage' => 0,
        'memory_limit' => 0,
        'memory_percent' => 0,
        'db_status' => 'unknown'
    ];
    
    // Check memory usage
    $memoryUsage = memory_get_usage(true);
    $memoryLimit = getMemoryLimitBytes();
    
    $status['memory_usage'] = $memoryUsage;
    $status['memory_limit'] = $memoryLimit;
    
    if ($memoryLimit > 0) {
        $status['memory_percent'] = round(($memoryUsage / $memoryLimit) * 100, 2);
        if ($status['memory_percent'] > 85) {
            $status['high_load'] = true;
        }
    }
    
    // Check database if available
    if (class_exists('Database')) {
        try {
            $db = Database::getInstanceOrNull();
            if ($db && method_exists($db, 'isUnderHeavyLoad')) {
                if ($db->isUnderHeavyLoad()) {
                    $status['high_load'] = true;
                    $status['db_status'] = 'heavy_load';
                } else {
                    $status['db_status'] = 'normal';
                }
            }
        } catch (Exception $e) {
            $status['db_status'] = 'error';
        }
    }
    
    return $status;
}

/**
 * Get memory limit in bytes
 */
function getMemoryLimitBytes() {
    $limit = ini_get('memory_limit');
    if ($limit == -1) {
        return PHP_INT_MAX;
    }
    
    $value = intval($limit);
    $unit = strtoupper(substr(trim($limit), -1));
    
    switch ($unit) {
        case 'G':
            $value *= 1024;
        case 'M':
            $value *= 1024;
        case 'K':
            $value *= 1024;
    }
    
    return $value;
}

/**
 * Handle graceful degradation under high load
 * Call this at the start of heavy operations
 * 
 * @param string $operation Description of the operation
 * @param callable $callback Operation to perform
 * @param mixed $fallback Fallback value if operation can't be performed
 * @return mixed Result of operation or fallback
 */
function handleHighLoadOperation($operation, callable $callback, $fallback = null) {
    $loadStatus = getSystemLoadStatus();
    
    if ($loadStatus['high_load']) {
        // Log the deferral
        error_log("High load detected - deferring operation: $operation (Memory: {$loadStatus['memory_percent']}%, DB: {$loadStatus['db_status']})");
        
        // Return fallback value
        return $fallback;
    }
    
    // Execute the operation with error handling
    try {
        return $callback();
    } catch (Exception $e) {
        error_log("Operation failed ($operation): " . $e->getMessage());
        return $fallback;
    }
}

/**
 * Send appropriate response for overloaded system
 * Use this in API endpoints when system is too busy
 */
function sendOverloadResponse() {
    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 5');
        header('Content-Type: application/json');
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'System is temporarily busy. Please try again in a few seconds.',
        'retry' => true,
        'retry_after' => 5
    ]);
    exit;
}

/**
 * Rate limit per-user to prevent abuse
 * Uses session-based rate limiting
 * 
 * @param string $action Action being performed
 * @param int $maxRequests Maximum requests allowed
 * @param int $perSeconds Time window in seconds
 * @return bool True if request is allowed
 */
function checkUserRateLimit($action, $maxRequests = 30, $perSeconds = 60) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true; // Can't rate limit without session
    }
    
    $key = 'rate_limit_' . md5($action);
    $now = time();
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = [
            'count' => 0,
            'window_start' => $now
        ];
    }
    
    $rateData = &$_SESSION[$key];
    
    // Reset if window has passed
    if ($now - $rateData['window_start'] >= $perSeconds) {
        $rateData['count'] = 0;
        $rateData['window_start'] = $now;
    }
    
    // Increment and check
    $rateData['count']++;
    
    if ($rateData['count'] > $maxRequests) {
        error_log("Rate limit exceeded for action: $action (User ID: " . ($_SESSION['user_id'] ?? 'unknown') . ")");
        return false;
    }
    
    return true;
}

/**
 * Clean up and optimize on shutdown
 * Register this for important pages
 */
function registerCleanupHandler() {
    register_shutdown_function(function() {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    });
}
