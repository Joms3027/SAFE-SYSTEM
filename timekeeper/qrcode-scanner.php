<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

// Override Permissions-Policy header to allow camera access
// This must be set AFTER config.php loads (which sets restrictive headers)
// and BEFORE any output
// 
// PORT FORWARDING COMPATIBILITY:
// Using 'camera=(self)' allows camera access for the same origin, which works for:
// - localhost/127.0.0.1 (local development)
// - Production domains (https://yourdomain.com)
// - Port forwarding tunnels (https://abc123.ngrok.io, https://*.devtunnels.ms, etc.)
// The '(self)' directive means "allow for same origin" regardless of domain
if (!headers_sent()) {
    // Remove any existing Permissions-Policy header first
    header_remove('Permissions-Policy');
    // Set new Permissions-Policy header allowing camera access
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(self)');
}

requireTimekeeper();

// Set flag to indicate user is accessing QR scanner
// This will be checked when navigating to dashboard
$_SESSION['from_qr_scanner'] = true;
// Clear password verification flag when accessing QR scanner
// So if user goes back to dashboard, they need to verify again
unset($_SESSION['timekeeper_password_verified']);

// Get timekeeper/station details
$timekeeper = null;
$isOffline = false;

// Try to connect to database, but don't fail if offline
try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    // Database connection failed - likely offline
    $isOffline = true;
    $db = null;
    error_log("Database connection failed (offline mode): " . $e->getMessage());
}

if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'station') {
    // Station-based login (new method) - works offline with session data only
    $timekeeper = [
        'station_name' => $_SESSION['station_name'] ?? 'Unknown Station',
        'station_id' => $_SESSION['station_id'] ?? 0
    ];
} elseif (isset($_SESSION['timekeeper_id']) && $db !== null) {
    // Old timekeeper login with user account - requires database
    try {
        $stmt = $db->prepare("
            SELECT tk.*, u.first_name, u.last_name, u.email,
                   fp.employee_id, s.name as station_name, s.id as station_id
            FROM timekeepers tk
            INNER JOIN users u ON tk.user_id = u.id
            LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
            LEFT JOIN stations s ON tk.station_id = s.id
            WHERE tk.id = ?
        ");
        $stmt->execute([$_SESSION['timekeeper_id']]);
        $timekeeper = $stmt->fetch();
    } catch (Exception $e) {
        // Database query failed - use session data only
        $isOffline = true;
        error_log("Database query failed (offline mode): " . $e->getMessage());
        // Create minimal timekeeper object from session
        $timekeeper = [
            'station_name' => $_SESSION['station_name'] ?? 'Unknown Station',
            'station_id' => $_SESSION['station_id'] ?? 0
        ];
    }
} elseif (isset($_SESSION['timekeeper_id'])) {
    // Old timekeeper login but database unavailable - use session data
    $isOffline = true;
    $timekeeper = [
        'station_name' => $_SESSION['station_name'] ?? 'Unknown Station',
        'station_id' => $_SESSION['station_id'] ?? 0
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Permissions-Policy" content="camera=(self)">
    <meta name="theme-color" content="#003366">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="application-name" content="WPU Safe Scanner">
    <meta name="apple-mobile-web-app-title" content="WPU Safe Scanner">
    <title>QR Code Scanner - Station Portal</title>
    
    <!-- PWA Manifest -->
    <link rel="manifest" href="<?php echo htmlspecialchars(SITE_URL . '/manifest.php', ENT_QUOTES, 'UTF-8'); ?>">
    
    <!-- Favicon and Apple Touch Icon (browser tab + home screen shortcut) -->
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link rel="apple-touch-icon" href="<?php echo asset_url('logo.png', true); ?>">
    
    <!-- Service Worker Registration -->
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                const siteUrl = '<?php echo rtrim(SITE_URL, '/'); ?>';
                const swUrl = siteUrl + '/service-worker.js';
                // Extract path from SITE_URL for scope
                const url = new URL(siteUrl || window.location.origin);
                const scope = url.pathname === '' ? '/' : url.pathname + '/';
                navigator.serviceWorker.register(swUrl, { scope: scope })
                    .then(registration => {
                        console.log('[PWA] Service Worker registered:', registration.scope);
                    })
                    .catch(error => {
                        console.log('[PWA] Service Worker registration failed:', error);
                    });
            });
        }
    </script>
    
    <!-- PWA Install Prompt Script -->
    <script src="<?php echo asset_url('js/pwa-install-prompt.js'); ?>"></script>
    
    <!-- Bootstrap CSS -->
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    
    <!-- Font Awesome -->
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css'); ?>" rel="stylesheet">
    
    <!-- SweetAlert2 CSS - Local for offline support -->
    <link href="<?php echo asset_url('vendor/sweetalert2.min.css'); ?>" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="../assets/css/timekeeper-mobile.css?v=3.7.1" rel="stylesheet">
    
    <style>
        /* QR Code Reader Styles - Minimal Bootstrap White Theme */
        .qrcode-page-body {
            background: #f8f9fa;
            min-height: 100vh;
            height: 100vh;
            max-height: 100vh;
            padding: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .qrcode-container {
            background: white;
            border-radius: 0.375rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            overflow: hidden;
            max-width: 1800px;
            margin: 0 auto;
            padding: 1rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
            height: 100%;
        }

        .qrcode-container > .row {
            flex: 1;
            display: flex;
            min-height: 0;
            margin: 0;
        }

        .qrcode-container > .row:last-of-type {
            flex: 0 0 auto;
        }

        .camera-section {
            background: white;
            padding: 1rem;
            border-right: 1px solid #dee2e6;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .camera-wrapper {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            width: 100%;
            max-width: 550px;
            margin-bottom: 0.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        #video {
            border-radius: 0.375rem;
            background: #000;
            height: 100%;
            width: 100%;
            object-fit: cover;
        }

        .scan-overlay {
            position: relative;
            width: 100%;
            aspect-ratio: 1 / 1;
            overflow: hidden;
            flex: 1;
            min-height: 0;
            max-height: 100%;
        }

        #qr-reader {
            width: 100% !important;
            height: 100% !important;
            position: relative;
        }

        #qr-reader video {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover;
            border-radius: 0.375rem;
        }

        #qr-reader__dashboard {
            display: none !important;
        }

        /* Hide default qr scanning region border */
        #qr-reader__scan_region {
            border: none !important;
            box-shadow: none !important;
        }

        #qr-reader img[alt="Scanning"] {
            display: none !important;
        }

        /* Responsive QR shaded region - override html5-qrcode fixed pixels for any desktop size */
        #qr-reader #qr-shaded-region {
            border-width: clamp(60px, 12vh, 140px) clamp(15px, 3vw, 30px) !important;
            box-sizing: border-box !important;
        }
        /* Horizontal corner bars (40x5) */
        #qr-reader #qr-shaded-region > div {
            width: clamp(25px, 5vw, 45px) !important;
            height: clamp(4px, 0.8vw, 6px) !important;
            min-width: 20px;
            min-height: 3px;
        }
        /* Vertical corner bars (5x45) - match by height: 45px in inline style */
        #qr-reader #qr-shaded-region > div[style*="45px"] {
            width: clamp(4px, 0.8vw, 6px) !important;
            height: clamp(30px, 6vh, 50px) !important;
        }

        .button-group {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.75rem;
            width: 100%;
            padding: 1rem;
            margin-top: 0.5rem;
            background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%);
            border-radius: 1rem;
            box-shadow: inset 0 2px 4px rgba(0, 0, 0, 0.06);
            /* Hidden by default, shown after QR scan */
            opacity: 0;
            max-height: 0;
            overflow: hidden;
            padding: 0 1rem;
            transition: all 0.4s ease-out;
            flex-shrink: 0;
        }

        .button-group.visible {
            opacity: 1;
            max-height: 260px;
            padding: 1rem;
            position: relative;
            z-index: 200;
        }

        .button-group .btn {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
            padding: 0.75rem 0.5rem;
            font-size: 1.1rem;
            font-weight: 600;
            border-radius: 0.875rem;
            border: none;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            transition: all 0.2s ease;
            min-height: 70px;
        }

        .button-group .btn i {
            font-size: 1.65rem;
        }

        .button-group .btn:not(:disabled):hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.15), 0 4px 6px -2px rgba(0, 0, 0, 0.1);
        }

        .button-group .btn:not(:disabled):active {
            transform: translateY(-1px);
        }

        .button-group .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            box-shadow: none;
        }

        /* Custom button colors with gradients */
        .button-group .btn-primary {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
        }

        .button-group .btn-secondary {
            background: linear-gradient(135deg, #64748b 0%, #475569 100%);
            color: white;
        }

        .button-group .btn-info {
            background: linear-gradient(135deg, #06b6d4 0%, #0891b2 100%);
        }

        .button-group .btn-warning {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            color: #1f2937;
        }

        .button-group .btn-dark {
            background: linear-gradient(135deg, #374151 0%, #1f2937 100%);
        }

        .button-group .btn-success {
            background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);
        }

        .table-section {
            padding: 1rem;
            background: white;
            display: flex;
            flex-direction: column;
            min-height: 0;
            overflow: hidden;
        }

        .table-section .table-responsive {
            flex: 1;
            overflow-y: auto;
            min-height: 0;
        }

        .alert-message {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
        }

        .camera-status {
            margin-top: 0.5rem;
            font-size: 0.875rem;
        }

        /* Offline Status Indicator */
        .offline-indicator {
            position: fixed;
            top: 70px;
            right: 20px;
            z-index: 1000;
            padding: 0.5rem 1rem;
            border-radius: 0.375rem;
            font-size: 0.875rem;
            font-weight: 500;
            display: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            animation: slideIn 0.3s ease-out;
        }

        .offline-indicator.show {
            display: block;
        }

        .offline-indicator.offline {
            background: #dc3545;
            color: white;
        }

        .offline-indicator.syncing {
            background: #ffc107;
            color: #000;
        }

        .offline-indicator.pending {
            background: #0dcaf0;
            color: #000;
        }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        /* Mobile styles moved to timekeeper-mobile.css */
        /* Keeping this for tablet breakpoint only */
        @media (min-width: 768px) and (max-width: 992px) {
            .camera-section {
                border-right: none;
                border-bottom: 1px solid #dee2e6;
            }

            .button-group {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.75rem;
                padding: 1rem;
            }

            .button-group .btn {
                min-height: 80px;
                padding: 1rem 0.75rem;
                font-size: 1rem;
            }

            .button-group .btn i {
                font-size: 1.5rem;
            }
        }

        /* Mobile styles for smaller screens */
        @media (max-width: 576px) {
            .button-group {
                grid-template-columns: repeat(2, 1fr);
                gap: 0.625rem;
                padding: 0.875rem;
                border-radius: 0.75rem;
            }

            .button-group .btn {
                min-height: 70px;
                padding: 0.875rem 0.5rem;
                font-size: 0.9rem;
                border-radius: 0.625rem;
            }

            .button-group .btn i {
                font-size: 1.35rem;
            }
        }

        /* Ensure navbar doesn't cause overflow */
        .navbar {
            flex-shrink: 0;
        }

        /* Adjust h3 margin for better spacing */
        .camera-section h3 {
            margin-bottom: 0.75rem;
        }

        /* Current employee card - shows who is selected after scan */
        .current-employee-card {
            display: none;
            align-items: center;
            gap: 0.875rem;
            padding: 0.875rem 1rem;
            background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
            border: 1px solid #a7f3d0;
            border-radius: 0.75rem;
            margin-bottom: 1rem;
            flex-shrink: 0;
        }
        .current-employee-card.visible {
            display: flex;
            flex-wrap: wrap;
        }
        .current-employee-card .employee-info {
            flex: 1;
            min-width: 0;
        }
        .current-employee-card .employee-info strong {
            display: block;
            color: #065f46;
            font-size: 1rem;
            margin-bottom: 0.15rem;
        }
        .current-employee-card .employee-info span {
            font-size: 0.875rem;
            color: #047857;
        }
        .current-employee-card .btn-clear-selection {
            flex-shrink: 0;
            font-size: 0.875rem;
            padding: 0.5rem 0.875rem;
            border-radius: 0.5rem;
        }
        .current-employee-card .employee-avatar-placeholder {
            width: 2.5rem;
            height: 2.5rem;
            background: rgba(16, 185, 129, 0.2);
            color: #059669;
        }
        .current-employee-card .employee-avatar-placeholder i {
            font-size: 1.25rem;
        }

        /* Scan hint when camera is active */
        .gun-scanner-hidden-input {
            position: absolute;
            left: -9999px;
            width: 1px;
            height: 1px;
            opacity: 0;
            overflow: hidden;
        }

        .scan-hint {
            position: absolute;
            bottom: 0.75rem;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.65);
            color: #fff;
            font-size: 0.8rem;
            padding: 0.4rem 0.875rem;
            border-radius: 999px;
            white-space: nowrap;
            z-index: 5;
            pointer-events: none;
        }

        /* Camera placeholder - clearer hierarchy */
        .camera-placeholder-icon {
            font-size: 4rem;
            opacity: 0.85;
            color: #0d6efd;
        }
        .camera-placeholder-title { color: #212529; }
        .camera-placeholder-lead { max-width: 360px; margin-left: auto; margin-right: auto; }
        .camera-placeholder-steps { max-width: 280px; margin-left: auto; margin-right: auto; text-align: left; }
        .camera-placeholder-steps li { margin-bottom: 0.35rem; }
    </style>
</head>
<body>
    <!-- Mobile drawer backdrop (tap to close) -->
    <div id="attendanceDrawerBackdrop" class="attendance-drawer-backdrop" aria-hidden="true" role="button" tabindex="0" title="Close attendance"></div>
    <!-- Offline Status Indicator -->
    <div id="offlineIndicator" class="offline-indicator">
        <i class="bi bi-wifi-off me-2"></i><span id="offlineText">Offline</span>
    </div>

    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm" style="margin-bottom: 0;" role="navigation">
        <div class="container-fluid d-flex justify-content-between align-items-center flex-nowrap">
            <a class="navbar-brand fw-bold flex-shrink-0" href="qrcode-scanner.php" style="color: #0d6efd;">
                <i class="bi bi-qr-code-scan me-2"></i><span class="d-none d-sm-inline">Station Scanner</span><span class="d-inline d-sm-none">Scanner</span>
            </a>
            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                <button type="button" id="btn-mobile-attendance" class="btn btn-outline-primary d-lg-none d-flex align-items-center gap-2 py-2 px-3" aria-label="View today's attendance">
                    <i class="bi bi-calendar-check" aria-hidden="true"></i>
                    <span>Attendance</span>
                </button>
                <div class="dropdown d-lg-none">
                    <button class="navbar-toggler dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Menu">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item active" href="qrcode-scanner.php"><i class="bi bi-qr-code-scan me-2"></i><?php echo htmlspecialchars($timekeeper['station_name'] ?? 'Station'); ?> Scanner</a></li>
                        <li><a class="dropdown-item" href="dashboard.php"><i class="bi bi-grid me-2"></i>Dashboard</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
                <div class="d-none d-lg-flex gap-2 ms-auto">
                    <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-grid me-2"></i>Dashboard
                    </a>
                    <a href="../logout.php" class="btn btn-outline-danger">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="qrcode-page-body" style="padding-top: 0;">
        <div class="qrcode-container" style="margin-top: 0;">
            <div class="row">
                <!-- Left Side: Camera Section -->
                <div class="col-lg-5 camera-section">
                    <h3 class="mb-4">
                        <i class="bi bi-qr-code-scan me-2"></i><?php echo htmlspecialchars($timekeeper['station_name'] ?? 'Station'); ?> Scanner
                    </h3>

                    <div class="camera-wrapper">
                        <div class="scan-overlay" id="scanOverlay" style="display: none;">
                            <div id="qr-reader" style="width: 100%;"></div>
                            <span class="scan-hint" id="scanHint" aria-hidden="true"><i class="bi bi-upc-scan me-1"></i>Position QR code in frame</span>
                        </div>
                        <div id="cameraPlaceholder" class="camera-placeholder-inner text-center py-5">
                            <i class="bi bi-qr-code-scan camera-placeholder-icon mb-3 d-block" aria-hidden="true"></i>
                            <h2 class="camera-placeholder-title h5 mb-2">Scan employee QR codes</h2>
                            <p class="camera-placeholder-lead text-muted mb-3">Point the camera at an employee's QR code to record time in, lunch, time out, and more.</p>
                            <ol class="camera-placeholder-steps list-unstyled small text-muted mb-4">
                                <li><strong>1.</strong> Tap Start Camera below</li>
                                <li><strong>2.</strong> Allow camera access when prompted</li>
                                <li><strong>3.</strong> Hold the QR code inside the frame</li>
                                <li><strong>4.</strong> Choose the action (Time In, Lunch, etc.)</li>
                            </ol>
                            <div class="camera-placeholder-buttons">
                                <button id="btn-start-camera" class="btn btn-primary btn-lg" aria-label="Start camera to scan QR codes">
                                    <i class="bi bi-camera-fill me-2"></i>Start Camera
                                </button>
                                <button id="btn-test-camera" type="button" class="btn btn-outline-light btn-lg d-none d-md-inline-block" aria-label="Test camera access">
                                    <i class="bi bi-bug me-1"></i>Test
                                </button>
                            </div>
                        </div>
                        <div class="camera-status text-muted small mt-2" id="cameraStatus">
                            <span id="statusText">Tap &quot;Start Camera&quot; to begin scanning</span>
                            <button id="btn-switch-front-camera" class="btn btn-outline-info btn-sm mt-2 ms-2" style="display: none;">
                                <i class="bi bi-camera-reverse me-1"></i>Switch to Front Camera
                            </button>
                        </div>
                        <p class="text-muted small mt-2 mb-0" id="gunScannerHint">
                            <i class="bi bi-upc-scan me-1"></i>Handheld scanner ready (always listening)
                        </p>
                        <input type="text" id="gunScannerInput" class="gun-scanner-hidden-input"
                               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                               aria-hidden="true" tabindex="-1">
                    </div>
                </div>

                <!-- Right Side: Attendance Table -->
                <div class="col-lg-7 table-section" id="tableSection">
                    <p class="table-section-drag-hint d-md-none mb-0">Swipe down to close</p>
                    <!-- Mobile drawer header - shows employee ID when scanned -->
                    <div class="table-section-drawer-header d-lg-none">
                        <div class="min-w-0 w-100">
                            <h2 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Today's Attendance</h2>
                            <p class="text-muted small mb-0 mt-1" id="currentDateDrawer"></p>
                            <p class="text-muted small mb-0 mt-1 d-none" id="currentEmployeeDrawer"></p>
                        </div>
                    </div>
                    <!-- Current employee card (shown after scan) -->
                    <div id="currentEmployeeCard" class="current-employee-card" role="status" aria-live="polite">
                        <div class="employee-avatar-placeholder rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" aria-hidden="true">
                            <i class="bi bi-person-fill text-success"></i>
                        </div>
                        <div class="employee-info flex-grow-1 min-w-0">
                            <strong id="currentEmployeeName" class="d-block text-truncate">—</strong>
                            <span id="currentEmployeeId" class="d-block text-muted small">—</span>
                        </div>
                        <button type="button" id="btn-clear-selection" class="btn btn-outline-secondary btn-clear-selection" title="Clear selection and scan another employee">
                            <i class="bi bi-x-circle me-1"></i>Clear
                        </button>
                    </div>
                    <div class="mb-4 d-none d-lg-block">
                        <h2 class="mb-2"><i class="bi bi-calendar-check me-2"></i>Today's Attendance</h2>
                        <p class="text-muted mb-0 small" id="currentDate"></p>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-dark">
                                <tr>
                                    <th>Safe Employee ID</th>
                                    <th>Name</th>
                                    <th>Time In</th>
                                    <th>Lunch Out</th>
                                    <th>Lunch In</th>
                                    <th>Time Out</th>
                                    <th>OT In</th>
                                    <th>OT Out</th>
                                    <th>Total Hours</th>
                                </tr>
                            </thead>
                            <tbody id="attendanceTableBody">
                                <tr>
                                    <td colspan="9" class="text-center py-5 text-muted">
                                        <i class="bi bi-qr-code-scan d-block fs-2 mb-2" style="opacity: 0.6;"></i>
                                        <span>Scan a QR code to view and record attendance</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Action Buttons - Full Width -->
            <div class="row">
                <div class="col-12">
                    <div class="button-group">
                        <button id="btn-time-in" class="btn btn-primary" onclick="recordAttendance('time_in')" disabled>
                            <i class="bi bi-clock-fill"></i>
                            <span>Time In</span>
                        </button>
                        <button id="btn-lunch-out" class="btn btn-secondary" onclick="recordAttendance('lunch_out')" disabled>
                            <i class="bi bi-arrow-right-circle"></i>
                            <span>Lunch Out</span>
                        </button>
                        <button id="btn-lunch-in" class="btn btn-info text-white" onclick="recordAttendance('lunch_in')" disabled>
                            <i class="bi bi-arrow-left-circle"></i>
                            <span>Lunch In</span>
                        </button>
                        <button id="btn-time-out" class="btn btn-warning" onclick="recordAttendance('time_out')" disabled>
                            <i class="bi bi-clock-history"></i>
                            <span>Time Out</span>
                        </button>
                        <button id="btn-ot-in" class="btn btn-dark" onclick="recordAttendance('ot_in')" disabled>
                            <i class="bi bi-moon-stars"></i>
                            <span>OT In</span>
                        </button>
                        <button id="btn-ot-out" class="btn btn-success" onclick="recordAttendance('ot_out')" disabled>
                            <i class="bi bi-sunrise"></i>
                            <span>OT Out</span>
                        </button>
                        <button id="btn-clear-selection-footer" class="btn btn-outline-light border d-block w-100" onclick="clearSelection()" style="grid-column: 1 / -1; margin-top: 0.25rem;">
                            <i class="bi bi-qr-code-scan me-1"></i><span>Scan another employee</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- SweetAlert2 - Local for offline support -->
        <script src="<?php echo asset_url('vendor/sweetalert2.min.js'); ?>"></script>
        
        <!-- Offline Storage and Sync Manager -->
        <script src="<?php echo getBasePath() . '/timekeeper/js/offline-storage.js'; ?>"></script>
        <script src="<?php echo getBasePath() . '/timekeeper/js/sync-manager.js'; ?>"></script>
        
        <!-- QR Code Scanner Library - Sync load like HR_EVENT/scanner.php (which reads QR reliably) -->
        <script src="<?php echo asset_url('vendor/html5-qrcode.min.js'); ?>"></script>
        <script>
        (function() {
            const s = document.querySelector('script[src*="html5-qrcode"]');
            if (s && s.src) {
                const fallback = document.createElement('script');
                fallback.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
                s.onerror = function() { document.head.appendChild(fallback); };
            }
        })();
        </script>

        <script>
        let html5QrcodeScanner;
        let scannedUserData = null;
        let currentAttendanceData = null; // Store current attendance data for button state management
        let lastScannedQR = null;
        let scanCooldown = false;
        const cameraStatus = document.getElementById('cameraStatus');
        let libraryLoaded = false;
        let cameraInitialized = false;
        let currentFacingMode = 'environment'; // Track current camera: 'environment' (back) or 'user' (front)
        
        // Scanner health monitoring
        let scannerHealthCheckInterval = null;
        let lastScanTime = null;
        let isPageVisible = true;
        let scannerPaused = false;
        let cameraConfig = null; // Store camera config for recovery
        let lastRecoveryAttempt = 0;
        const RECOVERY_COOLDOWN = 60000; // Don't attempt recovery more than once per minute

        /** True when running on a mobile phone (or narrow touch device). Gun scanner is disabled on mobile. */
        const isMobileDevice = window.innerWidth <= 768 || /Android|webOS|iPhone|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);

        /**
         * Scanner config - single place to tune detection.
         * Do NOT add experimentalFeatures.useBarCodeDetectorIfSupported - it causes
         * "camera loads but no QR detected" on many devices (see html5-qrcode#531, #487).
         *
         * MOBILE: No qrbox = scan full viewfinder (better detection when user can't align precisely).
         * Lower fps (8) gives camera more focus time per frame (improves mobile scanning).
         *
         * DESKTOP: qrbox provides visual target; higher fps for responsive feel.
         */
        function getScannerConfig() {
            var isMobile = window.innerWidth <= 768 || ('ontouchstart' in window);
            var config = {
                fps: isMobile ? 8 : 12,
                aspectRatio: isMobile ? (4 / 3) : 1.0,  // 4:3 matches most phone cameras; square for desktop
                disableFlip: false
            };
            if (isMobile) {
                // Scan full viewfinder - no qrbox restriction. Improves mobile detection significantly.
                // User can hold QR anywhere in frame; library scans entire video stream.
            } else {
                config.qrbox = function(viewfinderWidth, viewfinderHeight) {
                    var minEdge = Math.min(viewfinderWidth || 0, viewfinderHeight || 0);
                    var size = Math.floor(minEdge * 0.9);
                    if (size < 250) {
                        var el = document.getElementById('qr-reader');
                        var r = el ? el.getBoundingClientRect() : { width: 0, height: 0 };
                        var w = r.width || (el ? el.offsetWidth || el.clientWidth : 0) || 0;
                        var h = r.height || (el ? el.offsetHeight || el.clientHeight : 0) || 0;
                        minEdge = Math.max(w, h) || 400;
                        size = Math.max(Math.floor(minEdge * 0.9), 250);
                    }
                    return { width: size, height: size };
                };
            }
            return config;
        }

        /**
         * Wait for #qr-reader to have valid dimensions before starting scanner.
         * Fixes scanning on different monitors / high-DPI where clientWidth can be 0 (html5-qrcode#362).
         */
        function waitForScannerElementReady() {
            return new Promise(function(resolve) {
                var el = document.getElementById('qr-reader');
                if (!el) {
                    resolve();
                    return;
                }
                var deadline = Date.now() + 1000;
                function check() {
                    var r = el.getBoundingClientRect();
                    var w = r.width || el.offsetWidth || el.clientWidth || 0;
                    var h = r.height || el.offsetHeight || el.clientHeight || 0;
                    if (w > 0 && h > 0) {
                        resolve();
                        return;
                    }
                    if (Date.now() >= deadline) {
                        resolve();
                        return;
                    }
                    requestAnimationFrame(check);
                }
                requestAnimationFrame(function() {
                    requestAnimationFrame(check);
                });
            });
        }
        
        // Offline Storage and Sync Manager
        let offlineStorage = null;
        let syncManager = null;
        
        // Initialize offline storage
        async function initOfflineStorage() {
            try {
                offlineStorage = new OfflineStorage();
                await offlineStorage.init();
                console.log('[QR Scanner] Offline storage initialized');
                
                syncManager = new SyncManager(offlineStorage);
                console.log('[QR Scanner] Sync manager initialized');
                
                // Listen for sync status updates
                window.addEventListener('syncStatusUpdate', (event) => {
                    updateOfflineIndicator(event.detail);
                });
                
                // Initial status update
                const status = await syncManager.getStatus();
                updateOfflineIndicator(status);
            } catch (error) {
                console.error('[QR Scanner] Failed to initialize offline storage:', error);
            }
        }
        
        // Update offline status indicator
        function updateOfflineIndicator(status) {
            const indicator = document.getElementById('offlineIndicator');
            const text = document.getElementById('offlineText');
            
            if (!indicator || !text) return;
            
            indicator.classList.remove('offline', 'syncing', 'pending', 'show');
            
            if (!status.isOnline) {
                indicator.classList.add('offline', 'show');
                text.innerHTML = '<i class="bi bi-wifi-off me-2"></i>Offline Mode';
            } else if (status.isSyncing) {
                indicator.classList.add('syncing', 'show');
                text.innerHTML = '<i class="bi bi-arrow-repeat me-2"></i>Syncing...';
            } else if (status.pendingCount > 0) {
                indicator.classList.add('pending', 'show');
                text.innerHTML = `<i class="bi bi-clock-history me-2"></i>${status.pendingCount} Pending`;
            } else {
                // Online and synced - hide indicator
                indicator.classList.remove('show');
            }
        }

        // Helper function to detect if error is a permission error
        function isPermissionError(error) {
            if (!error) return false;
            
            const errorStr = String(error).toLowerCase();
            const errorName = error?.name || '';
            const errorMessage = error?.message || '';
            
            return errorName === 'NotAllowedError' ||
                   errorName === 'PermissionDeniedError' ||
                   errorMessage.toLowerCase().includes('permission') ||
                   errorMessage.toLowerCase().includes('denied') ||
                   errorMessage.toLowerCase().includes('not allowed') ||
                   errorStr.includes('permission') ||
                   errorStr.includes('denied') ||
                   errorStr.includes('not allowed');
        }

        // Helper function to update camera status
        function updateCameraStatus(type, message) {
            const statusText = document.getElementById('statusText');
            if (!statusText) return;
            
            let icon = '';
            let colorClass = '';
            
            switch(type) {
                case 'loading':
                    icon = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>';
                    colorClass = 'text-info';
                    break;
                case 'success':
                    icon = '<i class="bi bi-check-circle-fill me-2 text-success"></i>';
                    colorClass = 'text-success';
                    break;
                case 'error':
                    icon = '<i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>';
                    colorClass = 'text-danger';
                    break;
                case 'warning':
                    icon = '<i class="bi bi-exclamation-circle-fill me-2 text-warning"></i>';
                    colorClass = 'text-warning';
                    break;
                default:
                    icon = '<i class="bi bi-info-circle-fill me-2 text-info"></i>';
                    colorClass = 'text-muted';
            }
            
            statusText.innerHTML = icon + message;
            statusText.className = 'small ' + colorClass;
        }

        // Wait for Html5Qrcode library to be available
        function waitForLibrary(callback, maxAttempts = 50) {
            if (typeof Html5Qrcode !== 'undefined') {
                libraryLoaded = true;
                callback();
                return;
            }
            
            if (maxAttempts <= 0) {
                console.error('Html5Qrcode library failed to load after timeout');
                updateCameraStatus('error', 'QR Scanner library failed to load. Please refresh the page or check your internet connection.');
                return;
            }
            
            setTimeout(() => waitForLibrary(callback, maxAttempts - 1), 100);
        }

        // Set current date (desktop and mobile drawer)
        const currentDateStr = new Date().toLocaleDateString('en-US', {
            weekday: 'long',
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
        const currentDateEl = document.getElementById('currentDate');
        if (currentDateEl) currentDateEl.textContent = currentDateStr;
        const currentDateDrawerEl = document.getElementById('currentDateDrawer');
        if (currentDateDrawerEl) currentDateDrawerEl.textContent = currentDateStr;

        // Initialize camera and QR scanner
        async function initCamera() {
            console.log('[Camera Init] Starting camera initialization...');
            console.log('[Camera Init] Protocol:', location.protocol);
            console.log('[Camera Init] Hostname:', location.hostname);
            console.log('[Camera Init] User Agent:', navigator.userAgent);
            
            // Wait for library to load before proceeding
            if (!libraryLoaded) {
                console.log('[Camera Init] Library not loaded yet, waiting...');
                waitForLibrary(() => {
                    initCamera();
                });
                return;
            }

            // Verify Html5Qrcode is available
            if (typeof Html5Qrcode === 'undefined') {
                const errorMsg = 'QR Scanner library (Html5Qrcode) is not defined. Please refresh the page.';
                console.error('[Camera Init]', errorMsg);
                updateCameraStatus('error', errorMsg);
                return;
            }
            
            console.log('[Camera Init] Html5Qrcode library loaded successfully');

            // Check for HTTPS requirement on mobile
            // Note: Port forwarding tunnels (ngrok, Cloudflare Tunnel, etc.) typically use HTTPS
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || 
                             (window.innerWidth <= 768) ||
                             ('ontouchstart' in window);
            // HTTPS check: any HTTPS protocol OR localhost/127.0.0.1 (for local development)
            // This works for port forwarding tunnels which use HTTPS
            const isHTTPS = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
            
            // Check if getUserMedia is available (considering both modern and legacy APIs)
            const hasModernAPI = navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
            const hasLegacyAPI = navigator.getUserMedia || navigator.webkitGetUserMedia || navigator.mozGetUserMedia || navigator.msGetUserMedia;
            
            if (!hasModernAPI && !hasLegacyAPI) {
                updateCameraStatus('error', 'Camera API not supported in this browser. Please use a modern browser (Chrome, Firefox, Safari, Edge).');
                const startBtn = document.getElementById('btn-start-camera');
                if (startBtn) {
                    startBtn.disabled = false;
                    startBtn.innerHTML = '<i class="bi bi-camera-fill me-2"></i>Start Camera';
                }
                return;
            }

            // Warn if we're on HTTP (not HTTPS) but still attempt camera access
            if (!isHTTPS) {
                let warningMsg = '<strong>⚠️ HTTP Connection - Camera May Be Blocked</strong><br><br>';
                
                if (isMobile) {
                    warningMsg += '<strong>⚠️ Mobile browsers require HTTPS for camera access.</strong><br><br>';
                    warningMsg += 'Mobile browsers (Chrome, Safari, Firefox) <strong>cannot</strong> access the camera on HTTP sites.<br>';
                    warningMsg += 'There is <strong>no workaround</strong> for mobile browsers - the site must use HTTPS.<br><br>';
                    warningMsg += '<strong>Solutions:</strong><br>';
                    warningMsg += '1. <strong>Use HTTPS:</strong> Contact your administrator to enable SSL/HTTPS on the server<br>';
                    warningMsg += '2. <strong>Use desktop browser:</strong> Desktop Chrome/Edge can use chrome://flags workaround<br>';
                    warningMsg += '3. <strong>Use a different device:</strong> Try accessing from a desktop computer<br><br>';
                } else {
                    warningMsg += '<strong>Desktop browsers can work with HTTP using these workarounds:</strong><br><br>';
                    warningMsg += '<strong>Chrome/Edge (Desktop only):</strong><br>';
                    warningMsg += '1. Open: <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code><br>';
                    warningMsg += '2. Add: <code>http://' + location.hostname + '</code><br>';
                    warningMsg += '3. Set to "Enabled" and restart browser<br><br>';
                    
                    warningMsg += '<strong>Firefox (Desktop only):</strong><br>';
                    warningMsg += '1. Type: <code>about:config</code><br>';
                    warningMsg += '2. Search: <code>media.devices.insecure.enabled</code><br>';
                    warningMsg += '3. Set to <strong>true</strong><br>';
                    warningMsg += '4. Search: <code>media.getusermedia.insecure.enabled</code><br>';
                    warningMsg += '5. Set to <strong>true</strong><br><br>';
                    warningMsg += '<strong>Note:</strong> These workarounds only work on desktop browsers, not mobile.<br>';
                }
                
                warningMsg += '<br><em>Attempting to access camera anyway...</em>';
                updateCameraStatus('warning', warningMsg);
                console.warn('HTTP connection detected - camera access may be blocked by browser');
                // Continue with camera initialization instead of returning
            }
            
            // If we have legacy API but not modern API, wrap the legacy API
            if (!hasModernAPI && hasLegacyAPI) {
                console.log('Using legacy getUserMedia API');
                navigator.mediaDevices = navigator.mediaDevices || {};
                navigator.mediaDevices.getUserMedia = function(constraints) {
                    const legacyGetUserMedia = navigator.getUserMedia || 
                                              navigator.webkitGetUserMedia || 
                                              navigator.mozGetUserMedia || 
                                              navigator.msGetUserMedia;
                    
                    if (!legacyGetUserMedia) {
                        return Promise.reject(new Error('getUserMedia is not implemented'));
                    }
                    
                    return new Promise(function(resolve, reject) {
                        legacyGetUserMedia.call(navigator, constraints, resolve, reject);
                    });
                };
            }

            // Hide placeholder and show camera overlay
            const placeholder = document.getElementById('cameraPlaceholder');
            const overlay = document.getElementById('scanOverlay');
            if (placeholder) placeholder.style.display = 'none';
            if (overlay) overlay.style.display = 'block';

            // Wait for layout so html5-qrcode gets valid dimensions (fixes multi-monitor/high-DPI)
            await waitForScannerElementReady();

            // Check permission status using Permissions API (if available) - but don't request yet
            // This is just for informational purposes
            let permissionStatus = 'prompt'; // default
            try {
                if (navigator.permissions && navigator.permissions.query) {
                    const result = await navigator.permissions.query({ name: 'camera' });
                    permissionStatus = result.state;
                    console.log('Camera permission status:', permissionStatus);
                }
            } catch (permQueryError) {
                // Permissions API not supported or failed, continue anyway
                console.log('Permissions API not available, proceeding with camera request');
            }

            // Initialize QR Code Scanner
            // Let Html5Qrcode handle the permission request directly
            updateCameraStatus('loading', 'Initializing camera...');
            console.log('[Camera Init] Creating Html5Qrcode instance...');
            
            try {
                html5QrcodeScanner = new Html5Qrcode("qr-reader");
                console.log('[Camera Init] Html5Qrcode instance created successfully');
            } catch (instanceError) {
                console.error('[Camera Init] Failed to create Html5Qrcode instance:', instanceError);
                updateCameraStatus('error', 'Failed to initialize QR scanner: ' + instanceError.message);
                return;
            }

            // Try to start camera with facingMode first (works best on mobile)
            try {
                console.log('[Camera Init] Attempting to start camera with facingMode: environment');
                await html5QrcodeScanner.start({
                    facingMode: "environment"
                }, getScannerConfig(),
                (decodedText, decodedResult) => {
                    onScanSuccess(decodedText, decodedResult);
                },
                function() {});
                
                updateCameraStatus('success', 'Ready — point camera at a QR code');
                cameraInitialized = true;
                currentFacingMode = 'environment';
                // Store camera config for recovery
                cameraConfig = { facingMode: "environment" };
                // Start health monitoring
                startScannerHealthCheck();
                // Show switch button
                const switchBtn = document.getElementById('btn-switch-front-camera');
                if (switchBtn) {
                    switchBtn.style.display = 'inline-block';
                }
                return; // Success!
            } catch (envError) {
                console.warn('Environment camera failed:', envError);
                
                // Check if it's a permission error - if so, don't try other cameras
                if (isPermissionError(envError)) {
                    console.error('Permission error detected, stopping camera initialization');
                    const isHTTPS = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
                    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || 
                                     (window.innerWidth <= 768) ||
                                     ('ontouchstart' in window);
                    let errorMsg = '<strong>Camera Access Denied</strong><br><br>';
                    
                    if (!isHTTPS) {
                        if (isMobile) {
                            errorMsg += '⚠️ <strong>Mobile browsers require HTTPS for camera access.</strong><br><br>';
                            errorMsg += 'Mobile browsers (Chrome, Safari, Firefox) <strong>cannot</strong> access the camera on HTTP sites.<br>';
                            errorMsg += 'There is <strong>no workaround</strong> for mobile browsers - the site must use HTTPS.<br><br>';
                            errorMsg += '<strong>Solutions:</strong><br>';
                            errorMsg += '1. <strong>Use HTTPS:</strong> Contact your administrator to enable SSL/HTTPS on the server<br>';
                            errorMsg += '2. <strong>Use desktop browser:</strong> Desktop Chrome/Edge can use chrome://flags workaround<br>';
                            errorMsg += '3. <strong>Use a different device:</strong> Try accessing from a desktop computer<br>';
                        } else {
                            errorMsg += '⚠️ <strong>You are on HTTP without SSL.</strong> Browsers block camera access on HTTP sites.<br><br>';
                            errorMsg += '<strong>Enable camera on Chrome/Edge (Desktop only):</strong><br>';
                            errorMsg += '1. Open: <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code><br>';
                            errorMsg += '2. Add: <code>http://' + location.hostname + '</code><br>';
                            errorMsg += '3. Set to "Enabled" and restart browser<br><br>';
                            errorMsg += '<strong>Enable camera on Firefox (Desktop only):</strong><br>';
                            errorMsg += '1. Type: <code>about:config</code><br>';
                            errorMsg += '2. Set <code>media.devices.insecure.enabled</code> = true<br>';
                            errorMsg += '3. Set <code>media.getusermedia.insecure.enabled</code> = true<br>';
                            errorMsg += '<br><strong>Note:</strong> These workarounds only work on desktop browsers, not mobile.<br>';
                        }
                    } else {
                        if (isMobile) {
                            errorMsg += 'On mobile: Go to browser settings > Site settings > Camera, and ensure it\'s set to "Allow". Then refresh the page and try again.';
                        } else {
                            errorMsg += 'Click the lock/camera icon in the address bar and ensure camera access is allowed. Then refresh the page and try again.';
                        }
                    }
                    
                    updateCameraStatus('error', errorMsg);
                    const startBtn = document.getElementById('btn-start-camera');
                    if (startBtn) {
                        startBtn.disabled = false;
                        startBtn.innerHTML = '<i class="bi bi-camera-fill me-2"></i>Try Again';
                    }
                    return;
                }
                
                // Not a permission error, try user camera as fallback
                try {
                    console.log('Attempting to start camera with facingMode: user');
                    await html5QrcodeScanner.start({
                        facingMode: "user"
                    }, getScannerConfig(),
                    (decodedText, decodedResult) => {
                        onScanSuccess(decodedText, decodedResult);
                    },
                    function() {});
                    
                    updateCameraStatus('success', 'Ready — point camera at a QR code');
                    cameraInitialized = true;
                    currentFacingMode = 'user';
                    // Store camera config for recovery
                    cameraConfig = { facingMode: "user" };
                    // Start health monitoring
                    startScannerHealthCheck();
                    // Show switch button (update text since we're already on front)
                    const switchBtn = document.getElementById('btn-switch-front-camera');
                    if (switchBtn) {
                        switchBtn.style.display = 'inline-block';
                        switchBtn.innerHTML = '<i class="bi bi-camera-reverse me-1"></i>Switch to Back Camera';
                    }
                    return; // Success!
                } catch (userError) {
                    console.warn('User camera also failed:', userError);
                    
                    // Check if it's a permission error
                    if (isPermissionError(userError)) {
                        console.error('Permission error detected on user camera, stopping camera initialization');
                        const isHTTPS = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
                        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || 
                                         (window.innerWidth <= 768) ||
                                         ('ontouchstart' in window);
                        let errorMsg = '<strong>Camera Access Denied</strong><br><br>';
                        
                        if (!isHTTPS) {
                            if (isMobile) {
                                errorMsg += '⚠️ <strong>Mobile browsers require HTTPS for camera access.</strong><br><br>';
                                errorMsg += 'Mobile browsers (Chrome, Safari, Firefox) <strong>cannot</strong> access the camera on HTTP sites.<br>';
                                errorMsg += 'There is <strong>no workaround</strong> for mobile browsers - the site must use HTTPS.<br><br>';
                                errorMsg += '<strong>Solutions:</strong><br>';
                                errorMsg += '1. <strong>Use HTTPS:</strong> Contact your administrator to enable SSL/HTTPS on the server<br>';
                                errorMsg += '2. <strong>Use desktop browser:</strong> Desktop Chrome/Edge can use chrome://flags workaround<br>';
                                errorMsg += '3. <strong>Use a different device:</strong> Try accessing from a desktop computer<br>';
                            } else {
                                errorMsg += '⚠️ <strong>You are on HTTP without SSL.</strong> Browsers block camera access on HTTP sites.<br><br>';
                                errorMsg += '<strong>Enable camera on Chrome/Edge (Desktop only):</strong><br>';
                                errorMsg += '1. Open: <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code><br>';
                                errorMsg += '2. Add: <code>http://' + location.hostname + '</code><br>';
                                errorMsg += '3. Set to "Enabled" and restart browser<br><br>';
                                errorMsg += '<strong>Enable camera on Firefox (Desktop only):</strong><br>';
                                errorMsg += '1. Type: <code>about:config</code><br>';
                                errorMsg += '2. Set <code>media.devices.insecure.enabled</code> = true<br>';
                                errorMsg += '3. Set <code>media.getusermedia.insecure.enabled</code> = true<br>';
                                errorMsg += '<br><strong>Note:</strong> These workarounds only work on desktop browsers, not mobile.<br>';
                            }
                        } else {
                            if (isMobile) {
                                errorMsg += 'On mobile: Go to browser settings > Site settings > Camera, and ensure it\'s set to "Allow". Then refresh the page and try again.';
                            } else {
                                errorMsg += 'Click the lock/camera icon in the address bar and ensure camera access is allowed. Then refresh the page and try again.';
                            }
                        }
                        
                        updateCameraStatus('error', errorMsg);
                        const startBtn = document.getElementById('btn-start-camera');
                        if (startBtn) {
                            startBtn.disabled = false;
                            startBtn.innerHTML = '<i class="bi bi-camera-fill me-2"></i>Try Again';
                        }
                        return;
                    }
                    
                    // Not a permission error, fall through to device enumeration below
                }
            }

            // If facingMode failed, try device enumeration (for desktop with multiple cameras)
            try {

                // Try to get available cameras (for desktop or if mobile facingMode failed)
                let devices = [];
                let cameraConfig = null;

                try {
                    // Get cameras using MediaDevices API for better device information
                    let mediaDevices = await navigator.mediaDevices.enumerateDevices();
                    let videoDevices = mediaDevices.filter(device => device.kind === 'videoinput');
                    
                    // If we don't have proper labels (permission not granted), request permission
                    const hasLabels = videoDevices.some(device => device.label && device.label.length > 0);
                    if (!hasLabels && videoDevices.length > 0) {
                        try {
                            // Request permission to get device labels
                            const stream = await navigator.mediaDevices.getUserMedia({ video: true });
                            // Stop the stream immediately - we just needed permission
                            stream.getTracks().forEach(track => track.stop());
                            // Re-enumerate to get labels
                            mediaDevices = await navigator.mediaDevices.enumerateDevices();
                            videoDevices = mediaDevices.filter(device => device.kind === 'videoinput');
                        } catch (permError) {
                            console.warn('Permission request failed, continuing with limited info:', permError);
                        }
                    }
                    
                    console.log('All video devices:', videoDevices);

                    if (videoDevices && videoDevices.length > 0) {
                        // Function to check if a camera is likely external
                        function isExternalCamera(device, index, allDevices) {
                            const label = (device.label || '').toLowerCase();
                            
                            // Positive indicators for external cameras
                            const externalIndicators = [
                                'usb', 'webcam', 'external', 'hd pro', 'logitech', 'microsoft',
                                'c920', 'c930', 'brio', 'c922', 'c270', 'c310', 'c615',
                                'creative', 'razer', 'corsair', 'hp', 'dell', 'lenovo',
                                'acer', 'asus', 'sony', 'canon', 'nikon', 'panasonic'
                            ];
                            
                            // Negative indicators for built-in cameras
                            const builtInIndicators = [
                                'integrated', 'built-in', 'builtin', 'internal',
                                'facetime', 'isight', 'macbook', 'laptop',
                                'front facing', 'front-facing', 'facing front'
                            ];
                            
                            // Check for external indicators
                            const hasExternalIndicator = externalIndicators.some(indicator => 
                                label.includes(indicator)
                            );
                            
                            // Check for built-in indicators
                            const hasBuiltInIndicator = builtInIndicators.some(indicator => 
                                label.includes(indicator)
                            );
                            
                            // If it has external indicators, it's external
                            if (hasExternalIndicator) {
                                return true;
                            }
                            
                            // If it has built-in indicators, it's NOT external
                            if (hasBuiltInIndicator) {
                                return false;
                            }
                            
                            // If label contains "camera" but not built-in indicators, likely external
                            if (label.includes('camera') && !hasBuiltInIndicator && label.length > 0) {
                                return true;
                            }
                            
                            // Heuristic: If multiple cameras exist and this is not the first one,
                            // it's more likely to be external (built-in is often first)
                            if (allDevices.length > 1 && index > 0 && label.length === 0) {
                                return true; // Unknown device that's not first = likely external
                            }
                            
                            return false;
                        }
                        
                        // Separate external and built-in cameras
                        const externalCameras = videoDevices.filter((device, index) => 
                            isExternalCamera(device, index, videoDevices)
                        );
                        const builtInCameras = videoDevices.filter((device, index) => 
                            !isExternalCamera(device, index, videoDevices)
                        );
                        
                        console.log('External cameras found:', externalCameras.length, externalCameras.map(d => d.label || d.deviceId));
                        console.log('Built-in cameras found:', builtInCameras.length, builtInCameras.map(d => d.label || d.deviceId));
                        
                        // Prioritize external cameras - use the first one found
                        if (externalCameras.length > 0) {
                            const selectedExternal = externalCameras[0];
                            cameraConfig = {
                                deviceId: selectedExternal.deviceId
                            };
                            console.log('✓ Using EXTERNAL camera:', selectedExternal.label || selectedExternal.deviceId);
                        } else if (videoDevices.length > 1) {
                            // If multiple cameras exist but none detected as external,
                            // prioritize non-first cameras (often external USB cameras are listed after built-in)
                            // Try cameras in reverse order (last added is often external)
                            for (let i = videoDevices.length - 1; i >= 1; i--) {
                                const candidate = videoDevices[i];
                                if (candidate && candidate.deviceId) {
                                    cameraConfig = {
                                        deviceId: candidate.deviceId
                                    };
                                    console.log('✓ Using non-default camera (likely external, index ' + i + '):', candidate.label || candidate.deviceId);
                                    break;
                                }
                            }
                            
                            // If still no config, use second camera
                            if (!cameraConfig && videoDevices[1] && videoDevices[1].deviceId) {
                                cameraConfig = {
                                    deviceId: videoDevices[1].deviceId
                                };
                                console.log('✓ Using second camera (likely external):', videoDevices[1].label || videoDevices[1].deviceId);
                            }
                        }
                        
                        // Final fallback to device camera (built-in camera) only if no external found
                        if (!cameraConfig) {
                            // Try to find back/rear camera first (preferred for QR scanning)
                            let backCamera = videoDevices.find(device => {
                                const label = (device.label || '').toLowerCase();
                                return label.includes('back') ||
                                    label.includes('rear') ||
                                    label.includes('environment') ||
                                    label.includes('facing back');
                            });

                            if (backCamera && backCamera.deviceId) {
                                cameraConfig = {
                                    deviceId: backCamera.deviceId
                                };
                                console.log('Using device back camera:', backCamera.label || backCamera.deviceId);
                            } else {
                                // Use first available camera (device camera)
                                const firstCamera = videoDevices[0];
                                if (firstCamera && firstCamera.deviceId) {
                                    cameraConfig = {
                                        deviceId: firstCamera.deviceId
                                    };
                                    console.log('Using device camera (no external found):', firstCamera.label || firstCamera.deviceId);
                                }
                            }
                        }
                    }
                } catch (deviceError) {
                    console.warn('Could not enumerate cameras, using default:', deviceError);
                    // Fallback: try using Html5Qrcode.getCameras() if MediaDevices fails
                    try {
                        const devices = await Html5Qrcode.getCameras();
                        if (devices && devices.length > 0) {
                            // If multiple cameras, prefer non-first one (likely external)
                            const selectedDevice = devices.length > 1 ? devices[devices.length - 1] : devices[0];
                            cameraConfig = {
                                deviceId: selectedDevice.id
                            };
                            console.log('Using camera (fallback method):', selectedDevice.label || selectedDevice.id);
                        }
                    } catch (fallbackError) {
                        console.warn('Fallback camera enumeration also failed:', fallbackError);
                    }
                }

                // Fallback to facingMode if deviceId is not available
                if (!cameraConfig) {
                    cameraConfig = {
                        facingMode: "environment"
                    };
                    console.log('Using facingMode: environment (fallback)');
                }

                // Start scanning with camera
                await html5QrcodeScanner.start(
                    cameraConfig, getScannerConfig(),
                    (decodedText, decodedResult) => {
                        onScanSuccess(decodedText, decodedResult);
                    },
                    function() {}
                );

                updateCameraStatus('success', 'Ready — point camera at a QR code');
                cameraInitialized = true;
                // Store camera config for recovery
                cameraConfig = cameraConfig || { facingMode: "environment" };
                // Start health monitoring
                startScannerHealthCheck();
            } catch (error) {
                console.error('Error accessing camera:', error);

                // Try fallback with device camera (environment/back facing)
                try {
                    console.log('Trying fallback with device camera (facingMode: environment)');
                    await html5QrcodeScanner.start({
                            facingMode: "environment"
                        }, getScannerConfig(),
                        (decodedText, decodedResult) => {
                            onScanSuccess(decodedText, decodedResult);
                        },
                        function() {}
                    );
                    updateCameraStatus('success', 'Ready — point camera at a QR code');
                    cameraInitialized = true;
                    // Store camera config for recovery
                    cameraConfig = { facingMode: "environment" };
                    // Start health monitoring
                    startScannerHealthCheck();
                    return;
                } catch (fallbackError) {
                    console.error('Device camera fallback failed, trying user-facing camera:', fallbackError);
                    
                    // Last resort: try user-facing camera
                    try {
                        console.log('Trying fallback with facingMode: user');
                        await html5QrcodeScanner.start({
                                facingMode: "user"
                            }, getScannerConfig(),
                            (decodedText, decodedResult) => {
                                onScanSuccess(decodedText, decodedResult);
                            },
                            function() {}
                        );
                        updateCameraStatus('success', 'Ready — point camera at a QR code');
                        cameraInitialized = true;
                        // Store camera config for recovery
                        cameraConfig = { facingMode: "user" };
                        // Start health monitoring
                        startScannerHealthCheck();
                        return;
                    } catch (finalFallbackError) {
                        console.error('All camera fallbacks failed:', finalFallbackError);
                        // Use the final error for error message
                        error = finalFallbackError;
                    }
                }

                // Provide detailed error messages based on error type
                let errorMsg = '';
                let errorName = error?.name || '';
                let errorMessage = error?.message || '';
                const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || 
                                 (window.innerWidth <= 768) ||
                                 ('ontouchstart' in window);
                // HTTPS check: any HTTPS protocol OR localhost/127.0.0.1 (for local development)
                // This works for port forwarding tunnels which use HTTPS
                const isHTTPS = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
                
                // Check if it's a permission error first
                if (isPermissionError(error)) {
                    errorMsg = '<strong>Camera Access Denied</strong><br><br>';
                    if (!isHTTPS) {
                        if (isMobile) {
                            errorMsg += '⚠️ <strong>Mobile browsers require HTTPS for camera access.</strong><br><br>';
                            errorMsg += 'Mobile browsers (Chrome, Safari, Firefox) <strong>cannot</strong> access the camera on HTTP sites.<br>';
                            errorMsg += 'There is <strong>no workaround</strong> for mobile browsers - the site must use HTTPS.<br><br>';
                            errorMsg += '<strong>Solutions:</strong><br>';
                            errorMsg += '1. <strong>Use HTTPS:</strong> Contact your administrator to enable SSL/HTTPS on the server<br>';
                            errorMsg += '2. <strong>Use desktop browser:</strong> Desktop Chrome/Edge can use chrome://flags workaround<br>';
                            errorMsg += '3. <strong>Use a different device:</strong> Try accessing from a desktop computer<br>';
                        } else {
                            errorMsg += '⚠️ <strong>You are on HTTP without SSL.</strong> Browsers block camera access on HTTP sites.<br><br>';
                            errorMsg += '<strong>Enable camera on Chrome/Edge (Desktop only):</strong><br>';
                            errorMsg += '1. Open: <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code><br>';
                            errorMsg += '2. Add: <code>http://' + location.hostname + '</code><br>';
                            errorMsg += '3. Set to "Enabled" and restart browser<br><br>';
                            errorMsg += '<strong>Enable camera on Firefox (Desktop only):</strong><br>';
                            errorMsg += '1. Type: <code>about:config</code><br>';
                            errorMsg += '2. Set <code>media.devices.insecure.enabled</code> = true<br>';
                            errorMsg += '3. Set <code>media.getusermedia.insecure.enabled</code> = true<br>';
                            errorMsg += '<br><strong>Note:</strong> These workarounds only work on desktop browsers, not mobile.<br>';
                        }
                    } else if (isMobile) {
                        errorMsg += 'On mobile: Go to browser settings > Site settings > Camera, and ensure it\'s set to "Allow". Then refresh the page and try again.';
                    } else {
                        errorMsg += 'Click the lock/camera icon in the address bar and ensure camera access is allowed. Then refresh the page and try again.';
                    }
                } else if (errorName === 'NotFoundError' || errorMessage.includes('no camera') || errorMessage.includes('not found')) {
                    errorMsg = 'No camera found. Please connect a camera and try again.';
                } else if (errorName === 'NotReadableError' || errorMessage.includes('not readable') || errorMessage.includes('already in use')) {
                    errorMsg = 'Camera is already in use by another application. Please close other applications using the camera and try again.';
                } else if (errorName === 'OverconstrainedError' || errorMessage.includes('constraint')) {
                    errorMsg = 'Camera does not meet requirements. Please try again or use a different camera.';
                } else {
                    errorMsg = '<strong>Camera Access Error</strong><br><br>';
                    if (!isHTTPS) {
                        if (isMobile) {
                            errorMsg += '⚠️ <strong>Mobile browsers require HTTPS for camera access.</strong><br><br>';
                            errorMsg += 'Mobile browsers (Chrome, Safari, Firefox) <strong>cannot</strong> access the camera on HTTP sites.<br>';
                            errorMsg += 'There is <strong>no workaround</strong> for mobile browsers - the site must use HTTPS.<br><br>';
                            errorMsg += '<strong>Solutions:</strong><br>';
                            errorMsg += '1. <strong>Use HTTPS:</strong> Contact your administrator to enable SSL/HTTPS on the server<br>';
                            errorMsg += '2. <strong>Use desktop browser:</strong> Desktop Chrome/Edge can use chrome://flags workaround<br>';
                            errorMsg += '3. <strong>Use a different device:</strong> Try accessing from a desktop computer<br>';
                        } else {
                            errorMsg += '⚠️ <strong>You are on HTTP without SSL.</strong> Browsers block camera access on HTTP sites.<br><br>';
                            errorMsg += '<strong>Enable camera on Chrome/Edge (Desktop only):</strong><br>';
                            errorMsg += '1. Open: <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code><br>';
                            errorMsg += '2. Add: <code>http://' + location.hostname + '</code><br>';
                            errorMsg += '3. Set to "Enabled" and restart browser<br><br>';
                            errorMsg += '<strong>Enable camera on Firefox (Desktop only):</strong><br>';
                            errorMsg += '1. Type: <code>about:config</code><br>';
                            errorMsg += '2. Set <code>media.devices.insecure.enabled</code> = true<br>';
                            errorMsg += '3. Set <code>media.getusermedia.insecure.enabled</code> = true<br>';
                            errorMsg += '<br><strong>Note:</strong> These workarounds only work on desktop browsers, not mobile.<br>';
                        }
                    } else {
                        errorMsg += 'Please check your camera permissions and try again.';
                        if (errorMessage) {
                            errorMsg += '<br><br><small>Error: ' + errorMessage + '</small>';
                        }
                    }
                }
                
                updateCameraStatus('error', errorMsg);
                console.error('Camera initialization error details:', {
                    name: errorName,
                    message: errorMessage,
                    error: error,
                    isMobile: isMobile,
                    isHTTPS: isHTTPS,
                    protocol: location.protocol,
                    hostname: location.hostname
                });
                
                // Re-enable the start camera button so user can try again
                const startBtn = document.getElementById('btn-start-camera');
                if (startBtn) {
                    startBtn.disabled = false;
                    startBtn.innerHTML = '<i class="bi bi-camera-fill me-2"></i>Try Again';
                }
            }
        }

        // Switch to front camera
        async function switchToFrontCamera() {
            if (!html5QrcodeScanner || !cameraInitialized) {
                console.warn('Camera not initialized, cannot switch');
                return;
            }

            const switchBtn = document.getElementById('btn-switch-front-camera');
            if (switchBtn) {
                switchBtn.disabled = true;
                switchBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Switching...';
            }

            try {
                // Stop current camera
                await html5QrcodeScanner.stop();
                console.log('Camera stopped, switching to front camera...');
                
                // Update status
                updateCameraStatus('loading', 'Switching to front camera...');
                
                // Start with front camera (user facing)
                await html5QrcodeScanner.start({
                    facingMode: "user"
                }, getScannerConfig(),
                (decodedText, decodedResult) => {
                    onScanSuccess(decodedText, decodedResult);
                },
                function() {});

                currentFacingMode = 'user';
                // Store camera config for recovery
                cameraConfig = { facingMode: "user" };
                updateCameraStatus('success', 'Front camera ready — point at a QR code');
                
                // Update button text
                if (switchBtn) {
                    switchBtn.innerHTML = '<i class="bi bi-camera-reverse me-1"></i>Switch to Back Camera';
                    switchBtn.disabled = false;
                }
            } catch (error) {
                console.error('Failed to switch to front camera:', error);
                updateCameraStatus('error', 'Failed to switch to front camera: ' + error.message);
                
                if (switchBtn) {
                    switchBtn.innerHTML = '<i class="bi bi-camera-reverse me-1"></i>Switch to Front Camera';
                    switchBtn.disabled = false;
                }
            }
        }

        // Switch to back camera
        async function switchToBackCamera() {
            if (!html5QrcodeScanner || !cameraInitialized) {
                console.warn('Camera not initialized, cannot switch');
                return;
            }

            const switchBtn = document.getElementById('btn-switch-front-camera');
            if (switchBtn) {
                switchBtn.disabled = true;
                switchBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Switching...';
            }

            try {
                // Stop current camera
                await html5QrcodeScanner.stop();
                console.log('Camera stopped, switching to back camera...');
                
                // Update status
                updateCameraStatus('loading', 'Switching to back camera...');
                
                // Start with back camera (environment facing)
                await html5QrcodeScanner.start({
                    facingMode: "environment"
                }, getScannerConfig(),
                (decodedText, decodedResult) => {
                    onScanSuccess(decodedText, decodedResult);
                },
                function() {});

                currentFacingMode = 'environment';
                // Store camera config for recovery
                cameraConfig = { facingMode: "environment" };
                updateCameraStatus('success', 'Back camera ready — point at a QR code');
                
                // Update button text
                if (switchBtn) {
                    switchBtn.innerHTML = '<i class="bi bi-camera-reverse me-1"></i>Switch to Front Camera';
                    switchBtn.disabled = false;
                }
            } catch (error) {
                console.error('Failed to switch to back camera:', error);
                updateCameraStatus('error', 'Failed to switch to back camera: ' + error.message);
                
                if (switchBtn) {
                    switchBtn.innerHTML = currentFacingMode === 'user' 
                        ? '<i class="bi bi-camera-reverse me-1"></i>Switch to Back Camera'
                        : '<i class="bi bi-camera-reverse me-1"></i>Switch to Front Camera';
                    switchBtn.disabled = false;
                }
            }
        }

        // Fallback camera initialization with minimal constraints
        async function initCameraFallback() {
            if (!libraryLoaded || typeof Html5Qrcode === 'undefined') {
                waitForLibrary(() => {
                    initCameraFallback();
                });
                return;
            }

            try {
                if (!html5QrcodeScanner) {
                    html5QrcodeScanner = new Html5Qrcode("qr-reader");
                }

                // Try with minimal constraints - just facingMode
                await html5QrcodeScanner.start({
                    facingMode: "environment"
                }, getScannerConfig(),
                (decodedText, decodedResult) => {
                    onScanSuccess(decodedText, decodedResult);
                },
                function() {});

                updateCameraStatus('success', 'Ready — point camera at a QR code');
                cameraInitialized = true;
                // Store camera config for recovery
                cameraConfig = { facingMode: "environment" };
                // Start health monitoring
                startScannerHealthCheck();
            } catch (fallbackError) {
                console.error('Fallback camera initialization failed:', fallbackError);
                updateCameraStatus('error', 'Unable to access camera. Please check permissions and refresh the page.');
            }
        }

        function onScanSuccess(decodedText, decodedResult) {
            // QR code scanned successfully
            // Prevent rapid re-scanning (cooldown)
            if (scanCooldown) {
                return;
            }

            // Set cooldown to prevent rapid re-scanning
            scanCooldown = true;
            lastScannedQR = decodedText;
            lastScanTime = Date.now(); // Record successful scan time for health monitoring

            console.log('QR Code scanned:', decodedText);

            // Show scanning status
            updateCameraStatus('loading', 'Processing QR code...');

            // Automatically get user info from QR code
            // This will work for both same QR code (refresh) and new QR code (replace)
            getUserInfoFromQR(decodedText).finally(() => {
                // Shorter cooldown when offline so next QR can be scanned sooner
                const cooldownMs = navigator.onLine ? 2000 : 700;
                setTimeout(() => {
                    scanCooldown = false;
                    lastScannedQR = null; // Reset to allow same QR code to be scanned again
                    updateCameraStatus('success', 'Ready — point camera at a QR code');
                }, cooldownMs);
            });
        }

        function onScanError(errorMessage) {
            // Ignore scan errors (they happen frequently when no QR code is in view)
            // Only log if it's not a common "not found" error
            if (!errorMessage.includes('No QR code found')) {
                // console.log('Scan error:', errorMessage);
            }
        }

        /**
         * Check if scanner is still working and restart if needed
         */
        async function checkScannerHealth() {
            // Skip health check if page is not visible or scanner is paused
            if (!isPageVisible || scannerPaused || !cameraInitialized) {
                return;
            }

            // Check if scanner instance exists
            if (!html5QrcodeScanner) {
                console.log('[Scanner Health] Scanner instance missing, attempting recovery...');
                if (cameraConfig) {
                    await recoverScanner();
                }
                return;
            }

            // Check if scanner has been inactive for too long (more than 10 minutes)
            // This indicates the scanner might have stopped working
            // Note: We use a longer threshold to avoid unnecessary recovery attempts
            const now = Date.now();
            const inactiveThreshold = 10 * 60 * 1000; // 10 minutes
            
            // Only check inactivity if we've had at least one successful scan
            // This prevents recovery attempts right after initialization
            if (lastScanTime && (now - lastScanTime) > inactiveThreshold) {
                // Scanner might be stuck - try to verify it's still running
                // We can't directly check if scanner is running, so we'll try to restart it
                console.log('[Scanner Health] Scanner appears inactive for ' + Math.round((now - lastScanTime) / 60000) + ' minutes, attempting recovery...');
                await recoverScanner();
            } else if (!lastScanTime) {
                // No scans yet, but scanner should still be working
                // Reset lastScanTime to current time to prevent false positives
                // This allows the scanner to work even if no QR codes are being scanned
                lastScanTime = now;
            }
        }

        /**
         * Recover scanner by stopping and restarting it
         */
        async function recoverScanner() {
            if (!cameraInitialized || !cameraConfig) {
                return;
            }

            // Prevent too frequent recovery attempts
            const now = Date.now();
            if (now - lastRecoveryAttempt < RECOVERY_COOLDOWN) {
                console.log('[Scanner Recovery] Recovery attempt skipped (cooldown)');
                return;
            }
            lastRecoveryAttempt = now;

            try {
                console.log('[Scanner Recovery] Attempting to recover scanner...');
                updateCameraStatus('loading', 'Recovering scanner...');

                // Stop current scanner if it exists
                if (html5QrcodeScanner) {
                    try {
                        await html5QrcodeScanner.stop();
                    } catch (stopError) {
                        console.warn('[Scanner Recovery] Error stopping scanner:', stopError);
                    }
                }

                // Wait a bit before restarting
                await new Promise(resolve => setTimeout(resolve, 500));

                // Ensure element has valid dimensions (multi-monitor/high-DPI)
                await waitForScannerElementReady();

                // Restart scanner with saved config
                html5QrcodeScanner = new Html5Qrcode("qr-reader");
                
                await html5QrcodeScanner.start(
                    cameraConfig,
                    getScannerConfig(),
                    (decodedText, decodedResult) => {
                        onScanSuccess(decodedText, decodedResult);
                    },
                    function() {}
                );

                updateCameraStatus('success', 'Ready — point camera at a QR code');
                console.log('[Scanner Recovery] Scanner recovered successfully');
            } catch (recoveryError) {
                console.error('[Scanner Recovery] Failed to recover scanner:', recoveryError);
                updateCameraStatus('error', 'Scanner recovery failed. Please refresh the page.');
            }
        }

        /**
         * Pause scanner when page becomes hidden
         */
        async function pauseScanner() {
            if (!cameraInitialized || scannerPaused) {
                return;
            }

            scannerPaused = true;
            console.log('[Scanner] Pausing scanner (page hidden)');

            // Note: We don't stop the scanner completely as it's resource-intensive to restart
            // The browser will handle pausing the camera stream automatically
        }

        /**
         * Resume scanner when page becomes visible
         */
        async function resumeScanner() {
            if (!cameraInitialized || !scannerPaused) {
                return;
            }

            scannerPaused = false;
            console.log('[Scanner] Resuming scanner (page visible)');

            // Check if scanner is still working, recover if needed
            await checkScannerHealth();
        }

        /**
         * Start scanner health monitoring
         */
        function startScannerHealthCheck() {
            // Clear existing interval if any
            if (scannerHealthCheckInterval) {
                clearInterval(scannerHealthCheckInterval);
            }

            // Check scanner health every 2 minutes
            scannerHealthCheckInterval = setInterval(() => {
                checkScannerHealth();
            }, 2 * 60 * 1000); // 2 minutes

            console.log('[Scanner Health] Health monitoring started');
        }

        /**
         * Stop scanner health monitoring
         */
        function stopScannerHealthCheck() {
            if (scannerHealthCheckInterval) {
                clearInterval(scannerHealthCheckInterval);
                scannerHealthCheckInterval = null;
            }
        }

        /**
         * Helper function to try getting user from cache
         * Tries multiple methods: employee_id, user_id, and parsed JSON
         */
        async function tryGetUserFromCache(qrData) {
            if (!offlineStorage) {
                return null;
            }

            try {
                // QR codes typically contain employee_id as plain text
                // Try direct lookup first (most common case)
                let cachedUser = await offlineStorage.getUserFromCache(qrData);
                if (cachedUser) {
                    return cachedUser;
                }

                // Try parsing as JSON (in case QR contains JSON)
                try {
                    const qrJson = JSON.parse(qrData);
                    if (qrJson.employee_id) {
                        cachedUser = await offlineStorage.getUserFromCache(qrJson.employee_id);
                        if (cachedUser) {
                            return cachedUser;
                        }
                    }
                    if (qrJson.user_id) {
                        cachedUser = await offlineStorage.getUserFromCacheByUserId(qrJson.user_id);
                        if (cachedUser) {
                            return cachedUser;
                        }
                    }
                } catch (e) {
                    // Not JSON, continue
                }

                // Try as numeric user_id
                if (/^\d+$/.test(qrData.trim())) {
                    const userId = parseInt(qrData.trim());
                    cachedUser = await offlineStorage.getUserFromCacheByUserId(userId);
                    if (cachedUser) {
                        return cachedUser;
                    }
                }
            } catch (cacheError) {
                console.error('[QR Scanner] Cache lookup error:', cacheError);
            }

            return null;
        }

        // Helper function to try network request in background (doesn't block)
        // Used to update cache when online, without blocking offline scanning
        async function tryNetworkRequestInBackground(qrData) {
            // Only try network if we're not obviously offline
            if (!navigator.onLine) {
                return;
            }
            
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 3000); // 3 second timeout for background
                
                const response = await fetch('api/get-user-info.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        qr_data: qrData
                    }),
                    credentials: 'same-origin',
                    signal: controller.signal
                });
                
                clearTimeout(timeoutId);
                
                if (response.ok) {
                    const result = await response.json();
                    if (result.success && offlineStorage && result.user && result.user.employee_id) {
                        // Update cache in background
                        try {
                            await offlineStorage.cacheUserData(result.user);
                            console.log('[QR Scanner] Cache updated in background');
                        } catch (cacheError) {
                            console.warn('[QR Scanner] Failed to update cache:', cacheError);
                        }
                    }
                }
            } catch (error) {
                // Silently fail - this is background update
                console.log('[QR Scanner] Background network update failed (expected when offline)');
            }
        }

        async function getUserInfoFromQR(qrData) {
            // Check if we're actually offline (unreliable but helpful for optimization)
            const isOffline = !navigator.onLine;
            
            // If online, try network request first (with short timeout), then fallback to cache
            // If offline, check cache immediately
            if (!isOffline) {
                // Online: Try network first with a short timeout (3 seconds)
                try {
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 3000); // 3 second timeout for quick response
                    
                    const response = await fetch('api/get-user-info.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            qr_data: qrData
                        }),
                        credentials: 'same-origin',
                        signal: controller.signal
                    });
                    
                    clearTimeout(timeoutId);
                    
                    if (response.ok) {
                        const result = await response.json();
                        
                        if (result.success) {
                            // Cache user data for offline use
                            if (offlineStorage && result.user && result.user.employee_id) {
                                try {
                                    await offlineStorage.cacheUserData(result.user);
                                    console.log('[QR Scanner] User data cached for offline use');
                                } catch (cacheError) {
                                    console.warn('[QR Scanner] Failed to cache user data:', cacheError);
                                }
                            }
                            
                            // Process successful network response (same logic as before)
                            // Check if today is a holiday
                            if (result.is_holiday) {
                                if (result.today_attendance) {
                                    scannedUserData = result.user;
                                    currentAttendanceData = result.today_attendance;
                                    addEmployeeToTable(result.user, result.today_attendance);
                                    updateButtonStates(null);
                                }
                                showAlert('info', `Holiday today, no need to log. ${result.holiday_title || 'Holiday'} - Your attendance has been automatically recorded for 8 hours (08:00-17:00).`, 'Holiday Status');
                                if (!result.today_attendance) {
                                    scannedUserData = null;
                                    currentAttendanceData = null;
                                    updateButtonStates(null);
                                }
                                return;
                            }
                            
                            // Check if employee is in TARF
                            if (result.is_in_tarf) {
                                if (result.today_attendance) {
                                    scannedUserData = result.user;
                                    currentAttendanceData = result.today_attendance;
                                    addEmployeeToTable(result.user, result.today_attendance);
                                    updateButtonStates(null);
                                }
                                showAlert('info', `You are in TARF: ${result.tarf_title || 'TARF'}. Your attendance has been automatically recorded based on your official time.`, 'TARF Status');
                                if (!result.today_attendance) {
                                    scannedUserData = null;
                                    currentAttendanceData = null;
                                    updateButtonStates(null);
                                }
                                return;
                            }
                            
                            // Normal attendance processing
                            const isSameUser = scannedUserData && scannedUserData.user_id === result.user.user_id;
                            scannedUserData = result.user;
                            currentAttendanceData = result.today_attendance || null;
                            
                            if (isSameUser) {
                                updateEmployeeRowInTable(result.user.user_id, result.today_attendance);
                            } else {
                                addEmployeeToTable(result.user, result.today_attendance);
                            }
                            
                            updateButtonStates(currentAttendanceData);
                            
                            const message = isSameUser 
                                ? 'QR code scanned again! Buttons enabled. Select an action below.'
                                : 'QR code scanned successfully! Employee added to table. Select an action below.';
                            showAlert('success', message, 'Employee Found');
                            return;
                        } else {
                            showAlert('danger', result.message, 'Error');
                            scannedUserData = null;
                            currentAttendanceData = null;
                            updateButtonStates(null);
                            return;
                        }
                    }
                } catch (networkError) {
                    // Network request failed or timed out - fall through to cache check below
                    console.log('[QR Scanner] Network request failed/timed out, checking cache:', networkError);
                }
            }
            
            // Offline mode OR network request failed: Check cache
            if (offlineStorage) {
                console.log('[QR Scanner] Checking cache for QR data:', qrData, isOffline ? '(offline mode)' : '(network failed)');
                
                const cachedUser = await tryGetUserFromCache(qrData);
                
                if (cachedUser) {
                    console.log('[QR Scanner] Found user in cache:', cachedUser);
                    // Use cached data - pass isOffline flag to show appropriate message
                    await processCachedUserData(cachedUser, qrData, isOffline);
                    
                    // If online but using cache (network failed), try to update in background
                    if (!isOffline) {
                        tryNetworkRequestInBackground(qrData);
                    }
                    return;
                }
            }
            
            // No cache found: still allow recording offline using raw QR data (sync when online)
            await processUnknownUserOffline(qrData, isOffline);
        }

        /**
         * Process QR scan when employee is not in cache (offline or network failed).
         * We still allow recording: store raw qr_data and sync when online.
         * @param {string} qrData - Raw QR code data (employee_id or user_id string)
         * @param {boolean} isOffline - Whether system is offline (for message)
         */
        async function processUnknownUserOffline(qrData, isOffline) {
            const displayId = (typeof qrData === 'string' && qrData.length > 20) ? qrData.substring(0, 16) + '…' : qrData;
            const rowId = 'qr-' + (typeof qrData === 'string' ? qrData.replace(/[^a-zA-Z0-9]/g, '').slice(0, 30) : String(qrData).slice(0, 30));
            scannedUserData = {
                qr_data: qrData,
                user_id: null,
                _rowId: rowId,
                employee_id: displayId,
                name: 'Unknown (sync when online)'
            };
            currentAttendanceData = {
                employee_id: displayId,
                name: scannedUserData.name,
                time_in: null,
                lunch_out: null,
                lunch_in: null,
                time_out: null,
                ot_in: null,
                ot_out: null
            };
            addEmployeeToTable(scannedUserData, currentAttendanceData);
            updateButtonStates(currentAttendanceData);
            if (isOffline) {
                showAlert('info',
                    'Employee not in cache. You can still record attendance now — it will sync automatically when you\'re back online.',
                    'Record offline');
            } else {
                showAlert('info',
                    'Employee not in cache (network unavailable). You can record attendance now; it will sync when connection is restored.',
                    'Record now, sync later');
            }
        }

        /**
         * Process cached user data for offline QR scanning
         * Note: We can't get today's attendance data offline, so we'll use empty attendance
         * @param {Object} cachedUser - Cached user data
         * @param {string} qrData - QR code data
         * @param {boolean} isOffline - Whether system is actually offline (to show appropriate message)
         */
        async function processCachedUserData(cachedUser, qrData, isOffline = true) {
            try {
                // Use cached user data
                scannedUserData = {
                    user_id: cachedUser.user_id,
                    employee_id: cachedUser.employee_id,
                    name: cachedUser.name || (cachedUser.first_name + ' ' + (cachedUser.middle_name ? cachedUser.middle_name + ' ' : '') + cachedUser.last_name),
                    first_name: cachedUser.first_name,
                    last_name: cachedUser.last_name,
                    middle_name: cachedUser.middle_name,
                    email: cachedUser.email,
                    department: cachedUser.department,
                    position: cachedUser.position
                };
                
                // We don't have today's attendance data offline, so create empty record
                currentAttendanceData = {
                    employee_id: cachedUser.employee_id,
                    name: scannedUserData.name,
                    time_in: null,
                    lunch_out: null,
                    lunch_in: null,
                    time_out: null,
                    ot_in: null,
                    ot_out: null
                };
                
                // Add employee to table with empty attendance
                addEmployeeToTable(scannedUserData, currentAttendanceData);
                
                // Update button states - enable all buttons since we don't know current attendance
                updateButtonStates(currentAttendanceData);
                
                // Show appropriate message based on offline status
                if (isOffline) {
                    showAlert('info', 
                        'QR code scanned offline using cached data. Attendance buttons are enabled. Note: Current attendance status may not be accurate until connection is restored.', 
                        'Offline Scan Successful');
                } else {
                    // Online but using cache (network failed) - show different message
                    showAlert('warning', 
                        'QR code scanned using cached data (network unavailable). Attendance buttons are enabled. The system will try to sync with server in the background.', 
                        'Scan Successful (Using Cache)');
                }
                    
                console.log('[QR Scanner] Processed cached user data successfully', isOffline ? '(offline mode)' : '(cache fallback)');
            } catch (error) {
                console.error('[QR Scanner] Error processing cached user data:', error);
                showAlert('danger', 'Error processing cached data. Please try again when online.', 'Error');
                scannedUserData = null;
                currentAttendanceData = null;
                updateButtonStates(null);
            }
        }

        function addEmployeeToTable(user, todayAttendance) {
            const tbody = document.getElementById('attendanceTableBody');

            // Clear the table to show only the scanned user's data
            tbody.innerHTML = '';

            // Use todayAttendance data if available, otherwise create empty record
            const record = todayAttendance || {
                employee_id: user.employee_id,
                name: user.name,
                time_in: null,
                lunch_out: null,
                lunch_in: null,
                time_out: null,
                ot_in: null,
                ot_out: null
            };

            const totalHours = calculateTotalHours(record);

            // Create row for the scanned employee only (use _rowId for unknown/offline-first users)
            const row = document.createElement('tr');
            row.setAttribute('data-user-id', user.user_id != null ? user.user_id : (user._rowId || 'unknown'));
            row.setAttribute('data-employee-id', user.employee_id || '');

            row.innerHTML = `
                <td><strong>${user.employee_id || 'N/A'}</strong></td>
                <td>${user.name || 'N/A'}</td>
                <td>${formatTime(record.time_in)}</td>
                <td>${formatTime(record.lunch_out)}</td>
                <td>${formatTime(record.lunch_in)}</td>
                <td>${formatTime(record.time_out)}</td>
                <td>${formatTime(record.ot_in)}</td>
                <td>${formatTime(record.ot_out)}</td>
                <td><strong>${totalHours}</strong></td>
            `;

            // Add highlight animation
            row.classList.add('table-success');
            setTimeout(() => {
                row.classList.remove('table-success');
            }, 2000);

            // Add to table
            tbody.appendChild(row);

            // Scroll to the row
            row.scrollIntoView({
                behavior: 'smooth',
                block: 'nearest'
            });
        }

        function updateEmployeeRowInTable(userId, attendanceData) {
            const tbody = document.getElementById('attendanceTableBody');
            const row = tbody.querySelector(`tr[data-user-id="${userId}"]`);

            if (row) {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 9) {
                    cells[2].textContent = formatTime(attendanceData.time_in);
                    cells[3].textContent = formatTime(attendanceData.lunch_out);
                    cells[4].textContent = formatTime(attendanceData.lunch_in);
                    cells[5].textContent = formatTime(attendanceData.time_out);
                    cells[6].textContent = formatTime(attendanceData.ot_in);
                    cells[7].textContent = formatTime(attendanceData.ot_out);
                    cells[8].innerHTML = '<strong>' + calculateTotalHours(attendanceData) + '</strong>';

                    // Highlight the updated row
                    row.classList.add('table-warning');
                    setTimeout(() => {
                        row.classList.remove('table-warning');
                    }, 1500);
                }
            } else {
                // If row doesn't exist and we have scanned user data, add it to table
                if (scannedUserData) {
                    addEmployeeToTable(scannedUserData, attendanceData);
                }
            }
        }

        async function refreshUserInfo(userId) {
            // Check if offline before making request
            if (!navigator.onLine) {
                console.log('Offline: Skipping user info refresh');
                return;
            }

            try {
                // Add timeout to prevent hanging requests
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout

                // Use user_id directly to get fresh info
                const response = await fetch('api/get-user-info.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        qr_data: userId.toString()
                    }),
                    signal: controller.signal
                });

                clearTimeout(timeoutId);

                // Check if response is ok before parsing JSON
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                if (result.success) {
                    scannedUserData = result.user;
                    currentAttendanceData = result.today_attendance || null;
                    // Update employee in table
                    updateEmployeeRowInTable(result.user.user_id, result.today_attendance || {
                        employee_id: result.user.employee_id,
                        name: result.user.name,
                        time_in: null,
                        lunch_out: null,
                        lunch_in: null,
                        time_out: null,
                        ot_in: null,
                        ot_out: null
                    });
                    // Update button states based on updated attendance
                    updateButtonStates(currentAttendanceData);
                }
            } catch (error) {
                // Only log non-network errors to avoid console spam
                if (error.name === 'AbortError') {
                    console.log('User info refresh timeout - network may be slow or disconnected');
                } else if (error.message && (error.message.includes('Failed to fetch') || error.message.includes('NetworkError') || error.message.includes('ERR_INTERNET_DISCONNECTED'))) {
                    console.log('Network error: Unable to refresh user info - device appears to be offline');
                } else {
                    console.error('Error refreshing user info:', error);
                }
            }
        }

        async function refreshUserInfoWithoutEnablingButtons(userId) {
            // Check if offline before making request
            if (!navigator.onLine) {
                console.log('Offline: Skipping user info refresh');
                return;
            }

            try {
                // Add timeout to prevent hanging requests
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout

                // Use user_id directly to get fresh info
                const response = await fetch('api/get-user-info.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        qr_data: userId.toString()
                    }),
                    signal: controller.signal
                });

                clearTimeout(timeoutId);

                // Check if response is ok before parsing JSON
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const result = await response.json();

                if (result.success) {
                    // Update employee in table but don't set scannedUserData or enable buttons
                    updateEmployeeRowInTable(result.user.user_id, result.today_attendance || {
                        employee_id: result.user.employee_id,
                        name: result.user.name,
                        time_in: null,
                        lunch_out: null,
                        lunch_in: null,
                        time_out: null,
                        ot_in: null,
                        ot_out: null
                    });
                    // Keep buttons disabled - require new QR scan
                    disableAllButtons();
                }
            } catch (error) {
                // Only log non-network errors to avoid console spam
                if (error.name === 'AbortError') {
                    console.log('User info refresh timeout - network may be slow or disconnected');
                } else if (error.message && (error.message.includes('Failed to fetch') || error.message.includes('NetworkError') || error.message.includes('ERR_INTERNET_DISCONNECTED'))) {
                    console.log('Network error: Unable to refresh user info - device appears to be offline');
                } else {
                    console.error('Error refreshing user info:', error);
                }
            }
        }

        // Timer for auto-hiding button group
        let buttonGroupHideTimer = null;

        function disableAllButtons() {
            // Disable all buttons
            const btnTimeIn = document.getElementById('btn-time-in');
            const btnLunchOut = document.getElementById('btn-lunch-out');
            const btnLunchIn = document.getElementById('btn-lunch-in');
            const btnTimeOut = document.getElementById('btn-time-out');
            const btnOtIn = document.getElementById('btn-ot-in');
            const btnOtOut = document.getElementById('btn-ot-out');
            
            btnTimeIn.disabled = true;
            btnLunchOut.disabled = true;
            btnLunchIn.disabled = true;
            btnTimeOut.disabled = true;
            btnOtIn.disabled = true;
            btnOtOut.disabled = true;
        }

        function showButtonGroup() {
            const buttonGroup = document.querySelector('.button-group');
            if (buttonGroup) {
                buttonGroup.classList.add('visible');
                
                // Clear any existing timer
                if (buttonGroupHideTimer) {
                    clearTimeout(buttonGroupHideTimer);
                }
                
                // Set timer to hide after 60 seconds (user-friendly: actions stay visible longer)
                buttonGroupHideTimer = setTimeout(() => {
                    hideButtonGroup();
                }, 60000);
            }
        }

        function hideButtonGroup() {
            const buttonGroup = document.querySelector('.button-group');
            if (buttonGroup) {
                buttonGroup.classList.remove('visible');
            }
            
            // Clear timer if exists
            if (buttonGroupHideTimer) {
                clearTimeout(buttonGroupHideTimer);
                buttonGroupHideTimer = null;
            }
        }

        function updateCurrentEmployeeCard() {
            const card = document.getElementById('currentEmployeeCard');
            const nameEl = document.getElementById('currentEmployeeName');
            const idEl = document.getElementById('currentEmployeeId');
            const dateDrawer = document.getElementById('currentDateDrawer');
            const employeeDrawer = document.getElementById('currentEmployeeDrawer');
            if (!card || !nameEl || !idEl) return;
            if (!scannedUserData) {
                card.classList.remove('visible');
                if (dateDrawer) dateDrawer.classList.remove('d-none');
                if (employeeDrawer) {
                    employeeDrawer.classList.add('d-none');
                    employeeDrawer.textContent = '';
                }
                return;
            }
            nameEl.textContent = scannedUserData.name || '—';
            idEl.textContent = 'ID: ' + (scannedUserData.employee_id || '—');
            card.classList.add('visible');
            // Mobile drawer header: show employee ID when scanned
            if (dateDrawer) dateDrawer.classList.add('d-none');
            if (employeeDrawer) {
                employeeDrawer.textContent = 'Safe Employee ID: ' + (scannedUserData.employee_id || '—');
                employeeDrawer.classList.remove('d-none');
            }
        }

        function clearSelection() {
            scannedUserData = null;
            currentAttendanceData = null;
            loadAttendanceData();
            updateCurrentEmployeeCard();
            disableAllButtons();
            hideButtonGroup();
        }

        function updateButtonStates(attendance) {
            updateCurrentEmployeeCard();
            // Get button elements
            const btnTimeIn = document.getElementById('btn-time-in');
            const btnLunchOut = document.getElementById('btn-lunch-out');
            const btnLunchIn = document.getElementById('btn-lunch-in');
            const btnTimeOut = document.getElementById('btn-time-out');
            const btnOtIn = document.getElementById('btn-ot-in');
            const btnOtOut = document.getElementById('btn-ot-out');

            if (!scannedUserData) {
                // No user scanned - disable all buttons and hide button group
                disableAllButtons();
                hideButtonGroup();
                return;
            }

            // If no attendance data, create empty attendance object
            if (!attendance) {
                attendance = {
                    time_in: null,
                    lunch_out: null,
                    lunch_in: null,
                    time_out: null,
                    ot_in: null,
                    ot_out: null
                };
            }

            // Enable all buttons that haven't been recorded yet
            // Allow any button to be clicked after scanning QR code
            btnTimeIn.disabled = !!attendance.time_in;
            btnLunchOut.disabled = !!attendance.lunch_out;
            btnLunchIn.disabled = !!attendance.lunch_in;
            btnTimeOut.disabled = !!attendance.time_out;
            btnOtIn.disabled = !!attendance.ot_in;
            btnOtOut.disabled = !!attendance.ot_out;
            
            // Show button group when user is scanned (will auto-hide after 10 seconds)
            showButtonGroup();
            // On mobile: auto-open attendance drawer so user sees employee and can record
            if (window.innerWidth <= 991 && typeof toggleAttendanceDrawer === 'function') {
                toggleAttendanceDrawer(true);
            }
            
            // Log button states for debugging
            console.log('Button states updated:', {
                timeIn: !btnTimeIn.disabled,
                lunchOut: !btnLunchOut.disabled,
                lunchIn: !btnLunchIn.disabled,
                timeOut: !btnTimeOut.disabled,
                otIn: !btnOtIn.disabled,
                otOut: !btnOtOut.disabled
            });
        }

        function recordAttendance(type) {
            if (!scannedUserData) {
                showAlert('warning', 'Please scan a QR code first.', 'No Employee Selected');
                return;
            }

            // Use user_id when available, otherwise qr_data (for offline-first scan not in cache)
            const idOrQr = scannedUserData.user_id != null ? scannedUserData.user_id : scannedUserData.qr_data;
            processQRCode(idOrQr, type);
        }

        /**
         * Modal when attendance is saved locally but not yet on the server (offline / unstable network).
         */
        async function showPendingSyncAlert(isDefinitelyOffline) {
            const lead = isDefinitelyOffline
                ? 'No internet connection was detected.'
                : 'The server could not be reached — the connection may be unstable or offline.';
            const html = '<p>' + lead + ' Your attendance was <strong>saved on this device</strong> and is <strong>pending sync</strong> to the server.</p>' +
                '<p>It will upload automatically when the connection is stable. Watch the <strong>status indicator</strong> (top right) for <em>Pending</em> or <em>Syncing</em>.</p>';

            if (typeof Swal !== 'undefined') {
                await Swal.fire({
                    icon: 'warning',
                    title: 'Pending sync',
                    html: html,
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#0dcaf0'
                });
            } else {
                showAlert('warning', lead + ' Saved on this device; will sync when online.', 'Pending sync');
            }
        }

        async function refreshOfflineIndicatorAfterQueueChange() {
            try {
                if (syncManager) {
                    const status = await syncManager.getStatus();
                    updateOfflineIndicator(status);
                } else if (offlineStorage) {
                    const pendingCount = await offlineStorage.getPendingCount();
                    updateOfflineIndicator({
                        isOnline: navigator.onLine,
                        pendingCount: pendingCount,
                        isSyncing: false
                    });
                }
            } catch (e) {
                console.warn('[QR Scanner] Could not refresh offline indicator:', e);
            }
        }

        async function finalizePendingAttendanceLocal(attendanceType, timeString) {
            if (scannedUserData) {
                const localAttendance = currentAttendanceData ? { ...currentAttendanceData } : {
                    employee_id: scannedUserData.employee_id,
                    name: scannedUserData.name,
                    time_in: null,
                    lunch_out: null,
                    lunch_in: null,
                    time_out: null,
                    ot_in: null,
                    ot_out: null
                };
                const fieldMap = {
                    'time_in': 'time_in',
                    'lunch_out': 'lunch_out',
                    'lunch_in': 'lunch_in',
                    'time_out': 'time_out',
                    'ot_in': 'ot_in',
                    'ot_out': 'ot_out'
                };
                const fieldName = fieldMap[attendanceType];
                if (fieldName) {
                    localAttendance[fieldName] = timeString;
                }
                const rowKey = scannedUserData.user_id != null ? scannedUserData.user_id : scannedUserData._rowId;
                updateEmployeeRowInTable(rowKey, localAttendance);
            }
            scannedUserData = null;
            currentAttendanceData = null;
            updateCurrentEmployeeCard();
            disableAllButtons();
            hideButtonGroup();
            loadAttendanceData();
            await refreshOfflineIndicatorAfterQueueChange();
        }

        async function processQRCode(userIdOrQRData, attendanceType) {
            try {
                const stationId = <?php echo (int)($_SESSION['station_id'] ?? 0); ?>;
                // If userIdOrQRData is a number, it's a user_id, otherwise it's QR data
                const now = new Date();
                const hours = String(now.getHours()).padStart(2, '0');
                const minutes = String(now.getMinutes()).padStart(2, '0');
                const seconds = String(now.getSeconds()).padStart(2, '0');
                const timeString = `${hours}:${minutes}:${seconds}`;
                // Use local date (not UTC) - server may have wrong date/timezone
                const localDateStr = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
                const requestBody = {
                    attendance_type: attendanceType,
                    recorded_time: timeString,
                    log_date: localDateStr,
                    station_id: stationId
                };

                if (typeof userIdOrQRData === 'number' || (typeof userIdOrQRData === 'string' && /^\d+$/.test(
                        userIdOrQRData))) {
                    // It's a user_id, send it directly
                    requestBody.user_id = parseInt(userIdOrQRData);
                } else {
                    // It's QR data
                    requestBody.qr_data = userIdOrQRData;
                }

                // Check if we have user data for offline storage
                const userData = scannedUserData || {};

                const hasUserId = userData.user_id != null && userData.user_id !== '';
                const hasQrData = userData.qr_data != null && String(userData.qr_data).trim() !== '';

                // WRITE-AHEAD: Always store to IndexedDB first when we have user/qr data.
                // This ensures every scanner entry is persisted even if tab closes, network fails, or browser crashes.
                // SyncManager will sync to database when online; immediate fetch below will sync right away when possible.
                let storedRecordId = null;
                if (hasUserId || hasQrData) {
                    if (!offlineStorage) {
                        try {
                            offlineStorage = new OfflineStorage();
                            await offlineStorage.init();
                        } catch (initError) {
                            console.error('[QR Scanner] Failed to initialize offline storage:', initError);
                            showAlert('danger', 'Offline storage unavailable. Please try again when online.', 'Error');
                            return;
                        }
                    }
                    const offlineRecord = {
                        attendance_type: attendanceType,
                        station_id: stationId,
                        log_date: localDateStr,
                        timestamp: now.toISOString(),
                        recorded_time: timeString
                    };
                    if (hasUserId) {
                        offlineRecord.user_id = userData.user_id;
                        offlineRecord.employee_id = userData.employee_id || '';
                    } else {
                        offlineRecord.qr_data = String(userData.qr_data).trim();
                    }
                    storedRecordId = await offlineStorage.storeAttendance(offlineRecord);
                    console.log('[QR Scanner] Attendance stored (write-ahead):', offlineRecord);
                }

                // No network reported — avoid waiting on fetch; same pending flow as failed sync
                if (storedRecordId && !navigator.onLine) {
                    await showPendingSyncAlert(true);
                    await finalizePendingAttendanceLocal(attendanceType, timeString);
                    return;
                }

                // Try to send to server immediately (when online) - SyncManager will retry if this fails
                let result = null;

                try {
                    const response = await fetch('api/record-attendance.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(requestBody),
                        credentials: 'same-origin'
                    });

                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    result = await response.json();

                } catch (error) {
                    // Fetch failed - record is already in IndexedDB, SyncManager will sync when online
                    console.warn('[QR Scanner] Network error, record already stored - will sync when online:', error);
                    if (storedRecordId) {
                        await showPendingSyncAlert(false);
                        await finalizePendingAttendanceLocal(attendanceType, timeString);
                    } else {
                        showAlert('danger', 'Network error and no user data to store. Please try again.', 'Error');
                    }
                    return;
                }

                // Process server response
                if (result.success) {
                    // Mark as synced so SyncManager won't retry (record is already in database)
                    if (storedRecordId && offlineStorage) {
                        try {
                            await offlineStorage.markAsSynced(storedRecordId);
                        } catch (e) {
                            console.warn('[QR Scanner] Could not mark record as synced:', e);
                        }
                    }
                    showAlert('success', result.message, 'Attendance recorded successfully!');
                    
                    // Store user ID before clearing scanned data
                    const userIdToRefresh = scannedUserData ? scannedUserData.user_id : null;
                    
                    // Clear scanned user data to require a new QR scan for next action
                    scannedUserData = null;
                    currentAttendanceData = null;
                    updateCurrentEmployeeCard();
                    // Disable all buttons and hide button group after recording attendance
                    disableAllButtons();
                    hideButtonGroup();
                    
                    // Refresh attendance data to show updated times (but don't enable buttons)
                    if (userIdToRefresh) {
                        // Refresh the attendance data and update table without enabling buttons
                        refreshUserInfoWithoutEnablingButtons(userIdToRefresh);
                    }
                } else {
                    // Server rejected (holiday, TARF, validation) - mark as failed so we don't retry
                    if (storedRecordId && offlineStorage) {
                        try {
                            await offlineStorage.markAsFailed(storedRecordId, result.message || 'Server rejected');
                        } catch (e) {
                            console.warn('[QR Scanner] Could not mark record as failed:', e);
                        }
                    }
                    showAlert('danger', result.message, 'Error recording attendance');
                }
            } catch (error) {
                console.error('Error:', error);
                showAlert('danger', 'Network error. Please try again.', 'Error');
            }
        }

        function showAlert(type, message, title) {
            // Check if SweetAlert2 is loaded
            if (typeof Swal === 'undefined') {
                // Fallback to native alert if SweetAlert2 is not available
                alert((title || 'Notification') + '\n\n' + message);
                return;
            }
            
            // Map Bootstrap alert types to SweetAlert2 icon types
            let iconType = 'info';
            let confirmButtonColor = '#0d6efd';
            
            switch(type) {
                case 'success':
                    iconType = 'success';
                    confirmButtonColor = '#198754';
                    break;
                case 'danger':
                case 'error':
                    iconType = 'error';
                    confirmButtonColor = '#dc3545';
                    break;
                case 'warning':
                    iconType = 'warning';
                    confirmButtonColor = '#ffc107';
                    break;
                case 'info':
                    iconType = 'info';
                    confirmButtonColor = '#0dcaf0';
                    break;
            }
            
            Swal.fire({
                icon: iconType,
                title: title || 'Notification',
                text: message,
                confirmButtonColor: confirmButtonColor,
                confirmButtonText: 'OK',
                timer: type === 'success' ? 3000 : null,
                timerProgressBar: type === 'success'
            });
        }

        async function loadAttendanceData() {
            // If a user has been scanned, don't reload all users' data
            // Only refresh the scanned user's data
            if (scannedUserData && scannedUserData.user_id) {
                refreshUserInfo(scannedUserData.user_id);
                return;
            }

            // If no user is scanned, show message to scan QR code
            const tbody = document.getElementById('attendanceTableBody');
            tbody.innerHTML = `
                <tr>
                    <td colspan="9" class="text-center py-5 text-muted">
                        <i class="bi bi-qr-code-scan d-block fs-2 mb-2" style="opacity: 0.6;"></i>
                        <span>Scan a QR code to view and record attendance</span>
                    </td>
                </tr>
            `;
        }

        function formatTime(time) {
            if (!time) return '-';
            // Handle TIME format (HH:MM:SS) from attendance_logs
            if (typeof time === 'string' && time.match(/^\d{2}:\d{2}:\d{2}$/)) {
                return time.substring(0, 5); // Return HH:MM
            }
            try {
                const date = new Date(time);
                if (isNaN(date.getTime())) return '-';
                return date.toLocaleTimeString('en-US', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit'
                });
            } catch (e) {
                return '-';
            }
        }

        function calculateTotalHours(record) {
            // Only calculate if all four required fields are present
            if (!record.time_in || !record.lunch_out || !record.lunch_in || !record.time_out) {
                return '-';
            }

            let totalMinutes = 0;

            // Helper function to parse time to minutes
            const parseTimeToMinutes = (timeStr) => {
                if (!timeStr) return null;
                if (typeof timeStr === 'string' && timeStr.match(/^\d{2}:\d{2}:\d{2}$/)) {
                    const parts = timeStr.split(':');
                    return parseInt(parts[0]) * 60 + parseInt(parts[1]);
                }
                try {
                    const date = new Date(timeStr);
                    if (!isNaN(date.getTime())) {
                        return date.getHours() * 60 + date.getMinutes();
                    }
                } catch (e) {
                    return null;
                }
                return null;
            };

            // Calculate Time In to Lunch Out
            const timeIn = parseTimeToMinutes(record.time_in);
            const lunchOut = parseTimeToMinutes(record.lunch_out);
            if (timeIn !== null && lunchOut !== null && lunchOut > timeIn) {
                totalMinutes += (lunchOut - timeIn);
            }

            // Calculate Lunch In to Time Out
            const lunchIn = parseTimeToMinutes(record.lunch_in);
            const timeOut = parseTimeToMinutes(record.time_out);
            if (lunchIn !== null && timeOut !== null && timeOut > lunchIn) {
                totalMinutes += (timeOut - lunchIn);
            }

            // Calculate OT In to OT Out (optional)
            const otIn = parseTimeToMinutes(record.ot_in);
            const otOut = parseTimeToMinutes(record.ot_out);
            if (otIn !== null && otOut !== null && otOut > otIn) {
                totalMinutes += (otOut - otIn);
            }

            // If total is invalid, return '-'
            if (totalMinutes <= 0) {
                return '-';
            }

            const hours = Math.floor(totalMinutes / 60);
            const minutes = Math.floor(totalMinutes % 60);
            return `${hours}h ${minutes}m`;
        }

        /** Mobile attendance drawer: open/close the Today's Attendance panel */
        function toggleAttendanceDrawer(open) {
            const section = document.getElementById('tableSection');
            const backdrop = document.getElementById('attendanceDrawerBackdrop');
            if (!section) return;
            const isOpen = open === undefined ? section.classList.contains('show') : !!open;
            if (isOpen) {
                section.classList.add('show');
                if (backdrop) {
                    backdrop.classList.add('show');
                    backdrop.setAttribute('aria-hidden', 'false');
                }
                document.body.classList.add('drawer-open');
            } else {
                section.classList.remove('show');
                if (backdrop) {
                    backdrop.classList.remove('show');
                    backdrop.setAttribute('aria-hidden', 'true');
                }
                document.body.classList.remove('drawer-open');
            }
        }

        /**
         * Session Heartbeat - Keeps timekeeper session alive
         * Refreshes session every 5 minutes to prevent timeout
         */
        function startSessionHeartbeat() {
            const refreshInterval = 5 * 60 * 1000; // 5 minutes in milliseconds
            
            setInterval(async function() {
                // Skip if offline
                if (!navigator.onLine) {
                    console.log('Offline: Skipping session refresh');
                    return;
                }

                try {
                    // Add timeout to prevent hanging requests
                    const controller = new AbortController();
                    const timeoutId = setTimeout(() => controller.abort(), 8000); // 8 second timeout

                    const response = await fetch('api/refresh-session.php', {
                        method: 'GET',
                        credentials: 'same-origin', // Include cookies
                        signal: controller.signal
                    });
                    
                    clearTimeout(timeoutId);
                    
                    if (response.ok) {
                        const data = await response.json();
                        if (data.success) {
                            console.log('Session refreshed at', new Date().toLocaleTimeString());
                        }
                    } else if (response.status === 401) {
                        // Session expired - redirect to login
                        console.warn('Session expired, redirecting to login...');
                        window.location.href = '../station_login.php';
                    }
                } catch (error) {
                    // Only log non-network errors to avoid console spam
                    if (error.name === 'AbortError') {
                        console.log('Session refresh timeout - network may be slow or disconnected');
                    } else if (error.message && (error.message.includes('Failed to fetch') || error.message.includes('NetworkError') || error.message.includes('ERR_INTERNET_DISCONNECTED'))) {
                        console.log('Network error: Unable to refresh session - device appears to be offline');
                    } else {
                        console.error('Error refreshing session:', error);
                    }
                    // Don't redirect on network errors, just log
                }
            }, refreshInterval);
            
            console.log('Session heartbeat started - session will be refreshed every 5 minutes');
        }

        // Button click handler to start camera (required for mobile browsers)
        document.addEventListener('DOMContentLoaded', () => {
            // Mobile attendance drawer: open/close
            const btnMobileAttendance = document.getElementById('btn-mobile-attendance');
            const drawerBackdrop = document.getElementById('attendanceDrawerBackdrop');
            if (btnMobileAttendance) {
                btnMobileAttendance.addEventListener('click', () => toggleAttendanceDrawer(true));
            }
            if (drawerBackdrop) {
                drawerBackdrop.addEventListener('click', () => toggleAttendanceDrawer(false));
                drawerBackdrop.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggleAttendanceDrawer(false); } });
            }

            // Swipe-down-to-close for attendance drawer (mobile)
            (function initDrawerSwipe() {
                const section = document.getElementById('tableSection');
                if (!section || !('ontouchstart' in window)) return;
                let touchStartY = null;
                const SWIPE_ZONE_TOP = 100; // px from top - only swipe-to-close when touch starts here
                const SWIPE_THRESHOLD = 50;
                section.addEventListener('touchstart', (e) => {
                    if (!section.classList.contains('show')) return;
                    const rect = section.getBoundingClientRect();
                    const y = e.touches[0].clientY;
                    if (y - rect.top < SWIPE_ZONE_TOP) touchStartY = y;
                    else touchStartY = null;
                }, { passive: true });
                section.addEventListener('touchend', (e) => {
                    if (touchStartY === null || !section.classList.contains('show')) return;
                    const y = e.changedTouches[0].clientY;
                    if (y - touchStartY > SWIPE_THRESHOLD) toggleAttendanceDrawer(false);
                    touchStartY = null;
                }, { passive: true });
            })();

            const startCameraBtn = document.getElementById('btn-start-camera');
            if (startCameraBtn) {
                startCameraBtn.addEventListener('click', async () => {
                    // Disable button and show loading state
                    startCameraBtn.disabled = true;
                    startCameraBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Starting camera...';
                    updateCameraStatus('loading', 'Requesting camera access...');
                    
                    // Wait for library to load
                    if (!libraryLoaded) {
                        waitForLibrary(() => {
                            initCamera().finally(() => {
                                // Re-enable button if camera fails
                                if (!cameraInitialized) {
                                    startCameraBtn.disabled = false;
                                    startCameraBtn.innerHTML = '<i class="bi bi-camera-fill me-2"></i>Start Camera';
                                }
                            });
                        });
                    } else {
                        initCamera().finally(() => {
                            // Re-enable button if camera fails
                            if (!cameraInitialized) {
                                startCameraBtn.disabled = false;
                                startCameraBtn.innerHTML = '<i class="bi bi-camera-fill me-2"></i>Start Camera';
                            }
                        });
                    }
                });
            }

            // Gun scanner (handheld barcode/QR scanner) - hidden input kept focused so scanner always has a target.
            // Disabled on mobile phones (camera scanning is used instead; gun would steal focus and show irrelevant hint).
            // Process on Enter (scanners that send suffix) OR after ~100ms of no input (scanners that don't send Enter).
            (function initGunScanner() {
                const input = document.getElementById('gunScannerInput');
                const hint = document.getElementById('gunScannerHint');
                if (!input) return;
                if (isMobileDevice) {
                    if (hint) hint.style.display = 'none';
                    input.style.display = 'none';
                    return;
                }

                let scanTimeout = null;
                const SCAN_DELAY_MS = 100;

                function focusInput() {
                    try { input.focus(); } catch (e) {}
                }

                function processScan() {
                    const value = input.value.trim();
                    if (!value || scanCooldown) return;
                    input.value = '';
                    if (scanTimeout) clearTimeout(scanTimeout);
                    scanTimeout = null;
                    scanCooldown = true;
                    lastScannedQR = value;
                    lastScanTime = Date.now();
                    console.log('Gun scanner input:', value);
                    updateCameraStatus('loading', 'Processing QR code...');
                    getUserInfoFromQR(value).finally(() => {
                        const cooldownMs = navigator.onLine ? 2000 : 700;
                        setTimeout(() => {
                            scanCooldown = false;
                            lastScannedQR = null;
                            updateCameraStatus('success', 'Ready — scan another QR code');
                        }, cooldownMs);
                    });
                }

                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        processScan();
                        return;
                    }
                    if (e.key.length === 1) {
                        if (scanTimeout) clearTimeout(scanTimeout);
                        scanTimeout = setTimeout(processScan, SCAN_DELAY_MS);
                    }
                });

                input.addEventListener('blur', () => {
                    const el = document.activeElement;
                    const tag = (el && el.tagName) ? el.tagName.toLowerCase() : '';
                    const isFormField = tag === 'input' || tag === 'textarea' || tag === 'select';
                    if (!isFormField) setTimeout(focusInput, 150);
                });

                window.addEventListener('focus', () => setTimeout(focusInput, 150));

                setTimeout(focusInput, 500);
            })();
            
            // Test camera button - direct camera access test for debugging
            const testCameraBtn = document.getElementById('btn-test-camera');
            if (testCameraBtn) {
                testCameraBtn.addEventListener('click', async () => {
                    testCameraBtn.disabled = true;
                    testCameraBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing...';
                    
                    const results = [];
                    results.push('=== Camera Debug Info ===');
                    results.push('Protocol: ' + location.protocol);
                    results.push('Hostname: ' + location.hostname);
                    results.push('User Agent: ' + navigator.userAgent.substring(0, 100) + '...');
                    results.push('HTTPS: ' + (location.protocol === 'https:' ? 'Yes' : 'No'));
                    results.push('Localhost: ' + (location.hostname === 'localhost' || location.hostname === '127.0.0.1' ? 'Yes' : 'No'));
                    
                    // Check mediaDevices API
                    results.push('');
                    results.push('=== API Support ===');
                    results.push('navigator.mediaDevices: ' + (navigator.mediaDevices ? 'Yes' : 'No'));
                    results.push('navigator.mediaDevices.getUserMedia: ' + (navigator.mediaDevices && navigator.mediaDevices.getUserMedia ? 'Yes' : 'No'));
                    results.push('navigator.mediaDevices.enumerateDevices: ' + (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices ? 'Yes' : 'No'));
                    
                    // Check permissions API
                    let permStatus = 'Not supported';
                    try {
                        if (navigator.permissions && navigator.permissions.query) {
                            const result = await navigator.permissions.query({ name: 'camera' });
                            permStatus = result.state;
                        }
                    } catch (e) {
                        permStatus = 'Error: ' + e.message;
                    }
                    results.push('Camera permission status: ' + permStatus);
                    
                    // Try to enumerate devices
                    results.push('');
                    results.push('=== Camera Devices ===');
                    try {
                        if (navigator.mediaDevices && navigator.mediaDevices.enumerateDevices) {
                            const devices = await navigator.mediaDevices.enumerateDevices();
                            const videoDevices = devices.filter(d => d.kind === 'videoinput');
                            results.push('Video devices found: ' + videoDevices.length);
                            videoDevices.forEach((d, i) => {
                                results.push('  Camera ' + (i+1) + ': ' + (d.label || '(no label - permission needed)'));
                            });
                        } else {
                            results.push('enumerateDevices not available');
                        }
                    } catch (e) {
                        results.push('enumerateDevices error: ' + e.message);
                    }
                    
                    // Try direct camera access
                    results.push('');
                    results.push('=== Direct Camera Test ===');
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ 
                            video: { facingMode: 'environment' } 
                        });
                        results.push('SUCCESS! Camera stream obtained');
                        results.push('Tracks: ' + stream.getTracks().length);
                        stream.getTracks().forEach(track => {
                            results.push('  Track: ' + track.kind + ' - ' + track.label);
                            track.stop(); // Stop the track
                        });
                    } catch (e) {
                        results.push('FAILED: ' + e.name + ' - ' + e.message);
                        
                        // Try with just video: true
                        try {
                            results.push('');
                            results.push('Trying with video: true...');
                            const stream2 = await navigator.mediaDevices.getUserMedia({ video: true });
                            results.push('SUCCESS with video: true');
                            stream2.getTracks().forEach(track => track.stop());
                        } catch (e2) {
                            results.push('Also FAILED: ' + e2.name + ' - ' + e2.message);
                        }
                    }
                    
                    // Display results
                    const resultText = results.join('\n');
                    console.log(resultText);
                    
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            title: 'Camera Debug Results',
                            html: '<pre style="text-align:left;font-size:11px;max-height:400px;overflow:auto;">' + resultText + '</pre>',
                            width: '90%',
                            confirmButtonText: 'OK'
                        });
                    } else {
                        alert(resultText);
                    }
                    
                    testCameraBtn.disabled = false;
                    testCameraBtn.innerHTML = '<i class="bi bi-bug me-1"></i>Test Camera Access';
                });
            }
            
            // Switch camera button handler
            const switchCameraBtn = document.getElementById('btn-switch-front-camera');
            if (switchCameraBtn) {
                switchCameraBtn.addEventListener('click', async () => {
                    if (currentFacingMode === 'environment') {
                        await switchToFrontCamera();
                    } else {
                        await switchToBackCamera();
                    }
                });
            }
            // Clear selection buttons (card + footer)
            ['btn-clear-selection', 'btn-clear-selection-footer'].forEach(id => {
                const btn = document.getElementById(id);
                if (btn) btn.addEventListener('click', clearSelection);
            });
        });

        // Store device token in localStorage if this is first device registration
        function storeDeviceTokenIfNew() {
            <?php if (isset($_SESSION['station_new_device']) && $_SESSION['station_new_device']): ?>
                const stationId = <?php echo (int)($_SESSION['station_id'] ?? 0); ?>;
                const deviceToken = <?php echo json_encode($_SESSION['station_device_token'] ?? ''); ?>;
                
                if (stationId && deviceToken) {
                    localStorage.setItem(`station_${stationId}_device_token`, deviceToken);
                    console.log('Device token stored in localStorage for station:', stationId);
                    
                    // Show success message
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({
                            icon: 'success',
                            title: 'Device Registered',
                            text: 'This device has been registered to this station. Only this device can access this station from now on.',
                            confirmButtonColor: '#198754'
                        });
                    } else {
                        alert('Device Registered\n\nThis device has been registered to this station. Only this device can access this station from now on.');
                    }
                }
                <?php 
                // Clear the flag
                unset($_SESSION['station_new_device']); 
                ?>
            <?php endif; ?>
        }

        // Show offline login warning if applicable
        function showOfflineLoginWarning() {
            <?php if (isset($_SESSION['offline_login_warning']) && $_SESSION['offline_login_warning']): ?>
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Offline Mode',
                        html: 'You logged in while offline. Some features may be limited. Attendance records will be synced when internet connection is restored.',
                        confirmButtonColor: '#ffc107',
                        confirmButtonText: 'OK'
                    });
                } else {
                    alert('Offline Mode\n\nYou logged in while offline. Some features may be limited. Attendance records will be synced when internet connection is restored.');
                }
                <?php 
                // Clear the warning flag
                unset($_SESSION['offline_login_warning']); 
                ?>
            <?php endif; ?>
        }

        // Initialize on page load
        window.addEventListener('load', async () => {
            // Store device token if this is a new device registration
            storeDeviceTokenIfNew();
            
            // Show offline login warning if applicable
            showOfflineLoginWarning();
            
            // Initialize offline storage and sync manager
            await initOfflineStorage();
            
            // Start session heartbeat to prevent timeout
            startSessionHeartbeat();
            
            // Don't auto-start camera - require user interaction (button click)
            // This is required for mobile browsers
            
            loadAttendanceData();
            // Initialize button states (all disabled until user is scanned)
            updateButtonStates(null);

            // Refresh attendance data every 30 seconds
            setInterval(loadAttendanceData, 30000);
            
            // Check sync status every 10 seconds
            setInterval(async () => {
                if (syncManager) {
                    const status = await syncManager.getStatus();
                    updateOfflineIndicator(status);
                }
            }, 10000);

            // Handle page visibility changes to pause/resume scanner
            document.addEventListener('visibilitychange', async () => {
                if (document.hidden) {
                    // Page is hidden
                    isPageVisible = false;
                    await pauseScanner();
                } else {
                    // Page is visible
                    isPageVisible = true;
                    await resumeScanner();
                }
            });

            // Also handle focus/blur events as fallback
            window.addEventListener('blur', async () => {
                isPageVisible = false;
                await pauseScanner();
            });

            window.addEventListener('focus', async () => {
                isPageVisible = true;
                await resumeScanner();
            });
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
            // Stop health monitoring
            stopScannerHealthCheck();
            
            if (html5QrcodeScanner) {
                html5QrcodeScanner.stop().then(() => {
                    console.log('QR scanner stopped');
                }).catch(err => {
                    console.error('Error stopping scanner:', err);
                });
            }
        });
        </script>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>

