<?php
/**
 * My Assigned Employees - For employees (faculty/staff) with pardon_opener_assignments.
 * View employees in their scope and open pardon for them.
 * Only shown in sidebar when the user has been given an assignment in Settings → Pardon Openers.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAuth();

$database = Database::getInstance();
$db = $database->getConnection();

// Only employees (faculty/staff) with pardon opener assignments can access
if (!isFaculty() && !isStaff()) {
    $_SESSION['error'] = 'This page is for employees with pardon opener assignments.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/admin/dashboard.php', $basePath));
}

if (!hasPardonOpenerAssignments($_SESSION['user_id'], $db)) {
    $_SESSION['error'] = 'You do not have any pardon opener assignments. Contact HR to configure in Settings → Pardon Openers.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/faculty/dashboard.php', $basePath));
}

// Super admin uses Employees DTR; redirect them
if (isSuperAdmin()) {
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/admin/dashboard.php', $basePath));
}

// Get current user's employee_id to exclude self (pardon opener cannot open pardon for themselves)
$currentUserEmployeeId = '';
$stmtMe = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
$stmtMe->execute([$_SESSION['user_id']]);
$me = $stmtMe->fetch(PDO::FETCH_ASSOC);
if ($me && !empty($me['employee_id'])) {
    $currentUserEmployeeId = trim($me['employee_id']);
}

// Get user's department scopes and designation scopes from pardon_opener_assignments
$deptScopes = [];
$desigScopes = [];
try {
    $stmt = $db->prepare("SELECT scope_type, scope_value FROM pardon_opener_assignments WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $val = trim($row['scope_value'] ?? '');
        if ($val === '') continue;
        if ($row['scope_type'] === 'department') {
            $deptScopes[] = $val;
        } else {
            $desigScopes[] = $val;
        }
    }
} catch (Exception $e) {}

// Build employee list: faculty and staff matching department OR designation scope (exclude self)
$facultyEmployees = [];
$seenIds = [];

if (!empty($deptScopes)) {
    $placeholders = implode(',', array_fill(0, count($deptScopes), '?'));
    $stmt = $db->prepare("SELECT fp.employee_id, fp.position, fp.department, fp.designation,
        COALESCE(u.first_name, '') as first_name, COALESCE(u.last_name, '') as last_name,
        u.user_type
        FROM faculty_profiles fp
        LEFT JOIN users u ON fp.user_id = u.id
        WHERE u.user_type IN ('faculty', 'staff') AND u.is_active = 1
        AND LOWER(TRIM(COALESCE(fp.department, ''))) IN ($placeholders)
        ORDER BY fp.department, u.last_name, u.first_name");
    $stmt->execute(array_map('strtolower', array_map('trim', $deptScopes)));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!in_array($row['employee_id'], $seenIds) && $row['employee_id'] !== $currentUserEmployeeId) {
            $seenIds[] = $row['employee_id'];
            $facultyEmployees[] = $row;
        }
    }
}

if (!empty($desigScopes)) {
    $placeholders = implode(',', array_fill(0, count($desigScopes), '?'));
    $stmt = $db->prepare("SELECT fp.employee_id, fp.position, fp.department, fp.designation,
        COALESCE(u.first_name, '') as first_name, COALESCE(u.last_name, '') as last_name,
        u.user_type
        FROM faculty_profiles fp
        LEFT JOIN users u ON fp.user_id = u.id
        WHERE u.user_type IN ('faculty', 'staff') AND u.is_active = 1
        AND TRIM(COALESCE(fp.designation, '')) != ''
        AND LOWER(TRIM(fp.designation)) IN ($placeholders)
        ORDER BY fp.designation, u.last_name, u.first_name");
    $stmt->execute(array_map('strtolower', array_map('trim', $desigScopes)));
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!in_array($row['employee_id'], $seenIds) && $row['employee_id'] !== $currentUserEmployeeId) {
            $seenIds[] = $row['employee_id'];
            $facultyEmployees[] = $row;
        }
    }
}

// Sort by name
usort($facultyEmployees, function($a, $b) {
    $na = trim(($a['last_name'] ?? '') . ', ' . ($a['first_name'] ?? ''));
    $nb = trim(($b['last_name'] ?? '') . ', ' . ($b['first_name'] ?? ''));
    return strcasecmp($na, $nb);
});

// Check which employees have official time (for showing Official Time button)
$employeeIds = array_column($facultyEmployees, 'employee_id');
$hasOfficialTime = [];
if (!empty($employeeIds)) {
    $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));
    $stmtOT = $db->prepare("SELECT DISTINCT employee_id FROM employee_official_times WHERE employee_id IN ($placeholders)");
    $stmtOT->execute($employeeIds);
    while ($row = $stmtOT->fetch(PDO::FETCH_ASSOC)) {
        $hasOfficialTime[trim($row['employee_id'])] = true;
    }
}
foreach ($facultyEmployees as &$emp) {
    $emp['has_official_time'] = !empty($hasOfficialTime[trim($emp['employee_id'] ?? '')]);
}
unset($emp);

// Faculty/staff always use faculty APIs
$basePath = getBasePath();
$fetchLogsUrl = $basePath . '/faculty/fetch_department_logs_api.php';
$openPardonUrl = $basePath . '/faculty/open_pardon_api.php';
$closePardonUrl = $basePath . '/faculty/close_pardon_api.php';

$scopeLabels = array_merge(
    array_map(function($v) { return 'Dept: ' . $v; }, $deptScopes),
    array_map(function($v) { return 'Desig: ' . $v; }, $desigScopes)
);

$manageOfficialTimesApiUrl = $basePath . '/admin/manage_official_times_api.php';

require_once '../includes/navigation.php';
include_navigation();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Assigned Employees - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <style>
        .my-assigned-page .page-header { padding: 1.25rem 0; margin-bottom: 0.5rem; }
        .my-assigned-page .page-title { font-size: 1.5rem; font-weight: 600; color: var(--primary-blue, #003366); display: flex; align-items: center; gap: 0.5rem; }
        .my-assigned-page .dept-dtr-card { border-radius: var(--radius-lg, 12px); border: 1px solid var(--border-light, #f1f5f9); overflow: hidden; }
        .my-assigned-page .table thead { background: var(--light-blue, #e7f1ff); position: sticky; top: 0; z-index: 10; }
        .my-assigned-page .view-dtr-btn { min-width: 88px; border-radius: var(--radius-md, 8px); }
        #dtrModal.modal, #dtrModal.modal.show { z-index: 1060 !important; }
        #dtrModal .modal-dialog { max-width: 720px; z-index: 1061 !important; }
        #dtrModal .dtr-form-wrap { font-family: "Times New Roman", Times, serif; color: #000; background: #fff; padding: 1.25rem; border: 1px solid #000; }
        #dtrModal .dtr-form-title { font-size: 1rem; font-weight: 700; text-align: center; margin-bottom: 0.15rem; }
        #dtrModal .dtr-form-subtitle { font-size: 0.95rem; font-weight: 700; text-align: center; margin-bottom: 0.15rem; }
        #dtrModal .dtr-form-line { text-align: center; font-size: 0.8rem; letter-spacing: 0.2em; margin-bottom: 0.75rem; }
        #dtrModal .dtr-field-row { font-size: 0.9rem; margin-bottom: 0.35rem; }
        #dtrModal .dtr-field-inline { display: inline-block; border-bottom: 1px solid #000; min-width: 180px; margin-left: 0.35rem; padding: 0 0.25rem 0.1rem; font-size: 0.9rem; }
        #dtrModal .dtr-table { font-size: 0.8rem; table-layout: fixed; width: 100%; border-collapse: collapse; }
        #dtrModal .dtr-table th, #dtrModal .dtr-table td { padding: 0.2rem 0.25rem; vertical-align: middle; border: 1px solid #000; }
        #dtrModal .dtr-table th { background: #fff; font-weight: 700; text-align: center; }
        #dtrModal .dtr-table .dtr-day { width: 2.25em; text-align: center; }
        #dtrModal .dtr-table .dtr-time { width: 4em; text-align: center; }
        #dtrModal .dtr-table .dtr-undertime { width: 2.75em; text-align: center; }
        #dtrModal .dtr-table tbody tr.dtr-total { font-weight: 700; }
        #dtrModal .dtr-official-row { font-size: 0.9rem; margin-bottom: 0.5rem; }
        #dtrModal .dtr-certify { font-size: 0.8rem; margin-top: 0.75rem; margin-bottom: 0.25rem; line-height: 1.35; }
        #dtrModal .dtr-verified { font-size: 0.8rem; margin-top: 1rem; margin-bottom: 0; }
        #dtrModal .dtr-loading-overlay { position: absolute; inset: 0; background: rgba(255,255,255,0.9); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 10; border-radius: inherit; }
        #dtrModal .dtr-table-wrap { position: relative; }
        .dtr-scroll-hint { font-size: 0.75rem; color: #64748b; margin-top: 0.5rem; }
    </style>
</head>
<body class="layout-faculty my-assigned-page">
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header">
                    <div class="page-title"><i class="fas fa-user-shield me-2"></i>My Assigned Employees</div>
                    <p class="page-subtitle text-muted">View and open pardon for employees in your scope.</p>
                </div>

                <?php displayMessage(); ?>

                <div class="card mb-4 shadow-sm dept-dtr-card">
                    <div class="card-header dept-dtr-card-header bg-light">
                        <div class="row align-items-center flex-wrap g-2">
                            <div class="col-12 col-md-auto">
                                <h5 class="mb-0"><i class="fas fa-list me-2"></i>People Assigned to You <span class="badge bg-primary ms-2"><?php echo count($facultyEmployees); ?></span></h5>
                                <small class="text-muted"><?php echo htmlspecialchars(implode('; ', $scopeLabels)); ?></small>
                            </div>
                            <div class="col-12 col-md-4 col-lg-3">
                                <input type="search" id="employeeSearch" class="form-control form-control-sm" placeholder="Search name, ID, position..." autocomplete="off">
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
                                        <th>Safe Employee ID</th>
                                        <th>Position</th>
                                        <th>Department</th>
                                        <th>Designation</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="employeeTableBody">
                                    <?php 
                                    $idx = 0;
                                    foreach ($facultyEmployees as $emp): 
                                        $idx++;
                                        $name = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
                                        $name = $name !== '' ? $name : ('Faculty ' . $emp['employee_id']);
                                        $searchText = strtolower($name . ' ' . $emp['employee_id'] . ' ' . ($emp['position'] ?? '') . ' ' . ($emp['department'] ?? ''));
                                    ?>
                                        <tr class="employee-row" data-search="<?php echo htmlspecialchars($searchText); ?>" data-name="<?php echo htmlspecialchars($name); ?>" data-employee-id="<?php echo htmlspecialchars($emp['employee_id']); ?>" data-user-type="<?php echo htmlspecialchars($emp['user_type'] ?? 'faculty'); ?>">
                                            <td><?php echo $idx; ?></td>
                                            <td class="fw-medium"><?php echo htmlspecialchars($name); ?> <?php $ut = $emp['user_type'] ?? 'faculty'; ?><span class="badge bg-<?php echo $ut === 'staff' ? 'secondary' : 'primary'; ?> ms-1" style="font-size:0.65rem;"><?php echo $ut === 'staff' ? 'Staff' : 'Faculty'; ?></span></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($emp['employee_id']); ?></span></td>
                                            <td><?php echo htmlspecialchars($emp['position'] ?? '—'); ?></td>
                                            <td><?php echo htmlspecialchars($emp['department'] ?? '—'); ?></td>
                                            <td><?php echo htmlspecialchars($emp['designation'] ?? '—'); ?></td>
                                            <td class="text-end">
                                                <?php if (!empty($emp['has_official_time'])): ?>
                                                <button type="button" class="btn btn-info btn-sm view-official-time-btn me-1" 
                                                    data-employee-id="<?php echo htmlspecialchars($emp['employee_id']); ?>" 
                                                    data-name="<?php echo htmlspecialchars($name); ?>"
                                                    title="View Official Time">
                                                    <i class="fas fa-clock me-1"></i> Official Time
                                                </button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-primary btn-sm view-dtr-btn" 
                                                    data-employee-id="<?php echo htmlspecialchars($emp['employee_id']); ?>" 
                                                    data-name="<?php echo htmlspecialchars($name); ?>"
                                                    title="View DTR">
                                                    <i class="fas fa-eye me-1"></i> View DTR
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($facultyEmployees)): ?>
                                        <tr>
                                            <td colspan="7">
                                                <div class="text-center py-4 text-muted">
                                                    <i class="fas fa-users-slash fa-2x mb-2"></i>
                                                    <p class="mb-0">No employees match your pardon opener scope.</p>
                                                    <p class="small mb-0 mt-1">Ensure employees in your assigned department/designation have their profile department set correctly (Admin → Edit Faculty).</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Official Time Modal -->
    <div class="modal fade" id="officialTimeModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Official Time — <span id="officialTimeEmployeeName"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="officialTimeLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 text-muted mb-0">Loading official times...</p>
                    </div>
                    <div id="officialTimeContent" style="display: none;">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered">
                                <thead class="table-light">
                                    <tr>
                                        <th>Start Date</th>
                                        <th>End Date</th>
                                        <th>Weekday</th>
                                        <th>Time In</th>
                                        <th>Lunch Out</th>
                                        <th>Lunch In</th>
                                        <th>Time Out</th>
                                    </tr>
                                </thead>
                                <tbody id="officialTimeTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div id="officialTimeEmpty" class="text-center text-muted py-4" style="display: none;">
                        <i class="fas fa-info-circle fa-2x mb-2"></i>
                        <p class="mb-0">No official times have been set for this employee.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- DTR Modal -->
    <div class="modal fade" id="dtrModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title">Faculty Daily Time Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body position-relative">
                    <div id="dtrLoadingOverlay" class="dtr-loading-overlay d-none">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p class="mt-2 mb-0 small text-muted">Loading time records...</p>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="dtrMonth">Month</label>
                            <select id="dtrMonth" class="form-select form-select-sm" onchange="renderDTR()">
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?>" <?php echo ($i === (int)date('n')) ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $i, 1)); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="dtrYear">Year</label>
                            <select id="dtrYear" class="form-select form-select-sm" onchange="renderDTR()">
                                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($y === (int)date('Y')) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>
                    <div class="dtr-form-wrap">
                        <div class="dtr-form-title">Civil Service Form No. 48</div>
                        <div class="dtr-form-subtitle">DAILY TIME RECORD</div>
                        <div class="dtr-form-line">-----o0o-----</div>
                        <div class="dtr-field-row">(Name) <span id="dtrEmployeeName" class="dtr-field-inline"></span></div>
                        <div class="dtr-field-row">For the month of <span id="dtrMonthLabel" class="dtr-field-inline"></span></div>
                        <div class="dtr-official-row">Official hours for arrival and departure</div>
                        <div class="dtr-official-row">Regular days <span id="dtrOfficialRegular" class="dtr-field-inline">08:00-12:00, 13:00-17:00</span> Saturdays <span id="dtrOfficialSat" class="dtr-field-inline">—</span></div>
                        <div class="dtr-table-wrap">
                        <p class="dtr-scroll-hint"><i class="fas fa-arrows-alt-h me-1"></i>Scroll horizontally to see all columns</p>
                        <table class="dtr-table" role="grid">
                            <thead>
                                <tr>
                                    <th class="dtr-day">Day</th>
                                    <th colspan="2">A.M.</th>
                                    <th colspan="2">P.M.</th>
                                    <th colspan="2">Undertime</th>
                                    <th class="dtr-pardon">Pardon</th>
                                </tr>
                                <tr>
                                    <th></th>
                                    <th class="dtr-time">Arrival</th>
                                    <th class="dtr-time">Departure</th>
                                    <th class="dtr-time">Arrival</th>
                                    <th class="dtr-time">Departure</th>
                                    <th class="dtr-undertime">Hours</th>
                                    <th class="dtr-undertime">Minutes</th>
                                    <th class="dtr-pardon">Open for faculty</th>
                                </tr>
                            </thead>
                            <tbody id="dtrTableBody"></tbody>
                        </table>
                        </div>
                        <p class="dtr-certify">I certify on my honor that the above is a true and correct report of the hours of work performed.</p>
                        <p class="dtr-verified">VERIFIED as to the prescribed office hours:<br>In Charge<br><strong id="dtrInCharge" class="dtr-incharge"></strong></p>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <script>
    var selectedEmployeeId = null;
    var selectedEmployeeName = '';
    var pardonOpenDates = [];
    var fetchLogsUrl = '<?php echo addslashes($fetchLogsUrl); ?>';
    var openPardonUrl = '<?php echo addslashes($openPardonUrl); ?>';
    var closePardonUrl = '<?php echo addslashes($closePardonUrl); ?>';
    var manageOfficialTimesApiUrl = '<?php echo addslashes($manageOfficialTimesApiUrl); ?>';

    document.querySelectorAll('.view-official-time-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var empId = this.getAttribute('data-employee-id');
            var empName = this.getAttribute('data-name') || '';
            document.getElementById('officialTimeEmployeeName').textContent = empName;
            document.getElementById('officialTimeLoading').style.display = 'block';
            document.getElementById('officialTimeContent').style.display = 'none';
            document.getElementById('officialTimeEmpty').style.display = 'none';
            var modal = new bootstrap.Modal(document.getElementById('officialTimeModal'));
            modal.show();
            fetch(manageOfficialTimesApiUrl + '?action=get&employee_id=' + encodeURIComponent(empId))
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    document.getElementById('officialTimeLoading').style.display = 'none';
                    if (data.success && data.official_times && data.official_times.length > 0) {
                        var tbody = document.getElementById('officialTimeTableBody');
                        tbody.innerHTML = '';
                        data.official_times.forEach(function(ot) {
                            var endDate = ot.end_date || '—';
                            var tr = '<tr><td>' + (ot.start_date || '—') + '</td><td>' + endDate + '</td><td>' + (ot.weekday || '—') + '</td><td>' + (ot.time_in || '—') + '</td><td>' + (ot.lunch_out || '—') + '</td><td>' + (ot.lunch_in || '—') + '</td><td>' + (ot.time_out || '—') + '</td></tr>';
                            tbody.insertAdjacentHTML('beforeend', tr);
                        });
                        document.getElementById('officialTimeContent').style.display = 'block';
                    } else {
                        document.getElementById('officialTimeEmpty').style.display = 'block';
                    }
                })
                .catch(function() {
                    document.getElementById('officialTimeLoading').style.display = 'none';
                    document.getElementById('officialTimeEmpty').style.display = 'block';
                    document.getElementById('officialTimeEmpty').innerHTML = '<p class="text-danger mb-0">Error loading official times. Please try again.</p>';
                });
        });
    });

    document.querySelectorAll('.view-dtr-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            selectedEmployeeId = this.getAttribute('data-employee-id');
            selectedEmployeeName = this.getAttribute('data-name') || '';
            document.getElementById('dtrEmployeeName').textContent = selectedEmployeeName;
            var modal = new bootstrap.Modal(document.getElementById('dtrModal'));
            modal.show();
            document.getElementById('dtrLoadingOverlay').classList.remove('d-none');
            var month = document.getElementById('dtrMonth').value;
            var year = document.getElementById('dtrYear').value;
            var dateFrom = year + '-' + month + '-01';
            var lastDay = new Date(year, month, 0).getDate();
            var dateTo = year + '-' + month + '-' + String(lastDay).padStart(2, '0');
            fetch(fetchLogsUrl + '?employee_id=' + encodeURIComponent(selectedEmployeeId) + '&date_from=' + dateFrom + '&date_to=' + dateTo)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    document.getElementById('dtrLoadingOverlay').classList.add('d-none');
                    if (data.success && data.logs) {
                        pardonOpenDates = data.pardon_open_dates || [];
                        renderDTR();
                    }
                })
                .catch(function() {
                    document.getElementById('dtrLoadingOverlay').classList.add('d-none');
                });
        });
    });

    document.getElementById('employeeSearch').addEventListener('input', function() {
        var q = this.value.toLowerCase().trim();
        document.querySelectorAll('.employee-row').forEach(function(row) {
            var show = !q || (row.getAttribute('data-search') || '').indexOf(q) !== -1;
            row.style.display = show ? '' : 'none';
        });
    });

    function renderDTR() {
        var tbody = document.getElementById('dtrTableBody');
        if (!tbody || !selectedEmployeeId) return;
        var month = document.getElementById('dtrMonth').value;
        var year = document.getElementById('dtrYear').value;
        var monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];
        document.getElementById('dtrMonthLabel').textContent = monthNames[parseInt(month, 10) - 1] + ' ' + year;
        var dateFrom = year + '-' + month + '-01';
        var lastDay = new Date(year, month, 0).getDate();
        var dateTo = year + '-' + month + '-' + String(lastDay).padStart(2, '0');
        document.getElementById('dtrLoadingOverlay').classList.remove('d-none');
        fetch(fetchLogsUrl + '?employee_id=' + encodeURIComponent(selectedEmployeeId) + '&date_from=' + dateFrom + '&date_to=' + dateTo)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                document.getElementById('dtrLoadingOverlay').classList.add('d-none');
                if (!data.success || !data.logs) return;
                pardonOpenDates = data.pardon_open_dates || [];
                var inCharge = (data.dean_name || '') + (data.dean_department ? ', ' + data.dean_department : '');
                var inChargeEl = document.getElementById('dtrInCharge');
                if (inChargeEl) inChargeEl.textContent = inCharge || '—';
                var logsByDate = {};
                (data.logs || []).forEach(function(l) {
                    logsByDate[l.log_date || l.date] = l;
                });
                tbody.innerHTML = '';
                for (var d = 1; d <= lastDay; d++) {
                    var dateKey = year + '-' + month + '-' + String(d).padStart(2, '0');
                    var log = logsByDate[dateKey];
                    var timeIn = log && log.time_in ? log.time_in : '—';
                    var lunchOut = log && log.lunch_out ? log.lunch_out : '—';
                    var lunchIn = log && log.lunch_in ? log.lunch_in : '—';
                    var timeOut = log && log.time_out ? log.time_out : '—';
                    var utHrs = log && log.undertime_hours != null ? log.undertime_hours : '—';
                    var utMin = log && log.undertime_minutes != null ? log.undertime_minutes : '—';
                    var open = log && log.pardon_open;
                    var pardonStatus = log && log.pardon_status;
                    var pardonCell;
                    if (pardonStatus === 'approved') {
                        pardonCell = '<td class="dtr-pardon small"><span class="badge bg-success">Approved</span></td>';
                    } else if (pardonStatus === 'rejected') {
                        pardonCell = '<td class="dtr-pardon small"><span class="badge bg-danger">Rejected</span></td>';
                    } else if (open) {
                        pardonCell = '<td class="dtr-pardon small"><span class="badge bg-info">Opened</span> <button type="button" class="btn btn-sm btn-outline-secondary close-pardon-btn" data-date="' + dateKey + '" title="Undo">Close</button></td>';
                    } else {
                        pardonCell = '<td class="dtr-pardon small"><button type="button" class="btn btn-sm btn-outline-primary open-pardon-btn" data-date="' + dateKey + '" title="Allow faculty to submit pardon">Open</button></td>';
                    }
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td class="dtr-day">' + d + '</td><td class="dtr-time">' + timeIn + '</td><td class="dtr-time">' + lunchOut + '</td><td class="dtr-time">' + lunchIn + '</td><td class="dtr-time">' + timeOut + '</td><td class="dtr-undertime">' + utHrs + '</td><td class="dtr-undertime">' + utMin + '</td>' + pardonCell;
                    tbody.appendChild(tr);
                }
                var totalRow = document.createElement('tr');
                totalRow.className = 'dtr-total';
                totalRow.innerHTML = '<td class="dtr-day">Total</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>';
                tbody.appendChild(totalRow);
                tbody.querySelectorAll('.open-pardon-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var date = this.getAttribute('data-date');
                        if (!date || !selectedEmployeeId) return;
                        var f = new FormData();
                        f.append('employee_id', selectedEmployeeId);
                        f.append('log_date', date);
                        btn.disabled = true;
                        fetch(openPardonUrl, { method: 'POST', body: f })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    if (data.pardon_open_dates) pardonOpenDates = data.pardon_open_dates;
                                    renderDTR();
                                } else {
                                    alert(data.message || 'Failed to open pardon');
                                    btn.disabled = false;
                                }
                            })
                            .catch(function() {
                                alert('Request failed. Please try again.');
                                btn.disabled = false;
                            });
                    });
                });
                tbody.querySelectorAll('.close-pardon-btn').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var date = this.getAttribute('data-date');
                        if (!date || !selectedEmployeeId) return;
                        var f = new FormData();
                        f.append('employee_id', selectedEmployeeId);
                        f.append('log_date', date);
                        btn.disabled = true;
                        fetch(closePardonUrl, { method: 'POST', body: f })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    if (data.pardon_open_dates) pardonOpenDates = data.pardon_open_dates;
                                    renderDTR();
                                } else {
                                    alert(data.message || 'Failed to close pardon');
                                    btn.disabled = false;
                                }
                            })
                            .catch(function() {
                                alert('Request failed. Please try again.');
                                btn.disabled = false;
                            });
                    });
                });
            })
            .catch(function() {
                document.getElementById('dtrLoadingOverlay').classList.add('d-none');
            });
    }
    </script>
</body>
</html>
