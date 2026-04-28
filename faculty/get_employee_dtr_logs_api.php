<?php
/**
 * Get attendance logs for an employee's DTR (Dean or assigned Pardon Opener).
 * Used when viewing a submitted DTR from the DTR Submissions page.
 * Dean: employees in their department. Pardon Opener: employees in their scope (admin/settings opener section).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/staff_dtr_month_data.php';

requireAuth();
if (!isFaculty() && !isStaff() && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.', 'logs' => []]);
    exit;
}
if (function_exists('closeSessionEarly')) { closeSessionEarly(); }
header('Content-Type: application/json');

$employee_id = isset($_GET['employee_id']) ? trim($_GET['employee_id']) : '';
$year = isset($_GET['year']) ? (int)$_GET['year'] : 0;
$month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$period = isset($_GET['period']) ? (int)$_GET['period'] : 0;
$date_from = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

$useDateRange = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to));
$usePeriod = (!$useDateRange && $year >= 2000 && $month >= 1 && $month <= 12 && ($period === 1 || $period === 2));

if (empty($employee_id) || (!$useDateRange && !$usePeriod)) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters. Provide employee_id and (date_from, date_to) or (year, month, period).', 'logs' => []]);
    exit;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    $stmt = $db->prepare("SELECT fp.designation, fp.department FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id']]);
    $userProfile = $stmt->fetch(PDO::FETCH_ASSOC);
    $isDean = $userProfile && strtolower(trim($userProfile['designation'] ?? '')) === 'dean';
    $deanDepartment = trim($userProfile['department'] ?? '');
    $hasPardonOpenerAssignments = hasPardonOpenerAssignments($_SESSION['user_id'], $db);

    $allowed = false;
    if ($isDean && $deanDepartment !== '') {
        // Dean: only faculty in their department (staff verified by pardon opener)
        $stmt = $db->prepare("SELECT fp.user_id, fp.department FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.employee_id = ? AND u.user_type = 'faculty' LIMIT 1");
        $stmt->execute([$employee_id]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        $allowed = $emp && trim($emp['department'] ?? '') === $deanDepartment;
    } elseif ($hasPardonOpenerAssignments && function_exists('canUserOpenPardonForEmployee')) {
        $allowed = canUserOpenPardonForEmployee($_SESSION['user_id'], $employee_id, $db);
    }

    if (!$allowed) {
        echo json_encode(['success' => false, 'message' => 'Access denied. Employee not in your scope.', 'logs' => []]);
        exit;
    }

    $stmt = $db->prepare("SELECT fp.user_id, fp.department FROM faculty_profiles fp WHERE fp.employee_id = ? LIMIT 1");
    $stmt->execute([$employee_id]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($useDateRange) {
        // date_from and date_to already set from GET
    } else {
        $dayStart = $period === 1 ? 1 : 16;
        $dayEnd = $period === 1 ? 15 : 25;
        $date_from = sprintf('%04d-%02d-%02d', $year, $month, $dayStart);
        $date_to = sprintf('%04d-%02d-%02d', $year, $month, $dayEnd);
    }

    // DTR policy: Only return logs for dates the employee has submitted (daily DTR submission)
    $submittedDates = [];
    $userRow = null;
    $tableCheck = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
    if ($tableCheck && $tableCheck->rowCount() > 0) {
        $stmtUser = $db->prepare("SELECT user_id FROM faculty_profiles WHERE employee_id = ? LIMIT 1");
        $stmtUser->execute([$employee_id]);
        $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if ($userRow) {
            $stmtSub = $db->prepare("SELECT log_date FROM dtr_daily_submissions WHERE user_id = ? AND log_date >= ? AND log_date <= ?");
            $stmtSub->execute([$userRow['user_id'], $date_from, $date_to]);
            $submittedDates = $stmtSub->fetchAll(PDO::FETCH_COLUMN);
        }
    }

    $query = "SELECT al.id, al.employee_id, al.log_date, al.time_in, al.lunch_out, al.lunch_in, al.time_out, al.remarks, al.tarf_id, al.holiday_id,
              COALESCE((SELECT h.title FROM holidays h WHERE h.id = al.holiday_id LIMIT 1), (SELECT h2.title FROM holidays h2 WHERE h2.date = al.log_date LIMIT 1)) as holiday_title,
              (SELECT t.title FROM tarf t WHERE t.id = al.tarf_id LIMIT 1) as tarf_title
              FROM attendance_logs al
              WHERE al.employee_id = ? AND al.log_date >= ? AND al.log_date <= ?
              ORDER BY al.log_date ASC";
    $stmt = $db->prepare($query);
    $stmt->execute([$employee_id, $date_from, $date_to]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $logs = [];
    foreach ($rows as $row) {
        $logDateStr = $row['log_date'] ? date('Y-m-d', strtotime($row['log_date'])) : null;
        if (!empty($submittedDates) && $logDateStr && !in_array($logDateStr, $submittedDates, true)) {
            continue; // Skip logs for dates not yet submitted by employee
        }
        $isLeave = (strtoupper(trim($row['remarks'] ?? '')) === 'LEAVE');
        $isHoliday = (!empty($row['holiday_id']) || !empty($row['holiday_title']) || (!empty($row['remarks']) && strpos($row['remarks'], 'Holiday:') === 0));
        $hasHolidayAttendance = staff_dtr_row_has_real_holiday_attendance($row, $isHoliday);
        $remarksRaw = (string) ($row['remarks'] ?? '');
        $isTarfRow = (
            (!empty($row['tarf_id']) && strpos($remarksRaw, 'TARF:') === 0)
            || strpos($remarksRaw, 'TARF_HOURS_CREDIT:') !== false
            || strtoupper(trim($remarksRaw)) === 'TARF'
        );
        $leaveVal = $isLeave ? 'LEAVE' : null;
        $holidayVal = ($isHoliday && !$hasHolidayAttendance) ? 'HOLIDAY' : null;
        $timeVal = $leaveVal ?: $holidayVal;
        $logs[] = [
            'id' => $row['id'],
            'employee_id' => $row['employee_id'],
            'log_date' => $row['log_date'] ? date('Y-m-d', strtotime($row['log_date'])) : null,
            'time_in' => $timeVal ?: ($row['time_in'] ? substr($row['time_in'], 0, 5) : '00:00'),
            'lunch_out' => $timeVal ?: ($row['lunch_out'] ? substr($row['lunch_out'], 0, 5) : '00:00'),
            'lunch_in' => $timeVal ?: ($row['lunch_in'] ? substr($row['lunch_in'], 0, 5) : '00:00'),
            'time_out' => $timeVal ?: ($row['time_out'] ? substr($row['time_out'], 0, 5) : '00:00'),
            'remarks' => $row['remarks'] ?? null,
            'tarf_id' => $row['tarf_id'] ?? null,
            'tarf_title' => $row['tarf_title'] ?? null,
            'is_tarf' => $isTarfRow,
            'is_holiday' => $isHoliday,
            'has_holiday_attendance' => $hasHolidayAttendance,
        ];
    }

    // Official times and In Charge (pardon opener or Dean) for DTR form display
    $official_regular = '08:00-12:00, 13:00-17:00';
    $official_saturday = '—';
    $dean_name = '';
    $dean_department = '';
    $inCharge = function_exists('getPardonOpenerDisplayNameForEmployee') ? getPardonOpenerDisplayNameForEmployee($employee_id, $db) : '';
    if ($inCharge === 'HR') {
        $dean_department = trim($emp['department'] ?? '');
        if ($dean_department !== '') {
            $stmtDean = $db->prepare("SELECT u.first_name, u.last_name, fp.department FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.department = ? AND LOWER(TRIM(COALESCE(fp.designation, ''))) = 'dean' LIMIT 1");
            $stmtDean->execute([$dean_department]);
            $dean = $stmtDean->fetch(PDO::FETCH_ASSOC);
            if ($dean) {
                $dean_name = trim(($dean['first_name'] ?? '') . ' ' . ($dean['last_name'] ?? ''));
                $dean_department = trim($dean['department'] ?? '');
            }
        }
    } else {
        $parts = preg_split('/,\s*/', $inCharge, 2);
        $dean_name = trim($parts[0] ?? '');
        $dean_department = trim($parts[1] ?? '');
    }

    $fmt = function($t) { return $t ? substr($t, 0, 5) : ''; };
    $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $defaultOfficial = ['lunch_out' => 12 * 60, 'lunch_in' => 13 * 60, 'time_out' => 17 * 60];

    $stmtOT = $db->prepare("SELECT weekday, start_date, end_date, time_in, lunch_out, lunch_in, time_out FROM employee_official_times WHERE employee_id = ? ORDER BY start_date DESC");
    $stmtOT->execute([$employee_id]);
    $official_times_list = $stmtOT->fetchAll(PDO::FETCH_ASSOC);

    $official_regular_set = false;
    $official_saturday_set = false;
    foreach ($official_times_list as $ot) {
        $w = trim($ot['weekday'] ?? '');
        $seg = ($fmt($ot['time_in']) && $fmt($ot['lunch_out']) ? $fmt($ot['time_in']) . '-' . $fmt($ot['lunch_out']) : '') . ($fmt($ot['lunch_in']) && $fmt($ot['time_out']) ? ', ' . $fmt($ot['lunch_in']) . '-' . $fmt($ot['time_out']) : '');
        if ($w === 'Monday' && $seg && !$official_regular_set) { $official_regular = $seg; $official_regular_set = true; }
        if ($w === 'Saturday' && $seg && !$official_saturday_set) { $official_saturday = $seg; $official_saturday_set = true; }
    }

    // Build official_by_date: per-date official times for undertime calculation (matches print_dtr logic)
    $official_by_date = [];
    $parseMin = function($t) {
        if (!$t) return null;
        $p = explode(':', $t);
        return count($p) >= 2 ? ((int)$p[0] * 60) + (int)$p[1] : null;
    };
    $d = new DateTime($date_from);
    $end = (new DateTime($date_to))->modify('+1 day');
    $interval = new DateInterval('P1D');
    while ($d < $end) {
        $dateStr = $d->format('Y-m-d');
        $logWeekday = $weekdays[(int)$d->format('w')];
        $official = $defaultOfficial;
        $mostRecentStart = null;
        foreach ($official_times_list as $ot) {
            $sd = $ot['start_date'] ?? null;
            $ed = $ot['end_date'] ?? null;
            $w = trim($ot['weekday'] ?? '');
            if ($w === $logWeekday && $sd && $sd <= $dateStr && ($ed === null || $ed === '' || $ed >= $dateStr)) {
                if ($mostRecentStart === null || $sd > $mostRecentStart) {
                    $mostRecentStart = $sd;
                    $lo = $parseMin($ot['lunch_out']);
                    $li = $parseMin($ot['lunch_in']);
                    $to = $parseMin($ot['time_out']);
                    $official = [
                        'lunch_out' => $lo !== null ? $lo : $defaultOfficial['lunch_out'],
                        'lunch_in' => $li !== null ? $li : $defaultOfficial['lunch_in'],
                        'time_out' => $to !== null ? $to : $defaultOfficial['time_out'],
                    ];
                }
            }
        }
        $official_by_date[$dateStr] = $official;
        $d->add($interval);
    }

    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'count' => count($logs),
        'employee_id' => $employee_id,
        'official_regular' => $official_regular,
        'official_saturday' => $official_saturday,
        'official_by_date' => $official_by_date,
        'dean_name' => $dean_name,
        'dean_department' => $dean_department,
    ]);
} catch (Exception $e) {
    error_log('get_employee_dtr_logs_api.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error fetching logs.', 'logs' => []]);
}
