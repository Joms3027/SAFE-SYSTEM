<?php
/**
 * Open pardon for an employee+date (staff or faculty).
 * Faculty may also be opened by their supervisor (faculty/open_pardon_api.php).
 * Super Admin and Admin (HR) may open pardon for staff and faculty here.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

// Check pardon_open table exists
$tbl = $db->query("SHOW TABLES LIKE 'pardon_open'");
if (!$tbl || $tbl->rowCount() === 0) {
    echo json_encode(['success' => false, 'message' => 'Pardon open feature is not available.']);
    exit;
}

$employee_id = trim($_POST['employee_id'] ?? $_GET['employee_id'] ?? '');
$log_date = trim($_POST['log_date'] ?? $_GET['log_date'] ?? '');

if ($employee_id === '' || $log_date === '') {
    echo json_encode(['success' => false, 'message' => 'Employee ID and log date are required.']);
    exit;
}

// Validate date
$dateObj = DateTime::createFromFormat('Y-m-d', $log_date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $log_date) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format. Use Y-m-d.']);
    exit;
}

// Get employee type
$stmt = $db->prepare("SELECT u.user_type FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.employee_id = ? AND u.is_active = 1 LIMIT 1");
$stmt->execute([$employee_id]);
$empRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$empRow) {
    echo json_encode(['success' => false, 'message' => 'Employee not found.']);
    exit;
}

$empUserType = $empRow['user_type'] ?? '';
$sessionUserType = $_SESSION['user_type'] ?? '';

if (!in_array($empUserType, ['staff', 'faculty'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid employee type for pardon.']);
    exit;
}
if (!in_array($sessionUserType, ['super_admin', 'admin'], true)) {
    echo json_encode(['success' => false, 'message' => 'Only HR can open pardon from this tool.']);
    exit;
}

try {
    $stmt = $db->prepare("INSERT INTO pardon_open (employee_id, log_date, opened_by_user_id) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE opened_by_user_id = VALUES(opened_by_user_id), opened_at = CURRENT_TIMESTAMP");
    $stmt->execute([$employee_id, $log_date, $_SESSION['user_id']]);
    logAction('PARDON_OPENED', "HR opened pardon for {$empUserType} $employee_id on $log_date");
    
    // Fetch updated pardon_open_dates for this employee
    $stmtPo = $db->prepare("SELECT log_date FROM pardon_open WHERE employee_id = ?");
    $stmtPo->execute([$employee_id]);
    $pardon_open_dates = $stmtPo->fetchAll(PDO::FETCH_COLUMN);
    
    $who = $empUserType === 'faculty' ? 'The faculty member' : 'The staff member';
    echo json_encode(['success' => true, 'message' => "Pardon opened for this date. {$who} can now submit a pardon request from View Logs.", 'pardon_open_dates' => $pardon_open_dates]);
} catch (Exception $e) {
    error_log('admin/open_pardon_api.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to open pardon. Please try again.']);
}
