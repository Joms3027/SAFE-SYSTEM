<?php
/**
 * Employees DTR - admin and super_admin.
 * View employee attendance logs and open pardon for employees.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

$filterYear = isset($_GET['year']) ? (int) $_GET['year'] : (int) date('Y');
$filterMonth = isset($_GET['month']) ? (int) $_GET['month'] : (int) date('n');
$submissionFilter = isset($_GET['submission']) ? $_GET['submission'] : 'all';
if ($filterYear < 2000 || $filterYear > 2100) {
    $filterYear = (int) date('Y');
}
if ($filterMonth < 1 || $filterMonth > 12) {
    $filterMonth = (int) date('n');
}
if (!in_array($submissionFilter, ['all', 'submitted', 'not_submitted'], true)) {
    $submissionFilter = 'all';
}

$filterName = isset($_GET['name']) ? trim((string) $_GET['name']) : '';
$filterDepartment = isset($_GET['department']) ? trim((string) $_GET['department']) : '';
$filterEmploymentStatus = isset($_GET['employment_status']) ? trim((string) $_GET['employment_status']) : '';

$empFilterSql = "u.user_type IN ('staff', 'faculty') AND u.is_active = 1";
$empFilterParams = [];
if ($filterName !== '') {
    $empFilterSql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, '')) LIKE ?)";
    $term = '%' . $filterName . '%';
    $empFilterParams[] = $term;
    $empFilterParams[] = $term;
    $empFilterParams[] = $term;
}
if ($filterDepartment !== '') {
    $empFilterSql .= ' AND fp.department = ?';
    $empFilterParams[] = $filterDepartment;
}
if ($filterEmploymentStatus !== '') {
    $empFilterSql .= ' AND fp.employment_status = ?';
    $empFilterParams[] = $filterEmploymentStatus;
}

$monthStart = sprintf('%04d-%02d-01', $filterYear, $filterMonth);
$lastDayOfMonth = (int) date('t', mktime(0, 0, 0, $filterMonth, 1, $filterYear));
$monthEnd = sprintf('%04d-%02d-%02d', $filterYear, $filterMonth, $lastDayOfMonth);

$hasDtrDailyTable = false;
try {
    $tableCheck = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
    $hasDtrDailyTable = $tableCheck && $tableCheck->rowCount() > 0;
} catch (Exception $e) {
    $hasDtrDailyTable = false;
}

// All employees (faculty and staff); has_dtr_submission = at least one daily DTR row in selected month
if ($hasDtrDailyTable) {
    $stmtEmp = $db->prepare("SELECT fp.employee_id, fp.position, fp.department, fp.employment_status,
                        COALESCE(u.first_name, '') AS first_name, COALESCE(u.last_name, '') AS last_name,
                        CASE WHEN EXISTS (
                            SELECT 1 FROM dtr_daily_submissions dds
                            WHERE dds.user_id = fp.user_id
                            AND dds.log_date >= ? AND dds.log_date <= ?
                        ) THEN 1 ELSE 0 END AS has_dtr_submission
                        FROM faculty_profiles fp
                        LEFT JOIN users u ON fp.user_id = u.id
                        WHERE {$empFilterSql}
                        ORDER BY u.last_name ASC, u.first_name ASC");
    $stmtEmp->execute(array_merge([$monthStart, $monthEnd], $empFilterParams));
} else {
    $stmtEmp = $db->prepare("SELECT fp.employee_id, fp.position, fp.department, fp.employment_status,
                        COALESCE(u.first_name, '') AS first_name, COALESCE(u.last_name, '') AS last_name,
                        0 AS has_dtr_submission
                        FROM faculty_profiles fp
                        LEFT JOIN users u ON fp.user_id = u.id
                        WHERE {$empFilterSql}
                        ORDER BY u.last_name ASC, u.first_name ASC");
    $stmtEmp->execute($empFilterParams);
}

$staffEmployees = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);

$deptFilterOptions = [];
$empStatusFilterOptions = [];
try {
    $stmtDept = $db->query("SELECT DISTINCT fp.department FROM faculty_profiles fp
        INNER JOIN users u ON fp.user_id = u.id
        WHERE u.user_type IN ('staff', 'faculty') AND u.is_active = 1
        AND fp.department IS NOT NULL AND TRIM(fp.department) <> ''
        ORDER BY fp.department ASC");
    if ($stmtDept) {
        $deptFilterOptions = $stmtDept->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    $deptFilterOptions = [];
}
try {
    $stmtEs = $db->query("SELECT name FROM employment_statuses ORDER BY name ASC");
    if ($stmtEs) {
        $empStatusFilterOptions = $stmtEs->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Exception $e) {
    $empStatusFilterOptions = [];
}
if ($empStatusFilterOptions === []) {
    try {
        $stmtEs2 = $db->query("SELECT DISTINCT fp.employment_status FROM faculty_profiles fp
            INNER JOIN users u ON fp.user_id = u.id
            WHERE u.user_type IN ('staff', 'faculty') AND u.is_active = 1
            AND fp.employment_status IS NOT NULL AND TRIM(fp.employment_status) <> ''
            ORDER BY fp.employment_status ASC");
        if ($stmtEs2) {
            $empStatusFilterOptions = $stmtEs2->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Exception $e2) {
        $empStatusFilterOptions = [];
    }
} else {
    try {
        $stmtEs3 = $db->query("SELECT DISTINCT fp.employment_status FROM faculty_profiles fp
            INNER JOIN users u ON fp.user_id = u.id
            WHERE u.user_type IN ('staff', 'faculty') AND u.is_active = 1
            AND fp.employment_status IS NOT NULL AND TRIM(fp.employment_status) <> ''");
        if ($stmtEs3) {
            foreach ($stmtEs3->fetchAll(PDO::FETCH_COLUMN) as $v) {
                if ($v !== null && $v !== '' && !in_array($v, $empStatusFilterOptions, true)) {
                    $empStatusFilterOptions[] = $v;
                }
            }
        }
    } catch (Exception $e3) {
        // keep master list only
    }
    sort($empStatusFilterOptions);
}

if ($submissionFilter === 'submitted') {
    $staffEmployees = array_values(array_filter($staffEmployees, function ($e) {
        return ((int) ($e['has_dtr_submission'] ?? 0) === 1);
    }));
} elseif ($submissionFilter === 'not_submitted') {
    $staffEmployees = array_values(array_filter($staffEmployees, function ($e) {
        return ((int) ($e['has_dtr_submission'] ?? 0) !== 1);
    }));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('Employees DTR', 'View employee attendance logs and open pardon');
    ?>
    <style>
        .employees-dtr-page .page-title { font-size: 1.5rem; font-weight: 600; color: #003366; display: flex; align-items: center; gap: 0.5rem; }
        .employees-dtr-page .dept-dtr-card { border-radius: 12px; border: 1px solid #f1f5f9; overflow: hidden; }
        .employees-dtr-page .table thead { background: #e7f1ff; position: sticky; top: 0; z-index: 10; }
        .employees-dtr-page .table thead th { font-weight: 600; font-size: 0.875rem; padding: 0.75rem 1rem; }
        .employees-dtr-page .view-dtr-btn { min-width: 88px; border-radius: 8px; }
        #dtrModal.modal, #dtrModal.modal.show,
        #empOfficialTimesModal.modal, #empOfficialTimesModal.modal.show { z-index: 1060 !important; }
        #dtrModal .modal-dialog { max-width: 720px; z-index: 1061 !important; }
        #empOfficialTimesModal .modal-dialog { z-index: 1061 !important; }
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
        #dtrModal .dtr-table tbody tr.dtr-holiday-row td.dtr-holiday-label { text-align: center; }
        #dtrModal .dtr-table tbody tr.dtr-half-day-holiday { background-color: #fff3e0; }
        #dtrModal .dtr-table tbody tr.dtr-half-day-holiday td.dtr-holiday-label { font-size: 0.7rem; color: #e65100; font-weight: 600; }
        #dtrModal .dtr-table tbody tr.dtr-tarf-row { background-color: #e7f5ff; }
        #dtrModal .dtr-table tbody tr.dtr-tarf-row td.dtr-tarf-label { color: #0c63e4; font-weight: 700; text-align: center; }
        #dtrModal .dtr-table tbody tr.dtr-total { font-weight: 700; }
        #dtrModal .dtr-official-row { font-size: 0.9rem; margin-bottom: 0.5rem; }
        #dtrModal .dtr-certify { font-size: 0.8rem; margin-top: 0.75rem; margin-bottom: 0.25rem; line-height: 1.35; }
        #dtrModal .dtr-verified { font-size: 0.8rem; margin-top: 1rem; margin-bottom: 0; }
        #dtrModal .dtr-loading-overlay { position: absolute; inset: 0; background: rgba(255,255,255,0.9); display: flex; flex-direction: column; align-items: center; justify-content: center; z-index: 10; border-radius: inherit; }
        #dtrModal .dtr-table-wrap { position: relative; }
        .dtr-scroll-hint { font-size: 0.75rem; color: #64748b; margin-top: 0.5rem; }
        @media (max-width: 767px) {
            #dtrModal .modal-dialog { max-width: 100%; margin: 0.5rem; width: calc(100% - 1rem); }
            #dtrModal .dtr-table-wrap { overflow-x: auto; }
            #dtrModal .dtr-table { font-size: 0.7rem; min-width: 420px; }
            .dtr-scroll-hint { display: block; }
        }
    </style>
</head>
<body class="layout-admin employees-dtr-page">
    <?php require_once '../includes/navigation.php'; include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php displayMessage(); ?>

                <div class="mb-3 text-start">
                    <h6 class="mb-2 text-primary"><i class="fas fa-print me-2"></i>Print employees' DTR</h6>
                    <p class="small text-muted mb-2">Civil Service Form No. 48, monthly — two forms per A4 page (Appendix 24 style). Pardon column is not included. Uses the <strong>name</strong>, <strong>department</strong>, and <strong>employment status</strong> filters below when set; month/year are chosen here.</p>
                    <div class="d-flex flex-wrap align-items-end gap-2 gap-md-3">
                        <div>
                            <label class="form-label small mb-1" for="printAllDtrMonth">Month</label>
                            <select id="printAllDtrMonth" class="form-select form-select-sm">
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo ($i === (int)date('n')) ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $i, 1)); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small mb-1" for="printAllDtrYear">Year</label>
                            <select id="printAllDtrYear" class="form-select form-select-sm">
                                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo ($y === (int)date('Y')) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div>
                            <button type="button" class="btn btn-primary btn-sm" id="printAllEmployeesDtrBtn" title="Opens print view in a new tab (respects filters: name, department, employment status)">
                                <i class="fas fa-print me-1"></i> Print DTR
                            </button>
                        </div>
                    </div>
                </div>

                <?php if (!$hasDtrDailyTable): ?>
                <div class="alert alert-warning mb-3" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>Daily DTR submissions are not configured (<code>dtr_daily_submissions</code> missing). Submission filters cannot be used until the migration is applied.
                </div>
                <?php endif; ?>

                <div class="card mb-4 shadow-sm dept-dtr-card">
                    <div class="card-header dept-dtr-card-header bg-light">
                        <div class="row align-items-end flex-wrap g-2 mb-2 mb-md-0">
                            <div class="col-12 col-md-auto">
                                <h5 class="mb-0"><i class="fas fa-users me-2"></i>All Employees (Faculty & Staff)</h5>
                                <p class="small text-muted mb-0 mt-1">DTR submission status uses daily records in the selected month (at least one day submitted = &ldquo;Submitted&rdquo;).</p>
                            </div>
                        </div>
                        <form method="get" action="" class="row align-items-end flex-wrap g-2 gy-2" id="employeesDtrSubmissionFilterForm">
                            <div class="col-6 col-sm-4 col-md-2 col-lg-auto">
                                <label class="form-label small mb-0" for="filterDtrMonth">Month</label>
                                <select name="month" id="filterDtrMonth" class="form-select form-select-sm" aria-label="Filter by month" <?php echo !$hasDtrDailyTable ? 'disabled' : ''; ?> onchange="if (!this.disabled) this.form.submit();">
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo ($i === $filterMonth) ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $i, 1)); ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-6 col-sm-4 col-md-2 col-lg-auto">
                                <label class="form-label small mb-0" for="filterDtrYear">Year</label>
                                <select name="year" id="filterDtrYear" class="form-select form-select-sm" aria-label="Filter by year" <?php echo !$hasDtrDailyTable ? 'disabled' : ''; ?> onchange="if (!this.disabled) this.form.submit();">
                                    <?php for ($y = (int) date('Y'); $y >= (int) date('Y') - 2; $y--): ?>
                                        <option value="<?php echo $y; ?>" <?php echo ($y === $filterYear) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-8 col-md-4 col-lg-auto">
                                <label class="form-label small mb-0" for="filterDtrSubmission">DTR submission</label>
                                <select name="submission" id="filterDtrSubmission" class="form-select form-select-sm" aria-label="Filter by DTR submission status" <?php echo !$hasDtrDailyTable ? 'disabled' : ''; ?> onchange="if (!this.disabled) this.form.submit();">
                                    <option value="all" <?php echo $submissionFilter === 'all' ? 'selected' : ''; ?>>All employees</option>
                                    <option value="submitted" <?php echo $submissionFilter === 'submitted' ? 'selected' : ''; ?>>Submitted (≥1 day in month)</option>
                                    <option value="not_submitted" <?php echo $submissionFilter === 'not_submitted' ? 'selected' : ''; ?>>Not yet submitted</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6 col-lg-3">
                                <label class="form-label small mb-0" for="filterDtrName">Name</label>
                                <input type="search" name="name" id="filterDtrName" class="form-control form-control-sm" placeholder="First or last name" value="<?php echo htmlspecialchars($filterName); ?>" autocomplete="off" aria-label="Filter by employee name">
                            </div>
                            <div class="col-12 col-sm-6 col-md-4 col-lg-auto">
                                <label class="form-label small mb-0" for="filterDtrDepartment">Department</label>
                                <select name="department" id="filterDtrDepartment" class="form-select form-select-sm" aria-label="Filter by department" onchange="this.form.submit();">
                                    <option value="">All departments</option>
                                    <?php foreach ($deptFilterOptions as $dopt): ?>
                                        <option value="<?php echo htmlspecialchars($dopt); ?>" <?php echo ($filterDepartment !== '' && $filterDepartment === $dopt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dopt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-md-4 col-lg-auto">
                                <label class="form-label small mb-0" for="filterDtrEmploymentStatus">Employment status</label>
                                <select name="employment_status" id="filterDtrEmploymentStatus" class="form-select form-select-sm" aria-label="Filter by employment status" onchange="this.form.submit();">
                                    <option value="">All statuses</option>
                                    <?php foreach ($empStatusFilterOptions as $esopt): ?>
                                        <option value="<?php echo htmlspecialchars($esopt); ?>" <?php echo ($filterEmploymentStatus !== '' && $filterEmploymentStatus === $esopt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($esopt); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-lg-auto">
                                <label class="form-label small mb-0 d-block visually-hidden" for="filterDtrApplyBtn">Apply filters</label>
                                <button type="submit" class="btn btn-outline-primary btn-sm" id="filterDtrApplyBtn"><i class="fas fa-filter me-1"></i> Apply filters</button>
                            </div>
                        </form>
                        <div class="row align-items-center flex-wrap g-2 mt-2 pt-2 border-top">
                            <div class="col-12 col-md-4 col-lg-3 col-search">
                                <label class="form-label small mb-0 visually-hidden" for="employeeSearch">Search employees</label>
                                <input type="search" id="employeeSearch" class="form-control form-control-sm" placeholder="Search name, ID, position..." autocomplete="off" aria-label="Search employees">
                            </div>
                            <div class="col-12 col-md-auto ms-md-auto col-rows">
                                <div class="d-flex align-items-center gap-2">
                                    <label for="employeeRowsPerPage" class="small mb-0">Rows per page</label>
                                    <select id="employeeRowsPerPage" class="form-select form-select-sm" style="width: auto;">
                                        <option value="5">5</option>
                                        <option value="10" selected>10</option>
                                        <option value="25">25</option>
                                        <option value="50">50</option>
                                        <option value="100">100</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive department-dtr-employees">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Name</th>
                                        <th>Safe Employee ID</th>
                                        <th>Position</th>
                                        <th>Department</th>
                                        <th>Employment status</th>
                                        <th><?php echo htmlspecialchars(date('M Y', mktime(0, 0, 0, $filterMonth, 1, $filterYear))); ?> DTR</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody id="employeeTableBody">
                                    <?php 
                                    $idx = 0;
                                    foreach ($staffEmployees as $emp): 
                                        $idx++;
                                        $name = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? ''));
                                        $name = $name !== '' ? $name : ('Staff ' . $emp['employee_id']);
                                        $position = $emp['position'] ?? '—';
                                        $department = $emp['department'] ?? '—';
                                        $empStatus = trim((string) ($emp['employment_status'] ?? ''));
                                        $empStatusDisplay = $empStatus !== '' ? $empStatus : '—';
                                        $hasSub = ((int) ($emp['has_dtr_submission'] ?? 0) === 1);
                                        $searchText = strtolower($name . ' ' . $emp['employee_id'] . ' ' . $position . ' ' . $department . ' ' . $empStatusDisplay . ' ' . ($hasSub ? 'submitted' : 'not submitted'));
                                    ?>
                                        <tr class="employee-row" data-search="<?php echo htmlspecialchars($searchText); ?>" data-name="<?php echo htmlspecialchars($name); ?>" data-employee-id="<?php echo htmlspecialchars($emp['employee_id']); ?>" data-dtr-submitted="<?php echo $hasSub ? '1' : '0'; ?>">
                                            <td class="employee-row-num" data-label="#"><?php echo $idx; ?></td>
                                            <td class="fw-medium" data-label="Name"><?php echo htmlspecialchars($name); ?></td>
                                            <td data-label="Safe Employee ID"><span class="badge bg-secondary"><?php echo htmlspecialchars($emp['employee_id']); ?></span></td>
                                            <td data-label="Position"><?php echo htmlspecialchars($position); ?></td>
                                            <td data-label="Department"><?php echo htmlspecialchars($department); ?></td>
                                            <td data-label="Employment status"><?php echo htmlspecialchars($empStatusDisplay); ?></td>
                                            <td data-label="DTR">
                                                <?php if (!$hasDtrDailyTable): ?>
                                                    <span class="badge bg-secondary">—</span>
                                                <?php elseif ($hasSub): ?>
                                                    <span class="badge bg-success">Submitted</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">None</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end" data-label="Action">
                                                <div class="d-flex flex-wrap justify-content-end gap-1">
                                                    <button type="button" class="btn btn-primary btn-sm view-dtr-btn" 
                                                        data-employee-id="<?php echo htmlspecialchars($emp['employee_id']); ?>" 
                                                        data-name="<?php echo htmlspecialchars($name); ?>"
                                                        title="View DTR">
                                                        <i class="fas fa-eye me-1"></i> View DTR
                                                    </button>
                                                    <button type="button" class="btn btn-outline-info btn-sm view-official-times-btn" 
                                                        data-employee-id="<?php echo htmlspecialchars($emp['employee_id']); ?>" 
                                                        data-name="<?php echo htmlspecialchars($name); ?>"
                                                        title="View official working hours">
                                                        <i class="fas fa-clock me-1"></i> Official times
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr id="employeeNoResults" class="d-none">
                                        <td colspan="8">
                                            <div class="text-center py-4 text-muted">
                                                <i class="fas fa-search fa-2x mb-2"></i>
                                                <p class="mb-0">No employees match your search.</p>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php if (empty($staffEmployees)): ?>
                                        <tr>
                                            <td colspan="8">
                                                <div class="text-center py-4 text-muted">
                                                    <i class="fas fa-users-slash fa-2x mb-2"></i>
                                                    <p class="mb-0">No employees found.</p>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 p-3 border-top" id="employeePaginationWrap">
                            <div class="small text-muted" id="employeePaginationInfo">Showing 0–0 of 0</div>
                            <nav aria-label="Employees list pagination">
                                <ul class="pagination pagination-sm mb-0" id="employeePagination"></ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- DTR Modal -->
    <div class="modal fade" id="dtrModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title">Employee Daily Time Record</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body position-relative">
                    <div id="dtrLoadingOverlay" class="dtr-loading-overlay d-none">
                        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                        <p class="mt-2 mb-0 small text-muted">Loading time records...</p>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="dtrMonth">Month</label>
                            <select id="dtrMonth" class="form-select form-select-sm" onchange="onDtrMonthYearChange()">
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?>" <?php echo ($i === (int)date('n')) ? 'selected' : ''; ?>><?php echo date('F', mktime(0, 0, 0, $i, 1)); ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small mb-1" for="dtrYear">Year</label>
                            <select id="dtrYear" class="form-select form-select-sm" onchange="onDtrMonthYearChange()">
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
                                    <th class="dtr-pardon">Open for staff</th>
                                </tr>
                            </thead>
                            <tbody id="dtrTableBody"></tbody>
                        </table>
                        </div>
                        <p class="dtr-certify">I certify on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival and departure from office.</p>
                        <p class="dtr-verified">VERIFIED as to the prescribed office hours:<br>In Charge<br><strong id="dtrInCharge" class="dtr-incharge">HR</strong></p>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Official times (per employee) -->
    <div class="modal fade" id="empOfficialTimesModal" tabindex="-1" aria-labelledby="empOfficialTimesModalTitle">
        <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title" id="empOfficialTimesModalTitle">Official times</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2" id="empOfficialTimesSubtitle"></p>
                    <div id="empOfficialTimesLoading" class="text-center py-4">
                        <div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div>
                        <p class="mt-2 mb-0 small text-muted">Loading official times...</p>
                    </div>
                    <div id="empOfficialTimesContent" class="d-none">
                        <div class="table-responsive">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Start date</th>
                                        <th>End date</th>
                                        <th>Weekday</th>
                                        <th>Time in</th>
                                        <th>Lunch out</th>
                                        <th>Lunch in</th>
                                        <th>Time out</th>
                                    </tr>
                                </thead>
                                <tbody id="empOfficialTimesTableBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div id="empOfficialTimesEmpty" class="text-center text-muted py-4 d-none">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <p class="mb-0">No official times have been set for this employee yet.</p>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
    <script src="<?php echo asset_url('vendor/bootstrap/js/bootstrap.bundle.min.js', true); ?>"></script>
    <script>
        let allLogs = [];
        let pardonOpenDates = [];
        let selectedEmployeeId = '';
        let selectedEmployeeName = '';
        let officialRegular = '08:00-12:00, 13:00-17:00';
        let officialSaturday = '—';

        (function() {
            var employeeCurrentPage = 1;
            var employeeRowsPerPage = 10;
            var employeeSearchTerm = '';

            function getEmployeeRows() {
                return Array.prototype.slice.call(document.querySelectorAll('#employeeTableBody tr.employee-row'));
            }

            function applyEmployeeFilter(resetPage) {
                var rows = getEmployeeRows();
                var search = (document.getElementById('employeeSearch') && document.getElementById('employeeSearch').value) || '';
                employeeSearchTerm = search.toLowerCase().trim();
                employeeRowsPerPage = parseInt(document.getElementById('employeeRowsPerPage').value, 10) || 10;
                if (resetPage) employeeCurrentPage = 1;

                var visible = employeeSearchTerm
                    ? rows.filter(function(r) { return (r.getAttribute('data-search') || '').indexOf(employeeSearchTerm) >= 0; })
                    : rows;

                var total = visible.length;
                var totalPages = Math.max(1, Math.ceil(total / employeeRowsPerPage));
                employeeCurrentPage = Math.min(employeeCurrentPage, totalPages);

                var start = (employeeCurrentPage - 1) * employeeRowsPerPage;
                var end = start + employeeRowsPerPage;
                var pageRows = visible.slice(start, end);

                rows.forEach(function(row) { row.classList.add('d-none'); });
                pageRows.forEach(function(row, i) {
                    row.classList.remove('d-none');
                    var numCell = row.querySelector('.employee-row-num');
                    if (numCell) numCell.textContent = start + i + 1;
                });

                var noResults = document.getElementById('employeeNoResults');
                if (noResults) noResults.classList.toggle('d-none', total > 0 || rows.length === 0);

                var wrap = document.getElementById('employeePaginationWrap');
                if (wrap) wrap.classList.toggle('d-none', rows.length === 0);

                var info = document.getElementById('employeePaginationInfo');
                if (info) {
                    if (total === 0) info.textContent = 'Showing 0 of 0';
                    else info.textContent = 'Showing ' + (start + 1) + '–' + Math.min(end, total) + ' of ' + total;
                }

                var ul = document.getElementById('employeePagination');
                if (!ul) return;
                ul.innerHTML = '';
                if (totalPages <= 1) return;

                var prevLi = document.createElement('li');
                prevLi.className = 'page-item' + (employeeCurrentPage === 1 ? ' disabled' : '');
                prevLi.innerHTML = '<a class="page-link" href="#" aria-label="Previous">Previous</a>';
                prevLi.querySelector('a').addEventListener('click', function(e) {
                    e.preventDefault();
                    if (employeeCurrentPage > 1) { employeeCurrentPage--; applyEmployeeFilter(); }
                });
                ul.appendChild(prevLi);

                var maxShow = 5;
                var from = Math.max(1, employeeCurrentPage - Math.floor(maxShow / 2));
                var to = Math.min(totalPages, from + maxShow - 1);
                if (to - from < maxShow - 1) from = Math.max(1, to - maxShow + 1);
                for (var p = from; p <= to; p++) {
                    (function(page) {
                        var li = document.createElement('li');
                        li.className = 'page-item' + (page === employeeCurrentPage ? ' active' : '');
                        li.innerHTML = '<a class="page-link" href="#">' + page + '</a>';
                        li.querySelector('a').addEventListener('click', function(e) {
                            e.preventDefault();
                            employeeCurrentPage = page;
                            applyEmployeeFilter();
                        });
                        ul.appendChild(li);
                    })(p);
                }

                var nextLi = document.createElement('li');
                nextLi.className = 'page-item' + (employeeCurrentPage === totalPages ? ' disabled' : '');
                nextLi.innerHTML = '<a class="page-link" href="#" aria-label="Next">Next</a>';
                nextLi.querySelector('a').addEventListener('click', function(e) {
                    e.preventDefault();
                    if (employeeCurrentPage < totalPages) { employeeCurrentPage++; applyEmployeeFilter(); }
                });
                ul.appendChild(nextLi);
            }

            document.addEventListener('DOMContentLoaded', function() {
                var searchEl = document.getElementById('employeeSearch');
                var rowsEl = document.getElementById('employeeRowsPerPage');
                if (searchEl) searchEl.addEventListener('input', function() { applyEmployeeFilter(true); });
                if (searchEl) searchEl.addEventListener('keyup', function() { applyEmployeeFilter(true); });
                if (rowsEl) rowsEl.addEventListener('change', function() { applyEmployeeFilter(true); });
                applyEmployeeFilter(true);

                var printBtn = document.getElementById('printAllEmployeesDtrBtn');
                var printMonth = document.getElementById('printAllDtrMonth');
                var printYear = document.getElementById('printAllDtrYear');
                if (printBtn && printMonth && printYear) {
                    printBtn.addEventListener('click', function() {
                        var url = 'print_employees_monthly_dtr.php?month=' + encodeURIComponent(printMonth.value) + '&year=' + encodeURIComponent(printYear.value);
                        var form = document.getElementById('employeesDtrSubmissionFilterForm');
                        if (form) {
                            var nameEl = form.querySelector('#filterDtrName');
                            var deptEl = form.querySelector('#filterDtrDepartment');
                            var statEl = form.querySelector('#filterDtrEmploymentStatus');
                            if (nameEl && nameEl.value && String(nameEl.value).trim() !== '') {
                                url += '&name=' + encodeURIComponent(String(nameEl.value).trim());
                            }
                            if (deptEl && deptEl.value) {
                                url += '&department=' + encodeURIComponent(deptEl.value);
                            }
                            if (statEl && statEl.value) {
                                url += '&employment_status=' + encodeURIComponent(statEl.value);
                            }
                        }
                        window.open(url, '_blank', 'noopener');
                    });
                }
            });
        })();

        function showDtrLoading(show) {
            var el = document.getElementById('dtrLoadingOverlay');
            if (el) el.classList.toggle('d-none', !show);
        }

        function staffLogsMonthRangeQuery() {
            var mEl = document.getElementById('dtrMonth');
            var yEl = document.getElementById('dtrYear');
            if (!mEl || !yEl) return '';
            var m = mEl.value;
            var y = parseInt(yEl.value, 10);
            var mon = parseInt(m, 10);
            var last = new Date(y, mon, 0).getDate();
            function pad(n) { return String(n).padStart(2, '0'); }
            var from = y + '-' + m + '-01';
            var to = y + '-' + m + '-' + pad(last);
            return '&date_from=' + encodeURIComponent(from) + '&date_to=' + encodeURIComponent(to);
        }

        function loadDtrLogsForSelectedMonth() {
            if (!selectedEmployeeId) return;
            showDtrLoading(true);
            var url = 'fetch_staff_logs_api.php?employee_id=' + encodeURIComponent(selectedEmployeeId) + staffLogsMonthRangeQuery();
            fetch(url)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    allLogs = (data.success && data.logs) ? data.logs : [];
                    pardonOpenDates = (data.success && data.pardon_open_dates) ? data.pardon_open_dates : [];
                    officialRegular = data.official_regular || '08:00-12:00, 13:00-17:00';
                    officialSaturday = data.official_saturday || '—';
                    window.officialByDate = (data.success && data.official_by_date) ? data.official_by_date : {};
                    document.getElementById('dtrOfficialRegular').textContent = officialRegular;
                    document.getElementById('dtrOfficialSat').textContent = officialSaturday;
                    document.getElementById('dtrInCharge').textContent = data.in_charge || 'HR';
                    renderDTR();
                    showDtrLoading(false);
                })
                .catch(function() {
                    allLogs = [];
                    pardonOpenDates = [];
                    window.officialByDate = {};
                    document.getElementById('dtrInCharge').textContent = 'HR';
                    renderDTR();
                    showDtrLoading(false);
                });
        }

        function onDtrMonthYearChange() {
            if (selectedEmployeeId) {
                loadDtrLogsForSelectedMonth();
            } else {
                renderDTR();
            }
        }

        document.querySelectorAll('.view-dtr-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const empId = this.getAttribute('data-employee-id') || '';
                const name = this.getAttribute('data-name') || empId;
                if (!empId) return;
                selectedEmployeeId = empId;
                selectedEmployeeName = name;
                document.getElementById('dtrEmployeeName').textContent = name;
                var modalEl = document.getElementById('dtrModal');
                var modal = new bootstrap.Modal(modalEl);
                modal.show();
                fetch('manage_official_times_api.php?action=get_date_range&employee_id=' + encodeURIComponent(empId))
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data.success && data.date_from) {
                            var from = data.date_from;
                            var parts = from.split('-');
                            if (parts.length >= 2) {
                                var mEl = document.getElementById('dtrMonth');
                                var yEl = document.getElementById('dtrYear');
                                if (mEl && yEl) {
                                    mEl.value = parts[1];
                                    yEl.value = parts[0];
                                }
                            }
                        }
                        loadDtrLogsForSelectedMonth();
                    })
                    .catch(function() { loadDtrLogsForSelectedMonth(); });
            });
        });

        function resetEmpOfficialTimesModal() {
            var loadEl = document.getElementById('empOfficialTimesLoading');
            var contentEl = document.getElementById('empOfficialTimesContent');
            var emptyEl = document.getElementById('empOfficialTimesEmpty');
            if (loadEl) loadEl.classList.remove('d-none');
            if (contentEl) contentEl.classList.add('d-none');
            if (emptyEl) {
                emptyEl.classList.add('d-none');
                emptyEl.innerHTML = '<i class="fas fa-info-circle fa-2x mb-3"></i><p class="mb-0">No official times have been set for this employee yet.</p>';
            }
        }

        document.querySelectorAll('.view-official-times-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var empId = this.getAttribute('data-employee-id') || '';
                var name = this.getAttribute('data-name') || empId;
                if (!empId) return;
                var titleEl = document.getElementById('empOfficialTimesModalTitle');
                var subEl = document.getElementById('empOfficialTimesSubtitle');
                if (titleEl) titleEl.textContent = 'Official times';
                if (subEl) subEl.textContent = name + ' · Safe ID: ' + empId;
                resetEmpOfficialTimesModal();
                var modalEl = document.getElementById('empOfficialTimesModal');
                var modal = new bootstrap.Modal(modalEl);
                modal.show();
                var url = 'manage_official_times_api.php?action=get&employee_id=' + encodeURIComponent(empId);
                fetch(url, { credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var loadEl = document.getElementById('empOfficialTimesLoading');
                        var contentEl = document.getElementById('empOfficialTimesContent');
                        var emptyEl = document.getElementById('empOfficialTimesEmpty');
                        if (loadEl) loadEl.classList.add('d-none');
                        if (!data.success) {
                            if (emptyEl) {
                                var msg = (data && data.message) ? String(data.message) : 'Request failed.';
                                emptyEl.innerHTML = '<i class="fas fa-exclamation-triangle fa-2x mb-3 text-danger d-block"></i>';
                                var errP = document.createElement('p');
                                errP.className = 'text-danger mb-0';
                                errP.textContent = msg;
                                emptyEl.appendChild(errP);
                                emptyEl.classList.remove('d-none');
                            }
                            return;
                        }
                        if (data.official_times && data.official_times.length > 0) {
                            var tbody = document.getElementById('empOfficialTimesTableBody');
                            if (tbody) {
                                tbody.innerHTML = '';
                                var labels = ['Start date', 'End date', 'Weekday', 'Time in', 'Lunch out', 'Lunch in', 'Time out'];
                                data.official_times.forEach(function(ot) {
                                    var endDateHtml = ot.end_date || '<span class="text-success">Ongoing</span>';
                                    var tr = document.createElement('tr');
                                    tr.innerHTML =
                                        '<td data-label="' + labels[0] + '">' + (ot.start_date || '-') + '</td>' +
                                        '<td data-label="' + labels[1] + '">' + endDateHtml + '</td>' +
                                        '<td data-label="' + labels[2] + '">' + (ot.weekday || '-') + '</td>' +
                                        '<td data-label="' + labels[3] + '">' + (ot.time_in || '-') + '</td>' +
                                        '<td data-label="' + labels[4] + '">' + (ot.lunch_out || '-') + '</td>' +
                                        '<td data-label="' + labels[5] + '">' + (ot.lunch_in || '-') + '</td>' +
                                        '<td data-label="' + labels[6] + '">' + (ot.time_out || '-') + '</td>';
                                    tbody.appendChild(tr);
                                });
                            }
                            if (contentEl) contentEl.classList.remove('d-none');
                        } else if (emptyEl) {
                            emptyEl.classList.remove('d-none');
                        }
                    })
                    .catch(function() {
                        var loadEl = document.getElementById('empOfficialTimesLoading');
                        var emptyEl = document.getElementById('empOfficialTimesEmpty');
                        if (loadEl) loadEl.classList.add('d-none');
                        if (emptyEl) {
                            emptyEl.innerHTML = '<i class="fas fa-exclamation-triangle fa-2x mb-3 text-danger"></i><p class="text-danger mb-0">Could not load official times. Please try again.</p>';
                            emptyEl.classList.remove('d-none');
                        }
                    });
            });
        });

        function parseTime(timeStr) {
            if (!timeStr) return null;
            const p = String(timeStr).split(':');
            if (p.length >= 2) return (parseInt(p[0], 10) || 0) * 60 + (parseInt(p[1], 10) || 0);
            return null;
        }

        function parseOfficialTimes(str) {
            var lunchOutMin = 12 * 60, lunchInMin = 13 * 60, timeOutMin = 17 * 60;
            if (!str || str === '—' || str === '-') return { lunchOut: lunchOutMin, lunchIn: lunchInMin, timeOut: timeOutMin };
            var parts = String(str).split(',');
            if (parts.length >= 2) {
                var am = parts[0].trim().split('-'), pm = parts[1].trim().split('-');
                if (am.length >= 2) { var lo = parseTime(am[1].trim()); if (lo !== null) lunchOutMin = lo; }
                if (pm.length >= 2) {
                    var li = parseTime(pm[0].trim()); if (li !== null) lunchInMin = li;
                    var to = parseTime(pm[1].trim()); if (to !== null) timeOutMin = to;
                }
            } else if (parts.length === 1) {
                var seg = parts[0].trim().split('-');
                if (seg.length >= 2) { var end = parseTime(seg[1].trim()); if (end !== null) { lunchOutMin = end; lunchInMin = end; timeOutMin = end; } }
            }
            return { lunchOut: lunchOutMin, lunchIn: lunchInMin, timeOut: timeOutMin };
        }

        function renderDTR() {
            const monthSelect = document.getElementById('dtrMonth');
            const yearSelect = document.getElementById('dtrYear');
            if (!monthSelect || !yearSelect) return;
            const month = monthSelect.value;
            const year = yearSelect.value;
            const monthName = monthSelect.options[monthSelect.selectedIndex].text;
            document.getElementById('dtrMonthLabel').textContent = monthName + ' ' + year;
            const tbody = document.getElementById('dtrTableBody');
            if (!tbody) return;
            tbody.innerHTML = '';
            const logByDate = {};
            (allLogs || []).forEach(log => {
                if (log.log_date && log.log_date.substring(0, 4) === year && log.log_date.substring(5, 7) === month) logByDate[log.log_date] = log;
            });
            const regOfficial = parseOfficialTimes(officialRegular || '08:00-12:00, 13:00-17:00');
            const satOfficial = parseOfficialTimes(officialSaturday || '—');
            for (let day = 1; day <= 31; day++) {
                const dayStr = String(day).padStart(2, '0');
                const dateKey = year + '-' + month + '-' + dayStr;
                const log = logByDate[dateKey];
                const timeIn = log ? (log.time_in || '') : '';
                const lunchOut = log ? (log.lunch_out || '') : '';
                const lunchIn = log ? (log.lunch_in || '') : '';
                const timeOut = log ? (log.time_out || '') : '';
                let utHrs = '—', utMin = '—';
                const isLeave = (timeIn === 'LEAVE' || lunchOut === 'LEAVE' || lunchIn === 'LEAVE' || timeOut === 'LEAVE');
                const isTarfRow = (timeIn === 'TARF' || lunchOut === 'TARF' || lunchIn === 'TARF' || timeOut === 'TARF' || (log && log.is_tarf));
                const isHolidayRow = (timeIn === 'HOLIDAY' || lunchOut === 'HOLIDAY' || lunchIn === 'HOLIDAY' || timeOut === 'HOLIDAY');
                const isHalfDayHoliday = log && log.holiday_is_half_day == 1;
                const halfDayPeriod = log ? (log.holiday_half_day_period || 'morning') : 'morning';
                if (log && !isLeave && !isTarfRow && !isHolidayRow) {
                    const isSaturday = new Date(parseInt(year, 10), parseInt(month, 10) - 1, day).getDay() === 6;
                    let official;
                    if (window.officialByDate && window.officialByDate[dateKey]) {
                        const od = window.officialByDate[dateKey];
                        official = { lunchOut: od.lunch_out || 720, lunchIn: od.lunch_in || 780, timeOut: od.time_out || 1020 };
                    } else {
                        official = isSaturday ? satOfficial : regOfficial;
                    }
                    let undertimeMinutes = 0;
                    const hasLunchOut = lunchOut && String(lunchOut).trim() && lunchOut !== '00:00' && lunchOut !== '0:00';
                    const hasLunchIn = lunchIn && String(lunchIn).trim() && lunchIn !== '00:00' && lunchIn !== '0:00';
                    const hasTimeOut = timeOut && String(timeOut).trim() && timeOut !== '00:00' && timeOut !== '0:00';
                    if (hasLunchOut) {
                        const actualLunchOut = parseTime(lunchOut);
                        if (actualLunchOut !== null && actualLunchOut < official.lunchOut) undertimeMinutes += official.lunchOut - actualLunchOut;
                    }
                    if (hasTimeOut) {
                        const actualOut = parseTime(timeOut);
                        if (actualOut !== null && actualOut < official.timeOut) undertimeMinutes += official.timeOut - actualOut;
                    } else if (hasLunchIn) {
                        undertimeMinutes += official.timeOut - official.lunchIn;
                    }
                    utHrs = String(Math.floor(undertimeMinutes / 60));
                    utMin = String(undertimeMinutes % 60);
                }
                // Pardon cell: HR (admin/super_admin) opens for staff
                let pardonCell = '<td class="dtr-pardon small">-</td>';
                const status = log ? (log.pardon_status || '') : '';
                const open = log ? (log.pardon_open === true) : false;
                const openByList = pardonOpenDates && pardonOpenDates.indexOf(dateKey) >= 0;
                if (status === 'pending') {
                    pardonCell = '<td class="dtr-pardon small"><span class="badge bg-warning">Pending</span></td>';
                } else if (status === 'approved') {
                    pardonCell = '<td class="dtr-pardon small"><span class="badge bg-success">Approved</span></td>';
                } else if (status === 'rejected') {
                    pardonCell = '<td class="dtr-pardon small"><span class="badge bg-danger">Rejected</span></td>';
                } else if (open || openByList) {
                    pardonCell = '<td class="dtr-pardon small"><span class="badge bg-info">Opened</span> <button type="button" class="btn btn-sm btn-outline-secondary close-pardon-btn" data-date="' + dateKey + '" title="Undo accidental open">Close</button></td>';
                } else {
                    pardonCell = '<td class="dtr-pardon small"><button type="button" class="btn btn-sm btn-outline-primary open-pardon-btn" data-date="' + dateKey + '" title="Allow staff to submit pardon for this date">Open</button></td>';
                }
                const tr = document.createElement('tr');
                if (isTarfRow) {
                    tr.className = 'dtr-tarf-row';
                    tr.innerHTML = '<td class="dtr-day">' + day + '</td>'
                        + '<td class="dtr-time dtr-tarf-label">TARF</td>'
                        + '<td class="dtr-time dtr-tarf-label">TARF</td>'
                        + '<td class="dtr-time dtr-tarf-label">TARF</td>'
                        + '<td class="dtr-time dtr-tarf-label">TARF</td>'
                        + '<td class="dtr-undertime">—</td><td class="dtr-undertime">—</td>' + pardonCell;
                } else if (isHolidayRow && isHalfDayHoliday) {
                    tr.className = 'dtr-holiday-row dtr-half-day-holiday';
                    var hlabel = halfDayPeriod === 'afternoon' ? 'HALF-DAY PM' : 'HALF-DAY AM';
                    if (halfDayPeriod === 'afternoon') {
                        tr.innerHTML = '<td class="dtr-day">' + day + '</td>'
                            + '<td class="dtr-time">' + (timeIn || '') + '</td>'
                            + '<td class="dtr-time">' + (lunchOut || '') + '</td>'
                            + '<td class="dtr-time dtr-holiday-label">' + hlabel + '</td>'
                            + '<td class="dtr-time dtr-holiday-label">' + hlabel + '</td>'
                            + '<td class="dtr-undertime">—</td><td class="dtr-undertime">—</td>' + pardonCell;
                    } else {
                        tr.innerHTML = '<td class="dtr-day">' + day + '</td>'
                            + '<td class="dtr-time dtr-holiday-label">' + hlabel + '</td>'
                            + '<td class="dtr-time dtr-holiday-label">' + hlabel + '</td>'
                            + '<td class="dtr-time">' + (lunchIn || '') + '</td>'
                            + '<td class="dtr-time">' + (timeOut || '') + '</td>'
                            + '<td class="dtr-undertime">—</td><td class="dtr-undertime">—</td>' + pardonCell;
                    }
                } else if (isHolidayRow) {
                    tr.className = 'dtr-holiday-row';
                    tr.innerHTML = '<td class="dtr-day">' + day + '</td>'
                        + '<td class="dtr-time dtr-holiday-label">HOLIDAY</td>'
                        + '<td class="dtr-time dtr-holiday-label">HOLIDAY</td>'
                        + '<td class="dtr-time dtr-holiday-label">HOLIDAY</td>'
                        + '<td class="dtr-time dtr-holiday-label">HOLIDAY</td>'
                        + '<td class="dtr-undertime">—</td><td class="dtr-undertime">—</td>' + pardonCell;
                } else {
                    const fmtCell = function(v) { return (v === 'LEAVE') ? 'Leave' : v; };
                    tr.innerHTML = '<td class="dtr-day">' + day + '</td><td class="dtr-time">' + fmtCell(timeIn) + '</td><td class="dtr-time">' + fmtCell(lunchOut) + '</td><td class="dtr-time">' + fmtCell(lunchIn) + '</td><td class="dtr-time">' + fmtCell(timeOut) + '</td><td class="dtr-undertime">' + utHrs + '</td><td class="dtr-undertime">' + utMin + '</td>' + pardonCell;
                }
                tbody.appendChild(tr);
            }
            const totalRow = document.createElement('tr');
            totalRow.className = 'dtr-total';
            totalRow.innerHTML = '<td class="dtr-day">Total</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>';
            tbody.appendChild(totalRow);

            tbody.querySelectorAll('.open-pardon-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const date = this.getAttribute('data-date');
                    if (!date || !selectedEmployeeId) return;
                    const f = new FormData();
                    f.append('employee_id', selectedEmployeeId);
                    f.append('log_date', date);
                    btn.disabled = true;
                    fetch('open_pardon_api.php', { method: 'POST', body: f })
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
                    const date = this.getAttribute('data-date');
                    if (!date || !selectedEmployeeId) return;
                    const f = new FormData();
                    f.append('employee_id', selectedEmployeeId);
                    f.append('log_date', date);
                    btn.disabled = true;
                    fetch('close_pardon_api.php', { method: 'POST', body: f })
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
        }
    </script>
</body>
</html>
