<?php
/**
 * Optimized Scripts Include
 * Include this at the end of body for optimal script loading
 * Usage: include_once 'includes/optimized-scripts.php';
 */

// Load performance helper if not already loaded
if (!function_exists('getCacheVersion')) {
    require_once __DIR__ . '/performance.php';
}

// Get base path
$basePath = function_exists('getBasePath') ? getBasePath() : '';
$version = getCacheVersion();

// Determine page type from current script
$currentDir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$pageType = ($currentDir === 'admin') ? 'admin' : 'faculty';
?>
<!-- Core Scripts (Not Deferred - Needed immediately) -->
<script src="<?php echo $basePath; ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=<?php echo $version; ?>"></script>

<!-- Deferred Scripts -->
<script src="<?php echo $basePath; ?>/assets/js/main.js?v=<?php echo $version; ?>" defer></script>
<script src="<?php echo $basePath; ?>/assets/js/performance.js?v=<?php echo $version; ?>" defer></script>

<?php if ($pageType === 'faculty'): ?>
<script src="<?php echo $basePath; ?>/assets/js/mobile-interactions-unified.js?v=<?php echo $version; ?>" defer></script>
<?php else: ?>
<script src="<?php echo $basePath; ?>/assets/js/mobile.js?v=<?php echo $version; ?>" defer></script>
<?php endif; ?>

<!-- Lightweight Service Worker Registration -->
<script>
if('serviceWorker' in navigator){
    window.addEventListener('load',function(){
        navigator.serviceWorker.register('<?php echo $basePath; ?>/service-worker.js')
            .then(function(reg){console.log('[SW] Registered')})
            .catch(function(err){console.log('[SW] Error:',err)});
    });
}
</script>

<!-- Initialize tooltips only on desktop (deferred) -->
<script>
document.addEventListener('DOMContentLoaded',function(){
    if(window.innerWidth>=768&&typeof bootstrap!=='undefined'){
        var t=[].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        t.map(function(e){return new bootstrap.Tooltip(e)});
    }
});
</script>

