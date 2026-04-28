<?php
/**
 * API: Returns paginated faculty/staff list with optional filters (search, status, department).
 * Used for real-time filtering on admin faculty page without full page reload.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

requireAdmin();

header('Content-Type: application/json; charset=utf-8');

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

$whereClause = "u.user_type IN ('faculty','staff')";
$params = [];

$statusFilter = $_GET['status'] ?? '';
if ($statusFilter) {
    $whereClause .= " AND u.is_active = ?";
    $params[] = ($statusFilter === 'active') ? 1 : 0;
}

$departmentFilter = $_GET['department'] ?? '';
if ($departmentFilter) {
    $whereClause .= " AND fp.department = ?";
    $params[] = $departmentFilter;
}

$search = $_GET['search'] ?? '';
if ($search !== '') {
    $whereClause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)";
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

$itemsPerPage = isset($_GET['per_page']) && is_numeric($_GET['per_page']) ? max(1, min(100, intval($_GET['per_page']))) : 20;
$currentPage = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

$countSql = "SELECT COUNT(DISTINCT u.id) as total
    FROM users u
    LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
    WHERE $whereClause";

try {
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = (int) $countStmt->fetch()['total'];
    $totalPages = $totalCount > 0 ? (int) ceil($totalCount / $itemsPerPage) : 0;
    if ($currentPage > $totalPages && $totalPages > 0) {
        $currentPage = $totalPages;
        $offset = ($currentPage - 1) * $itemsPerPage;
    }
} catch (PDOException $e) {
    error_log("get_faculty_list count error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Query error']);
    exit;
}

$sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.is_active, u.created_at,
        fp.employee_id, fp.department, fp.position, fp.employment_status, fp.hire_date,
        (SELECT ps1.salary_grade FROM position_salary ps1 WHERE ps1.position_title = fp.position ORDER BY ps1.id LIMIT 1) as salary_grade,
        (SELECT ps1.annual_salary FROM position_salary ps1 WHERE ps1.position_title = fp.position ORDER BY ps1.id LIMIT 1) as annual_salary
    FROM users u
    LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
    WHERE $whereClause
    ORDER BY u.created_at DESC
    LIMIT ? OFFSET ?";

try {
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($params, [$itemsPerPage, $offset]));
    $faculty = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if ($faculty === false) {
        $faculty = [];
    }
} catch (PDOException $e) {
    error_log("get_faculty_list query error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Query error']);
    exit;
}

echo json_encode([
    'success' => true,
    'faculty' => $faculty,
    'totalCount' => $totalCount,
    'totalPages' => $totalPages,
    'currentPage' => $currentPage,
    'itemsPerPage' => $itemsPerPage,
]);
