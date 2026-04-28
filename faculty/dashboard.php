<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();

// Get faculty profile (including tutorial_completed status)
// PERFORMANCE: Get user_type from the profile query instead of separate query
// PERFORMANCE: Cache column existence check in session to avoid SHOW COLUMNS query on every page load
// SHOW COLUMNS is a slow metadata query that should only run once per session
if (!isset($_SESSION['tutorial_column_exists'])) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM faculty_profiles LIKE 'tutorial_completed'");
        $_SESSION['tutorial_column_exists'] = $stmt->rowCount() > 0;
    } catch (Exception $e) {
        $_SESSION['tutorial_column_exists'] = false;
    }
}
$columnExists = $_SESSION['tutorial_column_exists'];

// Get faculty profile - try to include tutorial_completed, fallback gracefully if column doesn't exist
// PERFORMANCE: Always try to select tutorial_completed - if column doesn't exist, it will just be NULL
// PERFORMANCE: Also get user_type in same query to avoid separate query
$stmt = $db->prepare("SELECT fp.*, u.email, u.first_name, u.last_name, u.user_type, u.created_at as user_created_at, fp.tutorial_completed FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$profile = $stmt->fetch();

// Get user_type from profile query result (default fallback)
$user_type = 'faculty'; // Default fallback
if ($profile && !empty($profile['user_type'])) {
    $user_type = trim(strtolower($profile['user_type'])); // Normalize to lowercase and trim whitespace
}

// Define cutoff date: Users created before this date are considered "old users" and won't see the tutorial
// IMPORTANT: This date determines which users see the tutorial
// - Users created BEFORE this date = "old users" → tutorial_completed = 1 (won't see tutorial)
// - Users created ON/AFTER this date = "new users" → can see tutorial if tutorial_completed = 0
// 
// To set a specific cutoff date, replace date('Y-m-d') with a fixed date like '2025-01-15'
// Using today's date means: all existing users (created before today) won't see tutorial
$tutorialCutoffDate = date('Y-m-d'); // All users created before today are considered "old users"

// Check tutorial_completed status per account
// Tutorial should ONLY show to NEW users (created after cutoff date) who haven't completed it
$tutorial_completed = 1; // Default to 1 (completed) - don't show tutorial if column doesn't exist

if ($profile) {
    // Check if user is "old" (created before cutoff date) or "new" (created on/after cutoff date)
    $userCreatedAt = isset($profile['user_created_at']) ? $profile['user_created_at'] : null;
    $isNewUser = false;
    
    if ($userCreatedAt) {
        $userCreatedDate = date('Y-m-d', strtotime($userCreatedAt));
        $isNewUser = ($userCreatedDate >= $tutorialCutoffDate);
    }
    
    if ($columnExists && isset($profile['tutorial_completed'])) {
        // Column exists - check the actual value from database
        $tutorial_completed = isset($profile['tutorial_completed']) ? (int)$profile['tutorial_completed'] : 0;
        
        // Check if tutorial_completed is explicitly set (not NULL)
        $tutorialExplicitlySet = isset($profile['tutorial_completed']) && $profile['tutorial_completed'] !== null;
        
        // If this is an OLD user (created before cutoff date), force tutorial_completed = 1
        // Old users should NOT see the tutorial
        // PERFORMANCE: Defer UPDATE to background - don't block page load
        if (!$isNewUser) {
            // This is an old user - ensure tutorial is marked as completed
            // Only auto-complete if it hasn't been explicitly set yet (is NULL)
            if (!$tutorialExplicitlySet || $tutorial_completed !== 1) {
                // PERFORMANCE: Set flag to update in background, don't block page load
                // The update will happen via a deferred background task or on next page load
                $_SESSION['tutorial_needs_update'] = true;
                $tutorial_completed = 1; // Set value immediately for display
            }
        } else {
            // This is a NEW user - show tutorial if not completed (tutorial_completed = 0)
            // Ensure: if value is 0 or NULL, set to 0 (show tutorial)
            // Don't change if it's already explicitly set to 1 (completed)
            if ($tutorial_completed !== 1) {
                $tutorial_completed = 0; // false = show tutorial
            }
        }
    } else {
        // Column doesn't exist yet or not set - default to 1 (don't show tutorial until migration is run)
        $tutorial_completed = 1;
    }
} else {
    // No profile found - default to 1 (don't show tutorial)
    $tutorial_completed = 1;
}

// PERFORMANCE: Handle deferred tutorial update in background (non-blocking)
// This prevents blocking page load with UPDATE queries
if (isset($_SESSION['tutorial_needs_update']) && $_SESSION['tutorial_needs_update'] === true) {
    // Use register_shutdown_function to run update after page is sent to browser
    register_shutdown_function(function() use ($db) {
        try {
            $updateStmt = $db->prepare("UPDATE faculty_profiles SET tutorial_completed = 1 WHERE user_id = ? AND (tutorial_completed IS NULL OR tutorial_completed != 1)");
            $updateStmt->execute([$_SESSION['user_id']]);
            unset($_SESSION['tutorial_needs_update']);
        } catch (Exception $e) {
            // Silently fail - don't log on every request to avoid log spam
        }
    });
}

// Get active requirements assigned to this faculty member
$stmt = $db->prepare("
    SELECT r.* 
    FROM requirements r
    INNER JOIN faculty_requirements fr ON r.id = fr.requirement_id
    WHERE r.is_active = 1 AND fr.faculty_id = ?
    ORDER BY r.deadline ASC
");
$stmt->execute([$_SESSION['user_id']]);
$requirements = $stmt->fetchAll();

// Get faculty submissions
$stmt = $db->prepare("SELECT fs.*, r.title as requirement_title, r.deadline FROM faculty_submissions fs JOIN requirements r ON fs.requirement_id = r.id WHERE fs.faculty_id = ? ORDER BY fs.submitted_at DESC");
$stmt->execute([$_SESSION['user_id']]);
$submissions = $stmt->fetchAll();

// Get PDS status — prioritize submitted/approved over draft (same logic as pds.php)
$stmt = $db->prepare("
    SELECT id, faculty_id, status, submitted_at, updated_at, created_at, admin_notes 
    FROM faculty_pds 
    WHERE faculty_id = ? 
    ORDER BY 
        CASE WHEN status IN ('submitted','approved') THEN 0 ELSE 1 END,
        COALESCE(submitted_at, created_at) DESC,
        id DESC 
    LIMIT 1
");
$stmt->execute([$_SESSION['user_id']]);
$pds = $stmt->fetch();

// Get notifications (recent requirements, deadlines, etc.)
$notifications = [];
foreach ($requirements as $req) {
    if ($req['deadline'] && strtotime($req['deadline']) <= strtotime('+7 days')) {
        $notifications[] = [
            'type' => 'deadline',
            'message' => "Deadline approaching for: " . $req['title'],
            'date' => $req['deadline']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <!-- PWA Meta Tags - Must be first for full-screen app experience -->
    <?php include_once '../includes/pwa-meta.php'; ?>
    <meta name="description" content="Faculty Dashboard - WPU Faculty and Staff Management System">
    <meta http-equiv="x-dns-prefetch-control" content="on">
    <title>Dashboard - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <?php
    $basePath = getBasePath();
    $cacheVersion = '1.0.0';
    ?>
    
    <!-- Preload critical resources -->
    <link rel="preload" href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>?v=<?php echo $cacheVersion; ?>" as="style">
    <link rel="preload" href="<?php echo asset_url('css/style.css', true); ?>?v=<?php echo $cacheVersion; ?>" as="style">
    <link rel="preload" href="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>?v=<?php echo $cacheVersion; ?>" as="script">
    <link rel="preload" href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>?v=<?php echo $cacheVersion; ?>" as="style">
    <!-- Prefetch font (will be loaded by CSS when needed) -->
    <link rel="prefetch" href="<?php echo asset_url('vendor/fontawesome/webfonts/fa-solid-900.woff2', true); ?>" as="font" type="font/woff2" crossorigin>
    
    <!-- Critical CSS inline for fastest first paint -->
    <style>
    :root{--primary:#003366;--bg:#f8fafc;--white:#fff;--text:#0f172a;--border:#e2e8f0}*{margin:0;padding:0;box-sizing:border-box}body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:var(--text);font-size:14px}.header{position:fixed;top:0;left:0;right:0;height:56px;background:var(--white);border-bottom:1px solid var(--border);display:flex;align-items:center;z-index:1030}.sidebar{position:fixed;top:0;left:0;width:280px;height:100vh;background:var(--white);border-right:1px solid var(--border);z-index:1028;transform:translateX(-100%);transition:transform .25s}.sidebar.show{transform:translateX(0)}@media(min-width:992px){.header{left:280px;width:calc(100% - 280px)}.sidebar{transform:translateX(0)}}.main-content{margin-top:56px;padding:1rem;min-height:calc(100vh - 56px)}@media(min-width:992px){.main-content{margin-left:280px;width:calc(100% - 280px)}}.card{background:var(--white);border:1px solid var(--border);border-radius:.5rem}
    </style>
    
    <!-- Core stylesheets -->
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet">
    
    <!-- Non-critical CSS (async load with fallback) -->
    <link href="<?php echo asset_url('css/mobile.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'; this.onload=null;">
    <noscript><link href="<?php echo asset_url('css/mobile.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet"></noscript>
    <link href="<?php echo asset_url('css/mobile-button-fix.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'; this.onload=null;">
    <script>
        // Fallback: Ensure CSS loads even if onload doesn't fire (e.g., from cache)
        (function() {
            setTimeout(function() {
                var mobileCSS = document.querySelector('link[href*="mobile.css"]');
                if (mobileCSS && mobileCSS.media === 'print') {
                    mobileCSS.media = 'all';
                }
                var buttonFixCSS = document.querySelector('link[href*="mobile-button-fix.css"]');
                if (buttonFixCSS && buttonFixCSS.media === 'print') {
                    buttonFixCSS.media = 'all';
                }
            }, 100);
        })();
    </script>
    
    <!-- Intro.js for Tutorial (async, vendored for offline) -->
    <link href="<?php echo asset_url('vendor/introjs/introjs.min.css', true); ?>?v=<?php echo $cacheVersion; ?>" rel="stylesheet" media="print" onload="this.media='all'">
    <style>
        /* Custom styles for tutorial - Enhanced for better visibility */
        .introjs-tooltip {
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            font-size: 14px;
            /* Ensure tooltip stays within viewport */
            max-width: min(400px, calc(100vw - 40px));
            max-height: calc(100vh - 40px);
            overflow-y: auto;
            overflow-x: hidden;
            /* Allow JavaScript to position tooltip */
            box-sizing: border-box;
        }
        .introjs-tooltip h3 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #003366;
            font-size: 18px;
        }
        .introjs-tooltip p {
            margin-bottom: 8px;
            line-height: 1.5;
        }
        .introjs-tooltip ul {
            margin: 10px 0;
            padding-left: 20px;
        }
        .introjs-tooltip-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e0e0e0;
        }
        .introjs-tooltip-title {
            margin: 0;
            flex: 1;
        }
        .introjs-tooltipbuttons {
            border-top: 1px solid #e0e0e0;
            padding-top: 10px;
            margin-top: 10px;
        }
        .introjs-button {
            border-radius: 4px;
            padding: 6px 12px;
            font-weight: 500;
        }
        .introjs-button.introjs-nextbutton {
            background-color: #003366;
            border-color: #003366;
        }
        .introjs-button.introjs-nextbutton:hover {
            background-color: #004488;
            border-color: #004488;
        }
        .introjs-skipbutton {
            display: inline-block;
            width: 36%;
            background-color: #6c757d;
            color: #ffffff !important;
            border: 1px solid #6c757d;
            border-radius: 6px;
            padding: 8px 16px;
            font-weight: 500;
            font-size: 14px;
            text-decoration: none !important;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            margin: 0;
            line-height: 1.5;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
        }
        .introjs-skipbutton:hover {
            background-color: #5a6268;
            border-color: #5a6268;
            color: #ffffff !important;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
            transform: translateY(-1px);
        }
        .introjs-skipbutton:active {
            background-color: #545b62;
            border-color: #545b62;
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .introjs-skipbutton:focus {
            outline: 2px solid rgba(108, 117, 125, 0.5);
            outline-offset: 2px;
        }
        /* Ensure arrows are visible */
        .introjs-arrow {
            border-width: 10px;
        }
        .introjs-arrow.top {
            border-bottom-color: #fff;
        }
        .introjs-arrow.bottom {
            border-top-color: #fff;
        }
        .introjs-arrow.left {
            border-right-color: #fff;
        }
        .introjs-arrow.right {
            border-left-color: #fff;
        }
        /* Highlight overlay */
        .introjs-overlay {
            opacity: 0.8;
        }
        /* Progress bar */
        .introjs-progress {
            background-color: #003366;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 768px) {
            .introjs-tooltip {
                max-width: calc(100vw - 30px);
                max-height: calc(100vh - 30px);
                font-size: 13px;
                margin: 15px !important;
            }
            .introjs-tooltip h3 {
                font-size: 16px;
            }
            .introjs-tooltipbuttons {
                flex-direction: column;
                gap: 8px;
            }
            .introjs-button {
                width: 100%;
            }
            .introjs-skipbutton {
                padding: 10px 16px;
                font-size: 14px;
                width: auto;
                min-width: 120px;
                touch-action: manipulation;
                -webkit-tap-highlight-color: rgba(108, 117, 125, 0.2);
                white-space: nowrap;
            }
        }
        
        /* Ensure tooltip reference layer respects viewport */
        .introjs-tooltipReferenceLayer {
            position: fixed !important;
            z-index: 99999999 !important;
        }
        
        /* Floating tooltip (no element) - center within viewport */
        .introjs-tooltip.introjs-floating {
            position: fixed !important;
            left: 50% !important;
            top: 50% !important;
            transform: translate(-50%, -50%) !important;
            margin: 0 !important;
            max-width: min(400px, calc(100vw - 40px));
            max-height: calc(100vh - 40px);
        }
        
        /* Ensure tooltip container can scroll if content is long */
        .introjs-tooltiptext {
            max-height: calc(100vh - 200px);
            overflow-y: auto;
            padding-right: 5px;
        }
        
        /* Custom scrollbar for tooltip content */
        .introjs-tooltiptext::-webkit-scrollbar {
            width: 6px;
        }
        .introjs-tooltiptext::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        .introjs-tooltiptext::-webkit-scrollbar-thumb {
            background: #888;
            border-radius: 3px;
        }
        .introjs-tooltiptext::-webkit-scrollbar-thumb:hover {
            background: #555;
        }
        
        /* Tutorial Skip Confirmation Modal Styles */
        .tutorial-skip-modal {
            border: none;
            border-radius: 20px;
            box-shadow: 0 25px 70px rgba(0, 0, 0, 0.35), 0 0 0 1px rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }
        
        .tutorial-skip-modal-header {
            background: linear-gradient(135deg, #003366 0%, #005599 100%);
            color: #ffffff;
            border-bottom: none;
            padding: 2rem 2rem -0.5rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            position: relative;
        }
        
        .tutorial-skip-modal-header .btn-close {
            filter: invert(1) brightness(2);
            opacity: 0.85;
            margin-left: auto;
            font-size: 1.25rem;
            padding: 0.5rem;
        }
        
        .tutorial-skip-modal-header .btn-close:hover {
            opacity: 1;
            transform: scale(1.1);
        }
        
        .tutorial-modal-icon-wrapper {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.2);
            flex-shrink: 0;
        }
        
        .tutorial-skip-modal-header .modal-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
            color: #ffffff;
            line-height: 1.3;
        }
        
        .tutorial-skip-modal-body {
            padding: 2.5rem 2rem;
            background: #ffffff;
        }
        
        .tutorial-confirmation-message {
            font-size: 1.125rem;
            color: #1e293b;
            margin-bottom: 1rem;
            font-weight: 500;
            line-height: 1.7;
        }
        
        .tutorial-confirmation-note {
            background: linear-gradient(135deg, #fff7ed 0%, #ffedd5 100%);
            border-left: 4px solid #f59e0b;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-top: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.875rem;
        }
        
        .tutorial-confirmation-note i {
            color: #f59e0b;
            font-size: 1.25rem;
            margin-top: 2px;
            flex-shrink: 0;
        }
        
        .tutorial-confirmation-note-text {
            color: #92400e;
            font-size: 0.95rem;
            line-height: 1.6;
            margin: 0;
            font-weight: 500;
        }
        
        .tutorial-skip-modal-footer {
            padding: 1.5rem 2rem 2rem 2rem;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        
        .tutorial-skip-modal-footer .btn {
            padding: 0.75rem 2rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 10px;
            min-width: 140px;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }
        
        .tutorial-skip-modal-footer .btn-outline-secondary {
            background: #ffffff;
            border: 2px solid #e5e7eb;
            color: #64748b;
        }
        
        .tutorial-skip-modal-footer .btn-outline-secondary:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
            color: #475569;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .tutorial-skip-modal-footer .btn-primary {
            background: linear-gradient(135deg, #003366 0%, #005599 100%);
            border: none;
            color: #ffffff;
            box-shadow: 0 4px 14px rgba(0, 51, 102, 0.3);
        }
        
        .tutorial-skip-modal-footer .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 51, 102, 0.4);
            background: linear-gradient(135deg, #004488 0%, #0066bb 100%);
        }
        
        .tutorial-skip-modal-footer .btn-primary:active {
            transform: translateY(0);
        }
        
        /* Mobile responsive */
        @media (max-width: 576px) {
            .tutorial-skip-modal-header {
                padding: 1.5rem 1.5rem 1.25rem 1.5rem;
            }
            
            .tutorial-skip-modal-header .modal-title {
                font-size: 1.25rem;
            }
            
            .tutorial-modal-icon-wrapper {
                width: 48px;
                height: 48px;
                font-size: 1.5rem;
            }
            
            .tutorial-skip-modal-body {
                padding: 1.75rem 1.5rem;
            }
            
            .tutorial-confirmation-message {
                font-size: 1rem;
            }
            
            .tutorial-skip-modal-footer {
                padding: 1.25rem 1.5rem 1.5rem 1.5rem;
                flex-direction: column;
            }
            
            .tutorial-skip-modal-footer .btn {
                width: 100%;
                min-width: auto;
            }
        }
        
        /* Animation */
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .tutorial-skip-modal.show .tutorial-skip-modal {
            animation: slideDown 0.3s ease-out;
        }
    </style>
</head>
<style>
   
    
    /* Remove excessive padding from Cancel button in modals */
    .btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"],
    button.btn-outline-secondary.me-2[data-bs-dismiss="modal"][data-mobile-fixed="true"] {
        padding: 0.375rem 0.75rem !important;
    }
    
    /* Remove and override excessive padding from Tutorial Skip Modal buttons */
    #tutorialSkipCancelBtn,
    #tutorialSkipConfirmBtn,
    button#tutorialSkipCancelBtn,
    button#tutorialSkipConfirmBtn,
    #tutorialSkipCancelBtn[data-mobile-fixed="true"],
    #tutorialSkipConfirmBtn[data-mobile-fixed="true"] {
        padding: 0.75rem 2rem !important;
    }
    
    /* Override any inline padding styles */
    #tutorialSkipCancelBtn[style*="padding"],
    #tutorialSkipConfirmBtn[style*="padding"] {
        padding: 0.75rem 2rem !important;
    }
    
    /* Remove and override excessive padding from Tutorial Skip Modal close button */
    #tutorialSkipModal .btn-close,
    .tutorial-skip-modal-header .btn-close,
    .tutorial-skip-modal .btn-close[data-mobile-fixed="true"],
    #tutorialSkipModal .btn-close[data-mobile-fixed="true"],
    button.btn-close[data-bs-dismiss="modal"][data-mobile-fixed="true"] {
        padding: 0.5rem !important;
    }
    
    /* Override any inline padding styles on close button */
    #tutorialSkipModal .btn-close[style*="padding"],
    .tutorial-skip-modal-header .btn-close[style*="padding"] {
        padding: 0.5rem !important;
    }
</style>
<body class="layout-faculty" data-tutorial-completed="<?php echo $tutorial_completed; ?>">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>

    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header">
                    <div>
                        
                        <div class="page-title">
                            <i class="fas fa-tachometer-alt"></i>
                            <span><?php echo (strtolower(trim($user_type)) === 'staff') ? 'Staff Dashboard' : 'Faculty Dashboard'; ?></span>
                        </div>
                    </div>
                    <div class="d-none d-md-flex align-items-center gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.location.reload()">
                            <i class="fas fa-sync-alt me-1"></i>Refresh
                        </button>
                    </div>
                </div>

                <?php 
                    displayMessage(); 
                    $firstName = explode(' ', trim($_SESSION['user_name']))[0] ?? 'there';
                    $approvedSubmissions = array_filter($submissions, function($s) { return $s['status'] === 'approved'; });
                    $pendingSubmissions = array_filter($submissions, function($s) { return $s['status'] === 'pending'; });
                    $upcomingRequirement = null;
                    if (!empty($requirements)) {
                        $sortedRequirements = $requirements;
                        usort($sortedRequirements, function($a, $b) {
                            return strtotime($a['deadline'] ?? '2100-01-01') <=> strtotime($b['deadline'] ?? '2100-01-01');
                        });
                        foreach ($sortedRequirements as $req) {
                            if (!empty($req['deadline']) && strtotime($req['deadline']) >= strtotime('today')) {
                                $upcomingRequirement = $req;
                                break;
                            }
                        }
                    }
                    $pdsStatusLabel = $pds['status'] ?? 'Not Started';
                ?>

                <section class="faculty-hero mb-3">
                    <div class="faculty-hero-content">
                        <span class="faculty-hero-title">Welcome back, <?php echo htmlspecialchars($firstName); ?>!</span>
                        <p class="faculty-hero-support mb-2">
                            You have <strong><?php echo count($requirements); ?></strong> active requirement<?php echo count($requirements) === 1 ? '' : 's'; ?>
                            and <strong><?php echo count($pendingSubmissions); ?></strong> pending submission<?php echo count($pendingSubmissions) === 1 ? '' : 's'; ?>.
                        </p>
                        <?php if ($profile && ($profile['department'] || $profile['position'])): ?>
                            <div class="faculty-chip-list">
                                <?php if ($profile['department']): ?>
                                    <span class="faculty-chip"><i class="fas fa-building me-1"></i><?php echo htmlspecialchars($profile['department']); ?></span>
                                <?php endif; ?>
                                <?php if ($profile['position']): ?>
                                    <span class="faculty-chip"><i class="fas fa-briefcase me-1"></i><?php echo htmlspecialchars($profile['position']); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="faculty-hero-actions">
                        <a href="requirements.php" class="btn btn-light">
                            <i class="fas fa-clipboard-check me-1"></i>View Requirements
                        </a>
                        <a href="pds.php" class="btn btn-primary">
                            <i class="fas fa-id-card me-1"></i>Update PDS
                        </a>
                        
                    </div>
                </section>

                

                <div class="faculty-grid">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-tasks me-2 text-primary"></i>Assigned Requirements
                            </h5>
                            <a href="requirements.php" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-eye me-1"></i>All
                            </a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($requirements)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-clipboard-check"></i>
                                    <span class="empty-title">All caught up!</span>
                                    <p class="mb-0">You're up-to-date. New tasks will appear here.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle">
                                        <thead>
                                            <tr>
                                                <th>Requirement</th>
                                                <th class="d-none d-lg-table-cell">Description</th>
                                                <th>Deadline</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($requirements as $requirement):
                                                $submission = array_filter($submissions, function($s) use ($requirement) {
                                                    return $s['requirement_id'] == $requirement['id'];
                                                });
                                                $submission = reset($submission);
                                                $deadlineSoon = $requirement['deadline'] && strtotime($requirement['deadline']) <= strtotime('+7 days');
                                            ?>
                                                <tr onclick="window.location.href='requirements.php?action=submit&id=<?php echo $requirement['id']; ?>'" style="cursor: pointer; touch-action: manipulation; -webkit-tap-highlight-color: rgba(0, 51, 102, 0.1);">
                                                    <td data-label="Requirement">
                                                        <strong><?php echo htmlspecialchars($requirement['title']); ?></strong>
                                                        <div class="text-muted small d-lg-none">
                                                            <?php echo substr(trim($requirement['description'] ?? ''), 0, 60) . (strlen($requirement['description'] ?? '') > 60 ? '...' : ''); ?>
                                                        </div>
                                                    </td>
                                                    <td class="d-none d-lg-table-cell">
                                                        <?php echo substr(trim($requirement['description'] ?? ''), 0, 80) . (strlen($requirement['description'] ?? '') > 80 ? '...' : ''); ?>
                                                    </td>
                                                    <td data-label="Deadline">
                                                        <?php if ($requirement['deadline']): ?>
                                                            <span><?php echo formatDate($requirement['deadline'], 'M j'); ?></span>
                                                            <?php if ($deadlineSoon): ?>
                                                                <span class="badge bg-warning ms-1">Soon</span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">N/A</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td data-label="Status" onclick="event.stopPropagation();">
                                                        <?php if ($submission): ?>
                                                            <span class="badge bg-<?php echo $submission['status'] === 'approved' ? 'success' : ($submission['status'] === 'rejected' ? 'danger' : 'warning'); ?>">
                                                                <?php echo ucfirst($submission['status']); ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <a href="requirements.php?action=submit&id=<?php echo $requirement['id']; ?>" class="btn btn-sm btn-primary" onclick="event.stopPropagation();">
                                                                <i class="fas fa-upload me-1"></i>Submit
                                                            </a>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-file-contract me-2 text-primary"></i>Personal Data Sheet
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!$pds): ?>
                                <div class="empty-state">
                                    <i class="fas fa-file-contract"></i>
                                    <span class="empty-title">PDS not started</span>
                                    <p class="mb-2">Complete your Personal Data Sheet to keep your profile up to date.</p>
                                    <a href="pds.php" class="btn btn-primary">
                                        <i class="fas fa-plus-circle me-1"></i>Start PDS
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="d-flex flex-column gap-2">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div>
                                            <span class="text-muted small d-block">Status</span>
                                            <span class="badge bg-<?php 
                                                echo $pds['status'] === 'approved' ? 'success' : 
                                                    ($pds['status'] === 'rejected' ? 'danger' : 
                                                        ($pds['status'] === 'submitted' ? 'warning' : 'info')); 
                                            ?>">
                                                <?php echo ucfirst($pds['status']); ?>
                                            </span>
                                        </div>
                                        <div>
                                            <?php if ($pds['status'] === 'draft'): ?>
                                                <a href="pds.php" class="btn btn-sm btn-primary">Continue</a>
                                            <?php elseif ($pds['status'] === 'rejected'): ?>
                                                <a href="pds.php" class="btn btn-sm btn-warning">Update</a>
                                            <?php else: ?>
                                                <a href="pds.php" class="btn btn-sm btn-outline-primary">View</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($pds['admin_notes']): ?>
                                        <div class="alert alert-info mb-0">
                                            <small><strong>Admin notes:</strong> <?php echo nl2br(htmlspecialchars(substr($pds['admin_notes'] ?? '', 0, 160))) . (strlen($pds['admin_notes'] ?? '') > 160 ? '...' : ''); ?></small>
                                        </div>
                                    <?php endif; ?>
                                    <small class="text-muted">
                                        <?php if ($pds['submitted_at']): ?>
                                            Submitted <?php echo formatDate($pds['submitted_at'], 'M j, Y'); ?>
                                        <?php else: ?>
                                            Updated <?php echo formatDate($pds['updated_at'], 'M j, Y'); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="faculty-grid">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-upload me-2 text-primary"></i>Recent Submissions
                            </h5>
                            <?php if (count($submissions) > 5): ?>
                                <a href="submissions.php" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-external-link-alt me-1"></i>Manage
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (empty($submissions)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-cloud-upload-alt"></i>
                                    <span class="empty-title">No submissions yet</span>
                                    <p class="mb-0">Submit a requirement to see it appear in your activity list.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table align-middle">
                                        <thead>
                                            <tr>
                                                <th>Requirement</th>
                                                <th class="d-none d-lg-table-cell">File</th>
                                                <th>Status</th>
                                                <th class="d-none d-xl-table-cell">Date</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($submissions, 0, 5) as $submission): ?>
                                                <tr>
                                                    <td data-label="Requirement">
                                                        <strong><?php echo htmlspecialchars($submission['requirement_title']); ?></strong>
                                                        <div class="text-muted small d-lg-none">
                                                            <?php echo htmlspecialchars(basename($submission['original_filename'])); ?>
                                                        </div>
                                                    </td>
                                                    <td class="d-none d-lg-table-cell">
                                                        <small><i class="fas fa-file-alt me-1"></i><?php echo htmlspecialchars(substr($submission['original_filename'] ?? '', 0, 40)) . (strlen($submission['original_filename'] ?? '') > 40 ? '...' : ''); ?></small>
                                                    </td>
                                                    <td data-label="Status">
                                                        <span class="badge bg-<?php 
                                                            echo $submission['status'] === 'approved' ? 'success' : 
                                                                ($submission['status'] === 'rejected' ? 'danger' : 'warning'); 
                                                        ?>">
                                                            <?php echo ucfirst($submission['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="d-none d-xl-table-cell">
                                                        <small><?php echo formatDate($submission['submitted_at'], 'M j, Y'); ?></small>
                                                    </td>
                                                    <td class="text-end">
                                                        <a href="submissions.php?view=<?php echo $submission['id']; ?>" class="btn btn-sm btn-primary">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                <i class="fas fa-bell me-2 text-primary"></i>Upcoming Deadlines
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($notifications)): ?>
                                <div class="empty-state">
                                    <i class="fas fa-calendar-check"></i>
                                    <span class="empty-title">No urgent deadlines</span>
                                    <p class="mb-0">You're on schedule. Keep an eye here for upcoming tasks.</p>
                                </div>
                            <?php else: ?>
                                <div class="faculty-timeline">
                                    <?php foreach (array_slice($notifications, 0, 4) as $notice): ?>
                                        <div class="faculty-timeline-item">
                                            <div class="faculty-timeline-content">
                                                <span class="faculty-timeline-title"><?php echo htmlspecialchars($notice['message']); ?></span>
                                                <?php if (!empty($notice['date'])): ?>
                                                    <span class="faculty-timeline-meta">
                                                        Due <?php echo formatDate($notice['date'], 'M j, Y'); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Tutorial Skip Confirmation Modal -->
    <div class="modal fade" id="tutorialSkipModal" tabindex="-1" aria-labelledby="tutorialSkipModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content tutorial-skip-modal">
                <div class="modal-header tutorial-skip-modal-header">
                    <div class="tutorial-modal-icon-wrapper">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <h5 class="modal-title" id="tutorialSkipModalLabel">Skip Tutorial?</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body tutorial-skip-modal-body">
                    <p class="tutorial-confirmation-message">
                        Are you sure you want to skip the tutorial?
                    </p>
                    <div class="tutorial-confirmation-note">
                        <i class="fas fa-info-circle"></i>
                        <p class="tutorial-confirmation-note-text">
                            <strong>Note:</strong> This tutorial will only show once per account. Once skipped or completed, it will not appear again automatically.
                        </p>
                    </div>
                </div>
                <div class="modal-footer tutorial-skip-modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" id="tutorialSkipCancelBtn">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                    <button type="button" class="btn btn-primary" id="tutorialSkipConfirmBtn">
                        <i class="fas fa-check me-2"></i>Yes, Skip Tutorial
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Load Bootstrap first - CRITICAL for mobile interactions -->
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>?v=<?php echo $cacheVersion; ?>"></script>
    <!-- Load main.js for core functionality -->
    <script src="<?php echo asset_url('js/main.js', true); ?>?v=<?php echo $cacheVersion; ?>" defer></script>
    <!-- Performance optimization -->
    <script src="<?php echo asset_url('js/performance.js', true); ?>?v=<?php echo $cacheVersion; ?>" defer></script>
    <!-- Load unified mobile interactions - replaces all mobile scripts -->
    <script src="<?php echo asset_url('js/mobile-interactions-unified.js', true); ?>?v=<?php echo $cacheVersion; ?>" defer></script>
    <!-- Intro.js for Tutorial (vendored for offline) -->
    <script src="<?php echo asset_url('vendor/introjs/intro.min.js', true); ?>?v=<?php echo $cacheVersion; ?>"></script>
    <!-- Faculty Tutorial Script -->
    <script>
        // IMPORTANT: Set tutorial status BEFORE loading faculty_tutorial.js
        // false (0) = show tutorial (not completed)
        // true (1) = don't show (completed)
        window.facultyTutorialCompleted = <?php echo $tutorial_completed; ?>;
        
        // Explicit check: if false (0), show tutorial
        const willShowTutorial = (window.facultyTutorialCompleted === 0 || window.facultyTutorialCompleted === false);
        console.log('=== TUTORIAL STATUS CHECK ===');
        console.log('Tutorial status for this account:', {
            'tutorial_completed': window.facultyTutorialCompleted,
            'type': typeof window.facultyTutorialCompleted,
            'will_show_tutorial': willShowTutorial,
            'message': willShowTutorial ? 'NOT COMPLETED - Will show tutorial' : 'COMPLETED - Will NOT show tutorial',
            'database_value': <?php echo json_encode($tutorial_completed); ?>,
            'column_exists': <?php echo $columnExists ? 'true' : 'false'; ?>
        });
        console.log('===========================');
    </script>
    <script src="<?php echo asset_url('js/faculty_tutorial.js', true); ?>" defer></script>
    <script>
        // Initialize Bootstrap tooltips (desktop only)
        document.addEventListener('DOMContentLoaded', function() {
            if (window.innerWidth >= 768) {
                var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                    return new bootstrap.Tooltip(tooltipTriggerEl);
                });
            }
            
            // CRITICAL FIX: Ensure buttons inside clickable table rows work on mobile
            // This fixes the issue where table rows with onclick prevent button clicks
            document.querySelectorAll('tr[onclick] button, tr[onclick] .btn, tr[onclick] a.btn').forEach(function(button) {
                // Stop click and mousedown from reaching parent row (but allow touch events to convert to click)
                ['click', 'mousedown'].forEach(function(eventType) {
                    button.addEventListener(eventType, function(e) {
                        // Stop event from reaching parent row
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                        // DON'T prevent default - let button's action work
                    }, true); // Use capture phase to intercept before parent
                });
                
                // For touch events, just stop propagation but don't prevent default
                // This allows browser to convert touch to click naturally
                button.addEventListener('touchstart', function(e) {
                    e.stopPropagation();
                    // DON'T prevent default - browser needs this to fire click
                }, { passive: true, capture: true });
                
                button.addEventListener('touchend', function(e) {
                    e.stopPropagation();
                    // DON'T prevent default - browser needs this to fire click
                }, { passive: true, capture: true });
                
                // Ensure button is clickable
                button.style.pointerEvents = 'auto';
                button.style.zIndex = '1000';
                button.style.position = 'relative';
                button.style.touchAction = 'manipulation';
            });
            
            // Remove and override excessive padding from Tutorial Skip Modal buttons
            function fixTutorialSkipButtons() {
                const cancelBtn = document.getElementById('tutorialSkipCancelBtn');
                const confirmBtn = document.getElementById('tutorialSkipConfirmBtn');
                const closeBtn = document.querySelector('#tutorialSkipModal .btn-close');
                
                if (cancelBtn) {
                    // Always override padding to remove excessive values like 34px 54px
                    cancelBtn.style.setProperty('padding', '0.75rem 2rem', 'important');
                }
                
                if (confirmBtn) {
                    // Always override padding to remove excessive values like 34px 54px
                    confirmBtn.style.setProperty('padding', '0.75rem 2rem', 'important');
                }
                
                if (closeBtn) {
                    // Override padding to remove excessive values like 30px
                    closeBtn.style.setProperty('padding', '0.5rem', 'important');
                }
            }
            
            // Fix buttons immediately
            fixTutorialSkipButtons();
            
            // Also fix buttons when modal is shown (in case they're modified dynamically)
            const tutorialSkipModal = document.getElementById('tutorialSkipModal');
            if (tutorialSkipModal) {
                tutorialSkipModal.addEventListener('shown.bs.modal', function() {
                    fixTutorialSkipButtons();
                    // Also fix after a short delay to catch any dynamic changes
                    setTimeout(fixTutorialSkipButtons, 100);
                });
                
                // Use MutationObserver to watch for style changes
                const observer = new MutationObserver(function(mutations) {
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'attributes' && mutation.attributeName === 'style') {
                            fixTutorialSkipButtons();
                        }
                    });
                });
                
                // Observe all buttons for style changes
                const cancelBtn = document.getElementById('tutorialSkipCancelBtn');
                const confirmBtn = document.getElementById('tutorialSkipConfirmBtn');
                const closeBtn = document.querySelector('#tutorialSkipModal .btn-close');
                
                if (cancelBtn) {
                    observer.observe(cancelBtn, { attributes: true, attributeFilter: ['style'] });
                }
                if (confirmBtn) {
                    observer.observe(confirmBtn, { attributes: true, attributeFilter: ['style'] });
                }
                if (closeBtn) {
                    observer.observe(closeBtn, { attributes: true, attributeFilter: ['style'] });
                }
            }
        });
    </script>
    <!-- PWA Scripts -->
    <?php include_once '../includes/pwa-script.php'; ?>
    <script src="<?php echo asset_url('js/pwa-install-prompt.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/pwa-app-behavior.js', true); ?>"></script>
</body>
</html>

