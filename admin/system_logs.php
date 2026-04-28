<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

$database = Database::getInstance();
$db = $database->getConnection();

// Get logs with filters (use log_action to avoid conflicts with routing/other scripts using ?action=)
// Exclude NACS/allscan2 attendance logs from audit trail
// Exclude super_admin users from System Activity Logs
$whereClause = "1=1 AND NOT (l.action = 'TIMEKEEPER_ATTENDANCE' AND l.description LIKE '%(NACS)%') AND (l.user_id IS NULL OR u.user_type != 'super_admin')";
$params = [];

$actionFilter = isset($_GET['log_action']) && is_string($_GET['log_action']) ? trim($_GET['log_action']) : '';
if ($actionFilter !== '') {
    $whereClause .= " AND l.action = ?";
    $params[] = $actionFilter;
}

$userFilter = isset($_GET['user']) ? trim((string)$_GET['user']) : '';
if ($userFilter !== '' && $userFilter !== '0') {
    $userFilterId = (int)$userFilter;
    if ($userFilterId > 0) {
        $whereClause .= " AND l.user_id = ?";
        $params[] = $userFilterId;
    }
}

$dateFrom = isset($_GET['date_from']) && is_string($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) && is_string($_GET['date_to']) ? trim($_GET['date_to']) : '';

if ($dateFrom !== '') {
    $whereClause .= " AND DATE(l.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $whereClause .= " AND DATE(l.created_at) <= ?";
    $params[] = $dateTo;
}

$search = isset($_GET['search']) && is_string($_GET['search']) ? trim($_GET['search']) : '';
if ($search !== '') {
    $whereClause .= " AND (l.action LIKE ? OR l.description LIKE ?)";
    $searchTerm = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
    $params = array_merge($params, [$searchTerm, $searchTerm]);
}

// Pagination
// Get total count for pagination (needed to decide whether to show all logs for a specific user)
$countSql = "SELECT COUNT(*) FROM system_logs l LEFT JOIN users u ON l.user_id = u.id WHERE $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();

$perPage = in_array((int)($_GET['per_page'] ?? 10), [10, 25, 50, 100]) ? (int)$_GET['per_page'] : 10;
// If a user filter is applied, show all logs for that user (no paging)
if (!empty($userFilter)) {
    $page = 1;
    $limit = $totalLogs > 0 ? $totalLogs : 10;
    $offset = 0;
    $totalPages = 1;
} else {
    $p = getPaginationParams($db, $countSql, $params, $perPage);
    $page = $p['page'];
    $limit = $p['limit'];
    $offset = $p['offset'];
    $totalPages = $p['totalPages'];
}

$sql = "SELECT l.*, u.first_name, u.last_name, u.email, u.user_type
        FROM system_logs l
        LEFT JOIN users u ON l.user_id = u.id
        WHERE $whereClause
        ORDER BY l.created_at DESC
        LIMIT $limit OFFSET $offset";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Get unique actions for filter
$actionsStmt = $db->query("SELECT DISTINCT action FROM system_logs ORDER BY action");
$actions = $actionsStmt->fetchAll(PDO::FETCH_COLUMN);

// Get users for filter (exclude super_admins)
$usersStmt = $db->query("SELECT DISTINCT u.id, u.first_name, u.last_name, u.email FROM users u INNER JOIN system_logs l ON l.user_id = u.id WHERE u.user_type != 'super_admin' ORDER BY u.first_name");
$users = $usersStmt->fetchAll();

// Stats: real counts (not just current page, exclude super_admins)
$loginsTodayStmt = $db->query("SELECT COUNT(*) FROM system_logs l LEFT JOIN users u ON l.user_id = u.id WHERE l.action = 'LOGIN' AND DATE(l.created_at) = CURDATE() AND (l.user_id IS NULL OR u.user_type != 'super_admin')");
$loginsToday = (int)$loginsTodayStmt->fetchColumn();
$regCountStmt = $db->query("SELECT COUNT(*) FROM system_logs l LEFT JOIN users u ON l.user_id = u.id WHERE l.action = 'REGISTER' AND (l.user_id IS NULL OR u.user_type != 'super_admin')");
$regCount = (int)$regCountStmt->fetchColumn();
$uploadCountStmt = $db->query("SELECT COUNT(*) FROM system_logs l LEFT JOIN users u ON l.user_id = u.id WHERE l.action = 'FILE_UPLOAD' AND (l.user_id IS NULL OR u.user_type != 'super_admin')");
$uploadCount = (int)$uploadCountStmt->fetchColumn();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    require_once '../includes/admin_layout_helper.php';
    admin_page_head('System Logs', 'View system activity logs and audit trail');
    ?>
</head>
<body class="layout-admin">
    <?php require_once '../includes/navigation.php';
    include_navigation(); ?>
    
    <div class="container-fluid">
        <div class="row">
            <main class="main-content">
                <?php
                admin_page_header(
                    'System Logs',
                    '',
                    'fas fa-history',
                    [
                       
                    ],
                    '<div class="btn-group" role="group" aria-label="Log actions"><button type="button" class="btn btn-sm btn-outline-primary" onclick="exportLogs()" title="Download current view as CSV"><i class="fas fa-download me-1"></i>Export CSV</button><button type="button" class="btn btn-sm btn-outline-warning" onclick="clearOldLogs()" title="Remove logs older than 30 days"><i class="fas fa-broom me-1"></i>Clear old logs</button></div>'
                );
                ?>

                <!-- (merged) statistics + filters will be rendered inside the Logs card below -->

                <!-- Logs Table (now includes statistics and filters above) -->
                <div class="card system-logs-card">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>System Activity Logs</h5>
                        <span class="d-flex align-items-center gap-2">
                            <span class="badge bg-success pulse-dot" id="system-logs-live-badge" title="Updates automatically"><i class="fas fa-circle me-1" style="font-size: 0.5rem; vertical-align: middle;"></i>Live</span>
                            <span class="text-muted small" id="system-logs-last-updated" title="Auto-refreshes every 8 seconds">Auto-refresh every 8 sec</span>
                        </span>
                    </div>
                    <div class="card-body">
                        <!-- Summary stats -->
                        <div class="row g-3 mb-4">
                            <div class="col-6 col-md-3">
                                <div class="stats-card primary">
                                    <div class="stats-number" id="stats-total-filtered"><?php echo number_format($totalLogs); ?></div>
                                    <div class="stats-label">Total (filtered)</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stats-card success">
                                    <div class="stats-number"><?php echo number_format($loginsToday); ?></div>
                                    <div class="stats-label">Logins Today</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stats-card warning">
                                    <div class="stats-number"><?php echo number_format($regCount); ?></div>
                                    <div class="stats-label">Registrations</div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stats-card info">
                                    <div class="stats-number"><?php echo number_format($uploadCount); ?></div>
                                    <div class="stats-label">File Uploads</div>
                                </div>
                            </div>
                        </div>

                        <!-- Filters: collapsible on small screens -->
                        <div class="system-logs-filters mb-4">
                            <?php
                            $hasFilters = $actionFilter || $userFilter || $dateFrom || $dateTo || $search;
                            ?>
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#logsFilterCollapse" aria-expanded="<?php echo $hasFilters ? 'true' : 'false'; ?>" aria-controls="logsFilterCollapse" id="logsFilterToggle">
                                    <i class="fas fa-filter me-1"></i><span>Filters</span>
                                    <?php if ($hasFilters): ?>
                                        <span class="badge bg-primary ms-1"><?php echo ($actionFilter ? 1 : 0) + ($userFilter ? 1 : 0) + ($dateFrom || $dateTo ? 1 : 0) + ($search ? 1 : 0); ?></span>
                                    <?php endif; ?>
                                </button>
                                <?php if ($hasFilters): ?>
                                    <a href="system_logs.php" class="btn btn-link btn-sm text-muted">Clear all filters</a>
                                <?php endif; ?>
                            </div>
                            <div class="collapse <?php echo $hasFilters ? 'show' : ''; ?>" id="logsFilterCollapse">
                                <form id="system-logs-filter-form" class="system-logs-filter-form" role="search">
                                    <div class="row g-3">
                                        <div class="col-12 col-sm-6 col-md-2">
                                            <label for="log_action" class="form-label small text-muted">Action type</label>
                                            <select class="form-select form-select-sm" id="log_action" name="log_action" aria-label="Filter by action">
                                                <option value="">All actions</option>
                                                <?php foreach ($actions as $act): ?>
                                                    <option value="<?php echo htmlspecialchars($act); ?>" <?php echo $actionFilter === $act ? 'selected' : ''; ?>><?php echo htmlspecialchars($act); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-sm-6 col-md-2">
                                            <label for="user" class="form-label small text-muted">User</label>
                                            <select class="form-select form-select-sm" id="user" name="user" aria-label="Filter by user">
                                                <option value="">All users</option>
                                                <?php foreach ($users as $u): ?>
                                                    <option value="<?php echo (int)$u['id']; ?>" <?php echo $userFilter == $u['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-sm-6 col-md-2">
                                            <label for="date_from" class="form-label small text-muted">From date</label>
                                            <input type="date" class="form-control form-control-sm" id="date_from" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" aria-label="From date">
                                        </div>
                                        <div class="col-12 col-sm-6 col-md-2">
                                            <label for="date_to" class="form-label small text-muted">To date</label>
                                            <input type="date" class="form-control form-control-sm" id="date_to" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" aria-label="To date">
                                        </div>
                                        <div class="col-12 col-sm-6 col-md-3">
                                            <label for="search" class="form-label small text-muted">Search</label>
                                            <input type="text" class="form-control form-control-sm" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Action or description…" aria-label="Search logs">
                                        </div>
                                        <div class="col-12 col-sm-6 col-md-1 d-flex align-items-end gap-1">
                                            <button type="button" id="system-logs-clear-filters" class="btn btn-outline-secondary btn-sm" aria-label="Reset filters" title="Clear filters"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div id="system-logs-results">
                        <?php if (empty($logs)): ?>
                            <div class="empty-state text-center py-5 px-3">
                                <i class="fas fa-inbox fa-4x text-muted mb-3" aria-hidden="true"></i>
                                <h5 class="empty-title mb-2">No logs found</h5>
                                <p class="text-muted mb-3">No system activity matches your current filters. Try broadening your search or clear filters to see recent activity.</p>
                                <button type="button" class="btn btn-outline-primary btn-sm" id="system-logs-clear-filters-inline">Clear filters</button>
                            </div>
                        <?php else: ?>
                            <?php $start = $totalLogs ? (($page - 1) * $limit) + 1 : 0; $end = min($page * $limit, $totalLogs); ?>
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3" data-logs-toolbar>
                                <p class="mb-0 small text-muted" data-logs-summary>
                                    Showing <strong><?php echo number_format($start); ?></strong>–<strong><?php echo number_format($end); ?></strong> of <strong><?php echo number_format($totalLogs); ?></strong> logs
                                </p>
                                <div class="d-flex align-items-center gap-2">
                                    <label for="per_page" class="small text-muted mb-0">Per page</label>
                                    <select class="form-select form-select-sm" id="per_page" style="width: auto;" data-logs-per-page>
                                        <?php foreach ([10, 25, 50, 100] as $n): ?>
                                            <option value="<?php echo $n; ?>" <?php echo $perPage === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm align-middle system-logs-table" aria-label="System activity logs">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col">Time</th>
                                            <th scope="col">User</th>
                                            <th scope="col">Action</th>
                                            <th scope="col">Description</th>
                                            <th scope="col">IP</th>
                                        </tr>
                                    </thead>
                                    <tbody data-logs-tbody>
                                        <?php foreach ($logs as $log): 
                                            $act = $log['action'] ?? '';
                                            $secActions = ['LOGIN_FAILED', 'LOGIN_ATTEMPT_INACTIVE', 'RATE_LIMIT_EXCEEDED', 'CSRF_TOKEN_INVALID', 'STATION_LOGIN_FAILED', 'PASSWORD_CHANGE_FAILED'];
                                            if (in_array($act, ['LOGIN', 'EMAIL_VERIFIED', 'TIMEKEEPER_LOGIN', 'STATION_LOGIN'])) { $badgeClass = 'success'; }
                                            elseif (in_array($act, $secActions)) { $badgeClass = 'danger'; }
                                            elseif (in_array($act, ['REGISTER', 'FILE_UPLOAD', 'FILE_SUBMITTED', 'PDS_SUBMIT', 'PARDON_SUBMIT', 'QR_SCAN', 'TIMEKEEPER_ATTENDANCE'])) { $badgeClass = 'primary'; }
                                            elseif (in_array($act, ['LOGOUT', 'PASSWORD_RESET', 'TIMEKEEPER_LOGOUT'])) { $badgeClass = 'info'; }
                                            else { $badgeClass = 'warning'; }
                                            if ($act === 'LOGIN' || strpos($act, 'LOGIN') !== false) { $actionIcon = 'fa-sign-in-alt'; }
                                            elseif ($act === 'LOGOUT') { $actionIcon = 'fa-sign-out-alt'; }
                                            elseif ($act === 'QR_SCAN' || $act === 'TIMEKEEPER_ATTENDANCE') { $actionIcon = 'fa-qrcode'; }
                                            elseif ($act === 'FILE_UPLOAD' || strpos($act, 'FILE') !== false) { $actionIcon = 'fa-file-upload'; }
                                            elseif ($act === 'REGISTER') { $actionIcon = 'fa-user-plus'; }
                                            elseif ($act === 'PDS_SUBMIT' || $act === 'PDS_SAVE') { $actionIcon = 'fa-file-alt'; }
                                            elseif (strpos($act, 'PARDON') !== false) { $actionIcon = 'fa-hand-holding-heart'; }
                                            else { $actionIcon = 'fa-circle'; }
                                        ?>
                                            <tr>
                                                <td class="text-nowrap"><small><?php echo formatDate($log['created_at'], 'M j, Y g:i A'); ?></small></td>
                                                <td>
                                                    <?php if ($log['user_id']): ?>
                                                        <div class="d-flex flex-column">
                                                            <span class="fw-semibold"><?php echo htmlspecialchars(trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? '')) ?: 'Unknown'); ?></span>
                                                            <small class="text-muted"><?php echo htmlspecialchars($log['email'] ?? ''); ?></small>
                                                            <span class="badge bg-<?php echo in_array($log['user_type'] ?? '', ['admin', 'super_admin']) ? 'primary' : 'secondary'; ?> align-self-start mt-1" style="font-size: 0.7rem;"><?php echo ucfirst($log['user_type'] ?? ''); ?></span>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-muted fst-italic">System</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $badgeClass; ?>"><i class="fas <?php echo $actionIcon; ?> me-1" aria-hidden="true"></i><?php echo htmlspecialchars($log['action']); ?></span>
                                                </td>
                                                <td><div class="system-logs-desc"><?php echo htmlspecialchars($log['description'] ?? ''); ?></div></td>
                                                <td><small class="text-muted"><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></small></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($totalPages > 1): ?>
                                <nav aria-label="Logs pagination" class="mt-3" data-logs-pagination>
                                    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
                                        <p class="mb-0 small text-muted">Page <?php echo $page; ?> of <?php echo $totalPages; ?></p>
                                        <ul class="pagination pagination-sm mb-0">
                                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="#" data-page="1" aria-label="First page">First</a></li>
                                            <?php if ($page > 1): ?><li class="page-item"><a class="page-link" href="#" data-page="<?php echo $page - 1; ?>" aria-label="Previous">Prev</a></li><?php endif; ?>
                                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="#" data-page="<?php echo $i; ?>"><?php echo $i; ?></a></li>
                                            <?php endfor; ?>
                                            <?php if ($page < $totalPages): ?><li class="page-item"><a class="page-link" href="#" data-page="<?php echo $page + 1; ?>" aria-label="Next">Next</a></li><?php endif; ?>
                                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="#" data-page="<?php echo $totalPages; ?>" aria-label="Last page">Last</a></li>
                                        </ul>
                                    </div>
                                </nav>
                            <?php endif; ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <style>
        .system-logs-card .stats-card { min-height: auto; }
        .system-logs-filters .collapse { margin-top: 0.5rem; }
        .system-logs-filter-form .row { align-items: flex-end; }
        .system-logs-desc { max-width: 320px; word-break: break-word; }
        .system-logs-table th { white-space: nowrap; }
        .system-logs-table .badge { font-weight: 500; }
        .pulse-dot { animation: pulse-dot 2s ease-in-out infinite; }
        @keyframes pulse-dot { 0%, 100% { opacity: 1; } 50% { opacity: 0.6; } }
        @media (max-width: 767px) {
            .system-logs-table table { font-size: 0.875rem; }
            .system-logs-per-page { flex-wrap: wrap; }
        }
    </style>
    <?php admin_page_scripts(); ?>
    <script>
        (function() {
            var API_URL = 'system_logs_api.php';
            var DEBOUNCE_MS = 320;
            var POLL_INTERVAL_MS = 8000;
            var searchTimeout = null;
            var pollTimer = null;

            function getFilterParams(page) {
                var form = document.getElementById('system-logs-filter-form');
                var perPageEl = document.getElementById('per_page');
                return {
                    log_action: form ? (form.querySelector('#log_action') && form.querySelector('#log_action').value) || '' : '',
                    user: form ? (form.querySelector('#user') && form.querySelector('#user').value) || '' : '',
                    date_from: form ? (form.querySelector('#date_from') && form.querySelector('#date_from').value) || '' : '',
                    date_to: form ? (form.querySelector('#date_to') && form.querySelector('#date_to').value) || '' : '',
                    search: form ? (form.querySelector('#search') && form.querySelector('#search').value) || '' : '',
                    page: typeof page === 'number' ? page : 1,
                    per_page: perPageEl ? parseInt(perPageEl.value, 10) || 10 : 10
                };
            }

            function buildQueryString(params) {
                var parts = [];
                ['log_action', 'user', 'date_from', 'date_to', 'search', 'page', 'per_page'].forEach(function(k) {
                    if (params[k] !== undefined && params[k] !== '' && params[k] !== null) parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(params[k]));
                });
                return parts.join('&');
            }

            function renderRow(log) {
                var userCell = log.user_id
                    ? '<div class="d-flex flex-column"><span class="fw-semibold">' + log.user_display + '</span><small class="text-muted">' + log.user_email + '</small><span class="badge bg-' + ((log.user_type === 'Admin' || log.user_type === 'Super_admin') ? 'primary' : 'secondary') + ' align-self-start mt-1" style="font-size: 0.7rem;">' + log.user_type + '</span></div>'
                    : '<span class="text-muted fst-italic">System</span>';
                return '<tr><td class="text-nowrap"><small>' + log.created_at_formatted + '</small></td><td>' + userCell + '</td><td><span class="badge bg-' + log.badge_class + '"><i class="fas ' + log.action_icon + ' me-1" aria-hidden="true"></i>' + log.action + '</span></td><td><div class="system-logs-desc">' + log.description + '</div></td><td><small class="text-muted">' + log.ip_address + '</small></td></tr>';
            }

            function renderResults(data) {
                var totalLogs = data.totalLogs;
                var page = data.page;
                var totalPages = data.totalPages;
                var limit = data.limit;
                var logs = data.logs || [];
                var start = totalLogs ? ((page - 1) * limit) + 1 : 0;
                var end = Math.min(page * limit, totalLogs);
                var num = function(n) { return n.toLocaleString(); };

                var statsEl = document.getElementById('stats-total-filtered');
                if (statsEl) statsEl.textContent = num(totalLogs);

                var container = document.getElementById('system-logs-results');
                if (!container) return;

                if (logs.length === 0) {
                    container.innerHTML = '<div class="empty-state text-center py-5 px-3"><i class="fas fa-inbox fa-4x text-muted mb-3" aria-hidden="true"></i><h5 class="empty-title mb-2">No logs found</h5><p class="text-muted mb-3">No system activity matches your current filters. Try broadening your search or clear filters to see recent activity.</p><button type="button" class="btn btn-outline-primary btn-sm" id="system-logs-clear-filters-inline">Clear filters</button></div>';
                    return;
                }

                var tbody = logs.map(renderRow).join('');
                var perPageOptions = [10, 25, 50, 100].map(function(n) {
                    return '<option value="' + n + '"' + (limit === n ? ' selected' : '') + '>' + n + '</option>';
                }).join('');
                var paginationHtml = '';
                if (totalPages > 1) {
                    var pagParts = ['<li class="page-item' + (page <= 1 ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="1" aria-label="First page">First</a></li>'];
                    if (page > 1) pagParts.push('<li class="page-item"><a class="page-link" href="#" data-page="' + (page - 1) + '" aria-label="Previous">Prev</a></li>');
                    for (var i = Math.max(1, page - 2); i <= Math.min(totalPages, page + 2); i++) pagParts.push('<li class="page-item' + (i === page ? ' active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>');
                    if (page < totalPages) pagParts.push('<li class="page-item"><a class="page-link" href="#" data-page="' + (page + 1) + '" aria-label="Next">Next</a></li>');
                    pagParts.push('<li class="page-item' + (page >= totalPages ? ' disabled' : '') + '"><a class="page-link" href="#" data-page="' + totalPages + '" aria-label="Last page">Last</a></li>');
                    paginationHtml = '<nav aria-label="Logs pagination" class="mt-3" data-logs-pagination><div class="d-flex flex-wrap align-items-center justify-content-between gap-2"><p class="mb-0 small text-muted">Page ' + page + ' of ' + totalPages + '</p><ul class="pagination pagination-sm mb-0">' + pagParts.join('') + '</ul></div></nav>';
                }
                container.innerHTML =
                    '<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3" data-logs-toolbar><p class="mb-0 small text-muted" data-logs-summary">Showing <strong>' + num(start) + '</strong>–<strong>' + num(end) + '</strong> of <strong>' + num(totalLogs) + '</strong> logs</p><div class="d-flex align-items-center gap-2"><label for="per_page" class="small text-muted mb-0">Per page</label><select class="form-select form-select-sm" id="per_page" style="width: auto;" data-logs-per-page>' + perPageOptions + '</select></div></div>' +
                    '<div class="table-responsive"><table class="table table-hover table-sm align-middle system-logs-table" aria-label="System activity logs"><thead class="table-light"><tr><th scope="col">Time</th><th scope="col">User</th><th scope="col">Action</th><th scope="col">Description</th><th scope="col">IP</th></tr></thead><tbody data-logs-tbody>' + tbody + '</tbody></table></div>' + paginationHtml;
            }

            function showLoading() {
                var container = document.getElementById('system-logs-results');
                if (container) container.innerHTML = '<div class="text-center py-5"><i class="fas fa-spinner fa-spin fa-2x text-muted"></i><p class="mt-2 text-muted small">Loading logs…</p></div>';
            }

            function updateLastUpdated() {
                var el = document.getElementById('system-logs-last-updated');
                if (el) {
                    var now = new Date();
                    el.textContent = 'Updated ' + now.toLocaleTimeString();
                    el.title = 'Last refresh: ' + now.toLocaleString() + ' (auto-refresh every 8 sec)';
                }
            }

            function fetchLogs(page, silent) {
                var params = getFilterParams(page);
                var qs = buildQueryString(params);
                if (!silent) showLoading();
                fetch(API_URL + '?' + qs, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        renderResults(data);
                        updateLastUpdated();
                        if (typeof history !== 'undefined' && history.replaceState && !silent) {
                            var url = 'system_logs.php' + (qs ? '?' + qs : '');
                            history.replaceState({ logsPage: params.page }, '', url);
                        }
                    })
                    .catch(function(err) {
                        var container = document.getElementById('system-logs-results');
                        if (container) container.innerHTML = '<div class="alert alert-danger">Failed to load logs. Please try again or refresh the page.</div>';
                        console.error(err);
                    });
            }

            function clearFilters() {
                var form = document.getElementById('system-logs-filter-form');
                if (form) {
                    form.querySelector('#log_action').value = '';
                    form.querySelector('#user').value = '';
                    form.querySelector('#date_from').value = '';
                    form.querySelector('#date_to').value = '';
                    form.querySelector('#search').value = '';
                }
                var perPage = document.getElementById('per_page');
                if (perPage) perPage.value = '10';
                fetchLogs(1);
            }

            function onFilterChange() { fetchLogs(1); }
            function onSearchInput() {
                if (searchTimeout) clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() { fetchLogs(1); }, DEBOUNCE_MS);
            }

            document.addEventListener('DOMContentLoaded', function() {
                var form = document.getElementById('system-logs-filter-form');
                if (form) {
                    form.addEventListener('submit', function(e) { e.preventDefault(); fetchLogs(1); });
                    ['log_action', 'user', 'date_from', 'date_to'].forEach(function(id) {
                        var el = form.querySelector('#' + id);
                        if (el) el.addEventListener('change', onFilterChange);
                    });
                    var searchEl = form.querySelector('#search');
                    if (searchEl) searchEl.addEventListener('input', onSearchInput);
                }
                var clearBtn = document.getElementById('system-logs-clear-filters');
                if (clearBtn) clearBtn.addEventListener('click', clearFilters);
                document.addEventListener('click', function(e) {
                    if (e.target.id === 'system-logs-clear-filters-inline') { e.preventDefault(); clearFilters(); return; }
                    var pageLink = e.target.closest('a[data-page]');
                    if (pageLink && pageLink.getAttribute('href') === '#') {
                        e.preventDefault();
                        if (pageLink.closest('.page-item.disabled')) return;
                        var p = parseInt(pageLink.getAttribute('data-page'), 10);
                        if (!isNaN(p)) fetchLogs(p);
                    }
                });
                document.body.addEventListener('change', function(e) {
                    if (e.target.id === 'per_page') fetchLogs(1);
                });

                function pollLogs() {
                    if (document.hidden) return;
                    var page = 1;
                    var pagination = document.querySelector('[data-logs-pagination]');
                    if (pagination) {
                        var activePage = pagination.querySelector('.page-item.active .page-link');
                        if (activePage) page = parseInt(activePage.getAttribute('data-page'), 10) || 1;
                    }
                    fetchLogs(page, true);
                }

                pollTimer = setInterval(pollLogs, POLL_INTERVAL_MS);
                document.addEventListener('visibilitychange', function() {
                    if (document.hidden && pollTimer) { clearInterval(pollTimer); pollTimer = null; }
                    else if (!document.hidden && !pollTimer) pollTimer = setInterval(pollLogs, POLL_INTERVAL_MS);
                });
            });
        })();

        function exportLogs() {
            var table = document.querySelector('.system-logs-table');
            if (!table) {
                if (typeof showError === 'function') showError('No logs to export. Apply different filters or clear filters to see logs.');
                return;
            }
            var rows = Array.from(table.querySelectorAll('tr'));
            if (rows.length === 0) {
                if (typeof showError === 'function') showError('No logs to export.');
                return;
            }
            var csvContent = rows.map(function(row) {
                var cells = Array.from(row.querySelectorAll('th, td'));
                return cells.map(function(cell) { return '"' + cell.textContent.trim().replace(/"/g, '""') + '"'; }).join(',');
            }).join('\n');
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8' });
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = 'system_logs_export.csv';
            a.click();
            window.URL.revokeObjectURL(url);
            if (typeof showSuccess === 'function') showSuccess('Export started. File will download shortly.');
        }
        function clearOldLogs() {
            if (!confirm('Clear logs older than 30 days? This cannot be undone.')) return;
            fetch('clear_logs.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'clear_old' })
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.success) {
                    if (typeof showSuccess === 'function') showSuccess('Cleared ' + data.count + ' old log entries.');
                    setTimeout(function() { location.reload(); }, 1500);
                } else {
                    if (typeof showError === 'function') showError('Failed: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(function() {
                if (typeof showError === 'function') showError('Failed to clear logs.');
            });
        }
    </script>
</body>
</html>







