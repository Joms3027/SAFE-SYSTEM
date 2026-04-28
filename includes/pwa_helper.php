<?php
/**
 * PWA Helper Functions
 * Functions to add Progressive Web App support to pages
 */

/**
 * Output PWA meta tags in the HTML head
 */
function addPWAMetaTags() {
    $basePath = getBasePath();
    
    // Build manifest URL (manifest.php is in root)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $manifestPath = $basePath ? rtrim($basePath, '/') . '/manifest.php' : '/manifest.php';
    $manifestUrl = $protocol . '://' . $host . $manifestPath;
    
    $themeColor = '#003366';
    
    echo "    <!-- PWA Meta Tags -->\n";
    echo "    <meta name=\"theme-color\" content=\"{$themeColor}\">\n";
    echo "    <meta name=\"mobile-web-app-capable\" content=\"yes\">\n";
    echo "    <meta name=\"apple-mobile-web-app-capable\" content=\"yes\">\n";
    echo "    <meta name=\"apple-mobile-web-app-status-bar-style\" content=\"black-translucent\">\n";
    echo "    <meta name=\"apple-mobile-web-app-title\" content=\"WPU Safe\">\n";
    echo "    \n";
    echo "    <!-- PWA Manifest -->\n";
    echo "    <link rel=\"manifest\" href=\"{$manifestUrl}\">\n";
    echo "    \n";
    echo "    <!-- Favicon and Apple Touch Icons (browser tab + home screen shortcut) -->\n";
    echo "    <link rel=\"icon\" type=\"image/png\" href=\"" . asset_url('logo.png', true) . "\">\n";
    echo "    <link rel=\"apple-touch-icon\" href=\"" . asset_url('logo.png', true) . "\">\n";
    echo "    <link rel=\"apple-touch-icon\" sizes=\"152x152\" href=\"" . asset_url('logo.png', true) . "\">\n";
    echo "    <link rel=\"apple-touch-icon\" sizes=\"192x192\" href=\"" . asset_url('logo.png', true) . "\">\n";
    echo "    <link rel=\"apple-touch-icon\" sizes=\"512x512\" href=\"" . asset_url('logo.png', true) . "\">\n";
}

/**
 * Output PWA installation script
 */
function addPWAScript() {
    $pwaScriptUrl = asset_url('js/pwa-install.js', true);
    echo "    <!-- PWA Installation Script -->\n";
    echo "    <script src=\"{$pwaScriptUrl}\"></script>\n";
}

/**
 * Check if the app is running in standalone mode (installed)
 */
function isPWAStandalone() {
    // This can only be checked client-side, but we can add a check here
    // for server-side detection if needed
    return false; // Always false on server-side, check client-side with JS
}

