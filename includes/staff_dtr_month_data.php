<?php
/**
 * Shared month-scoped staff DTR data for HR (Employees DTR modal + bulk print).
 * Must be loaded with config/functions/database already available.
 */

if (!function_exists('staff_dtr_normalize_time_hms')) {
    function staff_dtr_normalize_time_hms($t) {
        if ($t === null || $t === '') {
            return '';
        }
        $t = trim((string) $t);
        if ($t === '00:00' || $t === '00:00:00') {
            return '00:00:00';
        }
        $parts = preg_split('/[:.]/', $t);
        if (count($parts) < 2) {
            return '';
        }
        $h = (int) $parts[0];
        $m = (int) $parts[1];
        $s = isset($parts[2]) ? (int) $parts[2] : 0;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }
}

if (!function_exists('staff_dtr_row_is_synthetic_holiday_times')) {
    function staff_dtr_row_is_synthetic_holiday_times(array $row) {
        return staff_dtr_normalize_time_hms($row['time_in'] ?? '') === '08:00:00'
            && staff_dtr_normalize_time_hms($row['lunch_out'] ?? '') === '12:00:00'
            && staff_dtr_normalize_time_hms($row['lunch_in'] ?? '') === '13:00:00'
            && staff_dtr_normalize_time_hms($row['time_out'] ?? '') === '17:00:00';
    }
}

if (!function_exists('staff_dtr_time_field_emptyish')) {
    function staff_dtr_time_field_emptyish(array $row, $field) {
        $v = staff_dtr_normalize_time_hms($row[$field] ?? '');
        return $v === '' || $v === '00:00:00';
    }
}

/**
 * True when times match the auto-generated "holiday credit" block (not real punches).
 */
if (!function_exists('staff_dtr_row_is_system_generated_holiday_log')) {
    /**
     * Detect rows auto-created by createHolidayAttendanceLogs.
     * The system stamps remarks with "Holiday: <title>" (or Half-day variant)
     * and sets holiday_id.  Times may use the employee's custom official
     * schedule — not just the default 08-12-13-17 — so we cannot rely on
     * a hard-coded time comparison for non-default schedules.
     */
    function staff_dtr_row_is_system_generated_holiday_log(array $row) {
        if (staff_dtr_row_is_synthetic_holiday_times($row)) {
            return true;
        }
        $remarks = trim($row['remarks'] ?? '');
        $isAutoHolidayRemarks = (
            strpos($remarks, 'Holiday:') === 0
            || strpos($remarks, 'Holiday (Half-day') === 0
        );
        if ($isAutoHolidayRemarks && !empty($row['holiday_id'])) {
            return true;
        }
        if (strpos($remarks, 'Holiday:') !== 0) {
            return false;
        }
        $tin = staff_dtr_normalize_time_hms($row['time_in'] ?? '');
        $tout = staff_dtr_normalize_time_hms($row['time_out'] ?? '');
        if ($tin !== '08:00:00' || $tout !== '17:00:00') {
            return false;
        }
        $lo = staff_dtr_normalize_time_hms($row['lunch_out'] ?? '');
        $li = staff_dtr_normalize_time_hms($row['lunch_in'] ?? '');
        if ($lo === '12:00:00' && $li === '13:00:00') {
            return true;
        }
        if (staff_dtr_time_field_emptyish($row, 'lunch_out') && staff_dtr_time_field_emptyish($row, 'lunch_in')) {
            return true;
        }
        return false;
    }
}

/**
 * Build lookups for attendance rows covered by ANY approved TARF/NTARF pardon
 * (multiple requests per log/date: any approved counts; covers anchor log_id + covered dates JSON).
 *
 * @return array{by_date: array<string,true>, by_log_id: array<int,true>}
 */
if (!function_exists('staff_dtr_approved_tarf_ntarf_indexes_for_employee')) {
    function staff_dtr_approved_tarf_ntarf_indexes_for_employee(PDO $db, $employee_id) {
        $byDate = [];
        $byLogId = [];

        try {
            $chk = $db->query("SHOW COLUMNS FROM pardon_requests LIKE 'pardon_type'");
            if (!$chk || $chk->rowCount() < 1) {
                return ['by_date' => $byDate, 'by_log_id' => $byLogId];
            }
            $chkCd = $db->query("SHOW COLUMNS FROM pardon_requests LIKE 'pardon_covered_dates'");
            $hasCovered = $chkCd && $chkCd->rowCount() > 0;

            $sel = $hasCovered
                ? 'SELECT log_id, log_date, pardon_covered_dates FROM pardon_requests WHERE employee_id = ? AND status = ? AND pardon_type = ?'
                : 'SELECT log_id, log_date FROM pardon_requests WHERE employee_id = ? AND status = ? AND pardon_type = ?';
            $stmt = $db->prepare($sel);
            $stmt->execute([(string) $employee_id, 'approved', 'tarf_ntarf']);
            $pushNorm = static function (&$map, $d) {
                if ($d === null || $d === '') {
                    return;
                }
                $ts = strtotime(trim((string) $d));
                if ($ts === false) {
                    return;
                }
                $norm = date('Y-m-d', $ts);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $norm)) {
                    $map[$norm] = true;
                }
            };
            while ($pr = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $lid = (int) ($pr['log_id'] ?? 0);
                if ($lid > 0) {
                    $byLogId[$lid] = true;
                }
                $pushNorm($byDate, $pr['log_date'] ?? null);
                if ($hasCovered && !empty($pr['pardon_covered_dates'])) {
                    $decoded = json_decode($pr['pardon_covered_dates'], true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $piece) {
                            $pushNorm($byDate, $piece);
                        }
                    }
                }
            }
        } catch (Exception $e) { /* schema / query variance */ }

        return ['by_date' => $byDate, 'by_log_id' => $byLogId];
    }
}

if (!function_exists('staff_dtr_log_matches_approved_tarf_ntarf')) {
    /**
     * True when this attendance log row is tied to ANY approved tarf_ntarf pardon by log id or log_date (incl. pardon_covered_dates).
     *
     * @param array{by_date?: array<string,bool>, by_log_id?: array<int,bool>} $approvedIndexes
     */
    function staff_dtr_log_matches_approved_tarf_ntarf(array $log, array $approvedIndexes) {
        $byDate = isset($approvedIndexes['by_date']) && is_array($approvedIndexes['by_date'])
            ? $approvedIndexes['by_date'] : [];
        $byLogId = isset($approvedIndexes['by_log_id']) && is_array($approvedIndexes['by_log_id'])
            ? $approvedIndexes['by_log_id'] : [];
        $logPk = (int) ($log['id'] ?? 0);
        if ($logPk > 0 && !empty($byLogId[$logPk])) {
            return true;
        }
        if (empty($log['log_date'])) {
            return false;
        }
        $logDateStr = date('Y-m-d', strtotime($log['log_date']));
        return $logDateStr !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDateStr) && !empty($byDate[$logDateStr]);
    }
}

/** Employee actually worked on a holiday (real punches, not system template). */
if (!function_exists('staff_dtr_row_has_real_holiday_attendance')) {
    function staff_dtr_row_has_real_holiday_attendance(array $row, $isHoliday) {
        if (!$isHoliday) {
            return false;
        }
        if (!staff_dtr_row_has_any_punch($row)) {
            return false;
        }
        return !staff_dtr_row_is_system_generated_holiday_log($row);
    }
}

if (!function_exists('staff_dtr_row_has_any_punch')) {
    function staff_dtr_row_has_any_punch(array $row) {
        foreach (['time_in', 'lunch_out', 'lunch_in', 'time_out'] as $f) {
            $v = $row[$f] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            $t = substr(trim((string) $v), 0, 5);
            if ($t !== '' && $t !== '00:00' && $t !== '0:00') {
                return true;
            }
        }
        return false;
    }
}

/**
 * @return array{logs: array, pardon_open_dates: array, official_regular: string, official_saturday: string, in_charge: string}
 */
function staff_dtr_fetch_month_bundle(PDO $db, $employee_id, $date_from, $date_to) {
    $ntarfApproved = staff_dtr_approved_tarf_ntarf_indexes_for_employee($db, $employee_id);

    $submittedDates = [];
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

    $hasPardonOpenTable = false;
    try {
        $tblCheck = $db->query("SHOW TABLES LIKE 'pardon_open'");
        $hasPardonOpenTable = $tblCheck && $tblCheck->rowCount() > 0;
    } catch (Exception $e) { /* ignore */ }

    $pardonOpenSub = $hasPardonOpenTable
        ? ", (SELECT 1 FROM pardon_open po WHERE po.employee_id = al.employee_id AND DATE(po.log_date) = DATE(al.log_date) LIMIT 1) as pardon_open_flag"
        : "";

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

    $query = "SELECT al.id, al.employee_id, al.log_date, al.time_in, al.lunch_out, al.lunch_in, al.time_out, al.ot_in, al.ot_out, al.total_ot, al.remarks, al.tarf_id,
                         COALESCE(al.holiday_id, (SELECT h2.id FROM holidays h2 WHERE h2.date = DATE(al.log_date) LIMIT 1)) as holiday_id,
                         al.created_at,
                         (SELECT pr.status FROM pardon_requests pr 
                          WHERE pr.log_id = al.id AND pr.employee_id = al.employee_id 
                          ORDER BY pr.created_at DESC LIMIT 1) as pardon_status
                         $pardonOpenSub,
                         (SELECT t.title FROM tarf t WHERE t.id = al.tarf_id LIMIT 1) as tarf_title,
                         $holidayTitleSelect as holiday_title,
                         $holidayHalfDaySelect as holiday_is_half_day,
                         $holidayHalfPeriodSelect as holiday_half_day_period
                  FROM attendance_logs al
                  WHERE al.employee_id = ?
                  AND al.log_date >= ? AND al.log_date <= ?
                  ORDER BY al.log_date ASC";

    $stmt = $db->prepare($query);
    $stmt->execute([$employee_id, $date_from, $date_to]);
    $logs = [];

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $logDateStr = $row['log_date'] ? date('Y-m-d', strtotime($row['log_date'])) : null;
        $rowIsHoliday = (!empty($row['holiday_id']) || !empty($row['holiday_title']) || (!empty($row['remarks']) && strpos($row['remarks'] ?? '', 'Holiday:') === 0));
        if (!$rowIsHoliday && !empty($submittedDates) && $logDateStr && !in_array($logDateStr, $submittedDates, true)) {
            continue;
        }
        $isLeave = (strtoupper(trim($row['remarks'] ?? '')) === 'LEAVE');
        $isHoliday = $rowIsHoliday;
        $hasHolidayAttendance = staff_dtr_row_has_real_holiday_attendance($row, $isHoliday);
        $isHalfDayHoliday = $isHoliday && !$hasHolidayAttendance && !empty($row['holiday_is_half_day']);
        $halfDayPeriod = ($row['holiday_half_day_period'] ?? 'morning') === 'afternoon' ? 'afternoon' : 'morning';
        $rowRemarksStr = (string) ($row['remarks'] ?? '');
        $approvedTarfNtarfPardon = staff_dtr_log_matches_approved_tarf_ntarf($row, $ntarfApproved);
        $isTarfRow = ((!empty($row['tarf_id']) && strpos($rowRemarksStr, 'TARF:') === 0)
            || strpos($rowRemarksStr, 'TARF_HOURS_CREDIT:') !== false
            || strtoupper(trim($rowRemarksStr)) === 'TARF'
            || $approvedTarfNtarfPardon);
        if ($isLeave) {
            $timeInVal = $timeLOVal = $timeLIVal = $timeOutVal = 'LEAVE';
        } elseif ($isTarfRow) {
            $timeInVal = $timeLOVal = $timeLIVal = $timeOutVal = 'TARF';
        } elseif ($isHalfDayHoliday) {
            if ($halfDayPeriod === 'afternoon') {
                $timeInVal = $row['time_in'] ? substr($row['time_in'], 0, 5) : null;
                $timeLOVal = $row['lunch_out'] ? substr($row['lunch_out'], 0, 5) : null;
                $timeLIVal = 'HOLIDAY';
                $timeOutVal = 'HOLIDAY';
            } else {
                $timeInVal = 'HOLIDAY';
                $timeLOVal = 'HOLIDAY';
                $timeLIVal = $row['lunch_in'] ? substr($row['lunch_in'], 0, 5) : null;
                $timeOutVal = $row['time_out'] ? substr($row['time_out'], 0, 5) : null;
            }
        } elseif ($isHoliday && !$hasHolidayAttendance) {
            $timeInVal = $timeLOVal = $timeLIVal = $timeOutVal = 'HOLIDAY';
        } else {
            $timeInVal = $row['time_in'] ? substr($row['time_in'], 0, 5) : null;
            $timeLOVal = $row['lunch_out'] ? substr($row['lunch_out'], 0, 5) : null;
            $timeLIVal = $row['lunch_in'] ? substr($row['lunch_in'], 0, 5) : null;
            $timeOutVal = $row['time_out'] ? substr($row['time_out'], 0, 5) : null;
        }
        $logs[] = [
            'id' => $row['id'],
            'employee_id' => $row['employee_id'],
            'log_date' => $row['log_date'] ? date('Y-m-d', strtotime($row['log_date'])) : null,
            'time_in' => $timeInVal,
            'lunch_out' => $timeLOVal,
            'lunch_in' => $timeLIVal,
            'time_out' => $timeOutVal,
            'ot_in' => $row['ot_in'] ? substr($row['ot_in'], 0, 5) : null,
            'ot_out' => $row['ot_out'] ? substr($row['ot_out'], 0, 5) : null,
            'total_ot' => $row['total_ot'] ? substr($row['total_ot'], 0, 8) : null,
            'remarks' => $row['remarks'] ?? null,
            'tarf_id' => $row['tarf_id'] ?? null,
            'tarf_title' => $row['tarf_title'] ?? null,
            'holiday_id' => $row['holiday_id'] ?? null,
            'holiday_title' => $row['holiday_title'] ?? null,
            'holiday_is_half_day' => !empty($row['holiday_is_half_day']) ? 1 : 0,
            'holiday_half_day_period' => $row['holiday_half_day_period'] ?? null,
            'is_tarf' => $isTarfRow,
            'is_holiday' => (!empty($row['holiday_id']) || !empty($row['holiday_title']) || (!empty($row['remarks']) && strpos($row['remarks'], 'Holiday:') === 0)),
            'has_holiday_attendance' => $hasHolidayAttendance,
            'created_at' => $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : null,
            'pardon_status' => $row['pardon_status'],
            'pardon_open' => $hasPardonOpenTable && !empty($row['pardon_open_flag'])
        ];
    }

    $logDatesKeyed = [];
    foreach ($logs as $le) {
        if (!empty($le['log_date'])) {
            $logDatesKeyed[$le['log_date']] = true;
        }
    }
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

    $start = new DateTime($date_from);
    $end = new DateTime($date_to);
    $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);
    foreach ($period as $dt) {
        $d = $dt->format('Y-m-d');
        if (isset($logDatesKeyed[$d]) || !isset($holidayDatesWithInfo[$d])) {
            continue;
        }
        $holInfo = $holidayDatesWithInfo[$d];
        $title = $holInfo['title'] ?? 'Holiday';
        $isHd = !empty($holInfo['is_half_day']);
        $hp = ($isHd && (($holInfo['half_day_period'] ?? 'morning') === 'afternoon')) ? 'afternoon' : 'morning';
        if ($isHd && $hp === 'afternoon') {
            $synTI = null; $synLO = null; $synLI = 'HOLIDAY'; $synTO = 'HOLIDAY';
        } elseif ($isHd) {
            $synTI = 'HOLIDAY'; $synLO = 'HOLIDAY'; $synLI = null; $synTO = null;
        } else {
            $synTI = 'HOLIDAY'; $synLO = 'HOLIDAY'; $synLI = 'HOLIDAY'; $synTO = 'HOLIDAY';
        }
        $logs[] = [
            'id' => null,
            'employee_id' => $employee_id,
            'log_date' => $d,
            'time_in' => $synTI,
            'lunch_out' => $synLO,
            'lunch_in' => $synLI,
            'time_out' => $synTO,
            'ot_in' => null,
            'ot_out' => null,
            'total_ot' => null,
            'remarks' => 'Holiday: ' . $title,
            'tarf_id' => null,
            'tarf_title' => null,
            'holiday_id' => $holInfo['id'] ?? null,
            'holiday_title' => $title,
            'holiday_is_half_day' => $isHd ? 1 : 0,
            'holiday_half_day_period' => $isHd ? $hp : null,
            'is_tarf' => false,
            'is_holiday' => true,
            'has_holiday_attendance' => false,
            'created_at' => null,
            'pardon_status' => null,
            'pardon_open' => false,
        ];
        $logDatesKeyed[$d] = true;
    }
    usort($logs, function ($a, $b) {
        return strcmp($a['log_date'] ?? '', $b['log_date'] ?? '');
    });

    $pardon_open_dates = [];
    if ($hasPardonOpenTable) {
        $stmtPo = $db->prepare("SELECT log_date FROM pardon_open WHERE employee_id = ?");
        $stmtPo->execute([$employee_id]);
        $pardon_open_dates = $stmtPo->fetchAll(PDO::FETCH_COLUMN);
    }

    $official_regular = '08:00-12:00, 13:00-17:00';
    $official_saturday = '—';
    $fmt = function ($t) { return $t ? substr($t, 0, 5) : ''; };
    $stmtOT = $db->prepare("SELECT weekday, time_in, lunch_out, lunch_in, time_out FROM employee_official_times WHERE employee_id = ? AND (weekday = 'Monday' OR weekday = 'Saturday') ORDER BY start_date DESC");
    $stmtOT->execute([$employee_id]);
    $official_regular_set = false;
    $official_saturday_set = false;
    while ($ot = $stmtOT->fetch(PDO::FETCH_ASSOC)) {
        $w = trim($ot['weekday'] ?? '');
        $seg = ($fmt($ot['time_in']) && $fmt($ot['lunch_out']) ? $fmt($ot['time_in']) . '-' . $fmt($ot['lunch_out']) : '') . ($fmt($ot['lunch_in']) && $fmt($ot['time_out']) ? ', ' . $fmt($ot['lunch_in']) . '-' . $fmt($ot['time_out']) : '');
        if ($w === 'Monday' && $seg && !$official_regular_set) {
            $official_regular = $seg;
            $official_regular_set = true;
        }
        if ($w === 'Saturday' && $seg && !$official_saturday_set) {
            $official_saturday = $seg;
            $official_saturday_set = true;
        }
    }

    // Build official_by_date for per-date undertime calculation (matches get_employee_dtr_logs_api)
    $official_by_date = [];
    $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $defaultOfficial = ['lunch_out' => 12 * 60, 'lunch_in' => 13 * 60, 'time_out' => 17 * 60];
    $parseMin = function ($t) {
        if (!$t) return null;
        $p = explode(':', $t);
        return count($p) >= 2 ? ((int)$p[0] * 60) + (int)$p[1] : null;
    };
    $stmtOTAll = $db->prepare("SELECT weekday, start_date, end_date, time_in, lunch_out, lunch_in, time_out FROM employee_official_times WHERE employee_id = ? ORDER BY start_date DESC");
    $stmtOTAll->execute([$employee_id]);
    $official_times_list = $stmtOTAll->fetchAll(PDO::FETCH_ASSOC);
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

    $in_charge = function_exists('getPardonOpenerDisplayNameForEmployee')
        ? getPardonOpenerDisplayNameForEmployee($employee_id, $db)
        : 'HR';

    return [
        'logs' => $logs,
        'pardon_open_dates' => $pardon_open_dates,
        'official_regular' => $official_regular,
        'official_saturday' => $official_saturday,
        'official_by_date' => $official_by_date,
        'in_charge' => $in_charge,
    ];
}
