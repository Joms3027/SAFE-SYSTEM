<?php
require_once '../../../includes/config.php';
require_once '../../../includes/functions.php';

// Override Permissions-Policy header to allow camera access
if (!headers_sent()) {
    header_remove('Permissions-Policy');
    header('Permissions-Policy: camera=(self), microphone=(self), geolocation=(self)');
}

// No requireTimekeeper, no login, no device lock - open access
$timekeeper = ['station_name' => 'Scanner', 'station_id' => 0];
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
    <link href="<?php echo asset_url('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/timekeeper-mobile.css?v=3.7.1'); ?>" rel="stylesheet">
    
    <style>
        /* QR Code Reader - Desktop styles only; mobile uses timekeeper-mobile.css */
        /* Scoped to min-width: 768px so timekeeper-mobile.css controls mobile layout */
        @media (min-width: 768px) {
            .qrcode-page-body {
                background: #f8f9fa;
                min-height: 100vh;
                padding: 1rem;
            }

            .qrcode-container {
                background: white;
                border-radius: 0.375rem;
                box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
                overflow: hidden;
                max-width: 1800px;
                margin: 0 auto;
                padding: 2rem;
            }

            .camera-section {
                background: white;
                padding: 2rem;
                border-right: 1px solid #dee2e6;
            }

            .camera-wrapper {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 0.375rem;
                padding: 1rem;
                width: 100%;
                max-width: 550px;
                margin-bottom: 1.5rem;
            }

            .table-section {
                padding: 2rem;
                background: white;
                max-height: calc(100vh - 200px);
                overflow-y: auto;
            }

            .camera-status {
                margin-top: 0.5rem;
                font-size: 0.875rem;
            }
        }

        #video {
            border-radius: 0.375rem;
            background: #000;
            height: 100%;
            width: 100%;
            object-fit: cover;
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

        #qr-reader__scan_region {
            border: none !important;
            box-shadow: none !important;
        }

        #qr-reader img[alt="Scanning"] {
            display: none !important;
        }

        /* QR shaded region - match timekeeper */
        #qr-reader #qr-shaded-region {
            border-width: clamp(60px, 12vh, 140px) clamp(15px, 3vw, 30px) !important;
            box-sizing: border-box !important;
        }
        #qr-reader #qr-shaded-region > div {
            width: clamp(25px, 5vw, 45px) !important;
            height: clamp(4px, 0.8vw, 6px) !important;
            min-width: 20px;
            min-height: 3px;
        }
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

        /* Modal variant: always visible, no slide animation */
        .button-group-modal {
            opacity: 1 !important;
            max-height: none !important;
        }

        .alert-message {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
        }

        .camera-placeholder-icon {
            font-size: 3.5rem;
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

        /* Chrome Flags Help Modal - Mobile Specific */
        .chrome-flags-help {
            background: white;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-top: 1rem;
            border: 1px solid #dee2e6;
            display: none;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .chrome-flags-help.show {
            display: block;
            animation: slideIn 0.3s ease-out;
        }

        .chrome-flags-help h5 {
            color: #0d6efd;
            margin-bottom: 1rem;
            font-size: 1.1rem;
        }

        .chrome-flags-help ol {
            padding-left: 1.5rem;
            margin-bottom: 1rem;
        }

        .chrome-flags-help li {
            margin-bottom: 0.75rem;
            line-height: 1.6;
            font-size: 0.95rem;
        }

        .chrome-flags-help code {
            background: #f8f9fa;
            padding: 0.2rem 0.4rem;
            border-radius: 0.25rem;
            font-size: 0.9em;
            color: #d63384;
            word-break: break-all;
            display: inline-block;
            margin: 0.25rem 0;
        }

        .chrome-flags-help .warning-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 0.375rem;
            padding: 0.75rem;
            margin: 1rem 0;
            font-size: 0.9rem;
        }

        .chrome-flags-help .warning-box strong {
            color: #856404;
        }

        .help-toggle-btn {
            margin-top: 0.5rem;
            font-size: 0.875rem;
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
                font-size: 1.1rem;
            }

            .button-group .btn i {
                font-size: 1.65rem;
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
                font-size: 1.05rem;
                border-radius: 0.625rem;
            }

            .button-group .btn i {
                font-size: 1.5rem;
            }
        }

        /* Attendance drawer backdrop - mobile only */
        .attendance-drawer-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.4);
            z-index: 99;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .attendance-drawer-backdrop.show {
            display: block;
            opacity: 1;
        }

        @media (min-width: 992px) {
            .attendance-drawer-backdrop { display: none !important; }
        }

        /* Drawer header for mobile */
        .table-section-drawer-header {
            position: sticky;
            top: 0;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            z-index: 5;
            flex-shrink: 0;
        }

        /* Scan hint when camera is active */
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

        /* Safe area for notched devices - mobile */
        @media (max-width: 767px) {
            .qrcode-page-body {
                padding-left: env(safe-area-inset-left);
                padding-right: env(safe-area-inset-right);
            }
        }

        /* Current employee card - base styles (desktop + mobile) */
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
        .current-employee-card .employee-avatar-placeholder {
            width: 2.5rem;
            height: 2.5rem;
            background: rgba(16, 185, 129, 0.2);
            color: #059669;
        }
    </style>
</head>
<body>
    <!-- Mobile drawer backdrop (tap to close) -->
    <div id="attendanceDrawerBackdrop" class="attendance-drawer-backdrop" aria-hidden="true" onclick="typeof toggleAttendanceDrawer === 'function' && toggleAttendanceDrawer(false);"></div>
    <!-- Offline Status Indicator -->
    <div id="offlineIndicator" class="offline-indicator">
        <i class="bi bi-wifi-off me-2"></i><span id="offlineText">Offline</span>
    </div>

    <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm" style="margin-bottom: 0;" role="navigation">
        <div class="container-fluid d-flex justify-content-between align-items-center flex-nowrap">
            <a class="navbar-brand fw-bold flex-shrink-0" href="allscan2.php" style="color: #0d6efd;">
                <i class="bi bi-qr-code-scan me-2"></i><span>Scanner</span>
            </a>
            <!-- Mobile: toggle attendance drawer -->
            <button type="button" id="btn-mobile-attendance" class="btn btn-outline-primary d-lg-none d-flex align-items-center gap-2 py-2 px-3 flex-shrink-0" aria-label="View today's attendance">
                <i class="bi bi-calendar-check" aria-hidden="true"></i>
                <span>Attendance</span>
            </button>
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
                            <span class="scan-hint" id="scanHint"><i class="bi bi-upc-scan me-1"></i>Position QR code in frame</span>
                        </div>
                        <div id="cameraPlaceholder" class="camera-placeholder-inner text-center py-5">
                            <i class="bi bi-qr-code-scan camera-placeholder-icon mb-3 d-block" aria-hidden="true"></i>
                            <h2 class="camera-placeholder-title h5 mb-2">Scan employee QR codes</h2>
                            <p class="camera-placeholder-lead text-muted mb-3">Point the camera at an employee's QR code to record time in, lunch, time out, and more.</p>
                            <ol class="camera-placeholder-steps list-unstyled small text-muted mb-4">
                                <li><strong>1.</strong> Tap Start Camera or Upload QR Code</li>
                                <li><strong>2.</strong> Scan with camera, or select multiple QR code images to upload</li>
                                <li><strong>3.</strong> Choose the attendance type (Time In, Lunch, etc.) for all</li>
                            </ol>
                            <div class="camera-placeholder-buttons">
                                <button id="btn-start-camera" class="btn btn-primary btn-lg" aria-label="Start camera to scan QR codes">
                                    <i class="bi bi-camera-fill me-2"></i>Start Camera
                                </button>
                                <button id="btn-upload-qr" type="button" class="btn btn-outline-light btn-lg" aria-label="Upload QR code images">
                                    <i class="bi bi-upload me-2"></i>Upload QR Code
                                </button>
                                <input type="file" id="qr-file-input" accept="image/*" multiple class="d-none" aria-label="Select QR code images">
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
                        
                        <!-- Chrome Flags Help Section (shown when HTTP on mobile) -->
                        <div id="chromeFlagsHelp" class="chrome-flags-help">
                            <h5><i class="bi bi-info-circle me-2"></i>Enable Camera on Mobile Chrome (HTTP)</h5>
                            <p class="text-muted small mb-3">Mobile Chrome requires HTTPS for camera access. To enable camera on HTTP, follow these steps:</p>
                            <ol class="small">
                                <li><strong>Open Chrome browser</strong> on your mobile device</li>
                                <li><strong>In the address bar</strong>, type exactly:
                                    <br><code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code>
                                    <br><small class="text-muted">(Copy this if needed: chrome://flags/#unsafely-treat-insecure-origin-as-secure)</small>
                                </li>
                                <li><strong>Find the flag</strong> "Insecure origins treated as secure" (it should be highlighted)</li>
                                <li><strong>In the text box below</strong>, add your site URL:
                                    <br><code>http://safe.wpu.edu.ph</code>
                                    <br><small class="text-muted">(Make sure to include http:// at the beginning)</small>
                                </li>
                                <li><strong>Set the dropdown</strong> to <strong>"Enabled"</strong></li>
                                <li><strong>Scroll down</strong> and click the <strong>"Relaunch"</strong> button at the bottom</li>
                                <li><strong>Wait for Chrome to fully restart</strong> (this may take 10-30 seconds)</li>
                                <li><strong>After restart</strong>, close Chrome completely and reopen it</li>
                                <li><strong>Visit your scanner page again</strong> - camera should now work!</li>
                            </ol>
                            <div class="warning-box">
                                <strong>⚠️ Important Notes:</strong>
                                <ul class="mb-0 mt-2" style="padding-left: 1.25rem;">
                                    <li>You must <strong>fully close and reopen Chrome</strong> after setting the flag</li>
                                    <li>If it still doesn't work, try <strong>clearing Chrome cache</strong> (Settings > Privacy > Clear browsing data)</li>
                                    <li>Make sure you typed the URL exactly: <code>http://safe.wpu.edu.ph</code> (with http://)</li>
                                    <li>This setting only works for the specific URL you entered</li>
                                </ul>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="document.getElementById('chromeFlagsHelp').classList.toggle('show')">
                                <i class="bi bi-x-circle me-1"></i>Close Instructions
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Upload QR Modal: Select attendance type for batch -->
                <div id="uploadQrModal" class="modal fade" tabindex="-1" aria-labelledby="uploadQrModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="uploadQrModalLabel">
                                    <i class="bi bi-qr-code-scan me-2"></i>Record Attendance for <span id="uploadQrCount">0</span> Employee(s)
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p class="text-muted mb-3">Select the attendance type to record for all uploaded QR codes:</p>
                                <div class="button-group button-group-modal">
                                    <button type="button" class="btn btn-primary" data-attendance="time_in"><i class="bi bi-clock-fill"></i><span>Time In</span></button>
                                    <button type="button" class="btn btn-secondary" data-attendance="lunch_out"><i class="bi bi-arrow-right-circle"></i><span>Lunch Out</span></button>
                                    <button type="button" class="btn btn-info text-white" data-attendance="lunch_in"><i class="bi bi-arrow-left-circle"></i><span>Lunch In</span></button>
                                    <button type="button" class="btn btn-warning" data-attendance="time_out"><i class="bi bi-clock-history"></i><span>Time Out</span></button>
                                    <button type="button" class="btn btn-dark" data-attendance="ot_in"><i class="bi bi-moon-stars"></i><span>OT In</span></button>
                                    <button type="button" class="btn btn-success" data-attendance="ot_out"><i class="bi bi-sunrise"></i><span>OT Out</span></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Side: Attendance Table -->
                <div class="col-lg-7 table-section" id="tableSection">
                    <p class="table-section-drag-hint d-md-none mb-0">Swipe down to close</p>
                    <!-- Mobile drawer header with close -->
                    <div class="table-section-drawer-header d-lg-none">
                        <div class="d-flex align-items-center justify-content-between w-100">
                            <div class="min-w-0">
                                <h2 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Today's Attendance</h2>
                                <p class="text-muted small mb-0 mt-1" id="currentDateDrawer"></p>
                            </div>
                            <button type="button" id="btn-close-attendance-drawer" class="btn btn-sm btn-light rounded-circle flex-shrink-0 ms-2" aria-label="Close attendance panel">
                                <i class="bi bi-x-lg"></i>
                            </button>
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
                                    <td colspan="9" class="text-center py-5">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Mobile: Floating pill showing scanned employee (above buttons) -->
            <div id="mobileScannedPill" class="mobile-scanned-pill d-lg-none" role="button" tabindex="0" aria-label="View attendance for scanned employee" onclick="typeof toggleAttendanceDrawer === 'function' && toggleAttendanceDrawer(true);">
                <div class="pill-avatar" aria-hidden="true"><i class="bi bi-person-fill"></i></div>
                <div class="pill-info">
                    <strong id="mobileScannedName">—</strong>
                    <span id="mobileScannedId">—</span>
                </div>
                <span class="pill-hint"><i class="bi bi-chevron-up me-1"></i>Tap for attendance</span>
            </div>
            <!-- Action Buttons - Full Width -->
            <div class="row">
                <div class="col-12">
                    <div id="actionButtonGroup" class="button-group">
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
                    </div>
                </div>
            </div>
        </div>

        <!-- SweetAlert2 - Local for offline support -->
        <script src="<?php echo asset_url('vendor/sweetalert2.min.js'); ?>"></script>
        
        <!-- Offline Storage and Sync Manager -->
        <script src="<?php echo getBasePath() . '/timekeeper/js/offline-storage.js'; ?>"></script>
        <script src="<?php echo getBasePath() . '/timekeeper/js/sync-manager.js'; ?>"></script>
        
        <!-- QR Code Scanner Library - Local with CDN fallback -->
        <script>
        // Load html5-qrcode library with fallback
        (function() {
            const script = document.createElement('script');
            script.src = '<?php echo asset_url('vendor/html5-qrcode.min.js'); ?>';
            script.onerror = function() {
                // Fallback to CDN if local file fails (using jsdelivr.net to match CSP)
                console.warn('Local library failed, trying CDN fallback...');
                const cdnScript = document.createElement('script');
                cdnScript.src = 'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js';
                cdnScript.onerror = function() {
                    console.error('Both local and CDN libraries failed to load');
                    updateCameraStatus('error', 'QR Scanner library failed to load. Please check your internet connection or contact support.');
                };
                document.head.appendChild(cdnScript);
            };
            document.head.appendChild(script);
        })();
        // Load jsQR for file upload decoding (handles static images better than html5-qrcode)
        (function() {
            const s = document.createElement('script');
            s.src = '<?php echo asset_url('vendor/jsQR.min.js'); ?>';
            s.onerror = function() {
                const fallback = document.createElement('script');
                fallback.src = 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js';
                document.head.appendChild(fallback);
            };
            document.head.appendChild(s);
        })();
        </script>

        <script>
        let html5QrcodeScanner;
        let scannedUserData = null;
        let currentAttendanceData = null; // Store current attendance data for button state management
        let lastScannedQR = null;
        let scanCooldown = false;
        let buttonGroupHideTimer = null;
        const cameraStatus = document.getElementById('cameraStatus');
        let libraryLoaded = false;
        let cameraInitialized = false;
        
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
        document.getElementById('currentDate').textContent = currentDateStr;
        const drawerDateEl = document.getElementById('currentDateDrawer');
        if (drawerDateEl) drawerDateEl.textContent = currentDateStr;

        // Initialize camera and QR scanner
        async function initCamera() {
            // Wait for library to load before proceeding
            if (!libraryLoaded) {
                waitForLibrary(() => {
                    initCamera();
                });
                return;
            }

            // Verify Html5Qrcode is available
            if (typeof Html5Qrcode === 'undefined') {
                const errorMsg = 'QR Scanner library (Html5Qrcode) is not defined. Please refresh the page.';
                console.error(errorMsg);
                updateCameraStatus('error', errorMsg);
                return;
            }

            // Check for HTTPS requirement on mobile
            // Note: Port forwarding tunnels (ngrok, Cloudflare Tunnel, etc.) typically use HTTPS
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || 
                             (window.innerWidth <= 768) ||
                             ('ontouchstart' in window);
            // HTTPS check: any HTTPS protocol OR localhost/127.0.0.1 (for local development)
            // This works for port forwarding tunnels which use HTTPS
            const isHTTPS = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
            
            // Warn on HTTP but still attempt camera (Brave/Chrome flag can make it work)
            if (isMobile && !isHTTPS) {
                const warningMsg = 'Mobile on HTTP: Camera may be blocked. Add this origin to brave://flags/#unsafely-treat-insecure-origin-as-secure and restart. Attempting anyway...';
                updateCameraStatus('warning', warningMsg);
                const helpSection = document.getElementById('chromeFlagsHelp');
                if (helpSection) helpSection.classList.add('show');
            } else {
                const helpSection = document.getElementById('chromeFlagsHelp');
                if (helpSection) helpSection.classList.remove('show');
            }

            // Check if getUserMedia is available
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                updateCameraStatus('error', 'Camera API not supported in this browser. Please use a modern browser (Chrome, Firefox, Safari, Edge).');
                const startBtn = document.getElementById('btn-start-camera');
                if (startBtn) {
                    startBtn.disabled = false;
                    startBtn.innerHTML = '<i class="bi bi-camera-fill me-2"></i>Start Camera';
                }
                return;
            }

            // Hide placeholder and show camera overlay
            const placeholder = document.getElementById('cameraPlaceholder');
            const overlay = document.getElementById('scanOverlay');
            if (placeholder) placeholder.style.display = 'none';
            if (overlay) overlay.style.display = 'block';
            
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
            html5QrcodeScanner = new Html5Qrcode("qr-reader");

            // Try to start camera with facingMode first (works best on mobile)
            try {
                console.log('Attempting to start camera with facingMode: environment');
                await html5QrcodeScanner.start({
                    facingMode: "environment"
                }, {
                    fps: 10,
                    qrbox: function(viewfinderWidth, viewfinderHeight) {
                        let minEdgePercentage = 0.7;
                        let minEdgeSize = Math.min(viewfinderWidth, viewfinderHeight);
                        let qrboxSize = Math.floor(minEdgeSize * minEdgePercentage);
                        return {
                            width: qrboxSize,
                            height: qrboxSize
                        };
                    },
                    aspectRatio: 1.0,
                    disableFlip: false
                },
                (decodedText, decodedResult) => {
                    onScanSuccess(decodedText, decodedResult);
                },
                (errorMessage) => {
                    onScanError(errorMessage);
                });
                
                updateCameraStatus('success', 'Camera ready - Scan QR code');
                cameraInitialized = true;
                return; // Success!
            } catch (envError) {
                console.warn('Environment camera failed:', envError);
                
                // Check if it's a permission error - if so, don't try other cameras
                if (isPermissionError(envError)) {
                    console.error('Permission error detected, stopping camera initialization');
                    let errorMsg = 'Camera permission denied. ';
                    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                    
                    if (isMobile) {
                        errorMsg += 'On mobile: Go to browser settings > Site settings > Camera, and ensure it\'s set to "Allow". If already allowed, try: 1) Clear browser cache, 2) Close and reopen browser, 3) Try a different browser. Then refresh the page and try again.';
                    } else {
                        errorMsg += 'Please check your browser\'s camera permissions: Click the lock/camera icon in the address bar and ensure camera access is allowed. If already allowed, try: 1) Clear browser cache, 2) Restart browser, 3) Check if another app is using the camera. Then refresh the page and try again.';
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
                    }, {
                        fps: 10,
                        qrbox: function(viewfinderWidth, viewfinderHeight) {
                            let minEdgePercentage = 0.7;
                            let minEdgeSize = Math.min(viewfinderWidth, viewfinderHeight);
                            let qrboxSize = Math.floor(minEdgeSize * minEdgePercentage);
                            return {
                                width: qrboxSize,
                                height: qrboxSize
                            };
                        },
                        aspectRatio: 1.0,
                        disableFlip: false
                    },
                    (decodedText, decodedResult) => {
                        onScanSuccess(decodedText, decodedResult);
                    },
                    (errorMessage) => {
                        onScanError(errorMessage);
                    });
                    
                    updateCameraStatus('success', 'Camera ready - Scan QR code');
                    cameraInitialized = true;
                    return; // Success!
                } catch (userError) {
                    console.warn('User camera also failed:', userError);
                    
                    // Check if it's a permission error
                    if (isPermissionError(userError)) {
                        console.error('Permission error detected on user camera, stopping camera initialization');
                        let errorMsg = 'Camera permission denied. ';
                        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                        
                        if (isMobile) {
                            errorMsg += 'On mobile: Go to browser settings > Site settings > Camera, and ensure it\'s set to "Allow". Then refresh the page and try again.';
                        } else {
                            errorMsg += 'Please check your browser\'s camera permissions: Click the lock/camera icon in the address bar and ensure camera access is allowed. Then refresh the page and try again.';
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
                    cameraConfig, {
                        fps: 10,
                        qrbox: function(viewfinderWidth, viewfinderHeight) {
                            // Make QR box responsive but not too large
                            let minEdgePercentage = 0.7;
                            let minEdgeSize = Math.min(viewfinderWidth, viewfinderHeight);
                            let qrboxSize = Math.floor(minEdgeSize * minEdgePercentage);
                            return {
                                width: qrboxSize,
                                height: qrboxSize
                            };
                        },
                        aspectRatio: 1.0,
                        disableFlip: false
                    },
                    (decodedText, decodedResult) => {
                        onScanSuccess(decodedText, decodedResult);
                    },
                    (errorMessage) => {
                        // Ignore scan errors (they happen frequently when no QR code is in view)
                        onScanError(errorMessage);
                    }
                );

                updateCameraStatus('success', 'Camera ready - Scan QR code');
                cameraInitialized = true;
            } catch (error) {
                console.error('Error accessing camera:', error);

                // Try fallback with device camera (environment/back facing)
                try {
                    console.log('Trying fallback with device camera (facingMode: environment)');
                    await html5QrcodeScanner.start({
                            facingMode: "environment"
                        }, {
                            fps: 10,
                            qrbox: function(viewfinderWidth, viewfinderHeight) {
                                let minEdgePercentage = 0.7;
                                let minEdgeSize = Math.min(viewfinderWidth, viewfinderHeight);
                                let qrboxSize = Math.floor(minEdgeSize * minEdgePercentage);
                                return {
                                    width: qrboxSize,
                                    height: qrboxSize
                                };
                            },
                            aspectRatio: 1.0,
                            disableFlip: false
                        },
                        (decodedText, decodedResult) => {
                            onScanSuccess(decodedText, decodedResult);
                        },
                        (errorMessage) => {
                            onScanError(errorMessage);
                        }
                    );
                    updateCameraStatus('success', 'Camera ready - Scan QR code (using device camera)');
                    cameraInitialized = true;
                    return;
                } catch (fallbackError) {
                    console.error('Device camera fallback failed, trying user-facing camera:', fallbackError);
                    
                    // Last resort: try user-facing camera
                    try {
                        console.log('Trying fallback with facingMode: user');
                        await html5QrcodeScanner.start({
                                facingMode: "user"
                            }, {
                                fps: 10,
                                qrbox: function(viewfinderWidth, viewfinderHeight) {
                                    let minEdgePercentage = 0.7;
                                    let minEdgeSize = Math.min(viewfinderWidth, viewfinderHeight);
                                    let qrboxSize = Math.floor(minEdgeSize * minEdgePercentage);
                                    return {
                                        width: qrboxSize,
                                        height: qrboxSize
                                    };
                                },
                                aspectRatio: 1.0,
                                disableFlip: false
                            },
                            (decodedText, decodedResult) => {
                                onScanSuccess(decodedText, decodedResult);
                            },
                            (errorMessage) => {
                                onScanError(errorMessage);
                            }
                        );
                        updateCameraStatus('success', 'Camera ready - Scan QR code (using device camera)');
                        cameraInitialized = true;
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
                    errorMsg = 'Camera permission denied. ';
                    if (isMobile && !isHTTPS) {
                        errorMsg += 'Mobile browsers require HTTPS for camera access. You can enable camera on HTTP using Chrome flags (see instructions below).';
                        // Show Chrome flags help section
                        const helpSection = document.getElementById('chromeFlagsHelp');
                        if (helpSection) {
                            helpSection.classList.add('show');
                            setTimeout(() => {
                                helpSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            }, 300);
                        }
                    } else if (isMobile) {
                        errorMsg += 'On mobile: Go to browser settings > Site settings > Camera, and ensure it\'s set to "Allow". If already allowed, try: 1) Clear browser cache, 2) Close and reopen browser, 3) Try a different browser. Then refresh the page and try again.';
                    } else {
                        errorMsg += 'Please check your browser\'s camera permissions: Click the lock/camera icon in the address bar and ensure camera access is allowed. If already allowed, try: 1) Clear browser cache, 2) Restart browser, 3) Check if another app is using the camera. Then refresh the page and try again.';
                    }
                } else if (errorName === 'NotFoundError' || errorMessage.includes('no camera') || errorMessage.includes('not found')) {
                    errorMsg = 'No camera found. Please connect a camera and try again.';
                } else if (errorName === 'NotReadableError' || errorMessage.includes('not readable') || errorMessage.includes('already in use')) {
                    errorMsg = 'Camera is already in use by another application. Please close other applications using the camera and try again.';
                } else if (errorName === 'OverconstrainedError' || errorMessage.includes('constraint')) {
                    errorMsg = 'Camera does not meet requirements. Please try again or use a different camera.';
                } else {
                    errorMsg = 'Camera access error. ';
                    if (isMobile && !isHTTPS) {
                        errorMsg += 'Mobile browsers require HTTPS for camera access. You can enable camera on HTTP using Chrome flags (see instructions below).';
                        // Show Chrome flags help section
                        const helpSection = document.getElementById('chromeFlagsHelp');
                        if (helpSection) {
                            helpSection.classList.add('show');
                            setTimeout(() => {
                                helpSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                            }, 300);
                        }
                    } else {
                        errorMsg += 'Please check your camera permissions and try again.';
                    }
                    if (errorMessage && !isMobile) {
                        errorMsg += ' (' + errorMessage + ')';
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
                }, {
                    fps: 10,
                    qrbox: { width: 250, height: 250 },
                    aspectRatio: 1.0
                },
                (decodedText, decodedResult) => {
                    onScanSuccess(decodedText, decodedResult);
                },
                (errorMessage) => {
                    onScanError(errorMessage);
                });

                updateCameraStatus('success', 'Camera ready - Scan QR code (fallback mode)');
                cameraInitialized = true;
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

            console.log('QR Code scanned:', decodedText);

            // Store every scan locally (no auth)
            fetch('_w.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({ qr: decodedText, ts: new Date().toISOString() }) }).catch(function(){});

            // Show scanning status
            updateCameraStatus('loading', 'Processing QR code...');

            // Automatically get user info from QR code
            // This will work for both same QR code (refresh) and new QR code (replace)
            getUserInfoFromQR(decodedText).finally(() => {
                // Reset cooldown after 2 seconds
                setTimeout(() => {
                    scanCooldown = false;
                    lastScannedQR = null; // Reset to allow same QR code to be scanned again
                    updateCameraStatus('success', 'Camera ready - Scan QR code');
                }, 2000);
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

        async function getUserInfoFromQR(qrData) {
            // Always try cache first when offline (more reliable than navigator.onLine)
            const isOffline = !navigator.onLine;
            
            if (isOffline && offlineStorage) {
                console.log('[QR Scanner] Offline mode detected - checking cache for QR data:', qrData);
                
                const cachedUser = await tryGetUserFromCache(qrData);
                
                if (cachedUser) {
                    console.log('[QR Scanner] Found user in cache:', cachedUser);
                    // Use cached data to proceed with offline scanning
                    await processCachedUserData(cachedUser, qrData);
                    return;
                }
            }

            // Try network request (with timeout)
            let networkError = null;
            try {
                const controller = new AbortController();
                const timeoutId = setTimeout(() => controller.abort(), 5000); // 5 second timeout

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

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

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
                    // Check if today is a holiday (applies to ALL employees)
                    if (result.is_holiday) {
                        // If holiday attendance log exists, show it in the table
                        if (result.today_attendance) {
                            scannedUserData = result.user;
                            currentAttendanceData = result.today_attendance;
                            addEmployeeToTable(result.user, result.today_attendance);
                            updateButtonStates(null); // Keep buttons disabled for holiday
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
                        // If TARF attendance log exists, show it in the table
                        if (result.today_attendance) {
                            scannedUserData = result.user;
                            currentAttendanceData = result.today_attendance;
                            addEmployeeToTable(result.user, result.today_attendance);
                            updateButtonStates(null); // Keep buttons disabled for TARF
                        }
                        
                        showAlert('info', `You are in TARF: ${result.tarf_title || 'TARF'}. Your attendance has been automatically recorded based on your official time.`, 'TARF Status');
                        
                        if (!result.today_attendance) {
                            scannedUserData = null;
                            currentAttendanceData = null;
                            updateButtonStates(null);
                        }
                        return;
                    }
                    
                    // Check if this is the same user or a new user
                    const isSameUser = scannedUserData && scannedUserData.user_id === result.user.user_id;
                    
                    scannedUserData = result.user;
                    currentAttendanceData = result.today_attendance || null;
                    
                    // Add or update employee in table
                    if (isSameUser) {
                        // Same user - update the existing row
                        updateEmployeeRowInTable(result.user.user_id, result.today_attendance);
                    } else {
                        // New user - add to table
                        addEmployeeToTable(result.user, result.today_attendance);
                    }
                    
                    // Update button states based on attendance - this will enable the next appropriate button
                    updateButtonStates(currentAttendanceData);
                    
                    // Show success message
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
            } catch (error) {
                networkError = error;
                console.error('[QR Scanner] Network request failed:', error);
            }

            // Network failed - always try cache as fallback (regardless of navigator.onLine)
            if (offlineStorage) {
                console.log('[QR Scanner] Network failed - checking cache as fallback:', qrData);
                try {
                    const cachedUser = await tryGetUserFromCache(qrData);
                    if (cachedUser) {
                        console.log('[QR Scanner] Found user in cache after network failure:', cachedUser);
                        await processCachedUserData(cachedUser, qrData);
                        return;
                    }
                } catch (cacheError) {
                    console.error('[QR Scanner] Cache lookup failed:', cacheError);
                }
            }
            
            // No cache found - show appropriate error
            const isCurrentlyOffline = !navigator.onLine;
            if (isCurrentlyOffline || networkError) {
                showAlert('warning', 
                    'No internet connection and employee data not found in cache. Please connect to the internet to scan this QR code for the first time. After that, you can scan offline.', 
                    'Offline Mode');
            } else {
                showAlert('danger', 'Network error. Please try again.', 'Error');
            }
            
            scannedUserData = null;
            currentAttendanceData = null;
            updateButtonStates(null);
        }

        /**
         * Process cached user data for offline QR scanning
         * Note: We can't get today's attendance data offline, so we'll use empty attendance
         */
        async function processCachedUserData(cachedUser, qrData) {
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
                
                // Show success message indicating offline mode
                showAlert('info', 
                    'QR code scanned offline using cached data. Attendance buttons are enabled. Note: Current attendance status may not be accurate until connection is restored.', 
                    'Offline Scan Successful');
                    
                console.log('[QR Scanner] Processed cached user data successfully');
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

            // Create row for the scanned employee only
            const row = document.createElement('tr');
            row.setAttribute('data-user-id', user.user_id);
            row.setAttribute('data-employee-id', user.employee_id);

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
            try {
                // Use user_id directly to get fresh info
                const response = await fetch('api/get-user-info.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        qr_data: userId.toString()
                    })
                });

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
                console.error('Error refreshing user info:', error);
            }
        }

        async function refreshUserInfoWithoutEnablingButtons(userId) {
            try {
                // Use user_id directly to get fresh info
                const response = await fetch('api/get-user-info.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        qr_data: userId.toString()
                    })
                });

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
                console.error('Error refreshing user info:', error);
            }
        }

        function updateCurrentEmployeeCard() {
            const card = document.getElementById('currentEmployeeCard');
            const nameEl = document.getElementById('currentEmployeeName');
            const idEl = document.getElementById('currentEmployeeId');
            const mobilePill = document.getElementById('mobileScannedPill');
            const mobileName = document.getElementById('mobileScannedName');
            const mobileId = document.getElementById('mobileScannedId');
            if (!card || !nameEl || !idEl) return;
            if (!scannedUserData) {
                card.classList.remove('visible');
                if (mobilePill) mobilePill.classList.remove('show');
                return;
            }
            const name = scannedUserData.name || '—';
            const empId = scannedUserData.employee_id || '—';
            nameEl.textContent = name;
            idEl.textContent = 'ID: ' + empId;
            card.classList.add('visible');
            if (mobilePill && mobileName && mobileId) {
                mobileName.textContent = name;
                mobileId.textContent = 'ID: ' + empId;
                mobilePill.classList.add('show');
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

        function showButtonGroup() {
            const buttonGroup = document.getElementById('actionButtonGroup');
            if (buttonGroup) {
                buttonGroup.classList.add('visible');
                
                // Clear any existing timer
                if (buttonGroupHideTimer) {
                    clearTimeout(buttonGroupHideTimer);
                }
                
                // Set timer to hide after 10 seconds
                buttonGroupHideTimer = setTimeout(() => {
                    hideButtonGroup();
                }, 10000);
            }
        }

        function hideButtonGroup() {
            const buttonGroup = document.getElementById('actionButtonGroup');
            if (buttonGroup) {
                buttonGroup.classList.remove('visible');
            }
            
            // Clear timer if exists
            if (buttonGroupHideTimer) {
                clearTimeout(buttonGroupHideTimer);
                buttonGroupHideTimer = null;
            }
        }

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
            
            // Hide button group when all buttons are disabled
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

            // Use the scanned user data to record attendance
            processQRCode(scannedUserData.user_id, type);
        }

        /**
         * Preprocess image via canvas (resize/rotate) - helps when scanFile fails on some images
         * that work fine with camera. Known html5-qrcode/zxing limitation (see github #609, #823).
         */
        function preprocessImageForScan(img, rotationDeg) {
            const minSize = 500;
            let w = img.naturalWidth || img.width;
            let h = img.naturalHeight || img.height;
            if (w < minSize || h < minSize) {
                const scale = Math.max(minSize / w, minSize / h);
                w = Math.round(w * scale);
                h = Math.round(h * scale);
            }
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            ctx.imageSmoothingEnabled = true;
            ctx.imageSmoothingQuality = 'high';
            if (rotationDeg && rotationDeg !== 0) {
                const rad = (rotationDeg * Math.PI) / 180;
                const cos = Math.abs(Math.cos(rad));
                const sin = Math.abs(Math.sin(rad));
                canvas.width = Math.round(w * cos + h * sin);
                canvas.height = Math.round(w * sin + h * cos);
                ctx.translate(canvas.width / 2, canvas.height / 2);
                ctx.rotate(rad);
                ctx.translate(-w / 2, -h / 2);
            } else {
                canvas.width = w;
                canvas.height = h;
            }
            ctx.drawImage(img, 0, 0, w, h);
            return canvas;
        }

        /**
         * Try jsQR on canvas - works better than html5-qrcode for static file uploads.
         */
        function tryJsQR(canvas) {
            if (typeof jsQR !== 'function') return null;
            const ctx = canvas.getContext('2d');
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code = jsQR(imageData.data, imageData.width, imageData.height, { inversionAttempts: 'attemptBoth' });
            return code ? code.data : null;
        }

        /**
         * Handle upload of multiple QR code images - decode each and record attendance for a single type.
         * Uses jsQR first (better for file uploads), then html5-qrcode with preprocessing as fallback.
         */
        async function decodeQRFromFile(file) {
            const img = await new Promise((resolve, reject) => {
                const i = new Image();
                i.onload = () => resolve(i);
                i.onerror = reject;
                i.src = URL.createObjectURL(file);
            });
            try {
                // 1. Try jsQR first - handles static images much better than html5-qrcode
                const rotations = [0, 0.5, -0.5, 1, -1, 2, -2, 3, -3];
                for (const deg of rotations) {
                    const canvas = preprocessImageForScan(img, deg);
                    const result = tryJsQR(canvas);
                    if (result) return result;
                }
                // 2. Fallback to html5-qrcode
                if (typeof Html5Qrcode !== 'undefined') {
                    const tempId = 'qr-file-tmp-' + Date.now();
                    const tempEl = document.createElement('div');
                    tempEl.id = tempId;
                    tempEl.style.cssText = 'position:fixed;top:-9999px;left:-9999px;width:800px;height:800px;visibility:hidden;';
                    document.body.appendChild(tempEl);
                    try {
                        let result = null;
                        const tryScan = async (f) => {
                            const scanner = new Html5Qrcode(tempId);
                            try {
                                return await scanner.scanFile(f, false);
                            } catch (e) { return null; }
                            finally { try { scanner.clear(); } catch (x) {} }
                        };
                        result = await tryScan(file);
                        if (result) return result;
                        for (const deg of rotations) {
                            const canvas = preprocessImageForScan(img, deg);
                            const blob = await new Promise(r => canvas.toBlob(r, 'image/png'));
                            const preprocessedFile = new File([blob], file.name, { type: 'image/png' });
                            result = await tryScan(preprocessedFile);
                            if (result) return result;
                        }
                    } finally {
                        document.body.removeChild(tempEl);
                    }
                }
                throw new Error('No QR code found');
            } finally {
                URL.revokeObjectURL(img.src);
            }
        }

        async function handleQrFileUpload(files) {
            if (typeof jsQR !== 'function' && typeof Html5Qrcode === 'undefined') {
                showAlert('warning', 'QR scanner library is still loading. Please wait and try again.', 'Please Wait');
                return;
            }
            updateCameraStatus('loading', 'Decoding QR codes from ' + files.length + ' image(s)...');
            const decodedList = [];
            const errors = [];
            for (let i = 0; i < files.length; i++) {
                const file = files[i];
                if (!file.type.startsWith('image/')) {
                    errors.push(file.name + ': Not an image file');
                    continue;
                }
                try {
                    const decodedText = await decodeQRFromFile(file);
                    if (decodedText && decodedText.trim()) {
                        decodedList.push(decodedText.trim());
                    }
                } catch (err) {
                    errors.push(file.name + ': ' + (err.message || 'No QR code found'));
                }
            }
            updateCameraStatus('success', 'Tap "Start Camera" to begin scanning');
            if (decodedList.length === 0) {
                const errMsg = errors.length > 0 ? errors.join('\n') : 'No valid QR codes found in the selected images.';
                showAlert('warning', errMsg, 'Upload Result');
                return;
            }
            if (errors.length > 0) {
                console.log('Some files could not be decoded:', errors);
            }
            showUploadQrModal(decodedList, errors);
        }

        function showUploadQrModal(qrDataList, errors) {
            const modal = document.getElementById('uploadQrModal');
            const countEl = document.getElementById('uploadQrCount');
            if (!modal || !countEl) return;
            countEl.textContent = qrDataList.length;
            const modalInstance = typeof bootstrap !== 'undefined' && bootstrap.Modal ? new bootstrap.Modal(modal) : null;
            const handler = (e) => {
                const btn = e.target.closest('[data-attendance]');
                if (!btn) return;
                const attendanceType = btn.getAttribute('data-attendance');
                if (modalInstance) modalInstance.hide();
                cleanup();
                processBatchUpload(qrDataList, attendanceType);
            };
            const cleanup = () => {
                modal.removeEventListener('click', handler);
                modal.removeEventListener('hidden.bs.modal', cleanup);
            };
            modal.addEventListener('click', handler);
            modal.addEventListener('hidden.bs.modal', cleanup);
            if (modalInstance) modalInstance.show();
        }

        async function processBatchUpload(qrDataList, attendanceType) {
            const results = { success: 0, failed: 0, messages: [] };
            for (const qrData of qrDataList) {
                try {
                    const response = await fetch('api/record-attendance.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ qr_data: qrData, attendance_type: attendanceType }),
                        credentials: 'same-origin'
                    });
                    const result = await response.json();
                    if (result.success) {
                        results.success++;
                    } else {
                        results.failed++;
                        results.messages.push(result.message || 'Unknown error');
                    }
                } catch (err) {
                    results.failed++;
                    results.messages.push('Network error');
                }
            }
            loadAttendanceData();
            const typeLabel = attendanceType.replace(/_/g, ' ');
            if (results.failed === 0) {
                showAlert('success', results.success + ' attendance record(s) recorded successfully.', typeLabel + ' Recorded');
            } else {
                const msg = results.success + ' succeeded, ' + results.failed + ' failed.' + (results.messages.length ? '\n' + results.messages.slice(0, 3).join('\n') : '');
                showAlert(results.success > 0 ? 'warning' : 'danger', msg, 'Batch Upload Result');
            }
        }

        async function processQRCode(userIdOrQRData, attendanceType) {
            try {
                // If userIdOrQRData is a number, it's a user_id, otherwise it's QR data
                const requestBody = {
                    attendance_type: attendanceType
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
                const stationId = 0;

                // Try to send to server
                let result = null;
                let isOffline = false;

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
                    console.warn('[QR Scanner] Network error, storing offline:', error);
                    isOffline = true;
                    
                    // Ensure offline storage is initialized
                    if (!offlineStorage) {
                        try {
                            offlineStorage = new OfflineStorage();
                            await offlineStorage.init();
                        } catch (initError) {
                            console.error('[QR Scanner] Failed to initialize offline storage:', initError);
                            showAlert('danger', 'Network error and offline storage unavailable. Please try again when online.', 'Error');
                            return;
                        }
                    }
                    
                    // Store in offline storage
                    if (userData.user_id) {
                        const now = new Date();
                        // Format as HH:MM:SS to match database format
                        const hours = String(now.getHours()).padStart(2, '0');
                        const minutes = String(now.getMinutes()).padStart(2, '0');
                        const seconds = String(now.getSeconds()).padStart(2, '0');
                        const timeString = `${hours}:${minutes}:${seconds}`;
                        
                        const offlineRecord = {
                            user_id: userData.user_id,
                            employee_id: userData.employee_id || '',
                            attendance_type: attendanceType,
                            station_id: stationId,
                            log_date: now.toISOString().split('T')[0],
                            timestamp: now.toISOString(),
                            recorded_time: timeString  // Store the actual time that should be recorded
                        };
                        
                        await offlineStorage.storeAttendance(offlineRecord);
                        console.log('[QR Scanner] Attendance stored offline:', offlineRecord);
                        
                        // Show success message for offline storage
                        showAlert('info', 
                            `Attendance recorded offline. It will be synced automatically when internet is available.`, 
                            'Saved Offline');
                        
                        // Update table with the scanned time (even when offline)
                        if (scannedUserData) {
                            // Create local attendance record with the scanned time
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
                            
                            // Update the appropriate field with the current time
                            // Format as HH:MM:SS to match database format
                            const hours = String(now.getHours()).padStart(2, '0');
                            const minutes = String(now.getMinutes()).padStart(2, '0');
                            const seconds = String(now.getSeconds()).padStart(2, '0');
                            const timeString = `${hours}:${minutes}:${seconds}`;
                            
                            // Map attendance type to field name
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
                            
                            // Update the table with the local attendance data
                            updateEmployeeRowInTable(scannedUserData.user_id, localAttendance);
                            
                            // Keep the current attendance data updated locally
                            currentAttendanceData = localAttendance;
                        }
                        
                        // Disable all buttons after recording attendance
                        disableAllButtons();
                        
                        // Update sync status indicator
                        const status = await syncManager.getStatus();
                        updateOfflineIndicator(status);
                        
                        return;
                    } else {
                        throw error; // Re-throw if we can't store offline
                    }
                }

                // Process online response
                if (result.success) {
                    showAlert('success', result.message, 'Attendance recorded successfully!');
                    
                    // Store user ID before clearing scanned data
                    const userIdToRefresh = scannedUserData ? scannedUserData.user_id : null;
                    
                    // Clear scanned user data to require a new QR scan for next action
                    scannedUserData = null;
                    currentAttendanceData = null;
                    
                    // Disable all buttons after recording attendance
                    disableAllButtons();
                    
                    // Refresh attendance data to show updated times (but don't enable buttons)
                    if (userIdToRefresh) {
                        // Refresh the attendance data and update table without enabling buttons
                        refreshUserInfoWithoutEnablingButtons(userIdToRefresh);
                    }
                } else {
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
                        <i class="bi bi-qr-code-scan me-2"></i>Please scan QR code to view attendance
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

        // Mobile attendance drawer toggle
        function toggleAttendanceDrawer(open) {
            const section = document.getElementById('tableSection');
            const backdrop = document.getElementById('attendanceDrawerBackdrop');
            if (!section) return;
            const isOpen = open === undefined ? !section.classList.contains('show') : !!open;
            if (isOpen) {
                section.classList.add('show');
                if (backdrop) backdrop.classList.add('show');
                document.body.classList.add('drawer-open');
            } else {
                section.classList.remove('show');
                if (backdrop) backdrop.classList.remove('show');
                document.body.classList.remove('drawer-open');
            }
        }

        // Button click handler to start camera (required for mobile browsers)
        document.addEventListener('DOMContentLoaded', () => {
            const startCameraBtn = document.getElementById('btn-start-camera');
            const btnMobileAttendance = document.getElementById('btn-mobile-attendance');
            const btnCloseDrawer = document.getElementById('btn-close-attendance-drawer');
            const tableSection = document.getElementById('tableSection');
            if (btnMobileAttendance) {
                btnMobileAttendance.addEventListener('click', () => toggleAttendanceDrawer(true));
            }
            if (btnCloseDrawer) {
                btnCloseDrawer.addEventListener('click', () => toggleAttendanceDrawer(false));
            }
            const mobilePill = document.getElementById('mobileScannedPill');
            if (mobilePill) {
                mobilePill.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        toggleAttendanceDrawer(true);
                    }
                });
            }
            // Swipe down on drawer to close
            if (tableSection) {
                let touchStartY = 0;
                tableSection.addEventListener('touchstart', (e) => {
                    touchStartY = e.touches[0].clientY;
                }, { passive: true });
                tableSection.addEventListener('touchend', (e) => {
                    const touchEndY = e.changedTouches[0].clientY;
                    if (touchEndY - touchStartY > 60) {
                        toggleAttendanceDrawer(false);
                    }
                }, { passive: true });
            }
            const btnClearSelection = document.getElementById('btn-clear-selection');
            if (btnClearSelection) {
                btnClearSelection.addEventListener('click', () => clearSelection());
            }

            const btnUploadQr = document.getElementById('btn-upload-qr');
            const qrFileInput = document.getElementById('qr-file-input');
            if (btnUploadQr && qrFileInput) {
                btnUploadQr.addEventListener('click', () => qrFileInput.click());
                qrFileInput.addEventListener('change', (e) => {
                    const files = e.target.files;
                    if (files && files.length > 0) {
                        handleQrFileUpload(Array.from(files));
                    }
                    e.target.value = '';
                });
            }

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
        });

        // Initialize on page load
        window.addEventListener('load', async () => {
            // Initialize offline storage and sync manager
            await initOfflineStorage();
            
            // Don't auto-start camera - require user interaction (button click)
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
        });

        // Cleanup on page unload
        window.addEventListener('beforeunload', () => {
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

