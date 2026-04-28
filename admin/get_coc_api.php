<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

header('Content-Type: application/json');

// Allow both admin and faculty access
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$isAdmin = isAdmin();
$isFaculty = isset($_SESSION['user_type']) && ($_SESSION['user_type'] === 'faculty' || $_SESSION['user_type'] === 'staff');
if (function_exists('closeSessionEarly')) { closeSessionEarly(); }

if (!$isAdmin && !$isFaculty) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$employee_id = $_GET['employee_id'] ?? '';

if (empty($employee_id)) {
    echo json_encode(['success' => false, 'message' => 'Safe Employee ID is required']);
    exit;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // If faculty, verify they can only access their own employee_id
    if ($isFaculty && !$isAdmin) {
        $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? AND fp.employee_id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id'], $employee_id]);
        $verify = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$verify) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized: You can only access your own COC']);
            exit;
        }
    }
    
    // Helper function to parse TIME field to minutes from midnight
    $parseTimeToMinutes = function($timeStr) {
        if (empty($timeStr) || $timeStr === null) return null;
        $timeStr = trim((string)$timeStr);
        $parts = explode(':', $timeStr);
        if (count($parts) >= 2) {
            $hours = intval($parts[0]);
            $minutes = intval($parts[1]);
            return ($hours * 60) + $minutes;
        }
        return null;
    };

    $isTimeLogged = function($time) {
        if (empty($time)) return false;
        $time = trim((string)$time);
        return $time !== '00:00' && $time !== '00:00:00' && $time !== '0:00' && $time !== '0:00:00';
    };

    // Get employment_status for COC rule: hours worked on days without official time → COC (permanent/temporary only)
    $employment_status = '';
    $stmtEmp = $db->prepare("SELECT employment_status FROM faculty_profiles WHERE employee_id = ? LIMIT 1");
    $stmtEmp->execute([$employee_id]);
    $row = $stmtEmp->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['employment_status'])) {
        $employment_status = trim($row['employment_status']);
    }
    $isPermanentOrTemporary = (strcasecmp($employment_status, 'Permanent') === 0 || strcasecmp($employment_status, 'Temporary') === 0);

    // Official times for this employee (to detect "no official time" days)
    $official_times_list = [];
    if ($isPermanentOrTemporary) {
        $stmtOT = $db->prepare("SELECT start_date, end_date, weekday FROM employee_official_times WHERE employee_id = ? ORDER BY start_date DESC");
        $stmtOT->execute([$employee_id]);
        $official_times_list = $stmtOT->fetchAll(PDO::FETCH_ASSOC);
    }
    $getWeekdayName = function($dateStr) {
        $date = new DateTime($dateStr);
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return $weekdays[(int)$date->format('w')];
    };
    $hasOfficialTimeForDate = function($list, $logDate) use ($getWeekdayName) {
        $logWeekday = $getWeekdayName($logDate);
        foreach ($list as $ot) {
            $weekday = $ot['weekday'] ?? null;
            if (($weekday === null || $weekday === $logWeekday) && $ot['start_date'] <= $logDate && ($ot['end_date'] === null || $ot['end_date'] >= $logDate)) {
                return true;
            }
        }
        return false;
    };

    $total_coc_points = 0;

    // COC from hours worked on days when employee has no official time (permanent and temporary only)
    if ($isPermanentOrTemporary) {
        $stmtNoOT = $db->prepare("
            SELECT log_date, time_in, lunch_out, lunch_in, time_out
            FROM attendance_logs
            WHERE employee_id = ?
            AND time_in IS NOT NULL AND time_in != '' AND time_in != '00:00' AND time_in != '00:00:00'
            AND time_out IS NOT NULL AND time_out != '' AND time_out != '00:00' AND time_out != '00:00:00'
            ORDER BY log_date ASC
        ");
        $stmtNoOT->execute([$employee_id]);
        $logsNoOfficial = $stmtNoOT->fetchAll(PDO::FETCH_ASSOC);
        foreach ($logsNoOfficial as $log) {
            // Count hours as COC only when there is no official time for this date (or no official times set at all)
            if (!empty($official_times_list) && $hasOfficialTimeForDate($official_times_list, $log['log_date'])) {
                continue;
            }
            $in_min = $parseTimeToMinutes($log['time_in']);
            $out_min = $parseTimeToMinutes($log['time_out']);
            $lunch_out_min = $isTimeLogged($log['lunch_out']) ? $parseTimeToMinutes($log['lunch_out']) : null;
            $lunch_in_min = $isTimeLogged($log['lunch_in']) ? $parseTimeToMinutes($log['lunch_in']) : null;
            if ($in_min === null || $out_min === null || $out_min <= $in_min) {
                continue;
            }
            $worked_minutes = 0;
            if ($lunch_out_min !== null && $lunch_in_min !== null) {
                $morning = max(0, $lunch_out_min - $in_min);
                $afternoon = max(0, $out_min - $lunch_in_min);
                $worked_minutes = $morning + $afternoon;
            } else {
                $worked_minutes = $out_min - $in_min;
            }
            $total_coc_points += $worked_minutes / 60;
        }
    }

    // Get all attendance logs with OT in and OT out (overtime window → COC)
    $stmt = $db->prepare("
        SELECT ot_in, ot_out
        FROM attendance_logs
        WHERE employee_id = ? 
        AND ot_in IS NOT NULL 
        AND ot_out IS NOT NULL
        AND ot_in != '' 
        AND ot_out != ''
        ORDER BY log_date ASC
    ");
    $stmt->execute([$employee_id]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($logs as $log) {
        $ot_in_minutes = $parseTimeToMinutes($log['ot_in']);
        $ot_out_minutes = $parseTimeToMinutes($log['ot_out']);
        
        if ($ot_in_minutes !== null && $ot_out_minutes !== null && $ot_out_minutes > $ot_in_minutes) {
            // Calculate overtime from OT in to OT out (in hours)
            $overtime_minutes = $ot_out_minutes - $ot_in_minutes;
            $overtime_hours = $overtime_minutes / 60;
            // 1 hour overtime = 1 COC point
            $total_coc_points += $overtime_hours;
        }
    }
    
    echo json_encode([
        'success' => true,
        'employee_id' => $employee_id,
        'total_coc_points' => number_format($total_coc_points, 2, '.', ''),
        'total_coc_hours' => number_format($total_coc_points, 2, '.', '') // Same as points (1 hour = 1 point)
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error calculating COC: ' . $e->getMessage()
    ]);
}

