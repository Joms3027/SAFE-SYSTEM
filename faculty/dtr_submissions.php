<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireAuth();
$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->prepare("SELECT fp.designation, fp.department FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

$isDean = $userProfile && strtolower(trim($userProfile['designation'] ?? '')) === 'dean';
$deanDepartment = trim($userProfile['department'] ?? '');
$hasScopeAssignments = hasPardonOpenerAssignments($_SESSION['user_id'], $db);
$scopeLabel = $deanDepartment ?: 'Your Scope';

// Allow: faculty/staff (dean or pardon opener), or admin/super_admin with pardon opener assignments
$canAccess = (isFaculty() || isStaff()) && ($isDean || $hasScopeAssignments);
$canAccess = $canAccess || ((isAdmin()) && $hasScopeAssignments);

if (!$canAccess) {
    $_SESSION['error'] = 'Access denied. Only Deans or assigned personnel can view DTR submissions.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . (isAdmin() ? '/admin/dashboard.php' : '/faculty/dashboard.php'), $basePath));
}

if ($isDean && $deanDepartment !== '') {
    // Dean: use department
} elseif ($hasScopeAssignments) {
    // Pardon opener: use scope (department/designation)
}

$tableCheck = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
if (!$tableCheck || $tableCheck->rowCount() === 0) {
    $_SESSION['error'] = 'DTR submissions are not configured. Please run the database migration.';
    $basePath = getBasePath();
    redirect(clean_url($basePath . '/faculty/dashboard.php', $basePath));
}

$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$monthFilter = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$dateFrom = sprintf('%04d-%02d-01', $yearFilter, $monthFilter);
$lastDay = (int) date('t', mktime(0, 0, 0, $monthFilter, 1, $yearFilter));
$dateTo = sprintf('%04d-%02d-%02d', $yearFilter, $monthFilter, $lastDay);

$currentUserEmployeeId = '';
$stmtMe = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
$stmtMe->execute([$_SESSION['user_id']]);
$meRow = $stmtMe->fetch(PDO::FETCH_ASSOC);
if ($meRow && !empty($meRow['employee_id'])) {
    $currentUserEmployeeId = trim($meRow['employee_id']);
}

if ($isDean && $deanDepartment !== '') {
    // Dean sees only faculty in their department; staff are verified by assigned pardon opener
    // Exclude self: cannot verify own DTR
    $whereClause = "ds.log_date >= ? AND ds.log_date <= ? AND fp.department = ? AND u.user_type = 'faculty'";
    $params = [$dateFrom, $dateTo, $deanDepartment];
    if ($currentUserEmployeeId !== '') {
        $whereClause .= " AND fp.employee_id != ?";
        $params[] = $currentUserEmployeeId;
    }
} else {
    $scopeEmployeeIds = getEmployeeIdsInScope($_SESSION['user_id'], $db);
    if ($currentUserEmployeeId !== '' && in_array($currentUserEmployeeId, $scopeEmployeeIds)) {
        $scopeEmployeeIds = array_values(array_diff($scopeEmployeeIds, [$currentUserEmployeeId]));
    }
    if (empty($scopeEmployeeIds)) {
        $whereClause = "ds.log_date >= ? AND ds.log_date <= ? AND 1 = 0";
        $params = [$dateFrom, $dateTo];
    } else {
        $placeholders = implode(',', array_fill(0, count($scopeEmployeeIds), '?'));
        $whereClause = "ds.log_date >= ? AND ds.log_date <= ? AND fp.employee_id IN ($placeholders)";
        $params = array_merge([$dateFrom, $dateTo], $scopeEmployeeIds);
    }
}

if ($search !== '') {
    $whereClause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR fp.employee_id LIKE ?)";
    $term = '%' . $search . '%';
    $params = array_merge($params, [$term, $term, $term]);
}

$countSql = "SELECT COUNT(DISTINCT ds.user_id) FROM dtr_daily_submissions ds
    INNER JOIN users u ON ds.user_id = u.id
    INNER JOIN faculty_profiles fp ON fp.user_id = u.id
    WHERE $whereClause";

$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();

$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$hasVerifiedColumn = false;
$colCheck = $db->query("SHOW COLUMNS FROM dtr_daily_submissions LIKE 'dean_verified_at'");
if ($colCheck && $colCheck->rowCount() > 0) {
    $hasVerifiedColumn = true;
}

$sql = "SELECT ds.user_id,
        u.first_name, u.last_name, u.email,
        fp.employee_id, fp.position,
        COUNT(ds.log_date) as days_submitted,
        MAX(ds.submitted_at) as last_submitted_at" .
    ($hasVerifiedColumn ? ",
        SUM(CASE WHEN ds.dean_verified_at IS NOT NULL THEN 1 ELSE 0 END) as days_verified" : "") . "
    FROM dtr_daily_submissions ds
    INNER JOIN users u ON ds.user_id = u.id
    INNER JOIN faculty_profiles fp ON fp.user_id = u.id
    WHERE $whereClause
    GROUP BY ds.user_id, u.first_name, u.last_name, u.email, fp.employee_id, fp.position
    ORDER BY last_submitted_at DESC, u.last_name ASC, u.first_name ASC
    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$stmt = $db->prepare($sql);
$stmt->execute($params);
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../includes/navigation.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DTR Submissions - <?php echo htmlspecialchars($scopeLabel); ?></title>
    <link rel="icon" type="image/png" href="<?php echo asset_url('logo.png', true); ?>">
    <?php $basePath = getBasePath(); ?>
    <link href="<?php echo asset_url('vendor/bootstrap/css/bootstrap.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('vendor/fontawesome/css/all.min.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/style.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/faculty-portal.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/mobile.css', true); ?>" rel="stylesheet">
    <link href="<?php echo asset_url('css/dtr-submissions.css', true); ?>" rel="stylesheet">
    <style>
        /* DTR modal - Civil Service Form No. 48 (match admin, force table display - NOT inside .table-responsive) */
        #viewDtrModal .modal-dialog { max-width: 720px !important; }
        #viewDtrModal .dtr-form-wrap {
            font-family: "Times New Roman", Times, serif !important;
            color: #000 !important;
            background: #fff !important;
            padding: 1.25rem !important;
            border: 1px solid #000 !important;
        }
        #viewDtrModal .dtr-form-title, #viewDtrModal .dtr-form-subtitle, #viewDtrModal .dtr-form-line,
        #viewDtrModal .dtr-field-row, #viewDtrModal .dtr-field-inline, #viewDtrModal .dtr-official-row { color: #000 !important; }
        #viewDtrModal .dtr-form-title { font-size: 1rem !important; font-weight: 700 !important; text-align: center !important; margin-bottom: 0.15rem !important; }
        #viewDtrModal .dtr-form-subtitle { font-size: 0.95rem !important; font-weight: 700 !important; text-align: center !important; margin-bottom: 0.15rem !important; }
        #viewDtrModal .dtr-form-line { text-align: center !important; font-size: 0.8rem !important; letter-spacing: 0.2em !important; margin-bottom: 0.75rem !important; }
        #viewDtrModal .dtr-field-row { font-size: 0.9rem !important; margin-bottom: 0.35rem !important; }
        #viewDtrModal .dtr-field-inline { display: inline-block !important; border-bottom: 1px solid #000 !important; min-width: 180px !important; margin-left: 0.35rem !important; padding: 0 0.25rem 0.1rem !important; font-size: 0.9rem !important; }
        #viewDtrModal .dtr-official-row { font-size: 0.9rem !important; margin-bottom: 0.5rem !important; }
        #viewDtrModal .dtr-official-row .dtr-field-inline { min-width: 80px !important; }
        /* CRITICAL: Force table layout - override any .table-responsive or mobile rules that might affect modal tables */
        #viewDtrModal .dtr-table { display: table !important; font-size: 0.8rem !important; table-layout: fixed !important; width: 100% !important; border-collapse: collapse !important; color: #000 !important; font-family: "Times New Roman", Times, serif !important; }
        #viewDtrModal .dtr-table thead { display: table-header-group !important; }
        #viewDtrModal .dtr-table tbody { display: table-row-group !important; }
        #viewDtrModal .dtr-table tr { display: table-row !important; }
        #viewDtrModal .dtr-table th, #viewDtrModal .dtr-table td {
            display: table-cell !important;
            padding: 0.2rem 0.25rem !important;
            vertical-align: middle !important;
            border: 1px solid #000 !important;
        }
        #viewDtrModal .dtr-table th { background: #fff !important; font-weight: 700 !important; text-align: center !important; }
        #viewDtrModal .dtr-table .dtr-day { width: 2.25em !important; text-align: center !important; }
        #viewDtrModal .dtr-table .dtr-time { width: 4em !important; text-align: center !important; }
        #viewDtrModal .dtr-table .dtr-undertime { width: 2.75em !important; text-align: center !important; }
        #viewDtrModal .dtr-table tbody tr.dtr-total { font-weight: 700 !important; }
        #viewDtrModal .dtr-certify { font-size: 0.8rem !important; margin-top: 0.75rem !important; margin-bottom: 0.25rem !important; line-height: 1.35 !important; color: #000 !important; }
        #viewDtrModal .dtr-verified { font-size: 0.8rem !important; margin-top: 1rem !important; margin-bottom: 0 !important; color: #000 !important; }
        #viewDtrModal .dtr-verified .dtr-incharge { display: block !important; font-weight: 700 !important; margin-top: 0.25rem !important; }
        #viewDtrModal .dtr-verified .dtr-incharge:empty { font-weight: normal !important; border-bottom: 1px solid #000 !important; min-width: 200px !important; }
    </style>
</head>
<body class="layout-faculty dtr-submissions-page">
    <?php include_navigation(); ?>
    <div class="container-fluid dtr-submissions-container">
        <div class="row">
            <main class="main-content" role="main" aria-label="DTR submissions list">
                <div class="page-header dtr-page-header">
                    <h1 class="page-title">
                        <i class="fas fa-clipboard-check me-2" aria-hidden="true"></i>
                        DTR Submissions
                    </h1>
                    <p class="page-subtitle text-muted"><?php echo htmlspecialchars($scopeLabel); ?> — View and verify daily time records from employees in your scope.</p>
                </div>

                <?php displayMessage(); ?>

                <div class="dtr-submissions-filters" role="region" aria-label="Filter submissions">
                    <form method="get" action="" class="row g-2 dtr-filters-form" role="search" aria-label="Filter by year, month, and search">
                        <div class="col-6 col-md-2 dtr-filters-field">
                            <label class="form-label small" for="dtr-filter-year">Year</label>
                            <select name="year" id="dtr-filter-year" class="form-select form-select-sm" aria-label="Filter by year">
                                <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $yearFilter == $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-2 dtr-filters-field">
                            <label class="form-label small" for="dtr-filter-month">Month</label>
                            <select name="month" id="dtr-filter-month" class="form-select form-select-sm" aria-label="Filter by month">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $monthFilter == $m ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $m, 1)); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-3 dtr-filters-field">
                            <label class="form-label small" for="dtr-filter-search">Search by name or ID</label>
                            <input type="text" name="search" id="dtr-filter-search" class="form-control form-control-sm" placeholder="Name or Safe Employee ID" value="<?php echo htmlspecialchars($search); ?>" aria-label="Search by name or Safe Employee ID">
                        </div>
                        <div class="col-12 col-md-auto dtr-filters-actions">
                            <button type="submit" class="btn btn-primary btn-sm dtr-btn-apply">
                                <i class="fas fa-search me-1" aria-hidden="true"></i>Apply
                            </button>
                            <a href="<?php echo htmlspecialchars(clean_url($basePath . '/faculty/dtr_submissions.php', $basePath)); ?>" class="btn btn-outline-secondary btn-sm" role="button">Reset</a>
                        </div>
                    </form>
                </div>

                <div class="card dtr-submissions-card">
                    <div class="card-header dtr-submissions-card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h2 class="h5 mb-0 dtr-submissions-title">
                            <i class="fas fa-list me-2" aria-hidden="true"></i>
                            Submissions <span class="dtr-count-badge">(<?php echo $totalRows; ?> employee<?php echo $totalRows !== 1 ? 's' : ''; ?>)</span>
                        </h2>
                        <div class="d-flex align-items-center gap-2 flex-wrap dtr-submissions-toolbar">
                            <span class="small text-muted dtr-period-label"><?php echo date('F Y', mktime(0, 0, 0, $monthFilter, 1, $yearFilter)); ?></span>
                            <?php if ($hasVerifiedColumn): ?>
                            <button type="button" class="btn btn-sm btn-success" id="batchVerifyBtn" title="Verify all DTRs that have not been viewed yet" aria-label="Batch verify all unverified DTRs">
                                <i class="fas fa-check-double me-1" aria-hidden="true"></i><span class="dtr-batch-label">Batch Verify</span>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="dtr-submissions-table" role="region" aria-label="Submissions list">
                        <div class="table-responsive" role="presentation">
                            <table class="table table-hover mb-0" role="table" aria-label="DTR submissions for selected period">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Employee</th>
                                        <th>Safe Employee ID</th>
                                        <th>Position</th>
                                        <th>Days Submitted</th>
                                        <?php if ($hasVerifiedColumn): ?><th>Verified</th><?php endif; ?>
                                        <th>Last Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
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
                                            <td data-label="#"><?php echo $num++; ?></td>
                                            <td data-label="Employee" class="fw-medium"><?php echo htmlspecialchars($name); ?></td>
                                            <td data-label="Safe Employee ID"><span class="badge bg-secondary"><?php echo htmlspecialchars($row['employee_id'] ?? '—'); ?></span></td>
                                            <td data-label="Position"><?php echo htmlspecialchars($row['position'] ?? '—'); ?></td>
                                            <td data-label="Days Submitted"><?php echo $daysSubmitted; ?> day<?php echo $daysSubmitted !== 1 ? 's' : ''; ?></td>
                                            <?php if ($hasVerifiedColumn): ?>
                                            <td data-label="Verified">
                                                <?php if ($daysVerified >= $daysSubmitted && $daysSubmitted > 0): ?>
                                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>All</span>
                                                <?php elseif ($daysVerified > 0): ?>
                                                    <span class="badge bg-info"><?php echo $daysVerified; ?>/<?php echo $daysSubmitted; ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td data-label="Last Submitted"><?php echo $lastSubmitted ? date('M j, Y g:i A', strtotime($lastSubmitted)) : '—'; ?></td>
                                            <td data-label="Actions">
                                                <button type="button" class="btn btn-sm btn-outline-primary dtr-btn-view" onclick="openViewDTR('<?php echo htmlspecialchars($row['employee_id'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($name, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($dateFrom, ENT_QUOTES); ?>', '<?php echo htmlspecialchars($dateTo, ENT_QUOTES); ?>')" title="View DTR for <?php echo htmlspecialchars($name, ENT_QUOTES); ?>" aria-label="View DTR for <?php echo htmlspecialchars($name, ENT_QUOTES); ?>">
                                                    <i class="fas fa-eye me-1" aria-hidden="true"></i>View
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($submissions)): ?>
                                        <tr>
                                            <td colspan="<?php echo $hasVerifiedColumn ? 8 : 7; ?>" class="text-center py-5 dtr-empty-state">
                                                <i class="fas fa-inbox fa-2x text-muted mb-2 d-block" aria-hidden="true"></i>
                                                <p class="mb-1 fw-medium">No submissions found</p>
                                                <p class="small text-muted mb-0">No employees have submitted DTR for <strong><?php echo date('F Y', mktime(0, 0, 0, $monthFilter, 1, $yearFilter)); ?></strong>. Try another month or search term.</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        </div>
                        <?php if ($totalPages > 1): ?>
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 px-3 py-3 border-top dtr-pagination-wrap">
                            <div class="small text-muted dtr-pagination-info" aria-live="polite">
                                Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalRows; ?> total)
                            </div>
                            <nav class="dtr-pagination-nav" aria-label="DTR submissions pagination">
                                <ul class="pagination pagination-sm mb-0">
                                    <?php
                                    $baseUrl = clean_url($basePath . '/faculty/dtr_submissions.php', $basePath);
                                    $qParams = array_filter([
                                        'year' => $yearFilter,
                                        'month' => $monthFilter,
                                        'search' => $search ?: null,
                                    ]);
                                    if ($page > 1):
                                        $qParams['page'] = $page - 1;
                                        $prevUrl = $baseUrl . '?' . http_build_query($qParams);
                                    ?>
                                        <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($prevUrl); ?>">Previous</a></li>
                                    <?php endif;
                                    for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++):
                                        $qParams['page'] = $p;
                                        $url = $baseUrl . '?' . http_build_query($qParams);
                                    ?>
                                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo htmlspecialchars($url); ?>"><?php echo $p; ?></a></li>
                                    <?php endfor;
                                    if ($page < $totalPages):
                                        $qParams['page'] = $page + 1;
                                        $nextUrl = $baseUrl . '?' . http_build_query($qParams);
                                    ?>
                                        <li class="page-item"><a class="page-link" href="<?php echo htmlspecialchars($nextUrl); ?>">Next</a></li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- View DTR Modal -->
    <div class="modal fade" id="viewDtrModal" tabindex="-1" role="dialog" aria-labelledby="viewDtrModalTitle" aria-modal="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title" id="viewDtrModalTitle">Daily Time Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
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
                <div class="modal-footer py-2 dtr-modal-footer">
                    <button type="button" class="btn btn-success btn-sm" id="modalVerifyBtn" style="display: none;" title="Mark this DTR as verified" aria-label="Mark this DTR as verified">
                        <i class="fas fa-check me-1" aria-hidden="true"></i>Verify
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal" aria-label="Close modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script src="<?php echo asset_url('js/main.js', true); ?>"></script>
    <script>
    (function() {
        // Use absolute path for API - ensures correct resolution from any URL (clean URLs, PWA, etc.)
        var fetchLogsUrl = '<?php echo htmlspecialchars(clean_url($basePath . '/faculty/get_employee_dtr_logs_api.php', $basePath)); ?>';
        var verifyDtrUrl = '<?php echo htmlspecialchars(clean_url($basePath . '/faculty/verify_dtr_api.php', $basePath)); ?>';
        var dateFrom = '<?php echo htmlspecialchars($dateFrom, ENT_QUOTES); ?>';
        var dateTo = '<?php echo htmlspecialchars($dateTo, ENT_QUOTES); ?>';

        var batchVerifyBtn = document.getElementById('batchVerifyBtn');
        if (batchVerifyBtn) {
            batchVerifyBtn.addEventListener('click', function() {
                if (!confirm('Verify all DTRs that have not been viewed yet for the selected month? This will mark them as verified.')) return;
                batchVerifyBtn.disabled = true;
                batchVerifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Verifying...';
                var formData = new FormData();
                formData.append('date_from', dateFrom);
                formData.append('date_to', dateTo);
                fetch(verifyDtrUrl, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        batchVerifyBtn.disabled = false;
                        batchVerifyBtn.innerHTML = '<i class="fas fa-check-double me-1"></i>Batch Verify All';
                        if (data.success) {
                            if (typeof showNotification === 'function') {
                                showNotification('success', 'Success', data.message);
                            } else {
                                alert(data.message);
                            }
                            window.location.reload();
                        } else {
                            if (typeof showNotification === 'function') {
                                showNotification('error', 'Error', data.message || 'Verification failed.');
                            } else {
                                alert(data.message || 'Verification failed.');
                            }
                        }
                    })
                    .catch(function() {
                        batchVerifyBtn.disabled = false;
                        batchVerifyBtn.innerHTML = '<i class="fas fa-check-double me-1"></i>Batch Verify All';
                        if (typeof showNotification === 'function') {
                            showNotification('error', 'Error', 'Failed to verify. Please try again.');
                        } else {
                            alert('Failed to verify. Please try again.');
                        }
                    });
            });
        }

        var modalVerifyBtn = document.getElementById('modalVerifyBtn');
        var currentModalEmployeeId = '';
        var currentModalDateFrom = '';
        var currentModalDateTo = '';

        if (modalVerifyBtn) {
            modalVerifyBtn.addEventListener('click', function() {
                if (!currentModalEmployeeId || !currentModalDateFrom || !currentModalDateTo) return;
                modalVerifyBtn.disabled = true;
                modalVerifyBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Verifying...';
                var formData = new FormData();
                formData.append('employee_id', currentModalEmployeeId);
                formData.append('date_from', currentModalDateFrom);
                formData.append('date_to', currentModalDateTo);
                fetch(verifyDtrUrl, { method: 'POST', body: formData })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        modalVerifyBtn.disabled = false;
                        modalVerifyBtn.innerHTML = '<i class="fas fa-check me-1"></i>Verify';
                        if (data.success) {
                            modalVerifyBtn.classList.remove('btn-success');
                            modalVerifyBtn.classList.add('btn-outline-success');
                            modalVerifyBtn.innerHTML = '<i class="fas fa-check-circle me-1"></i>Verified';
                            modalVerifyBtn.disabled = true;
                            if (typeof showNotification === 'function') {
                                showNotification('success', 'Success', data.message);
                            } else {
                                alert(data.message);
                            }
                            window.location.reload();
                        } else {
                            if (typeof showNotification === 'function') {
                                showNotification('error', 'Error', data.message || 'Verification failed.');
                            } else {
                                alert(data.message || 'Verification failed.');
                            }
                        }
                    })
                    .catch(function() {
                        modalVerifyBtn.disabled = false;
                        modalVerifyBtn.innerHTML = '<i class="fas fa-check me-1"></i>Verify';
                        if (typeof showNotification === 'function') {
                            showNotification('error', 'Error', 'Failed to verify. Please try again.');
                        } else {
                            alert('Failed to verify. Please try again.');
                        }
                    });
            });
        }

        window.openViewDTR = function(employeeId, employeeName, dateFrom, dateTo) {
            currentModalEmployeeId = employeeId || '';
            currentModalDateFrom = dateFrom || '';
            currentModalDateTo = dateTo || '';
            var modal = new bootstrap.Modal(document.getElementById('viewDtrModal'));
            document.getElementById('viewDtrEmployeeName').textContent = employeeName || '—';
            document.getElementById('viewDtrDateLabel').textContent = dateFrom && dateTo ? (new Date(dateFrom + 'T12:00:00').toLocaleDateString('en-US', { month: 'long' }) + ' ' + dateFrom.substring(0, 4)) : '—';
            document.getElementById('viewDtrLoading').style.display = 'block';
            document.getElementById('viewDtrContent').style.display = 'none';
            document.getElementById('viewDtrError').style.display = 'none';
            if (modalVerifyBtn) {
                modalVerifyBtn.style.display = 'none';
                modalVerifyBtn.disabled = false;
                modalVerifyBtn.classList.remove('btn-outline-success');
                modalVerifyBtn.classList.add('btn-success');
                modalVerifyBtn.innerHTML = '<i class="fas fa-check me-1"></i>Verify';
            }
            modal.show();

            var url = fetchLogsUrl + '?employee_id=' + encodeURIComponent(employeeId) + '&date_from=' + encodeURIComponent(dateFrom) + '&date_to=' + encodeURIComponent(dateTo);

            fetch(url).then(function(r) { return r.json(); }).then(function(data) {
                document.getElementById('viewDtrLoading').style.display = 'none';
                if (data.success && data.logs) {
                    document.getElementById('viewDtrContent').style.display = 'block';
                    if (modalVerifyBtn) modalVerifyBtn.style.display = 'inline-block';
                    document.getElementById('viewDtrOfficialRegular').textContent = formatOfficial12hr(data.official_regular || '08:00-12:00, 13:00-17:00');
                    document.getElementById('viewDtrOfficialSat').textContent = formatOfficial12hr(data.official_saturday || '—');
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

        function formatTime12hr(timeStr) {
            if (!timeStr || String(timeStr).trim() === '') return '—';
            var t = String(timeStr).trim();
            if (t === '00:00' || t === '0:00') return '—';
            var parts = t.split(':');
            var h = parseInt(parts[0], 10) || 0;
            var m = parseInt(parts[1], 10) || 0;
            var ampm = h >= 12 ? 'PM' : 'AM';
            h = h % 12;
            if (h === 0) h = 12;
            return h + ':' + String(m).padStart(2, '0') + ' ' + ampm;
        }
        function formatOfficial12hr(str) {
            if (!str || str === '—' || str === '-') return '—';
            var parts = String(str).split(',');
            return parts.map(function(p) {
                var seg = p.trim().split('-');
                if (seg.length >= 2) {
                    return formatTime12hr(seg[0].trim()) + '-' + formatTime12hr(seg[1].trim());
                }
                return p.trim();
            }).join(', ');
        }
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
                return formatTime12hr(log ? (rawTime || '') : '');
            }
            function parseTime(timeStr) {
                if (!timeStr) return null;
                var parts = String(timeStr).split(':');
                if (parts.length >= 2) return (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
                return null;
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
                tr.innerHTML = '<td class="dtr-day">' + day + '</td><td class="dtr-time">' + formatTime12hr(timeIn) + '</td><td class="dtr-time">' + formatTime12hr(lunchOut) + '</td><td class="dtr-time">' + formatTime12hr(lunchIn) + '</td><td class="dtr-time">' + formatTime12hr(timeOut) + '</td><td class="dtr-undertime">' + utHrs + '</td><td class="dtr-undertime">' + utMin + '</td>';
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
