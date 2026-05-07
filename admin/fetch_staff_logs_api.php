<?php
/**
 * Fetch attendance logs for an employee (admin and super_admin).
 * Used by Employees DTR page to view employee DTRs and open pardon.
 */
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/session_optimization.php';
require_once '../includes/staff_dtr_month_data.php';

header('Content-Type: application/json');

requireAdmin();

$employee_id = $_GET['employee_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

if (empty($employee_id)) {
    echo json_encode(['success' => false, 'logs' => [], 'message' => 'Employee ID required']);
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

// Verify employee exists (faculty or staff) and resolve user_type for clients
$stmt = $db->prepare("SELECT u.user_type FROM faculty_profiles fp 
                      JOIN users u ON fp.user_id = u.id 
                      WHERE fp.employee_id = ? AND u.user_type IN ('staff', 'faculty') AND u.is_active = 1 LIMIT 1");
$stmt->execute([$employee_id]);
$empTypeRow = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$empTypeRow) {
    http_response_code(403);
    echo json_encode(['success' => false, 'logs' => [], 'message' => 'Employee not found.']);
    exit;
}
$resolvedEmployeeUserType = $empTypeRow['user_type'] ?? 'staff';

closeSessionEarly(true);

try {
    if ($date_from === '' || $date_to === '') {
        $date_from = date('Y-m-01');
        $date_to = date('Y-m-t');
    }

    $bundle = staff_dtr_fetch_month_bundle($db, $employee_id, $date_from, $date_to);

    echo json_encode([
        'success' => true,
        'logs' => $bundle['logs'],
        'count' => count($bundle['logs']),
        'employee_id' => $employee_id,
        'pardon_open_dates' => $bundle['pardon_open_dates'],
        'employee_user_type' => $resolvedEmployeeUserType,
        'official_regular' => $bundle['official_regular'],
        'official_saturday' => $bundle['official_saturday'],
        'official_by_date' => $bundle['official_by_date'] ?? [],
        'in_charge' => $bundle['in_charge']
    ]);
} catch (Exception $e) {
    error_log('fetch_staff_logs_api.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error fetching logs']);
}
