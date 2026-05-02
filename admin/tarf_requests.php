<?php
/**
 * Admin: all employee Travel Activity Request Form (TARF) submissions with status.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

$tableExists = false;
try {
    $tbl = $db->query("SHOW TABLES LIKE 'tarf_requests'");
    $tableExists = $tbl && $tbl->rowCount() > 0;
} catch (Exception $e) {
    $tableExists = false;
}

/**
 * Map raw DB status to admin bucket: pending | approved | rejected.
 */
$tarfAdminBucket = static function (string $raw): string {
    $s = $raw === 'pending' ? 'pending_supervisor' : $raw;
    if (in_array($s, ['pending_supervisor', 'pending_endorser', 'pending_joint', 'pending_president'], true)) {
        return 'pending';
    }
    if ($s === 'endorsed') {
        return 'approved';
    }
    if ($s === 'rejected') {
        return 'rejected';
    }
    return 'pending';
};

/**
 * Human label for workflow step (table column + badge color class suffix).
 *
 * @return array{0: string, 1: string}
 */
$tarfAdminDetailLabel = static function (string $raw): array {
    $s = $raw === 'pending' ? 'pending_supervisor' : $raw;
    $map = [
        'pending_joint' => ['Pending — parallel endorsements', 'warning'],
        'pending_supervisor' => ['Pending — parallel endorsements', 'warning'],
        'pending_endorser' => ['Pending — parallel endorsements', 'warning'],
        'pending_president' => ['Pending — President (final)', 'primary'],
        'endorsed' => ['Approved', 'success'],
        'rejected' => ['Rejected', 'danger'],
    ];
    return $map[$s] ?? ['Pending', 'secondary'];
};

$filterBucket = isset($_GET['status']) ? trim((string) $_GET['status']) : 'all';
if (!in_array($filterBucket, ['all', 'pending', 'approved', 'rejected'], true)) {
    $filterBucket = 'all';
}
$filterDept = isset($_GET['dept']) ? trim((string) $_GET['dept']) : '';
$searchQ = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$rows = [];
$departments = [];
if ($tableExists) {
    try {
        $deptStmt = $db->query("SELECT DISTINCT fp.department FROM faculty_profiles fp WHERE fp.department IS NOT NULL AND fp.department != '' ORDER BY fp.department");
        if ($deptStmt) {
            $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);
        }
    } catch (Exception $e) {
        $departments = [];
    }

    $sql = "SELECT tr.id, tr.serial_year, tr.user_id, tr.employee_id, tr.form_data, tr.status, tr.created_at,
                   u.first_name, u.last_name, u.email,
                   fp.department
            FROM tarf_requests tr
            LEFT JOIN users u ON u.id = tr.user_id
            LEFT JOIN faculty_profiles fp ON fp.user_id = tr.user_id
            ORDER BY tr.created_at DESC";
    $stmt = $db->query($sql);
    if ($stmt) {
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$filtered = [];
foreach ($rows as $r) {
    $rawSt = (string) ($r['status'] ?? 'pending_supervisor');
    $bucket = $tarfAdminBucket($rawSt);
    if ($filterBucket !== 'all' && $bucket !== $filterBucket) {
        continue;
    }
    if ($filterDept !== '' && trim((string) ($r['department'] ?? '')) !== $filterDept) {
        continue;
    }
    if ($searchQ !== '') {
        $needle = mb_strtolower($searchQ, 'UTF-8');
        $name = mb_strtolower(trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')), 'UTF-8');
        $empId = mb_strtolower(trim((string) ($r['employee_id'] ?? '')), 'UTF-8');
        $yearStr = mb_strtolower((string) ((int) ($r['serial_year'] ?? 0)), 'UTF-8');
        $fd = json_decode($r['form_data'] ?? '[]', true);
        $purpose = is_array($fd) ? mb_strtolower((string) ($fd['event_purpose'] ?? ''), 'UTF-8') : '';
        if ($needle !== '' && strpos($name, $needle) === false && strpos($empId, $needle) === false
            && strpos($yearStr, $needle) === false && ($purpose === '' || strpos($purpose, $needle) === false)) {
            continue;
        }
    }
    $filtered[] = $r;
}

$basePath = getBasePath();
$adminPath = $basePath ? rtrim($basePath, '/') . '/admin' : '/admin';

require_once __DIR__ . '/../includes/admin_layout_helper.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php admin_page_head('TARF requests', 'Travel Activity Request Form submissions from employees, with approval status.'); ?>
</head>
<body class="layout-admin">
    <?php
    require_once __DIR__ . '/../includes/navigation.php';
    include_navigation();
    ?>

    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'TARF requests',
                    'View all employee travel activity requests and whether each is pending, approved, or rejected.',
                    'fas fa-plane-departure',
                    [],
                    ''
                );
                ?>

                <?php displayMessage(); ?>

                <?php if (!$tableExists): ?>
                <div class="alert alert-warning">
                    <strong>Not available.</strong> The <code>tarf_requests</code> table (TARF / NTARF) is missing or incomplete. Run
                    <code>php db/migrations/run_tarf_ntarf_migrations.php</code> once, then reload this page.
                </div>
                <?php else: ?>

                <form method="get" action="" class="row g-2 align-items-end mb-3">
                    <div class="col-md-3 col-6">
                        <label class="form-label small mb-0" for="status">Status</label>
                        <select class="form-select form-select-sm" id="status" name="status" onchange="this.form.submit()">
                            <option value="all"<?php echo $filterBucket === 'all' ? ' selected' : ''; ?>>All</option>
                            <option value="pending"<?php echo $filterBucket === 'pending' ? ' selected' : ''; ?>>Pending</option>
                            <option value="approved"<?php echo $filterBucket === 'approved' ? ' selected' : ''; ?>>Approved</option>
                            <option value="rejected"<?php echo $filterBucket === 'rejected' ? ' selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-3 col-6">
                        <label class="form-label small mb-0" for="dept">Department</label>
                        <select class="form-select form-select-sm" id="dept" name="dept" onchange="this.form.submit()">
                            <option value="">All departments</option>
                            <?php foreach ($departments as $d): ?>
                            <option value="<?php echo htmlspecialchars((string) $d, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $filterDept === $d ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $d, ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-12">
                        <label class="form-label small mb-0" for="q">Search</label>
                        <input type="text" class="form-control form-control-sm" id="q" name="q" value="<?php echo htmlspecialchars($searchQ, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Name, employee ID, year, or purpose">
                    </div>
                    <div class="col-md-2 col-12 d-flex gap-1">
                        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                        <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars(clean_url($adminPath . '/tarf_requests.php', $basePath), ENT_QUOTES, 'UTF-8'); ?>">Clear</a>
                    </div>
                </form>

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h5 class="mb-0"><i class="fas fa-list me-2 text-primary"></i>Submissions</h5>
                        <span class="text-muted small"><?php echo count($filtered); ?> record(s)</span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($filtered)): ?>
                        <div class="text-center text-muted py-5 px-3">
                            <i class="fas fa-inbox fa-2x mb-2 d-block opacity-50"></i>
                            No TARF requests match the current filters.
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th scope="col">TARF # / Year</th>
                                        <th scope="col">Employee</th>
                                        <th scope="col">Department</th>
                                        <th scope="col">Summary</th>
                                        <th scope="col">Submitted</th>
                                        <th scope="col">Status</th>
                                        <th scope="col" class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filtered as $r):
                                        $rawSt = (string) ($r['status'] ?? 'pending_supervisor');
                                        $bucket = $tarfAdminBucket($rawSt);
                                        $detail = $tarfAdminDetailLabel($rawSt);
                                        $bucketLabel = $bucket === 'pending' ? 'Pending' : ($bucket === 'approved' ? 'Approved' : 'Rejected');
                                        $bucketClass = $bucket === 'pending' ? 'warning' : ($bucket === 'approved' ? 'success' : 'danger');
                                        $fd = json_decode($r['form_data'] ?? '[]', true);
                                        $purpose = is_array($fd) ? trim((string) ($fd['event_purpose'] ?? '')) : '';
                                        if ($purpose === '') {
                                            $purpose = '—';
                                        } elseif (function_exists('mb_strimwidth')) {
                                            $purpose = mb_strimwidth($purpose, 0, 72, '…', 'UTF-8');
                                        } elseif (strlen($purpose) > 72) {
                                            $purpose = substr($purpose, 0, 69) . '...';
                                        }
                                        $empName = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                                        if ($empName === '') {
                                            $empName = 'User #' . (int) ($r['user_id'] ?? 0);
                                        }
                                        $idSerial = 'TARF #' . (int) $r['id'] . ' · s. ' . (int) ($r['serial_year'] ?? 0);
                                        $createdRaw = $r['created_at'] ?? '';
                                        $createdDisp = $createdRaw !== '' ? date('M j, Y g:i A', strtotime($createdRaw)) : '—';
                                        $viewUrl = clean_url($adminPath . '/tarf_request_view.php?id=' . (int) $r['id'], $basePath);
                                        ?>
                                    <tr>
                                        <td class="small"><?php echo $idSerial; ?></td>
                                        <td>
                                            <div class="fw-medium"><?php echo htmlspecialchars($empName, ENT_QUOTES, 'UTF-8'); ?></div>
                                            <div class="small text-muted"><?php echo htmlspecialchars((string) ($r['employee_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                        </td>
                                        <td class="small"><?php echo htmlspecialchars((string) ($r['department'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="small text-break"><?php echo htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="small text-nowrap"><?php echo htmlspecialchars($createdDisp, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo htmlspecialchars($bucketClass, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($bucketLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                                            <div class="small text-muted mt-1"><?php echo htmlspecialchars($detail[0], ENT_QUOTES, 'UTF-8'); ?></div>
                                        </td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($viewUrl, ENT_QUOTES, 'UTF-8'); ?>">View</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php admin_page_scripts(); ?>
</body>
</html>
