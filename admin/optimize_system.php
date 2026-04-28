<?php
/**
 * System Optimization Utility
 * Run this page to apply database indexes and optimize the system
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

$results = [];
$errors = [];

// Apply database indexes
if (isset($_POST['apply_indexes'])) {
    try {
        $indexFile = dirname(__DIR__) . '/db/migrations/add_performance_indexes.sql';
        if (file_exists($indexFile)) {
            $sql = file_get_contents($indexFile);
            $statements = array_filter(array_map('trim', explode(';', $sql)));
            
            foreach ($statements as $statement) {
                if (!empty($statement) && stripos($statement, 'CREATE INDEX') !== false) {
                    try {
                        $db->exec($statement);
                        $results[] = "Applied: " . substr($statement, 0, 100) . "...";
                    } catch (PDOException $e) {
                        // Index might already exist, that's okay
                        if (strpos($e->getMessage(), 'Duplicate key') === false) {
                            $errors[] = "Error: " . $e->getMessage();
                        }
                    }
                }
            }
            $_SESSION['success'] = "Database indexes applied successfully!";
        } else {
            $errors[] = "Index file not found: " . $indexFile;
        }
    } catch (Exception $e) {
        $errors[] = "Failed to apply indexes: " . $e->getMessage();
    }
}

// Clear all caches
if (isset($_POST['clear_cache'])) {
    try {
        Database::clearAllCache();
        
        // Clear OPcache
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        // Clear file cache
        $cacheDir = dirname(__DIR__) . '/storage/cache';
        if (is_dir($cacheDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    @unlink($file->getPathname());
                }
            }
        }
        
        $_SESSION['success'] = "All caches cleared successfully!";
    } catch (Exception $e) {
        $errors[] = "Failed to clear cache: " . $e->getMessage();
    }
}

// Get system statistics
$stats = [];
try {
    // Database indexes status
    $stmt = $db->query("SHOW INDEX FROM users");
    $stats['users_indexes'] = $stmt->rowCount();
    
    // Cache status
    $cacheDir = dirname(__DIR__) . '/storage/cache';
    $cacheSize = 0;
    $cacheFiles = 0;
    if (is_dir($cacheDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cacheDir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $cacheSize += $file->getSize();
                $cacheFiles++;
            }
        }
    }
    $stats['cache_size'] = $cacheSize;
    $stats['cache_files'] = $cacheFiles;
    
    // OPcache status
    if (function_exists('opcache_get_status')) {
        $opcache = opcache_get_status(false);
        $stats['opcache_enabled'] = $opcache ? $opcache['opcache_enabled'] : false;
        if ($opcache) {
            $stats['opcache_hit_rate'] = round($opcache['opcache_statistics']['opcache_hit_rate'], 2);
            $stats['opcache_memory_used'] = round($opcache['memory_usage']['used_memory'] / 1024 / 1024, 2);
        }
    }
    
    // PHP version
    $stats['php_version'] = PHP_VERSION;
    
    // Database connection info
    $stats['db_host'] = DB_HOST;
    $stats['db_name'] = DB_NAME;
    
} catch (Exception $e) {
    $errors[] = "Failed to get statistics: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Optimization - WPU Faculty System</title>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/admin-portal.css', true); ?>" rel="stylesheet">
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php'; include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header">
                    <h1><i class="fas fa-tachometer-alt"></i> System Optimization</h1>
                    <p class="text-muted">Optimize database, cache, and system performance</p>
                </div>
                
                <?php displayMessage(); ?>
                
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-database"></i> Database Optimization</h5>
                            </div>
                            <div class="card-body">
                                <p>Apply performance indexes to improve query speed.</p>
                                <form method="POST">
                                    <button type="submit" name="apply_indexes" class="btn btn-primary">
                                        <i class="fas fa-magic"></i> Apply Database Indexes
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header">
                                <h5><i class="fas fa-broom"></i> Cache Management</h5>
                            </div>
                            <div class="card-body">
                                <p>Clear all caches (database, file, and OPcache).</p>
                                <form method="POST">
                                    <button type="submit" name="clear_cache" class="btn btn-warning">
                                        <i class="fas fa-trash"></i> Clear All Caches
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card mt-4">
                    <div class="card-header">
                        <h5><i class="fas fa-chart-line"></i> System Statistics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="stat-box">
                                    <h6>PHP Version</h6>
                                    <p class="stat-value"><?php echo htmlspecialchars($stats['php_version'] ?? 'Unknown'); ?></p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-box">
                                    <h6>Database</h6>
                                    <p class="stat-value"><?php echo htmlspecialchars($stats['db_name'] ?? 'Unknown'); ?></p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-box">
                                    <h6>OPcache Status</h6>
                                    <p class="stat-value">
                                        <?php if (isset($stats['opcache_enabled']) && $stats['opcache_enabled']): ?>
                                            <span class="badge badge-success">Enabled</span>
                                            <?php if (isset($stats['opcache_hit_rate'])): ?>
                                                <br><small>Hit Rate: <?php echo $stats['opcache_hit_rate']; ?>%</small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Disabled</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-box">
                                    <h6>File Cache</h6>
                                    <p class="stat-value">
                                        <?php 
                                        $cacheSizeMB = isset($stats['cache_size']) ? round($stats['cache_size'] / 1024 / 1024, 2) : 0;
                                        echo $cacheSizeMB; ?> MB
                                        <br><small><?php echo $stats['cache_files'] ?? 0; ?> files</small>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($results)): ?>
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5><i class="fas fa-list"></i> Operation Results</h5>
                        </div>
                        <div class="card-body">
                            <ul class="list-group">
                                <?php foreach ($results as $result): ?>
                                    <li class="list-group-item"><?php echo htmlspecialchars($result); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
</body>
</html>

