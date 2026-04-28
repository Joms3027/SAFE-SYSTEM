<?php
/**
 * Pardon Request Letters - For pardon openers to view incoming pardon requests (letter + day).
 * Shows requests from employees in their department or designation scope.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAuth();

$database = Database::getInstance();
$db = $database->getConnection();

// Only faculty/staff with pardon opener assignments can access
if (!isFaculty() && !isStaff()) {
    $_SESSION['error'] = 'Access denied.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/admin/dashboard.php', $basePath));
}

if (!hasPardonOpenerAssignments($_SESSION['user_id'], $db)) {
    $_SESSION['error'] = 'You do not have pardon opener assignments. This page is for viewing pardon request letters from employees in your scope.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/faculty/dashboard.php', $basePath));
}

// Get employee IDs in this user's scope
$employeeIdsInScope = getEmployeeIdsInScope($_SESSION['user_id'], $db);

// Check if table exists
$tableExists = false;
try {
    $tbl = $db->query("SHOW TABLES LIKE 'pardon_request_letters'");
    $tableExists = $tbl && $tbl->rowCount() > 0;
} catch (Exception $e) {}

$requests = [];
$historyRequests = [];
if ($tableExists && !empty($employeeIdsInScope)) {
    $placeholders = implode(',', array_fill(0, count($employeeIdsInScope), '?'));
    $stmt = $db->prepare("SELECT * FROM pardon_request_letters 
                         WHERE employee_id IN ($placeholders) 
                         ORDER BY created_at DESC 
                         LIMIT 100");
    $stmt->execute($employeeIdsInScope);
    $allRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allRequests as $req) {
        $status = $req['status'] ?? 'pending';
        if (in_array($status, ['opened', 'rejected', 'closed'])) {
            $historyRequests[] = $req;
        } else {
            $requests[] = $req;
        }
    }
}

$basePath = getBasePath();
require_once '../includes/navigation.php';
include_navigation();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pardon Request Letters - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/pardon-requests-mobile.css', true); ?>" rel="stylesheet">
    <style>
        .letter-content { white-space: pre-wrap; background: #f8f9fa; padding: 0.5rem 0.75rem; border-radius: 6px; font-size: 0.875rem; max-height: 4.5rem; overflow: auto; }
        .letter-requests-table thead th { font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.03em; color: #6c757d; font-weight: 600; white-space: nowrap; }
        .letter-requests-table tbody td { vertical-align: middle; }
        .letter-requests-table .letter-cell { min-width: 10rem; max-width: 22rem; }
    </style>
</head>
<body class="layout-faculty">
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <h1 class="h3 mb-1"><i class="fas fa-envelope-open-text me-2 text-primary"></i>Pardon Request Letters</h1>
                        <p class="text-muted mb-0">Pardon requests (letter + day) from employees in your scope. Open pardon for them in My Assigned Employees when ready.</p>
                    </div>
                    <?php if ($tableExists): ?>
                    <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#historyModal">
                        <i class="fas fa-history me-1"></i>View History<?php if (!empty($historyRequests)): ?> <span class="badge bg-secondary"><?php echo count($historyRequests); ?></span><?php endif; ?>
                    </button>
                    <?php endif; ?>
                </div>

                <?php displayMessage(); ?>

                <?php if (!$tableExists): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        The pardon request letters feature is not yet set up.
                    </div>
                <?php elseif (empty($requests)): ?>
                    <div class="card shadow-sm">
                        <div class="card-body text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                            <h5><?php echo !empty($historyRequests) ? 'No Pending Pardon Requests' : 'No Pardon Request Letters'; ?></h5>
                            <p class="text-muted mb-0"><?php echo !empty($historyRequests) ? 'All requests have been processed. View approved and rejected requests in History.' : 'No employees in your scope have submitted pardon request letters yet.'; ?></p>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card shadow-sm border-0 overflow-hidden">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0 letter-requests-table">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">Employee</th>
                                        <th scope="col" class="d-none d-md-table-cell">Department</th>
                                        <th scope="col">Pardon date</th>
                                        <th scope="col">Letter</th>
                                        <th scope="col" class="d-none d-lg-table-cell">Submitted</th>
                                        <th scope="col">Status</th>
                                        <th scope="col" class="text-end">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($requests as $req): 
                                    $fullName = trim(($req['employee_first_name'] ?? '') . ' ' . ($req['employee_last_name'] ?? ''));
                                    $fullName = $fullName ?: 'Employee';
                                    $statusClass = $req['status'] ?? 'pending';
                                    $statusBadge = $statusClass === 'opened' ? 'success' : ($statusClass === 'acknowledged' ? 'info' : ($statusClass === 'rejected' ? 'danger' : ($statusClass === 'closed' ? 'secondary' : 'warning')));
                                    $rowBorder = $statusClass === 'opened' ? 'success' : ($statusClass === 'acknowledged' ? 'info' : ($statusClass === 'rejected' ? 'danger' : ($statusClass === 'closed' ? 'secondary' : 'warning')));
                                    $letter = $req['request_letter'] ?? '';
                                    $letterPaths = [];
                                    $decoded = @json_decode($letter, true);
                                    if (is_array($decoded)) {
                                        $letterPaths = $decoded;
                                    } elseif ($letter && (strpos($letter, 'pardon_letters/') === 0 || strpos($letter, 'uploads/') === 0 || preg_match('/\.(pdf|doc|docx)$/i', $letter))) {
                                        $letterPaths = [$letter];
                                    }
                                    $baseUpload = rtrim($basePath, '/') . '/uploads/';
                                ?>
                                    <tr>
                                        <td class="border-start border-4 border-<?php echo $rowBorder; ?>">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($fullName); ?></div>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($req['employee_id']); ?></span>
                                            <div class="d-md-none small text-muted mt-1">
                                                <?php if (!empty($req['employee_department'])): ?><?php echo htmlspecialchars($req['employee_department']); ?> · <?php endif; ?>
                                                <?php echo date('M j, Y g:i A', strtotime($req['created_at'])); ?>
                                            </div>
                                        </td>
                                        <td class="d-none d-md-table-cell"><?php echo !empty($req['employee_department']) ? htmlspecialchars($req['employee_department']) : '—'; ?></td>
                                        <td class="text-nowrap"><?php echo date('M j, Y', strtotime($req['pardon_date'])); ?></td>
                                        <td class="letter-cell">
                                            <?php if (!empty($letterPaths)): ?>
                                                <?php foreach ($letterPaths as $path):
                                                    $downloadUrl = $baseUpload . ltrim($path, '/');
                                                    $label = basename($path);
                                                ?>
                                                    <a href="<?php echo htmlspecialchars($downloadUrl); ?>" class="btn btn-sm btn-outline-primary d-inline-flex align-items-center mb-1 me-1" target="_blank" rel="noopener" title="<?php echo htmlspecialchars($label); ?>">
                                                        <i class="fas fa-file-download me-1"></i><span class="text-truncate" style="max-width: 9rem;"><?php echo htmlspecialchars($label); ?></span>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="letter-content mb-0"><?php echo htmlspecialchars($letter); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="d-none d-lg-table-cell small text-muted text-nowrap"><?php echo date('M j, Y g:i A', strtotime($req['created_at'])); ?></td>
                                        <td><span class="badge bg-<?php echo $statusBadge; ?>"><?php echo ucfirst($statusClass); ?></span></td>
                                        <td class="text-end">
                                            <div class="d-flex flex-column flex-xl-row gap-1 justify-content-xl-end align-items-xl-center">
                                                <?php 
                                                $canOpen = in_array($req['status'] ?? '', ['pending', 'acknowledged']);
                                                if ($canOpen): ?>
                                                <button type="button" class="btn btn-sm btn-primary open-pardon-btn" 
                                                    data-employee-id="<?php echo htmlspecialchars($req['employee_id']); ?>" 
                                                    data-pardon-date="<?php echo htmlspecialchars($req['pardon_date']); ?>"
                                                    data-request-id="<?php echo (int)$req['id']; ?>">
                                                    <i class="fas fa-user-shield me-1"></i><span class="d-none d-xl-inline">Open Pardon to My Assigned Employee</span><span class="d-xl-none">Open pardon</span>
                                                </button>
                                                <?php elseif (($req['status'] ?? '') === 'opened'): ?>
                                                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Opened</span>
                                                <?php endif; ?>
                                                <?php if (($req['status'] ?? '') === 'pending'): ?>
                                                <button type="button" class="btn btn-sm btn-outline-danger reject-btn" data-id="<?php echo (int)$req['id']; ?>">
                                                    <i class="fas fa-times me-1"></i>Reject
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Reject Pardon Modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectModalLabel"><i class="fas fa-times-circle text-danger me-2"></i>Reject Pardon Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3">Please provide a reason for rejecting this pardon request. The employee will see this comment.</p>
                    <div class="mb-0">
                        <label for="rejectComment" class="form-label">Comment / Reason <span class="text-muted">(optional but recommended)</span></label>
                        <textarea class="form-control" id="rejectComment" name="reject_comment" rows="4" placeholder="e.g., Incomplete documentation, Letter does not meet requirements..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="rejectConfirmBtn">
                        <i class="fas fa-times me-1"></i>Reject Request
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- History Modal -->
    <div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="historyModalLabel"><i class="fas fa-history me-2"></i>Pardon Request History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($historyRequests)): ?>
                        <p class="text-muted mb-0">No approved or rejected requests yet.</p>
                    <?php else: ?>
                        <div class="pardon-history-list">
                            <?php foreach ($historyRequests as $req): 
                                $fullName = trim(($req['employee_first_name'] ?? '') . ' ' . ($req['employee_last_name'] ?? ''));
                                $fullName = $fullName ?: 'Employee';
                                $statusClass = $req['status'] ?? '';
                                $isApproved = ($statusClass === 'opened' || $statusClass === 'closed');
                                $isRejected = ($statusClass === 'rejected');
                            ?>
                                <div class="pardon-history-item <?php echo $isApproved ? 'approved' : ($isRejected ? 'rejected' : ''); ?>">
                                    <div class="pardon-history-header">
                                        <div>
                                            <strong><?php echo htmlspecialchars($fullName); ?></strong>
                                            <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($req['employee_id']); ?></span>
                                        </div>
                                        <span class="badge bg-<?php 
                                            echo $statusClass === 'opened' ? 'success' : ($statusClass === 'closed' ? 'secondary' : ($statusClass === 'rejected' ? 'danger' : 'secondary')); 
                                        ?>"><?php echo ucfirst($statusClass); ?></span>
                                    </div>
                                    <div class="pardon-history-details">
                                        <div>
                                            <strong><i class="fas fa-calendar me-1"></i>Day:</strong>
                                            <?php echo date('F j, Y', strtotime($req['pardon_date'])); ?>
                                        </div>
                                        <div>
                                            <strong><i class="fas fa-clock me-1"></i>Submitted:</strong>
                                            <?php echo date('M j, Y g:i A', strtotime($req['created_at'])); ?>
                                        </div>
                                        <?php if (!empty($req['employee_department'])): ?>
                                        <div>
                                            <strong><i class="fas fa-building me-1"></i>Dept:</strong>
                                            <?php echo htmlspecialchars($req['employee_department']); ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (($statusClass === 'rejected') && !empty($req['rejection_comment'])): ?>
                                        <div class="mt-2">
                                            <strong><i class="fas fa-comment-alt me-1"></i>Rejection reason:</strong>
                                            <div class="small text-muted mt-1"><?php echo nl2br(htmlspecialchars($req['rejection_comment'])); ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="mt-2">
                                        <?php 
                                        $letter = $req['request_letter'] ?? '';
                                        $letterPaths = [];
                                        $decoded = @json_decode($letter, true);
                                        if (is_array($decoded)) {
                                            $letterPaths = $decoded;
                                        } elseif ($letter && (strpos($letter, 'pardon_letters/') === 0 || strpos($letter, 'uploads/') === 0 || preg_match('/\.(pdf|doc|docx)$/i', $letter))) {
                                            $letterPaths = [$letter];
                                        }
                                        if (!empty($letterPaths)): 
                                            $baseUpload = rtrim($basePath, '/') . '/uploads/';
                                            foreach ($letterPaths as $path):
                                                $downloadUrl = $baseUpload . ltrim($path, '/');
                                                $label = basename($path);
                                        ?>
                                            <a href="<?php echo htmlspecialchars($downloadUrl); ?>" class="btn btn-sm btn-outline-primary me-1 mb-1" target="_blank" rel="noopener">
                                                <i class="fas fa-download me-1"></i><?php echo htmlspecialchars($label); ?>
                                            </a>
                                        <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="letter-content small"><?php echo htmlspecialchars(mb_substr($letter, 0, 200)); ?><?php echo mb_strlen($letter) > 200 ? '…' : ''; ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <script>
    var openPardonUrl = '<?php echo addslashes(clean_url($basePath . '/faculty/open_pardon_api.php', $basePath)); ?>';
    document.querySelectorAll('.open-pardon-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var employeeId = this.getAttribute('data-employee-id');
            var pardonDate = this.getAttribute('data-pardon-date');
            var requestId = this.getAttribute('data-request-id') || '';
            if (!employeeId || !pardonDate) return;
            var btnEl = this;
            btnEl.disabled = true;
            var fd = new FormData();
            fd.append('employee_id', employeeId);
            fd.append('log_date', pardonDate);
            if (requestId) fd.append('request_id', requestId);
            fetch(openPardonUrl, { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.message || 'Failed to open pardon');
                        btnEl.disabled = false;
                    }
                })
                .catch(function() {
                    alert('Request failed. Please try again.');
                    btnEl.disabled = false;
                });
        });
    });
    var rejectModal, rejectCommentEl, rejectConfirmBtn, pendingRejectId, pendingRejectBtn;
    document.querySelectorAll('.reject-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            pendingRejectId = this.getAttribute('data-id');
            pendingRejectBtn = this;
            rejectModal = new bootstrap.Modal(document.getElementById('rejectModal'));
            rejectCommentEl = document.getElementById('rejectComment');
            rejectConfirmBtn = document.getElementById('rejectConfirmBtn');
            if (rejectCommentEl) rejectCommentEl.value = '';
            rejectModal.show();
        });
    });
    document.getElementById('rejectConfirmBtn').addEventListener('click', function() {
        if (!pendingRejectId) return;
        var comment = (rejectCommentEl && rejectCommentEl.value) ? rejectCommentEl.value.trim() : '';
        rejectConfirmBtn.disabled = true;
        var fd = new FormData();
        fd.append('request_id', pendingRejectId);
        if (comment) fd.append('rejection_comment', comment);
        fetch('<?php echo addslashes(clean_url($basePath . '/faculty/reject_pardon_request_letter_api.php', $basePath)); ?>', {
            method: 'POST',
            body: fd
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) {
                rejectModal.hide();
                window.location.reload();
            } else {
                rejectModal.hide();
                alert(data.message || 'Failed to reject');
                if (pendingRejectBtn) pendingRejectBtn.disabled = false;
            }
        })
        .catch(function() {
            alert('Request failed');
            if (pendingRejectBtn) pendingRejectBtn.disabled = false;
        })
        .finally(function() {
            rejectConfirmBtn.disabled = false;
        });
    });
    </script>
</body>
</html>
