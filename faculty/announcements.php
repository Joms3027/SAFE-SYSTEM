<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../storage/logs/php_errors.log');

// Set error handler to catch all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $errorMsg = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($errorMsg);
    if (ini_get('display_errors')) {
        echo "<div style='background: #ff0000; color: white; padding: 20px; margin: 20px; border-radius: 5px;'>";
        echo "<h3>PHP Error Detected</h3>";
        echo "<p><strong>Error:</strong> $errstr</p>";
        echo "<p><strong>File:</strong> $errfile</p>";
        echo "<p><strong>Line:</strong> $errline</p>";
        echo "</div>";
    }
    return false; // Let PHP handle the error normally
});

// Set exception handler
set_exception_handler(function($exception) {
    $errorMsg = "Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine();
    error_log($errorMsg);
    if (ini_get('display_errors')) {
        echo "<div style='background: #ff0000; color: white; padding: 20px; margin: 20px; border-radius: 5px;'>";
        echo "<h3>Uncaught Exception</h3>";
        echo "<p><strong>Error:</strong> " . htmlspecialchars($exception->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($exception->getFile()) . "</p>";
        echo "<p><strong>Line:</strong> " . $exception->getLine() . "</p>";
        echo "<pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
        echo "</div>";
    }
});

// Disable output buffering to see errors immediately
if (ob_get_level()) {
    ob_end_clean();
}

try {
    require_once '../includes/config.php';
} catch (Throwable $e) {
    die("Error loading config.php: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine());
}

try {
    require_once '../includes/functions.php';
} catch (Throwable $e) {
    die("Error loading functions.php: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine());
}

try {
    require_once '../includes/database.php';
} catch (Throwable $e) {
    die("Error loading database.php: " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine());
}

try {
    requireFaculty();
} catch (Throwable $e) {
    die("Error in requireFaculty(): " . htmlspecialchars($e->getMessage()) . " in " . $e->getFile() . " on line " . $e->getLine());
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Throwable $e) {
    die("Database connection error: " . htmlspecialchars($e->getMessage()) . "<br><br>Please check your database credentials in includes/config.php");
}

// Fetch active announcements for faculty or all audiences
try {
    $stmt = $db->prepare("
        SELECT a.*, u.first_name, u.last_name
        FROM announcements a
        JOIN users u ON a.created_by = u.id
        WHERE a.is_active = 1
          AND (a.target_audience = 'all' OR a.target_audience = 'faculty')
          AND (a.expires_at IS NULL OR a.expires_at = '' OR a.expires_at >= CURDATE())
        ORDER BY a.priority DESC, a.created_at DESC
    ");
    $stmt->execute();
    $announcements = $stmt->fetchAll();
    if ($announcements === false) {
        $announcements = [];
    }
} catch (PDOException $e) {
    error_log("Announcements query error: " . $e->getMessage());
    $announcements = [];
    // Display error in development
    if (ini_get('display_errors')) {
        echo "<div style='background: #ffeb3b; color: #000; padding: 15px; margin: 20px; border-radius: 5px; border-left: 4px solid #ff9800;'>";
        echo "<strong>Database Query Error:</strong> " . htmlspecialchars($e->getMessage());
        echo "</div>";
    }
} catch (Exception $e) {
    error_log("Announcements query exception: " . $e->getMessage());
    $announcements = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#003366">
    <title>Announcements - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <?php
    $basePath = getBasePath();
    ?>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile-button-fix.css', true); ?>" rel="stylesheet">
    
    <!-- All announcement styles moved to dedicated CSS file -->
    <style>
        /* 3-column card grid styles */
        .announcement-card {
            transition: transform 0.2s, box-shadow 0.2s;
            border: 1px solid #dee2e6;
            touch-action: manipulation;
            -webkit-tap-highlight-color: rgba(0, 51, 102, 0.1);
        }
        
        
        .announcement-card:active {
            transform: translateY(-2px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        @media (max-width: 768px) {
            .announcement-card {
                cursor: pointer;
            }
            
            .announcement-card:active {
                transform: scale(0.98);
                background-color: #f8f9fa;
            }
        }
        
        .announcement-card.priority-urgent {
            border-left: 4px solid #dc3545;
        }
        
        .announcement-card.priority-high {
            border-left: 4px solid #ffc107;
        }
        
        .announcement-card .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
        }
        
        .announcement-card .card-body {
            flex: 1;
        }
        
        .announcement-content {
            /* min-height: 60px; */
        }

        .layout-faculty .card-body {
    padding: var(--spacing-xl);
    max-height: 120px;
    
}
.modal-title {
    margin-bottom: 0;
    line-height: 1.5;
    color: white;
}
        
        .announcement-view-btn {
            font-size: 0.75rem;
            padding: 0.25rem 0.75rem;
        }
        
        .announcement-card .card-footer {
            text-align: left;
            padding: 0.75rem 1rem;
        }
        
        @media (max-width: 768px) {
            .col-md-4 {
                flex: 0 0 100%;
                max-width: 100%;
            }
        }
        
        /* ==========================================
           CRITICAL Z-INDEX FIXES - SAME AS requirements.css
           Fix Bootstrap modal z-index blocking announcement buttons
           ========================================== */
        
        /* CRITICAL: Announcement buttons must be ABOVE Bootstrap (1100+) */
        .announcement-card,
        .announcement-view-btn,
        .btn-outline-primary,
        .btn-primary,
        .btn-secondary,
        button:not(.modal button),
        .btn:not(.modal .btn) {
            position: relative !important;
            z-index: 1100 !important; /* Above Bootstrap's 1055 */
            pointer-events: auto !important;
            touch-action: manipulation !important; /* Better for clickable elements */
            isolation: isolate !important; /* Create new stacking context */
            transform: translateZ(0) !important; /* Force hardware acceleration */
        }
        
        /* CRITICAL FIX: Hide Bootstrap modals completely when closed - prevents blocking */
        .modal:not(.show),
        .modal[style*="display: none"],
        .modal.fade:not(.show),
        body:not(.modal-open) .modal,
        body:not(.modal-open) .modal.fade,
        body:not(.modal-open) .modal:not(.show),
        html:not(.modal-open) body:not(.modal-open) .modal {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
            z-index: -9999 !important;
            position: fixed !important;
            top: -99999px !important;
            left: -99999px !important;
            width: 0 !important;
            height: 0 !important;
            overflow: hidden !important;
            margin: 0 !important;
            /* padding: 0 !important; */
            border: none !important;
            box-shadow: none !important;
        }
        
        /* CRITICAL FIX: Ensure Bootstrap backdrops don't block buttons when modals are closed */
        .modal-backdrop:not(.show),
        .modal-backdrop[style*="display: none"],
        body:not(.modal-open) .modal-backdrop,
        body:not(.modal-open) .modal-backdrop.fade {
            display: none !important;
            pointer-events: none !important;
            z-index: -1 !important;
            opacity: 0 !important;
            visibility: hidden !important;
            position: fixed !important;
        }
        
        /* Ensure modals are only interactive when showing */
        .modal.show,
        .modal[style*="display: block"],
        body.modal-open .modal.show {
            pointer-events: auto !important;
            z-index: 1055 !important; /* Bootstrap's default */
        }
        
        /* Ensure backdrops are only interactive when showing */
        .modal-backdrop.show,
        body.modal-open .modal-backdrop.show {
            pointer-events: auto !important;
            z-index: 1040 !important; /* Bootstrap's default */
        }
        
        /* CRITICAL: Ensure announcement buttons are always clickable ABOVE Bootstrap when modals are closed */
        body:not(.modal-open) .announcement-card,
        body:not(.modal-open) .announcement-view-btn,
        body:not(.modal-open) .btn-outline-primary,
        body:not(.modal-open) .btn-primary,
        body:not(.modal-open) .btn-secondary,
        body:not(.modal-open) button:not(.modal button):not(:disabled),
        body:not(.modal-open) .btn:not(.modal .btn):not(:disabled) {
            z-index: 1020 !important; /* Above Bootstrap's 1055 */
            position: relative !important;
            pointer-events: auto !important;
            touch-action: manipulation !important;
            isolation: isolate !important;
            transform: translateZ(0) !important; /* Force new stacking context */
        }
        
        /* CRITICAL: Ensure main content is not blocked by hidden modals */
        body:not(.modal-open) .main-content,
        body:not(.modal-open) .main-content *:not(.modal):not(.modal *) {
            position: relative !important;
            z-index: auto !important;
            pointer-events: auto !important;
        }
        
        /* Mobile specific z-index fixes - ABOVE Bootstrap */
        @media (max-width: 991px) {
            /* CRITICAL: Hide Bootstrap modals completely when closed on mobile */
            body:not(.modal-open) .modal,
            body:not(.modal-open) .modal.fade,
            body:not(.modal-open) .modal:not(.show) {
                display: none !important;
                visibility: hidden !important;
                opacity: 0 !important;
                pointer-events: none !important;
                z-index: -9999 !important;
                position: fixed !important;
                top: -99999px !important;
                left: -99999px !important;
                width: 0 !important;
                height: 0 !important;
                overflow: hidden !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            
            /* CRITICAL: Ensure announcement buttons are clickable on mobile ABOVE Bootstrap */
            .announcement-card,
            .announcement-view-btn,
            .btn-outline-primary,
            .btn-primary,
            .btn-secondary,
            .card-footer .btn,
            .page-header .btn,
            button:not(.modal button):not(:disabled),
            .btn:not(.modal .btn):not(:disabled) {
                z-index: 1100 !important; /* Above Bootstrap's 1055 */
                position: relative !important;
                pointer-events: auto !important;
                touch-action: manipulation !important;
                isolation: isolate !important;
                transform: translateZ(0) !important; /* Force new stacking context */
            }
            
            /* Ensure main content is interactive and not blocked - allows scrolling */
            .main-content {
                position: relative;
                z-index: 1;
                pointer-events: auto !important;
                touch-action: pan-y !important; /* Allow vertical scrolling */
                -webkit-overflow-scrolling: touch !important;
                overscroll-behavior: contain !important;
            }
            
            .main-content *:not(.modal):not(.modal *) {
                pointer-events: auto !important;
            }
            
            /* Ensure announcement cards are clickable */
            .announcement-card,
            .card {
                pointer-events: auto !important;
                position: relative;
                z-index: 1;
            }
            
            /* But buttons inside cards should be ABOVE Bootstrap */
            .announcement-card .btn,
            .announcement-card button,
            .card .btn,
            .card button {
                z-index: 1100 !important; /* Above Bootstrap's 1055 */
                position: relative !important;
                pointer-events: auto !important;
                isolation: isolate !important;
                transform: translateZ(0) !important;
            }
            
            /* Ensure modal close buttons are clickable - no extra padding */
            .modal .btn-close,
            .modal-header .btn-close,
            .btn-close.btn-close-white {
                z-index: 1100 !important; /* Above Bootstrap's 1055 */
                position: relative !important;
                pointer-events: auto !important;
                touch-action: manipulation !important;
                cursor: pointer !important;
                min-width: 44px !important; /* Ensure minimum touch target */
                min-height: 44px !important; /* Ensure minimum touch target */
            }
            
            /* Ensure body allows scrolling when modals are closed */
            body:not(.modal-open) {
                overflow-y: auto !important;
                -webkit-overflow-scrolling: touch !important;
                touch-action: pan-y !important;
            }
            
            /* Ensure announcement grid allows scrolling */
            #announcementsGridWrapper {
                pointer-events: auto !important;
                touch-action: pan-y !important; /* Allow vertical scrolling */
                right: 1.5%;
            }
        }
        
        /* Remove excessive padding from Cancel button in modals */
        .btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        button.btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
        .btn-outline-secondary[data-bs-dismiss="modal"] {
            padding: 0.375rem 0.75rem !important;
        }
    </style>
    
    <!-- Optional: Auto-refresh announcements every 2 minutes -->
    <meta http-equiv="refresh" content="120">
    <script>
        function openAnnouncementModal(id) {
            const modal = new bootstrap.Modal(document.getElementById('viewAnnouncementModal' + id));
            modal.show();
        }
    </script>
</head>
<body class="layout-faculty">
    <?php 
    try {
        require_once '../includes/navigation.php';
        include_navigation();
    } catch (Throwable $e) {
        die("<h1>Error Loading Navigation</h1><p>" . htmlspecialchars($e->getMessage()) . "</p><p>File: " . htmlspecialchars($e->getFile()) . "</p><p>Line: " . $e->getLine() . "</p>");
    }
    ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header">
                    <div class="page-title">
                        <i class="fas fa-bullhorn"></i>
                        <span>Announcements</span>
                    </div>
                    
                </div>
                
                <?php displayMessage(); ?>
                
                <?php if (empty($announcements) || !is_array($announcements)): ?>
                    <div class="text-center py-5" id="announcementsEmptyState">
                        <i class="fas fa-bullhorn fa-4x text-muted mb-3"></i>
                        <h4 class="text-muted">No announcements at the moment</h4>
                        <p class="text-muted">You'll see important updates from the administration here.</p>
                    </div>
                <?php else: ?>
                    <div class="row g-4" id="announcementsGridWrapper">
                        <?php foreach ($announcements as $announcement): ?>
                            <div class="col-md-4 col-lg-4">
                                <div class="card h-100 announcement-card priority-<?php echo $announcement['priority']; ?>" 
                                     id="announcementCard<?php echo $announcement['id']; ?>" 
                                     data-announcement-id="<?php echo $announcement['id']; ?>"
                                     data-priority="<?php echo $announcement['priority']; ?>"
                                     onclick="openAnnouncementModal(<?php echo $announcement['id']; ?>)"
                                     style="cursor: pointer; touch-action: manipulation; -webkit-tap-highlight-color: rgba(0, 51, 102, 0.1);">
                                    <div class="card-header">
                                        <h5 class="card-title mb-0">
                                            <?php echo htmlspecialchars($announcement['title']); ?>
                                        </h5>
                                        <div class="mt-2 announcement-badges">
                                            <?php if ($announcement['priority'] === 'urgent'): ?>
                                                <span class="badge bg-danger">Urgent</span>
                                            <?php elseif ($announcement['priority'] === 'high'): ?>
                                                <span class="badge bg-warning text-dark">High Priority</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <div class="announcement-content mb-3">
                                            <p class="card-text"><?php echo htmlspecialchars(substr($announcement['content'], 0, 150)) . (strlen($announcement['content']) > 150 ? '...' : ''); ?></p>
                                        </div>
                                        <div class="text-muted small mb-3">
                                            <div class="mb-1">
                                                <i class="fas fa-user me-1"></i>
                                                <?php echo $announcement['first_name'] . ' ' . $announcement['last_name']; ?>
                                            </div>
                                            <div>
                                                <i class="far fa-clock me-1"></i>
                                                <?php echo formatDate($announcement['created_at'], 'M j, Y'); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="card-footer bg-transparent">
                                        <button type="button" class="btn btn-primary btn-sm announcement-view-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#viewAnnouncementModal<?php echo $announcement['id']; ?>"
                                                title="View Details"
                                                aria-label="View announcement: <?php echo htmlspecialchars($announcement['title']); ?>"
                                                onclick="event.stopPropagation();">
                                            <i class="fas fa-eye me-1"></i> View Details
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>
    
    <!-- View Announcement Modals -->
    <?php if (!empty($announcements) && is_array($announcements)): ?>
        <?php foreach ($announcements as $announcement): ?>
        <div class="modal fade" id="viewAnnouncementModal<?php echo $announcement['id']; ?>" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title" >
                            <i class="fas fa-bullhorn me-2"></i><?php echo htmlspecialchars($announcement['title']); ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="touch-action: manipulation; -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2); cursor: pointer; pointer-events: auto;" data-mobile-fixed="true"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <div class="d-flex flex-wrap gap-2 mb-3">
                                <?php if ($announcement['priority'] === 'urgent'): ?>
                                    <span class="badge bg-danger">Urgent</span>
                                <?php elseif ($announcement['priority'] === 'high'): ?>
                                    <span class="badge bg-warning text-dark">High Priority</span>
                                <?php elseif ($announcement['priority'] === 'normal'): ?>
                                    <span class="badge bg-info">Normal Priority</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Low Priority</span>
                                <?php endif; ?>
                                <span class="badge bg-primary"><?php echo ucfirst($announcement['target_audience']); ?></span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <h6 class="text-muted mb-2">Message:</h6>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                        </div>
                        
                        <hr>
                        
                        <div class="row text-muted small">
                            <div class="col-md-6">
                                <p class="mb-1">
                                    <i class="fas fa-user me-2"></i>
                                    <strong>Posted By:</strong> <?php echo $announcement['first_name'] . ' ' . $announcement['last_name']; ?>
                                </p>
                                <p class="mb-1">
                                    <i class="far fa-clock me-2"></i>
                                    <strong>Date Posted:</strong> <?php echo formatDate($announcement['created_at'], 'M j, Y g:i A'); ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <?php if ($announcement['expires_at']): ?>
                                    <p class="mb-1">
                                        <i class="fas fa-hourglass-end me-2"></i>
                                        <strong>Expires On:</strong> <?php echo formatDate($announcement['expires_at'], 'M j, Y'); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-mobile-fixed="true" style="touch-action: manipulation; -webkit-tap-highlight-color: rgba(0, 51, 102, 0.2); cursor: pointer; pointer-events: auto;">Close</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <!-- Load Bootstrap first - CRITICAL for mobile interactions -->
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <!-- Load main.js for core functionality -->
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <!-- Load unified mobile interactions - replaces all mobile scripts -->
    <script src="<?php echo asset_url('js/mobile-interactions-unified.js', true); ?>"></script>
</body>
</html>

