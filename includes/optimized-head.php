<?php
/**
 * Optimized Head Include
 * Include this in pages for fast, lightweight loading
 * Usage: include_once 'includes/optimized-head.php';
 */

// Load performance helper
require_once __DIR__ . '/performance.php';

// Start page optimization (gzip, etc.)
startPageOptimization();

// Get base path
$basePath = function_exists('getBasePath') ? getBasePath() : '';
$version = getCacheVersion();

// Determine page type from current script
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'home.php');
$currentDir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$pageType = ($currentDir === 'admin') ? 'admin' : 'faculty';
?>
<!-- Performance Meta Tags -->
<meta http-equiv="x-dns-prefetch-control" content="on">
<meta name="format-detection" content="telephone=no">

<!-- DNS Prefetch & Preconnect -->
<link rel="dns-prefetch" href="//fonts.googleapis.com">
<link rel="dns-prefetch" href="//cdn.jsdelivr.net">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

<!-- Preload Critical Resources -->
<link rel="preload" href="<?php echo $basePath; ?>/assets/vendor/bootstrap/css/bootstrap.min.css?v=<?php echo $version; ?>" as="style">
<link rel="preload" href="<?php echo $basePath; ?>/assets/css/style.css?v=<?php echo $version; ?>" as="style">
<link rel="preload" href="<?php echo $basePath; ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=<?php echo $version; ?>" as="script">
<link rel="preload" href="<?php echo $basePath; ?>/assets/vendor/fontawesome/css/all.min.css?v=<?php echo $version; ?>" as="style">
<!-- Prefetch font (will be loaded by CSS when needed) -->
<link rel="prefetch" href="<?php echo $basePath; ?>/assets/vendor/fontawesome/webfonts/fa-solid-900.woff2" as="font" type="font/woff2" crossorigin>

<!-- Critical CSS (Inline) -->
<style>
/* Critical CSS - Inline for fastest first paint */
:root{--primary:#003366;--bg:#f8fafc;--white:#fff;--text:#0f172a;--border:#e2e8f0;--shadow:0 1px 3px rgba(0,0,0,.1)}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);font-size:14px;line-height:1.5}
.header{position:fixed;top:0;left:0;right:0;height:56px;background:var(--white);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1rem;z-index:1030}
.sidebar{position:fixed;top:0;left:0;width:280px;height:100vh;background:var(--white);border-right:1px solid var(--border);z-index:1028;transform:translateX(-100%);transition:transform .25s}
.sidebar.show{transform:translateX(0)}
@media(min-width:992px){.header{left:280px;width:calc(100% - 280px)}.sidebar{transform:translateX(0)}}
.main-content{margin-top:56px;padding:1rem;min-height:calc(100vh - 56px)}
@media(min-width:992px){.main-content{margin-left:280px;width:calc(100% - 280px)}}
.card{background:var(--white);border:1px solid var(--border);border-radius:.5rem;box-shadow:var(--shadow)}
.btn{padding:.5rem 1rem;font-size:.9rem;font-weight:500;border-radius:.375rem;border:1px solid transparent;cursor:pointer}
.btn-primary{background:linear-gradient(135deg,var(--primary),#005599);color:#fff}
/* Loading skeleton */
.skeleton{background:linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);background-size:200% 100%;animation:shimmer 1.5s infinite}
@keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
</style>

<!-- Main Stylesheets (Render-blocking but critical) -->
<link rel="stylesheet" href="<?php echo $basePath; ?>/assets/vendor/bootstrap/css/bootstrap.min.css?v=<?php echo $version; ?>">
<link rel="stylesheet" href="<?php echo $basePath; ?>/assets/vendor/fontawesome/css/all.min.css?v=<?php echo $version; ?>">
<link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/style.css?v=<?php echo $version; ?>">

<?php if ($pageType === 'admin'): ?>
<link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/admin-portal.css?v=<?php echo $version; ?>">
<?php else: ?>
<link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/faculty-portal.css?v=<?php echo $version; ?>">
<?php endif; ?>

<!-- Non-Critical CSS (Async Load) -->
<link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/mobile.css?v=<?php echo $version; ?>" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/mobile.css?v=<?php echo $version; ?>"></noscript>

