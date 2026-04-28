<?php
/**
 * Close pardon for an employee+date.
 * Super Admin and ordinary Admin: staff (HR). Admin with pardon_opener: faculty in their scope.
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

// Get employee type and verify permission
$stmt = $db->prepare("SELECT u.user_type FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.employee_id = ? AND u.is_active = 1 LIMIT 1");
$stmt->execute([$employee_id]);
$empRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$empRow) {
    echo json_encode(['success' => false, 'message' => 'Employee not found.']);
    exit;
}
$empUserType = $empRow['user_type'] ?? '';
$sessionUserType = $_SESSION['user_type'] ?? '';

if ($empUserType === 'staff') {
    if (!in_array($sessionUserType, ['super_admin', 'admin'], true)) {
        echo json_encode(['success' => false, 'message' => 'Only HR can close staff pardon.']);
        exit;
    }
} else {
    if ($sessionUserType === 'super_admin') {
        echo json_encode(['success' => false, 'message' => 'Only staff pardon can be closed here.']);
        exit;
    }
    if (!canUserOpenPardonForEmployee($_SESSION['user_id'], $employee_id, $db)) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to close pardon for this employee.']);
        exit;
    }
}

try {
    $stmt = $db->prepare("DELETE FROM pardon_open WHERE employee_id = ? AND log_date = ?");
    $stmt->execute([$employee_id, $log_date]);
    $deleted = $stmt->rowCount();
    if ($deleted > 0) {
        logAction('PARDON_CLOSED', "HR closed pardon for staff $employee_id on $log_date");
    }
    // Fetch updated pardon_open_dates for this employee
    $stmtPo = $db->prepare("SELECT log_date FROM pardon_open WHERE employee_id = ?");
    $stmtPo->execute([$employee_id]);
    $pardon_open_dates = $stmtPo->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode(['success' => true, 'message' => 'Pardon closed for this date.', 'pardon_open_dates' => $pardon_open_dates]);
} catch (Exception $e) {
    error_log('admin/close_pardon_api.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to close pardon. Please try again.']);
}
