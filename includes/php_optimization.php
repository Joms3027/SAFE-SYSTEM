<?php
/**
 * PHP Performance Optimization Configuration
 * Include this file early in your application (after config.php)
 */

// Enable OPcache if available (PHP 5.5+)
// Note: On shared hosting like InfinityFree, these settings may not be changeable
// We use @ to suppress warnings if settings can't be changed
if (function_exists('opcache_get_status') && !ini_get('opcache.enable')) {
    @ini_set('opcache.enable', 1);
    @ini_set('opcache.memory_consumption', 128);
    @ini_set('opcache.interned_strings_buffer', 8);
    @ini_set('opcache.max_accelerated_files', 10000);
    @ini_set('opcache.revalidate_freq', 2);
    @ini_set('opcache.fast_shutdown', 1);
    @ini_set('opcache.enable_cli', 0);
}

// Optimize realpath cache (PHP 5.3+)
// Use @ to suppress warnings on shared hosting where these may be restricted
@ini_set('realpath_cache_size', '4096K');
@ini_set('realpath_cache_ttl', 600);

// Optimize memory and execution
// Use @ to suppress warnings on shared hosting where these may be restricted
@ini_set('memory_limit', '256M');
@ini_set('max_execution_time', 300);
@ini_set('max_input_time', 300);

// Optimize session handling
// Note: Session settings are better configured in config.php before session_start()
// These are just fallbacks, use @ to suppress warnings on shared hosting
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.gc_probability', 1);
    @ini_set('session.gc_divisor', 100);
    @ini_set('session.gc_maxlifetime', 3600);
    @ini_set('session.cookie_httponly', 1);
    @ini_set('session.use_strict_mode', 1);
}

// Disable unnecessary PHP features for better performance
@ini_set('expose_php', 0);

// Optimize file uploads
// Use @ to suppress warnings on shared hosting where these may be restricted
@ini_set('file_uploads', 1);
@ini_set('upload_max_filesize', '25M');
@ini_set('post_max_size', '30M');
@ini_set('max_file_uploads', 20);

// Optimize output buffering
// Use gzip compression if available, otherwise use regular output buffering
if (!ob_get_level()) {
    // Check if zlib is available and headers haven't been sent
    if (extension_loaded('zlib') && function_exists('ob_gzhandler') && !headers_sent()) {
        @ob_start('ob_gzhandler'); // Use @ to suppress errors if compression fails
    } else {
        ob_start(); // Fall back to regular output buffering
    }
}

register_shutdown_function(function() {
    if (ob_get_level()) {
        ob_end_flush();
    }
});

/**
 * Get OPcache statistics if available
 */
function getOPcacheStats() {
    if (function_exists('opcache_get_status')) {
        $status = opcache_get_status(false);
        if ($status) {
            return [
                'enabled' => $status['opcache_enabled'],
                'cache_full' => $status['cache_full'],
                'memory_used' => $status['memory_usage']['used_memory'],
                'memory_free' => $status['memory_usage']['free_memory'],
                'hits' => $status['opcache_statistics']['hits'],
                'misses' => $status['opcache_statistics']['misses'],
                'hit_rate' => $status['opcache_statistics']['opcache_hit_rate']
            ];
        }
    }
    return null;
}

/**
 * Clear OPcache if available
 */
function clearOPcache() {
    if (function_exists('opcache_reset')) {
        return opcache_reset();
    }
    return false;
}

