<?php
/**
 * TARF / NTARF — Budget or Accounting fund endorsement (parallel with supervisor and applicable endorser).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/tarf_workflow.php';
require_once __DIR__ . '/../includes/tarf_form_options.php';

requireAuth();

if (!isFaculty() && !isStaff()) {
    $_SESSION['error'] = 'Access denied.';
    redirect(clean_url(getBasePath() . '/faculty/dashboard.php', getBasePath()));
}

$database = Database::getInstance();
$db = $database->getConnection();
$uid = (int) $_SESSION['user_id'];

if (!tarf_user_holds_fund_availability_designation($uid, $db)) {
    $_SESSION['error'] = 'This page is for accounts designated as University Budget Office or Officer in Charge University Accountant.';
    redirect(clean_url(getBasePath() . '/faculty/dashboard.php', getBasePath()));
}

$tarfOpts = tarf_get_form_options();

$pending = [];
$tableOk = false;
try {
    $tbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
    $tableOk = $tbl && $tbl->rowCount() > 0;
    $fc = $db->query("SHOW COLUMNS FROM tarf_requests LIKE " . $db->quote('fund_availability_target_user_id'));
    if ($tableOk && $fc && $fc->rowCount() > 0) {
        $st = $db->prepare(
            "SELECT tr.*, u.first_name, u.last_name, fp.department
             FROM tarf_requests tr
             INNER JOIN users u ON u.id = tr.user_id
             LEFT JOIN faculty_profiles fp ON fp.user_id = tr.user_id
             WHERE tr.status IN ('pending_joint','pending_supervisor','pending_endorser')
             AND tr.fund_availability_target_user_id = ?
             AND tr.fund_availability_target_user_id IS NOT NULL
             AND tr.fund_availability_endorsed_at IS NULL
             ORDER BY tr.created_at ASC, tr.id ASC"
        );
        $st->execute([$uid]);
        $pending = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $pending = [];
}

$basePath = getBasePath();
require_once __DIR__ . '/../includes/navigation.php';
include_navigation();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TARF / NTARF — Budget and Accounting</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
</head>
<body class="layout-faculty">
    <div class="container-fluid py-3">
        <main class="main-content">
            <h1 class="h4 mb-3"><i class="fas fa-coins text-primary me-2"></i>TARF / NTARF — Fund availability</h1>
            <p class="text-muted">Requests appear here when the employee chose Budget (Fund 101/164) or Accounting (Fund 184) and your profile matches that office. Supervisor and applicable endorser may endorse at the same time.</p>
            <?php displayMessage(); ?>

            <?php if (!$tableOk): ?>
                <div class="alert alert-warning">TARF table not found.</div>
            <?php elseif (empty($pending)): ?>
                <div class="card shadow-sm"><div class="card-body text-center text-muted py-5">No requests pending your fund-availability endorsement.</div></div>
            <?php else: ?>
                <div class="table-responsive card shadow-sm">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light"><tr><th>ID</th><th>Requester</th><th>Department</th><th>Fund route</th><th>Submitted</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($pending as $r):
                                $fd = json_decode($r['form_data'], true);
                                $fk = is_array($fd) ? trim((string) ($fd['endorser_fund_availability'] ?? '')) : '';
                                $fundLab = ($fk !== '' && isset($tarfOpts['fund_endorser_role'][$fk]))
                                    ? $tarfOpts['fund_endorser_role'][$fk] : ($fk !== '' ? $fk : '—');
                                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                                $viewUrl = clean_url($basePath . '/faculty/tarf_request_view.php?id=' . (int) $r['id'], $basePath);
                                ?>
                                <tr>
                                    <td><?php echo (int) $r['id']; ?></td>
                                    <td><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r['department'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($fundLab, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary btn-view-tarf" data-id="<?php echo (int) $r['id']; ?>" data-full-url="<?php echo htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8'); ?>">View</button>
                                        <button type="button" class="btn btn-sm btn-success btn-endorse" data-id="<?php echo (int) $r['id']; ?>">Endorse</button>
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-reject" data-id="<?php echo (int) $r['id']; ?>">Reject</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <div class="modal fade tarf-view-modal" id="viewTarfModal" tabindex="-1">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewTarfModalTitle">Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body py-3" id="viewTarfModalBody"></div>
                <div class="modal-footer">
                    <a class="btn btn-outline-secondary btn-sm d-none" id="viewTarfOpenFull" href="#" target="_blank" rel="noopener">Open full page</a>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="actionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title" id="actionModalTitle">Action</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <input type="hidden" id="modalTarfId" value="">
                    <input type="hidden" id="modalAction" value="">
                    <div id="commentBlock">
                        <label class="form-label">Comment (optional)</label>
                        <textarea class="form-control" id="modalComment" rows="2"></textarea>
                    </div>
                    <div id="rejectBlock" class="d-none">
                        <label class="form-label">Reason for rejection <span class="text-danger">*</span></label>
                        <textarea class="form-control" id="modalRejectReason" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="modalConfirm">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <form id="csrfForm" class="d-none"><?php addFormToken(); ?></form>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script>
    (function() {
        var apiUrl = <?php echo json_encode(clean_url($basePath . '/faculty/tarf_workflow_action_api.php', $basePath)); ?>;
        var fragmentUrl = <?php echo json_encode(clean_url($basePath . '/faculty/tarf_request_view_fragment.php', $basePath)); ?>;
        var modalEl = document.getElementById('actionModal');
        var modal = modalEl ? new bootstrap.Modal(modalEl) : null;
        var viewModalEl = document.getElementById('viewTarfModal');
        var viewModal = viewModalEl ? new bootstrap.Modal(viewModalEl) : null;
        var viewBody = document.getElementById('viewTarfModalBody');
        var viewTitle = document.getElementById('viewTarfModalTitle');
        var viewOpenFull = document.getElementById('viewTarfOpenFull');
        var loadingHtml = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2" role="status"></span>Loading</div>';

        document.querySelectorAll('.btn-view-tarf').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = btn.getAttribute('data-id');
                var fullUrl = btn.getAttribute('data-full-url') || '';
                if (!viewModal || !viewBody) return;
                viewTitle.textContent = 'Request #' + id;
                viewBody.innerHTML = loadingHtml;
                if (viewOpenFull) {
                    viewOpenFull.href = fullUrl;
                    viewOpenFull.classList.remove('d-none');
                }
                viewModal.show();
                fetch(fragmentUrl + '?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
                    .then(function(r) {
                        if (!r.ok) throw new Error('Failed to load');
                        return r.text();
                    })
                    .then(function(html) {
                        viewBody.innerHTML = html;
                    })
                    .catch(function() {
                        viewBody.innerHTML = '<div class="alert alert-danger mb-0">Could not load this request.</div>';
                    });
            });
        });

        if (viewModalEl) {
            viewModalEl.addEventListener('hidden.bs.modal', function() {
                if (viewBody) viewBody.innerHTML = '';
            });
        }
        function csrf() {
            var i = document.querySelector('#csrfForm input[name="csrf_token"]');
            return i ? i.value : '';
        }
        function post(data, cb) {
            var body = new URLSearchParams();
            Object.keys(data).forEach(function(k) { body.append(k, data[k]); });
            body.append('csrf_token', csrf());
            body.append('role', 'fund_availability');
            fetch(apiUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function(r) { return r.json(); })
                .then(cb)
                .catch(function() { alert('Network error'); });
        }
        document.querySelectorAll('.btn-endorse').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('modalTarfId').value = btn.getAttribute('data-id');
                document.getElementById('modalAction').value = 'endorse';
                document.getElementById('actionModalTitle').textContent = 'Endorse (fund availability)';
                document.getElementById('commentBlock').classList.remove('d-none');
                document.getElementById('rejectBlock').classList.add('d-none');
                document.getElementById('modalComment').value = '';
                modal.show();
            });
        });
        document.querySelectorAll('.btn-reject').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('modalTarfId').value = btn.getAttribute('data-id');
                document.getElementById('modalAction').value = 'reject';
                document.getElementById('actionModalTitle').textContent = 'Reject';
                document.getElementById('commentBlock').classList.add('d-none');
                document.getElementById('rejectBlock').classList.remove('d-none');
                document.getElementById('modalRejectReason').value = '';
                modal.show();
            });
        });
        document.getElementById('modalConfirm').addEventListener('click', function() {
            var id = document.getElementById('modalTarfId').value;
            var act = document.getElementById('modalAction').value;
            var payload = { tarf_id: id, action: act };
            if (act === 'endorse') payload.comment = document.getElementById('modalComment').value;
            else payload.rejection_reason = document.getElementById('modalRejectReason').value;
            post(payload, function(data) {
                if (!data.success) {
                    alert(data.message || 'Failed');
                    return;
                }
                location.reload();
            });
        });
    })();
    </script>
</body>
</html>
