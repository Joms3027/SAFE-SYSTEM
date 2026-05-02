<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/session_optimization.php';
require_once '../includes/staff_dtr_month_data.php';

requireAdmin();

header('Content-Type: application/json');

// Close session early for read-only API call to prevent blocking
// This allows multiple users to fetch logs simultaneously without session conflicts
closeSessionEarly(true);

$employee_id = $_GET['employee_id'] ?? '';
$simple = isset($_GET['simple']) && $_GET['simple'] == '1';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

if (empty($employee_id)) {
    echo json_encode(['success' => false, 'logs' => []]);
    exit;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // SIMPLE MODE - for employee_logs.php modal (just list all personal logs from attendance_logs)
    if ($simple) {
        // First, ensure holiday_id column exists in attendance_logs
        try {
            $db->exec("ALTER TABLE attendance_logs ADD COLUMN holiday_id INT DEFAULT NULL");
        } catch (Exception $e) {
            // Column might already exist, ignore
        }
        
        // Ensure ot_in, ot_out, and total_ot columns exist in attendance_logs
        try {
            $db->exec("ALTER TABLE attendance_logs ADD COLUMN ot_in TIME DEFAULT NULL");
        } catch (Exception $e) {
            // Column might already exist, ignore
        }
        
        try {
            $db->exec("ALTER TABLE attendance_logs ADD COLUMN ot_out TIME DEFAULT NULL");
        } catch (Exception $e) {
            // Column might already exist, ignore
        }
        
        try {
            $db->exec("ALTER TABLE attendance_logs ADD COLUMN total_ot TIME DEFAULT NULL");
        } catch (Exception $e) {
            // Column might already exist, ignore
        }
        
        // Normalize any DB date string to Y-m-d (fixes key mismatches with existingByDate / in_array)
        $normalizeHolidayDate = function ($d) {
            if ($d === null || $d === '') {
                return '';
            }
            $ts = strtotime((string) $d);
            return $ts ? date('Y-m-d', $ts) : '';
        };
        // Holidays on/after this date participate in DTR holiday display and auto-logs
        $holidayPolicyStartDate = '2026-03-09';

        // Check for holidays that don't have logs yet and create them automatically
        try {
            // Get all holidays that don't have attendance logs for this employee
            // Include holidays starting March 09, 2026 and in requested date range
            $holidayMinDate = $holidayPolicyStartDate;
            $holidayDateCondition = "h.date >= ? AND h.date <= CURDATE()";
            $holidayParams = [$holidayMinDate, $employee_id];
            $calRangeFrom = $holidayMinDate;
            $calUseCurdateEnd = true;
            if (!empty($date_from) && !empty($date_to)) {
                $rangeFrom = max($holidayMinDate, $date_from);
                $holidayDateCondition = "h.date >= ? AND h.date <= ?";
                $holidayParams = [$rangeFrom, $date_to, $employee_id];
                $calRangeFrom = $rangeFrom;
                $calUseCurdateEnd = false;
                $calRangeTo = $date_to;
            }
            $stmtHolidays = $db->prepare("
                SELECT h.id, h.title, h.date
                FROM holidays h
                WHERE " . $holidayDateCondition . "
                AND NOT EXISTS (
                    SELECT 1 FROM attendance_logs al 
                    WHERE al.employee_id = ? 
                    AND al.log_date = h.date 
                    AND (al.holiday_id = h.id OR al.remarks LIKE CONCAT('Holiday:', h.title))
                )
            ");
            $stmtHolidays->execute($holidayParams);
            $missingHolidays = $stmtHolidays->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($missingHolidays)) {
                $holidayDatesList = array_map($normalizeHolidayDate, array_column($missingHolidays, 'date'));
                $holidayDatesList = array_filter(array_unique($holidayDatesList));
                $placeholders = implode(',', array_fill(0, count($holidayDatesList), '?'));
                $stmtExisting = $db->prepare("SELECT id, log_date, time_in FROM attendance_logs WHERE employee_id = ? AND log_date IN ($placeholders)");
                $stmtExisting->execute(array_merge([$employee_id], $holidayDatesList));
                $existingByDate = [];
                foreach ($stmtExisting->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $existingByDate[$normalizeHolidayDate($row['log_date'])] = $row;
                }

                $stmtUpdate = $db->prepare("
                    UPDATE attendance_logs
                    SET time_in = COALESCE(NULLIF(time_in, ''), '08:00:00'),
                        lunch_out = COALESCE(NULLIF(lunch_out, ''), '12:00:00'),
                        lunch_in = COALESCE(NULLIF(lunch_in, ''), '13:00:00'),
                        time_out = COALESCE(NULLIF(time_out, ''), '17:00:00'),
                        remarks = ?,
                        holiday_id = ?
                    WHERE id = ?
                ");
                $stmtUpdateTagOnly = $db->prepare("
                    UPDATE attendance_logs SET holiday_id = ?, remarks = CONCAT(?, COALESCE(remarks, '')) WHERE id = ?
                ");
                $stmtInsert = $db->prepare("
                    INSERT INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks, holiday_id, created_at)
                    VALUES (?, ?, '08:00:00', '12:00:00', '13:00:00', '17:00:00', ?, ?, NOW())
                ");

                foreach ($missingHolidays as $holiday) {
                    $holidayDate = $normalizeHolidayDate($holiday['date']);
                    if ($holidayDate === '') {
                        continue;
                    }
                    $holidayId = $holiday['id'];
                    $remarks = "Holiday: " . $holiday['title'];

                    if (isset($existingByDate[$holidayDate])) {
                        $existing = $existingByDate[$holidayDate];
                        $ti = substr($existing['time_in'] ?? '', 0, 5);
                        $hasActualAttendance = $ti && $ti !== '08:00';
                        if ($hasActualAttendance) {
                            $stmtUpdateTagOnly->execute([$holidayId, $remarks . ' ', $existing['id']]);
                        } else {
                            $stmtUpdate->execute([$remarks, $holidayId, $existing['id']]);
                        }
                    } else {
                        $stmtInsert->execute([$employee_id, $holidayDate, $remarks, $holidayId]);
                    }
                }
            }

            // Calendar events marked event_type = 'holiday' are NOT always copied to `holidays` table.
            // Treat them the same for attendance / absent exclusion.
            $tblCal = $db->query("SHOW TABLES LIKE 'calendar_events'");
            if ($tblCal && $tblCal->rowCount() > 0) {
                $ceArchivedCal = $db->query("SHOW COLUMNS FROM calendar_events LIKE 'is_archived'");
                $calArchivedSql = ($ceArchivedCal && $ceArchivedCal->rowCount() > 0)
                    ? 'AND COALESCE(ce.is_archived, 0) = 0'
                    : '';
                if ($calUseCurdateEnd) {
                    $calCondition = "ce.event_date >= ? AND ce.event_date <= CURDATE()";
                    $calParams = [$calRangeFrom, $employee_id];
                } else {
                    $calCondition = "ce.event_date >= ? AND ce.event_date <= ?";
                    $calParams = [$calRangeFrom, $calRangeTo, $employee_id];
                }
                $stmtCalHol = $db->prepare("
                    SELECT ce.id, ce.title, ce.event_date AS date
                    FROM calendar_events ce
                    WHERE ce.event_type = 'holiday'
                    " . $calArchivedSql . "
                    AND " . $calCondition . "
                    AND NOT EXISTS (
                        SELECT 1 FROM attendance_logs al
                        WHERE al.employee_id = ?
                        AND al.log_date = ce.event_date
                        AND (al.holiday_id IS NOT NULL OR TRIM(COALESCE(al.remarks, '')) LIKE 'Holiday:%')
                    )
                ");
                $stmtCalHol->execute($calParams);
                $missingCalHolidays = $stmtCalHol->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($missingCalHolidays)) {
                    $calDatesList = array_map($normalizeHolidayDate, array_column($missingCalHolidays, 'date'));
                    $calDatesList = array_filter(array_unique($calDatesList));
                    $placeholdersCal = implode(',', array_fill(0, count($calDatesList), '?'));
                    $stmtExistingCal = $db->prepare("SELECT id, log_date, time_in FROM attendance_logs WHERE employee_id = ? AND log_date IN ($placeholdersCal)");
                    $stmtExistingCal->execute(array_merge([$employee_id], $calDatesList));
                    $existingCalByDate = [];
                    foreach ($stmtExistingCal->fetchAll(PDO::FETCH_ASSOC) as $row) {
                        $existingCalByDate[$normalizeHolidayDate($row['log_date'])] = $row;
                    }

                    $stmtUpdateCal = $db->prepare("
                        UPDATE attendance_logs
                        SET time_in = COALESCE(NULLIF(time_in, ''), '08:00:00'),
                            lunch_out = COALESCE(NULLIF(lunch_out, ''), '12:00:00'),
                            lunch_in = COALESCE(NULLIF(lunch_in, ''), '13:00:00'),
                            time_out = COALESCE(NULLIF(time_out, ''), '17:00:00'),
                            remarks = ?
                        WHERE id = ?
                    ");
                    $stmtUpdateTagCal = $db->prepare("
                        UPDATE attendance_logs SET remarks = CONCAT(?, COALESCE(remarks, '')) WHERE id = ?
                    ");
                    $stmtInsertCal = $db->prepare("
                        INSERT INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks, holiday_id, created_at)
                        VALUES (?, ?, '08:00:00', '12:00:00', '13:00:00', '17:00:00', ?, NULL, NOW())
                    ");

                    foreach ($missingCalHolidays as $ch) {
                        $dCal = $normalizeHolidayDate($ch['date']);
                        if ($dCal === '') {
                            continue;
                        }
                        $remarksCal = 'Holiday: ' . ($ch['title'] ?? 'Holiday');
                        if (isset($existingCalByDate[$dCal])) {
                            $ex = $existingCalByDate[$dCal];
                            $ti = substr($ex['time_in'] ?? '', 0, 5);
                            $hasActual = $ti && $ti !== '08:00';
                            if ($hasActual) {
                                $stmtUpdateTagCal->execute([$remarksCal . ' ', $ex['id']]);
                            } else {
                                $stmtUpdateCal->execute([$remarksCal, $ex['id']]);
                            }
                        } else {
                            $stmtInsertCal->execute([$employee_id, $dCal, $remarksCal]);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error auto-creating holiday logs: " . $e->getMessage());
        }
        
        // Fetch personal attendance logs from attendance_logs table for this employee
        // Also fetch the latest pardon request status for each log (same as faculty API)
        // DTR policy: Admin only sees logs for dates the dean has verified (employee submits → dean verifies → then admin)
        // Super_admin (HR) sees all submitted dates so they can verify faculty with designation (staff/faculty with designation bypass dean)
        $submittedDates = [];
        $verifiedDates = [];
        $useVerifiedForAdmin = false;
        $isSuperAdmin = isSuperAdmin();
        $tableCheck = $db->query("SHOW TABLES LIKE 'dtr_daily_submissions'");
        if ($tableCheck && $tableCheck->rowCount() > 0) {
            $stmtUser = $db->prepare("SELECT user_id FROM faculty_profiles WHERE employee_id = ? LIMIT 1");
            $stmtUser->execute([$employee_id]);
            $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
            if ($userRow) {
                $stmtSub = $db->prepare("SELECT log_date FROM dtr_daily_submissions WHERE user_id = ?");
                $stmtSub->execute([$userRow['user_id']]);
                $submittedDates = $stmtSub->fetchAll(PDO::FETCH_COLUMN);
                $colCheck = $db->query("SHOW COLUMNS FROM dtr_daily_submissions LIKE 'dean_verified_at'");
                if ($colCheck && $colCheck->rowCount() > 0) {
                    $useVerifiedForAdmin = true;
                    $stmtVer = $db->prepare("SELECT log_date FROM dtr_daily_submissions WHERE user_id = ? AND dean_verified_at IS NOT NULL");
                    $stmtVer->execute([$userRow['user_id']]);
                    $verifiedDates = $stmtVer->fetchAll(PDO::FETCH_COLUMN);
                }
            }
        }

        // Check if pardon_open table exists (for Open Pardon column when super_admin views staff)
        $hasPardonOpenTable = false;
        try {
            $tblCheck = $db->query("SHOW TABLES LIKE 'pardon_open'");
            $hasPardonOpenTable = $tblCheck && $tblCheck->rowCount() > 0;
        } catch (Exception $e) { /* ignore */ }
        $pardonOpenSub = $hasPardonOpenTable
            ? ", (SELECT 1 FROM pardon_open po WHERE po.employee_id = al.employee_id AND po.log_date = al.log_date LIMIT 1) as pardon_open_flag"
            : "";

        $calendarHolidayTitleSub = '';
        try {
            $tcEv = $db->query("SHOW TABLES LIKE 'calendar_events'");
            if ($tcEv && $tcEv->rowCount() > 0) {
                $ceArchived = $db->query("SHOW COLUMNS FROM calendar_events LIKE 'is_archived'");
                $ceHasArchived = $ceArchived && $ceArchived->rowCount() > 0;
                $calendarHolidayTitleSub = $ceHasArchived
                    ? "(SELECT ce.title FROM calendar_events ce WHERE ce.event_type = 'holiday' AND COALESCE(ce.is_archived, 0) = 0 AND ce.event_date = al.log_date ORDER BY ce.id DESC LIMIT 1)"
                    : "(SELECT ce.title FROM calendar_events ce WHERE ce.event_type = 'holiday' AND ce.event_date = al.log_date ORDER BY ce.id DESC LIMIT 1)";
            }
        } catch (Exception $e) { /* ignore */ }
        $holidayTitleSql = $calendarHolidayTitleSub !== ''
            ? "COALESCE(
                (SELECT h.title FROM holidays h WHERE h.id = al.holiday_id LIMIT 1),
                (SELECT h2.title FROM holidays h2 WHERE h2.date = al.log_date LIMIT 1),
                " . $calendarHolidayTitleSub . "
            ) as holiday_title"
            : "COALESCE(
                (SELECT h.title FROM holidays h WHERE h.id = al.holiday_id LIMIT 1),
                (SELECT h2.title FROM holidays h2 WHERE h2.date = al.log_date LIMIT 1)
            ) as holiday_title";
        $holidayHalfDaySelect = "COALESCE((SELECT h.is_half_day FROM holidays h WHERE h.id = al.holiday_id LIMIT 1), (SELECT h2.is_half_day FROM holidays h2 WHERE h2.date = al.log_date LIMIT 1), 0)";
        $holidayHalfPeriodSelect = "COALESCE((SELECT h.half_day_period FROM holidays h WHERE h.id = al.holiday_id LIMIT 1), (SELECT h2.half_day_period FROM holidays h2 WHERE h2.date = al.log_date LIMIT 1), NULL)";
        
        $query = "SELECT al.id, al.employee_id, al.log_date, al.time_in, al.lunch_out, al.lunch_in, al.time_out, al.ot_in, al.ot_out, al.total_ot, al.remarks, al.tarf_id, al.holiday_id, al.created_at,
                                     (SELECT pr.status FROM pardon_requests pr 
                                      WHERE pr.log_id = al.id AND pr.employee_id = al.employee_id 
                                      ORDER BY pr.created_at DESC LIMIT 1) as pardon_status,
                                     (SELECT t.title FROM tarf t WHERE t.id = al.tarf_id LIMIT 1) as tarf_title,
                                     " . $holidayTitleSql . ",
                                     " . $holidayHalfDaySelect . " as holiday_is_half_day,
                                     " . $holidayHalfPeriodSelect . " as holiday_half_day_period
                                     $pardonOpenSub
                              FROM attendance_logs al
                              WHERE al.employee_id = ?";
        $params = [$employee_id];
        
        // Add date range filtering if provided
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

        $ntarfApprovedIdx = staff_dtr_approved_tarf_ntarf_indexes_for_employee($db, $employee_id);

        $fmtTime = function ($t) {
            if (!$t) {
                return null;
            }
            $s = substr($t, 0, 5);
            return ($s === '00:00' || $s === '0:00') ? null : $s;
        };
        
        // When date range is provided (DTR context), also fetch dean and official times for full DTR display (same as faculty view_logs)
        $dean_name = '';
        $dean_department = '';
        $official_regular = '08:00-12:00, 13:00-17:00';
        $official_saturday = '—';
        
        if (!empty($date_from) && !empty($date_to)) {
            // In Charge: designated pardon opener (by department/designation) or fallback to Dean
            $inCharge = function_exists('getPardonOpenerDisplayNameForEmployee') ? getPardonOpenerDisplayNameForEmployee($employee_id, $db) : '';
            if ($inCharge !== '' && $inCharge !== 'HR') {
                $parts = preg_split('/,\s*/', $inCharge, 2);
                $dean_name = trim($parts[0] ?? '');
                $dean_department = trim($parts[1] ?? '');
            } else {
                $stmtDept = $db->prepare("SELECT fp.department FROM faculty_profiles fp WHERE fp.employee_id = ? LIMIT 1");
                $stmtDept->execute([$employee_id]);
                $deptRow = $stmtDept->fetch(PDO::FETCH_ASSOC);
                $empDepartment = trim($deptRow['department'] ?? '');
                $dean_name = '';
                $dean_department = '';
                $colCheck = $db->query("SHOW COLUMNS FROM dtr_daily_submissions LIKE 'dean_verified_by'");
                if ($colCheck && $colCheck->rowCount() > 0) {
                    $stmtVer = $db->prepare("
                        SELECT CONCAT(uv.first_name, ' ', uv.last_name) as verifier_name, fpv.department as verifier_dept
                        FROM dtr_daily_submissions dsv
                        INNER JOIN faculty_profiles fp_emp ON fp_emp.user_id = dsv.user_id AND fp_emp.employee_id = ?
                        LEFT JOIN users uv ON uv.id = dsv.dean_verified_by
                        LEFT JOIN faculty_profiles fpv ON fpv.user_id = uv.id
                        WHERE dsv.dean_verified_at IS NOT NULL AND dsv.dean_verified_by IS NOT NULL
                          AND dsv.log_date >= ? AND dsv.log_date <= ?
                        ORDER BY dsv.dean_verified_at DESC LIMIT 1
                    ");
                    $stmtVer->execute([$employee_id, $date_from, $date_to]);
                    $ver = $stmtVer->fetch(PDO::FETCH_ASSOC);
                    if ($ver && !empty(trim($ver['verifier_name'] ?? ''))) {
                        $dean_name = trim($ver['verifier_name']);
                        $dean_department = trim($ver['verifier_dept'] ?? '');
                    }
                }
                if ($dean_name === '' && !empty($empDepartment)) {
                    $stmtDean = $db->prepare("SELECT u.first_name, u.last_name, fp.department FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.department = ? AND LOWER(TRIM(COALESCE(fp.designation, ''))) = 'dean' LIMIT 1");
                    $stmtDean->execute([$empDepartment]);
                    $dean = $stmtDean->fetch(PDO::FETCH_ASSOC);
                    if ($dean) {
                        $dean_name = trim(($dean['first_name'] ?? '') . ' ' . ($dean['last_name'] ?? ''));
                        $dean_department = trim($dean['department'] ?? '');
                    }
                }
            }
            
            // Get official times for Regular days (Monday) and Saturday
            $fmt = function($t) { return $t ? substr($t, 0, 5) : ''; };
            $stmtOT = $db->prepare("SELECT weekday, time_in, lunch_out, lunch_in, time_out FROM employee_official_times WHERE employee_id = ? AND (weekday = 'Monday' OR weekday = 'Saturday') ORDER BY start_date DESC");
            $stmtOT->execute([$employee_id]);
            $official_regular_set = false;
            $official_saturday_set = false;
            while ($ot = $stmtOT->fetch(PDO::FETCH_ASSOC)) {
                $w = trim($ot['weekday'] ?? '');
                $seg = ($fmt($ot['time_in']) && $fmt($ot['lunch_out']) ? $fmt($ot['time_in']) . '-' . $fmt($ot['lunch_out']) : '') . ($fmt($ot['lunch_in']) && $fmt($ot['time_out']) ? ', ' . $fmt($ot['lunch_in']) . '-' . $fmt($ot['time_out']) : '');
                if ($w === 'Monday' && $seg && !$official_regular_set) { $official_regular = $seg; $official_regular_set = true; }
                if ($w === 'Saturday' && $seg && !$official_saturday_set) { $official_saturday = $seg; $official_saturday_set = true; }
            }

            // Build official_by_date: per-date official times for undertime calculation (same as faculty get_employee_dtr_logs_api)
            $official_by_date = [];
            $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $defaultOfficial = ['lunch_out' => 12 * 60, 'lunch_in' => 13 * 60, 'time_out' => 17 * 60];
            $parseMin = function($t) {
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
        }
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $logDateStr = $row['log_date'] ? date('Y-m-d', strtotime($row['log_date'])) : null;
            // Holiday rows: always show (do not require DTR submission/verification)
            $rowIsHoliday = (!empty($row['holiday_id']) || !empty($row['holiday_title']) || (!empty($row['remarks']) && strpos($row['remarks'], 'Holiday:') === 0));
            // Super_admin (HR) sees all submitted dates so they can verify; regular admin only sees dean-verified dates
            if (!$rowIsHoliday && $useVerifiedForAdmin && !$isSuperAdmin) {
                if ($logDateStr && !in_array($logDateStr, $verifiedDates, true)) {
                    continue; // Skip logs for dates not yet verified by dean
                }
            } elseif (!$rowIsHoliday && $useVerifiedForAdmin && $isSuperAdmin) {
                if ($logDateStr && !in_array($logDateStr, $submittedDates, true)) {
                    continue; // HR: skip only dates not submitted (HR can see all submitted to verify)
                }
            } elseif (!$rowIsHoliday && !empty($submittedDates) && $logDateStr && !in_array($logDateStr, $submittedDates, true)) {
                continue; // Legacy: skip logs for dates not yet submitted by employee
            }
            $isLeave = (strtoupper(trim($row['remarks'] ?? '')) === 'LEAVE');
            $isHoliday = (!empty($row['holiday_id']) || !empty($row['holiday_title']) || (!empty($row['remarks']) && strpos($row['remarks'], 'Holiday:') === 0));
            $hasHolidayAttendance = staff_dtr_row_has_real_holiday_attendance($row, $isHoliday);
            $isHalfDayHoliday = $isHoliday && !$hasHolidayAttendance && !empty($row['holiday_is_half_day']);
            $halfDayPeriod = ($row['holiday_half_day_period'] ?? 'morning') === 'afternoon' ? 'afternoon' : 'morning';
            $rowRemarksStr = (string) ($row['remarks'] ?? '');
            $approvedTarfNtarfPardon = staff_dtr_log_matches_approved_tarf_ntarf($row, $ntarfApprovedIdx);
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
                    $timeInVal = $fmtTime($row['time_in'] ?? null);
                    $timeLOVal = $fmtTime($row['lunch_out'] ?? null);
                    $timeLIVal = 'HOLIDAY';
                    $timeOutVal = 'HOLIDAY';
                } else {
                    $timeInVal = 'HOLIDAY';
                    $timeLOVal = 'HOLIDAY';
                    $timeLIVal = $fmtTime($row['lunch_in'] ?? null);
                    $timeOutVal = $fmtTime($row['time_out'] ?? null);
                }
            } elseif ($isHoliday && !$hasHolidayAttendance) {
                $timeInVal = $timeLOVal = $timeLIVal = $timeOutVal = 'HOLIDAY';
            } else {
                $timeInVal = $row['time_in'] ? substr($row['time_in'], 0, 5) : '00:00';
                $timeLOVal = $row['lunch_out'] ? substr($row['lunch_out'], 0, 5) : '00:00';
                $timeLIVal = $row['lunch_in'] ? substr($row['lunch_in'], 0, 5) : '00:00';
                $timeOutVal = $row['time_out'] ? substr($row['time_out'], 0, 5) : '00:00';
            }
            $logs[] = [
                'id' => $row['id'],
                'employee_id' => $row['employee_id'],
                'log_date' => $logDateStr,
                'time_in' => $timeInVal,
                'lunch_out' => $timeLOVal,
                'lunch_in' => $timeLIVal,
                'time_out' => $timeOutVal,
                'ot_in' => $row['ot_in'] ? substr($row['ot_in'], 0, 5) : null,
                'ot_out' => $row['ot_out'] ? substr($row['ot_out'], 0, 5) : null,
                'total_ot' => $row['total_ot'] ? substr($row['total_ot'], 0, 8) : null, // Format as HH:MM:SS
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
                'pardon_open' => $hasPardonOpenTable && !empty($row['pardon_open_flag']),
                'dean_verified' => $logDateStr && in_array($logDateStr, $verifiedDates, true)
            ];
        }
        
        // Add absent days: employee has official time for a day but did not come in (no log)
        if (!empty($date_from) && !empty($date_to)) {
            $logDates = array_flip(array_column($logs, 'log_date'));
            $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            
            // Get holiday dates in range: `holidays` table + calendar_events (event_type = holiday)
            $holidayDates = [];
            try {
                $stmtH = $db->prepare("SELECT date FROM holidays WHERE date >= ? AND date <= ?");
                $stmtH->execute([$date_from, $date_to]);
                $holidayDates = $stmtH->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) { /* ignore */ }
            try {
                $tCal = $db->query("SHOW TABLES LIKE 'calendar_events'");
                if ($tCal && $tCal->rowCount() > 0) {
                    $ceArcAbsent = $db->query("SHOW COLUMNS FROM calendar_events LIKE 'is_archived'");
                    $absentCalArchived = ($ceArcAbsent && $ceArcAbsent->rowCount() > 0)
                        ? 'AND COALESCE(is_archived, 0) = 0'
                        : '';
                    $stmtCe = $db->prepare("
                        SELECT event_date FROM calendar_events
                        WHERE event_type = 'holiday' " . $absentCalArchived . "
                        AND event_date >= ? AND event_date <= ?
                    ");
                    $stmtCe->execute([$date_from, $date_to]);
                    $holidayDates = array_merge($holidayDates, $stmtCe->fetchAll(PDO::FETCH_COLUMN));
                }
            } catch (Exception $e) { /* ignore */ }
            $holidayDatesSet = [];
            foreach ($holidayDates as $hd) {
                $norm = $normalizeHolidayDate($hd);
                if ($norm !== '') {
                    $holidayDatesSet[$norm] = true;
                }
            }
            
            $start = new DateTime($date_from);
            $end = new DateTime($date_to);
            $end->modify('+1 day');
            $interval = new DateInterval('P1D');
            $period = new DatePeriod($start, $interval, $end);
            
            $pardonOpenDates = [];
            if ($hasPardonOpenTable) {
                $stmtPo = $db->prepare("SELECT log_date FROM pardon_open WHERE employee_id = ?");
                $stmtPo->execute([$employee_id]);
                $pardonOpenDates = $stmtPo->fetchAll(PDO::FETCH_COLUMN);
            }
            
            foreach ($period as $dt) {
                $d = $dt->format('Y-m-d');
                if (isset($logDates[$d]) || isset($holidayDatesSet[$d])) continue;
                
                $weekday = $weekdays[(int)$dt->format('w')];
                // Official time applies only from its start_date onwards (computation starts at Start Date)
                $stmtOT = $db->prepare("SELECT id FROM employee_official_times 
                    WHERE employee_id = ? AND weekday = ? 
                    AND (end_date IS NULL OR end_date >= ?) 
                    AND start_date <= ? 
                    ORDER BY start_date DESC LIMIT 1");
                $stmtOT->execute([$employee_id, $weekday, $d, $d]);
                if ($stmtOT->fetch()) {
                    $logs[] = [
                        'id' => null,
                        'employee_id' => $employee_id,
                        'log_date' => $d,
                        'time_in' => '00:00',
                        'lunch_out' => '00:00',
                        'lunch_in' => '00:00',
                        'time_out' => '00:00',
                        'ot_in' => null,
                        'ot_out' => null,
                        'total_ot' => null,
                        'remarks' => null,
                        'tarf_id' => null,
                        'tarf_title' => null,
                        'holiday_id' => null,
                        'holiday_title' => null,
                        'is_tarf' => false,
                        'is_holiday' => false,
                        'created_at' => null,
                        'pardon_status' => null,
                        'pardon_open' => in_array($d, $pardonOpenDates, true),
                        'dean_verified' => false,
                        'is_absent_day' => true
                    ];
                }
            }
            usort($logs, function ($a, $b) {
                return strcmp($b['log_date'] ?? '', $a['log_date'] ?? '');
            });
        }

        /*
         * Employee DTR modal often opens with no date filter: query uses LIMIT 500 only, so holiday dates
         * before the newest 500 rows disappear. Also fills gaps when a holiday has no attendance row yet.
         * Inject synthetic holiday rows for every holiday (holidays table + calendar) from policy start through range end.
         */
        $injectFrom = $holidayPolicyStartDate;
        $injectTo = date('Y-m-d');
        if (!empty($date_from)) {
            $injectFrom = max($injectFrom, $date_from);
        }
        if (!empty($date_to)) {
            $injectTo = min($injectTo, $date_to);
        }
        if ($injectFrom <= $injectTo) {
            $logDatesPresent = [];
            foreach ($logs as $lg) {
                $k = $normalizeHolidayDate($lg['log_date'] ?? '');
                if ($k !== '') {
                    $logDatesPresent[$k] = true;
                }
            }
            $holidayMetaByDate = [];
            try {
                $stmtHolInj = $db->prepare("SELECT id, title, date, COALESCE(is_half_day, 0) AS is_half_day, half_day_period FROM holidays WHERE date >= ? AND date <= ?");
                $stmtHolInj->execute([$injectFrom, $injectTo]);
                foreach ($stmtHolInj->fetchAll(PDO::FETCH_ASSOC) as $hr) {
                    $dn = $normalizeHolidayDate($hr['date'] ?? '');
                    if ($dn === '') {
                        continue;
                    }
                    $holidayMetaByDate[$dn] = [
                        'holiday_id' => isset($hr['id']) ? (int) $hr['id'] : null,
                        'title' => $hr['title'] ?? 'Holiday',
                        'is_half_day' => !empty($hr['is_half_day']) ? 1 : 0,
                        'half_day_period' => $hr['half_day_period'] ?? null,
                    ];
                }
            } catch (Exception $e) { /* ignore */ }
            try {
                $tCalInj = $db->query("SHOW TABLES LIKE 'calendar_events'");
                if ($tCalInj && $tCalInj->rowCount() > 0) {
                    $ceArcInj = $db->query("SHOW COLUMNS FROM calendar_events LIKE 'is_archived'");
                    $archSqlInj = ($ceArcInj && $ceArcInj->rowCount() > 0) ? 'AND COALESCE(is_archived, 0) = 0' : '';
                    $stmtCeInj = $db->prepare("
                        SELECT title, event_date FROM calendar_events
                        WHERE event_type = 'holiday' " . $archSqlInj . "
                        AND event_date >= ? AND event_date <= ?
                    ");
                    $stmtCeInj->execute([$injectFrom, $injectTo]);
                    foreach ($stmtCeInj->fetchAll(PDO::FETCH_ASSOC) as $cr) {
                        $dn = $normalizeHolidayDate($cr['event_date'] ?? '');
                        if ($dn === '') {
                            continue;
                        }
                        if (!isset($holidayMetaByDate[$dn])) {
                            $holidayMetaByDate[$dn] = [
                                'holiday_id' => null,
                                'title' => $cr['title'] ?? 'Holiday',
                                'is_half_day' => 0,
                                'half_day_period' => null,
                            ];
                        }
                    }
                }
            } catch (Exception $e) { /* ignore */ }

            $pardonOpenForSynthetic = [];
            if ($hasPardonOpenTable) {
                try {
                    $stmtPoSyn = $db->prepare("SELECT log_date FROM pardon_open WHERE employee_id = ?");
                    $stmtPoSyn->execute([$employee_id]);
                    foreach ($stmtPoSyn->fetchAll(PDO::FETCH_COLUMN) as $pod) {
                        $pn = $normalizeHolidayDate($pod);
                        if ($pn !== '') {
                            $pardonOpenForSynthetic[$pn] = true;
                        }
                    }
                } catch (Exception $e) { /* ignore */ }
            }

            $addedSynthetic = false;
            foreach ($holidayMetaByDate as $hDate => $meta) {
                if ($hDate < $injectFrom || $hDate > $injectTo) {
                    continue;
                }
                if (isset($logDatesPresent[$hDate])) {
                    continue;
                }
                $title = $meta['title'];
                $hid = $meta['holiday_id'] ?? null;
                $remarks = 'Holiday: ' . $title;
                $isHd = !empty($meta['is_half_day']);
                $hp = ($isHd && (($meta['half_day_period'] ?? 'morning') === 'afternoon')) ? 'afternoon' : 'morning';
                if ($isHd && $hp === 'afternoon') {
                    $synTI = null;
                    $synLO = null;
                    $synLI = 'HOLIDAY';
                    $synTO = 'HOLIDAY';
                } elseif ($isHd) {
                    $synTI = 'HOLIDAY';
                    $synLO = 'HOLIDAY';
                    $synLI = null;
                    $synTO = null;
                } else {
                    $synTI = $synLO = $synLI = $synTO = 'HOLIDAY';
                }
                $logs[] = [
                    'id' => null,
                    'employee_id' => $employee_id,
                    'log_date' => $hDate,
                    'time_in' => $synTI,
                    'lunch_out' => $synLO,
                    'lunch_in' => $synLI,
                    'time_out' => $synTO,
                    'ot_in' => null,
                    'ot_out' => null,
                    'total_ot' => null,
                    'remarks' => $remarks,
                    'tarf_id' => null,
                    'tarf_title' => null,
                    'holiday_id' => $hid ?: null,
                    'holiday_title' => $title,
                    'holiday_is_half_day' => $isHd ? 1 : 0,
                    'holiday_half_day_period' => $isHd ? $hp : null,
                    'is_tarf' => false,
                    'is_holiday' => true,
                    'has_holiday_attendance' => false,
                    'created_at' => null,
                    'pardon_status' => null,
                    'pardon_open' => $hasPardonOpenTable && !empty($pardonOpenForSynthetic[$hDate]),
                    'dean_verified' => $hDate && in_array($hDate, $verifiedDates, true),
                    'is_absent_day' => false,
                    'is_synthetic_holiday' => true,
                ];
                $logDatesPresent[$hDate] = true;
                $addedSynthetic = true;
            }
            if ($addedSynthetic) {
                usort($logs, function ($a, $b) {
                    return strcmp($b['log_date'] ?? '', $a['log_date'] ?? '');
                });
            }
        }
        
        // Get employee user_type (faculty or staff) for Open Pardon UI - super_admin opens pardon for staff only
        $employee_user_type = 'faculty';
        $stmtUT = $db->prepare("SELECT u.user_type FROM faculty_profiles fp JOIN users u ON fp.user_id = u.id WHERE fp.employee_id = ? LIMIT 1");
        $stmtUT->execute([$employee_id]);
        $utRow = $stmtUT->fetch(PDO::FETCH_ASSOC);
        if ($utRow) {
            $employee_user_type = $utRow['user_type'] ?? 'faculty';
        }
        
        // Get pardon_open_dates for this employee (for Open Pardon column state)
        $pardon_open_dates = [];
        if ($hasPardonOpenTable) {
            $stmtPo = $db->prepare("SELECT log_date FROM pardon_open WHERE employee_id = ?");
            $stmtPo->execute([$employee_id]);
            $pardon_open_dates = $stmtPo->fetchAll(PDO::FETCH_COLUMN);
        }
        
        $response = [
            'success' => true,
            'logs' => $logs,
            'count' => count($logs),
            'employee_id' => $employee_id,
            'employee_user_type' => $employee_user_type,
            'pardon_open_dates' => $pardon_open_dates
        ];
        if (!empty($date_from) && !empty($date_to)) {
            $response['dean_name'] = $dean_name;
            $response['dean_department'] = $dean_department;
            $response['official_regular'] = $official_regular;
            $response['official_saturday'] = $official_saturday;
            $response['official_by_date'] = $official_by_date ?? [];
        }
        echo json_encode($response);
    } else {
        // Normal mode - return empty for now (can be implemented later)
        echo json_encode(['success' => true, 'logs' => []]);
    }
} catch (Exception $e) {
    error_log('fetch_logs_api.php Error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching logs: ' . $e->getMessage(),
        'logs' => []
    ]);
}
?>

