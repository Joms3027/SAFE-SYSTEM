<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

// Ensure dtr_daily_submissions table exists
$tableCheck = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
if (!$tableCheck || $tableCheck->rowCount() === 0) {
    $_SESSION['error'] = 'DTR submissions are not configured. Please run the database migration.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/admin/dashboard.php', $basePath));
}

$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$monthFilter = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$departmentFilter = isset($_GET['department']) ? trim($_GET['department']) : '';
$employeeTypeFilter = isset($_GET['employee_type']) ? trim($_GET['employee_type']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$dateFrom = sprintf('%04d-%02d-01', $yearFilter, $monthFilter);
$lastDay = (int) date('t', mktime(0, 0, 0, $monthFilter, 1, $yearFilter));
$dateTo = sprintf('%04d-%02d-%02d', $yearFilter, $monthFilter, $lastDay);

$whereClause = "ds.log_date >= ? AND ds.log_date <= ?";
$params = [$dateFrom, $dateTo];

// This page shows only DTRs verified and endorsed by those assigned (Dean or Pardon Opener).
// Super admin/admin cannot verify here; verification is done in faculty portal by Dean or Pardon Opener.
$hasVerifiedColumn = false;
$colCheck = $db->query("SHOW COLUMNS FROM dtr_daily_submissions LIKE 'dean_verified_at'");
$hasPardonOpenerTable = false;
$tblCheck = $db->query("SHOW TABLES LIKE 'pardon_opener_assignments'");
if ($tblCheck && $tblCheck->rowCount() > 0) {
    $hasPardonOpenerTable = true;
}
$hasVerifiedByColumn = false;
$colCheckVerifiedBy = $db->query("SHOW COLUMNS FROM dtr_daily_submissions LIKE 'dean_verified_by'");
if ($colCheckVerifiedBy && $colCheckVerifiedBy->rowCount() > 0) {
    $hasVerifiedByColumn = true;
}
if ($colCheck && $colCheck->rowCount() > 0) {
    $hasVerifiedColumn = true;
    // Only show DTRs that have been verified by Dean or assigned Pardon Opener.
    // Faculty: show when dean_verified_at. Staff: show when verified AND in pardon opener's scope.
    if ($hasPardonOpenerTable) {
        $whereClause .= " AND (
            (ds.dean_verified_at IS NOT NULL AND u.user_type = 'faculty')
            OR (ds.dean_verified_at IS NOT NULL AND u.user_type = 'staff' AND EXISTS (
                SELECT 1 FROM pardon_opener_assignments poa
                WHERE (
                    (poa.scope_type = 'department' AND TRIM(COALESCE(fp.department, '')) != '' AND LOWER(TRIM(poa.scope_value)) = LOWER(TRIM(fp.department)))
                    OR (poa.scope_type = 'designation' AND TRIM(poa.scope_value) != '' AND TRIM(COALESCE(fp.designation, '')) != '' AND LOWER(TRIM(poa.scope_value)) = LOWER(TRIM(fp.designation)))
                )
            ))
        )";
    } else {
        $whereClause .= " AND ds.dean_verified_at IS NOT NULL";
    }
}

if ($departmentFilter !== '') {
    $whereClause .= " AND COALESCE(fp.department, '') = ?";
    $params[] = $departmentFilter;
}

if ($employeeTypeFilter === 'faculty' || $employeeTypeFilter === 'staff') {
    $whereClause .= " AND u.user_type = ?";
    $params[] = $employeeTypeFilter;
}

if ($search !== '') {
    $whereClause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR fp.employee_id LIKE ? OR COALESCE(fp.department, '') LIKE ?)";
    $term = '%' . $search . '%';
    $params = array_merge($params, [$term, $term, $term, $term]);
}

$countSql = "SELECT COUNT(DISTINCT ds.user_id) FROM dtr_daily_submissions ds
    INNER JOIN users u ON ds.user_id = u.id
    LEFT JOIN faculty_profiles fp ON fp.user_id = u.id
    WHERE $whereClause";

$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// $hasVerifiedColumn already set above for admin verified-only filter

$sql = "SELECT ds.user_id,
        u.first_name, u.last_name, u.email,
        fp.employee_id, fp.department, fp.position, fp.designation,
        COUNT(ds.log_date) as days_submitted,
        " . ($hasVerifiedColumn ? "MAX(ds.dean_verified_at)" : "MAX(ds.submitted_at)") . " as last_submitted_at" .
    ($hasVerifiedColumn ? ",
        SUM(CASE WHEN ds.dean_verified_at IS NOT NULL THEN 1 ELSE 0 END) as days_verified" : "") .
    ($hasVerifiedColumn && $hasVerifiedByColumn ? ",
        (SELECT CONCAT(uv.first_name, ' ', uv.last_name) FROM dtr_daily_submissions dsv
         LEFT JOIN users uv ON uv.id = dsv.dean_verified_by
         WHERE dsv.user_id = ds.user_id AND dsv.dean_verified_at IS NOT NULL
           AND dsv.log_date >= ? AND dsv.log_date <= ?
         ORDER BY dsv.dean_verified_at DESC LIMIT 1) as verified_by_name" : "") . "
    FROM dtr_daily_submissions ds
    INNER JOIN users u ON ds.user_id = u.id
    LEFT JOIN faculty_profiles fp ON fp.user_id = u.id
    WHERE $whereClause
    GROUP BY ds.user_id, u.first_name, u.last_name, u.email, fp.employee_id, fp.department, fp.position, fp.designation
    ORDER BY last_submitted_at DESC, u.last_name ASC, u.first_name ASC
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$stmt = $db->prepare($sql);
$execParams = $params;
if ($hasVerifiedColumn && $hasVerifiedByColumn) {
    // Subquery ? placeholders appear before main WHERE in SQL, so prepend
    $execParams = array_merge([$dateFrom, $dateTo], $params);
}
$stmt->execute($execParams);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Distinct departments for filter dropdown
$deptStmt = $db->query("SELECT DISTINCT fp.department FROM faculty_profiles fp WHERE fp.department IS NOT NULL AND fp.department != '' ORDER BY fp.department");
$departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

require_once '../includes/navigation.php';
include_navigation();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DTR Submissions - WPU Faculty System</title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/admin-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <style>
        /* Admin DTR filters card - responsive height (vh scales with viewport) */
        .dtr-filters-card {
            height: 25vh !important;
            min-height: fit-content;
        }
        .dtr-filters-card-body {
            padding: 0.75rem 1rem;
            flex: 0 0 auto;
        }
        .dtr-filters-card-body .form-label { margin-bottom: 0.2rem; }
        .dtr-filters-card-body .row.g-3 { --bs-gutter-y: 0.5rem; --bs-gutter-x: 0.5rem; }
        @media (max-width: 767.98px) {
            .dtr-filters-card { height: auto !important; min-height: 0; }
        }
        #viewDtrModal .modal-dialog { max-width: 720px; }
        #viewDtrModal .dtr-form-wrap { font-family: "Times New Roman", Times, serif; color: #000; background: #fff; padding: 1.25rem; border: 1px solid #000; }
        #viewDtrModal .dtr-form-title { font-size: 1rem; font-weight: 700; text-align: center; margin-bottom: 0.15rem; color: #000; }
        #viewDtrModal .dtr-form-subtitle { font-size: 0.95rem; font-weight: 700; text-align: center; margin-bottom: 0.15rem; color: #000; }
        #viewDtrModal .dtr-form-line { text-align: center; font-size: 0.8rem; letter-spacing: 0.2em; margin-bottom: 0.75rem; color: #000; }
        #viewDtrModal .dtr-field-row { font-size: 0.9rem; margin-bottom: 0.35rem; color: #000; }
        #viewDtrModal .dtr-field-inline { display: inline-block; border-bottom: 1px solid #000; min-width: 180px; margin-left: 0.35rem; padding: 0 0.25rem 0.1rem; font-size: 0.9rem; }
        #viewDtrModal .dtr-table { font-size: 0.8rem; table-layout: fixed; width: 100%; border-collapse: collapse; color: #000; }
        #viewDtrModal .dtr-table th, #viewDtrModal .dtr-table td { padding: 0.2rem 0.25rem; vertical-align: middle; border: 1px solid #000; }
        #viewDtrModal .dtr-table th { background: #fff; font-weight: 700; text-align: center; }
        #viewDtrModal .dtr-table .dtr-day { width: 2.25em; text-align: center; }
        #viewDtrModal .dtr-table .dtr-time { width: 4em; text-align: center; }
        #viewDtrModal .dtr-table .dtr-undertime { width: 2.75em; text-align: center; }
        #viewDtrModal .dtr-table tbody tr.dtr-total { font-weight: 700; }
        #viewDtrModal .dtr-official-row { font-size: 0.9rem; margin-bottom: 0.5rem; color: #000; }
        #viewDtrModal .dtr-official-row .dtr-field-inline { min-width: 80px; }
        #viewDtrModal .dtr-certify { font-size: 0.8rem; margin-top: 0.75rem; margin-bottom: 0.25rem; line-height: 1.35; color: #000; }
        #viewDtrModal .dtr-verified { font-size: 0.8rem; margin-top: 1rem; margin-bottom: 0; color: #000; }
        #viewDtrModal .dtr-verified .dtr-incharge { display: block; font-weight: 700; margin-top: 0.25rem; }
        #viewDtrModal .dtr-verified .dtr-incharge:empty { font-weight: normal; border-bottom: 1px solid #000; min-width: 200px; }
    </style>
</head>
<body class="layout-admin">
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <div class="page-header">
                    <div>
                        <div class="page-title">
                            <i class="fas fa-clipboard-check"></i>
                            <span>DTR Submissions</span>
                        </div>
                        <p class="page-subtitle text-muted mb-0">View-only. DTRs shown here have been verified and endorsed by the Dean or assigned Pardon Openers (<a href="<?php echo htmlspecialchars(clean_url(getBasePath() . '/admin/settings.php#section-pardon-openers', getBasePath())); ?>">Settings → Pardon Openers</a>). Admin cannot verify DTRs on this page.</p>
                    </div>
                </div>

                <?php displayMessage(); ?>

                <div class="card mb-4 dtr-filters-card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filters</h5>
                    </div>
                    <div class="card-body dtr-filters-card-body">
                        <form id="dtrFiltersForm" method="get" action="" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label small">Year</label>
                                <select name="year" id="filterYear" class="form-select form-select-sm">
                                    <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo $yearFilter == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Month</label>
                                <select name="month" id="filterMonth" class="form-select form-select-sm">
                                    <?php for ($m = 1; $m <= 12; $m++): ?>
                                        <option value="<?php echo $m; ?>" <?php echo $monthFilter == $m ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Department</label>
                                <select name="department" id="filterDepartment" class="form-select form-select-sm">
                                    <option value="">All departments</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?php echo htmlspecialchars($d); ?>" <?php echo $departmentFilter === $d ? 'selected' : ''; ?>><?php echo htmlspecialchars($d); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Employee Type</label>
                                <select name="employee_type" id="filterEmployeeType" class="form-select form-select-sm">
                                    <option value="">All</option>
                                    <option value="faculty" <?php echo $employeeTypeFilter === 'faculty' ? 'selected' : ''; ?>>Faculty</option>
                                    <option value="staff" <?php echo $employeeTypeFilter === 'staff' ? 'selected' : ''; ?>>Staff</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Search</label>
                                <input type="text" name="search" id="filterSearch" class="form-control form-control-sm" placeholder="Name, ID, dept" value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="button" id="filterResetBtn" class="btn btn-outline-secondary btn-sm">Reset</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card" id="dtrSubmissionsCard">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Submissions (<span id="dtrSubmissionsCount"><?php echo $totalRows; ?></span> employee<span id="dtrSubmissionsPlural"><?php echo $totalRows !== 1 ? 's' : ''; ?></span>)</h5>
                        <div class="d-flex align-items-center gap-2">
                            <span class="small text-muted" id="dtrMonthLabel"><?php echo date('F Y', mktime(0, 0, 0, $monthFilter, 1, $yearFilter)); ?></span>
                            <?php
                            $printQuery = array_filter([
                                'year' => $yearFilter,
                                'month' => $monthFilter,
                                'department' => $departmentFilter ?: null,
                                'employee_type' => $employeeTypeFilter ?: null,
                                'search' => $search ?: null,
                            ]);
                            $printUrl = clean_url(getBasePath() . '/admin/print_dtr_submissions.php', getBasePath()) . (empty($printQuery) ? '' : '?' . http_build_query($printQuery));
                            ?>
                            <a href="<?php echo htmlspecialchars($printUrl); ?>" id="dtrPrintLink" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success" title="Print or save as PDF (2 DTRs per A4 page)">
                                <i class="fas fa-print me-1"></i>Print / Download DTRs
                            </a>
                        </div>
                    </div>
                    <div class="card-body p-0 position-relative">
                        <div id="dtrTableLoading" class="position-absolute top-0 start-0 end-0 bottom-0 d-none align-items-center justify-content-center bg-white bg-opacity-75" style="z-index: 5;">
                            <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Employee</th>
                                        <th>Safe Employee ID</th>
                                        <th>Department</th>
                                        <th>Position</th>
                                        <th>Days Submitted</th>
                                        <?php if ($hasVerifiedColumn): ?><th>Verified by Supervisor</th><?php endif; ?>
                                        <th>Last Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="dtrTableBody">
                                    <?php
                                    $num = $offset + 1;
                                    foreach ($submissions as $row):
                                        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                                        if ($name === '') $name = '—';
                                        $daysSubmitted = (int)($row['days_submitted'] ?? 0);
                                        $daysVerified = $hasVerifiedColumn ? (int)($row['days_verified'] ?? 0) : 0;
                                        $lastSubmitted = $row['last_submitted_at'] ?? null;
                                    ?>
                                        <tr>
                                            <td><?php echo $num++; ?></td>
                                            <td class="fw-medium"><?php echo htmlspecialchars($name); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['employee_id'] ?? '—'); ?></span></td>
                                            <td><?php echo htmlspecialchars($row['department'] ?? '—'); ?></td>
                                            <td><?php echo htmlspecialchars($row['position'] ?? '—'); ?></td>
                                            <td><?php echo $daysSubmitted; ?> day<?php echo $daysSubmitted !== 1 ? 's' : ''; ?></td>
                                            <?php if ($hasVerifiedColumn): ?>
                                            <td>
                                                <?php
                                                $verifiedByName = $hasVerifiedByColumn ? trim($row['verified_by_name'] ?? '') : '';
                                                ?>
                                                <?php if ($daysVerified >= $daysSubmitted && $daysSubmitted > 0): ?>
                                                    <span class="badge bg-success" title="All DTR days verified by supervisor"><i class="fas fa-check-circle me-1"></i><?php echo $verifiedByName ? 'Verified by ' . htmlspecialchars($verifiedByName) : 'Verified'; ?></span>
                                                <?php elseif ($daysVerified > 0): ?>
                                                    <span class="badge bg-info" title="<?php echo $daysVerified; ?> of <?php echo $daysSubmitted; ?> days verified"><?php echo $daysVerified; ?>/<?php echo $daysSubmitted; ?><?php echo $verifiedByName ? ' by ' . htmlspecialchars($verifiedByName) : ''; ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td><?php echo $lastSubmitted ? date('M j, Y g:i A', strtotime($lastSubmitted)) : '—'; ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="openViewDTR('<?php echo htmlspecialchars($row['employee_id'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($dateFrom, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($dateTo, ENT_QUOTES); ?>')" title="View DTR">
                                                    <i class="fas fa-eye me-1"></i>View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($submissions)): ?>
                                        <tr>
                                            <td colspan="<?php echo $hasVerifiedColumn ? 9 : 8; ?>" class="text-center text-muted py-4">No employees have submitted DTR for the selected filters.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div id="dtrPagination" class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-2 border-top">
                            <?php if ($totalPages > 1): ?>
                            <div class="small text-muted" id="dtrPaginationInfo">
                                Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalRows; ?> total)
                            </div>
                            <nav aria-label="DTR submissions pagination">
                                <ul class="pagination pagination-sm mb-0" id="dtrPaginationNav">
                                    <?php
                                    $baseUrl = clean_url(getBasePath() . '/admin/dtr_submissions.php', getBasePath());
                                    $qParams = array_filter([
                                        'year' => $yearFilter,
                                        'month' => $monthFilter,
                                        'department' => $departmentFilter ?: null,
                                        'employee_type' => $employeeTypeFilter ?: null,
                                        'search' => $search ?: null,
                                    ]);
                                    $makePageUrl = function($p) use ($baseUrl, $qParams) {
                                        $qParams['page'] = $p;
                                        return $baseUrl . '?' . http_build_query($qParams);
                                    };
                                    if ($page > 1): ?>
                                        <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($makePageUrl($page - 1)); ?>">Previous</a></li>
                                    <?php endif;
                                    for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($makePageUrl($p)); ?>"><?php echo $p; ?></a></li>
                                    <?php endfor;
                                    if ($page < $totalPages): ?>
                                        <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($makePageUrl($page + 1)); ?>">Next</a></li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                            <?php else: ?>
                            <div class="small text-muted" id="dtrPaginationInfo"><?php echo $totalRows; ?> total</div>
                            <nav aria-label="DTR submissions pagination" style="display: none;">
                                <ul class="pagination pagination-sm mb-0" id="dtrPaginationNav"></ul>
                            </nav>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- View DTR Modal -->
    <div class="modal fade" id="viewDtrModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title">Daily Time Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="viewDtrLoading" class="text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div> Loading DTR...
                    </div>
                    <div id="viewDtrContent" style="display: none;">
                        <div class="dtr-form-wrap">
                            <div class="dtr-form-title">Civil Service Form No. 48</div>
                            <div class="dtr-form-subtitle">DAILY TIME RECORD</div>
                            <div class="dtr-form-line">-----o0o-----</div>
                            <div class="dtr-field-row">(Name) <span id="viewDtrEmployeeName" class="dtr-field-inline"></span></div>
                            <div class="dtr-field-row">For the month of <span id="viewDtrDateLabel" class="dtr-field-inline"></span></div>
                            <div class="dtr-official-row">Official hours for arrival and departure</div>
                            <div class="dtr-official-row">Regular days <span id="viewDtrOfficialRegular" class="dtr-field-inline"></span> Saturdays <span id="viewDtrOfficialSat" class="dtr-field-inline"></span></div>
                            <table class="dtr-table">
                                <thead>
                                    <tr>
                                        <th class="dtr-day">Day</th>
                                        <th colspan="2">A.M.</th>
                                        <th colspan="2">P.M.</th>
                                        <th colspan="2">Undertime</th>
                                    </tr>
                                    <tr>
                                        <th></th>
                                        <th class="dtr-time">Arrival</th>
                                        <th class="dtr-time">Departure</th>
                                        <th class="dtr-time">Arrival</th>
                                        <th class="dtr-time">Departure</th>
                                        <th class="dtr-undertime">Hours</th>
                                        <th class="dtr-undertime">Minutes</th>
                                    </tr>
                                </thead>
                                <tbody id="viewDtrTableBody"></tbody>
                            </table>
                            <p class="dtr-certify">I certify on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival and departure from office.</p>
                            <p class="dtr-verified">VERIFIED as to the prescribed office hours:<br>In Charge<br><strong id="viewDtrInCharge" class="dtr-incharge"></strong></p>
                        </div>
                    </div>
                    <div id="viewDtrError" class="alert alert-danger py-2" style="display: none;"></div>
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
    (function() {
        var basePath = '<?php echo htmlspecialchars(rtrim(getBasePath(), '/'), ENT_QUOTES); ?>';
        var adminPath = basePath + '/admin';
        var fetchLogsUrl = adminPath + '/fetch_logs_api.php';
        var apiUrl = adminPath + '/dtr_submissions_api.php';
        var dateFrom = '<?php echo htmlspecialchars($dateFrom, ENT_QUOTES); ?>';
        var dateTo = '<?php echo htmlspecialchars($dateTo, ENT_QUOTES); ?>';
        var hasVerifiedColumn = <?php echo $hasVerifiedColumn ? 'true' : 'false'; ?>;
        var hasVerifiedByColumn = <?php echo ($hasVerifiedByColumn ?? false) ? 'true' : 'false'; ?>;
        var searchDebounceTimer = null;

        function getFilterParams() {
            return {
                year: document.getElementById('filterYear').value,
                month: document.getElementById('filterMonth').value,
                department: document.getElementById('filterDepartment').value,
                employee_type: document.getElementById('filterEmployeeType').value,
                search: document.getElementById('filterSearch').value.trim(),
                page: 1
            };
        }

        function loadSubmissions(page) {
            var params = getFilterParams();
            if (page) params.page = page;
            var qs = Object.keys(params).filter(function(k) { return params[k]; }).map(function(k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
            var url = apiUrl + (qs ? '?' + qs : '');

            var loader = document.getElementById('dtrTableLoading');
            if (loader) { loader.classList.remove('d-none'); loader.classList.add('d-flex'); }
            fetch(url).then(function(r) {
                if (!r.ok) throw new Error('Request failed: ' + r.status);
                return r.text();
            }).then(function(text) {
                var data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error('Invalid response from server');
                }
                if (!data.success) {
                    alert(data.message || 'Failed to load submissions.');
                    return;
                }
                dateFrom = data.date_from || dateFrom;
                dateTo = data.date_to || dateTo;
                if (data.has_verified_by_column) hasVerifiedByColumn = true;
                try {
                    renderTable(data);
                } catch (err) {
                    console.error('renderTable error:', err);
                    alert('Error displaying data. Please refresh the page.');
                }
                updatePrintLink(params);
                if (typeof history !== 'undefined' && history.replaceState) {
                    var newUrl = window.location.pathname + (qs ? '?' + qs : '');
                    history.replaceState(null, '', newUrl);
                }
            }).catch(function(err) {
                console.error('loadSubmissions error:', err);
                alert('Failed to load submissions. Please try again.');
            }).finally(function() {
                if (loader) { loader.classList.remove('d-flex'); loader.classList.add('d-none'); }
            });
        }

        function renderTable(data) {
            var tbody = document.getElementById('dtrTableBody');
            var rows = data.submissions || [];
            var offset = (data.page - 1) * (data.per_page || 25);
            var num = offset + 1;
            var colCount = hasVerifiedColumn ? 9 : 8;

            var html = '';
            rows.forEach(function(row) {
                var name = (row.first_name || '').trim() + ' ' + (row.last_name || '').trim();
                if (!name.trim()) name = '—';
                var daysSubmitted = parseInt(row.days_submitted, 10) || 0;
                var daysVerified = hasVerifiedColumn ? (parseInt(row.days_verified, 10) || 0) : 0;
                var lastSubmitted = row.last_submitted_at || null;
                var empId = (row.employee_id || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
                var nameEsc = name.replace(/'/g, "\\'").replace(/"/g, '&quot;');
                var verifiedCell = '';
                if (hasVerifiedColumn) {
                    var verifiedByName = (row.verified_by_name || '').trim();
                    var verifiedText = verifiedByName ? 'Verified by ' + escapeHtml(verifiedByName) : 'Verified';
                    var partialText = daysVerified + '/' + daysSubmitted + (verifiedByName ? ' by ' + escapeHtml(verifiedByName) : '');
                    if (daysVerified >= daysSubmitted && daysSubmitted > 0) {
                        verifiedCell = '<td><span class="badge bg-success" title="All DTR days verified by supervisor"><i class="fas fa-check-circle me-1"></i>' + verifiedText + '</span></td>';
                    } else if (daysVerified > 0) {
                        verifiedCell = '<td><span class="badge bg-info" title="' + daysVerified + ' of ' + daysSubmitted + ' days verified">' + partialText + '</span></td>';
                    } else {
                        verifiedCell = '<td><span class="badge bg-secondary">—</span></td>';
                    }
                }

                var lastStr = lastSubmitted ? (new Date(lastSubmitted).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) || lastSubmitted) : '—';

                html += '<tr><td>' + num++ + '</td><td class="fw-medium">' + escapeHtml(name) + '</td><td><span class="badge bg-secondary">' + escapeHtml(row.employee_id || '—') + '</span></td><td>' + escapeHtml(row.department || '—') + '</td><td>' + escapeHtml(row.position || '—') + '</td><td>' + daysSubmitted + ' day' + (daysSubmitted !== 1 ? 's' : '') + '</td>' + verifiedCell + '<td>' + escapeHtml(lastStr) + '</td><td><button type="button" class="btn btn-sm btn-outline-primary" onclick="openViewDTR(\'' + empId + '\', \'' + nameEsc + '\', \'' + (data.date_from || '') + '\', \'' + (data.date_to || '') + '\')" title="View DTR"><i class="fas fa-eye me-1"></i>View</button></td></tr>';
            });

            if (rows.length === 0) {
                html = '<tr><td colspan="' + colCount + '" class="text-center text-muted py-4">No employees have submitted DTR for the selected filters.</td></tr>';
            }
            tbody.innerHTML = html;

            document.getElementById('dtrSubmissionsCount').textContent = data.total_rows;
            document.getElementById('dtrSubmissionsPlural').textContent = data.total_rows !== 1 ? 's' : '';
            document.getElementById('dtrMonthLabel').textContent = data.month_label || '';

            var paginationInfo = document.getElementById('dtrPaginationInfo');
            var paginationNav = document.getElementById('dtrPaginationNav');
            var paginationNavParent = paginationNav ? paginationNav.closest('nav') : null;
            if (data.total_pages > 1) {
                paginationInfo.innerHTML = 'Page ' + data.page + ' of ' + data.total_pages + ' (' + data.total_rows + ' total)';
                var navHtml = '';
                if (data.page > 1) navHtml += '<li class="page-item"><a class="page-link dtr-page-link" href="#" data-page="' + (data.page - 1) + '">Previous</a></li>';
                for (var p = Math.max(1, data.page - 2); p <= Math.min(data.total_pages, data.page + 2); p++) {
                    navHtml += '<li class="page-item' + (p === data.page ? ' active' : '') + '"><a class="page-link dtr-page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
                }
                if (data.page < data.total_pages) navHtml += '<li class="page-item"><a class="page-link dtr-page-link" href="#" data-page="' + (data.page + 1) + '">Next</a></li>';
                paginationNav.innerHTML = navHtml;
                if (paginationNavParent) paginationNavParent.style.display = '';
                paginationNav.querySelectorAll('.dtr-page-link').forEach(function(a) {
                    a.addEventListener('click', function(e) { e.preventDefault(); loadSubmissions(parseInt(a.getAttribute('data-page'), 10)); });
                });
            } else {
                paginationInfo.textContent = data.total_rows + ' total';
                paginationNav.innerHTML = '';
                if (paginationNavParent) paginationNavParent.style.display = 'none';
            }
        }

        function escapeHtml(s) {
            if (!s) return '';
            var d = document.createElement('div');
            d.textContent = s;
            return d.innerHTML;
        }

        function updatePrintLink(params) {
            var q = [];
            if (params.year) q.push('year=' + encodeURIComponent(params.year));
            if (params.month) q.push('month=' + encodeURIComponent(params.month));
            if (params.department) q.push('department=' + encodeURIComponent(params.department));
            if (params.employee_type) q.push('employee_type=' + encodeURIComponent(params.employee_type));
            if (params.search) q.push('search=' + encodeURIComponent(params.search));
            document.getElementById('dtrPrintLink').href = adminPath + '/print_dtr_submissions.php' + (q.length ? '?' + q.join('&') : '');
        }

        document.getElementById('dtrFiltersForm').addEventListener('submit', function(e) { e.preventDefault(); loadSubmissions(1); });
        document.getElementById('filterYear').addEventListener('change', function() { loadSubmissions(1); });
        document.getElementById('filterMonth').addEventListener('change', function() { loadSubmissions(1); });
        document.getElementById('filterDepartment').addEventListener('change', function() { loadSubmissions(1); });
        document.getElementById('filterEmployeeType').addEventListener('change', function() { loadSubmissions(1); });
        document.getElementById('filterSearch').addEventListener('input', function() {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(function() { loadSubmissions(1); }, 300);
        });
        document.getElementById('filterResetBtn').addEventListener('click', function() {
            document.getElementById('filterYear').value = '<?php echo (int)date('Y'); ?>';
            document.getElementById('filterMonth').value = '<?php echo (int)date('n'); ?>';
            document.getElementById('filterDepartment').value = '';
            document.getElementById('filterEmployeeType').value = '';
            document.getElementById('filterSearch').value = '';
            loadSubmissions(1);
        });

        document.getElementById('dtrSubmissionsCard').addEventListener('click', function(e) {
            var a = e.target.closest('.dtr-page-link');
            if (a) { e.preventDefault(); loadSubmissions(parseInt(a.getAttribute('data-page'), 10)); }
        });

        window.openViewDTR = function(employeeId, employeeName, dateFrom, dateTo) {
            var modal = new bootstrap.Modal(document.getElementById('viewDtrModal'));
            document.getElementById('viewDtrEmployeeName').textContent = employeeName || '—';
            document.getElementById('viewDtrDateLabel').textContent = dateFrom && dateTo ? (new Date(dateFrom + 'T12:00:00').toLocaleDateString('en-US', { month: 'long' }) + ' ' + dateFrom.substring(0, 4)) : '—';
            document.getElementById('viewDtrLoading').style.display = 'block';
            document.getElementById('viewDtrContent').style.display = 'none';
            document.getElementById('viewDtrError').style.display = 'none';
            modal.show();

            var url = fetchLogsUrl + '?employee_id=' + encodeURIComponent(employeeId) + '&date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo) + '&simple=1';

            function formatOfficialHoursStr(str) {
                if (!str || str === '—' || str === '-') return str || '—';
                return String(str).replace(/(\d{1,2}):(\d{2})/g, function(m, h, m2) {
                    var hr = parseInt(h, 10);
                    var ampm = hr >= 12 ? 'PM' : 'AM';
                    hr = hr === 0 ? 12 : hr > 12 ? hr - 12 : hr;
                    return hr + ':' + m2 + ' ' + ampm;
                });
            }
            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                document.getElementById('viewDtrLoading').style.display = 'none';
                if (data.success && data.logs) {
                    document.getElementById('viewDtrContent').style.display = 'block';
                    document.getElementById('viewDtrOfficialRegular').textContent = formatOfficialHoursStr(data.official_regular || '08:00-12:00, 13:00-17:00');
                    document.getElementById('viewDtrOfficialSat').textContent = formatOfficialHoursStr(data.official_saturday || '—');
                    var inCharge = '';
                    if (data.dean_name) inCharge = data.dean_name + (data.dean_department ? ', ' + data.dean_department : '');
                    document.getElementById('viewDtrInCharge').textContent = inCharge || '—';
                    renderViewDTRTable(data.logs, dateFrom, dateTo, data.official_regular || '08:00-12:00, 13:00-17:00', data.official_saturday || '—', data.official_by_date || {});
                } else {
                    document.getElementById('viewDtrError').textContent = data.message || 'Could not load DTR.';
                    document.getElementById('viewDtrError').style.display = 'block';
                }
            }).catch(function() {
                document.getElementById('viewDtrLoading').style.display = 'none';
                document.getElementById('viewDtrError').textContent = 'Failed to load DTR. Please try again.';
                document.getElementById('viewDtrError').style.display = 'block';
            });
        };

        function renderViewDTRTable(logs, dateFrom, dateTo, officialRegular, officialSaturday, officialByDate) {
            officialByDate = officialByDate || {};
            var logByDate = {};
            (logs || []).forEach(function(log) {
                if (log.log_date) logByDate[log.log_date] = log;
            });
            function logShowsTarfCells(log) {
                if (!log) return false;
                var r = String(log.remarks || '').trim();
                if (r.indexOf('TARF_HOURS_CREDIT:') !== -1 && (log.tarf_id || r.indexOf('TARF:') === 0)) return true;
                if (Number(log.tarf_id) > 0 && r.indexOf('TARF:') === 0) return true;
                return false;
            }
            function punchCell(log, rawTime) {
                if (logShowsTarfCells(log)) return 'TARF';
                return formatTimeForDisplay(log ? (rawTime || '') : '') || '';
            }
            function parseTime(timeStr) {
                if (!timeStr) return null;
                var parts = String(timeStr).split(':');
                if (parts.length >= 2) return (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
                return null;
            }
            function formatTimeForDisplay(timeStr) {
                if (!timeStr) return '';
                var t = String(timeStr).trim();
                if (t === '00:00' || t === '0:00' || t === '00:00:00' || t === '0:00:00') return '';
                var parts = t.match(/^(\d{1,2}):(\d{2})/);
                if (!parts) return t;
                var h = parseInt(parts[1], 10);
                var m = parts[2];
                var ampm = h >= 12 ? 'PM' : 'AM';
                h = h === 0 ? 12 : h > 12 ? h - 12 : h;
                return h + ':' + m + ' ' + ampm;
            }
            function parseOfficialTimes(str) {
                var lunchOutMin = 12 * 60;
                var lunchInMin = 13 * 60;
                var timeOutMin = 17 * 60;
                if (!str || str === '—' || str === '-') return { lunchOut: lunchOutMin, lunchIn: lunchInMin, timeOut: timeOutMin };
                var parts = String(str).split(',');
                if (parts.length >= 2) {
                    var am = parts[0].trim().split('-');
                    var pm = parts[1].trim().split('-');
                    if (am.length >= 2) {
                        var lo = parseTime(am[1].trim());
                        if (lo !== null) lunchOutMin = lo;
                    }
                    if (pm.length >= 2) {
                        var li = parseTime(pm[0].trim());
                        if (li !== null) lunchInMin = li;
                        var to = parseTime(pm[1].trim());
                        if (to !== null) timeOutMin = to;
                    }
                } else if (parts.length === 1) {
                    var seg = parts[0].trim().split('-');
                    if (seg.length >= 2) {
                        var end = parseTime(seg[1].trim());
                        if (end !== null) { lunchOutMin = end; lunchInMin = end; timeOutMin = end; }
                    }
                }
                return { lunchOut: lunchOutMin, lunchIn: lunchInMin, timeOut: timeOutMin };
            }
            var regOfficial = parseOfficialTimes(officialRegular || '08:00-12:00, 13:00-17:00');
            var satOfficial = parseOfficialTimes(officialSaturday || '—');
            var fromParts = dateFrom ? dateFrom.split('-') : [];
            var toParts = dateTo ? dateTo.split('-') : [];
            var year = fromParts[0] ? parseInt(fromParts[0], 10) : new Date().getFullYear();
            var month = fromParts[1] ? parseInt(fromParts[1], 10) : new Date().getMonth() + 1;
            var dayStart = fromParts[2] ? parseInt(fromParts[2], 10) : 1;
            var dayEnd = toParts[2] ? parseInt(toParts[2], 10) : new Date(year, month, 0).getDate();
            var tbody = document.getElementById('viewDtrTableBody');
            tbody.innerHTML = '';
            for (var day = dayStart; day <= dayEnd; day++) {
                var dayStr = String(day).padStart(2, '0');
                var monthStr = String(month).padStart(2, '0');
                var dateKey = year + '-' + monthStr + '-' + dayStr;
                var log = logByDate[dateKey];
                var timeIn = punchCell(log, log ? log.time_in : '');
                var lunchOut = punchCell(log, log ? log.lunch_out : '');
                var lunchIn = punchCell(log, log ? log.lunch_in : '');
                var timeOut = punchCell(log, log ? log.time_out : '');
                var utHrs = '', utMin = '';
                if (log && !logShowsTarfCells(log)) {
                    var dayOfficial = officialByDate[dateKey];
                    var official = dayOfficial ? { lunchOut: dayOfficial.lunch_out, lunchIn: dayOfficial.lunch_in, timeOut: dayOfficial.time_out } : (new Date(year, month - 1, day).getDay() === 6 ? satOfficial : regOfficial);
                    var undertimeMinutes = 0;
                    var hasLunchOut = log.lunch_out && String(log.lunch_out).trim() && String(log.lunch_out) !== '00:00' && String(log.lunch_out) !== '0:00';
                    var hasLunchIn = log.lunch_in && String(log.lunch_in).trim() && String(log.lunch_in) !== '00:00' && String(log.lunch_in) !== '0:00';
                    var hasTimeOut = log.time_out && String(log.time_out).trim() && String(log.time_out) !== '00:00' && String(log.time_out) !== '0:00';
                    if (hasLunchOut) {
                        var actualLunchOut = parseTime(log.lunch_out);
                        if (actualLunchOut !== null && actualLunchOut < official.lunchOut) {
                            undertimeMinutes += official.lunchOut - actualLunchOut;
                        }
                    }
                    if (hasTimeOut) {
                        var actualOut = parseTime(log.time_out);
                        if (actualOut !== null && actualOut < official.timeOut) {
                            undertimeMinutes += official.timeOut - actualOut;
                        }
                    } else if (hasLunchIn) {
                        undertimeMinutes += official.timeOut - official.lunchIn;
                    }
                    if (undertimeMinutes > 0) {
                        utHrs = String(Math.floor(undertimeMinutes / 60));
                        utMin = String(undertimeMinutes % 60);
                    }
                }
                var tr = document.createElement('tr');
                tr.innerHTML = '<td class="dtr-day">' + day + '</td><td class="dtr-time">' + (timeIn || '') + '</td><td class="dtr-time">' + (lunchOut || '') + '</td><td class="dtr-time">' + (lunchIn || '') + '</td><td class="dtr-time">' + (timeOut || '') + '</td><td class="dtr-undertime">' + utHrs + '</td><td class="dtr-undertime">' + utMin + '</td>';
                tbody.appendChild(tr);
            }
            var totalRow = document.createElement('tr');
            totalRow.className = 'dtr-total';
            totalRow.innerHTML = '<td class="dtr-day">Total</td><td></td><td></td><td></td><td></td><td></td><td></td>';
            tbody.appendChild(totalRow);
        }
    })();
    </script>
</body>
</html>
