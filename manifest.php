<?php
/**
 * PWA Web App Manifest
 * Uses script location for base path - no config/session to avoid output before JSON
 */

// CRITICAL: Prevent ANY output before JSON - no config/session loading
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();
error_reporting(0);
ini_set('display_errors', 0);

// Calculate base path from script location (avoids config.php which may output)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '/manifest.php';
$scriptDir = str_replace('\\', '/', dirname($scriptName));
$basePath = ($scriptDir !== '/' && $scriptDir !== '' && $scriptDir !== '.') ? $scriptDir : '';

// Set headers before any output
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');

// Normalize base path: ensure it starts with / and doesn't end with /
$basePath = $basePath ? '/' . trim($basePath, '/') : '';

$manifest = [
    'name' => 'WPU Safe System - Faculty & Staff Management',
    'short_name' => 'WPU Safe',
    'description' => 'WPU Faculty and Staff Management System',
    
    // CRITICAL: Use relative URLs - browsers resolve them relative to manifest's origin
    // This ensures they always match the document origin (works with any origin)
    // start_url must be within scope and accessible
    // Scope covers entire site - allows installation from any page
    'start_url' => $basePath . '/',
    'scope' => $basePath . '/', // Entire site scope - allows installation from any page
    
    // App ID - use relative path (browser will resolve it)
    // Must be unique and match start_url pattern
    // CRITICAL: The id field helps browsers identify the app uniquely
    'id' => $basePath . '/',
    
    // CRITICAL: standalone = native app (no browser UI)
    // Use display_override: fullscreen first helps some Chrome/Android open without browser tab
    'display' => 'standalone',
    'display_override' => ['fullscreen', 'standalone', 'minimal-ui'],
    
    // CRITICAL: This ensures the app opens in standalone mode when launched from homescreen
    // Without this, some browsers might open it in a browser tab instead
    'prefer_related_applications' => false,
    
    // Colors - must match for proper app appearance
    'background_color' => '#003366',
    'theme_color' => '#003366',
    
    // CRITICAL: This helps browsers recognize the app as installable
    // The start_url must be accessible and within the service worker scope
    // Adding this ensures proper app launch behavior
    
    'orientation' => 'portrait-primary',
    'dir' => 'ltr',
    'lang' => 'en',
    
    // Icons - use logo.png for browser tab and home screen shortcut (browsers scale as needed)
    'icons' => [
        [
            'src' => $basePath . '/assets/logo.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $basePath . '/assets/logo.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $basePath . '/assets/logo.png',
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'maskable'
        ],
        [
            'src' => $basePath . '/assets/logo.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable'
        ]
    ],
    
    'categories' => ['education', 'productivity', 'business'],
    
    // Shortcuts - must be within scope
    // Note: Shortcuts are optional and may cause issues if URLs are outside scope
    // Commented out to avoid validation errors - uncomment if needed
    /*
    'shortcuts' => [
        [
            'name' => 'Dashboard',
            'short_name' => 'Home',
            'description' => 'Go to dashboard',
            'url' => $basePath . '/faculty/dashboard.php',
            'icons' => [['src' => $basePath . '/assets/icons/icon-96x96.png', 'sizes' => '96x96']]
        ],
        [
            'name' => 'Attendance',
            'short_name' => 'Logs',
            'description' => 'View attendance',
            'url' => $basePath . '/faculty/view_logs.php',
            'icons' => [['src' => $basePath . '/assets/icons/icon-96x96.png', 'sizes' => '96x96']]
        ]
    ],
    */
    
    'related_applications' => []
];

// Output JSON only - no BOM, no whitespace
$json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    header('HTTP/1.1 500 Internal Server Error');
    exit;
}
while (ob_get_level() > 0) {
    ob_end_clean();
}
echo $json;
exit;
