<?php
/**
 * Open pardon for an employee+date.
 * Persons with pardon_opener_assignments (configured in admin settings) can open pardon for employees in their scope.
 * When opened, the employee can submit a pardon request from faculty/view_logs.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

$employee_id = trim($_POST['employee_id'] ?? $_GET['employee_id'] ?? '');
$log_date = trim($_POST['log_date'] ?? $_GET['log_date'] ?? '');
$request_id = (int)($_POST['request_id'] ?? $_GET['request_id'] ?? 0);

if ($employee_id === '' || $log_date === '') {
    echo json_encode(['success' => false, 'message' => 'Safe Employee ID and log date are required.']);
    exit;
}

// Check if user can open pardon for this employee (via pardon_opener_assignments or legacy dean)
if (!canUserOpenPardonForEmployee($_SESSION['user_id'], $employee_id, $db)) {
    echo json_encode(['success' => false, 'message' => 'You do not have permission to open pardon for this employee.']);
    exit;
}

// Policy: You cannot open pardon for yourself. Only the person assigned to you can do that.
$stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$me = $stmt->fetch(PDO::FETCH_ASSOC);
if ($me && !empty($me['employee_id']) && trim($me['employee_id']) === $employee_id) {
    echo json_encode(['success' => false, 'message' => 'You cannot open pardon for yourself. Only the person assigned to you can open your pardon.']);
    exit;
}

// Check pardon_open table exists
$tbl = $db->query("SHOW TABLES LIKE 'pardon_open'");
if (!$tbl || $tbl->rowCount() === 0) {
    echo json_encode(['success' => false, 'message' => 'Pardon open feature is not available. Please run the pardon_open migration.']);
    exit;
}

// Validate date
$dateObj = DateTime::createFromFormat('Y-m-d', $log_date);
if (!$dateObj || $dateObj->format('Y-m-d') !== $log_date) {
    echo json_encode(['success' => false, 'message' => 'Invalid date format. Use Y-m-d.']);
    exit;
}

// Verify employee exists (faculty or staff) - pardon openers can open for anyone in their scope
$stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp 
                      JOIN users u ON fp.user_id = u.id 
                      WHERE fp.employee_id = ? AND u.user_type IN ('faculty', 'staff') AND u.is_active = 1 LIMIT 1");
$stmt->execute([$employee_id]);
if (!$stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Employee not found or inactive.']);
    exit;
}

try {
    $stmt = $db->prepare("INSERT INTO pardon_open (employee_id, log_date, opened_by_user_id) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE opened_by_user_id = VALUES(opened_by_user_id), opened_at = CURRENT_TIMESTAMP");
    $stmt->execute([$employee_id, $log_date, $_SESSION['user_id']]);
    logAction('PARDON_OPENED', "Opened pardon for employee $employee_id on $log_date");
    // If opened from pardon_request_letters, mark that letter as opened
    if ($request_id > 0) {
        $tblLetters = $db->query("SHOW TABLES LIKE 'pardon_request_letters'");
        if ($tblLetters && $tblLetters->rowCount() > 0) {
            $stmtLetter = $db->prepare("UPDATE pardon_request_letters SET status = 'opened' WHERE id = ? AND employee_id = ? AND pardon_date = ?");
            $stmtLetter->execute([$request_id, $employee_id, $log_date]);
        }
    }

    // Notify the employee: in-app notification + email
    try {
        $stmtEmp = $db->prepare("SELECT fp.user_id, u.email, u.first_name, u.last_name 
                                 FROM faculty_profiles fp 
                                 INNER JOIN users u ON fp.user_id = u.id 
                                 WHERE fp.employee_id = ? AND u.is_active = 1 LIMIT 1");
        $stmtEmp->execute([$employee_id]);
        $emp = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        if ($emp && !empty($emp['user_id'])) {
            require_once __DIR__ . '/../includes/notifications.php';
            $notificationManager = getNotificationManager();
            if (method_exists($notificationManager, 'notifyEmployeePardonApproved')) {
                $notificationManager->notifyEmployeePardonApproved((int)$emp['user_id'], $log_date);
            }
            if (!empty($emp['email'])) {
                require_once __DIR__ . '/../includes/mailer.php';
                $mailer = new Mailer();
                $empName = trim(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')) ?: 'Employee';
                $mailer->sendPardonApprovedEmail($emp['email'], $empName, $log_date);
            }
        }
    } catch (Exception $e) {
        error_log('Failed to notify employee about pardon approval: ' . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => 'Pardon opened for this date. The employee can now submit a pardon request from View Logs.']);
} catch (Exception $e) {
    error_log('open_pardon_api.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to open pardon. Please try again.']);
}
