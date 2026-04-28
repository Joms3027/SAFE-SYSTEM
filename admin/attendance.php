<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

$stationsStmt = $db->query("SELECT id, name FROM stations ORDER BY name ASC");
$stations = $stationsStmt ? $stationsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
$canOverride = isSuperAdmin();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Attendance / Entries', 'View attendance and time entries for all employees');
    ?>
    <link href="<?php echo asset_url('css/attendance.css', true); ?>" rel="stylesheet">
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php'; include_navigation(); ?>

    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="attendance-filters">
            <div class="attendance-filters__row">
                <div class="attendance-filters__field">
                    <label class="attendance-filters__label" for="viewMode">View</label>
                    <select id="viewMode" class="attendance-filters__select">
                        <option value="single">Single Date</option>
                        <option value="range">Date Range</option>
                    </select>
                </div>
                <div class="attendance-filters__field" id="singleDateGroup">
                    <label class="attendance-filters__label" for="filterDate">Date</label>
                    <div class="attendance-filters__date-wrap">
                        <input type="date" id="filterDate" class="attendance-filters__input" value="<?php echo date('Y-m-d'); ?>">
                        <button type="button" id="btnToday" class="attendance-filters__quick" title="Today">Today</button>
                    </div>
                </div>
                <div class="attendance-filters__field d-none" id="dateRangeGroup">
                    <label class="attendance-filters__label" for="filterDateFrom">From</label>
                    <input type="date" id="filterDateFrom" class="attendance-filters__input">
                </div>
                <div class="attendance-filters__field d-none" id="dateRangeGroupTo">
                    <label class="attendance-filters__label" for="filterDateTo">To</label>
                    <input type="date" id="filterDateTo" class="attendance-filters__input">
                </div>
                <div class="attendance-filters__field">
                    <label class="attendance-filters__label" for="filterStation">Station</label>
                    <select id="filterStation" class="attendance-filters__select">
                        <option value="">All stations</option>
                        <option value="none">Unspecified</option>
                        <?php foreach ($stations as $s): ?>
                        <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="attendance-filters__field attendance-filters__search-wrap">
                    <label class="attendance-filters__label" for="filterSearch">Search</label>
                    <div class="attendance-filters__input-group">
                        <i class="fas fa-search attendance-filters__input-icon"></i>
                        <input type="text" id="filterSearch" class="attendance-filters__input" placeholder="Name or ID">
                        <button type="button" id="btnClearSearch" class="attendance-filters__clear" title="Clear search" aria-label="Clear search"><i class="fas fa-times"></i></button>
                    </div>
                </div>
                <div class="attendance-filters__actions">
                    <button type="button" id="btnDownloadReport" class="btn btn-outline-success btn-sm attendance-filters__btn" title="Download Excel report for the selected date">
                        <i class="fas fa-file-download"></i><span class="d-none d-md-inline ms-1">Report</span>
                    </button>
                    <button type="button" id="btnRefresh" class="btn btn-outline-primary btn-sm attendance-filters__btn" title="Refresh">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                    <button type="button" id="btnReset" class="btn btn-outline-secondary btn-sm attendance-filters__btn" title="Reset filters">
                        <i class="fas fa-undo"></i>
                    </button>
                </div>
            </div>
        </div>

        <div class="card" id="attendanceCard">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Attendance Records</h5>
                <span class="attendance-header__meta">
                    <span class="badge bg-secondary me-1" id="loadingBadge" style="display:none;"><i class="fas fa-spinner fa-spin me-1"></i>Loading</span>
                    <span class="badge bg-primary" id="recordCount">0 records</span>
                </span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive" id="tableWrapper">
                    <table class="table table-hover table-striped attendance-table mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Date</th>
                                <th>Employee ID</th>
                                <th>Name</th>
                                <th>Time In</th>
                                <th>Lunch Out</th>
                                <th>Lunch In</th>
                                <th>Time Out</th>
                                <th>OT In</th>
                                <th>OT Out</th>
                                <th>Total Hours</th>
                                <th>Station</th>
                                <th>Remarks</th>
                                <?php if ($canOverride): ?><th>Override</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="attendanceTableBody">
                            <tr>
                                <td colspan="<?php echo $canOverride ? 13 : 12; ?>" class="text-center py-5 text-muted">
                                    <i class="fas fa-spinner fa-spin me-2"></i>Loading...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
            </main>
        </div>
    </div>

    <?php if ($canOverride): ?>
    <!-- Override Modal (super_admin only) -->
    <div class="modal fade" id="overrideModal" tabindex="-1" aria-labelledby="overrideModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="overrideModalLabel"><i class="fas fa-edit me-2"></i>Override Attendance Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small mb-3" id="overrideRecordInfo">—</p>
                    <form id="overrideForm">
                        <input type="hidden" name="id" id="overrideId">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="overrideTimeIn" class="form-label">Time In</label>
                                <input type="time" class="form-control" id="overrideTimeIn" name="time_in" step="60">
                            </div>
                            <div class="col-md-4">
                                <label for="overrideLunchOut" class="form-label">Lunch Out</label>
                                <input type="time" class="form-control" id="overrideLunchOut" name="lunch_out" step="60">
                            </div>
                            <div class="col-md-4">
                                <label for="overrideLunchIn" class="form-label">Lunch In</label>
                                <input type="time" class="form-control" id="overrideLunchIn" name="lunch_in" step="60">
                            </div>
                            <div class="col-md-4">
                                <label for="overrideTimeOut" class="form-label">Time Out</label>
                                <input type="time" class="form-control" id="overrideTimeOut" name="time_out" step="60">
                            </div>
                            <div class="col-md-4">
                                <label for="overrideOtIn" class="form-label">OT In</label>
                                <input type="time" class="form-control" id="overrideOtIn" name="ot_in" step="60">
                            </div>
                            <div class="col-md-4">
                                <label for="overrideOtOut" class="form-label">OT Out</label>
                                <input type="time" class="form-control" id="overrideOtOut" name="ot_out" step="60">
                            </div>
                            <div class="col-12">
                                <label for="overrideStation" class="form-label">Station</label>
                                <select class="form-select" id="overrideStation" name="station_id">
                                    <option value="">Unspecified</option>
                                    <?php foreach ($stations as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label for="overrideRemarks" class="form-label">Remarks</label>
                                <textarea class="form-control" id="overrideRemarks" name="remarks" rows="2" maxlength="500"></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="btnSaveOverride"><i class="fas fa-save me-1"></i>Save Override</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php admin_page_scripts(); ?>
    <script>
    (function() {
        const apiUrl = 'get_attendance_api.php';
        const downloadReportUrl = 'download_attendance_report.php';
        const canOverride = <?php echo $canOverride ? 'true' : 'false'; ?>;
        const DEBOUNCE_MS = 350;
        let debounceTimer = null;
        let loadInProgress = false;

        function formatTime(t) {
            if (!t) return '—';
            if (typeof t === 'string' && t.match(/^\d{2}:\d{2}/)) return t.substring(0, 5);
            try {
                const d = new Date(t);
                return isNaN(d.getTime()) ? '—' : d.toTimeString().slice(0, 5);
            } catch (e) { return '—'; }
        }

        function timeToInputValue(t) {
            if (!t) return '';
            if (typeof t === 'string' && t.match(/^\d{2}:\d{2}/)) return t.substring(0, 5);
            try {
                const d = new Date(t);
                return isNaN(d.getTime()) ? '' : d.toTimeString().slice(0, 5);
            } catch (e) { return ''; }
        }

        function formatDate(d) {
            if (!d) return '—';
            try {
                const dt = new Date(d);
                return isNaN(dt.getTime()) ? d : dt.toLocaleDateString();
            } catch (e) { return d; }
        }

        function calculateTotalHours(record) {
            let totalMinutes = 0;
            const parseTime = (t) => {
                if (!t) return null;
                if (typeof t === 'string' && t.match(/^\d{2}:\d{2}/)) {
                    const p = t.split(':');
                    return (parseInt(p[0]) || 0) * 60 + (parseInt(p[1]) || 0);
                }
                const d = new Date(t);
                return isNaN(d.getTime()) ? null : d.getHours() * 60 + d.getMinutes();
            };
            if (record.time_in && record.lunch_out) {
                const a = parseTime(record.time_in), b = parseTime(record.lunch_out);
                if (a !== null && b !== null && b > a) totalMinutes += (b - a);
            }
            if (record.lunch_in && record.time_out) {
                const a = parseTime(record.lunch_in), b = parseTime(record.time_out);
                if (a !== null && b !== null && b > a) totalMinutes += (b - a);
            }
            if (record.ot_in && record.ot_out) {
                const a = parseTime(record.ot_in), b = parseTime(record.ot_out);
                if (a !== null && b !== null && b > a) totalMinutes += (b - a);
            }
            if (totalMinutes <= 0) return '—';
            const h = Math.floor(totalMinutes / 60), m = Math.floor(totalMinutes % 60);
            return h + 'h ' + m + 'm';
        }

        function escapeHtml(s) {
            if (s == null || s === undefined) return '';
            const div = document.createElement('div');
            div.textContent = s;
            return div.innerHTML;
        }

        function buildUrl() {
            const viewMode = document.getElementById('viewMode').value;
            const search = document.getElementById('filterSearch').value.trim();
            const station = document.getElementById('filterStation').value;
            let url = apiUrl + '?';
            if (viewMode === 'range') {
                const from = document.getElementById('filterDateFrom').value;
                const to = document.getElementById('filterDateTo').value;
                if (from) url += 'date_from=' + encodeURIComponent(from) + '&';
                if (to) url += 'date_to=' + encodeURIComponent(to) + '&';
            } else {
                url += 'date=' + encodeURIComponent(document.getElementById('filterDate').value) + '&';
            }
            if (station) url += 'station_id=' + encodeURIComponent(station) + '&';
            if (search) url += 'search=' + encodeURIComponent(search) + '&';
            return url;
        }

        function buildDownloadReportUrl() {
            const search = document.getElementById('filterSearch').value.trim();
            const station = document.getElementById('filterStation').value;
            const date = document.getElementById('filterDate').value;
            let url = downloadReportUrl + '?date=' + encodeURIComponent(date || new Date().toISOString().slice(0, 10));
            if (station) url += '&station_id=' + encodeURIComponent(station);
            if (search) url += '&search=' + encodeURIComponent(search);
            return url;
        }

        function setDownloadReportEnabled() {
            const btn = document.getElementById('btnDownloadReport');
            if (!btn) return;
            const isRange = document.getElementById('viewMode').value === 'range';
            btn.disabled = isRange;
            btn.title = isRange
                ? 'Switch to Single Date to download a one-day report'
                : 'Download styled Excel report for the selected date (station and search filters apply)';
        }

        function setLoading(loading) {
            loadInProgress = loading;
            const badge = document.getElementById('loadingBadge');
            const wrapper = document.getElementById('tableWrapper');
            if (badge) badge.style.display = loading ? 'inline-block' : 'none';
            if (wrapper) wrapper.classList.toggle('attendance-loading', loading);
        }

        async function loadAttendance() {
            const tbody = document.getElementById('attendanceTableBody');
            const countEl = document.getElementById('recordCount');
            setLoading(true);
            tbody.innerHTML = '<tr><td colspan="' + (canOverride ? 13 : 12) + '" class="text-center py-5"><i class="fas fa-spinner fa-spin me-2"></i>Loading attendance...</td></tr>';
            try {
                const res = await fetch(buildUrl());
                const data = await res.json();
                if (!data.success) {
                    tbody.innerHTML = '<tr><td colspan="' + (canOverride ? 13 : 12) + '" class="text-center py-5 text-danger"><i class="fas fa-exclamation-circle me-2"></i>' + escapeHtml(data.message || 'Failed to load attendance') + '</td></tr>';
                    countEl.textContent = '0 records';
                    return;
                }
                const records = data.attendance || [];
                countEl.textContent = records.length + ' record' + (records.length !== 1 ? 's' : '');
                if (records.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="' + (canOverride ? 13 : 12) + '" class="text-center py-5 text-muted"><i class="fas fa-inbox me-2"></i>No attendance records found. Try a different date or search.</td></tr>';
                    return;
                }
                const recordsById = {};
                records.forEach(function(r) { recordsById[r.id] = r; });
                tbody.innerHTML = records.map(r => {
                    const totalHours = calculateTotalHours(r);
                    const overrideBtn = canOverride
                        ? '<td data-label="Override"><button type="button" class="btn btn-sm btn-outline-warning override-btn" data-id="' + escapeHtml(String(r.id)) + '" title="Override entry"><i class="fas fa-edit"></i></button></td>'
                        : '';
                    return '<tr>' +
                        '<td data-label="Date">' + escapeHtml(formatDate(r.log_date)) + '</td>' +
                        '<td data-label="Employee ID"><strong>' + escapeHtml(r.employee_id || 'N/A') + '</strong></td>' +
                        '<td data-label="Name">' + escapeHtml(r.name || 'N/A') + '</td>' +
                        '<td data-label="Time In">' + escapeHtml(formatTime(r.time_in)) + '</td>' +
                        '<td data-label="Lunch Out">' + escapeHtml(formatTime(r.lunch_out)) + '</td>' +
                        '<td data-label="Lunch In">' + escapeHtml(formatTime(r.lunch_in)) + '</td>' +
                        '<td data-label="Time Out">' + escapeHtml(formatTime(r.time_out)) + '</td>' +
                        '<td data-label="OT In">' + escapeHtml(formatTime(r.ot_in)) + '</td>' +
                        '<td data-label="OT Out">' + escapeHtml(formatTime(r.ot_out)) + '</td>' +
                        '<td data-label="Total Hours"><strong>' + escapeHtml(totalHours) + '</strong></td>' +
                        '<td data-label="Station">' + (r.station_name ? '<span class="badge bg-secondary badge-station">' + escapeHtml(r.station_name) + '</span>' : '—') + '</td>' +
                        '<td data-label="Remarks">' + escapeHtml((r.remarks || '').substring(0, 50)) + (r.remarks && r.remarks.length > 50 ? '…' : '') + '</td>' +
                        overrideBtn +
                        '</tr>';
                }).join('');

                if (canOverride) {
                    tbody.querySelectorAll('.override-btn').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            const rec = recordsById[this.getAttribute('data-id')];
                            if (!rec) return;
                            document.getElementById('overrideId').value = rec.id || '';
                            document.getElementById('overrideRecordInfo').textContent = (rec.employee_id || '') + ' — ' + (rec.name || '') + ' — ' + (rec.log_date || '');
                            document.getElementById('overrideTimeIn').value = timeToInputValue(rec.time_in);
                            document.getElementById('overrideLunchOut').value = timeToInputValue(rec.lunch_out);
                            document.getElementById('overrideLunchIn').value = timeToInputValue(rec.lunch_in);
                            document.getElementById('overrideTimeOut').value = timeToInputValue(rec.time_out);
                            document.getElementById('overrideOtIn').value = timeToInputValue(rec.ot_in);
                            document.getElementById('overrideOtOut').value = timeToInputValue(rec.ot_out);
                            document.getElementById('overrideRemarks').value = rec.remarks || '';
                            document.getElementById('overrideStation').value = rec.station_id || '';
                            const modal = new bootstrap.Modal(document.getElementById('overrideModal'));
                            modal.show();
                        });
                    });
                }
            } catch (err) {
                tbody.innerHTML = '<tr><td colspan="' + (canOverride ? 13 : 12) + '" class="text-center py-5 text-danger"><i class="fas fa-exclamation-circle me-2"></i>Error: ' + escapeHtml(err.message) + '</td></tr>';
                countEl.textContent = '0 records';
            } finally {
                setLoading(false);
            }
        }

        function debouncedLoad() {
            if (debounceTimer) clearTimeout(debounceTimer);
            debounceTimer = setTimeout(loadAttendance, DEBOUNCE_MS);
        }

        function immediateLoad() {
            if (debounceTimer) clearTimeout(debounceTimer);
            loadAttendance();
        }

        function updateClearButton() {
            const search = document.getElementById('filterSearch');
            const btn = document.getElementById('btnClearSearch');
            if (btn) btn.style.visibility = search.value.trim() ? 'visible' : 'hidden';
        }

        document.getElementById('viewMode').addEventListener('change', function() {
            const isRange = this.value === 'range';
            document.getElementById('singleDateGroup').classList.toggle('d-none', isRange);
            document.getElementById('dateRangeGroup').classList.toggle('d-none', !isRange);
            document.getElementById('dateRangeGroupTo').classList.toggle('d-none', !isRange);
            if (isRange) {
                const today = new Date().toISOString().slice(0, 10);
                const first = new Date();
                first.setDate(1);
                document.getElementById('filterDateFrom').value = first.toISOString().slice(0, 10);
                document.getElementById('filterDateTo').value = today;
            }
            setDownloadReportEnabled();
            immediateLoad();
        });

        document.getElementById('filterDate').addEventListener('change', immediateLoad);
        document.getElementById('filterDateFrom').addEventListener('change', immediateLoad);
        document.getElementById('filterDateTo').addEventListener('change', immediateLoad);
        document.getElementById('filterStation').addEventListener('change', immediateLoad);

        document.getElementById('filterSearch').addEventListener('input', function() {
            updateClearButton();
            debouncedLoad();
        });
        document.getElementById('filterSearch').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); immediateLoad(); }
        });

        document.getElementById('btnToday').addEventListener('click', function() {
            document.getElementById('filterDate').value = new Date().toISOString().slice(0, 10);
            immediateLoad();
        });

        document.getElementById('btnClearSearch').addEventListener('click', function() {
            document.getElementById('filterSearch').value = '';
            updateClearButton();
            immediateLoad();
        });

        document.getElementById('btnRefresh').addEventListener('click', immediateLoad);
        document.getElementById('btnReset').addEventListener('click', function() {
            document.getElementById('viewMode').value = 'single';
            document.getElementById('filterDate').value = new Date().toISOString().slice(0, 10);
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterStation').value = '';
            document.getElementById('filterSearch').value = '';
            document.getElementById('singleDateGroup').classList.remove('d-none');
            document.getElementById('dateRangeGroup').classList.add('d-none');
            document.getElementById('dateRangeGroupTo').classList.add('d-none');
            updateClearButton();
            setDownloadReportEnabled();
            immediateLoad();
        });

        document.getElementById('btnDownloadReport').addEventListener('click', function() {
            if (document.getElementById('viewMode').value === 'range') {
                return;
            }
            window.location.href = buildDownloadReportUrl();
        });

        updateClearButton();
        setDownloadReportEnabled();
        loadAttendance();

        if (canOverride) {
            document.getElementById('btnSaveOverride').addEventListener('click', async function() {
                const id = document.getElementById('overrideId').value;
                if (!id) return;
                const btn = this;
                btn.disabled = true;
                try {
                    const fd = new FormData(document.getElementById('overrideForm'));
                    const res = await fetch('update_attendance_api.php', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        bootstrap.Modal.getInstance(document.getElementById('overrideModal')).hide();
                        immediateLoad();
                    } else {
                        alert(data.message || 'Update failed.');
                    }
                } catch (e) {
                    alert('Request failed. Please try again.');
                } finally {
                    btn.disabled = false;
                }
            });
        }
    })();
    </script>
</body>
</html>
