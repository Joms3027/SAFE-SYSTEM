<?php
/**
 * Official Time Requests (Dean) - Verify and endorse faculty official time requests.
 * Endorsed requests go to HR for final approval/rejection.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->prepare("SELECT fp.designation, fp.department FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

$isDean = $userProfile && strtolower(trim($userProfile['designation'] ?? '')) === 'dean';
$deanDepartment = trim($userProfile['department'] ?? '');
$hasScopeAssignments = hasPardonOpenerAssignments($_SESSION['user_id'], $db);

if ($isDean && $deanDepartment !== '') {
    $scopeLabel = $deanDepartment;
} elseif ($hasScopeAssignments) {
    $scopeLabel = 'Your Scope';
} else {
    $_SESSION['error'] = 'Access denied. Only Deans or assigned personnel can access this page.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/faculty/dashboard.php', $basePath));
}

require_once __DIR__ . '/../includes/navigation.php';
include_navigation();
$basePath = getBasePath();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Official Time Requests - <?php echo htmlspecialchars($scopeLabel); ?></title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
</head>
<body class="faculty-portal">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="main-content">
        <div class="container-fluid py-3">
            <div class="page-header mb-3">
                <h1 class="page-title">Official Time Requests</h1>
                <p class="text-muted mb-0">Verify and endorse official time declarations from employees in <?php echo htmlspecialchars($scopeLabel); ?>. Endorsed requests go to HR for final approval.</p>
            </div>

            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Pending Your Endorsement</h5>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-success" id="verifyAllBtn"><i class="fas fa-check-double me-1"></i>Verify All</button>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="refreshBtn"><i class="fas fa-sync-alt"></i> Refresh</button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row g-2 mb-3" id="filterRow">
                        <div class="col-md-4 col-6">
                            <label class="form-label small mb-0">Name</label>
                            <input type="text" class="form-control form-control-sm" id="filterName" placeholder="Search by name...">
                        </div>
                        <div class="col-md-4 col-6">
                            <label class="form-label small mb-0">Date</label>
                            <input type="date" class="form-control form-control-sm" id="filterDate" placeholder="Date" value="">
                        </div>
                        <div class="col-md-2 col-6 d-flex align-items-end">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="clearFiltersBtn"><i class="fas fa-times me-1"></i>Clear</button>
                        </div>
                    </div>
                    <div id="loading" class="text-center py-4 text-muted">Loading...</div>
                    <div id="tableWrap" style="display: none;">
                        <div id="pageInfo" class="small text-muted mb-2" style="display: none;"></div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee</th>
                                        <th>Period</th>
                                        <th>Mon</th>
                                        <th>Tue</th>
                                        <th>Wed</th>
                                        <th>Thu</th>
                                        <th>Fri</th>
                                        <th>Sat</th>
                                        <th>Sun</th>
                                        <th>Submitted</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="employeeTableBody"></tbody>
                            </table>
                        </div>
                        <nav id="paginationNav" class="mt-3 d-flex justify-content-center" aria-label="Employee pagination" style="display: none;">
                            <ul class="pagination pagination-sm mb-0" id="paginationList"></ul>
                        </nav>
                        <div id="empty" class="text-center text-muted py-4" style="display: none;">No pending official time requests in your department.</div>
                        <div id="noMatch" class="text-center text-muted py-4" style="display: none;">No employees match your filters.</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <!-- Reject reason modal -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Reject Official Time Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="rejectRequestId" value="">
                    <input type="hidden" id="rejectEmployeeId" value="">
                    <p class="text-muted small mb-2">Rejecting will notify the employee. They may submit a new request if needed.</p>
                    <label class="form-label">Reason (optional)</label>
                    <textarea class="form-control" id="rejectReason" rows="3" placeholder="Optional reason for rejection"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="confirmRejectBtn">Reject</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const apiBase = '<?php echo $basePath; ?>/admin/official_time_requests_api.php';
        const PER_PAGE = 5;
        let allGroups = [];
        let currentPage = 1;

        const WEEKDAY_ORDER = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

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

        function sortRequestsByWeekday(requests) {
            return [...requests].sort((a, b) => WEEKDAY_ORDER.indexOf(a.weekday) - WEEKDAY_ORDER.indexOf(b.weekday));
        }

        function groupMatchesFilter(group, filters) {
            const name = (filters.name || '').toLowerCase().trim();
            const date = (filters.date || '').trim();
            if (name && !(group.employee_name || '').toLowerCase().includes(name) && !(group.employee_id || '').toLowerCase().includes(name)) return false;
            if (date) {
                const d = date;
                const hasMatch = (group.requests || []).some(r => {
                    const start = r.start_date || '';
                    const end = r.end_date || null;
                    if (!start) return false;
                    if (d < start) return false;
                    if (end && d > end) return false;
                    return true;
                });
                if (!hasMatch) return false;
            }
            return true;
        }

        function getFilters() {
            return {
                name: document.getElementById('filterName').value,
                date: document.getElementById('filterDate').value
            };
        }

        function getTimeStr(r) {
            if (!r) return '–';
            const lunch = (r.lunch_out && r.lunch_in) ? formatTime12(r.lunch_out) + '–' + formatTime12(r.lunch_in) : '';
            return formatTime12(r.time_in) + '–' + formatTime12(r.time_out) + (lunch ? ' (Lunch ' + lunch + ')' : '');
        }

        function renderEmployeeRow(group) {
            const reqs = group.requests || [];
            const byDay = {};
            reqs.forEach(r => { byDay[r.weekday] = r; });
            const mon = getTimeStr(byDay['Monday']);
            const tue = getTimeStr(byDay['Tuesday']);
            const wed = getTimeStr(byDay['Wednesday']);
            const thu = getTimeStr(byDay['Thursday']);
            const fri = getTimeStr(byDay['Friday']);
            const sat = getTimeStr(byDay['Saturday']);
            const sun = getTimeStr(byDay['Sunday']);
            const first = reqs[0];
            const period = first ? (first.start_date + ' to ' + (first.end_date || 'Ongoing')) : '–';
            const submitted = first ? (first.submitted_at || '') : '–';
            return '<tr>' +
                '<td>' + escapeHtml(group.employee_name) + ' <small class="text-muted">' + escapeHtml(group.employee_id) + '</small></td>' +
                '<td>' + period + '</td>' +
                '<td>' + mon + '</td><td>' + tue + '</td><td>' + wed + '</td><td>' + thu + '</td><td>' + fri + '</td><td>' + sat + '</td><td>' + sun + '</td>' +
                '<td>' + submitted + '</td>' +
                '<td><div class="btn-group btn-group-sm"><button type="button" class="btn btn-success endorseBtn" data-employee-id="' + escapeHtml(group.employee_id) + '">Endorse All</button><button type="button" class="btn btn-danger rejectBtn" data-employee-id="' + escapeHtml(group.employee_id) + '">Reject All</button></div></td>' +
                '</tr>';
        }

        function renderPagination(filteredGroups) {
            const totalPages = Math.ceil(filteredGroups.length / PER_PAGE) || 1;
            const nav = document.getElementById('paginationNav');
            const list = document.getElementById('paginationList');
            if (totalPages <= 1) {
                nav.style.display = 'none';
                return;
            }
            nav.style.display = 'flex';
            let html = '';
            if (currentPage > 1) {
                html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (currentPage - 1) + '">Previous</a></li>';
            }
            for (let i = 1; i <= totalPages; i++) {
                html += '<li class="page-item' + (i === currentPage ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
            }
            if (currentPage < totalPages) {
                html += '<li class="page-item"><a class="page-link" href="#" data-page="' + (currentPage + 1) + '">Next</a></li>';
            }
            list.innerHTML = html;
            list.querySelectorAll('a').forEach(a => {
                a.addEventListener('click', function(e) {
                    e.preventDefault();
                    currentPage = parseInt(this.getAttribute('data-page'), 10);
                    applyFilters();
                });
            });
        }

        function render() {
            const filters = getFilters();
            const filtered = allGroups.filter(g => groupMatchesFilter(g, filters));
            const empty = document.getElementById('empty');
            const noMatch = document.getElementById('noMatch');
            const tbody = document.getElementById('employeeTableBody');

            if (allGroups.length === 0) {
                tbody.innerHTML = '';
                empty.style.display = 'block';
                noMatch.style.display = 'none';
                document.getElementById('paginationNav').style.display = 'none';
                document.getElementById('pageInfo').style.display = 'none';
                return;
            }
            empty.style.display = 'none';

            if (filtered.length === 0) {
                tbody.innerHTML = '';
                noMatch.style.display = 'block';
                document.getElementById('paginationNav').style.display = 'none';
                document.getElementById('pageInfo').style.display = 'none';
                return;
            }
            noMatch.style.display = 'none';

            const start = Math.max(0, (currentPage - 1) * PER_PAGE);
            const pageGroups = filtered.slice(start, start + PER_PAGE);
            if (pageGroups.length === 0 && filtered.length > 0) {
                currentPage = 1;
                return render();
            }
            const pageInfo = document.getElementById('pageInfo');
            if (filtered.length > PER_PAGE) {
                const from = start + 1;
                const to = Math.min(start + PER_PAGE, filtered.length);
                pageInfo.textContent = 'Showing employees ' + from + '–' + to + ' of ' + filtered.length;
                pageInfo.style.display = 'block';
            } else {
                pageInfo.textContent = 'Showing ' + filtered.length + ' employee(s)';
                pageInfo.style.display = 'block';
            }
            tbody.innerHTML = pageGroups.map(g => renderEmployeeRow(g)).join('');

            renderPagination(filtered);

            document.querySelectorAll('.endorseBtn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const empId = this.getAttribute('data-employee-id');
                    if (!confirm('Endorse all official time requests for this employee? They will be sent to HR for approval.')) return;
                    this.disabled = true;
                    const fd = new FormData();
                    fd.set('action', 'endorse_batch');
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

            document.querySelectorAll('.rejectBtn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const empId = this.getAttribute('data-employee-id');
                    document.getElementById('rejectRequestId').value = '';
                    document.getElementById('rejectEmployeeId').value = empId;
                    document.getElementById('rejectReason').value = '';
                    new bootstrap.Modal(document.getElementById('rejectModal')).show();
                });
            });
        }

        function applyFilters() {
            currentPage = Math.min(currentPage, Math.ceil(allGroups.filter(g => groupMatchesFilter(g, getFilters())).length / PER_PAGE) || 1);
            render();
        }

        function load() {
            document.getElementById('loading').style.display = 'block';
            document.getElementById('tableWrap').style.display = 'none';
            fetch(apiBase + '?action=list_for_dean_grouped', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('tableWrap').style.display = 'block';
                    allGroups = (data.success && Array.isArray(data.groups)) ? data.groups : [];
                    currentPage = 1;
                    applyFilters();
                })
                .catch(() => {
                    document.getElementById('loading').style.display = 'none';
                    document.getElementById('tableWrap').style.display = 'block';
                    allGroups = [];
                    document.getElementById('employeeTableBody').innerHTML = '<tr><td colspan="11" class="text-center text-danger py-3">Failed to load.</td></tr>';
                });
        }

        document.getElementById('refreshBtn').addEventListener('click', load);
        document.getElementById('verifyAllBtn').addEventListener('click', function() {
            const totalReqs = allGroups.reduce((n, g) => n + (g.requests ? g.requests.length : 0), 0);
            if (totalReqs === 0) {
                alert('No pending requests to verify.');
                return;
            }
            if (!confirm('Verify (endorse) all ' + totalReqs + ' pending official time request(s) from ' + allGroups.length + ' employee(s)? They will be sent to HR for approval.')) return;
            this.disabled = true;
            const fd = new FormData();
            fd.set('action', 'endorse_all');
            fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.success) { alert(d.message); load(); }
                    else { alert(d.message || 'Failed'); }
                    this.disabled = false;
                })
                .catch(() => { this.disabled = false; alert('Request failed.'); });
        });
        document.getElementById('confirmRejectBtn').addEventListener('click', function() {
            const empId = document.getElementById('rejectEmployeeId').value;
            const reason = document.getElementById('rejectReason').value.trim();
            const fd = new FormData();
            fd.set('action', 'reject_employee_dean');
            fd.set('employee_id', empId);
            fd.set('rejection_reason', reason);
            fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(d => {
                    if (d.success) { bootstrap.Modal.getInstance(document.getElementById('rejectModal')).hide(); load(); alert(d.message); }
                    else { alert(d.message || 'Failed'); }
                })
                .catch(() => alert('Request failed.'));
        });
        document.getElementById('filterName').addEventListener('input', function() { currentPage = 1; applyFilters(); });
        document.getElementById('filterDate').addEventListener('change', function() { currentPage = 1; applyFilters(); });
        document.getElementById('clearFiltersBtn').addEventListener('click', function() {
            document.getElementById('filterName').value = '';
            document.getElementById('filterDate').value = '';
            currentPage = 1;
            applyFilters();
        });
        load();
    })();
    </script>
</body>
</html>
