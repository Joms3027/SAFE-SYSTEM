<?php
/**
 * TARF — Applicable endorser queue (after supervisor endorsement).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/tarf_workflow.php';
require_once __DIR__ . '/../includes/tarf_queue_filter.php';
require_once __DIR__ . '/../includes/tarf_endorser_history.php';

requireAuth();

if (!isFaculty() && !isStaff()) {
    $_SESSION['error'] = 'Access denied.';
    redirect(clean_url(getBasePath() . '/faculty/dashboard.php', getBasePath()));
}

$database = Database::getInstance();
$db = $database->getConnection();
$uid = (int) $_SESSION['user_id'];
$isTarfPresidentViewer = tarf_is_president_key_official_viewer($uid);

if (!tarf_is_endorser_target_user($uid, $db)) {
    $_SESSION['error'] = 'This page is for applicable endorsers configured in the TARF system.';
    redirect(clean_url(getBasePath() . '/faculty/dashboard.php', getBasePath()));
}

$pending = [];
$tableOk = false;
try {
    $tbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
    $tableOk = $tbl && $tbl->rowCount() > 0;
    if ($tableOk) {
        $st = $db->prepare(
            "SELECT tr.*, u.first_name, u.last_name, fp.department
             FROM tarf_requests tr
             INNER JOIN users u ON u.id = tr.user_id
             LEFT JOIN faculty_profiles fp ON fp.user_id = tr.user_id
             WHERE tr.status IN ('pending_joint','pending_supervisor','pending_endorser')
             AND tr.endorser_endorsed_at IS NULL
             AND tr.endorser_target_user_id = ?
             ORDER BY COALESCE(tr.supervisor_endorsed_at, tr.created_at) ASC, tr.id ASC"
        );
        $st->execute([$uid]);
        $pending = $st->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $pending = [];
}

$basePath = getBasePath();
$presidentQueueUrl = clean_url($basePath . '/faculty/tarf_president_queue.php', $basePath);
require_once __DIR__ . '/../includes/navigation.php';
include_navigation();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TARF / NTARF — Applicable endorser</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <style>
        /* Match tarf_request_view.php DISAPP layout inside modal */
        .tarf-view-modal .modal-body { max-height: min(85vh, 900px); overflow-y: auto; }
        .tarf-view-modal .tarf-disapp { font-family: Georgia, "Times New Roman", serif; max-width: 920px; margin: 0 auto; }
        .tarf-view-modal .tarf-disapp .tarf-head-num { text-align: right; font-style: italic; text-decoration: underline; font-size: 1.05rem; }
        .tarf-view-modal .tarf-disapp .tarf-title { text-align: center; font-weight: 700; font-size: 1.15rem; margin: 0.5rem 0 1rem; }
        .tarf-view-modal .tarf-disapp table.disapp-grid { width: 100%; border-collapse: collapse; font-size: 0.95rem; }
        .tarf-view-modal .tarf-disapp table.disapp-grid th,
        .tarf-view-modal .tarf-disapp table.disapp-grid td { border: 1px solid #000; padding: 0.45rem 0.6rem; vertical-align: top; }
        .tarf-view-modal .tarf-disapp table.disapp-grid .lbl { font-weight: 700; width: 36%; background: #fafafa; }
        .tarf-view-modal .tarf-disapp .endorse-grid { width: 100%; border-collapse: collapse; margin-top: 1.25rem; }
        .tarf-view-modal .tarf-disapp .endorse-grid.endorse-grid-tarf td { border: 2px solid #000; padding: 1rem; vertical-align: top; width: 33.333%; text-align: center; }
        .tarf-view-modal .tarf-disapp .endorse-grid.endorse-grid-ntarf td { border: 2px solid #000; padding: 1rem; vertical-align: top; width: 25%; text-align: center; }
        .tarf-view-modal .tarf-disapp .endorse-grid .sub { font-size: 0.95rem; margin-bottom: 0.75rem; }
        .tarf-view-modal .tarf-disapp .endorse-grid .name-line { font-weight: 700; font-size: 1.1rem; }
        .tarf-view-modal .tarf-disapp .endorse-grid .role-line { font-size: 0.95rem; margin-top: 0.25rem; }
        .tarf-view-modal .tarf-disapp .status-notes .final { font-weight: 700; font-size: 1.15rem; text-decoration: underline; }
        .tarf-view-modal .tarf-disapp .attachments { margin-top: 1rem; font-size: 0.9rem; }
        .tarf-view-modal .tarf-disapp.card { overflow: visible; }
    </style>
</head>
<body class="layout-faculty">
    <div class="container-fluid py-3">
        <main class="main-content">
            <h1 class="h4 mb-3"><i class="fas fa-stamp text-primary me-2"></i>TARF / NTARF — Applicable endorser</h1>
            <p class="text-muted">These requests are routed to you based on the “Applicable endorser” selected. Supervisor and Budget/Accounting (when required) may endorse at the same time.</p>
            <?php displayMessage(); ?>

            <?php if (!$tableOk): ?>
                <div class="alert alert-warning">TARF table not found.</div>
            <?php elseif (empty($pending)): ?>
                <div class="card shadow-sm"><div class="card-body text-center text-muted py-5">No TARF/NTARF requests pending your endorsement.</div></div>
            <?php else: ?>
                <div class="card shadow-sm">
                    <?php tarf_queue_filter_bar(); ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light"><tr><th>ID</th><th>Requester</th><th>Department</th><th>Endorser (form)</th><th>Supervisor endorsed</th><th></th></tr></thead>
                        <tbody>
                            <?php foreach ($pending as $r):
                                $fd = json_decode($r['form_data'], true);
                                $endLab = is_array($fd) ? ($fd['applicable_endorser'] ?? '') : '';
                                $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                                $viewUrl = clean_url($basePath . '/faculty/tarf_request_view.php?id=' . (int) $r['id'], $basePath);
                                $supAt = $r['supervisor_endorsed_at'] ?? '';
                                ?>
                                <tr<?php echo tarf_queue_row_data_attrs($r); ?>>
                                    <td><?php echo (int) $r['id']; ?></td>
                                    <td><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($r['department'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars($endLab, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo $supAt ? htmlspecialchars(date('M j, Y g:i A', strtotime($supAt)), ENT_QUOTES, 'UTF-8') : '—'; ?></td>
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
                </div>
            <?php endif; ?>
        </main>
    </div>

    <div class="modal fade tarf-view-modal" id="viewTarfModal" tabindex="-1" aria-labelledby="viewTarfModalTitle">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="viewTarfModalTitle">TARF request</h5>
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
        var isTarfPresidentViewer = <?php echo $isTarfPresidentViewer ? 'true' : 'false'; ?>;
        var presidentQueueUrl = <?php echo json_encode($presidentQueueUrl, JSON_UNESCAPED_SLASHES); ?>;
        var modalEl = document.getElementById('actionModal');
        var modal = modalEl ? new bootstrap.Modal(modalEl) : null;
        var viewModalEl = document.getElementById('viewTarfModal');
        var viewModal = viewModalEl ? new bootstrap.Modal(viewModalEl) : null;
        var viewBody = document.getElementById('viewTarfModalBody');
        var viewTitle = document.getElementById('viewTarfModalTitle');
        var viewOpenFull = document.getElementById('viewTarfOpenFull');
        var loadingHtml = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2" role="status"></span>Loading…</div>';

        document.querySelectorAll('.btn-view-tarf').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = btn.getAttribute('data-id');
                var fullUrl = btn.getAttribute('data-full-url') || '';
                if (!viewModal || !viewBody) return;
                viewTitle.textContent = 'TARF #' + id;
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
                        viewBody.innerHTML = '<div class="alert alert-danger mb-0">Could not load this request. Try <a href="' + (fullUrl || '#') + '">opening the full page</a>.</div>';
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
            body.append('role', 'endorser');
            fetch(apiUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                .then(function(r) { return r.json(); })
                .then(cb)
                .catch(function() { alert('Network error'); });
        }
        document.querySelectorAll('.btn-endorse').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('modalTarfId').value = btn.getAttribute('data-id');
                document.getElementById('modalAction').value = 'endorse';
                document.getElementById('actionModalTitle').textContent = 'Endorse TARF';
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
                document.getElementById('actionModalTitle').textContent = 'Reject TARF';
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
                if (act === 'endorse' && data.routed_to_president) {
                    if (isTarfPresidentViewer) {
                        window.location.href = presidentQueueUrl;
                        return;
                    }
                    alert('This TARF was forwarded to the President for final approval. It appears under “TARF — President (final)” for the key-official account.');
                }
                location.reload();
            });
        });
    })();
    </script>
    <?php tarf_queue_filter_script(); ?>
    <?php tarf_endorser_history_render_fixed_button_and_modal($basePath, 'endorser'); ?>
</body>
</html>
