<?php
/**
 * JSON API for system logs (used for real-time filtering without page reload).
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();
if (function_exists('closeSessionEarly')) { closeSessionEarly(); }

header('Content-Type: application/json; charset=utf-8');

$database = Database::getInstance();
$db = $database->getConnection();

// Exclude NACS/allscan2 attendance logs from audit trail (match system_logs.php)
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

$countSql = "SELECT COUNT(*) FROM system_logs l LEFT JOIN users u ON l.user_id = u.id WHERE $whereClause";
$countStmt = $db->prepare($countSql);
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();

$perPage = in_array((int)($_GET['per_page'] ?? 10), [10, 25, 50, 100]) ? (int)$_GET['per_page'] : 10;
if (!empty($userFilter) && $userFilter !== '0' && (int)$userFilter > 0) {
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
        LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
$stmt = $db->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$secActions = ['LOGIN_FAILED', 'LOGIN_ATTEMPT_INACTIVE', 'RATE_LIMIT_EXCEEDED', 'CSRF_TOKEN_INVALID', 'STATION_LOGIN_FAILED', 'PASSWORD_CHANGE_FAILED'];
$getBadgeClass = function ($action) use ($secActions) {
    return in_array($action, ['LOGIN', 'EMAIL_VERIFIED', 'TIMEKEEPER_LOGIN', 'STATION_LOGIN']) ? 'success' : (in_array($action, $secActions) ? 'danger' : (in_array($action, ['REGISTER', 'FILE_UPLOAD', 'FILE_SUBMITTED', 'PDS_SUBMIT', 'PARDON_SUBMIT', 'QR_SCAN', 'TIMEKEEPER_ATTENDANCE']) ? 'primary' : (in_array($action, ['LOGOUT', 'PASSWORD_RESET', 'TIMEKEEPER_LOGOUT']) ? 'info' : 'warning')));
};
$getActionIcon = function ($action) {
    if ($action === 'LOGIN' || strpos($action, 'LOGIN') !== false) return 'fa-sign-in-alt';
    if ($action === 'LOGOUT') return 'fa-sign-out-alt';
    if ($action === 'QR_SCAN' || $action === 'TIMEKEEPER_ATTENDANCE') return 'fa-qrcode';
    if ($action === 'FILE_UPLOAD' || strpos($action, 'FILE') !== false) return 'fa-file-upload';
    if ($action === 'REGISTER') return 'fa-user-plus';
    if ($action === 'PDS_SUBMIT' || $action === 'PDS_SAVE') return 'fa-file-alt';
    if (strpos($action, 'PARDON') !== false) return 'fa-hand-holding-heart';
    return 'fa-circle';
};

$rows = [];
foreach ($logs as $log) {
    $rows[] = [
        'created_at_formatted' => formatDate($log['created_at'], 'M j, Y g:i A'),
        'user_id' => (int)$log['user_id'],
        'user_display' => $log['user_id'] ? (htmlspecialchars($log['first_name'] . ' ' . $log['last_name'])) : null,
        'user_email' => $log['user_id'] ? htmlspecialchars($log['email']) : null,
        'user_type' => $log['user_id'] ? ucfirst($log['user_type']) : null,
        'action' => $log['action'],
        'badge_class' => $getBadgeClass($log['action']),
        'action_icon' => $getActionIcon($log['action']),
        'description' => htmlspecialchars($log['description'] ?? ''),
        'ip_address' => htmlspecialchars($log['ip_address'] ?? '—'),
    ];
}

echo json_encode([
    'logs' => $rows,
    'totalLogs' => $totalLogs,
    'page' => $page,
    'totalPages' => $totalPages,
    'limit' => $limit,
]);
