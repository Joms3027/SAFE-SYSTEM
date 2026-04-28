<?php
/**
 * Request Pardon - Employees submit a pardon request letter and day to be pardoned.
 * The request is sent to pardon openers assigned to the employee's department or designation.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();

// Get faculty profile
$stmt = $db->prepare("SELECT fp.employee_id, fp.department, fp.designation, u.first_name, u.last_name 
                      FROM faculty_profiles fp
                      INNER JOIN users u ON fp.user_id = u.id
                      WHERE fp.user_id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$profile || empty($profile['employee_id'])) {
    $_SESSION['error'] = 'Safe Employee ID not found. Please update your profile.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/faculty/profile.php', $basePath));
}

$employeeId = $profile['employee_id'];
$fullName = trim(($profile['first_name'] ?? '') . ' ' . ($profile['last_name'] ?? ''));
$department = trim($profile['department'] ?? '');
$designation = trim($profile['designation'] ?? '');

// Get pardon openers assigned to this employee's department or designation
$pardonOpeners = [];
if (function_exists('getOpenerUserIdsForEmployee')) {
    $openerUserIds = getOpenerUserIdsForEmployee($employeeId, $db);
    if (!empty($openerUserIds)) {
        $placeholders = implode(',', array_fill(0, count($openerUserIds), '?'));
        $stmt = $db->prepare("SELECT u.id, u.first_name, u.last_name, fp.designation as user_designation
                              FROM users u
                              LEFT JOIN faculty_profiles fp ON fp.user_id = u.id
                              WHERE u.id IN ($placeholders) AND u.is_active = 1
                              ORDER BY u.last_name, u.first_name");
        $stmt->execute($openerUserIds);
        $pardonOpeners = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Check if pardon_request_letters table exists
$tableExists = false;
$myPardonRequests = [];
try {
    $tbl = $db->query("SHOW TABLES LIKE 'pardon_request_letters'");
    $tableExists = $tbl && $tbl->rowCount() > 0;
    if ($tableExists && $employeeId) {
        $hasRejectionComment = false;
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM pardon_request_letters LIKE 'rejection_comment'");
            $hasRejectionComment = $colCheck && $colCheck->rowCount() > 0;
        } catch (Exception $e) {}
        $cols = $hasRejectionComment ? 'id, pardon_date, status, rejection_comment, created_at' : 'id, pardon_date, status, created_at';
        $stmt = $db->prepare("SELECT $cols FROM pardon_request_letters WHERE employee_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$employeeId]);
        $myPardonRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$hasRejectionComment) {
            foreach ($myPardonRequests as &$r) { $r['rejection_comment'] = null; }
            unset($r);
        }
    }
} catch (Exception $e) {}

$basePath = getBasePath();

// Build display names of pardon openers (recipients)
$pardonOpenerNames = array_map(function ($o) {
    return trim(($o['first_name'] ?? '') . ' ' . ($o['last_name'] ?? ''));
}, $pardonOpeners);
$pardonOpenerNames = array_filter($pardonOpenerNames);
$pardonOpenerNamesDisplay = !empty($pardonOpenerNames)
    ? implode(', ', $pardonOpenerNames)
    : '';

require_once '../includes/navigation.php';
include_navigation();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Pardon - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <style>
        .request-pardon-page .page-header { padding: 1.25rem 0; margin-bottom: 0.5rem; }
        .request-pardon-page .page-title { font-size: 1.5rem; font-weight: 600; color: var(--primary-blue, #003366); }
        /* Request Pardon UX enhancements */
        .pardon-how-it-works { background: linear-gradient(135deg, rgba(0, 51, 102, 0.06) 0%, rgba(0, 85, 153, 0.04) 100%); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.5rem; }
        .pardon-how-it-works h3 { font-size: 1rem; font-weight: 600; color: var(--navy-blue, #00264d); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem; }
        .pardon-steps { display: flex; flex-wrap: wrap; gap: 1rem; }
        .pardon-step { flex: 1; min-width: 140px; display: flex; align-items: flex-start; gap: 0.75rem; }
        .pardon-step-num { width: 32px; height: 32px; border-radius: 50%; background: var(--primary-blue, #003366); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 0.9rem; flex-shrink: 0; }
        .pardon-step-text { font-size: 0.9rem; color: var(--text-medium, #475569); line-height: 1.4; }
        .pardon-template-card { background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%); border: 2px dashed rgba(0, 119, 204, 0.35); border-radius: 12px; padding: 1.25rem; margin-bottom: 1.25rem; }
        .pardon-template-card .btn-download { font-weight: 600; padding: 0.6rem 1.25rem; border-radius: 10px; width: 100%; }
        @media (min-width: 768px) { .pardon-template-card .btn-download { width: auto; } }
        .pardon-file-drop { border: 2px dashed #cbd5e1; border-radius: 12px; padding: 2rem; text-align: center; background: #f8fafc; transition: all 0.2s ease; cursor: pointer; }
        .pardon-file-drop:hover, .pardon-file-drop.dragover { border-color: var(--primary-blue, #003366); background: rgba(0, 51, 102, 0.04); }
        .pardon-file-drop.has-file { border-style: solid; border-color: var(--success-color, #059669); background: rgba(5, 150, 105, 0.06); }
        .pardon-file-drop i { font-size: 2.5rem; color: var(--text-muted); margin-bottom: 0.5rem; display: block; }
        .pardon-file-drop.has-file i { color: var(--success-color, #059669); }
        .pardon-file-drop .file-list { margin-top: 0.5rem; text-align: left; }
        .pardon-file-drop .file-list-item { font-size: 0.9rem; color: var(--text-dark); word-break: break-all; display: flex; align-items: center; gap: 0.5rem; padding: 0.25rem 0; }
        .pardon-file-drop .file-list-item i { font-size: 0.9rem; color: var(--success-color, #059669); }
        .pardon-file-drop .file-hint { font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem; }
        .pardon-toast { position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%) translateY(100px); z-index: 9999; min-width: 280px; max-width: 90vw; padding: 1rem 1.25rem; border-radius: 12px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); display: flex; align-items: center; gap: 0.75rem; opacity: 0; transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1); }
        @media (max-width: 991px) { .pardon-toast { bottom: calc(72px + 1rem); } }
        .pardon-toast.show { transform: translateX(-50%) translateY(0); opacity: 1; }
        .pardon-toast.success { background: #059669; color: #fff; }
        .pardon-toast.error { background: #dc2626; color: #fff; }
        .pardon-toast i { font-size: 1.5rem; flex-shrink: 0; }
        .pardon-form-actions { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-top: 1.5rem; }
        .pardon-form-actions .btn { padding: 0.6rem 1.25rem; font-weight: 600; border-radius: 10px; }
        @media (max-width: 767px) { .pardon-steps { flex-direction: column; } .pardon-file-drop { padding: 1.5rem; } }
        .pardon-status-list .pardon-status-item { border-left: 4px solid #6c757d; }
        .pardon-status-list .pardon-status-item.rejected { border-left-color: #dc3545; background: linear-gradient(to right, #fef2f2 0%, #fff 10%); }
        .pardon-status-list .pardon-status-item.opened { border-left-color: #198754; }
        .pardon-status-list .pardon-status-item.pending { border-left-color: #ffc107; }
        .pardon-rejection-comment { background: #fff5f5; border: 1px solid #fecaca; border-radius: 8px; padding: 0.75rem 1rem; margin-top: 0.5rem; font-size: 0.9rem; }
    </style>
</head>
<body class="layout-faculty request-pardon-page">
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header">
                    <h1 class="page-title"><i class="fas fa-file-signature me-2"></i>Request Pardon</h1>
                    <p class="text-muted mb-0">Submit a pardon request letter and the day you wish to be pardoned. Your request will be sent to those assigned to your department or designation.</p>
                    <?php if (!empty($pardonOpenerNamesDisplay)): ?>
                    <p class="mb-0 mt-2"><strong>Request will be sent to:</strong> <span class="text-primary"><?php echo htmlspecialchars($pardonOpenerNamesDisplay); ?></span></p>
                    <?php endif; ?>
                </div>

                <?php displayMessage(); ?>

                <?php if ($tableExists && !empty($myPardonRequests)): ?>
                    <div class="card shadow-sm mb-4">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Current Pardon Request</h5>
                        </div>
                        <div class="card-body">
                            <div class="pardon-status-list">
                                <?php foreach ($myPardonRequests as $pr): 
                                    $status = $pr['status'] ?? 'pending';
                                    $statusBadge = $status === 'opened' ? 'success' : ($status === 'rejected' ? 'danger' : ($status === 'closed' ? 'secondary' : ($status === 'acknowledged' ? 'info' : 'warning')));
                                    $rejectionComment = trim($pr['rejection_comment'] ?? '');
                                ?>
                                    <div class="pardon-status-item <?php echo htmlspecialchars($status); ?> rounded p-3 mb-2">
                                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                            <div>
                                                <strong><i class="fas fa-calendar me-1"></i><?php echo date('F j, Y', strtotime($pr['pardon_date'])); ?></strong>
                                                <span class="badge bg-<?php echo $statusBadge; ?> ms-2"><?php echo ucfirst($status); ?></span>
                                            </div>
                                            <span class="text-muted small"><?php echo date('M j, Y g:i A', strtotime($pr['created_at'])); ?></span>
                                        </div>
                                        <?php if ($status === 'rejected' && $rejectionComment !== ''): ?>
                                            <div class="pardon-rejection-comment mt-2">
                                                <strong><i class="fas fa-comment-alt me-1"></i>Reason for rejection:</strong>
                                                <div class="mt-1"><?php echo nl2br(htmlspecialchars($rejectionComment)); ?></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!$tableExists): ?>
                    <div class="alert alert-warning d-flex align-items-start gap-3">
                        <i class="fas fa-exclamation-triangle mt-1" style="font-size: 1.25rem;"></i>
                        <div class="flex-grow-1">
                            <strong>Feature not yet available</strong>
                            <p class="mb-2 mt-1">The pardon request letters feature is not yet set up. Please contact the administrator to run the database migration.</p>
                            <a href="<?php echo htmlspecialchars(clean_url($basePath . '/faculty/dashboard.php', $basePath)); ?>" class="btn btn-outline-warning btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                        </div>
                    </div>
                <?php elseif (empty($pardonOpeners)): ?>
                    <div class="alert alert-info d-flex align-items-start gap-3">
                        <i class="fas fa-info-circle mt-1" style="font-size: 1.25rem;"></i>
                        <div class="flex-grow-1">
                            <strong>No pardon openers assigned</strong>
                            <p class="mb-2 mt-1">No pardon openers are assigned to your department (<strong><?php echo htmlspecialchars($department ?: '—'); ?></strong>) or designation (<strong><?php echo htmlspecialchars($designation ?: '—'); ?></strong>). Please contact HR to configure pardon opener assignments in Settings → Pardon Openers.</p>
                            <a href="<?php echo htmlspecialchars(clean_url($basePath . '/faculty/dashboard.php', $basePath)); ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- How it works -->
                    <div class="pardon-how-it-works">
                        <h3><i class="fas fa-lightbulb text-warning"></i> How it works</h3>
                        <div class="pardon-steps">
                            <div class="pardon-step">
                                <span class="pardon-step-num">1</span>
                                <span class="pardon-step-text">Download the template and fill it out with your pardon request details.</span>
                            </div>
                            <div class="pardon-step">
                                <span class="pardon-step-num">2</span>
                                <span class="pardon-step-text">Select the date you wish to be pardoned and upload your completed letter.</span>
                            </div>
                            <div class="pardon-step">
                                <span class="pardon-step-num">3</span>
                                <span class="pardon-step-text">Submit your request. The pardon openers shown will receive and review it.</span>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <div class="card shadow-sm mb-4">
                                <div class="card-header bg-light">
                                    <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Submit Pardon Request</h5>
                                </div>
                                <div class="card-body">
                                    <form id="pardonRequestForm" enctype="multipart/form-data">
                                        <!-- Template download - prominent -->
                                        <div class="pardon-template-card">
                                            <div class="d-flex flex-column flex-md-row flex-wrap align-items-start align-items-md-center gap-3">
                                                <i class="fas fa-file-pdf text-danger" style="font-size: 1.5rem;" aria-hidden="true"></i>
                                                <div class="flex-grow-1">
                                                    <strong>Need the template?</strong>
                                                    <p class="mb-0 small text-muted">Download the official pardon request template, fill it out, then upload it below.</p>
                                                </div>
                                                <a href="<?php echo htmlspecialchars(asset_url('Request for Pardon Template.docx', true)); ?>" class="btn btn-primary btn-download" download>
                                                    <i class="fas fa-download me-2"></i>Download Template
                                                </a>
                                            </div>
                                        </div>

                                        <div class="mb-4">
                                            <label for="pardon_date" class="form-label fw-semibold">Day to be Pardoned <span class="text-danger">*</span></label>
                                            <input type="date" class="form-control form-control-lg" id="pardon_date" name="pardon_date" required
                                                   aria-describedby="pardon_date_help">
                                            <div id="pardon_date_help" class="form-text">Select the date you are requesting pardon for.</div>
                                        </div>

                                        <div class="mb-4">
                                            <label class="form-label fw-semibold">Request Letter <span class="text-danger">*</span></label>
                                            <div class="pardon-file-drop" id="fileDropZone" role="button" tabindex="0"
                                                 aria-label="Click or drag files to upload pardon request letter">
                                                <input type="file" class="d-none" id="request_letter_file" name="request_letter_file[]" multiple required
                                                       accept=".pdf,application/pdf" aria-label="Upload pardon request letter">
                                                <i class="fas fa-cloud-upload-alt" id="fileDropIcon"></i>
                                                <div id="fileDropText">Click or drag your files here</div>
                                                <div class="file-list" id="fileDropFileList" style="display: none;"></div>
                                                <div class="file-hint" id="fileDropHint">PDF only (.pdf). Up to 10 files.</div>
                                            </div>
                                        </div>

                                        <div class="pardon-form-actions">
                                            <button type="submit" class="btn btn-primary" id="submitBtn">
                                                <i class="fas fa-paper-plane me-2"></i>Submit Request
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" id="resetBtn">
                                                <i class="fas fa-undo me-2"></i>Reset
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Toast container for feedback -->
                    <div id="pardonToast" class="pardon-toast" role="alert" aria-live="polite"></div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script>
    (function() {
        var form = document.getElementById('pardonRequestForm');
        var fileDropZone = document.getElementById('fileDropZone');
        var fileInput = document.getElementById('request_letter_file');
        var resetBtn = document.getElementById('resetBtn');

        function showToast(message, type) {
            var toast = document.getElementById('pardonToast');
            if (!toast) return;
            toast.className = 'pardon-toast show ' + (type || 'success');
            toast.innerHTML = (type === 'error' ? '<i class="fas fa-exclamation-circle"></i>' : '<i class="fas fa-check-circle"></i>') + '<span>' + (message || 'Done') + '</span>';
            toast.setAttribute('aria-live', 'polite');
            setTimeout(function() {
                toast.classList.remove('show');
            }, 4500);
        }

        function updateFileDropZone() {
            if (!fileDropZone || !fileInput) return;
            var files = fileInput.files;
            var listEl = document.getElementById('fileDropFileList');
            if (files && files.length > 0) {
                fileDropZone.classList.add('has-file');
                fileDropZone.querySelector('#fileDropIcon').className = 'fas fa-file-alt';
                var html = '';
                for (var i = 0; i < files.length; i++) {
                    html += '<div class="file-list-item"><i class="fas fa-file-alt"></i>' + escapeHtml(files[i].name) + '</div>';
                }
                listEl.innerHTML = html;
                listEl.style.display = 'block';
                fileDropZone.querySelector('#fileDropText').style.display = 'none';
            } else {
                fileDropZone.classList.remove('has-file');
                fileDropZone.querySelector('#fileDropIcon').className = 'fas fa-cloud-upload-alt';
                listEl.style.display = 'none';
                listEl.innerHTML = '';
                fileDropZone.querySelector('#fileDropText').style.display = '';
            }
        }
        function escapeHtml(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

        if (fileDropZone && fileInput) {
            fileDropZone.addEventListener('click', function() { fileInput.click(); });
            fileDropZone.addEventListener('dragover', function(e) { e.preventDefault(); fileDropZone.classList.add('dragover'); });
            fileDropZone.addEventListener('dragleave', function() { fileDropZone.classList.remove('dragover'); });
            fileDropZone.addEventListener('drop', function(e) {
                e.preventDefault();
                fileDropZone.classList.remove('dragover');
                if (e.dataTransfer.files.length) {
                    fileInput.files = e.dataTransfer.files;
                    updateFileDropZone();
                }
            });
            fileInput.addEventListener('change', updateFileDropZone);
            fileDropZone.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
            });
        }

        if (resetBtn && form) {
            resetBtn.addEventListener('click', function() {
                form.reset();
                updateFileDropZone();
            });
        }

        if (!form) return;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('submitBtn');
            var pardonDate = document.getElementById('pardon_date').value.trim();

            if (!pardonDate) {
                showToast('Please select the day you want to be pardoned.', 'error');
                document.getElementById('pardon_date').focus();
                return;
            }
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                showToast('Please upload at least one pardon request letter file.', 'error');
                if (fileDropZone) fileDropZone.focus();
                return;
            }
            for (var j = 0; j < fileInput.files.length; j++) {
                var fn = fileInput.files[j].name.toLowerCase();
                if (!fn.endsWith('.pdf')) {
                    showToast('Only PDF files are accepted. Please upload PDF documents only.', 'error');
                    if (fileDropZone) fileDropZone.focus();
                    return;
                }
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';

            var fd = new FormData(form);

            fetch('<?php echo addslashes(clean_url($basePath . '/faculty/submit_pardon_request_letter_api.php', $basePath)); ?>', {
                method: 'POST',
                body: fd
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    showToast(data.message || 'Your pardon request has been submitted successfully.');
                    form.reset();
                    updateFileDropZone();
                } else {
                    showToast(data.message || 'Failed to submit request. Please try again.', 'error');
                }
            })
            .catch(function() {
                showToast('Request failed. Please try again.', 'error');
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Submit Request';
            });
        });
    })();
    </script>
</body>
</html>
