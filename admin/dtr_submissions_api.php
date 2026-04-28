<?php
/**
 * DTR Submissions API - returns filtered submissions as JSON for realtime filtering.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

$tableCheck = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
if (!$tableCheck || $tableCheck->rowCount() === 0) {
    echo json_encode(['success' => false, 'message' => 'DTR submissions are not configured.']);
    exit;
}

$yearFilter = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$monthFilter = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$departmentFilter = isset($_GET['department']) ? trim($_GET['department']) : '';
$employeeTypeFilter = isset($_GET['employee_type']) ? trim($_GET['employee_type']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$dateFrom = sprintf('%04d-%02d-01', $yearFilter, $monthFilter);
$lastDay = (int) date('t', mktime(0, 0, 0, $monthFilter, 1, $yearFilter));
$dateTo = sprintf('%04d-%02d-%02d', $yearFilter, $monthFilter, $lastDay);

$whereClause = "ds.log_date >= ? AND ds.log_date <= ?";
$params = [$dateFrom, $dateTo];

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
    // Only show DTRs verified by Dean or assigned Pardon Opener. Admin cannot verify.
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

$offset = ($page - 1) * $perPage;
$totalPages = max(1, (int)ceil($totalRows / $perPage));

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

echo json_encode([
    'success' => true,
    'submissions' => $submissions,
    'total_rows' => $totalRows,
    'page' => $page,
    'total_pages' => $totalPages,
    'per_page' => $perPage,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'has_verified_column' => $hasVerifiedColumn,
    'has_verified_by_column' => $hasVerifiedByColumn,
    'month_label' => date('F Y', mktime(0, 0, 0, $monthFilter, 1, $yearFilter)),
]);
