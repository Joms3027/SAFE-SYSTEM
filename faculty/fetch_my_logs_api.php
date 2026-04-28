<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/session_optimization.php';
require_once '../includes/staff_dtr_month_data.php';

// Set JSON header early for API responses
header('Content-Type: application/json');

// For API endpoints, check authentication and return JSON 401 instead of redirecting
// IMPORTANT: Check authentication BEFORE closing session, as isLoggedIn() requires active session
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Please log in']);
    exit;
}

// Check if user is faculty, staff, or admin (admin may have faculty_profile for own logs)
if (!isFaculty() && !isStaff() && !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied - Faculty or Staff privileges required']);
    exit;
}

// Close session early for read-only API call to prevent blocking
// This allows multiple users to fetch logs simultaneously without session conflicts
// IMPORTANT: Close session AFTER authentication checks, as they require active session
closeSessionEarly(true);

$employee_id = $_GET['employee_id'] ?? '';
$simple = isset($_GET['simple']) && $_GET['simple'] == '1';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

if (empty($employee_id)) {
    echo json_encode(['success' => false, 'logs' => [], 'message' => 'Safe Employee ID not provided']);
    exit;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Verify that the employee_id belongs to the current logged-in faculty
    $stmt = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? AND fp.employee_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id'], $employee_id]);
    $verify = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$verify) {
        echo json_encode(['success' => false, 'logs' => [], 'message' => 'Unauthorized: Safe Employee ID does not match your account']);
        exit;
    }
    
    // Check if requesting pardon details for a specific log
    $log_id = $_GET['log_id'] ?? null;
    if ($log_id) {
        $hasPardonType = false;
        $hasPardonCovered = false;
        try {
            $colCheck = $db->query("SHOW COLUMNS FROM pardon_requests LIKE 'pardon_type'");
            $hasPardonType = $colCheck && $colCheck->rowCount() > 0;
        } catch (Exception $e) {}
        try {
            $colCd = $db->query("SHOW COLUMNS FROM pardon_requests LIKE 'pardon_covered_dates'");
            $hasPardonCovered = $colCd && $colCd->rowCount() > 0;
        } catch (Exception $e) {}
        $pardonTypeCol = $hasPardonType ? ', pr.pardon_type' : '';
        $pardonCoveredCol = $hasPardonCovered ? ', pr.pardon_covered_dates' : '';
        $stmt = $db->prepare("SELECT pr.status, pr.review_notes, pr.reviewed_at, pr.reason,
                                     pr.requested_time_in, pr.requested_lunch_out, pr.requested_lunch_in, pr.requested_time_out
                                     $pardonTypeCol
                                     $pardonCoveredCol
                                     , reviewer.first_name as reviewer_first_name, reviewer.last_name as reviewer_last_name
                              FROM pardon_requests pr
                              LEFT JOIN users reviewer ON pr.reviewed_by = reviewer.id
                              WHERE pr.log_id = ? AND pr.employee_id = ?
                              ORDER BY pr.created_at DESC LIMIT 1");
        $stmt->execute([$log_id, $employee_id]);
        $pardon = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($pardon) {
            $coveredDates = [];
            if ($hasPardonCovered && !empty($pardon['pardon_covered_dates'])) {
                $cj = json_decode($pardon['pardon_covered_dates'], true);
                if (is_array($cj)) {
                    foreach ($cj as $cd) {
                        $cd = trim((string) $cd);
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cd)) {
                            $coveredDates[] = $cd;
                        }
                    }
                    $coveredDates = array_values(array_unique($coveredDates));
                    sort($coveredDates);
                }
            }
            echo json_encode([
                'success' => true,
                'pardon' => [
                    'status' => $pardon['status'],
                    'review_notes' => $pardon['review_notes'],
                    'reviewed_at' => $pardon['reviewed_at'] ? date('Y-m-d H:i', strtotime($pardon['reviewed_at'])) : null,
                    'reason' => $pardon['reason'],
                    'reviewer_name' => trim(($pardon['reviewer_first_name'] ?? '') . ' ' . ($pardon['reviewer_last_name'] ?? '')),
                    'requested_time_in' => $pardon['requested_time_in'] ?? null,
                    'requested_lunch_out' => $pardon['requested_lunch_out'] ?? null,
                    'requested_lunch_in' => $pardon['requested_lunch_in'] ?? null,
                    'requested_time_out' => $pardon['requested_time_out'] ?? null,
                    'pardon_type' => $pardon['pardon_type'] ?? 'ordinary_pardon',
                    'covered_dates' => $coveredDates
                ]
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'pardon' => null
            ]);
        }
        exit;
    }
    
    // SIMPLE MODE - for view_logs.php (just list all personal logs from attendance_logs)
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
        
        // Check for holidays that don't have logs yet and create them automatically
        try {
            // Get all holidays that don't have attendance logs for this employee
            // Include holidays starting March 09, 2026 and in requested date range
            $holidayMinDate = '2026-03-09';
            $holidayDateCondition = "h.date >= ? AND h.date <= CURDATE()";
            $holidayParams = [$holidayMinDate, $employee_id];
            if (!empty($date_from) && !empty($date_to)) {
                $rangeFrom = max($holidayMinDate, $date_from);
                $holidayDateCondition = "(h.date >= ? AND h.date <= CURDATE()) OR (h.date >= ? AND h.date <= ?)";
                $holidayParams = [$holidayMinDate, $rangeFrom, $date_to, $employee_id];
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
            
            // Create missing holiday logs
            foreach ($missingHolidays as $holiday) {
                $holidayDate = $holiday['date'];
                $holidayId = $holiday['id'];
                $holidayTitle = $holiday['title'];
                $remarks = "Holiday: " . $holidayTitle;
                
                // Check if log exists (might exist without holiday_id)
                $stmtCheck = $db->prepare("SELECT id FROM attendance_logs WHERE employee_id = ? AND log_date = ?");
                $stmtCheck->execute([$employee_id, $holidayDate]);
                $existingLog = $stmtCheck->fetch(PDO::FETCH_ASSOC);
                
                if ($existingLog) {
                    $stmtGetLog = $db->prepare("SELECT time_in, lunch_out, lunch_in, time_out, remarks FROM attendance_logs WHERE id = ?");
                    $stmtGetLog->execute([$existingLog['id']]);
                    $logRow = $stmtGetLog->fetch(PDO::FETCH_ASSOC);
                    $hasActualAttendance = staff_dtr_row_has_real_holiday_attendance($logRow, true);
                    if ($hasActualAttendance) {
                        $stmtTagOnly = $db->prepare("UPDATE attendance_logs SET holiday_id = ?, remarks = CONCAT(?, COALESCE(remarks, '')) WHERE id = ?");
                        $stmtTagOnly->execute([$holidayId, $remarks . ' ', $existingLog['id']]);
                    } else {
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
                        $stmtUpdate->execute([$remarks, $holidayId, $existingLog['id']]);
                    }
                } else {
                    // Create new holiday log
                    $stmtInsert = $db->prepare("
                        INSERT INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks, holiday_id, created_at)
                        VALUES (?, ?, '08:00:00', '12:00:00', '13:00:00', '17:00:00', ?, ?, NOW())
                    ");
                    $stmtInsert->execute([$employee_id, $holidayDate, $remarks, $holidayId]);
                }
            }
        } catch (Exception $e) {
            error_log("Error auto-creating holiday logs: " . $e->getMessage());
        }
        
        // Check if pardon_open table exists (dean must open pardon for a date before employee can submit)
        $hasPardonOpenTable = false;
        try {
            $tbl = $db->query("SHOW TABLES LIKE 'pardon_open'");
            $hasPardonOpenTable = $tbl && $tbl->rowCount() > 0;
        } catch (Exception $e) { /* ignore */ }

        // Fetch personal attendance logs from attendance_logs table for this employee
        // Also fetch the latest pardon request status and pardon_open (dean opened for this date) for each log
        $pardonOpenSub = $hasPardonOpenTable
            ? ", (SELECT 1 FROM pardon_open po WHERE po.employee_id = al.employee_id AND po.log_date = al.log_date LIMIT 1) as pardon_open_flag"
            : "";
        $holidayHalfDaySelect = "COALESCE((SELECT h.is_half_day FROM holidays h WHERE h.id = al.holiday_id LIMIT 1), (SELECT h2.is_half_day FROM holidays h2 WHERE h2.date = al.log_date LIMIT 1), 0)";
        $holidayHalfPeriodSelect = "COALESCE((SELECT h.half_day_period FROM holidays h WHERE h.id = al.holiday_id LIMIT 1), (SELECT h2.half_day_period FROM holidays h2 WHERE h2.date = al.log_date LIMIT 1), NULL)";
        $query = "SELECT al.id, al.employee_id, al.log_date, al.time_in, al.lunch_out, al.lunch_in, al.time_out, al.ot_in, al.ot_out, al.total_ot, al.remarks, al.tarf_id, al.holiday_id, al.created_at,
                                     (SELECT pr.status FROM pardon_requests pr 
                                      WHERE pr.log_id = al.id AND pr.employee_id = al.employee_id 
                                      ORDER BY pr.created_at DESC LIMIT 1) as pardon_status
                                     $pardonOpenSub,
                                     (SELECT t.title FROM tarf t WHERE t.id = al.tarf_id LIMIT 1) as tarf_title,
                                     COALESCE((SELECT h.title FROM holidays h WHERE h.id = al.holiday_id LIMIT 1), (SELECT h2.title FROM holidays h2 WHERE h2.date = al.log_date LIMIT 1)) as holiday_title,
                                     $holidayHalfDaySelect as holiday_is_half_day,
                                     $holidayHalfPeriodSelect as holiday_half_day_period
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
        
        $fmtTime = function($t) {
                if (!$t) return null;
                $s = substr($t, 0, 5);
                return ($s === '00:00' || $s === '0:00') ? null : $s;
            };
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $isLeave = (strtoupper(trim($row['remarks'] ?? '')) === 'LEAVE');
            $isHoliday = (!empty($row['holiday_id']) || !empty($row['holiday_title']) || (!empty($row['remarks']) && strpos($row['remarks'], 'Holiday:') === 0));
            $hasHolidayAttendance = staff_dtr_row_has_real_holiday_attendance($row, $isHoliday);
            $isHalfDayHoliday = $isHoliday && !$hasHolidayAttendance && !empty($row['holiday_is_half_day']);
            $halfDayPeriod = ($row['holiday_half_day_period'] ?? 'morning') === 'afternoon' ? 'afternoon' : 'morning';
            if ($isLeave) {
                $timeInVal = $timeLOVal = $timeLIVal = $timeOutVal = 'LEAVE';
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
                $timeInVal = $fmtTime($row['time_in'] ?? null);
                $timeLOVal = $fmtTime($row['lunch_out'] ?? null);
                $timeLIVal = $fmtTime($row['lunch_in'] ?? null);
                $timeOutVal = $fmtTime($row['time_out'] ?? null);
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
                'total_ot' => $row['total_ot'] ? substr($row['total_ot'], 0, 8) : null, // Format as HH:MM:SS
                'remarks' => $row['remarks'] ?? null,
                'tarf_id' => $row['tarf_id'] ?? null,
                'tarf_title' => $row['tarf_title'] ?? null,
                'holiday_id' => $row['holiday_id'] ?? null,
                'holiday_title' => $row['holiday_title'] ?? null,
                'holiday_is_half_day' => !empty($row['holiday_is_half_day']) ? 1 : 0,
                'holiday_half_day_period' => $row['holiday_half_day_period'] ?? null,
                'is_tarf' => (!empty($row['remarks']) && (strpos($row['remarks'], 'TARF:') === 0 || strtoupper($row['remarks']) === 'TARF')),
                'is_holiday' => (!empty($row['holiday_id']) || !empty($row['holiday_title']) || (!empty($row['remarks']) && strpos($row['remarks'], 'Holiday:') === 0)),
                'has_holiday_attendance' => $hasHolidayAttendance,
                'created_at' => $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : null,
                'pardon_status' => $row['pardon_status'],
                'pardon_open' => $hasPardonOpenTable && !empty($row['pardon_open_flag'])
            ];
        }

        // Resolve date range for pardon_open and absent-day scanning (UI filter or span of loaded logs)
        $absent_date_from = $date_from;
        $absent_date_to = $date_to;
        if (empty($absent_date_from) || empty($absent_date_to)) {
            foreach ($logs as $log) {
                $d = $log['log_date'] ?? null;
                if ($d) {
                    if (empty($absent_date_from) || $d < $absent_date_from) $absent_date_from = $d;
                    if (empty($absent_date_to) || $d > $absent_date_to) $absent_date_to = $d;
                }
            }
        }

        // For absent days we need pardon_open by (employee_id, log_date); fetch set of opened dates if table exists
        $pardonOpenDates = [];
        if ($hasPardonOpenTable && ($absent_date_from || $absent_date_to || count($logs) > 0)) {
            $rangeFrom = $absent_date_from;
            $rangeTo = $absent_date_to;
            if (empty($rangeFrom) || empty($rangeTo)) {
                foreach ($logs as $log) {
                    $d = $log['log_date'] ?? null;
                    if ($d) {
                        if (empty($rangeFrom) || $d < $rangeFrom) $rangeFrom = $d;
                        if (empty($rangeTo) || $d > $rangeTo) $rangeTo = $d;
                    }
                }
            }
            if ($rangeFrom && $rangeTo) {
                $stmtPo = $db->prepare("SELECT log_date FROM pardon_open WHERE employee_id = ? AND log_date >= ? AND log_date <= ?");
                $stmtPo->execute([$employee_id, $rangeFrom, $rangeTo]);
                $pardonOpenDates = $stmtPo->fetchAll(PDO::FETCH_COLUMN);
            }
        }

        // Add absent days: employee has official time for a day but did not come in (no log)
        if ($absent_date_from && $absent_date_to) {
            // Ensure absent_cleared table exists (for tracking cleared absents)
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS absent_cleared (
                    employee_id VARCHAR(50) NOT NULL,
                    log_date DATE NOT NULL,
                    cleared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (employee_id, log_date)
                )");
            } catch (Exception $e) { /* ignore */ }

            $logDates = array_flip(array_column($logs, 'log_date'));
            $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $holidayDates = [];
            try {
                $stmtH = $db->prepare("SELECT date FROM holidays WHERE date >= ? AND date <= ?");
                $stmtH->execute([$absent_date_from, $absent_date_to]);
                $holidayDates = $stmtH->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) { /* ignore */ }
            $clearedDates = [];
            try {
                $stmtC = $db->prepare("SELECT log_date FROM absent_cleared WHERE employee_id = ? AND log_date >= ? AND log_date <= ?");
                $stmtC->execute([$employee_id, $absent_date_from, $absent_date_to]);
                $clearedDates = $stmtC->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) { /* ignore */ }
            $start = new DateTime($absent_date_from);
            $end = new DateTime($absent_date_to);
            $end->modify('+1 day');
            $interval = new DateInterval('P1D');
            $today = (new DateTime('now', $start->getTimezone()))->format('Y-m-d');
            $period = new DatePeriod($start, $interval, $end);
            $absentDatesToAdd = [];
            foreach ($period as $dt) {
                $d = $dt->format('Y-m-d');
                // Do not add absent placeholder for future dates (not yet occurred)
                if ($d > $today) continue;
                if (isset($logDates[$d]) || in_array($d, $holidayDates, true)) continue;
                if (in_array($d, $clearedDates, true)) continue; // Skip cleared absents
                $weekday = $weekdays[(int)$dt->format('w')];
                // Official time applies only from its start_date onwards (computation starts at Start Date)
                $stmtOT = $db->prepare("SELECT id FROM employee_official_times 
                    WHERE employee_id = ? AND weekday = ? 
                    AND (end_date IS NULL OR end_date >= ?) 
                    AND start_date <= ? 
                    ORDER BY start_date DESC LIMIT 1");
                $stmtOT->execute([$employee_id, $weekday, $d, $d]);
                if ($stmtOT->fetch()) {
                    $absentDatesToAdd[] = $d;
                }
            }
            // Persist absent records to database so they are visible even when absent
            $stmtInsertAbsent = $db->prepare("
                INSERT IGNORE INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks, created_at)
                VALUES (?, ?, NULL, NULL, NULL, NULL, 'Absent', NOW())
            ");
            foreach ($absentDatesToAdd as $d) {
                try {
                    $stmtInsertAbsent->execute([$employee_id, $d]);
                } catch (Exception $e) {
                    error_log("Error inserting absent record: " . $e->getMessage());
                }
            }
            // Fetch the absent records from DB (including newly inserted) to get ids and merge into logs
            if (!empty($absentDatesToAdd)) {
                $placeholders = implode(',', array_fill(0, count($absentDatesToAdd), '?'));
                $stmtFetchAbsent = $db->prepare("
                    SELECT al.id, al.employee_id, al.log_date, al.time_in, al.lunch_out, al.lunch_in, al.time_out, al.ot_in, al.ot_out, al.total_ot, al.remarks, al.tarf_id, al.holiday_id, al.created_at,
                           (SELECT pr.status FROM pardon_requests pr WHERE pr.log_id = al.id AND pr.employee_id = al.employee_id ORDER BY pr.created_at DESC LIMIT 1) as pardon_status
                    FROM attendance_logs al
                    WHERE al.employee_id = ? AND al.log_date IN ($placeholders)
                ");
                $stmtFetchAbsent->execute(array_merge([$employee_id], $absentDatesToAdd));
                while ($row = $stmtFetchAbsent->fetch(PDO::FETCH_ASSOC)) {
                    $d = $row['log_date'] ? date('Y-m-d', strtotime($row['log_date'])) : null;
                    $logs[] = [
                        'id' => $row['id'],
                        'employee_id' => $row['employee_id'],
                        'log_date' => $d,
                        'time_in' => null,
                        'lunch_out' => null,
                        'lunch_in' => null,
                        'time_out' => null,
                        'ot_in' => null,
                        'ot_out' => null,
                        'total_ot' => null,
                        'remarks' => $row['remarks'] ?? null,
                        'tarf_id' => $row['tarf_id'] ?? null,
                        'tarf_title' => null,
                        'holiday_id' => $row['holiday_id'] ?? null,
                        'holiday_title' => null,
                        'is_tarf' => false,
                        'is_holiday' => false,
                        'created_at' => $row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : null,
                        'pardon_status' => $row['pardon_status'],
                        'pardon_open' => in_array($d, $pardonOpenDates, true),
                        'is_absent_day' => true
                    ];
                }
            }
            usort($logs, function ($a, $b) {
                return strcmp($b['log_date'] ?? '', $a['log_date'] ?? '');
            });
        }

        // Get official times for DTR display (same as admin fetch_staff_logs_api)
        $official_regular = '08:00-12:00, 13:00-17:00';
        $official_saturday = '—';
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

        $in_charge = function_exists('getPardonOpenerDisplayNameForEmployee') ? getPardonOpenerDisplayNameForEmployee($employee_id, $db) : '';
        if ($in_charge === 'HR') {
            $in_charge = '';
        }
        
        echo json_encode([
            'success' => true, 
            'logs' => $logs, 
            'count' => count($logs),
            'employee_id' => $employee_id,
            'official_regular' => $official_regular,
            'official_saturday' => $official_saturday,
            'in_charge' => $in_charge
        ]);
    } else {
        // Normal mode - return empty for now (can be implemented later)
        echo json_encode(['success' => true, 'logs' => []]);
    }
} catch (Exception $e) {
    error_log("Error fetching faculty logs: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching logs: ' . $e->getMessage()
    ]);
}
?>

