<?php
/**
 * One-time script: Generate attendance logs from official time for a specific employee.
 * Use case: Employee WPU-2026-00004 is given attendance based on official time for January–February 2026.
 *
 * Run as admin: open in browser while logged in as admin, or run via CLI with admin context.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';

if (php_sapi_name() !== 'cli') {
    session_start();
    require_once __DIR__ . '/../includes/functions.php';
    if (!isset($_SESSION['user_id']) || !isAdmin()) {
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Unauthorized. Admin login required.';
        exit;
    }
}

$database = Database::getInstance();
$db = $database->getConnection();

$EMPLOYEE_ID = 'WPU-2026-00004';
$START_DATE = '2026-01-01';
$END_DATE   = '2026-02-28';
$REMARKS    = 'official time';

/**
 * Get official times for an employee on a specific date (same logic as calendar_api).
 */
function getOfficialTimesForDate($employeeId, $date, $db) {
    $dateObj = new DateTime($date);
    $dayOfWeek = $dateObj->format('w');
    $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $weekday = $weekdays[$dayOfWeek];

    $stmt = $db->prepare("SELECT * FROM employee_official_times
                          WHERE employee_id = ?
                          AND weekday = ?
                          AND start_date <= ?
                          AND (end_date IS NULL OR end_date >= ?)
                          ORDER BY start_date DESC
                          LIMIT 1");
    $stmt->execute([$employeeId, $weekday, $date, $date]);
    $officialTime = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($officialTime) {
        $logDate = new DateTime($date);
        $logDate->setTime(0, 0, 0);
        $startDate = new DateTime($officialTime['start_date']);
        $startDate->setTime(0, 0, 0);
        $endDate = $officialTime['end_date'] ? new DateTime($officialTime['end_date']) : null;
        if ($endDate) {
            $endDate->setTime(0, 0, 0);
        }
        $isInRange = ($logDate >= $startDate) && ($endDate === null || $logDate <= $endDate);

        if ($isInRange) {
            return [
                'found' => true,
                'time_in' => $officialTime['time_in'],
                'lunch_out' => ($officialTime['lunch_out'] && $officialTime['lunch_out'] != '00:00:00') ? $officialTime['lunch_out'] : '12:00:00',
                'lunch_in' => ($officialTime['lunch_in'] && $officialTime['lunch_in'] != '00:00:00') ? $officialTime['lunch_in'] : '13:00:00',
                'time_out' => $officialTime['time_out'],
            ];
        }
    }

    return ['found' => false];
}

// Verify employee has official times for the period (at least one weekday in range)
$stmtOT = $db->prepare("SELECT COUNT(*) AS c FROM employee_official_times
    WHERE employee_id = ? AND start_date <= ? AND (end_date IS NULL OR end_date >= ?)");
$stmtOT->execute([$EMPLOYEE_ID, $END_DATE, $START_DATE]);
$hasOfficial = (int) $stmtOT->fetch(PDO::FETCH_ASSOC)['c'] > 0;
if (!$hasOfficial) {
    $msg = "Employee $EMPLOYEE_ID has no official times covering $START_DATE to $END_DATE. Set official times in Employee Logs → Manage Official Times first.";
    if (php_sapi_name() === 'cli') {
        echo $msg . "\n";
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
    exit;
}

// Ensure optional columns exist
try {
    $db->exec("ALTER TABLE attendance_logs ADD COLUMN remarks VARCHAR(500) DEFAULT NULL");
} catch (Exception $e) { /* ignore if exists */ }
try {
    $db->exec("ALTER TABLE attendance_logs ADD COLUMN holiday_id INT DEFAULT NULL");
} catch (Exception $e) { /* ignore if exists */ }

$start = new DateTime($START_DATE);
$end   = new DateTime($END_DATE);
$end->modify('+1 day');

$created = 0;
$updated = 0;
$skipped = 0;
$errors = [];

while ($start < $end) {
    $date = $start->format('Y-m-d');
    $start->modify('+1 day');

    $official = getOfficialTimesForDate($EMPLOYEE_ID, $date, $db);
    if (!$official['found']) {
        $skipped++;
        continue;
    }

    $stmtCheck = $db->prepare("SELECT id FROM attendance_logs WHERE employee_id = ? AND log_date = ?");
    $stmtCheck->execute([$EMPLOYEE_ID, $date]);
    $existing = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    try {
        if ($existing) {
            $stmt = $db->prepare("
                UPDATE attendance_logs
                SET time_in = ?, lunch_out = ?, lunch_in = ?, time_out = ?, remarks = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $official['time_in'],
                $official['lunch_out'],
                $official['lunch_in'],
                $official['time_out'],
                $REMARKS,
                $existing['id'],
            ]);
            $updated++;
        } else {
            $stmt = $db->prepare("
                INSERT INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $EMPLOYEE_ID,
                $date,
                $official['time_in'],
                $official['lunch_out'],
                $official['lunch_in'],
                $official['time_out'],
                $REMARKS,
            ]);
            $created++;
        }
    } catch (Exception $e) {
        $errors[] = "$date: " . $e->getMessage();
    }
}

$out = [];
$out[] = "Done. Employee: $EMPLOYEE_ID";
$out[] = "Period: $START_DATE to $END_DATE";
$out[] = "Created: $created | Updated: $updated | Skipped (no official time): $skipped";
if (!empty($errors)) {
    $out[] = "Errors: " . implode('; ', $errors);
}

$message = implode("\n", $out);
if (php_sapi_name() === 'cli') {
    echo $message . "\n";
} else {
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
}
