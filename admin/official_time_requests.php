<?php
/**
 * Official Time Requests - View and approve/reject employee-declared official times.
 * All admins (admin and super_admin) can view, approve, and reject.
 * All requests are endorsed by the employee's supervisor first, then appear here for final approval.
 * Once approved, the official time becomes the employee's working time.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireAdmin();

// All admins can approve or reject official time requests
$canApproveReject = isAdmin();

$database = Database::getInstance();
$db = $database->getConnection();
$deptStmt = $db->query("SELECT DISTINCT fp.department FROM faculty_profiles fp WHERE fp.department IS NOT NULL AND fp.department != '' ORDER BY fp.department");
$departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
$desigStmt = $db->query("SELECT DISTINCT fp.designation FROM faculty_profiles fp WHERE fp.designation IS NOT NULL AND fp.designation != '' ORDER BY fp.designation");
$designations = $desigStmt->fetchAll(PDO::FETCH_COLUMN);

$basePath = getBasePath();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once __DIR__ . '/../includes/admin_layout_helper.php';
    admin_page_head('Official Time Requests', 'Approve or reject official time declarations. All requests are endorsed by the supervisor first, then appear here for final approval.');
    ?>
</head>
<body class="layout-admin">
    <?php require_once __DIR__ . '/../includes/navigation.php';
    include_navigation(); ?>

    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'Official Time Requests',
                    '',
                    'fas fa-clock',
                    [],
                    '<button type="button" class="btn btn-sm btn-outline-primary" id="refreshBtn"><i class="fas fa-sync-alt me-1"></i>Refresh</button>'
                );
                ?>

                <?php displayMessage(); ?>

                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Admin:</strong> Approve or reject official time declarations. Approved requests become the employee's working time. All requests are endorsed by the employee's supervisor first, then appear here for final approval.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>

                <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-clock me-2 text-primary"></i>Pending Approval</h5>
                </div>
                <div class="card-body">
                    <div class="row g-2 mb-3">
                        <div class="col-md-3 col-6">
                            <label class="form-label small mb-0">Name</label>
                            <input type="text" class="form-control form-control-sm" id="filterName" placeholder="Search by name...">
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label small mb-0">Department</label>
                            <select class="form-select form-select-sm" id="filterDepartment">
                                <option value="">All departments</option>
                                <?php foreach ($departments as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label small mb-0">Designation</label>
                            <select class="form-select form-select-sm" id="filterDesignation">
                                <option value="">All designations</option>
                                <?php foreach ($designations as $d): ?>
                                <option value="<?php echo htmlspecialchars($d); ?>"><?php echo htmlspecialchars($d); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <label class="form-label small mb-0">Date</label>
                            <input type="date" class="form-control form-control-sm" id="filterDate" title="Filter by date (shows requests covering this date)">
                        </div>
                        <div class="col-md-12 col-6 d-flex align-items-end gap-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearFiltersBtn">Clear filters</button>
                        </div>
                    </div>
                    <div id="loading" class="text-center py-4 text-muted">Loading...</div>
                    <div id="groupsWrap" style="display: none;">
                        <p class="text-muted small mb-3">Requests are grouped by employee. Review the full schedule below, then approve or reject all days for that employee in one action.</p>
                        <div id="groupsList"></div>
                        <div id="empty" class="empty-state" style="display: none;">
                            <i class="fas fa-inbox"></i>
                            <span class="empty-title">No Pending Official Time Requests</span>
                            <p class="mb-0">All official time requests have been processed or there are no new submissions.</p>
                        </div>
                    </div>
                </div>
            </div>
            </main>
        </div>
    </div>

    <!-- Reject reason modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Official Time Request(s)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="rejectEmployeeId" value="">
                    <p class="text-muted small mb-2" id="rejectModalDesc">Rejecting all pending requests for this employee.</p>
                    <label class="form-label">Reason (optional)</label>
                    <textarea class="form-control" id="rejectReason" rows="3" placeholder="Optional reason for rejection"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmRejectBtn">Reject All</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const apiBase = '<?php echo $basePath; ?>/admin/official_time_requests_api.php';
        const canApproveReject = <?php echo $canApproveReject ? 'true' : 'false'; ?>;

        function formatTime12(t) {
            if (!t) return '';
            const parts = String(t).match(/^(\d{1,2}):(\d{2})/);
            if (!parts) return t;
            let h = parseInt(parts[1], 10);
            const m = parts[2];
            const ampm = h >= 12 ? 'PM' : 'AM';
            h = h === 0 ? 12 : h > 12 ? h - 12 : h;
            return h + ':' + m + ' ' + ampm;
        }

        function escapeHtml(s) {
            if (s == null) return '';
            const div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }

        function formatVerifiedBy(r) {
            if (!r.verified_by && !r.verified_at) return '<span class="text-muted">—</span>';
            const dt = r.verified_at ? String(r.verified_at) : '';
            const datePart = dt ? dt.split(' ')[0] : '';
            const timePart = dt && dt.includes(' ') ? formatTime12(dt.split(' ')[1]) : '';
            const who = escapeHtml(r.verified_by || '');
            if (!who) return datePart && timePart ? datePart + ' ' + timePart : (datePart || timePart || '—');
            return who + (datePart ? ' on ' + datePart : '') + (timePart ? ' at ' + timePart : '');
        }

        function renderGroup(g) {
            const typeLabel = (g.user_type === 'staff') ? '<span class="badge bg-secondary">Staff</span>' : '<span class="badge bg-primary">Faculty</span>';
            const reqs = g.requests || [];
            const first = reqs[0];
            const periodStr = first ? (first.start_date + ' to ' + (first.end_date || 'Ongoing')) : '';
            const daysList = reqs.map(r => r.weekday).join(', ');

            let rows = reqs.map(r => {
                const lunch = (r.lunch_out && r.lunch_in) ? formatTime12(r.lunch_out) + '–' + formatTime12(r.lunch_in) : '';
                const timeStr = formatTime12(r.time_in) + '–' + formatTime12(r.time_out) + (lunch ? ' (Lunch ' + lunch + ')' : '');
                const verifiedBy = formatVerifiedBy(r);
                return '<tr><td>' + r.weekday + '</td><td>' + (r.start_date + ' to ' + (r.end_date || 'Ongoing')) + '</td><td>' + timeStr + '</td><td>' + (r.submitted_at || '') + '</td><td>' + verifiedBy + '</td></tr>';
            }).join('');

            const desigStr = (g.designation || '') ? (' &middot; ' + escapeHtml(g.designation)) : '';
            const actionBtns = canApproveReject
                ? '<div class="d-flex flex-nowrap gap-1"><button type="button" class="btn btn-sm btn-success approveBatchBtn" data-employee-id="' + escapeHtml(g.employee_id) + '" data-name="' + escapeHtml(g.employee_name) + '" title="Approve all"><i class="fas fa-check me-1"></i>Approve All</button>' +
                  '<button type="button" class="btn btn-sm btn-danger rejectBatchBtn" data-employee-id="' + escapeHtml(g.employee_id) + '" data-name="' + escapeHtml(g.employee_name) + '" data-count="' + reqs.length + '" title="Reject all"><i class="fas fa-times me-1"></i>Reject All</button></div>'
                : '';
            return '<div class="card mb-3 border" data-employee-id="' + escapeHtml(g.employee_id) + '">' +
                '<div class="card-header bg-light d-flex justify-content-between align-items-center flex-wrap gap-2">' +
                '<div><strong>' + escapeHtml(g.employee_name) + '</strong> <small class="text-muted">' + escapeHtml(g.employee_id) + '</small> ' + typeLabel + ' &middot; ' + escapeHtml(g.department || '') + desigStr + '</div>' +
                actionBtns + '</div>' +
                '<div class="card-body py-2">' +
                '<p class="small text-muted mb-2">Period: ' + periodStr + ' &middot; Days: ' + daysList + '</p>' +
                '<div class="table-responsive"><table class="table table-sm table-bordered mb-0"><thead class="table-light"><tr><th>Day</th><th>Period</th><th>Time</th><th>Submitted</th><th>Verified By</th></tr></thead><tbody>' + rows + '</tbody></table></div>' +
                '</div></div>';
        }

        function load() {
            document.getElementById('loading').style.display = 'block';
            document.getElementById('groupsWrap').style.display = 'none';
            const params = new URLSearchParams({ action: 'list_pending_super_admin_grouped' });
            const name = document.getElementById('filterName').value.trim();
            const dept = document.getElementById('filterDepartment').value.trim();
            const desig = document.getElementById('filterDesignation').value.trim();
            const date = document.getElementById('filterDate').value.trim();
            if (name) params.set('name', name);
            if (dept) params.set('department', dept);
            if (desig) params.set('designation', desig);
            if (date) params.set('date', date);
            fetch(apiBase + '?' + params.toString(), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('groupsWrap').style.display = 'block';
                    const groupsList = document.getElementById('groupsList');
                    const empty = document.getElementById('empty');
                    if (!data.success || !data.groups || data.groups.length === 0) {
                        groupsList.innerHTML = '';
                        empty.style.display = 'block';
                        return;
                    }
                    empty.style.display = 'none';
                    groupsList.innerHTML = data.groups.map(renderGroup).join('');

                    document.querySelectorAll('.approveBatchBtn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const empId = this.getAttribute('data-employee-id');
                            const name = this.getAttribute('data-name');
                            if (!confirm('Approve all official time requests for ' + name + '? They will become the employee\'s working time.')) return;
                            this.disabled = true;
                            const fd = new FormData();
                            fd.set('action', 'approve_batch');
                            fd.set('employee_id', empId);
                            fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' })
                                .then(r => r.json())
                                .then(d => {
                                    if (d.success) { alert(d.message); load(); }
                                    else { alert(d.message || 'Failed'); this.disabled = false; }
                                })
                                .catch(() => { this.disabled = false; alert('Request failed.'); });
                        });
                    });

                    document.querySelectorAll('.rejectBatchBtn').forEach(btn => {
                        btn.addEventListener('click', function() {
                            document.getElementById('rejectEmployeeId').value = this.getAttribute('data-employee-id');
                            const name = this.getAttribute('data-name');
                            const count = this.getAttribute('data-count') || '1';
                            document.getElementById('rejectModalDesc').textContent = 'Rejecting ' + count + ' pending request(s) for ' + name + '.';
                            document.getElementById('rejectReason').value = '';
                            new bootstrap.Modal(document.getElementById('rejectModal')).show();
                        });
                    });
                })
                .catch(() => {
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('groupsWrap').style.display = 'block';
                    document.getElementById('groupsList').innerHTML = '<div class="alert alert-danger">Failed to load. Ensure the official_time_requests table exists (run migration).</div>';
                });
        }

        document.getElementById('refreshBtn').addEventListener('click', load);

        document.getElementById('clearFiltersBtn').addEventListener('click', function() {
            document.getElementById('filterName').value = '';
            document.getElementById('filterDepartment').value = '';
            document.getElementById('filterDesignation').value = '';
            document.getElementById('filterDate').value = '';
            load();
        });

        (function() {
            var nameDebounce;
            document.getElementById('filterName').addEventListener('input', function() {
                clearTimeout(nameDebounce);
                nameDebounce = setTimeout(load, 350);
            });
            document.getElementById('filterDepartment').addEventListener('change', load);
            document.getElementById('filterDesignation').addEventListener('change', load);
            document.getElementById('filterDate').addEventListener('change', load);
        })();

        document.getElementById('confirmRejectBtn').addEventListener('click', function() {
            const empId = document.getElementById('rejectEmployeeId').value;
            const reason = document.getElementById('rejectReason').value.trim();
            const fd = new FormData();
            fd.set('action', 'reject_batch');
            fd.set('employee_id', empId);
            fd.set('rejection_reason', reason);
            fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    bootstrap.Modal.getInstance(document.getElementById('rejectModal')).hide();
                    if (d.success) { alert(d.message); load(); }
                    else alert(d.message || 'Failed');
                })
                .catch(() => alert('Request failed.'));
        });

        load();
    })();
    </script>
    <?php admin_page_scripts(); ?>
</body>
</html>
