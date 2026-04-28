<?php
/**
 * Declare Official Time - Faculty/Staff submit official time requests.
 * Faculty: request goes to Dean for verification/endorsement, then HR approves/rejects.
 * Staff: request goes directly to HR for approval/rejection.
 * Once approved, it becomes the employee's working time.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->prepare("SELECT fp.employee_id, u.first_name, u.last_name FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.user_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);
$employee_id = $profile['employee_id'] ?? '';
$user_type = $_SESSION['user_type'] ?? 'faculty';
$isStaff = ($user_type === 'staff');

if (empty($employee_id)) {
    $_SESSION['error'] = 'Employee ID not found. Please update your profile.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/faculty/profile.php', $basePath));
}

require_once __DIR__ . '/../includes/navigation.php';
include_navigation();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Declare Official Time - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <?php $basePath = getBasePath(); ?>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <style>
        /* Declare Official Time - Mobile responsive overrides (page-specific, avoids conflicts) */
        @media (max-width: 767px) {
            .declare-official-time .page-header { padding: 0.75rem 0; margin-bottom: 0.75rem; }
            .declare-official-time .page-header .text-muted { font-size: 0.9rem; line-height: 1.4; }
            .declare-official-time .card-header.d-flex { flex-wrap: wrap; gap: 0.5rem; }
            .declare-official-time .card-header h5 { font-size: 1rem; flex: 1; min-width: 0; }
            .declare-official-time .card-header .btn { flex-shrink: 0; }
            .declare-official-time .row .col-6 { flex: 0 0 50%; max-width: 50%; }
        }
        @media (max-width: 480px) {
            .declare-official-time .row .col-6 { flex: 0 0 100%; max-width: 100%; }
        }

        /* Pagination: centered in the middle of the screen (override default layout) */
        .declare-official-time #requestsPagination { justify-content: center !important; align-items: center !important; text-align: center !important; }
        .declare-official-time #requestsPaginationInfo { text-align: center !important; }
        .declare-official-time #requestsPaginationNav { justify-content: center !important; margin: 0 auto !important; }

        /* Fix: Mobile offset + pagination Next button cut off - page-specific overrides */
        @media (max-width: 767px) {
            .declare-official-time { overflow-x: hidden !important; }
            .declare-official-time .main-content { padding-left: 12px !important; padding-right: 12px !important; box-sizing: border-box !important; overflow-x: hidden !important; max-width: 100vw !important; }
            .declare-official-time .main-content .container-fluid { padding-left: 0 !important; padding-right: 0 !important; max-width: 100% !important; overflow-x: hidden !important; }
            .declare-official-time .card-body { padding-left: 12px !important; padding-right: 12px !important; }
            .declare-official-time #requestsTableWrap { width: 100% !important; max-width: 100% !important; margin: 0 !important; min-width: 0 !important; }
            .declare-official-time .col-lg-7 { min-width: 0 !important; }
            .declare-official-time .table-responsive { margin: 0 !important; padding: 0 !important; overflow-x: auto !important; -webkit-overflow-scrolling: touch !important; max-width: 100% !important; }
            .declare-official-time .table-responsive tbody tr { width: 100% !important; }
            /* Pagination: stack and center so Next button is always visible */
            .declare-official-time #requestsPagination { flex-direction: column !important; align-items: center !important; justify-content: center !important; gap: 0.75rem !important; width: 100% !important; max-width: 100% !important; }
            .declare-official-time #requestsPaginationInfo { text-align: center !important; }
            .declare-official-time #requestsPaginationNav { justify-content: center !important; flex-wrap: wrap !important; }
        }
        @media (max-width: 480px) {
            .declare-official-time .main-content .container-fluid { padding-left: 0 !important; padding-right: 0 !important; }
            .declare-official-time .card-body { padding-left: 12px !important; padding-right: 12px !important; }
        }
    </style>
</head>
<body class="layout-faculty faculty-portal declare-official-time">
    <?php include __DIR__ . '/../includes/header.php'; ?>
    <div class="main-content">
        <div class="container-fluid py-3">
            <div class="page-header mb-3">
                <h1 class="page-title">Declare Official Time</h1>
                <p class="text-muted mb-0">Submit your official working schedule. Your request will be reviewed by your supervisor first, then by HR. Once approved, it becomes your working time.</p>
            </div>

            <div class="row">
                <div class="col-lg-5">
                    <div class="card shadow-sm">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-clock me-2"></i>New Request</h5>
                        </div>
                        <div class="card-body">
                            <form id="declareForm">
                                <div class="mb-3">
                                    <label class="form-label">Start date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="start_date" id="start_date" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">End date <small class="text-muted">(leave blank for ongoing)</small></label>
                                    <input type="date" class="form-control" name="end_date" id="end_date">
                                </div>
                                <div class="mb-3" id="weekdaysWrap">
                                    <label class="form-label">Days <span class="text-danger">*</span> <small class="text-muted">(select one or more)</small></label>
                                    <div class="d-flex flex-wrap gap-3" id="weekdaysGroup">
                                        <div class="form-check">
                                            <input class="form-check-input weekday-cb" type="checkbox" name="weekdays[]" id="wd_mon" value="Monday">
                                            <label class="form-check-label" for="wd_mon">Mon</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input weekday-cb" type="checkbox" name="weekdays[]" id="wd_tue" value="Tuesday">
                                            <label class="form-check-label" for="wd_tue">Tue</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input weekday-cb" type="checkbox" name="weekdays[]" id="wd_wed" value="Wednesday">
                                            <label class="form-check-label" for="wd_wed">Wed</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input weekday-cb" type="checkbox" name="weekdays[]" id="wd_thu" value="Thursday">
                                            <label class="form-check-label" for="wd_thu">Thu</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input weekday-cb" type="checkbox" name="weekdays[]" id="wd_fri" value="Friday">
                                            <label class="form-check-label" for="wd_fri">Fri</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input weekday-cb" type="checkbox" name="weekdays[]" id="wd_sat" value="Saturday">
                                            <label class="form-check-label" for="wd_sat">Sat</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input weekday-cb" type="checkbox" name="weekdays[]" id="wd_sun" value="Sunday">
                                            <label class="form-check-label" for="wd_sun">Sun</label>
                                        </div>
                                    </div>
                                    <div class="invalid-feedback" id="weekdaysError">Select at least one day.</div>
                                </div>
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <label class="form-label">Time in <span class="text-danger">*</span></label>
                                        <input type="time" class="form-control" name="time_in" id="time_in" value="08:00" required>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label class="form-label">Time out <span class="text-danger">*</span></label>
                                        <input type="time" class="form-control" name="time_out" id="time_out" value="17:00" required>
                                    </div>
                                </div>
                                <div class="mb-2">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="halfDayCheck" title="Check if you work half-day with no lunch break">
                                        <label class="form-check-label" for="halfDayCheck">Half-day (no lunch break)</label>
                                    </div>
                                </div>
                                <div class="row" id="lunchRow">
                                    <div class="col-6 mb-3">
                                        <label class="form-label">Lunch out</label>
                                        <input type="time" class="form-control" name="lunch_out" id="lunch_out" value="12:00">
                                    </div>
                                    <div class="col-6 mb-3">
                                        <label class="form-label">Lunch in</label>
                                        <input type="time" class="form-control" name="lunch_in" id="lunch_in" value="13:00">
                                    </div>
                                </div>
                                <button type="submit" class="btn btn-primary w-100" id="submitBtn">
                                    <i class="fas fa-paper-plane me-1"></i> Submit Request
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="card shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>My Requests</h5>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="refreshList"><i class="fas fa-sync-alt"></i> Refresh</button>
                        </div>
                        <div class="card-body">
                            <div id="requestsLoading" class="text-center py-4 text-muted">Loading...</div>
                            <div id="requestsTableWrap" style="display: none;">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th>Period</th>
                                                <th>Day</th>
                                                <th>Time</th>
                                                <th>Status</th>
                                                <th>Submitted</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody id="requestsTbody"></tbody>
                                    </table>
                                </div>
                                <nav id="requestsPagination" class="d-flex justify-content-center align-items-center flex-wrap gap-2 mt-2" style="display: none;" aria-label="Requests pagination">
                                    <div class="text-muted small" id="requestsPaginationInfo"></div>
                                    <ul class="pagination pagination-sm mb-0" id="requestsPaginationNav"></ul>
                                </nav>
                                <div id="noRequests" class="text-center text-muted py-3" style="display: none;">No requests yet. Submit one above.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const apiBase = '<?php echo $basePath; ?>/admin/official_time_requests_api.php';
        const ROWS_PER_PAGE = 10;
        let allRequests = [];
        let currentPage = 1;

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

        function statusBadge(s) {
            const map = {
                pending_dean: '<span class="badge bg-warning text-dark">Pending Supervisor</span>',
                pending_super_admin: '<span class="badge bg-info">Pending HR</span>',
                approved: '<span class="badge bg-success">Approved (Working time)</span>',
                rejected: '<span class="badge bg-danger">Rejected</span>'
            };
            return map[s] || s;
        }

        function rowHtml(r) {
            const canDelete = (s) => s === 'pending_dean' || s === 'pending_super_admin';
            const end = r.end_date || 'Ongoing';
            const lunch = (r.lunch_out && r.lunch_in) ? formatTime12(r.lunch_out) + '–' + formatTime12(r.lunch_in) : '–';
            const timeRange = formatTime12(r.time_in) + '–' + formatTime12(r.time_out);
            let actions = '';
            if (canDelete(r.status)) {
                actions = '<button type="button" class="btn btn-sm btn-outline-danger delete-req" data-id="' + r.id + '" title="Delete request"><i class="fas fa-trash-alt"></i></button>';
            } else {
                actions = '<span class="text-muted">–</span>';
            }
            return '<tr><td data-label="Period">' + r.start_date + ' to ' + end + '</td><td data-label="Day">' + r.weekday + '</td><td data-label="Time">' + timeRange + (lunch !== '–' ? ' (Lunch ' + lunch + ')' : '') + '</td><td data-label="Status">' + statusBadge(r.status) + '</td><td data-label="Submitted">' + (r.submitted_at || '') + '</td><td data-label="Actions">' + actions + '</td></tr>';
        }

        function renderPage() {
            const tbody = document.getElementById('requestsTbody');
            const totalPages = Math.ceil(allRequests.length / ROWS_PER_PAGE) || 1;
            currentPage = Math.max(1, Math.min(currentPage, totalPages));
            const start = (currentPage - 1) * ROWS_PER_PAGE;
            const pageData = allRequests.slice(start, start + ROWS_PER_PAGE);
            tbody.innerHTML = pageData.map(rowHtml).join('');
            renderPagination(totalPages);
        }

        function renderPagination(totalPages) {
            const nav = document.getElementById('requestsPagination');
            const info = document.getElementById('requestsPaginationInfo');
            const navList = document.getElementById('requestsPaginationNav');
            if (allRequests.length <= ROWS_PER_PAGE) {
                nav.style.display = 'none';
                return;
            }
            nav.style.display = 'flex';
            const start = (currentPage - 1) * ROWS_PER_PAGE + 1;
            const end = Math.min(currentPage * ROWS_PER_PAGE, allRequests.length);
            info.textContent = 'Showing ' + start + '–' + end + ' of ' + allRequests.length;
            let html = '';
            html += '<li class="page-item' + (currentPage <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (currentPage - 1) + '">Previous</a></li>';
            for (let p = 1; p <= totalPages; p++) {
                html += '<li class="page-item' + (p === currentPage ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
            }
            html += '<li class="page-item' + (currentPage >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + (currentPage + 1) + '">Next</a></li>';
            navList.innerHTML = html;
        }

        document.getElementById('requestsPagination').addEventListener('click', function(e) {
            const a = e.target.closest('a.page-link');
            if (!a || a.parentElement.classList.contains('disabled')) return;
            e.preventDefault();
            const p = parseInt(a.getAttribute('data-page'), 10);
            if (p >= 1 && p <= Math.ceil(allRequests.length / ROWS_PER_PAGE)) {
                currentPage = p;
                renderPage();
            }
        });

        function loadMyRequests() {
            document.getElementById('requestsLoading').style.display = 'block';
            document.getElementById('requestsTableWrap').style.display = 'none';
            fetch(apiBase + '?action=list_my', { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    document.getElementById('requestsLoading').style.display = 'none';
                    document.getElementById('requestsTableWrap').style.display = 'block';
                    const tbody = document.getElementById('requestsTbody');
                    const noReq = document.getElementById('noRequests');
                    if (!data.success || !data.requests || data.requests.length === 0) {
                        allRequests = [];
                        tbody.innerHTML = '';
                        document.getElementById('requestsPagination').style.display = 'none';
                        noReq.style.display = 'block';
                        return;
                    }
                    noReq.style.display = 'none';
                    allRequests = data.requests;
                    currentPage = 1;
                    renderPage();
                })
                .catch(() => {
                    document.getElementById('requestsLoading').style.display = 'none';
                    document.getElementById('requestsTableWrap').style.display = 'block';
                    document.getElementById('requestsTbody').innerHTML = '<tr><td colspan="6" class="text-danger">Failed to load requests.</td></tr>';
                    document.getElementById('requestsPagination').style.display = 'none';
                });
        }

        document.getElementById('halfDayCheck').addEventListener('change', function() {
            const lunchRow = document.getElementById('lunchRow');
            const lunchOut = document.getElementById('lunch_out');
            const lunchIn = document.getElementById('lunch_in');
            if (this.checked) {
                lunchOut.value = '';
                lunchIn.value = '';
                lunchRow.style.opacity = '0.5';
                lunchOut.disabled = lunchIn.disabled = true;
            } else {
                lunchOut.value = '12:00';
                lunchIn.value = '13:00';
                lunchRow.style.opacity = '1';
                lunchOut.disabled = lunchIn.disabled = false;
            }
        });

        document.getElementById('declareForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const weekdays = Array.from(document.querySelectorAll('.weekday-cb:checked')).map(cb => cb.value);
            const group = document.getElementById('weekdaysGroup');
            const errEl = document.getElementById('weekdaysError');
            if (weekdays.length === 0) {
                group.classList.add('is-invalid');
                errEl.style.display = 'block';
                return;
            }
            group.classList.remove('is-invalid');
            errEl.style.display = 'none';
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            const fd = new FormData(this);
            fd.set('action', 'submit_request_batch');
            fd.delete('weekdays[]');
            weekdays.forEach(w => fd.append('weekdays[]', w));
            if (document.getElementById('halfDayCheck').checked) {
                fd.set('lunch_out', '');
                fd.set('lunch_in', '');
            }
            fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    btn.disabled = false;
                    if (data.success) {
                        alert(data.message);
                        document.querySelectorAll('.weekday-cb').forEach(cb => cb.checked = false);
                        document.getElementById('halfDayCheck').checked = false;
                        this.reset();
                        document.getElementById('time_in').value = '08:00';
                        document.getElementById('time_out').value = '17:00';
                        document.getElementById('lunch_out').value = '12:00';
                        document.getElementById('lunch_in').value = '13:00';
                        document.getElementById('lunch_out').disabled = document.getElementById('lunch_in').disabled = false;
                        document.getElementById('lunchRow').style.opacity = '1';
                        loadMyRequests();
                    } else {
                        alert(data.message || 'Failed to submit.');
                    }
                })
                .catch(() => { btn.disabled = false; alert('Request failed.'); });
        });

        document.getElementById('refreshList').addEventListener('click', loadMyRequests);

        document.getElementById('requestsTbody').addEventListener('click', function(e) {
            const btn = e.target.closest('.delete-req');
            if (!btn) return;
            const id = btn.getAttribute('data-id');
            if (!id || !confirm('Delete this official time request?')) return;
            btn.disabled = true;
            const fd = new FormData();
            fd.set('action', 'delete_my_request');
            fd.set('id', id);
            fetch(apiBase, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        loadMyRequests();
                    } else {
                        alert(data.message || 'Failed to delete.');
                        btn.disabled = false;
                    }
                })
                .catch(() => { btn.disabled = false; alert('Request failed.'); });
        });

        loadMyRequests();
    })();
    </script>
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
</body>
</html>
