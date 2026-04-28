<?php
/**
 * Close pardon for an employee+date (Dean or users with pardon_opener_assignments).
 * Reverses an accidental "Open" - removes the pardon_open entry so the employee can no longer submit pardon for that date.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

// Deans or users with pardon_opener_assignments can close pardon
$stmt = $db->prepare("SELECT fp.designation, fp.department FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

$deanDepartment = trim($userProfile['department'] ?? '');
$isDean = $userProfile && strtolower(trim($userProfile['designation'] ?? '')) === 'dean';

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

// Verify: dean + employee in their dept, OR user can open pardon for this employee (pardon_opener_assignments)
$allowed = false;
if ($isDean && !empty($deanDepartment)) {
    $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp 
                          JOIN users u ON fp.user_id = u.id 
                          WHERE fp.employee_id = ? AND fp.department = ? AND u.user_type = 'faculty' LIMIT 1");
    $stmt->execute([$employee_id, $deanDepartment]);
    $allowed = (bool) $stmt->fetch();
}
if (!$allowed && function_exists('canUserOpenPardonForEmployee')) {
    $allowed = canUserOpenPardonForEmployee($_SESSION['user_id'], $employee_id, $db);
}

if (!$allowed) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to close pardon for this employee.']);
    exit;
}

// Policy: You cannot close pardon for yourself. Only the person assigned to you can do that.
$stmtMe = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
$stmtMe->execute([$_SESSION['user_id']]);
$me = $stmtMe->fetch(PDO::FETCH_ASSOC);
if ($me && !empty($me['employee_id']) && trim($me['employee_id']) === $employee_id) {
    echo json_encode(['success' => false, 'message' => 'You cannot close pardon for yourself. Only the person assigned to you can manage your pardon.']);
    exit;
}

try {
    $stmt = $db->prepare("DELETE FROM pardon_open WHERE employee_id = ? AND log_date = ?");
    $stmt->execute([$employee_id, $log_date]);
    if ($stmt->rowCount() > 0) {
        logAction('PARDON_CLOSED', "Closed pardon for employee $employee_id on $log_date");
    }
    // Fetch updated pardon_open_dates for this employee
    $stmtPo = $db->prepare("SELECT log_date FROM pardon_open WHERE employee_id = ?");
    $stmtPo->execute([$employee_id]);
    $pardon_open_dates = $stmtPo->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode(['success' => true, 'message' => 'Pardon closed for this date.', 'pardon_open_dates' => $pardon_open_dates]);
} catch (Exception $e) {
    error_log('faculty/close_pardon_api.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to close pardon. Please try again.']);
}
