<?php
/**
 * Event scanner – use at the venue to scan employees' SAFE (profile) QR codes.
 * URL: scanner.php?e=EVENT_ID&t=TOKEN (from Admin → HR Events).
 * No login required; the token authorizes check-ins for this event.
 * Design matches timekeeper/qrcode-scanner.php.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

if (!headers_sent()) {
    header_remove('Permissions-Policy');
    header('Permissions-Policy: camera=(self), microphone=(self)');
}

$eventId = isset($_GET['e']) ? (int) $_GET['e'] : 0;
$token = isset($_GET['t']) ? trim($_GET['t']) : '';
$event = null;
if ($eventId && $token !== '') {
    try {
        $database = Database::getInstance();
        $db = $database->getConnection();
        $stmt = $db->prepare("SELECT id, title, event_date, event_time, location FROM hr_events WHERE id = ? AND qr_token = ? AND is_active = 1");
        $stmt->execute([$eventId, $token]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $event = null;
    }
}

$basePath = getBasePath();
if (strpos($_SERVER['SCRIPT_NAME'] ?? '', 'HR_EVENT') !== false && strpos($basePath, '/HR_EVENT') === false) {
    $basePath = rtrim($basePath, '/') . '/HR_EVENT';
}
if (!$event) {
    header('Content-Type: text/html; charset=utf-8');
    $base = rtrim($basePath, '/');
    $loginUrl = htmlspecialchars($base . (strpos($base, 'HR_EVENT') !== false ? '/../login.php' : '/login.php'));
    $adminUrl = htmlspecialchars($base . '/admin/events.php');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Invalid scanner link</title>';
    echo '<link href="' . htmlspecialchars(asset_url('vendor/bootstrap/css/bootstrap.min.css')) . '" rel="stylesheet">';
    echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">';
    echo '<style>body{background:linear-gradient(180deg,#f0f4f8 0%,#e2e8f0 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1rem;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif}.invalid-card{max-width:420px;border-radius:16px;box-shadow:0 10px 40px rgba(0,0,0,0.1);overflow:hidden}.invalid-card .card-body{padding:2rem;text-align:center}.invalid-icon{width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,#fef2f2 0%,#fee2e2 100%);color:#dc2626;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem}.invalid-icon i{font-size:2.25rem}.invalid-card h5{color:#1e293b;font-weight:700;margin-bottom:0.5rem}.invalid-card p{color:#64748b}.invalid-card .btn{border-radius:10px;font-weight:600;padding:0.6rem 1.25rem}</style></head><body>';
    echo '<div class="card invalid-card border-0"><div class="card-body">';
    echo '<div class="invalid-icon"><i class="bi bi-link-45deg"></i></div>';
    echo '<h5>Invalid or expired scanner link</h5>';
    echo '<p class="small mb-4">This link may have expired or the event was deactivated. Get a fresh scanner link or QR code from <strong>Admin → HR Events</strong>.</p>';
    echo '<div class="d-flex flex-column gap-2">';
    echo '<a href="' . $adminUrl . '" class="btn btn-primary"><i class="bi bi-qr-code-scan me-2"></i>Open HR Events (Admin)</a>';
    echo '<a href="' . $loginUrl . '" class="btn btn-outline-secondary">Go to login</a>';
    echo '</div></div></div></body></html>';
    exit;
}

// Scanning allowed anytime – no date restriction (IN, LUNCH OUT, LUNCH IN, OUT can be scanned any time)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Permissions-Policy" content="camera=(self)">
    <title>QR Code Scanner – <?php echo htmlspecialchars($event['title']); ?></title>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
    <link href="<?php echo htmlspecialchars(asset_url('assets/css/style.css')); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars(asset_url('assets/css/timekeeper-mobile.css') . '?v=3.1.0'); ?>" rel="stylesheet">
    <link href="<?php echo htmlspecialchars($basePath . '/css/scanner.css?v=1'); ?>" rel="stylesheet">
</head>
<body class="hr-scanner-page">
    <nav class="navbar navbar-expand-lg navbar-scanner navbar-dark shadow-sm" style="margin-bottom: 0;">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo htmlspecialchars(rtrim($basePath, '/') . '/admin/events.php'); ?>">
                <i class="bi bi-qr-code-scan me-2"></i><span class="d-none d-sm-inline">HR Event Scanner</span><span class="d-inline d-sm-none">Scanner</span>
                <span class="event-badge d-none d-md-inline" title="<?php echo htmlspecialchars($event['title']); ?>"><?php echo htmlspecialchars($event['title']); ?></span>
            </a>
        </div>
    </nav>

    <div class="qrcode-page-body" style="padding-top: 0;">
        <div class="qrcode-container" style="margin-top: 0;">
            <div class="row">
                <!-- Left: Scanner -->
                <div class="col-lg-5 camera-section">
                    <h3><i class="bi bi-qr-code-scan me-2"></i><?php echo htmlspecialchars($event['title']); ?></h3>
                    <p class="event-meta mb-0">
                        <i class="bi bi-calendar3 me-1"></i><?php echo date('M j, Y', strtotime($event['event_date'])); ?>
                        <?php if (!empty($event['event_time'])): ?> &bull; <?php echo date('g:i A', strtotime($event['event_time'])); endif; ?>
                        <?php if (!empty($event['location'])): ?><br><i class="bi bi-geo-alt me-1"></i><?php echo htmlspecialchars($event['location']); endif; ?>
                    </p>
                    <p class="scan-hint mb-0"><i class="bi bi-arrow-repeat me-1"></i>Continuous scanning — no reload. Select type on the right, then scan.</p>
                    <div class="camera-wrapper">
                        <div class="scan-overlay" id="scanOverlay" style="display: none;">
                            <div id="qr-reader" style="width: 100%;"></div>
                        </div>
                        <div id="cameraPlaceholder" class="camera-placeholder-box">
                            <div class="camera-icon-wrap"><i class="bi bi-camera-video-fill"></i></div>
                            <p class="text-muted mb-2 small">Turn on the camera to scan employee SAFE QR codes</p>
                            <div id="httpWarning" class="alert alert-warning small text-start mb-3 d-none" style="max-width: 100%;">
                                <strong>Using local IP (HTTP)?</strong> Browsers block camera on HTTP. See instructions below or use manual entry.
                            </div>
                            <button id="btn-start-camera" type="button" class="btn btn-primary btn-start-camera">
                                <i class="bi bi-camera-fill me-2"></i>Start camera
                            </button>
                        </div>
                        <div class="camera-status text-muted small mt-2" id="cameraStatus"></div>
                        <p class="small mt-2 mb-0 d-none" id="cantReadLink"><a href="#" class="text-primary"><i class="bi bi-keyboard me-1"></i>Can't scan? Use manual entry below</a></p>
                        <div id="manualEntryFallback" class="manual-entry-card d-none">
                            <div class="card-title"><i class="bi bi-keyboard me-1"></i>Manual entry</div>
                            <p class="small text-muted mb-2">Type or paste Safe Employee ID, choose IN / Lunch Out / Lunch In / Out on the right, then Submit.</p>
                            <div class="input-group">
                                <input type="text" id="manualQrInput" class="form-control" placeholder="Safe Employee ID or paste QR data..." autocomplete="off" aria-label="Safe Employee ID or QR data">
                                <button type="button" id="btnManualSubmit" class="btn btn-primary">Submit</button>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Right: Check-in type + recent list -->
                <div class="col-lg-7 buttons-section">
                    <div class="scanner-steps" id="scannerSteps">
                        <span class="scanner-step" id="step1"><span class="step-num">1</span> Select check-in type</span>
                        <span class="scanner-step" id="step2"><span class="step-num">2</span> Scan QR or enter ID</span>
                    </div>
                    <h2><i class="bi bi-calendar-check me-2"></i>4-Point attendance</h2>
                    <p class="text-muted small mb-0">Tap IN, Lunch Out, Lunch In, or Out — then scan the employee's SAFE QR.</p>
                    <div class="button-group mt-3" id="checkButtons">
                        <button type="button" class="btn btn-success" id="btnInMorning" data-mode="in_morning" title="IN (morning)" aria-pressed="false">
                            <i class="bi bi-box-arrow-in-right"></i>
                            <span>IN</span>
                            <small class="opacity-75 d-block">Morning</small>
                        </button>
                        <button type="button" class="btn btn-warning" id="btnLunchOut" data-mode="out_noon" title="Lunch OUT" aria-pressed="false">
                            <i class="bi bi-box-arrow-right"></i>
                            <span>LUNCH OUT</span>
                            <small class="opacity-75 d-block">12:00 PM</small>
                        </button>
                        <button type="button" class="btn btn-success" id="btnLunchIn" data-mode="in_afternoon" title="Lunch IN" aria-pressed="false">
                            <i class="bi bi-box-arrow-in-right"></i>
                            <span>LUNCH IN</span>
                            <small class="opacity-75 d-block">1:00 PM</small>
                        </button>
                        <button type="button" class="btn btn-warning" id="btnOut" data-mode="out_afternoon" title="OUT (afternoon)" aria-pressed="false">
                            <i class="bi bi-box-arrow-right"></i>
                            <span>OUT</span>
                            <small class="opacity-75 d-block">Afternoon</small>
                        </button>
                    </div>
                    <div class="status-box" id="statusBox">
                        <span class="status-icon" id="statusIcon"><i class="bi bi-info-circle"></i></span>
                        <span id="statusText">Select a check-in type above, then scan the employee's SAFE QR code.</span>
                    </div>

                    <div class="recent-checkins-wrapper">
                        <h6><i class="bi bi-list-check me-1"></i>Recent check-ins</h6>
                        <div class="table-responsive recent-checkins-table-wrap">
                            <table class="table table-sm table-hover mb-0" id="recentCheckinsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Name</th>
                                        <th>Time</th>
                                        <th>Entry</th>
                                    </tr>
                                </thead>
                                <tbody id="recentCheckinsBody">
                                </tbody>
                            </table>
                        </div>
                        <p class="recent-checkins-empty" id="recentCheckinsEmpty">No check-ins yet. Select a type and scan to see entries here.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="resultAlert" class="alert scanner-toast d-none" role="alert" aria-live="polite"></div>

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
    document.addEventListener('DOMContentLoaded', function() {
        const EVENT_ID = <?php echo (int) $eventId; ?>;
        const TOKEN = <?php echo json_encode($token); ?>;
        let scanner = null;
        let cameraOn = false;
        let scanCooldown = false;
        const scanOverlay = document.getElementById('scanOverlay');
        const cameraPlaceholder = document.getElementById('cameraPlaceholder');
        const cameraStatus = document.getElementById('cameraStatus');
        const statusText = document.getElementById('statusText');
        const resultAlert = document.getElementById('resultAlert');
        const btnStart = document.getElementById('btn-start-camera');
        const checkButtons = document.getElementById('checkButtons');
        const btnInMorning = document.getElementById('btnInMorning');
        const btnLunchOut = document.getElementById('btnLunchOut');
        const btnLunchIn = document.getElementById('btnLunchIn');
        const btnOut = document.getElementById('btnOut');
        const recentCheckinsBody = document.getElementById('recentCheckinsBody');
        const recentCheckinsEmpty = document.getElementById('recentCheckinsEmpty');
        const manualEntryFallback = document.getElementById('manualEntryFallback');
        const manualQrInput = document.getElementById('manualQrInput');
        const btnManualSubmit = document.getElementById('btnManualSubmit');
        const statusBox = document.getElementById('statusBox');
        const statusIcon = document.getElementById('statusIcon');
        const step1El = document.getElementById('step1');
        const step2El = document.getElementById('step2');
        const CHECK_MODES = ['in_morning', 'out_noon', 'in_afternoon', 'out_afternoon'];
        let selectedCheckMode = ''; // Staff must select IN, LUNCH OUT, LUNCH IN, or OUT

        // Detect HTTP (local IP) - camera blocked unless user enables via browser flags
        const isHTTPS = location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1';
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || (window.innerWidth <= 768) || ('ontouchstart' in window);
        if (!isHTTPS) {
            const httpWarning = document.getElementById('httpWarning');
            if (httpWarning) {
                httpWarning.classList.remove('d-none');
                httpWarning.innerHTML = '<strong>Using local IP (e.g. http://' + location.hostname + ')?</strong> Browsers block camera on HTTP.<br><br>' +
                    '<strong class="text-success">You can still check in:</strong> Use <strong>Manual entry</strong> below — type or paste Safe Employee ID, select IN/LUNCH OUT/LUNCH IN/OUT, then Submit.<br><br>' +
                    (isMobile ? '<strong>Mobile:</strong> Use HTTPS or a desktop browser with flags below to enable camera.<br><br>' : '') +
                    '<strong>To enable camera (Chrome/Edge Desktop):</strong> Add <code>http://' + location.hostname + '</code> at <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code>, enable, restart.<br><br>' +
                    '<strong>Firefox (Desktop):</strong> In <code>about:config</code> set <code>media.devices.insecure.enabled</code> and <code>media.getusermedia.insecure.enabled</code> to true.';
            }
            // On HTTP, show manual entry and buttons immediately so staff can check in without camera
            manualEntryFallback.classList.remove('d-none');
            checkButtons.classList.add('visible');
            updateCheckButtonsUI();
        }

        function showCameraError(msg) {
            cameraStatus.innerHTML = msg;
            cameraStatus.className = 'camera-status small mt-2 text-danger';
            if (manualEntryFallback) manualEntryFallback.classList.remove('d-none');
            checkButtons.classList.add('visible');
            updateCheckButtonsUI();
            if (btnStart) {
                btnStart.disabled = false;
                btnStart.innerHTML = '<i class="bi bi-camera-fill me-2"></i>Try Again';
            }
        }

        function setStatus(msg, type) {
            if (statusText) statusText.textContent = msg;
            if (statusBox) {
                statusBox.className = 'status-box' + (type === 'error' ? ' status-error' : type === 'success' ? ' status-success' : '');
            }
            if (statusIcon) {
                var icon = type === 'error' ? 'bi-exclamation-triangle' : type === 'success' ? 'bi-check-circle' : 'bi-info-circle';
                statusIcon.innerHTML = '<i class="bi ' + icon + '"></i>';
            }
        }

        function showResult(success, message, name, already) {
            resultAlert.classList.remove('d-none', 'alert-success', 'alert-danger', 'show');
            resultAlert.classList.add('alert-' + (success ? 'success' : 'danger'));
            const badge = already ? ' <span class="badge bg-light text-dark ms-1">Already done</span>' : '';
            resultAlert.innerHTML = (success ? '<i class="bi bi-check-circle-fill me-2"></i>' : '<i class="bi bi-exclamation-triangle-fill me-2"></i>') +
                message + (name ? ' <strong>' + escapeHtml(name) + '</strong>' : '') + badge;
            resultAlert.classList.remove('d-none');
            requestAnimationFrame(function() { resultAlert.classList.add('show'); });
            setTimeout(function() {
                resultAlert.classList.remove('show');
                setTimeout(function() { resultAlert.classList.add('d-none'); }, 350);
            }, 4200);
        }

        function escapeHtml(s) {
            const d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function checkTypeToLabel(checkType) {
            const labels = { in_morning: 'IN', out_noon: 'Lunch Out', in_afternoon: 'Lunch In', out_afternoon: 'OUT' };
            return labels[checkType] || checkType || '—';
        }

        function addRecentCheckinRow(fullName, checkType) {
            if (!fullName || !checkType) return;
            var nameNorm = String(fullName).trim().toLowerCase();
            var rows = recentCheckinsBody.querySelectorAll('tr');
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].getAttribute('data-name') === nameNorm && rows[i].getAttribute('data-check-type') === checkType) return;
            }
            const now = new Date();
            const timeStr = now.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });
            const row = document.createElement('tr');
            row.setAttribute('data-name', nameNorm);
            row.setAttribute('data-check-type', checkType);
            row.classList.add('recent-row-new');
            const badgeClass = (checkType === 'in_morning' || checkType === 'in_afternoon') ? 'bg-success' : 'bg-warning text-dark';
            row.innerHTML = '<td>' + escapeHtml(fullName) + '</td><td>' + escapeHtml(timeStr) + '</td><td><span class="badge ' + badgeClass + '">' + escapeHtml(checkTypeToLabel(checkType)) + '</span></td>';
            recentCheckinsBody.insertBefore(row, recentCheckinsBody.firstChild);
            if (recentCheckinsEmpty) recentCheckinsEmpty.style.display = 'none';
            setTimeout(function() { row.classList.remove('recent-row-new'); }, 1800);
        }

        function updateCheckButtonsUI() {
            [btnInMorning, btnLunchOut, btnLunchIn, btnOut].forEach(function(btn) {
                if (btn) {
                    var active = btn.getAttribute('data-mode') === selectedCheckMode;
                    btn.classList.toggle('active', active);
                    btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                }
            });
            if (step1El) step1El.classList.toggle('active', !!selectedCheckMode);
            if (step2El) step2El.classList.toggle('active', !!selectedCheckMode);
        }

        const SCAN_COOLDOWN_MS = 1200; // Prevent duplicate reads from same QR and allow UI to update

        async function doCheckin(qrData) {
            if (scanCooldown) {
                setStatus('Processing previous scan... wait a moment, then try again.', 'error');
                return;
            }
            if (!CHECK_MODES.includes(selectedCheckMode)) {
                setStatus('Select IN, LUNCH OUT, LUNCH IN, or OUT first, then scan.', 'error');
                showResult(false, 'Select entry type before scanning.', null, false);
                return;
            }
            scanCooldown = true;
            setStatus('Processing...', 'success');
            try {
                const body = { event_id: EVENT_ID, token: TOKEN, qr_data: qrData, check_mode: selectedCheckMode };
                const res = await fetch('api/checkin.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    credentials: 'same-origin',
                    body: JSON.stringify(body)
                });
                const data = await res.json();
                showResult(data.success, data.message, data.employee_name || null, data.already_checked_in);
                setStatus(data.success ? 'Scan next employee QR.' : 'Try again or scan next.', data.success ? 'success' : 'error');
                if (data.success && data.employee_name && !data.already_checked_in) {
                    addRecentCheckinRow(data.employee_name, data.check_type);
                }
            } catch (err) {
                showResult(false, 'Network error. Try again.');
                setStatus('Error. Try again.', 'error');
            }
            // Brief cooldown so duplicate QR reads and rapid LUNCH IN/OUT scans are not silently dropped
            // Scanner keeps running — no reload needed for next scan
            setTimeout(function() {
                scanCooldown = false;
                setStatus('Continuous scanning — no reload needed. Select entry type and scan next QR.', '');
            }, SCAN_COOLDOWN_MS);
        }

        function onScanSuccess(decodedText) {
            try {
                doCheckin(decodedText);
            } catch (err) {
                console.error('Scan callback error:', err);
                setStatus('Scan error. Ready for next scan.', 'error');
                scanCooldown = false;
            }
        }

        document.getElementById('btn-start-camera').addEventListener('click', async function() {
            if (cameraOn) return;
            if (typeof Html5Qrcode === 'undefined') {
                setStatus('QR scanner loading... Wait and try again.', 'error');
                return;
            }
            cameraStatus.innerHTML = '';
            cameraStatus.className = 'camera-status text-muted small mt-2';
            manualEntryFallback.classList.add('d-none');
            cameraPlaceholder.style.display = 'none';
            scanOverlay.style.display = 'block';
            setStatus('Starting camera...', '');
            try {
                scanner = new Html5Qrcode('qr-reader');
                await scanner.start(
                    { facingMode: 'environment' },
                    {
                        fps: 12,
                        qrbox: function(viewfinderWidth, viewfinderHeight) {
                            var minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                            var size = Math.floor(minEdge * 0.7);
                            return { width: size, height: size };
                        },
                        aspectRatio: 1.0,
                        disableFlip: false
                    },
                    function(decodedText) { onScanSuccess(decodedText); },
                    function() {}
                );
                cameraOn = true;
                setStatus('Continuous scanning — no reload needed. Select IN, LUNCH OUT, LUNCH IN, or OUT, then scan next QR.', '');
                this.textContent = 'Camera on';
                this.disabled = true;
                checkButtons.classList.add('visible');
                updateCheckButtonsUI();
                var cantReadLink = document.getElementById('cantReadLink');
                if (cantReadLink) cantReadLink.classList.remove('d-none');
            } catch (err) {
                scanOverlay.style.display = 'none';
                cameraPlaceholder.style.display = 'block';
                setStatus('Camera error. See instructions below.', 'error');
                let errorHtml = '<strong>Camera blocked or error</strong><br><br>';
                if (!isHTTPS) {
                    if (isMobile) {
                        errorHtml += 'Mobile browsers require HTTPS for camera. Use HTTPS or a desktop browser.<br><br>';
                    } else {
                        errorHtml += 'Browsers block camera on HTTP (local IP). <strong>Enable it:</strong><br><br>';
                        errorHtml += '<strong>Chrome/Edge:</strong> Open <code>chrome://flags/#unsafely-treat-insecure-origin-as-secure</code>, add <code>http://' + location.hostname + '</code>, enable, restart.<br><br>';
                        errorHtml += '<strong>Firefox:</strong> In <code>about:config</code> set <code>media.devices.insecure.enabled</code> and <code>media.getusermedia.insecure.enabled</code> to true.<br><br>';
                    }
                }
                errorHtml += 'Or use <strong>Manual entry</strong> below to type/paste Safe Employee ID.';
                showCameraError(errorHtml);
            }
        });

        [btnInMorning, btnLunchOut, btnLunchIn, btnOut].forEach(function(btn) {
            if (btn) btn.addEventListener('click', function() {
                selectedCheckMode = this.getAttribute('data-mode');
                updateCheckButtonsUI();
            });
        });

        var cantReadLink = document.getElementById('cantReadLink');
        if (cantReadLink) {
            cantReadLink.querySelector('a').addEventListener('click', function(e) {
                e.preventDefault();
                manualEntryFallback.classList.remove('d-none');
                manualQrInput.focus();
            });
        }
        if (btnManualSubmit && manualQrInput) {
            btnManualSubmit.addEventListener('click', function() {
                const val = (manualQrInput.value || '').trim();
                if (!val) {
                    setStatus('Enter Safe Employee ID or paste QR data first.', 'error');
                    return;
                }
                if (!CHECK_MODES.includes(selectedCheckMode)) {
                    setStatus('Select IN, LUNCH OUT, LUNCH IN, or OUT first, then submit.', 'error');
                    showResult(false, 'Select entry type before submitting.', null, false);
                    return;
                }
                manualQrInput.value = '';
                doCheckin(val);
            });
            manualQrInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') btnManualSubmit.click();
            });
        }
    });
    </script>
</body>
</html>
