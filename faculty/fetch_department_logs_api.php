<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/session_optimization.php';
require_once '../includes/staff_dtr_month_data.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isFaculty() && !isStaff()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// Deans or users with pardon_opener_assignments can fetch department/scope logs
$database = Database::getInstance();
$db = $database->getConnection();
$stmt = $db->prepare("SELECT fp.designation, fp.department FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
$stmt->execute([$_SESSION['user_id']]);
$userProfile = $stmt->fetch(PDO::FETCH_ASSOC);

$deanDepartment = trim($userProfile['department'] ?? '');
$isDean = $userProfile && strtolower(trim($userProfile['designation'] ?? '')) === 'dean';

$employee_id = $_GET['employee_id'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

if (empty($employee_id)) {
    echo json_encode(['success' => false, 'logs' => [], 'message' => 'Safe Employee ID required']);
    exit;
}

// Verify: dean + employee in their dept, OR user can open pardon for this employee (pardon_opener_assignments)
$allowed = false;
$allowedViaDean = false;
$allowedViaPardonOpener = function_exists('canUserOpenPardonForEmployee') && canUserOpenPardonForEmployee($_SESSION['user_id'], $employee_id, $db);
if ($isDean && !empty($deanDepartment)) {
    $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.employee_id = ? AND fp.department = ? LIMIT 1");
    $stmt->execute([$employee_id, $deanDepartment]);
    $allowedViaDean = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    $allowed = $allowedViaDean;
}
if (!$allowed) {
    $allowed = $allowedViaPardonOpener;
}

if (!$allowed) {
    http_response_code(403);
    echo json_encode(['success' => false, 'logs' => [], 'message' => 'You do not have permission to view this employee\'s DTR.']);
    exit;
}

closeSessionEarly(true);

try {
    // DTR policy: Deans only see logs for dates the employee has submitted (daily DTR submission).
    // Pardon openers (my_assigned_employees, department/designation scope) see ALL logs so they can open pardon for any date with attendance.
    $submittedDates = [];
    if ($allowedViaDean && !$allowedViaPardonOpener) {
        $tableCheck = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
        if ($tableCheck && $tableCheck->rowCount() > 0) {
            $stmtUser = $db->prepare("SELECT user_id FROM faculty_profiles WHERE employee_id = ? LIMIT 1");
            $stmtUser->execute([$employee_id]);
            $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
            if ($userRow) {
                $stmtSub = $db->prepare("SELECT log_date FROM dtr_daily_submissions WHERE user_id = ?");
                $stmtSub->execute([$userRow['user_id']]);
                $submittedDates = $stmtSub->fetchAll(PDO::FETCH_COLUMN);
            }
        }
    }

    // Check if pardon_open table exists (for "Open pardon" column in dean DTR view)
    $hasPardonOpenTable = false;
    try {
        $tblCheck = $db->query("SHOW TABLES LIKE 'pardon_open'");
        $hasPardonOpenTable = $tblCheck && $tblCheck->rowCount() > 0;
    } catch (Exception $e) { /* ignore */ }

    $pardonOpenSub = $hasPardonOpenTable
        ? ", (SELECT 1 FROM pardon_open po WHERE po.employee_id = al.employee_id AND po.log_date = al.log_date LIMIT 1) as pardon_open_flag"
        : "";

    // Same schema as fetch_my_logs_api simple mode - no holiday auto-creation for dean view
    $query = "SELECT al.id, al.employee_id, al.log_date, al.time_in, al.lunch_out, al.lunch_in, al.time_out, al.ot_in, al.ot_out, al.total_ot, al.remarks, al.tarf_id, al.holiday_id, al.created_at,
                             (SELECT pr.status FROM pardon_requests pr 
                              WHERE pr.log_id = al.id AND pr.employee_id = al.employee_id 
                              ORDER BY pr.created_at DESC LIMIT 1) as pardon_status
                             $pardonOpenSub,
                             (SELECT t.title FROM tarf t WHERE t.id = al.tarf_id LIMIT 1) as tarf_title,
                             COALESCE((SELECT h.title FROM holidays h WHERE h.id = al.holiday_id LIMIT 1), (SELECT h2.title FROM holidays h2 WHERE h2.date = al.log_date LIMIT 1)) as holiday_title
                      FROM attendance_logs al
                      WHERE al.employee_id = ?";
    $params = [$employee_id];

    if (!empty($date_from) && !empty($date_to)) {
        $query .= " AND al.log_date >= ? AND al.log_date <= ?";
        $params[] = $date_from;
        $params[] = $date_to;
    } elseif (!empty($date_from)) {
        $query .= " AND al.log_date >= ?";
        $params[] = $date_from;
    } elseif (!empty($date_to)) {
        $query .= " AND al.log_date <= ?";
        $params[] = $date_to;
    }

    $query .= " ORDER BY al.log_date DESC LIMIT 500";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $logs = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $logDateStr = $row['log_date'] ? date('Y-m-d', strtotime($row['log_date'])) : null;
        if (!empty($submittedDates) && $logDateStr && !in_array($logDateStr, $submittedDates, true)) {
            continue; // Skip logs for dates not yet submitted by employee
        }
        $isLeave = (strtoupper(trim($row['remarks'] ?? '')) === 'LEAVE');
        $isHoliday = (!empty($row['holiday_id']) || !empty($row['holiday_title']) || (!empty($row['remarks']) && strpos($row['remarks'], 'Holiday:') === 0));
        $hasHolidayAttendance = staff_dtr_row_has_real_holiday_attendance($row, $isHoliday);
        $leaveVal = $isLeave ? 'LEAVE' : null;
        $holidayVal = ($isHoliday && !$hasHolidayAttendance) ? 'HOLIDAY' : null;
        $timeVal = $leaveVal ?: $holidayVal;
        $logs[] = [
            'id' => $row['id'],
            'employee_id' => $row['employee_id'],
            'log_date' => $row['log_date'] ? date('Y-m-d', strtotime($row['log_date'])) : null,
            'time_in' => $timeVal ?: ($row['time_in'] ? substr($row['time_in'], 0, 5) : null),
            'lunch_out' => $timeVal ?: ($row['lunch_out'] ? substr($row['lunch_out'], 0, 5) : null),
            'lunch_in' => $timeVal ?: ($row['lunch_in'] ? substr($row['lunch_in'], 0, 5) : null),
            'time_out' => $timeVal ?: ($row['time_out'] ? substr($row['time_out'], 0, 5) : null),
            'ot_in' => $row['ot_in'] ? substr($row['ot_in'], 0, 5) : null,
            'ot_out' => $row['ot_out'] ? substr($row['ot_out'], 0, 5) : null,
            'total_ot' => $row['total_ot'] ? substr($row['total_ot'], 0, 8) : null,
            'remarks' => $row['remarks'] ?? null,
            'tarf_id' => $row['tarf_id'] ?? null,
            'tarf_title' => $row['tarf_title'] ?? null,
            'holiday_id' => $row['holiday_id'] ?? null,
            'holiday_title' => $row['holiday_title'] ?? null,
            'is_tarf' => (!empty($row['remarks']) && (strpos($row['remarks'], 'TARF:') === 0 || strtoupper($row['remarks']) === 'TARF')),
            'is_holiday' => (!empty($row['holiday_id']) || !empty($row['holiday_title']) || (!empty($row['remarks']) && strpos($row['remarks'], 'Holiday:') === 0)),
            'has_holiday_attendance' => $hasHolidayAttendance,
            'created_at' => $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : null,
            'pardon_status' => $row['pardon_status'],
            'pardon_open' => $hasPardonOpenTable && !empty($row['pardon_open_flag'])
        ];
    }

    // List of dates for which pardon is open (so dean DTR can show "Opened" or "Open" for any day in month)
    $pardon_open_dates = [];
    if ($hasPardonOpenTable) {
        $stmtPo = $db->prepare("SELECT log_date FROM pardon_open WHERE employee_id = ?");
        $stmtPo->execute([$employee_id]);
        $pardon_open_dates = $stmtPo->fetchAll(PDO::FETCH_COLUMN);
    }

    // Get employee user_type - dean can only open pardon for faculty, not staff
    $employee_user_type = 'faculty';
    $stmtUT = $db->prepare("SELECT u.user_type FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.employee_id = ? LIMIT 1");
    $stmtUT->execute([$employee_id]);
    $utRow = $stmtUT->fetch(PDO::FETCH_ASSOC);
    if ($utRow) {
        $employee_user_type = $utRow['user_type'] ?? 'faculty';
    }

    // In Charge: designated pardon opener (by department/designation) or fallback to Dean
    $dean_name = '';
    $dean_department = '';
    $inCharge = function_exists('getPardonOpenerDisplayNameForEmployee') ? getPardonOpenerDisplayNameForEmployee($employee_id, $db) : '';
    if ($inCharge !== '' && $inCharge !== 'HR') {
        $parts = preg_split('/,\s*/', $inCharge, 2);
        $dean_name = trim($parts[0] ?? '');
        $dean_department = trim($parts[1] ?? '');
    } else {
        $stmtEmp = $db->prepare("SELECT fp.department FROM faculty_profiles fp WHERE fp.employee_id = ? LIMIT 1");
        $stmtEmp->execute([$employee_id]);
        $empRow = $stmtEmp->fetch(PDO::FETCH_ASSOC);
        $empDept = trim($empRow['department'] ?? '');
        if ($empDept !== '') {
            $stmtDean = $db->prepare("SELECT u.first_name, u.last_name, fp.department FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.department = ? AND LOWER(TRIM(COALESCE(fp.designation, ''))) = 'dean' LIMIT 1");
            $stmtDean->execute([$empDept]);
            $dean = $stmtDean->fetch(PDO::FETCH_ASSOC);
            if ($dean) {
                $dean_name = trim(($dean['first_name'] ?? '') . ' ' . ($dean['last_name'] ?? ''));
                $dean_department = trim($dean['department'] ?? '');
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'count' => count($logs),
        'employee_id' => $employee_id,
        'pardon_open_dates' => $pardon_open_dates,
        'employee_user_type' => $employee_user_type,
        'dean_name' => $dean_name,
        'dean_department' => $dean_department
    ]);
} catch (Exception $e) {
    error_log('fetch_department_logs_api.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error fetching logs']);
}
