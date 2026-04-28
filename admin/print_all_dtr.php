<?php
set_time_limit(600);

// Disable output buffering so content streams to browser immediately (prevents FastCGI timeout)
while (ob_get_level()) { ob_end_flush(); }
ini_set('output_buffering', '0');
ini_set('implicit_flush', '1');
// Prevent IIS dynamic compression from buffering the whole response
if (!headers_sent()) {
    header('Content-Encoding: identity');
    header('X-Accel-Buffering: no');
}

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/staff_dtr_month_data.php';

requireAdmin();

$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$positionFilter = $_GET['position'] ?? '';
$departmentFilter = $_GET['department'] ?? '';
$employmentStatusFilter = $_GET['employment_status'] ?? '';
$search = $_GET['search'] ?? '';

if (empty($date_from) || empty($date_to)) {
    die('Date range is required. Please select both start and end dates.');
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Build query to get all employees with filters
    $whereClause = "u.user_type IN ('faculty','staff') AND u.is_active = 1";
    $params = [];

    if ($search) {
        $whereClause .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR fp.employee_id LIKE ?)";
        $searchTerm = "%$search%";
        $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
    }

    if ($positionFilter) {
        $whereClause .= " AND fp.position = ?";
        $params[] = $positionFilter;
    }

    if ($departmentFilter) {
        $whereClause .= " AND fp.department = ?";
        $params[] = $departmentFilter;
    }

    if ($employmentStatusFilter) {
        $whereClause .= " AND fp.employment_status = ?";
        $params[] = $employmentStatusFilter;
    }
    
    // Get all employees matching the filters (sorted alphabetically)
    $sql = "SELECT 
                fp.employee_id,
                u.first_name,
                u.last_name,
                u.middle_name,
                fp.position,
                fp.department
            FROM users u
            LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
            WHERE $whereClause
            ORDER BY COALESCE(u.last_name, '') ASC, COALESCE(u.first_name, '') ASC, COALESCE(u.middle_name, '') ASC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($employees)) {
        die('No employees found matching the selected filters.');
    }
    
    function isLeaveDtrRow($timeIn, $lunchOut, $lunchIn, $timeOut, $remarks = null) {
        if ($remarks !== null && strtoupper(trim((string) $remarks)) === 'LEAVE') {
            return true;
        }
        foreach ([$timeIn, $lunchOut, $lunchIn, $timeOut] as $t) {
            if (strtoupper(trim((string) $t)) === 'LEAVE') {
                return true;
            }
        }
        return false;
    }

    /** Calendar / endorsed TARF mirrored into attendance_logs (remarks start with TARF:). */
    function dtr_row_is_tarf_mirror_log(array $log) {
        if (empty($log['tarf_id'])) {
            return false;
        }
        $r = (string) ($log['remarks'] ?? '');

        return strpos($r, 'TARF:') === 0;
    }

    // Helper function to check if entry is complete (matches calculateLogHours shift rules)
    function isEntryComplete($timeIn, $lunchOut, $lunchIn, $timeOut, $otIn, $otOut, $remarks = null, $officialHasLunch = true) {
        if (isLeaveDtrRow($timeIn, $lunchOut, $lunchIn, $timeOut, $remarks)) {
            return true;
        }
        if ($remarks !== null && strpos($remarks, 'TARF_HOURS_CREDIT:') !== false) {
            return true;
        }
        if ($remarks !== null && strpos($remarks, 'Holiday (Half-day PM):') === 0) {
            return isTimeLogged($lunchIn) && isTimeLogged($timeOut);
        }
        if ($remarks !== null && (strpos($remarks, 'Holiday (Half-day AM):') === 0 || strpos($remarks, 'Holiday (Half-day):') === 0)) {
            return isTimeLogged($timeIn) && isTimeLogged($lunchOut);
        }
        if ($remarks !== null && strpos($remarks, 'Holiday:') === 0) {
            return true;
        }
        if (!isTimeLogged($timeIn) || !isTimeLogged($timeOut)) {
            return false;
        }

        if ($officialHasLunch) {
            $hasLunchOut = isTimeLogged($lunchOut);
            $hasLunchIn = isTimeLogged($lunchIn);
            if (($hasLunchOut && !$hasLunchIn) || (!$hasLunchOut && $hasLunchIn)) {
                return false;
            }
        }

        $hasOtIn = isTimeLogged($otIn);
        $hasOtOut = isTimeLogged($otOut);
        if (($hasOtIn && !$hasOtOut) || (!$hasOtIn && $hasOtOut)) {
            return false;
        }

        return true;
    }
    
    // Helper function to check if a time is actually logged (not empty and not 00:00)
    function isTimeLogged($time) {
        if (empty($time)) return false;
        $time = trim($time);
        // Treat 00:00, 00:00:00, or any variation as "not logged"
        return $time !== '00:00' && $time !== '00:00:00' && $time !== '0:00' && $time !== '0:00:00';
    }
    
    // Hours worked (matches calculateLogHours): full-day = AM+PM; half-day = time_in to time_out only
    function calculateHours($timeIn, $lunchOut, $lunchIn, $timeOut, $isComplete = true, $officialHasLunch = true) {
        if (!$isComplete) {
            return null;
        }

        $hasTimeIn = isTimeLogged($timeIn);
        $hasLunchOut = isTimeLogged($lunchOut);
        $hasLunchIn = isTimeLogged($lunchIn);
        $hasTimeOut = isTimeLogged($timeOut);

        if ($officialHasLunch) {
            $morning_shift_complete = $hasTimeIn && $hasLunchOut;
            $afternoon_shift_complete = $hasLunchIn && $hasTimeOut;
            if (!$morning_shift_complete && !$afternoon_shift_complete) {
                return null;
            }
            $morning = 0;
            $afternoon = 0;
            if ($morning_shift_complete) {
                $in = strtotime($timeIn);
                $lunchOutTime = strtotime($lunchOut);
                $morning = max(0, ($lunchOutTime - $in) / 3600);
            }
            if ($afternoon_shift_complete) {
                $lunchInTime = strtotime($lunchIn);
                $out = strtotime($timeOut);
                $afternoon = max(0, ($out - $lunchInTime) / 3600);
            }
            return round($morning + $afternoon, 2);
        }

        if ($hasTimeIn && $hasTimeOut) {
            $in = strtotime($timeIn);
            $out = strtotime($timeOut);
            return round(max(0, ($out - $in) / 3600), 2);
        }
        return null;
    }
    
    // Helper function to format clock time for display (12-hour with AM/PM)
    function formatTime($time) {
        if (!$time) {
            return '-';
        }
        $ts = strtotime($time);
        if ($ts === false) {
            return '-';
        }
        return date('h:i A', $ts);
    }
    
    // Helper function to parse time to minutes from midnight
    function parseTimeToMinutes($time) {
        if (!$time) return null;
        $parts = explode(':', $time);
        if (count($parts) >= 2) {
            return (intval($parts[0]) * 60) + intval($parts[1]);
        }
        return null;
    }
    
    // Official base hours (matches JS officialBaseHours); half-day = time_in to time_out only
    function getOfficialBaseHours($official, $officialHasLunch = true) {
        if (!$official) {
            return null;
        }
        $in = parseTimeToMinutes($official['time_in']);
        $out = parseTimeToMinutes($official['time_out']);
        if ($in === null || $out === null) {
            return null;
        }
        if ($officialHasLunch) {
            $lunchOut = parseTimeToMinutes($official['lunch_out']);
            $lunchIn = parseTimeToMinutes($official['lunch_in']);
            if ($lunchOut === null || $lunchIn === null) {
                return null;
            }
            $morning = max(0, ($lunchOut - $in) / 60);
            $afternoon = max(0, ($out - $lunchIn) / 60);
            return round($morning + $afternoon, 2);
        }
        return round(max(0, ($out - $in) / 60), 2);
    }

    /** @return 'morning'|'afternoon'|null */
    function dtr_holiday_half_period_all(array $log) {
        if (!empty($log['holiday_is_half_day']) && (int)$log['holiday_is_half_day'] === 1) {
            $p = $log['holiday_half_day_period'] ?? null;
            if ($p === 'afternoon' || $p === 'morning') {
                return $p;
            }
        }
        $r = $log['remarks'] ?? '';
        if (strpos($r, 'Holiday (Half-day PM):') === 0) {
            return 'afternoon';
        }
        if (strpos($r, 'Holiday (Half-day AM):') === 0 || strpos($r, 'Holiday (Half-day):') === 0) {
            return 'morning';
        }
        return null;
    }

    function dtr_log_field_is_holiday_marker_all($v) {
        return $v !== null && strtoupper(trim((string) $v)) === 'HOLIDAY';
    }

    /** Half-day holiday total hours (work half actual + holiday half official). Matches faculty view_logs.js. */
    function dtr_half_day_holiday_total_hours_display_all(array $log, $official, $officialHasLunch) {
        if (!$official) {
            return null;
        }
        $p = dtr_holiday_half_period_all($log);
        if ($p !== 'morning' && $p !== 'afternoon') {
            return null;
        }
        $in = parseTimeToMinutes($official['time_in']);
        $out = parseTimeToMinutes($official['time_out']);
        $lo = parseTimeToMinutes($official['lunch_out']);
        $li = parseTimeToMinutes($official['lunch_in']);

        $om = 0.0;
        $oa = 0.0;
        if ($officialHasLunch && $in !== null && $out !== null && $lo !== null && $li !== null) {
            $om = max(0, $lo - $in);
            $oa = max(0, $out - $li);
        } elseif ($in !== null && $out !== null) {
            $total = max(0, $out - $in);
            $om = $total / 2;
            $oa = $total / 2;
        } else {
            return null;
        }

        $ti = $log['time_in'] ?? null;
        $lOt = $log['lunch_out'] ?? null;
        $lIn = $log['lunch_in'] ?? null;
        $tOut = $log['time_out'] ?? null;

        $hasTi = isTimeLogged($ti) && !dtr_log_field_is_holiday_marker_all($ti);
        $hasLo = isTimeLogged($lOt) && !dtr_log_field_is_holiday_marker_all($lOt);
        $hasLi = isTimeLogged($lIn) && !dtr_log_field_is_holiday_marker_all($lIn);
        $hasTo = isTimeLogged($tOut) && !dtr_log_field_is_holiday_marker_all($tOut);
        $holTi = dtr_log_field_is_holiday_marker_all($ti);
        $holLo = dtr_log_field_is_holiday_marker_all($lOt);
        $holLi = dtr_log_field_is_holiday_marker_all($lIn);
        $holTo = dtr_log_field_is_holiday_marker_all($tOut);

        $hours = 0.0;
        if ($p === 'afternoon') {
            // PM holiday: morning is work — only actual AM punches count (matches employee_logs.js calculateHalfDayHolidayHours)
            if ($hasTi && $hasLo) {
                $aIn = parseTimeToMinutes($ti);
                $aLo = parseTimeToMinutes($lOt);
                if ($aIn !== null && $aLo !== null) {
                    $hours += max(0, ($aLo - $aIn) / 60);
                }
            }
            if ($holLi && $holTo) {
                $hours += $oa / 60;
            } elseif ($hasLi && $hasTo) {
                $aLi = parseTimeToMinutes($lIn);
                $aTo = parseTimeToMinutes($tOut);
                if ($aLi !== null && $aTo !== null) {
                    $hours += max(0, ($aTo - $aLi) / 60);
                }
            } else {
                $hours += $oa / 60;
            }
        } else {
            if ($holTi && $holLo) {
                $hours += $om / 60;
            } elseif ($hasTi && $hasLo) {
                $aIn = parseTimeToMinutes($ti);
                $aLo = parseTimeToMinutes($lOt);
                if ($aIn !== null && $aLo !== null) {
                    $hours += max(0, ($aLo - $aIn) / 60);
                }
            } else {
                $hours += $om / 60;
            }
            if ($hasLi && $hasTo) {
                $aLi = parseTimeToMinutes($lIn);
                $aTo = parseTimeToMinutes($tOut);
                if ($aLi !== null && $aTo !== null) {
                    $hours += max(0, ($aTo - $aLi) / 60);
                }
            }
        }
        return round($hours, 2);
    }

    function dtr_holiday_is_half_day_all(array $log) {
        if (!empty($log['holiday_is_half_day']) && (int)$log['holiday_is_half_day'] === 1) {
            return true;
        }
        $r = $log['remarks'] ?? '';
        return strpos($r, 'Holiday (Half-day):') === 0
            || strpos($r, 'Holiday (Half-day AM):') === 0
            || strpos($r, 'Holiday (Half-day PM):') === 0;
    }
    
    // Helper function to get weekday name from date
    function getWeekdayName($dateStr) {
        $date = new DateTime($dateStr);
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        return $weekdays[(int)$date->format('w')];
    }
    
    // decimalHoursToDayFraction() is in includes/functions.php (Table IV; matches employee_logs.js)

    // Function to get logs for an employee (includes absent days when employee has official time but no log)
    function getEmployeeLogs($db, $employee_id, $date_from, $date_to) {
        $calendarHolidayTitleSub = '';
        try {
            $tcEv = $db->query("SHOW TABLES LIKE 'calendar_events'");
            if ($tcEv && $tcEv->rowCount() > 0) {
                $ceArchived = $db->query("SHOW COLUMNS FROM calendar_events LIKE 'is_archived'");
                $ceHasArchived = $ceArchived && $ceArchived->rowCount() > 0;
                $calendarHolidayTitleSub = $ceHasArchived
                    ? "(SELECT ce.title FROM calendar_events ce WHERE ce.event_type = 'holiday' AND COALESCE(ce.is_archived, 0) = 0 AND DATE(ce.event_date) = DATE(al.log_date) ORDER BY ce.id DESC LIMIT 1)"
                    : "(SELECT ce.title FROM calendar_events ce WHERE ce.event_type = 'holiday' AND DATE(ce.event_date) = DATE(al.log_date) ORDER BY ce.id DESC LIMIT 1)";
            }
        } catch (Exception $e) { /* ignore */ }
        $holidayTitleSelect = $calendarHolidayTitleSub !== ''
            ? "COALESCE((SELECT h.title FROM holidays h WHERE h.id = al.holiday_id LIMIT 1), (SELECT h2.title FROM holidays h2 WHERE h2.date = DATE(al.log_date) LIMIT 1), $calendarHolidayTitleSub)"
            : "COALESCE((SELECT h.title FROM holidays h WHERE h.id = al.holiday_id LIMIT 1), (SELECT h2.title FROM holidays h2 WHERE h2.date = DATE(al.log_date) LIMIT 1))";
        $holidayHalfDaySelect = "COALESCE((SELECT h.is_half_day FROM holidays h WHERE h.id = al.holiday_id LIMIT 1), (SELECT h2.is_half_day FROM holidays h2 WHERE h2.date = DATE(al.log_date) LIMIT 1), 0)";
        $holidayHalfPeriodSelect = "COALESCE((SELECT h.half_day_period FROM holidays h WHERE h.id = al.holiday_id LIMIT 1), (SELECT h2.half_day_period FROM holidays h2 WHERE h2.date = DATE(al.log_date) LIMIT 1), NULL)";
        
        $query = "
            SELECT 
                al.id,
                al.log_date,
                al.time_in,
                al.lunch_out,
                al.lunch_in,
                al.time_out,
                al.ot_in,
                al.ot_out,
                al.total_ot,
                al.remarks,
                al.tarf_id,
                COALESCE(al.holiday_id, (SELECT h2.id FROM holidays h2 WHERE h2.date = DATE(al.log_date) LIMIT 1)) as holiday_id,
                (SELECT t.title FROM tarf t WHERE t.id = al.tarf_id LIMIT 1) as tarf_title,
                $holidayTitleSelect as holiday_title,
                $holidayHalfDaySelect as holiday_is_half_day,
                $holidayHalfPeriodSelect as holiday_half_day_period
            FROM attendance_logs al
            WHERE al.employee_id = ?
            AND al.log_date >= ?
            AND al.log_date <= ?
            ORDER BY al.log_date ASC
        ";
        
        $stmt = $db->prepare($query);
        $stmt->execute([$employee_id, $date_from, $date_to]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Add absent days: employee has official time for a day but did not come in (no log)
        if (!empty($date_from) && !empty($date_to)) {
            $logDates = [];
            foreach ($logs as $log) {
                $ld = $log['log_date'] ? date('Y-m-d', strtotime($log['log_date'])) : null;
                if ($ld) $logDates[$ld] = true;
            }
            $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $holidayDatesWithInfo = [];
            try {
                $stmtH = $db->prepare("SELECT id, date, title, COALESCE(is_half_day, 0) AS is_half_day, half_day_period FROM holidays WHERE date >= ? AND date <= ?");
                $stmtH->execute([$date_from, $date_to]);
                while ($hr = $stmtH->fetch(PDO::FETCH_ASSOC)) {
                    $dn = $hr['date'] ? date('Y-m-d', strtotime($hr['date'])) : '';
                    if ($dn !== '') {
                        $holidayDatesWithInfo[$dn] = $hr;
                    }
                }
            } catch (Exception $e) { /* ignore */ }
            try {
                $tcHol = $db->query("SHOW TABLES LIKE 'calendar_events'");
                if ($tcHol && $tcHol->rowCount() > 0) {
                    $ceArc = $db->query("SHOW COLUMNS FROM calendar_events LIKE 'is_archived'");
                    $archSql = ($ceArc && $ceArc->rowCount() > 0) ? 'AND COALESCE(is_archived, 0) = 0' : '';
                    $stmtCe = $db->prepare("
                        SELECT title, event_date FROM calendar_events
                        WHERE event_type = 'holiday' $archSql
                        AND event_date >= ? AND event_date <= ?
                    ");
                    $stmtCe->execute([$date_from, $date_to]);
                    while ($cr = $stmtCe->fetch(PDO::FETCH_ASSOC)) {
                        $dn = $cr['event_date'] ? date('Y-m-d', strtotime($cr['event_date'])) : '';
                        if ($dn !== '' && !isset($holidayDatesWithInfo[$dn])) {
                            $holidayDatesWithInfo[$dn] = [
                                'id' => null,
                                'date' => $dn,
                                'title' => $cr['title'] ?? 'Holiday',
                            ];
                        }
                    }
                }
            } catch (Exception $e) { /* ignore */ }

            $stmtAllOT = $db->prepare("SELECT id, weekday, start_date, end_date FROM employee_official_times WHERE employee_id = ?");
            $stmtAllOT->execute([$employee_id]);
            $allOfficialTimes = $stmtAllOT->fetchAll(PDO::FETCH_ASSOC);

            $start = new DateTime($date_from);
            $end = new DateTime($date_to);
            $end->modify('+1 day');
            $interval = new DateInterval('P1D');
            $period = new DatePeriod($start, $interval, $end);
            foreach ($period as $dt) {
                $d = $dt->format('Y-m-d');
                if (isset($logDates[$d])) continue;

                if (isset($holidayDatesWithInfo[$d])) {
                    $holInfo = $holidayDatesWithInfo[$d];
                    $isHd = !empty($holInfo['is_half_day']);
                    $hp = ($isHd && (($holInfo['half_day_period'] ?? 'morning') === 'afternoon')) ? 'afternoon' : 'morning';
                    if ($isHd && $hp === 'afternoon') {
                        $rem = 'Holiday (Half-day PM): ' . ($holInfo['title'] ?? 'Holiday');
                        $ti = null;
                        $lo = null;
                        $li = '13:00:00';
                        $to = '17:00:00';
                    } elseif ($isHd) {
                        $rem = 'Holiday (Half-day AM): ' . ($holInfo['title'] ?? 'Holiday');
                        $ti = '08:00:00';
                        $lo = '12:00:00';
                        $li = null;
                        $to = null;
                    } else {
                        $rem = 'Holiday: ' . ($holInfo['title'] ?? 'Holiday');
                        $ti = '08:00:00';
                        $lo = '12:00:00';
                        $li = '13:00:00';
                        $to = '17:00:00';
                    }
                    $logs[] = [
                        'id' => null,
                        'log_date' => $d,
                        'time_in' => $ti,
                        'lunch_out' => $lo,
                        'lunch_in' => $li,
                        'time_out' => $to,
                        'ot_in' => null,
                        'ot_out' => null,
                        'total_ot' => null,
                        'remarks' => $rem,
                        'tarf_id' => null,
                        'holiday_id' => $holInfo['id'] ?? null,
                        'tarf_title' => null,
                        'holiday_title' => $holInfo['title'] ?? 'Holiday',
                        'holiday_is_half_day' => $isHd ? 1 : 0,
                        'holiday_half_day_period' => $isHd ? $hp : null
                    ];
                    continue;
                }

                $weekday = $weekdays[(int)$dt->format('w')];
                $hasOT = false;
                foreach ($allOfficialTimes as $ot) {
                    if ($ot['weekday'] === $weekday && $ot['start_date'] <= $d && ($ot['end_date'] === null || $ot['end_date'] >= $d)) {
                        $hasOT = true;
                        break;
                    }
                }
                if ($hasOT) {
                    $logs[] = [
                        'id' => null,
                        'log_date' => $d,
                        'time_in' => null,
                        'lunch_out' => null,
                        'lunch_in' => null,
                        'time_out' => null,
                        'ot_in' => null,
                        'ot_out' => null,
                        'total_ot' => null,
                        'remarks' => null,
                        'tarf_id' => null,
                        'holiday_id' => null,
                        'tarf_title' => null,
                        'holiday_title' => null
                    ];
                }
            }
            usort($logs, function ($a, $b) {
                return strcmp($a['log_date'] ?? '', $b['log_date'] ?? '');
            });
        }
        
        return $logs;
    }
    
    // Default official times
    $default_official_times = [
        'time_in' => '08:00:00',
        'lunch_out' => '12:00:00',
        'lunch_in' => '13:00:00',
        'time_out' => '17:00:00'
    ];
    
    /** True when DB row has a lunch break (matches manage_official_times_api lunch fields). */
    function officialTimesRowHasLunch(array $ot) {
        $lo = isset($ot['lunch_out']) ? trim((string) $ot['lunch_out']) : '';
        $li = isset($ot['lunch_in']) ? trim((string) $ot['lunch_in']) : '';
        return $lo !== '' && $lo !== '00:00:00' && $li !== '' && $li !== '00:00:00';
    }

    /**
     * Per-date official schedule (matches employee_logs.js getOfficialTimesForDate + calculateLogHours).
     * from_db: employee_official_times row applies; has_lunch: false = half-day (single stretch time_in–time_out).
     */
    function getOfficialTimesForDate($logDate, $official_times_list, $default_official_times) {
        $logWeekday = getWeekdayName($logDate);
        $official = $default_official_times;
        $mostRecentStartDate = null;
        $selectedOt = null;

        foreach ($official_times_list as $ot) {
            $startDate = $ot['start_date'];
            $endDate = $ot['end_date'];
            $weekday = $ot['weekday'] ?? null;

            $weekdayMatches = ($weekday === null || $weekday === $logWeekday);

            if ($weekdayMatches && $startDate <= $logDate && ($endDate === null || $endDate >= $logDate)) {
                if ($mostRecentStartDate === null || $startDate > $mostRecentStartDate) {
                    $mostRecentStartDate = $startDate;
                    $selectedOt = $ot;
                    $official = [
                        'time_in' => $ot['time_in'],
                        'lunch_out' => $ot['lunch_out'],
                        'lunch_in' => $ot['lunch_in'],
                        'time_out' => $ot['time_out']
                    ];
                }
            }
        }

        $fromDb = ($mostRecentStartDate !== null);
        $hasLunch = !$fromDb || ($selectedOt !== null && officialTimesRowHasLunch($selectedOt));

        return ['official' => $official, 'from_db' => $fromDb, 'has_lunch' => $hasLunch];
    }
    
    /**
     * Per-row DTR metrics (matches assets/js/employee_logs.js calculateLogHours).
     * @param array $log
     * @param array $official Resolved schedule for the date
     * @param bool $fromDb True when employee_official_times applies (JS official_time.found)
     * @param bool $officialHasLunch Full-day vs half-day official schedule
     */
    function calculateLogData($log, $official, $fromDb, $officialHasLunch) {
        $r = $log['remarks'] ?? '';
        if (strpos($r, 'TARF_HOURS_CREDIT:') !== false && preg_match('/TARF_HOURS_CREDIT:([\d.]+)/', $r, $tm)) {
            return [
                'absent_hours' => 0,
                'absent_period' => '',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'tardiness_undertime_hours' => 0,
                'status' => 'TARF',
                'has_official_time' => false,
            ];
        }
        $isHoliday = !empty($log['holiday_id']) || !empty($log['holiday_title'])
            || (strpos($r, 'Holiday:') === 0 || strpos($r, 'Holiday (Half-day):') === 0
                || strpos($r, 'Holiday (Half-day AM):') === 0 || strpos($r, 'Holiday (Half-day PM):') === 0);
        $hasHolidayAttendance = function_exists('staff_dtr_row_has_real_holiday_attendance')
            ? staff_dtr_row_has_real_holiday_attendance($log, $isHoliday)
            : false;

        $isLeave = isLeaveDtrRow(
            $log['time_in'] ?? null,
            $log['lunch_out'] ?? null,
            $log['lunch_in'] ?? null,
            $log['time_out'] ?? null,
            $log['remarks'] ?? null
        );

        // Approved leave: credit base hours from official schedule (same as JS)
        if ($isLeave) {
            $baseHours = getOfficialBaseHours($official, $officialHasLunch);
            if ($baseHours !== null) {
                return [
                    'absent_hours' => 0,
                    'absent_period' => '',
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                    'tardiness_undertime_hours' => 0,
                    'status' => 'LEAVE',
                    'has_official_time' => true,
                ];
            }
            return [
                'absent_hours' => 0,
                'absent_period' => '',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'tardiness_undertime_hours' => 0,
                'status' => 'LEAVE',
                'has_official_time' => false,
            ];
        }

        // Holiday auto-credit: only when from_db (JS: !officialTimesData.found → 0 hours); else credit base, hasOfficialTime false in UI
        if ($isHoliday && !$hasHolidayAttendance) {
            if (!$fromDb) {
                return [
                    'absent_hours' => 0,
                    'absent_period' => '',
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                    'tardiness_undertime_hours' => 0,
                    'status' => 'Holiday',
                    'has_official_time' => false,
                ];
            }
            $baseHours = dtr_holiday_is_half_day_all($log)
                ? dtr_half_day_holiday_total_hours_display_all($log, $official, $officialHasLunch)
                : getOfficialBaseHours($official, $officialHasLunch);
            if ($baseHours !== null) {
                return [
                    'absent_hours' => 0,
                    'absent_period' => '',
                    'late_minutes' => 0,
                    'undertime_minutes' => 0,
                    'tardiness_undertime_hours' => 0,
                    'status' => 'Holiday',
                    'has_official_time' => false,
                ];
            }
            return [
                'absent_hours' => 0,
                'absent_period' => '',
                'late_minutes' => 0,
                'undertime_minutes' => 0,
                'tardiness_undertime_hours' => 0,
                'status' => 'Holiday',
                'has_official_time' => false,
            ];
        }

        $hasOfficialTime = true;

        $has_time_in = isTimeLogged($log['time_in']);
        $has_lunch_out = isTimeLogged($log['lunch_out']);
        $has_lunch_in = isTimeLogged($log['lunch_in']);
        $has_time_out = isTimeLogged($log['time_out']);

        $morningShiftComplete = $officialHasLunch ? ($has_time_in && $has_lunch_out) : ($has_time_in && $has_time_out);
        $afternoonShiftComplete = $officialHasLunch ? ($has_lunch_in && $has_time_out) : false;
        $halfDayComplete = !$officialHasLunch && $has_time_in && $has_time_out;

        $morningShiftAbsent = !$has_time_in;
        $afternoonShiftAbsent = $officialHasLunch ? !$has_lunch_in : false;
        $isAbsent = $morningShiftAbsent || $afternoonShiftAbsent;

        $absentPeriod = '';
        if ($isAbsent) {
            if ($morningShiftAbsent && $afternoonShiftAbsent) {
                $absentPeriod = 'full';
            } elseif ($morningShiftAbsent) {
                $absentPeriod = 'morning';
            } elseif ($afternoonShiftAbsent) {
                $absentPeriod = 'afternoon';
            }
        }

        $absentHours = 0;
        $lateMinutes = 0;
        $undertimeMinutes = 0;

        $official_in_minutes = parseTimeToMinutes($official['time_in']);
        $official_out_minutes = parseTimeToMinutes($official['time_out']);
        $official_lunch_out_minutes = parseTimeToMinutes($official['lunch_out']);
        $official_lunch_in_minutes = parseTimeToMinutes($official['lunch_in']);

        if ($isAbsent && $hasOfficialTime) {
            if ($officialHasLunch && $official_in_minutes !== null && $official_out_minutes !== null
                && $official_lunch_out_minutes !== null && $official_lunch_in_minutes !== null) {
                $lunch_break_minutes = $official_lunch_in_minutes - $official_lunch_out_minutes;
                $expected_total_minutes = ($official_out_minutes - $official_in_minutes) - $lunch_break_minutes;
                $morning_minutes = $official_lunch_out_minutes - $official_in_minutes;
                $afternoon_minutes = $official_out_minutes - $official_lunch_in_minutes;
                if ($morningShiftAbsent && $afternoonShiftAbsent) {
                    $absentHours = $expected_total_minutes / 60;
                } elseif ($morningShiftAbsent) {
                    $absentHours = $morning_minutes / 60;
                } elseif ($afternoonShiftAbsent) {
                    $absentHours = $afternoon_minutes / 60;
                }
            } elseif (!$officialHasLunch && $official_in_minutes !== null && $official_out_minutes !== null) {
                $expectedMinutes = $official_out_minutes - $official_in_minutes;
                if ($morningShiftAbsent && $afternoonShiftAbsent) {
                    $absentHours = $expectedMinutes / 60;
                }
            }
        }

        // Morning/afternoon absent → LATE / UNDERTIME (not Absent column), except full-day absent
        if ($absentPeriod === 'morning') {
            $lateMinutes += $absentHours * 60;
            $absentHours = 0;
        } elseif ($absentPeriod === 'afternoon') {
            $undertimeMinutes += $absentHours * 60;
            $absentHours = 0;
        }

        if ($officialHasLunch && $hasOfficialTime && $official_in_minutes !== null && $official_lunch_out_minutes !== null
            && $has_time_in && !$has_lunch_out) {
            $lateMinutes += ($official_lunch_out_minutes - $official_in_minutes);
        }

        $overtimeMinutes = 0;
        if (isTimeLogged($log['ot_in']) && isTimeLogged($log['ot_out'])) {
            $otInMinutes = parseTimeToMinutes($log['ot_in']);
            $otOutMinutes = parseTimeToMinutes($log['ot_out']);
            if ($otInMinutes !== null && $otOutMinutes !== null && $otOutMinutes > $otInMinutes) {
                $overtimeMinutes = $otOutMinutes - $otInMinutes;
            }
        }

        $isComplete = $officialHasLunch ? ($morningShiftComplete && $afternoonShiftComplete) : $halfDayComplete;

        if ($morningShiftComplete || $afternoonShiftComplete || $halfDayComplete) {
            if ($hasOfficialTime && $official_in_minutes !== null && $official_out_minutes !== null) {
                if ($officialHasLunch && $official_lunch_out_minutes !== null && $official_lunch_in_minutes !== null) {
                    if ($morningShiftComplete) {
                        $actual_in_minutes = parseTimeToMinutes($log['time_in']);
                        if ($actual_in_minutes !== null && $actual_in_minutes > $official_in_minutes) {
                            $lateMinutes = $actual_in_minutes - $official_in_minutes;
                        }
                    }
                    if ($afternoonShiftComplete) {
                        $actual_lunch_in_minutes = parseTimeToMinutes($log['lunch_in']);
                        if ($actual_lunch_in_minutes !== null && $actual_lunch_in_minutes > $official_lunch_in_minutes) {
                            $lateMinutes += ($actual_lunch_in_minutes - $official_lunch_in_minutes);
                        }
                    }
                    if ($morningShiftComplete) {
                        $actual_lunch_out_minutes = parseTimeToMinutes($log['lunch_out']);
                        if ($actual_lunch_out_minutes !== null && $actual_lunch_out_minutes < $official_lunch_out_minutes) {
                            $undertimeMinutes += ($official_lunch_out_minutes - $actual_lunch_out_minutes);
                        }
                    }
                    if ($afternoonShiftComplete) {
                        $actual_out_minutes = parseTimeToMinutes($log['time_out']);
                        if ($actual_out_minutes !== null && $actual_out_minutes < $official_out_minutes) {
                            $undertimeMinutes += ($official_out_minutes - $actual_out_minutes);
                        }
                    }
                    if ($morningShiftComplete && $has_lunch_in && !$has_time_out) {
                        $undertimeMinutes += ($official_out_minutes - $official_lunch_in_minutes);
                    }
                } elseif (!$officialHasLunch && $halfDayComplete) {
                    $actual_in_minutes = parseTimeToMinutes($log['time_in']);
                    $actual_out_minutes = parseTimeToMinutes($log['time_out']);
                    if ($actual_in_minutes !== null && $actual_in_minutes > $official_in_minutes) {
                        $lateMinutes = $actual_in_minutes - $official_in_minutes;
                    }
                    if ($actual_out_minutes !== null && $actual_out_minutes < $official_out_minutes) {
                        $undertimeMinutes = $official_out_minutes - $actual_out_minutes;
                    }
                }
            }
        }

        $tardinessUndertimeHours = ($lateMinutes + $undertimeMinutes) / 60;

        $status = '';
        $lateHours = $lateMinutes / 60;
        $undertimeHours = $undertimeMinutes / 60;
        $overtimeHours = $overtimeMinutes / 60;

        if ($hasOfficialTime) {
            if ($isAbsent && $absentPeriod === 'full') {
                $status = 'Absent';
            } elseif (!$isComplete && $undertimeHours > 0) {
                $status = $lateHours > 0 ? 'Late & Undertime' : 'Undertime';
            } elseif (!$isComplete && $lateHours > 0) {
                $status = 'Late';
            } elseif (!$isComplete) {
                $status = 'Incomplete';
            } elseif ($lateHours > 0 && $undertimeHours > 0) {
                $status = 'Late & Undertime';
            } elseif ($lateHours > 0) {
                $status = 'Late';
            } elseif ($undertimeHours > 0) {
                $status = 'Undertime';
            } elseif ($overtimeHours > 0) {
                $status = 'Overtime';
            } else {
                $status = 'Complete';
            }
        }

        return [
            'absent_hours' => $absentHours,
            'absent_period' => $absentPeriod,
            'late_minutes' => $lateMinutes,
            'undertime_minutes' => $undertimeMinutes,
            'tardiness_undertime_hours' => $tardinessUndertimeHours,
            'status' => $status,
            'has_official_time' => $hasOfficialTime,
        ];
    }
    
    // Helper function to format minutes to HH:MM:SS
    function minutesToTimeFormat($minutes) {
        if ($minutes <= 0) return '00:00:00';
        $hours = floor($minutes / 60);
        $mins = floor($minutes % 60);
        $secs = 0;
        return sprintf('%02d:%02d:%02d', $hours, $mins, $secs);
    }
    
    // Legacy function for backward compatibility (prefer calculateLogData with from_db + has_lunch)
    function calculateTardinessAbsentLate($log, $official) {
        $data = calculateLogData($log, $official, true, true);
        return [
            'absent_hours' => $data['absent_hours'],
            'late_hours' => $data['late_minutes'] / 60,
            'total' => $data['absent_hours'] + ($data['late_minutes'] / 60)
        ];
    }
    
    $dateRangeText = date('F d, Y', strtotime($date_from)) . ' to ' . date('F d, Y', strtotime($date_to));
    
} catch (Exception $e) {
    die('Error: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Employees DTR - <?php echo htmlspecialchars($dateRangeText); ?></title>
    <style>
        @media print {
            @page { 
                margin: 0.5in;
                size: A4 landscape;
            }
            body { margin: 0; }
            .no-print { display: none !important; }
            .employee-page {
                page-break-after: always;
                page-break-inside: avoid;
            }
            .employee-page:last-child {
                page-break-after: auto;
            }
        }
        
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: white;
        }
        
        .no-print {
            text-align: right;
            margin-bottom: 20px;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 4px;
        }
        
        .no-print button {
            padding: 8px 15px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-left: 5px;
        }
        
        .no-print button:hover {
            background: #0056b3;
        }
        
        .employee-page {
            margin-bottom: 40px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
        }
        
        .header h1 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
        }
        
        .header h2 {
            margin: 10px 0 0 0;
            font-size: 18px;
            font-weight: normal;
        }
        
        .employee-info {
            margin-bottom: 20px;
        }
        
        .employee-info table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .employee-info td {
            padding: 5px 10px;
        }
        
        .employee-info td:first-child {
            font-weight: bold;
            width: 150px;
        }
        
        .dtr-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 10px;
        }
        
        .dtr-table th,
        .dtr-table td {
            border: 1px solid #333;
            padding: 4px 6px;
            text-align: center;
            font-size: 10px;
        }
        
        .dtr-table th {
            background-color: #f0f0f0;
            font-weight: bold;
            white-space: nowrap;
        }
        
        .dtr-table td {
            word-wrap: break-word;
        }
        
        .dtr-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        @media print {
            .dtr-table {
                font-size: 8px;
            }
            
            .dtr-table th,
            .dtr-table td {
                padding: 3px 4px;
                font-size: 8px;
            }
        }
        
        .summary {
            margin-top: 20px;
            padding: 15px;
            background-color: #f0f0f0;
            border: 1px solid #333;
        }
        
        .summary-layout {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }
        
        .summary-layout > tbody > tr > td {
            vertical-align: top;
            width: 50%;
            padding: 0 8px 0 0;
        }
        
        .summary-layout > tbody > tr > td:last-child {
            padding: 0 0 0 8px;
        }
        
        .summary-computation table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .summary-computation td {
            padding: 5px 10px;
            border: 1px solid #333;
        }
        
        .summary-computation td:first-child {
            font-weight: bold;
            width: 200px;
        }
        
        .summary-computation td:last-child {
            text-align: right;
        }
        
        .summary-official-title {
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 12px;
        }
        
        .summary-official table {
            width: 100%;
            border-collapse: collapse;
            font-size: 10px;
        }
        
        .summary-official th,
        .summary-official td {
            padding: 4px 6px;
            border: 1px solid #333;
            text-align: center;
        }
        
        .summary-official th {
            background-color: #e8e8e8;
            font-weight: bold;
        }
        
        .summary-official td.summary-official-day,
        .summary-official td.summary-official-effective {
            text-align: left;
        }
        
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #333;
            font-size: 11px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">Print All DTRs</button>
        <button onclick="window.close()">Close</button>
        <p style="margin-top: 10px; color: #666; font-size: 14px;">
            <strong>Total Employees:</strong> <?php echo count($employees); ?> | 
            <strong>Date Range:</strong> <?php echo htmlspecialchars($dateRangeText); ?>
        </p>
        <p id="loadingProgress" style="color: #007bff; font-size: 14px;">Loading DTRs... <span id="progressCount">0</span>/<?php echo count($employees); ?></p>
    </div>
    <?php flush(); ?>
    
    <?php $empIndex = 0; foreach ($employees as $employee): $empIndex++; ?>
        <?php
        $logs = getEmployeeLogs($db, $employee['employee_id'], $date_from, $date_to);
        
        // Get official times for the employee
        $stmt = $db->prepare("
            SELECT * FROM employee_official_times 
            WHERE employee_id = ? 
            ORDER BY start_date DESC
        ");
        $stmt->execute([$employee['employee_id']]);
        $official_times_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals (only for complete entries)
        $totalHours = 0;
        $totalOT = 0;
        $completeDays = 0;
        $incompleteDays = 0;
        $totalLateHoursForDays = 0;
        $totalUndertimeHoursForDays = 0;
        $totalAbsentHoursForDays = 0;
        foreach ($logs as $log) {
            $res = getOfficialTimesForDate($log['log_date'], $official_times_list, $default_official_times);
            $official = $res['official'];
            $fromDb = $res['from_db'];
            $hasLunch = $res['has_lunch'];

            $r = $log['remarks'] ?? '';
            $isHolidayRow = !empty($log['holiday_id']) || !empty($log['holiday_title'])
                || (strpos($r, 'Holiday:') === 0 || strpos($r, 'Holiday (Half-day):') === 0
                    || strpos($r, 'Holiday (Half-day AM):') === 0 || strpos($r, 'Holiday (Half-day PM):') === 0);
            $hasHolidayAttendance = staff_dtr_row_has_real_holiday_attendance($log, $isHolidayRow);
            $isLeaveRow = isLeaveDtrRow(
                $log['time_in'] ?? null,
                $log['lunch_out'] ?? null,
                $log['lunch_in'] ?? null,
                $log['time_out'] ?? null,
                $log['remarks'] ?? null
            );
            $tarfCredHours = null;
            if (preg_match('/TARF_HOURS_CREDIT:([\d.]+)/', (string) ($log['remarks'] ?? ''), $tx)) {
                $tarfCredHours = (float) $tx[1];
            }

            $isComplete = isEntryComplete(
                $log['time_in'],
                $log['lunch_out'],
                $log['lunch_in'],
                $log['time_out'],
                $log['ot_in'],
                $log['ot_out'],
                $log['remarks'] ?? null,
                $hasLunch
            );

            $logData = calculateLogData($log, $official, $fromDb, $hasLunch);

            $totalLateHoursForDays += ($logData['late_minutes'] / 60);
            $totalUndertimeHoursForDays += ($logData['undertime_minutes'] / 60);
            $totalAbsentHoursForDays += $logData['absent_hours'];

            // Row hours (same rules as calculateLogHours / display row)
            if ($isLeaveRow) {
                $hours = getOfficialBaseHours($official, $hasLunch);
            } elseif ($tarfCredHours !== null) {
                $hours = $tarfCredHours;
            } elseif ($isHolidayRow && !$hasHolidayAttendance) {
                $hours = $fromDb
                    ? (dtr_holiday_is_half_day_all($log) ? dtr_half_day_holiday_total_hours_display_all($log, $official, $hasLunch) : getOfficialBaseHours($official, $hasLunch))
                    : 0;
            } else {
                $hours = calculateHours($log['time_in'], $log['lunch_out'], $log['lunch_in'], $log['time_out'], true, $hasLunch);
            }

            $otMin = 0;
            if (isTimeLogged($log['ot_in']) && isTimeLogged($log['ot_out'])) {
                $oi = parseTimeToMinutes($log['ot_in']);
                $oo = parseTimeToMinutes($log['ot_out']);
                if ($oi !== null && $oo !== null && $oo > $oi) {
                    $otMin = $oo - $oi;
                }
            }
            if ($hours !== null && $tarfCredHours === null && $logData['has_official_time'] && $otMin === 0) {
                $officialBase = getOfficialBaseHours($official, $hasLunch);
                if ($officialBase !== null && $hours > $officialBase) {
                    $hours = $officialBase;
                }
            }

            $totalHours += ($hours !== null ? $hours : 0);

            if ($isComplete || $isHolidayRow) {
                $completeDays++;
            } else {
                $incompleteDays++;
            }

            if ($log['total_ot']) {
                $otParts = explode(':', $log['total_ot']);
                if (count($otParts) >= 2) {
                    $totalOT += floatval($otParts[0]) + (floatval($otParts[1]) / 60);
                }
            }
        }
        ?>
        <div class="employee-page">
            <div class="header">
                <h1>DAILY TIME RECORD (DTR)</h1>
                <h2>WESTERN PHILIPPINES UNIVERSITY</h2>
            </div>
            
            <div class="employee-info">
                <table>
                    <tr>
                        <td>Safe Employee ID:</td>
                        <td><?php echo htmlspecialchars($employee['employee_id']); ?></td>
                    </tr>
                    <tr>
                        <td>Name:</td>
                        <td><?php echo htmlspecialchars($employee['first_name'] . ' ' . $employee['last_name']); ?></td>
                    </tr>
                    <tr>
                        <td>Position:</td>
                        <td><?php echo htmlspecialchars($employee['position'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td>Department:</td>
                        <td><?php echo htmlspecialchars($employee['department'] ?? 'N/A'); ?></td>
                    </tr>
                    <tr>
                        <td>Period:</td>
                        <td><?php echo htmlspecialchars($dateRangeText); ?></td>
                    </tr>
                </table>
            </div>
            
            <table class="dtr-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date</th>
                        <th>Day</th>
                        <th>Time In</th>
                        <th>Lunch Out</th>
                        <th>Lunch In</th>
                        <th>Time Out</th>
                        <th>Hours</th>
                        <th>Hours (Days)</th>
                        <th>Tardiness (Hrs, Days)</th>
                        <th>Undertime (Hrs, Days)</th>
                        <th>Absent (Hrs, Days)</th>
                        <th>TOTAL OT</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="14" style="text-align: center; padding: 20px;">
                                No attendance records found for the selected period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php 
                        $counter = 1;
                        foreach ($logs as $log): 
                            $res = getOfficialTimesForDate($log['log_date'], $official_times_list, $default_official_times);
                            $official = $res['official'];
                            $fromDb = $res['from_db'];
                            $hasLunch = $res['has_lunch'];

                            $r = $log['remarks'] ?? '';
                            $isHolidayRow = !empty($log['holiday_id']) || !empty($log['holiday_title'])
                                || (strpos($r, 'Holiday:') === 0 || strpos($r, 'Holiday (Half-day):') === 0
                                    || strpos($r, 'Holiday (Half-day AM):') === 0 || strpos($r, 'Holiday (Half-day PM):') === 0);
                            $hasHolidayAttendance = staff_dtr_row_has_real_holiday_attendance($log, $isHolidayRow);
                            $isLeaveRow = isLeaveDtrRow(
                                $log['time_in'] ?? null,
                                $log['lunch_out'] ?? null,
                                $log['lunch_in'] ?? null,
                                $log['time_out'] ?? null,
                                $log['remarks'] ?? null
                            );
                            $tarfCredHours = null;
                            if (preg_match('/TARF_HOURS_CREDIT:([\d.]+)/', (string) ($log['remarks'] ?? ''), $tx2)) {
                                $tarfCredHours = (float) $tx2[1];
                            }
                            $isComplete = isEntryComplete(
                                $log['time_in'],
                                $log['lunch_out'],
                                $log['lunch_in'],
                                $log['time_out'],
                                $log['ot_in'],
                                $log['ot_out'],
                                $log['remarks'] ?? null,
                                $hasLunch
                            );

                            if ($isLeaveRow) {
                                $hours = getOfficialBaseHours($official, $hasLunch);
                            } elseif ($tarfCredHours !== null) {
                                $hours = $tarfCredHours;
                            } elseif ($isHolidayRow && !$hasHolidayAttendance) {
                                $hours = $fromDb
                                    ? (dtr_holiday_is_half_day_all($log) ? dtr_half_day_holiday_total_hours_display_all($log, $official, $hasLunch) : getOfficialBaseHours($official, $hasLunch))
                                    : 0;
                            } else {
                                $hours = calculateHours($log['time_in'], $log['lunch_out'], $log['lunch_in'], $log['time_out'], true, $hasLunch);
                            }

                            $otMinRow = 0;
                            if (isTimeLogged($log['ot_in']) && isTimeLogged($log['ot_out'])) {
                                $oi = parseTimeToMinutes($log['ot_in']);
                                $oo = parseTimeToMinutes($log['ot_out']);
                                if ($oi !== null && $oo !== null && $oo > $oi) {
                                    $otMinRow = $oo - $oi;
                                }
                            }
                            $logData = calculateLogData($log, $official, $fromDb, $hasLunch);
                            if ($hours !== null && $tarfCredHours === null && $logData['has_official_time'] && $otMinRow === 0) {
                                $officialBase = getOfficialBaseHours($official, $hasLunch);
                                if ($officialBase !== null && $hours > $officialBase) {
                                    $hours = $officialBase;
                                }
                            }

                            $hourDay = decimalHoursToDayFraction($hours !== null ? $hours : 0);

                            $lateHoursForDay = $logData['late_minutes'] / 60;
                            $undertimeHoursForDay = $logData['undertime_minutes'] / 60;
                            $tardinessDays = decimalHoursToDayFraction($lateHoursForDay);
                            $undertimeDays = decimalHoursToDayFraction($undertimeHoursForDay);
                            $absentDays = decimalHoursToDayFraction($logData['absent_hours']);
                            
                            // Format TOTAL OT
                            $totalOtFormat = '00:00:00';
                            if ($log['total_ot']) {
                                $totalOtFormat = $log['total_ot'];
                            } else if ($log['ot_in'] && $log['ot_out']) {
                                $otInMinutes = parseTimeToMinutes($log['ot_in']);
                                $otOutMinutes = parseTimeToMinutes($log['ot_out']);
                                if ($otInMinutes !== null && $otOutMinutes !== null && $otOutMinutes > $otInMinutes) {
                                    $totalOtFormat = minutesToTimeFormat($otOutMinutes - $otInMinutes);
                                }
                            }
                            
                            // Format status
                            $statusBadge = '';
                            if ($logData['status'] === 'LEAVE') {
                                $statusBadge = '<span style="background-color: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">LEAVE</span>';
                            } else if ($logData['status'] === 'TARF' || dtr_row_is_tarf_mirror_log($log)) {
                                $statusBadge = '<span style="background-color: #0dcaf0; color: #000; padding: 2px 6px; border-radius: 3px; font-size: 10px;">TARF</span>';
                            } else if ($logData['status'] === 'Holiday') {
                                $statusBadge = '<span style="background-color: #6c757d; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">Holiday</span>';
                            } else if ($logData['status'] === 'Complete') {
                                $statusBadge = '<span style="background-color: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">Complete</span>';
                            } else if ($logData['status'] === 'Late') {
                                $statusBadge = '<span style="background-color: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">Late</span>';
                            } else if ($logData['status'] === 'Undertime') {
                                $statusBadge = '<span style="background-color: #ffc107; color: black; padding: 2px 6px; border-radius: 3px; font-size: 10px;">Undertime</span>';
                            } else if ($logData['status'] === 'Late & Undertime') {
                                $statusBadge = '<span style="background-color: #ffc107; color: black; padding: 2px 6px; border-radius: 3px; font-size: 10px;">Late & Undertime</span>';
                            } else if ($logData['status'] === 'Overtime') {
                                $statusBadge = '<span style="background-color: #28a745; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">Overtime</span>';
                            } else if ($logData['status'] === 'Absent') {
                                $statusBadge = '<span style="background-color: #dc3545; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">Absent</span>';
                            } else if ($logData['status'] === 'Incomplete') {
                                $statusBadge = '<span style="background-color: #6c757d; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">Incomplete</span>';
                            } else {
                                $statusBadge = '<span style="background-color: #6c757d; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px;">No official time yet</span>';
                            }
                        ?>
                            <?php
                            $isHoliday = !empty($log['holiday_id']) || !empty($log['holiday_title'])
                                || (strpos($log['remarks'] ?? '', 'Holiday:') === 0 || strpos($log['remarks'] ?? '', 'Holiday (Half-day):') === 0
                                    || strpos($log['remarks'] ?? '', 'Holiday (Half-day AM):') === 0 || strpos($log['remarks'] ?? '', 'Holiday (Half-day PM):') === 0);
                            $hasHolidayAttendance = staff_dtr_row_has_real_holiday_attendance($log, $isHoliday);
                            $hdRow = $isHoliday && !$hasHolidayAttendance && dtr_holiday_is_half_day_all($log);
                            $hpRow = dtr_holiday_half_period_all($log);
                            $rowStyle = '';
                            if ($hasHolidayAttendance) {
                                $rowStyle = 'background-color: #f8d7da;';
                            } elseif ($isHoliday) {
                                $rowStyle = dtr_holiday_is_half_day_all($log) ? 'background-color: #fff3e0;' : 'background-color: #ffdddd;';
                            } elseif (!$isComplete) {
                                $rowStyle = 'background-color: #ffe6e6;';
                            }
                            ?>
                            <tr <?php echo $rowStyle ? 'style="' . $rowStyle . '"' : ''; ?>>
                                <td><?php echo $counter++; ?></td>
                                <td><?php echo date('M d, Y', strtotime($log['log_date'])); ?></td>
                                <td><?php echo date('D', strtotime($log['log_date'])); ?></td>
                                <td><?php
                                    if ($isLeaveRow) {
                                        echo 'LEAVE';
                                    } elseif ($tarfCredHours !== null || dtr_row_is_tarf_mirror_log($log)) {
                                        echo 'TARF';
                                    } elseif ($isHoliday && !$hasHolidayAttendance) {
                                        if ($hdRow && $hpRow === 'morning') {
                                            echo htmlspecialchars('HOLIDAY');
                                        } elseif ($isHoliday && !$hasHolidayAttendance && !dtr_holiday_is_half_day_all($log)) {
                                            echo htmlspecialchars('Holiday');
                                        } elseif (isTimeLogged($log['time_in'] ?? null)) {
                                            echo formatTime($log['time_in']);
                                        } else {
                                            echo '-';
                                        }
                                    } else {
                                        echo formatTime($log['time_in']);
                                    }
                                ?></td>
                                <td><?php
                                    if ($isLeaveRow) {
                                        echo 'LEAVE';
                                    } elseif ($tarfCredHours !== null || dtr_row_is_tarf_mirror_log($log)) {
                                        echo 'TARF';
                                    } elseif ($isHoliday && !$hasHolidayAttendance) {
                                        if ($hdRow && $hpRow === 'morning') {
                                            echo htmlspecialchars('HOLIDAY');
                                        } elseif ($isHoliday && !$hasHolidayAttendance && !dtr_holiday_is_half_day_all($log)) {
                                            echo htmlspecialchars('Holiday');
                                        } elseif (isTimeLogged($log['lunch_out'] ?? null)) {
                                            echo formatTime($log['lunch_out']);
                                        } else {
                                            echo '-';
                                        }
                                    } else {
                                        echo formatTime($log['lunch_out']);
                                    }
                                ?></td>
                                <td><?php
                                    if ($isLeaveRow) {
                                        echo 'LEAVE';
                                    } elseif ($tarfCredHours !== null || dtr_row_is_tarf_mirror_log($log)) {
                                        echo 'TARF';
                                    } elseif ($isHoliday && !$hasHolidayAttendance) {
                                        if ($hdRow && $hpRow === 'afternoon') {
                                            echo htmlspecialchars('HOLIDAY');
                                        } elseif ($isHoliday && !$hasHolidayAttendance && !dtr_holiday_is_half_day_all($log)) {
                                            echo htmlspecialchars('Holiday');
                                        } elseif (isTimeLogged($log['lunch_in'] ?? null)) {
                                            echo formatTime($log['lunch_in']);
                                        } else {
                                            echo '-';
                                        }
                                    } else {
                                        echo formatTime($log['lunch_in']);
                                    }
                                ?></td>
                                <td><?php
                                    if ($isLeaveRow) {
                                        echo 'LEAVE';
                                    } elseif ($tarfCredHours !== null || dtr_row_is_tarf_mirror_log($log)) {
                                        echo 'TARF';
                                    } elseif ($isHoliday && !$hasHolidayAttendance) {
                                        if ($hdRow && $hpRow === 'afternoon') {
                                            echo htmlspecialchars('HOLIDAY');
                                        } elseif ($isHoliday && !$hasHolidayAttendance && !dtr_holiday_is_half_day_all($log)) {
                                            echo htmlspecialchars('Holiday');
                                        } elseif (isTimeLogged($log['time_out'] ?? null)) {
                                            echo formatTime($log['time_out']);
                                        } else {
                                            echo '-';
                                        }
                                    } else {
                                        echo formatTime($log['time_out']);
                                    }
                                ?></td>
                                <td><?php 
                                    if ($hours !== null) {
                                        echo number_format($hours, 2);
                                    } else {
                                        echo '<span style="color: red; font-weight: bold;">-</span>';
                                    }
                                ?></td>
                                <td><?php 
                                    // Always show hours (days), even for incomplete entries
                                    echo number_format($hourDay, 3);
                                ?></td>
                                <td><?php 
                                    echo ($logData['has_official_time'] && $lateHoursForDay > 0)
                                        ? number_format($lateHoursForDay, 2) . ' h / ' . number_format($tardinessDays, 3) . ' d'
                                        : '-';
                                ?></td>
                                <td><?php 
                                    echo ($logData['has_official_time'] && $undertimeHoursForDay > 0)
                                        ? number_format($undertimeHoursForDay, 2) . ' h / ' . number_format($undertimeDays, 3) . ' d'
                                        : '-';
                                ?></td>
                                <td><?php 
                                    echo ($logData['has_official_time'] && $logData['absent_hours'] > 0)
                                        ? number_format($logData['absent_hours'], 2) . ' h / ' . number_format($absentDays, 3) . ' d'
                                        : '-';
                                ?></td>
                                <td><?php 
                                    if ($logData['has_official_time'] && $totalOtFormat !== '00:00:00') {
                                        echo $totalOtFormat;
                                    } else {
                                        echo '-';
                                    }
                                ?></td>
                                <td><?php echo $statusBadge; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php
            // All official schedules that overlap the printed window (not only the row active on the last day).
            $periodStart = !empty($date_from) ? $date_from : null;
            $periodEnd = !empty($date_to) ? $date_to : null;
            if (!empty($logs)) {
                $logDates = [];
                foreach ($logs as $lg) {
                    if (!empty($lg['log_date'])) {
                        $logDates[] = substr((string) $lg['log_date'], 0, 10);
                    }
                }
                if (!empty($logDates)) {
                    $minLog = min($logDates);
                    $maxLog = max($logDates);
                    if ($periodStart === null) {
                        $periodStart = $minLog;
                    }
                    if ($periodEnd === null) {
                        $periodEnd = $maxLog;
                    }
                }
            }
            if ($periodStart === null && $periodEnd === null) {
                $periodStart = $periodEnd = date('Y-m-d');
            } elseif ($periodStart === null) {
                $periodStart = $periodEnd;
            } elseif ($periodEnd === null) {
                $periodEnd = $periodStart;
            }

            $officialSummaryRows = [];
            foreach ($official_times_list as $ot) {
                $sd = $ot['start_date'] ?? '';
                if ($sd === '' || $sd === null) {
                    continue;
                }
                $ed = $ot['end_date'];
                if ($sd > $periodEnd) {
                    continue;
                }
                if ($ed !== null && $ed !== '' && $ed < $periodStart) {
                    continue;
                }
                $officialSummaryRows[] = $ot;
            }
            $weekdayOrder = ['Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3, 'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6, 'Sunday' => 7];
            usort($officialSummaryRows, function ($a, $b) use ($weekdayOrder) {
                $cmp = strcmp((string) ($a['start_date'] ?? ''), (string) ($b['start_date'] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }
                $wa = $a['weekday'] ?? null;
                $wb = $b['weekday'] ?? null;
                if (($wa === null || $wa === '') && ($wb === null || $wb === '')) {
                    return 0;
                }
                if ($wa === null || $wa === '') {
                    return -1;
                }
                if ($wb === null || $wb === '') {
                    return 1;
                }
                return ($weekdayOrder[$wa] ?? 99) <=> ($weekdayOrder[$wb] ?? 99);
            });

            if ($periodStart === $periodEnd) {
                $officialSummaryPeriodLabel = date('M j, Y', strtotime($periodStart));
            } else {
                $officialSummaryPeriodLabel = date('M j, Y', strtotime($periodStart)) . ' – ' . date('M j, Y', strtotime($periodEnd));
            }
            ?>
            <div class="summary">
                <table class="summary-layout">
                    <tr>
                        <td class="summary-col summary-computation">
                            <table>
                                <tr>
                                    <td>Total Hours Worked:</td>
                                    <td><?php echo number_format($totalHours, 2); ?> hours</td>
                                </tr>
                                <tr>
                                    <td>Total Overtime Hours:</td>
                                    <td><?php echo number_format($totalOT, 2); ?> hours</td>
                                </tr>
                                <tr>
                                    <td>Total Hours (Days):</td>
                                    <td><?php echo number_format(decimalHoursToDayFraction($totalHours), 3); ?> days</td>
                                </tr>
                                <tr>
                                    <td>Total Tardiness (Hrs, Days):</td>
                                    <td><?php echo number_format($totalLateHoursForDays, 2); ?> hours / <?php echo number_format(decimalHoursToDayFraction($totalLateHoursForDays), 3); ?> days</td>
                                </tr>
                                <tr>
                                    <td>Total Undertime (Hrs, Days):</td>
                                    <td><?php echo number_format($totalUndertimeHoursForDays, 2); ?> hours / <?php echo number_format(decimalHoursToDayFraction($totalUndertimeHoursForDays), 3); ?> days</td>
                                </tr>
                                <tr>
                                    <td>Total Absent (Hrs, Days):</td>
                                    <td><?php echo number_format($totalAbsentHoursForDays, 2); ?> hours / <?php echo number_format(decimalHoursToDayFraction($totalAbsentHoursForDays), 3); ?> days</td>
                                </tr>
                                <tr>
                                    <td>Complete Days (Based on Hours):</td>
                                    <td><?php echo number_format(decimalHoursToDayFraction($totalHours), 3); ?> days</td>
                                </tr>
                                <?php if ($incompleteDays > 0): ?>
                                <tr>
                                    <td>Incomplete Days:</td>
                                    <td style="color: red; font-weight: bold;"><?php echo $incompleteDays; ?> days</td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </td>
                        <td class="summary-col summary-official">
                            <div class="summary-official-title">Official time (report period: <?php echo htmlspecialchars($officialSummaryPeriodLabel); ?>)</div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Effective</th>
                                        <th>Time In</th>
                                        <th>Lunch Out</th>
                                        <th>Lunch In</th>
                                        <th>Time Out</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($officialSummaryRows)): ?>
                                        <tr>
                                            <td class="summary-official-day">All days</td>
                                            <td class="summary-official-effective">Default</td>
                                            <td><?php echo htmlspecialchars(formatTime($default_official_times['time_in'])); ?></td>
                                            <td><?php echo htmlspecialchars(formatTime($default_official_times['lunch_out'])); ?></td>
                                            <td><?php echo htmlspecialchars(formatTime($default_official_times['lunch_in'])); ?></td>
                                            <td><?php echo htmlspecialchars(formatTime($default_official_times['time_out'])); ?></td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($officialSummaryRows as $otRow): ?>
                                            <?php
                                            $effStart = $otRow['start_date'] ?? '';
                                            $effEnd = $otRow['end_date'];
                                            $effLabel = $effStart !== '' && $effStart !== null
                                                ? date('M j, Y', strtotime($effStart)) . ' – ' . (($effEnd === null || $effEnd === '') ? 'present' : date('M j, Y', strtotime($effEnd)))
                                                : '—';
                                            $dayLabel = (isset($otRow['weekday']) && $otRow['weekday'] !== '' && $otRow['weekday'] !== null)
                                                ? $otRow['weekday']
                                                : 'All days';
                                            $hasL = officialTimesRowHasLunch($otRow);
                                            ?>
                                            <tr>
                                                <td class="summary-official-day"><?php echo htmlspecialchars($dayLabel); ?></td>
                                                <td class="summary-official-effective"><?php echo htmlspecialchars($effLabel); ?></td>
                                                <td><?php echo htmlspecialchars(formatTime($otRow['time_in'] ?? null)); ?></td>
                                                <td><?php echo $hasL ? htmlspecialchars(formatTime($otRow['lunch_out'] ?? null)) : '—'; ?></td>
                                                <td><?php echo $hasL ? htmlspecialchars(formatTime($otRow['lunch_in'] ?? null)) : '—'; ?></td>
                                                <td><?php echo htmlspecialchars(formatTime($otRow['time_out'] ?? null)); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="footer">
                <p><strong>Note:</strong> This is a system-generated Daily Time Record. For any discrepancies, please contact the HR Department.</p>
                <p>Generated on: <?php echo date('F d, Y \a\t h:i A'); ?></p>
            </div>
        </div>
    <script>document.getElementById('progressCount').textContent = '<?php echo $empIndex; ?>';</script>
    <?php flush(); ?>
    <?php endforeach; ?>
    
    <script>
        var lp = document.getElementById('loadingProgress');
        if (lp) lp.style.display = 'none';
    </script>
    <script>
        // Auto-print when page loads (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>

