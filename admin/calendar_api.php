<?php
// Start output buffering to catch any stray output
ob_start();

// Suppress error display to prevent HTML output before JSON
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/mailer.php';
require_once '../includes/notifications.php';

// Bump when adding migrations below so the cache is invalidated once.
if (!defined('CALENDAR_API_SCHEMA_VERSION')) {
    define('CALENDAR_API_SCHEMA_VERSION', 3);
}

// Ensure session is active before accessing $_SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear any output that might have been generated
ob_clean();

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
// Prefer non-empty POST action, else GET (see earlier notes on ?action= empty string).
if ($method === 'POST') {
    $postAction = trim((string)($_POST['action'] ?? ''));
    $getAction = trim((string)($_GET['action'] ?? ''));
    $action = $postAction !== '' ? $postAction : $getAction;
} else {
    $action = trim((string)($_GET['action'] ?? ''));
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Run heavy CREATE/ALTER/SHOW migrations only until cache file exists (then skip on every request).
    // This avoids slowing every calendar load for all users. Bump CALENDAR_API_SCHEMA_VERSION when adding migrations.
    $calendarSchemaCacheFile = dirname(__DIR__) . '/storage/cache/calendar_api_schema_v' . CALENDAR_API_SCHEMA_VERSION . '.ok';
    if (!is_file($calendarSchemaCacheFile)) {
    // Ensure calendar_events table exists
    $db->exec("CREATE TABLE IF NOT EXISTS calendar_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        event_date DATE NOT NULL,
        event_time TIME,
        end_time TIME,
        location VARCHAR(255),
        category VARCHAR(50) DEFAULT 'university_event',
        event_type ENUM('university_event', 'holiday', 'other') DEFAULT 'university_event',
        is_philippines_holiday TINYINT(1) DEFAULT 0,
        color VARCHAR(7) DEFAULT '#007bff',
        is_archived TINYINT(1) DEFAULT 0,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_event_date (event_date),
        INDEX idx_event_type (event_type),
        INDEX idx_is_archived (is_archived)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    
    // Ensure holidays table exists
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS holidays (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_date (date),
            UNIQUE KEY unique_holiday_date (date, title)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        // Ensure AUTO_INCREMENT is set
        $stmt = $db->query("SHOW TABLE STATUS LIKE 'holidays'");
        $tableStatus = $stmt->fetch();
        if ($tableStatus && $tableStatus['Auto_increment'] == null) {
            $maxId = $db->query("SELECT COALESCE(MAX(id), 0) as max_id FROM holidays")->fetch()['max_id'];
            $db->exec("ALTER TABLE holidays AUTO_INCREMENT = " . ($maxId + 1));
        }
    } catch (Exception $e) {
        error_log("Holiday table creation error: " . $e->getMessage());
    }
    
    try {
        $stmt = $db->query("SHOW COLUMNS FROM holidays LIKE 'is_half_day'");
        if ($stmt && $stmt->rowCount() === 0) {
            $db->exec("ALTER TABLE holidays ADD COLUMN is_half_day TINYINT(1) NOT NULL DEFAULT 0");
        }
    } catch (Exception $e) {
        error_log("holidays.is_half_day migration: " . $e->getMessage());
    }
    
    try {
        $stmt = $db->query("SHOW COLUMNS FROM holidays LIKE 'half_day_period'");
        if ($stmt && $stmt->rowCount() === 0) {
            $db->exec("ALTER TABLE holidays ADD COLUMN half_day_period ENUM('morning', 'afternoon') NULL DEFAULT NULL AFTER is_half_day");
        }
    } catch (Exception $e) {
        error_log("holidays.half_day_period migration: " . $e->getMessage());
    }
    
    // Add new columns if they don't exist (for existing tables)
    try {
        $columns = ['description', 'end_time', 'location', 'category', 'color', 'is_archived'];
        $stmt = $db->query("SHOW COLUMNS FROM calendar_events");
        $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (!in_array('description', $existingColumns)) {
            $db->exec("ALTER TABLE calendar_events ADD COLUMN description TEXT");
        }
        if (!in_array('end_time', $existingColumns)) {
            $db->exec("ALTER TABLE calendar_events ADD COLUMN end_time TIME");
        }
        if (!in_array('location', $existingColumns)) {
            $db->exec("ALTER TABLE calendar_events ADD COLUMN location VARCHAR(255)");
        }
        if (!in_array('category', $existingColumns)) {
            $db->exec("ALTER TABLE calendar_events ADD COLUMN category VARCHAR(50) DEFAULT 'university_event'");
        }
        if (!in_array('color', $existingColumns)) {
            $db->exec("ALTER TABLE calendar_events ADD COLUMN color VARCHAR(7) DEFAULT '#007bff'");
        }
        if (!in_array('is_archived', $existingColumns)) {
            $db->exec("ALTER TABLE calendar_events ADD COLUMN is_archived TINYINT(1) DEFAULT 0");
        }
        if (!in_array('scope_supervisor_id', $existingColumns)) {
            $db->exec("ALTER TABLE calendar_events ADD COLUMN scope_supervisor_id INT NULL AFTER created_by");
        }
        
        // Check if index exists
        $stmt = $db->query("SHOW INDEXES FROM calendar_events WHERE Key_name = 'idx_is_archived'");
        if ($stmt->rowCount() === 0) {
            $db->exec("ALTER TABLE calendar_events ADD INDEX idx_is_archived (is_archived)");
        }
    } catch (Exception $e) {
        // Columns might already exist, continue
        error_log("Calendar table migration: " . $e->getMessage());
    }
    
    // Ensure tarf table has description and file_path columns (for existing installations)
    try {
        $stmt = $db->query("SHOW TABLES LIKE 'tarf'");
        if ($stmt->rowCount() > 0) {
            $stmt = $db->query("SHOW COLUMNS FROM tarf");
            $tarfCols = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('description', $tarfCols)) {
                $db->exec("ALTER TABLE tarf ADD COLUMN description TEXT");
            }
            if (!in_array('file_path', $tarfCols)) {
                $db->exec("ALTER TABLE tarf ADD COLUMN file_path VARCHAR(500)");
            }
        }
    } catch (Exception $e) {
        error_log("TARF table migration: " . $e->getMessage());
    }
    
        $cacheDir = dirname($calendarSchemaCacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        @file_put_contents($calendarSchemaCacheFile, date('c'), LOCK_EX);
    }
    
    /**
     * True if the attendance row has any non-empty time value (real or credited).
     */
    function attendance_log_has_any_time_value(array $row) {
        foreach (['time_in', 'lunch_out', 'lunch_in', 'time_out'] as $f) {
            if (!isset($row[$f])) {
                continue;
            }
            $v = trim((string)$row[$f]);
            if ($v === '' || $v === '00:00:00') {
                continue;
            }
            return true;
        }
        return false;
    }
    
    /**
     * Remove holiday placeholders without deleting rows that have time entries.
     * Deletes only "holiday-only" rows (all times empty); clears holiday_id on rows with times.
     */
    function release_holiday_attendance_logs($holidayId, $db) {
        $holidayId = (int)$holidayId;
        if ($holidayId <= 0) {
            return;
        }
        try {
            $stmtDel = $db->prepare("
                DELETE FROM attendance_logs
                WHERE holiday_id = ?
                AND (time_in IS NULL OR TRIM(time_in) = '' OR time_in = '00:00:00')
                AND (lunch_out IS NULL OR TRIM(lunch_out) = '' OR lunch_out = '00:00:00')
                AND (lunch_in IS NULL OR TRIM(lunch_in) = '' OR lunch_in = '00:00:00')
                AND (time_out IS NULL OR TRIM(time_out) = '' OR time_out = '00:00:00')
            ");
            $stmtDel->execute([$holidayId]);
        } catch (Exception $e) {
            error_log("release_holiday_attendance_logs DELETE: " . $e->getMessage());
        }
        try {
            $stmtUp = $db->prepare("UPDATE attendance_logs SET holiday_id = NULL WHERE holiday_id = ?");
            $stmtUp->execute([$holidayId]);
        } catch (Exception $e) {
            error_log("release_holiday_attendance_logs UPDATE: " . $e->getMessage());
        }
    }
    
    /**
     * Whether the log's four times match the employee's full official schedule (for synthetic holiday rows).
     */
    function attendance_times_match_full_official(array $row, array $ot) {
        if (empty($ot['found'])) {
            return false;
        }
        $norm = function ($t) {
            $t = trim((string)$t);
            if ($t === '' || $t === '00:00:00') {
                return '';
            }
            return strlen($t) >= 5 ? substr($t, 0, 5) : $t;
        };
        foreach (['time_in', 'lunch_out', 'lunch_in', 'time_out'] as $f) {
            if ($norm($row[$f] ?? '') !== $norm($ot[$f] ?? '')) {
                return false;
            }
        }
        return true;
    }
    
    // Function to create holiday attendance logs for ALL employees.
    // Credited hours use official schedule only when employee_official_times applies (found=true).
    // No official time and no pre-existing real attendance → holiday-only row (NULL times, no default 8–17 credit).
    function createHolidayAttendanceLogs($dates, $db, $holidayIds = null) {
        try {
            // Ensure attendance_logs table has remarks and holiday_id fields
            try {
                $db->exec("ALTER TABLE attendance_logs ADD COLUMN remarks VARCHAR(500) DEFAULT NULL");
            } catch (Exception $e) {
                // Column might already exist, ignore
            }
            
            try {
                $db->exec("ALTER TABLE attendance_logs ADD COLUMN holiday_id INT DEFAULT NULL");
                $db->exec("ALTER TABLE attendance_logs ADD INDEX idx_holiday_id (holiday_id)");
            } catch (Exception $e) {
                // Column might already exist, ignore
            }
            
            // Get ALL active employees (faculty and staff) - use same query as TARF
            $stmtEmployees = $db->prepare("
                SELECT DISTINCT fp.employee_id, u.id as user_id, u.first_name, u.last_name
                FROM users u
                INNER JOIN faculty_profiles fp ON u.id = fp.user_id
                WHERE u.user_type IN ('faculty', 'staff')
                AND u.is_active = 1
                AND u.is_verified = 1
                AND fp.employee_id IS NOT NULL
                AND fp.employee_id != ''
            ");
            $stmtEmployees->execute();
            $allEmployees = $stmtEmployees->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($allEmployees)) {
                error_log("No employees found for holiday attendance log creation");
                return;
            }
            
            error_log("Creating holiday attendance logs for " . count($allEmployees) . " employees");
            
            // If holidayIds is provided as a map (date => id), use it; otherwise, fetch holiday IDs by date
            $holidayIdMap = [];
            if ($holidayIds && is_array($holidayIds)) {
                // Check if it's already a map (has date keys) or an array (has numeric keys)
                // Use reset() and key() for PHP 7.0+ compatibility instead of array_key_first()
                $firstKey = null;
                if (!empty($holidayIds)) {
                    reset($holidayIds);
                    $firstKey = key($holidayIds);
                }
                if (is_string($firstKey) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $firstKey)) {
                    // It's already a map with date keys
                    $holidayIdMap = $holidayIds;
                } else {
                    // It's an array, convert to map (this shouldn't happen with new code, but keep for compatibility)
                    foreach ($dates as $index => $date) {
                        if (isset($holidayIds[$index])) {
                            $holidayIdMap[$date] = $holidayIds[$index];
                        }
                    }
                }
            }

            $stmtHolidayFallback = $db->prepare("SELECT id FROM holidays WHERE date = ? ORDER BY id DESC LIMIT 1");
            $stmtHolidayMeta = $db->prepare("SELECT title, COALESCE(is_half_day, 0) AS is_half_day, half_day_period FROM holidays WHERE id = ?");
            $stmtExistingLogs = $db->prepare("
                SELECT id, employee_id, remarks, time_in, lunch_out, lunch_in, time_out
                FROM attendance_logs WHERE log_date = ?
            ");
            $stmtUpdateFull = $db->prepare("
                UPDATE attendance_logs
                SET time_in = COALESCE(NULLIF(time_in, ''), ?),
                    lunch_out = COALESCE(NULLIF(lunch_out, ''), ?),
                    lunch_in = COALESCE(NULLIF(lunch_in, ''), ?),
                    time_out = COALESCE(NULLIF(time_out, ''), ?),
                    remarks = ?,
                    holiday_id = ?
                WHERE id = ?
            ");
            $stmtUpdateHalfAm = $db->prepare("
                UPDATE attendance_logs
                SET time_in = COALESCE(NULLIF(time_in, ''), ?),
                    lunch_out = COALESCE(NULLIF(lunch_out, ''), ?),
                    lunch_in = NULL,
                    time_out = NULL,
                    remarks = ?,
                    holiday_id = ?
                WHERE id = ?
            ");
            $stmtUpdateHalfPm = $db->prepare("
                UPDATE attendance_logs
                SET time_in = NULL,
                    lunch_out = NULL,
                    lunch_in = COALESCE(NULLIF(lunch_in, ''), ?),
                    time_out = COALESCE(NULLIF(time_out, ''), ?),
                    remarks = ?,
                    holiday_id = ?
                WHERE id = ?
            ");
            $stmtInsertHolidayLog = $db->prepare("
                INSERT INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks, holiday_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmtInsertHolidayOnly = $db->prepare("
                INSERT INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks, holiday_id, created_at)
                VALUES (?, ?, NULL, NULL, NULL, NULL, ?, ?, NOW())
            ");
            $stmtUpdateHolidayOnly = $db->prepare("
                UPDATE attendance_logs
                SET time_in = NULL, lunch_out = NULL, lunch_in = NULL, time_out = NULL,
                    remarks = ?, holiday_id = ?
                WHERE id = ?
            ");
            $stmtTagPreserveTimes = $db->prepare("
                UPDATE attendance_logs
                SET holiday_id = ?, remarks = ?
                WHERE id = ?
            ");
            $stmtTagHoliday = $db->prepare("UPDATE attendance_logs SET holiday_id = ?, remarks = CONCAT(?, COALESCE(remarks, '')) WHERE id = ? AND (holiday_id IS NULL OR holiday_id = 0)");
            $defaultOtHoliday = [
                'found' => false,
                'time_in' => '08:00:00',
                'lunch_out' => '12:00:00',
                'lunch_in' => '13:00:00',
                'time_out' => '17:00:00'
            ];
            
            foreach ($dates as $date) {
                // Get holiday ID for this date (use map if available, otherwise query)
                $holidayId = null;
                if (isset($holidayIdMap[$date])) {
                    $holidayId = $holidayIdMap[$date];
                } else {
                    $stmtHolidayFallback->execute([$date]);
                    $holiday = $stmtHolidayFallback->fetch(PDO::FETCH_ASSOC);
                    if ($holiday && isset($holiday['id'])) {
                        $holidayId = (int)$holiday['id'];
                        $holidayIdMap[$date] = $holidayId; // Cache it
                    }
                }
                
                $holidayTitle = 'Holiday';
                $isHalfDay = false;
                $halfPeriod = 'morning';
                if ($holidayId) {
                    $stmtHolidayMeta->execute([$holidayId]);
                    $holidayData = $stmtHolidayMeta->fetch(PDO::FETCH_ASSOC);
                    if ($holidayData) {
                        if (isset($holidayData['title'])) {
                            $holidayTitle = $holidayData['title'];
                        }
                        $isHalfDay = !empty($holidayData['is_half_day']);
                        $hp = $holidayData['half_day_period'] ?? null;
                        $halfPeriod = ($hp === 'afternoon') ? 'afternoon' : 'morning';
                    }
                }
                if ($isHalfDay) {
                    $remarks = ($halfPeriod === 'afternoon')
                        ? ('Holiday (Half-day PM): ' . $holidayTitle)
                        : ('Holiday (Half-day AM): ' . $holidayTitle);
                } else {
                    $remarks = 'Holiday: ' . $holidayTitle;
                }
                
                // Batch-load existing attendance logs for this date to avoid per-employee queries
                $stmtExistingLogs->execute([$date]);
                $existingLogs = [];
                foreach ($stmtExistingLogs->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $existingLogs[(string)$row['employee_id']] = $row;
                }

                $otByEmployee = getOfficialTimesBatchForDate($allEmployees, $date, $db);

                $logsCreated = 0;
                $logsUpdated = 0;
                foreach ($allEmployees as $employee) {
                    $employeeId = $employee['employee_id'];
                    
                    if (empty($employeeId)) {
                        error_log("Skipping employee with empty employee_id: user_id=" . ($employee['user_id'] ?? 'N/A') . ", name=" . ($employee['first_name'] ?? '') . " " . ($employee['last_name'] ?? ''));
                        continue;
                    }
                    
                    $ot = $otByEmployee[(string)$employeeId] ?? $defaultOtHoliday;
                    $hasOfficial = !empty($ot['found']);
                    $timeIn = $ot['time_in'];
                    $lunchOut = $ot['lunch_out'];
                    $lunchIn = $ot['lunch_in'];
                    $timeOut = $ot['time_out'];
                    
                    $existingLog = $existingLogs[(string)$employeeId] ?? null;
                    $remarksAuto = $existingLog['remarks'] ?? '';
                    $isAutoHolidayRemarks = ($remarksAuto === ''
                        || strpos($remarksAuto, 'Holiday:') === 0
                        || strpos($remarksAuto, 'Holiday (Half-day):') === 0
                        || strpos($remarksAuto, 'Holiday (Half-day AM):') === 0
                        || strpos($remarksAuto, 'Holiday (Half-day PM):') === 0);
                    
                    if ($existingLog) {
                        if ($isAutoHolidayRemarks) {
                            if (!$hasOfficial) {
                                // No official time: holiday-only rows (NULL times) or preserve actual entries on the day
                                if (attendance_log_has_any_time_value($existingLog)) {
                                    if ($stmtTagPreserveTimes->execute([$holidayId, $remarks, $existingLog['id']])) {
                                        $logsUpdated++;
                                    } else {
                                        $errorInfo = $stmtTagPreserveTimes->errorInfo();
                                        error_log("Error tagging holiday preserve-times for employee_id=$employeeId, date=$date: " . print_r($errorInfo, true));
                                    }
                                } elseif ($stmtUpdateHolidayOnly->execute([$remarks, $holidayId, $existingLog['id']])) {
                                    $logsUpdated++;
                                } else {
                                    $errorInfo = $stmtUpdateHolidayOnly->errorInfo();
                                    error_log("Error updating holiday-only log for employee_id=$employeeId, date=$date: " . print_r($errorInfo, true));
                                }
                            } elseif ($isHalfDay) {
                                // Half-day updates NULL out part of the day; skip that if times differ from full official (logged work)
                                if (attendance_log_has_any_time_value($existingLog)
                                    && !attendance_times_match_full_official($existingLog, $ot)) {
                                    if ($stmtTagPreserveTimes->execute([$holidayId, $remarks, $existingLog['id']])) {
                                        $logsUpdated++;
                                    } else {
                                        $errorInfo = $stmtTagPreserveTimes->errorInfo();
                                        error_log("Error tagging half-day holiday preserve-times for employee_id=$employeeId, date=$date: " . print_r($errorInfo, true));
                                    }
                                } elseif ($halfPeriod === 'afternoon') {
                                    if ($stmtUpdateHalfPm->execute([$lunchIn, $timeOut, $remarks, $holidayId, $existingLog['id']])) {
                                        $logsUpdated++;
                                    } else {
                                        $errorInfo = $stmtUpdateHalfPm->errorInfo();
                                        error_log("Error updating half-day PM holiday log for employee_id=$employeeId, date=$date: " . print_r($errorInfo, true));
                                    }
                                } else {
                                    if ($stmtUpdateHalfAm->execute([$timeIn, $lunchOut, $remarks, $holidayId, $existingLog['id']])) {
                                        $logsUpdated++;
                                    } else {
                                        $errorInfo = $stmtUpdateHalfAm->errorInfo();
                                        error_log("Error updating half-day AM holiday log for employee_id=$employeeId, date=$date: " . print_r($errorInfo, true));
                                    }
                                }
                            } else {
                                if ($stmtUpdateFull->execute([$timeIn, $lunchOut, $lunchIn, $timeOut, $remarks, $holidayId, $existingLog['id']])) {
                                    $logsUpdated++;
                                } else {
                                    $errorInfo = $stmtUpdateFull->errorInfo();
                                    error_log("Error updating holiday log for employee_id=$employeeId, date=$date: " . print_r($errorInfo, true));
                                }
                            }
                        } else {
                            // Employee has actual attendance (came in on holiday) - set holiday_id without overwriting times
                            if ($stmtTagHoliday->execute([$holidayId, $remarks . ' ', $existingLog['id']])) {
                                $logsUpdated++;
                            }
                        }
                    } else {
                        if (!$hasOfficial) {
                            if ($stmtInsertHolidayOnly->execute([$employeeId, $date, $remarks, $holidayId])) {
                                $logsCreated++;
                            } else {
                                $errorInfo = $stmtInsertHolidayOnly->errorInfo();
                                error_log("Error inserting holiday-only log for employee_id=$employeeId, date=$date: " . print_r($errorInfo, true));
                            }
                        } elseif ($isHalfDay) {
                            if ($halfPeriod === 'afternoon') {
                                if ($stmtInsertHolidayLog->execute([$employeeId, $date, null, null, $lunchIn, $timeOut, $remarks, $holidayId])) {
                                    $logsCreated++;
                                } else {
                                    $errorInfo = $stmtInsertHolidayLog->errorInfo();
                                    error_log("Error inserting half-day PM holiday log for employee_id=$employeeId, date=$date: " . print_r($errorInfo, true));
                                }
                            } else {
                                if ($stmtInsertHolidayLog->execute([$employeeId, $date, $timeIn, $lunchOut, null, null, $remarks, $holidayId])) {
                                    $logsCreated++;
                                } else {
                                    $errorInfo = $stmtInsertHolidayLog->errorInfo();
                                    error_log("Error inserting half-day AM holiday log for employee_id=$employeeId, date=$date: " . print_r($errorInfo, true));
                                }
                            }
                        } else {
                            if ($stmtInsertHolidayLog->execute([$employeeId, $date, $timeIn, $lunchOut, $lunchIn, $timeOut, $remarks, $holidayId])) {
                                $logsCreated++;
                            } else {
                                $errorInfo = $stmtInsertHolidayLog->errorInfo();
                                error_log("Error inserting holiday log for employee_id=$employeeId, date=$date: " . print_r($errorInfo, true));
                            }
                        }
                    }
                }
                error_log("Holiday logs created: $logsCreated, updated: $logsUpdated for date: $date");
            }
        } catch (Exception $e) {
            error_log("Error creating holiday attendance logs: " . $e->getMessage());
            throw $e;
        }
    }
    
    // Helper function to get official times for an employee on a specific date
    function getOfficialTimesForDate($employeeId, $date, $db) {
        // Get weekday from date
        $dateObj = new DateTime($date);
        $dayOfWeek = $dateObj->format('w'); // 0 (Sunday) to 6 (Saturday)
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $weekday = $weekdays[$dayOfWeek];
        
        // Find official time for this date range and weekday
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
            $sched = official_schedule_from_official_time_row($officialTime, $date);
            if ($sched !== null) {
                return $sched;
            }
        }
        
        // Default times if no official time found
        return [
            'found' => false,
            'time_in' => '08:00:00',
            'lunch_out' => '12:00:00',
            'lunch_in' => '13:00:00',
            'time_out' => '17:00:00'
        ];
    }

    /** @return array{found:bool,time_in:mixed,lunch_out:mixed,lunch_in:mixed,time_out:mixed}|null */
    function official_schedule_from_official_time_row(array $officialTime, $date) {
        $logDate = new DateTime($date);
        $logDate->setTime(0, 0, 0);
        $startDate = new DateTime($officialTime['start_date']);
        $startDate->setTime(0, 0, 0);
        $endDate = $officialTime['end_date'] ? new DateTime($officialTime['end_date']) : null;
        if ($endDate) {
            $endDate->setTime(0, 0, 0);
        }
        $isInRange = ($logDate >= $startDate) && ($endDate === null || $logDate <= $endDate);
        if (!$isInRange) {
            return null;
        }
        return [
            'found' => true,
            'time_in' => $officialTime['time_in'],
            'lunch_out' => ($officialTime['lunch_out'] && $officialTime['lunch_out'] != '00:00:00') ? $officialTime['lunch_out'] : '12:00:00',
            'lunch_in' => ($officialTime['lunch_in'] && $officialTime['lunch_in'] != '00:00:00') ? $officialTime['lunch_in'] : '13:00:00',
            'time_out' => $officialTime['time_out']
        ];
    }

    /**
     * One query per date instead of one per employee (major speedup for Add Holiday).
     * Keys are string employee_id values matching faculty_profiles.employee_id.
     */
    function getOfficialTimesBatchForDate(array $allEmployees, $date, $db) {
        $defaultOt = [
            'found' => false,
            'time_in' => '08:00:00',
            'lunch_out' => '12:00:00',
            'lunch_in' => '13:00:00',
            'time_out' => '17:00:00'
        ];
        $idList = [];
        foreach ($allEmployees as $emp) {
            $eid = $emp['employee_id'] ?? '';
            if ($eid !== '' && $eid !== null) {
                $idList[] = (string)$eid;
            }
        }
        $idList = array_values(array_unique($idList));
        $result = [];
        foreach ($idList as $eid) {
            $result[$eid] = $defaultOt;
        }
        if ($idList === []) {
            return $result;
        }
        $dateObj = new DateTime($date);
        $dayOfWeek = $dateObj->format('w');
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $weekday = $weekdays[(int)$dayOfWeek];
        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $sql = "SELECT * FROM employee_official_times
                WHERE employee_id IN ($placeholders)
                AND weekday = ?
                AND start_date <= ?
                AND (end_date IS NULL OR end_date >= ?)
                ORDER BY employee_id ASC, start_date DESC";
        $params = array_merge($idList, [$weekday, $date, $date]);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $resolved = [];
        foreach ($rows as $row) {
            $eid = (string)$row['employee_id'];
            if (isset($resolved[$eid])) {
                continue;
            }
            $sched = official_schedule_from_official_time_row($row, $date);
            $resolved[$eid] = true;
            $result[$eid] = $sched !== null ? $sched : $defaultOt;
        }
        return $result;
    }
    
    // Function to create TARF attendance logs based on employee's official time
    function createTarfAttendanceLogs($dates, $employeeIds, $db, $tarfIds = null) {
        try {
            // Ensure attendance_logs table has remarks field for TARF marking
            try {
                $db->exec("ALTER TABLE attendance_logs ADD COLUMN remarks VARCHAR(500) DEFAULT NULL");
            } catch (Exception $e) {
                // Column might already exist, ignore
            }
            
            // Ensure attendance_logs table has tarf_id field
            try {
                $db->exec("ALTER TABLE attendance_logs ADD COLUMN tarf_id INT DEFAULT NULL");
                $db->exec("ALTER TABLE attendance_logs ADD INDEX idx_tarf_id (tarf_id)");
            } catch (Exception $e) {
                // Column might already exist, ignore
            }
            
            // If tarfIds array is provided, use it; otherwise, fetch TARF IDs by date
            $tarfIdMap = [];
            if ($tarfIds && is_array($tarfIds)) {
                // Map dates to TARF IDs if provided
                foreach ($dates as $index => $date) {
                    if (isset($tarfIds[$index])) {
                        $tarfIdMap[$date] = $tarfIds[$index];
                    }
                }
            }
            
            foreach ($dates as $date) {
                // Get TARF ID for this date (use map if available, otherwise query)
                $tarfId = null;
                if (isset($tarfIdMap[$date])) {
                    $tarfId = $tarfIdMap[$date];
                } else {
                    $stmt = $db->prepare("SELECT id FROM tarf WHERE date = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$date]);
                    $tarfRow = $stmt->fetch();
                    $tarfId = $tarfRow ? $tarfRow['id'] : null;
                }
                
                foreach ($employeeIds as $employeeId) {
                    if (empty($employeeId)) {
                        continue;
                    }
                    
                    // Get official times for this employee on this date
                    $officialTimes = getOfficialTimesForDate($employeeId, $date, $db);
                    
                    // Check if attendance log already exists for this employee and date
                    // Don't check station_id for TARF logs as they should work across stations
                    $stmt = $db->prepare("SELECT id FROM attendance_logs WHERE employee_id = ? AND log_date = ?");
                    $stmt->execute([$employeeId, $date]);
                    $existingLog = $stmt->fetch();
                    
                    if ($existingLog) {
                        // Update existing log with TARF times based on official time and mark as TARF
                        $stmt = $db->prepare("
                            UPDATE attendance_logs 
                            SET time_in = ?,
                                lunch_out = ?,
                                lunch_in = ?,
                                time_out = ?,
                                remarks = 'TARF',
                                tarf_id = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $officialTimes['time_in'],
                            $officialTimes['lunch_out'],
                            $officialTimes['lunch_in'],
                            $officialTimes['time_out'],
                            $tarfId,
                            $existingLog['id']
                        ]);
                    } else {
                        // Create new TARF attendance log based on official time
                        // Note: station_id and timekeeper_id are NULL for TARF logs
                        // Use ON DUPLICATE KEY UPDATE to handle race conditions
                        $stmt = $db->prepare("
                            INSERT INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks, tarf_id, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, 'TARF', ?, NOW())
                            ON DUPLICATE KEY UPDATE 
                                time_in = VALUES(time_in),
                                lunch_out = VALUES(lunch_out),
                                lunch_in = VALUES(lunch_in),
                                time_out = VALUES(time_out),
                                remarks = VALUES(remarks),
                                tarf_id = VALUES(tarf_id)
                        ");
                        $stmt->execute([
                            $employeeId,
                            $date,
                            $officialTimes['time_in'],
                            $officialTimes['lunch_out'],
                            $officialTimes['lunch_in'],
                            $officialTimes['time_out'],
                            $tarfId
                        ]);
                    }
                }
            }
        } catch (Exception $e) {
            error_log("Error creating TARF attendance logs: " . $e->getMessage());
            // Don't throw - allow TARF creation to succeed even if log creation fails
        }
    }
    
    // Get Philippines holidays for current year
    function getPhilippinesHolidays($year) {
        $holidays = [];
        
        // Fixed holidays
        $fixedHolidays = [
            ['date' => "$year-01-01", 'title' => 'New Year\'s Day'],
            ['date' => "$year-02-25", 'title' => 'People Power Revolution'],
            ['date' => "$year-04-09", 'title' => 'Araw ng Kagitingan'],
            ['date' => "$year-05-01", 'title' => 'Labor Day'],
            ['date' => "$year-06-12", 'title' => 'Independence Day'],
            ['date' => "$year-08-21", 'title' => 'Ninoy Aquino Day'],
            ['date' => "$year-08-30", 'title' => 'National Heroes\' Day'],
            ['date' => "$year-11-30", 'title' => 'Bonifacio Day'],
            ['date' => "$year-12-25", 'title' => 'Christmas Day'],
            ['date' => "$year-12-30", 'title' => 'Rizal Day'],
        ];
        
        // Calculate Easter Sunday (Western Christian) using Anonymous Gregorian algorithm
        $a = $year % 19;
        $b = intval($year / 100);
        $c = $year % 100;
        $d = intval($b / 4);
        $e = $b % 4;
        $f = intval(($b + 8) / 25);
        $g = intval(($b - $f + 1) / 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intval($c / 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intval(($a + 11 * $h + 22 * $l) / 451);
        $month = intval(($h + $l - 7 * $m + 114) / 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;
        $easter = sprintf('%04d-%02d-%02d', $year, $month, $day);
        
        // Calculate movable holidays based on Easter
        $easterTimestamp = strtotime($easter);
        $holidays[] = ['date' => date('Y-m-d', strtotime('-2 days', $easterTimestamp)), 'title' => 'Good Friday'];
        
        // Add fixed holidays
        foreach ($fixedHolidays as $holiday) {
            $holidays[] = $holiday;
        }
        
        // Add other special observances
        $holidays[] = ['date' => "$year-11-01", 'title' => 'All Saints\' Day'];
        $holidays[] = ['date' => "$year-12-08", 'title' => 'Feast of the Immaculate Conception'];
        $holidays[] = ['date' => "$year-12-31", 'title' => 'New Year\'s Eve'];
        
        return $holidays;
    }
    
    if ($method === 'GET' && $action === 'events') {
        // Get events for calendar display (available to all authenticated users)
        // Check if user is authenticated (admin, faculty, or staff)
        if (!isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit();
        }
        
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-t');
        
        // Build scope filter for supervisor events (faculty/staff see events from their supervisor)
        $scopeSupervisorIds = [];
        if (isset($_SESSION['user_id'])) {
            if (function_exists('hasPardonOpenerAssignments') && hasPardonOpenerAssignments($_SESSION['user_id'], $db)) {
                $scopeSupervisorIds[] = (int)$_SESSION['user_id'];
            }
            if (function_exists('getOpenerUserIdsForEmployee')) {
                $stmtEmp = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
                $stmtEmp->execute([$_SESSION['user_id']]);
                $empRow = $stmtEmp->fetch(PDO::FETCH_ASSOC);
                if ($empRow && !empty(trim($empRow['employee_id'] ?? ''))) {
                    $openerIds = getOpenerUserIdsForEmployee(trim($empRow['employee_id']), $db);
                    foreach ($openerIds as $oid) {
                        if (!in_array($oid, $scopeSupervisorIds)) $scopeSupervisorIds[] = (int)$oid;
                    }
                }
            }
        }
        $scopeCondition = "AND (ce.scope_supervisor_id IS NULL" . (!empty($scopeSupervisorIds) ? " OR ce.scope_supervisor_id IN (" . implode(',', array_map('intval', $scopeSupervisorIds)) . ")" : "") . ")";
        
        // Get university + supervisor-scoped events (exclude archived)
        $stmt = $db->prepare("
            SELECT ce.id, ce.title, ce.description, ce.event_date, ce.event_time, ce.end_time, ce.location, ce.category, ce.event_type, ce.color, ce.is_philippines_holiday
            FROM calendar_events ce
            WHERE ce.event_date BETWEEN ? AND ? AND ce.is_archived = 0 " . $scopeCondition . "
            ORDER BY ce.event_date ASC, ce.event_time ASC
        ");
        $stmt->execute([$start, $end]);
        $events = $stmt->fetchAll();
        
        // Get TARF entries for the date range
        // Check if description and file_path columns exist
        $tarfs = [];
        try {
            $stmt = $db->query("SHOW TABLES LIKE 'tarf'");
            $tarfTableExists = $stmt->rowCount() > 0;
            
            if ($tarfTableExists) {
                $stmt = $db->query("SHOW COLUMNS FROM tarf LIKE 'description'");
                $hasDescription = $stmt->rowCount() > 0;
                $stmt = $db->query("SHOW COLUMNS FROM tarf LIKE 'file_path'");
                $hasFilePath = $stmt->rowCount() > 0;
                
                // Build query based on available columns
                if ($hasDescription && $hasFilePath) {
                    $tarfQuery = "
                        SELECT t.id, t.title, t.description, t.file_path, t.date, t.created_at,
                               GROUP_CONCAT(DISTINCT te.employee_id ORDER BY te.employee_id SEPARATOR ', ') as employee_ids,
                               COUNT(DISTINCT te.employee_id) as employee_count
                        FROM tarf t
                        LEFT JOIN tarf_employees te ON t.id = te.tarf_id
                        WHERE t.date BETWEEN ? AND ?
                        GROUP BY t.id, t.title, t.description, t.file_path, t.date, t.created_at
                        ORDER BY t.date ASC
                    ";
                } else {
                    // Fallback query without description and file_path
                    $tarfQuery = "
                        SELECT t.id, t.title, t.date, t.created_at,
                               GROUP_CONCAT(DISTINCT te.employee_id ORDER BY te.employee_id SEPARATOR ', ') as employee_ids,
                               COUNT(DISTINCT te.employee_id) as employee_count
                        FROM tarf t
                        LEFT JOIN tarf_employees te ON t.id = te.tarf_id
                        WHERE t.date BETWEEN ? AND ?
                        GROUP BY t.id, t.title, t.date, t.created_at
                        ORDER BY t.date ASC
                    ";
                }
                
                $stmt = $db->prepare($tarfQuery);
                $stmt->execute([$start, $end]);
                $tarfs = $stmt->fetchAll();
            }
        } catch (Exception $e) {
            // Table or columns don't exist yet, use empty array
            error_log("TARF query error in events action: " . $e->getMessage());
            $tarfs = [];
        }
        
        // Get Holidays for the date range
        $holidays = [];
        try {
            $stmt = $db->prepare("
                SELECT id, title, date, created_at, COALESCE(is_half_day, 0) AS is_half_day, half_day_period
                FROM holidays
                WHERE date BETWEEN ? AND ?
                ORDER BY date ASC
            ");
            $stmt->execute([$start, $end]);
            $holidays = $stmt->fetchAll();
        } catch (Exception $e) {
            // Table might not exist yet, initialize empty array
            $holidays = [];
        }
        
        // Get current year from start date
        $currentYear = date('Y', strtotime($start));
        
        // Get Philippines holidays
        $phHolidays = getPhilippinesHolidays($currentYear);
        
        // Format events for FullCalendar
        $formattedEvents = [];
        
        // Add Philippines holidays
        foreach ($phHolidays as $holiday) {
            $formattedEvents[] = [
                'id' => 'ph_holiday_' . str_replace('-', '', $holiday['date']),
                'title' => $holiday['title'],
                'start' => $holiday['date'],
                'allDay' => true,
                'backgroundColor' => '#dc3545',
                'borderColor' => '#dc3545',
                'textColor' => '#ffffff',
                'eventType' => 'holiday',
                'isPhilippinesHoliday' => true
            ];
        }
        
        // Add university events
        foreach ($events as $event) {
            $startDateTime = $event['event_date'];
            if ($event['event_time']) {
                $startDateTime .= 'T' . $event['event_time'];
            }
            
            $endDateTime = null;
            if ($event['end_time']) {
                $endDateTime = $event['event_date'] . 'T' . $event['end_time'];
            }
            
            // Determine color based on category or use stored color
            $color = $event['color'] ?? '#007bff';
            if ($event['category']) {
                $colorMap = [
                    'Training' => '#9c27b0',
                    'Workshop' => '#2196f3',
                    'Seminar' => '#4caf50',
                    'Conference' => '#f44336',
                    'Holiday' => '#dc3545'
                ];
                $color = $colorMap[$event['category']] ?? $color;
            }
            
            $formattedEvents[] = [
                'id' => $event['id'],
                'title' => $event['title'],
                'description' => $event['description'],
                'start' => $startDateTime,
                'end' => $endDateTime,
                'allDay' => !$event['event_time'],
                'backgroundColor' => $color,
                'borderColor' => $color,
                'textColor' => '#ffffff',
                'eventType' => $event['event_type'],
                'category' => $event['category'],
                'location' => $event['location'],
                'eventTime' => $event['event_time'],
                'endTime' => $event['end_time'],
                'isPhilippinesHoliday' => (bool)$event['is_philippines_holiday']
            ];
        }
        
        // Add TARF entries as calendar events
        foreach ($tarfs as $tarf) {
            $employeeCount = (int)$tarf['employee_count'];
            $employeeText = $employeeCount === 1 ? 'employee' : 'employees';
            $tarfTitle = $tarf['title'] . ' (' . $employeeCount . ' ' . $employeeText . ')';
            
            $tarfDescription = $tarf['description'] ?? '';
            $descriptionText = 'TARF: ' . $tarf['title'];
            if ($tarfDescription) {
                $descriptionText .= ' - ' . $tarfDescription;
            }
            if ($tarf['employee_ids']) {
                $descriptionText .= ' - Employees: ' . $tarf['employee_ids'];
            }
            
            $formattedEvents[] = [
                'id' => 'tarf_' . $tarf['id'],
                'title' => $tarfTitle,
                'description' => $descriptionText,
                'tarfDescription' => $tarfDescription,
                'filePath' => $tarf['file_path'] ?? null,
                'start' => $tarf['date'],
                'allDay' => true,
                'backgroundColor' => '#28a745', // Green color for TARF
                'borderColor' => '#28a745',
                'textColor' => '#ffffff',
                'eventType' => 'tarf',
                'category' => 'TARF',
                'isTARF' => true,
                'tarfId' => $tarf['id'],
                'employeeCount' => $employeeCount,
                'employeeIds' => $tarf['employee_ids']
            ];
        }
        
        // Add Holidays as calendar events
        foreach ($holidays as $holiday) {
            $isHalf = !empty($holiday['is_half_day']);
            $hp = ($holiday['half_day_period'] ?? 'morning') === 'afternoon' ? 'afternoon' : 'morning';
            if ($isHalf) {
                $suffix = ($hp === 'afternoon') ? ' (Half-day PM — all employees)' : ' (Half-day AM — all employees)';
                $descHalf = ($hp === 'afternoon')
                    ? 'Half-day PM (afternoon): '
                    : 'Half-day AM (morning): ';
            } else {
                $suffix = ' (Holiday - All Employees)';
                $descHalf = 'Holiday: ';
            }
            $formattedEvents[] = [
                'id' => 'holiday_' . $holiday['id'],
                'title' => $holiday['title'] . $suffix,
                'description' => $descHalf . $holiday['title'] . ' — Credits apply when the employee has official time for that day or actual time entries.',
                'start' => $holiday['date'],
                'allDay' => true,
                'backgroundColor' => $isHalf ? '#e8a317' : '#ffc107',
                'borderColor' => $isHalf ? '#bf360c' : '#ffc107',
                'textColor' => '#000000',
                'eventType' => 'holiday',
                'category' => 'Holiday',
                'isHoliday' => true,
                'isHalfDayHoliday' => $isHalf,
                'halfDayPeriod' => $isHalf ? $hp : null,
                'holidayId' => $holiday['id']
            ];
        }
        
        echo json_encode($formattedEvents);
        
    } elseif ($method === 'GET' && $action === 'event_list') {
        // Get event list for sidebar display
        if (!isLoggedIn()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Authentication required']);
            exit();
        }
        
        $start = $_GET['start'] ?? date('Y-m-01');
        $end = $_GET['end'] ?? date('Y-m-t');
        
        // Build scope filter for supervisor events (faculty/staff see events from their supervisor)
        $scopeSupervisorIds = [];
        if (isset($_SESSION['user_id'])) {
            if (function_exists('hasPardonOpenerAssignments') && hasPardonOpenerAssignments($_SESSION['user_id'], $db)) {
                $scopeSupervisorIds[] = (int)$_SESSION['user_id'];
            }
            if (function_exists('getOpenerUserIdsForEmployee')) {
                $stmtEmp = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ? LIMIT 1");
                $stmtEmp->execute([$_SESSION['user_id']]);
                $empRow = $stmtEmp->fetch(PDO::FETCH_ASSOC);
                if ($empRow && !empty(trim($empRow['employee_id'] ?? ''))) {
                    $openerIds = getOpenerUserIdsForEmployee(trim($empRow['employee_id']), $db);
                    foreach ($openerIds as $oid) {
                        if (!in_array($oid, $scopeSupervisorIds)) $scopeSupervisorIds[] = (int)$oid;
                    }
                }
            }
        }
        $scopeCondition = "AND (ce.scope_supervisor_id IS NULL" . (!empty($scopeSupervisorIds) ? " OR ce.scope_supervisor_id IN (" . implode(',', array_map('intval', $scopeSupervisorIds)) . ")" : "") . ")";
        
        // Get university + supervisor-scoped events (exclude archived)
        $stmt = $db->prepare("
            SELECT ce.id, ce.title, ce.description, ce.event_date, ce.event_time, ce.end_time, ce.location, ce.category, ce.event_type, ce.color, ce.is_philippines_holiday
            FROM calendar_events ce
            WHERE ce.event_date BETWEEN ? AND ? AND ce.is_archived = 0 " . $scopeCondition . "
            ORDER BY ce.event_date ASC, ce.event_time ASC
        ");
        $stmt->execute([$start, $end]);
        $events = $stmt->fetchAll();
        
        // Get TARF entries for the date range
        // Check if description and file_path columns exist
        try {
            $stmt = $db->query("SHOW COLUMNS FROM tarf LIKE 'description'");
            $hasDescription = $stmt->rowCount() > 0;
            $stmt = $db->query("SHOW COLUMNS FROM tarf LIKE 'file_path'");
            $hasFilePath = $stmt->rowCount() > 0;
        } catch (Exception $e) {
            $hasDescription = false;
            $hasFilePath = false;
        }
        
        // Build query based on available columns
        if ($hasDescription && $hasFilePath) {
            $tarfQuery = "
                SELECT t.id, t.title, t.description, t.file_path, t.date, t.created_at,
                       GROUP_CONCAT(DISTINCT te.employee_id ORDER BY te.employee_id SEPARATOR ', ') as employee_ids,
                       COUNT(DISTINCT te.employee_id) as employee_count
                FROM tarf t
                LEFT JOIN tarf_employees te ON t.id = te.tarf_id
                WHERE t.date BETWEEN ? AND ?
                GROUP BY t.id, t.title, t.description, t.file_path, t.date, t.created_at
                ORDER BY t.date ASC
            ";
        } else {
            // Fallback query without description and file_path
            $tarfQuery = "
                SELECT t.id, t.title, t.date, t.created_at,
                       GROUP_CONCAT(DISTINCT te.employee_id ORDER BY te.employee_id SEPARATOR ', ') as employee_ids,
                       COUNT(DISTINCT te.employee_id) as employee_count
                FROM tarf t
                LEFT JOIN tarf_employees te ON t.id = te.tarf_id
                WHERE t.date BETWEEN ? AND ?
                GROUP BY t.id, t.title, t.date, t.created_at
                ORDER BY t.date ASC
            ";
        }
        
        $stmt = $db->prepare($tarfQuery);
        $stmt->execute([$start, $end]);
        $tarfs = $stmt->fetchAll();
        
        // Get Holidays for the date range
        try {
            $stmt = $db->prepare("
                SELECT id, title, date, created_at, COALESCE(is_half_day, 0) AS is_half_day, half_day_period
                FROM holidays
                WHERE date BETWEEN ? AND ?
                ORDER BY date ASC
            ");
            $stmt->execute([$start, $end]);
            $holidays = $stmt->fetchAll();
        } catch (Exception $e) {
            // Table might not exist yet, initialize empty array
            $holidays = [];
        }
        
        // Get current year from start date
        $currentYear = date('Y', strtotime($start));
        
        // Get Philippines holidays
        $phHolidays = getPhilippinesHolidays($currentYear);
        
        // Format events for list display
        $formattedEvents = [];
        
        // Add Philippines holidays
        foreach ($phHolidays as $holiday) {
            $holidayDate = new DateTime($holiday['date']);
            $holidayDate->setTime(0, 0, 0);
            $now = new DateTime();
            $now->setTime(0, 0, 0);
            
            $daysDiff = $now->diff($holidayDate)->days;
            if ($holidayDate < $now) {
                $daysDiff = -$daysDiff;
            }
            
            $status = 'upcoming';
            $statusText = '';
            if ($daysDiff < 0) {
                $status = 'past';
            } elseif ($daysDiff === 0) {
                $status = 'today';
                $statusText = 'Today';
            } elseif ($daysDiff <= 2) {
                $status = 'soon';
                $statusText = $daysDiff === 1 ? 'Tomorrow' : "In $daysDiff days";
            } elseif ($daysDiff <= 7) {
                $status = 'in_days';
                $statusText = "In $daysDiff days";
            }
            
            $formattedEvents[] = [
                'id' => 'ph_holiday_' . str_replace('-', '', $holiday['date']),
                'title' => $holiday['title'],
                'date' => $holiday['date'],
                'time' => null,
                'endTime' => null,
                'location' => 'Philippines',
                'category' => 'Holiday',
                'color' => '#dc3545',
                'description' => 'Philippines National Holiday',
                'isPhilippinesHoliday' => true,
                'status' => $status,
                'statusText' => $statusText
            ];
        }
        
        // Add university events
        foreach ($events as $event) {
            $eventDate = new DateTime($event['event_date']);
            $eventDate->setTime(0, 0, 0);
            $now = new DateTime();
            $now->setTime(0, 0, 0);
            
            $daysDiff = $now->diff($eventDate)->days;
            if ($eventDate < $now) {
                $daysDiff = -$daysDiff;
            }
            
            $status = 'upcoming';
            $statusText = '';
            if ($daysDiff < 0) {
                $status = 'past';
            } elseif ($daysDiff === 0) {
                $status = 'today';
                $statusText = 'Today';
            } elseif ($daysDiff <= 2) {
                $status = 'soon';
                $statusText = $daysDiff === 1 ? 'Tomorrow' : "In $daysDiff days";
            } elseif ($daysDiff <= 7) {
                $status = 'in_days';
                $statusText = "In $daysDiff days";
            }
            
            $color = $event['color'] ?? '#007bff';
            if ($event['category']) {
                $colorMap = [
                    'Training' => '#9c27b0',
                    'Workshop' => '#2196f3',
                    'Seminar' => '#4caf50',
                    'Conference' => '#f44336',
                    'Holiday' => '#dc3545'
                ];
                $color = $colorMap[$event['category']] ?? $color;
            }
            
            $formattedEvents[] = [
                'id' => $event['id'],
                'title' => $event['title'],
                'description' => $event['description'],
                'date' => $event['event_date'],
                'time' => $event['event_time'],
                'endTime' => $event['end_time'],
                'location' => $event['location'],
                'category' => $event['category'] ?? 'University Event',
                'color' => $color,
                'isPhilippinesHoliday' => false,
                'status' => $status,
                'statusText' => $statusText
            ];
        }
        
        // Add TARF entries to event list
        foreach ($tarfs as $tarf) {
            $tarfDate = new DateTime($tarf['date']);
            $tarfDate->setTime(0, 0, 0);
            $now = new DateTime();
            $now->setTime(0, 0, 0);
            
            $daysDiff = $now->diff($tarfDate)->days;
            if ($tarfDate < $now) {
                $daysDiff = -$daysDiff;
            }
            
            $status = 'upcoming';
            $statusText = '';
            if ($daysDiff < 0) {
                $status = 'past';
            } elseif ($daysDiff === 0) {
                $status = 'today';
                $statusText = 'Today';
            } elseif ($daysDiff <= 2) {
                $status = 'soon';
                $statusText = $daysDiff === 1 ? 'Tomorrow' : "In $daysDiff days";
            } elseif ($daysDiff <= 7) {
                $status = 'in_days';
                $statusText = "In $daysDiff days";
            }
            
            $employeeCount = (int)$tarf['employee_count'];
            $employeeText = $employeeCount === 1 ? 'employee' : 'employees';
            $tarfTitle = $tarf['title'] . ' (' . $employeeCount . ' ' . $employeeText . ')';
            
            $tarfDescription = $tarf['description'] ?? '';
            $descriptionText = 'TARF: ' . $tarf['title'];
            if ($tarfDescription) {
                $descriptionText .= ' - ' . $tarfDescription;
            }
            if ($tarf['employee_ids']) {
                $descriptionText .= ' - Employees: ' . $tarf['employee_ids'];
            }
            
            $formattedEvents[] = [
                'id' => 'tarf_' . $tarf['id'],
                'title' => $tarfTitle,
                'description' => $descriptionText,
                'tarfDescription' => $tarfDescription,
                'filePath' => $tarf['file_path'] ?? null,
                'date' => $tarf['date'],
                'time' => null,
                'endTime' => null,
                'location' => null,
                'category' => 'TARF',
                'color' => '#28a745', // Green color for TARF
                'isPhilippinesHoliday' => false,
                'isTARF' => true,
                'tarfId' => $tarf['id'],
                'employeeCount' => $employeeCount,
                'employeeIds' => $tarf['employee_ids'],
                'status' => $status,
                'statusText' => $statusText
            ];
        }
        
        // Add Holidays to event list
        foreach ($holidays as $holiday) {
            $holidayDate = new DateTime($holiday['date']);
            $holidayDate->setTime(0, 0, 0);
            $now = new DateTime();
            $now->setTime(0, 0, 0);
            
            $daysDiff = $now->diff($holidayDate)->days;
            if ($holidayDate < $now) {
                $daysDiff = -$daysDiff;
            }
            
            $status = 'upcoming';
            $statusText = '';
            if ($daysDiff < 0) {
                $status = 'past';
            } elseif ($daysDiff === 0) {
                $status = 'today';
                $statusText = 'Today';
            } elseif ($daysDiff <= 2) {
                $status = 'soon';
                $statusText = $daysDiff === 1 ? 'Tomorrow' : "In $daysDiff days";
            } elseif ($daysDiff <= 7) {
                $status = 'in_days';
                $statusText = "In $daysDiff days";
            }
            
            $isHalf = !empty($holiday['is_half_day']);
            $hp = ($holiday['half_day_period'] ?? 'morning') === 'afternoon' ? 'afternoon' : 'morning';
            if ($isHalf) {
                $suffix = ($hp === 'afternoon') ? ' (Half-day PM — all employees)' : ' (Half-day AM — all employees)';
                $descHalf = ($hp === 'afternoon') ? 'Half-day PM (afternoon): ' : 'Half-day AM (morning): ';
            } else {
                $suffix = ' (Holiday - All Employees)';
                $descHalf = 'Holiday: ';
            }
            $formattedEvents[] = [
                'id' => 'holiday_' . $holiday['id'],
                'title' => $holiday['title'] . $suffix,
                'description' => $descHalf . $holiday['title'] . ' — Credits apply when the employee has official time for that day or actual time entries.',
                'date' => $holiday['date'],
                'time' => null,
                'endTime' => null,
                'location' => null,
                'category' => 'Holiday',
                'color' => $isHalf ? '#e8a317' : '#ffc107',
                'isPhilippinesHoliday' => false,
                'isHoliday' => true,
                'isHalfDayHoliday' => $isHalf,
                'halfDayPeriod' => $isHalf ? $hp : null,
                'holidayId' => $holiday['id'],
                'status' => $status,
                'statusText' => $statusText
            ];
        }
        
        // Sort by date and time
        usort($formattedEvents, function($a, $b) {
            if ($a['date'] === $b['date']) {
                if ($a['time'] && $b['time']) {
                    return strcmp($a['time'], $b['time']);
                }
                return $a['time'] ? -1 : 1;
            }
            return strcmp($a['date'], $b['date']);
        });
        
        echo json_encode(['success' => true, 'events' => $formattedEvents]);
        
    } elseif ($method === 'POST' && $action === 'create') {
        // Create event (admin only)
        requireAdmin();
        
        $title = sanitizeInput($_POST['title'] ?? '');
        $description = sanitizeInput($_POST['description'] ?? '');
        $eventDate = $_POST['event_date'] ?? '';
        $eventTime = $_POST['event_time'] ?? null;
        $endTime = $_POST['end_time'] ?? null;
        $location = sanitizeInput($_POST['location'] ?? '');
        $category = sanitizeInput($_POST['category'] ?? 'university_event');
        $eventType = $_POST['event_type'] ?? 'university_event';
        $color = sanitizeInput($_POST['color'] ?? '#007bff');
        
        if (empty($title) || empty($eventDate)) {
            echo json_encode(['success' => false, 'message' => 'Title and event date are required']);
            exit();
        }
        
        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date format']);
            exit();
        }
        
        // Validate time if provided
        if ($eventTime && !preg_match('/^\d{2}:\d{2}$/', $eventTime)) {
            echo json_encode(['success' => false, 'message' => 'Invalid time format']);
            exit();
        }
        
        if ($endTime && !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            echo json_encode(['success' => false, 'message' => 'Invalid end time format']);
            exit();
        }
        
        $stmt = $db->prepare("
            INSERT INTO calendar_events (title, description, event_date, event_time, end_time, location, category, event_type, color, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$title, $description, $eventDate, $eventTime, $endTime, $location, $category, $eventType, $color, $_SESSION['user_id']])) {
            $eventId = $db->lastInsertId();
            logAction('CALENDAR_EVENT_CREATE', "Created calendar event: $title on $eventDate");
            
            // Send email notifications and in-app notifications to faculty/staff
            try {
                $mailer = new Mailer();
                $notificationManager = getNotificationManager();
                
                // Get all active faculty and staff members
                $stmt = $db->prepare("SELECT id, email, first_name, last_name FROM users WHERE user_type IN ('faculty', 'staff') AND is_verified = 1 AND is_active = 1");
                $stmt->execute();
                $facultyStaff = $stmt->fetchAll();
                
                $emailCount = 0;
                $notificationCount = 0;
                
                // Format event details for notification
                $dateObj = new DateTime($eventDate);
                $formattedDate = $dateObj->format('F j, Y');
                $notificationMessage = "New event: {$title} on {$formattedDate}";
                if ($eventTime) {
                    $timeDisplay = date('g:i A', strtotime($eventTime));
                    if ($endTime) {
                        $timeDisplay .= ' - ' . date('g:i A', strtotime($endTime));
                    }
                    $notificationMessage .= " at {$timeDisplay}";
                }
                if ($location) {
                    $notificationMessage .= ". Location: {$location}";
                }
                
                foreach ($facultyStaff as $member) {
                    $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                    if (empty($memberName)) {
                        $memberName = 'Faculty/Staff';
                    }
                    
                    // Send email notification
                    try {
                        $mailer->sendCalendarEventNotification(
                            $member['email'],
                            $memberName,
                            $title,
                            $eventDate,
                            $eventTime,
                            $endTime,
                            $location,
                            $description,
                            $category
                        );
                        $emailCount++;
                    } catch (Exception $e) {
                        error_log("Error sending calendar event email to user ID {$member['id']} ({$member['email']}): " . $e->getMessage());
                    }
                    
                    // Send in-app notification
                    try {
                        $notificationManager->createNotification(
                            $member['id'],
                            'calendar_event',
                            '📅 New Calendar Event',
                            $notificationMessage,
                            '../admin/calendar.php',
                            'normal'
                        );
                        $notificationCount++;
                    } catch (Exception $e) {
                        error_log("Error sending calendar event in-app notification to user ID {$member['id']}: " . $e->getMessage());
                    }
                }
                
                if ($emailCount > 0 || $notificationCount > 0) {
                    error_log("Calendar event notifications sent: {$emailCount} emails, {$notificationCount} in-app notifications");
                }
            } catch (Exception $e) {
                // Log error but don't fail the event creation
                error_log("Error sending calendar event notifications: " . $e->getMessage());
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Event created successfully',
                'eventId' => $eventId
            ]);
        } else {
            throw new Exception('Failed to create event');
        }
        
    } elseif ($method === 'POST' && $action === 'update') {
        // Update event (admin only)
        requireAdmin();
        
        $eventId = (int)($_POST['event_id'] ?? 0);
        $title = sanitizeInput($_POST['title'] ?? '');
        $description = sanitizeInput($_POST['description'] ?? '');
        $eventDate = $_POST['event_date'] ?? '';
        $eventTime = $_POST['event_time'] ?? null;
        $endTime = $_POST['end_time'] ?? null;
        $location = sanitizeInput($_POST['location'] ?? '');
        $category = sanitizeInput($_POST['category'] ?? 'university_event');
        $eventType = $_POST['event_type'] ?? 'university_event';
        $color = sanitizeInput($_POST['color'] ?? '#007bff');
        
        if (!$eventId || empty($title) || empty($eventDate)) {
            echo json_encode(['success' => false, 'message' => 'Event ID, title, and event date are required']);
            exit();
        }
        
        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDate)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date format']);
            exit();
        }
        
        // Validate time if provided
        if ($eventTime && !preg_match('/^\d{2}:\d{2}$/', $eventTime)) {
            echo json_encode(['success' => false, 'message' => 'Invalid time format']);
            exit();
        }
        
        if ($endTime && !preg_match('/^\d{2}:\d{2}$/', $endTime)) {
            echo json_encode(['success' => false, 'message' => 'Invalid end time format']);
            exit();
        }
        
        // Check if event exists
        $stmt = $db->prepare("SELECT id FROM calendar_events WHERE id = ?");
        $stmt->execute([$eventId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Event not found']);
            exit();
        }
        
        $stmt = $db->prepare("
            UPDATE calendar_events
            SET title = ?, description = ?, event_date = ?, event_time = ?, end_time = ?, location = ?, category = ?, event_type = ?, color = ?, updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        
        if ($stmt->execute([$title, $description, $eventDate, $eventTime, $endTime, $location, $category, $eventType, $color, $eventId])) {
            logAction('CALENDAR_EVENT_UPDATE', "Updated calendar event ID: $eventId - $title");
            
            echo json_encode([
                'success' => true,
                'message' => 'Event updated successfully'
            ]);
        } else {
            throw new Exception('Failed to update event');
        }
        
    } elseif ($method === 'POST' && $action === 'archive') {
        // Archive event (admin only)
        requireAdmin();
        
        $eventId = (int)($_POST['event_id'] ?? 0);
        
        if (!$eventId) {
            echo json_encode(['success' => false, 'message' => 'Event ID is required']);
            exit();
        }
        
        // Get event title for logging
        $stmt = $db->prepare("SELECT title FROM calendar_events WHERE id = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        
        if (!$event) {
            echo json_encode(['success' => false, 'message' => 'Event not found']);
            exit();
        }
        
        $stmt = $db->prepare("UPDATE calendar_events SET is_archived = 1 WHERE id = ?");
        
        if ($stmt->execute([$eventId])) {
            logAction('CALENDAR_EVENT_ARCHIVE', "Archived calendar event ID: $eventId - {$event['title']}");
            
            echo json_encode([
                'success' => true,
                'message' => 'Event archived successfully'
            ]);
        } else {
            throw new Exception('Failed to archive event');
        }
        
    } elseif ($method === 'POST' && $action === 'delete') {
        // Delete event (admin only)
        requireAdmin();
        
        $eventId = (int)($_POST['event_id'] ?? 0);
        
        if (!$eventId) {
            echo json_encode(['success' => false, 'message' => 'Event ID is required']);
            exit();
        }
        
        // Get event title for logging
        $stmt = $db->prepare("SELECT title FROM calendar_events WHERE id = ?");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();
        
        if (!$event) {
            echo json_encode(['success' => false, 'message' => 'Event not found']);
            exit();
        }
        
        $stmt = $db->prepare("DELETE FROM calendar_events WHERE id = ?");
        
        if ($stmt->execute([$eventId])) {
            logAction('CALENDAR_EVENT_DELETE', "Deleted calendar event ID: $eventId - {$event['title']}");
            
            echo json_encode([
                'success' => true,
                'message' => 'Event deleted successfully'
            ]);
        } else {
            throw new Exception('Failed to delete event');
        }
        
    } elseif ($method === 'POST' && $action === 'create_tarf') {
        // Create TARF (admin only)
        requireAdmin();
        
        // Check if tables exist and create/alter them
        try {
            // Check if tarf table exists
            $stmt = $db->query("SHOW TABLES LIKE 'tarf'");
            $tarfExists = $stmt->rowCount() > 0;
            
            if (!$tarfExists) {
                // Create tarf table
                $db->exec("CREATE TABLE tarf (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    description TEXT,
                    file_path VARCHAR(500),
                    date DATE NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_date (date)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } else {
                // Check if AUTO_INCREMENT is set, if not, alter the table
                $stmt = $db->query("SHOW COLUMNS FROM tarf WHERE Field = 'id'");
                $column = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($column && strpos($column['Extra'], 'auto_increment') === false) {
                    // Get max ID to set AUTO_INCREMENT properly
                    $maxIdStmt = $db->query("SELECT COALESCE(MAX(id), 0) as max_id FROM tarf");
                    $maxIdRow = $maxIdStmt->fetch(PDO::FETCH_ASSOC);
                    $nextId = ($maxIdRow['max_id'] ?? 0) + 1;
                    
                    // Modify column to add AUTO_INCREMENT
                    $db->exec("ALTER TABLE tarf MODIFY id INT AUTO_INCREMENT PRIMARY KEY");
                    // Set AUTO_INCREMENT to next value
                    $db->exec("ALTER TABLE tarf AUTO_INCREMENT = $nextId");
                }
                // Add created_at column if it doesn't exist
                try {
                    $db->exec("ALTER TABLE tarf ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
                } catch (Exception $e) {
                    // Column might already exist, ignore
                }
                // Add description column if it doesn't exist
                try {
                    $db->exec("ALTER TABLE tarf ADD COLUMN description TEXT");
                } catch (Exception $e) {
                    // Column might already exist, ignore
                }
                // Add file_path column if it doesn't exist
                try {
                    $db->exec("ALTER TABLE tarf ADD COLUMN file_path VARCHAR(500)");
                } catch (Exception $e) {
                    // Column might already exist, ignore
                }
            }
            
            // Check if tarf_employees table exists
            $stmt = $db->query("SHOW TABLES LIKE 'tarf_employees'");
            $tarfEmployeesExists = $stmt->rowCount() > 0;
            
            if (!$tarfEmployeesExists) {
                // Create tarf_employees table
                $db->exec("CREATE TABLE tarf_employees (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tarf_id INT NOT NULL,
                    employee_id VARCHAR(50) NOT NULL,
                    FOREIGN KEY (tarf_id) REFERENCES tarf(id) ON DELETE CASCADE,
                    INDEX idx_tarf_id (tarf_id),
                    INDEX idx_employee_id (employee_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            } else {
                // Check if AUTO_INCREMENT is set, if not, alter the table
                $stmt = $db->query("SHOW COLUMNS FROM tarf_employees WHERE Field = 'id'");
                $column = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($column && strpos($column['Extra'], 'auto_increment') === false) {
                    // Get max ID to set AUTO_INCREMENT properly
                    $maxIdStmt = $db->query("SELECT COALESCE(MAX(id), 0) as max_id FROM tarf_employees");
                    $maxIdRow = $maxIdStmt->fetch(PDO::FETCH_ASSOC);
                    $nextId = ($maxIdRow['max_id'] ?? 0) + 1;
                    
                    // Modify column to add AUTO_INCREMENT
                    $db->exec("ALTER TABLE tarf_employees MODIFY id INT AUTO_INCREMENT PRIMARY KEY");
                    // Set AUTO_INCREMENT to next value
                    $db->exec("ALTER TABLE tarf_employees AUTO_INCREMENT = $nextId");
                }
                // Ensure foreign key exists
                try {
                    // Check if foreign key exists
                    $stmt = $db->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE 
                                       WHERE TABLE_SCHEMA = DATABASE() 
                                       AND TABLE_NAME = 'tarf_employees' 
                                       AND CONSTRAINT_NAME = 'tarf_employees_ibfk_1'");
                    if ($stmt->rowCount() == 0) {
                        $db->exec("ALTER TABLE tarf_employees ADD CONSTRAINT tarf_employees_ibfk_1 
                                   FOREIGN KEY (tarf_id) REFERENCES tarf(id) ON DELETE CASCADE");
                    }
                } catch (Exception $e) {
                    // Foreign key might already exist, ignore
                }
            }
        } catch (Exception $e) {
            // Log error but continue
            error_log("TARF table setup error: " . $e->getMessage());
        }
        
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $datesJson = $_POST['dates'] ?? '[]';
        $employeeIdsJson = $_POST['employee_ids'] ?? '[]';
        
        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'Title is required']);
            exit();
        }
        
        $dates = json_decode($datesJson, true);
        $employeeIds = json_decode($employeeIdsJson, true);
        
        if (empty($dates) || !is_array($dates)) {
            echo json_encode(['success' => false, 'message' => 'At least one date is required']);
            exit();
        }
        
        if (empty($employeeIds) || !is_array($employeeIds)) {
            echo json_encode(['success' => false, 'message' => 'At least one employee is required']);
            exit();
        }
        
        // Validate dates
        foreach ($dates as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                echo json_encode(['success' => false, 'message' => 'Invalid date format']);
                exit();
            }
        }
        
        // Handle file upload
        $filePath = null;
        if (isset($_FILES['tarf_file']) && $_FILES['tarf_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/tarf/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $file = $_FILES['tarf_file'];
            $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG']);
                exit();
            }
            
            // Generate unique filename
            $fileName = 'tarf_' . time() . '_' . uniqid() . '.' . $fileExtension;
            $targetPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $filePath = 'uploads/tarf/' . $fileName;
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
                exit();
            }
        }
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            $createdCount = 0;
            $employeeCount = count($employeeIds);
            $createdTarfIds = [];
            
            // Create TARF entry for each date
            foreach ($dates as $date) {
                $stmt = $db->prepare("INSERT INTO tarf (title, description, file_path, date) VALUES (?, ?, ?, ?)");
                if (!$stmt->execute([$title, $description ?: null, $filePath, $date])) {
                    throw new Exception('Failed to insert TARF record');
                }
                
                $tarfId = $db->lastInsertId();
                
                // Validate that we got a valid ID
                if (!$tarfId || $tarfId <= 0) {
                    throw new Exception('Failed to get TARF ID after insertion. Please ensure the tarf table has AUTO_INCREMENT enabled on the id column.');
                }
                
                $createdTarfIds[] = $tarfId;
                
                // Add employees to this TARF
                foreach ($employeeIds as $employeeId) {
                    if (empty($employeeId)) {
                        continue; // Skip empty employee IDs
                    }
                    $stmt = $db->prepare("INSERT INTO tarf_employees (tarf_id, employee_id) VALUES (?, ?)");
                    if (!$stmt->execute([$tarfId, $employeeId])) {
                        throw new Exception('Failed to insert TARF employee record');
                    }
                }
                
                $createdCount++;
            }
            
            $db->commit();
            
            // Auto-create 8-hour attendance logs for TARF employees
            createTarfAttendanceLogs($dates, $employeeIds, $db, $createdTarfIds);
            
            logAction('TARF_CREATE', "Created TARF: $title for " . count($dates) . " date(s) with $employeeCount employee(s)");
            
            // Send email and in-app notifications to selected TARF employees only
            try {
                $mailer = new Mailer();
                $notificationManager = getNotificationManager();
                
                // Get only the selected employees (those in the TARF) by joining through faculty_profiles
                if (empty($employeeIds)) {
                    $facultyStaff = [];
                } else {
                    // Create placeholders for IN clause
                    $placeholders = str_repeat('?,', count($employeeIds) - 1) . '?';
                    $stmt = $db->prepare("
                        SELECT DISTINCT u.id, u.email, u.first_name, u.last_name 
                        FROM users u
                        INNER JOIN faculty_profiles fp ON u.id = fp.user_id
                        WHERE fp.employee_id IN ($placeholders)
                        AND u.user_type IN ('faculty', 'staff') 
                        AND u.is_verified = 1 
                        AND u.is_active = 1
                    ");
                    $stmt->execute($employeeIds);
                    $facultyStaff = $stmt->fetchAll();
                }
                
                $emailCount = 0;
                $notificationCount = 0;
                
                // Format TARF details for notification
                $dateList = '';
                if (count($dates) == 1) {
                    $dateObj = new DateTime($dates[0]);
                    $dateList = $dateObj->format('F j, Y');
                } else {
                    $dateList = count($dates) . ' dates';
                }
                
                $notificationMessage = "New TARF: {$title} for {$dateList}. This TARF includes {$employeeCount} employee(s).";
                
                // Email subject and body
                $emailSubject = "New TARF Notification: " . $title;
                $emailBody = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #28a745; margin-top: 0;">📋 New TARF Created</h2>
    <p>A new TARF (Travel Authority Request Form) has been created:</p>
    <div style="background-color: #f5f5f5; padding: 15px; margin: 20px 0; border-left: 4px solid #28a745;">
        <p><strong>TARF Title:</strong> ' . htmlspecialchars($title) . '</p>
        <p><strong>Number of Dates:</strong> ' . count($dates) . '</p>
        <p><strong>Dates:</strong></p>
        <ul>';
        
                foreach ($dates as $date) {
                    $dateObj = new DateTime($date);
                    $emailBody .= '<li>' . htmlspecialchars($dateObj->format('F j, Y')) . '</li>';
                }
                
                $emailBody .= '
        </ul>
        <p><strong>Number of Employees:</strong> ' . $employeeCount . '</p>
    </div>
    <p>Please login to the faculty portal to view the full calendar and TARF details.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU Faculty and Staff System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';
                
                foreach ($facultyStaff as $member) {
                    $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                    if (empty($memberName)) {
                        $memberName = 'Faculty/Staff';
                    }
                    
                    // Send email notification
                    try {
                        $mailer->sendMail($member['email'], $memberName, $emailSubject, $emailBody, true);
                        $emailCount++;
                    } catch (Exception $e) {
                        error_log("Error sending TARF email to user ID {$member['id']} ({$member['email']}): " . $e->getMessage());
                    }
                    
                    // Send in-app notification
                    try {
                        $notificationManager->createNotification(
                            $member['id'],
                            'tarf',
                            '📋 New TARF Created',
                            $notificationMessage,
                            '../admin/calendar.php',
                            'normal'
                        );
                        $notificationCount++;
                    } catch (Exception $e) {
                        error_log("Error sending TARF in-app notification to user ID {$member['id']}: " . $e->getMessage());
                    }
                }
                
                if ($emailCount > 0 || $notificationCount > 0) {
                    error_log("TARF notifications sent: {$emailCount} emails, {$notificationCount} in-app notifications");
                }
            } catch (Exception $e) {
                // Log error but don't fail the TARF creation
                error_log("Error sending TARF notifications: " . $e->getMessage());
            }
            
            echo json_encode([
                'success' => true,
                'message' => "TARF created successfully for $createdCount date(s) with $employeeCount employee(s)"
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("TARF creation error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error creating TARF: ' . $e->getMessage()
            ]);
            exit();
        }
        
    } elseif ($method === 'GET' && $action === 'get_tarf') {
        // Get TARF details for editing (admin only)
        requireAdmin();
        
        $tarfId = (int)($_GET['tarf_id'] ?? 0);
        
        if (!$tarfId) {
            echo json_encode(['success' => false, 'message' => 'TARF ID is required']);
            exit();
        }
        
        try {
            // Check if description and file_path columns exist
            $hasDescription = false;
            $hasFilePath = false;
            try {
                $stmt = $db->query("SHOW COLUMNS FROM tarf LIKE 'description'");
                $hasDescription = $stmt->rowCount() > 0;
                $stmt = $db->query("SHOW COLUMNS FROM tarf LIKE 'file_path'");
                $hasFilePath = $stmt->rowCount() > 0;
            } catch (Exception $e) { /* ignore */ }
            
            $cols = ['id', 'title', 'date'];
            if ($hasDescription) $cols[] = 'description';
            if ($hasFilePath) $cols[] = 'file_path';
            $colList = implode(', ', $cols);
            
            $stmt = $db->prepare("SELECT $colList FROM tarf WHERE id = ?");
            $stmt->execute([$tarfId]);
            $tarf = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$tarf) {
                echo json_encode(['success' => false, 'message' => 'TARF not found']);
                exit();
            }
            
            // Get employees for this TARF
            $stmt = $db->prepare("SELECT employee_id FROM tarf_employees WHERE tarf_id = ?");
            $stmt->execute([$tarfId]);
            $employees = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            echo json_encode([
                'success' => true,
                'tarf' => [
                    'id' => $tarf['id'],
                    'title' => $tarf['title'],
                    'description' => $tarf['description'] ?? '',
                    'file_path' => $tarf['file_path'] ?? null,
                    'date' => $tarf['date'],
                    'employee_ids' => $employees
                ]
            ]);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        
    } elseif ($method === 'POST' && $action === 'update_tarf') {
        // Update TARF (admin only)
        requireAdmin();
        
        $tarfId = (int)($_POST['tarf_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $date = $_POST['date'] ?? '';
        $employeeIdsJson = $_POST['employee_ids'] ?? '[]';
        
        if (!$tarfId) {
            echo json_encode(['success' => false, 'message' => 'TARF ID is required']);
            exit();
        }
        
        if (empty($title)) {
            echo json_encode(['success' => false, 'message' => 'Title is required']);
            exit();
        }
        
        if (empty($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['success' => false, 'message' => 'Valid date is required']);
            exit();
        }
        
        $employeeIds = json_decode($employeeIdsJson, true);
        
        if (empty($employeeIds) || !is_array($employeeIds)) {
            echo json_encode(['success' => false, 'message' => 'At least one employee is required']);
            exit();
        }
        
        // Handle file upload
        $filePath = null;
        if (isset($_FILES['tarf_file']) && $_FILES['tarf_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/tarf/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $file = $_FILES['tarf_file'];
            $allowedExtensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (!in_array($fileExtension, $allowedExtensions)) {
                echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG']);
                exit();
            }
            
            // Generate unique filename
            $fileName = 'tarf_' . time() . '_' . uniqid() . '.' . $fileExtension;
            $targetPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $filePath = 'uploads/tarf/' . $fileName;
                
                // Delete old file if exists
                $stmt = $db->prepare("SELECT file_path FROM tarf WHERE id = ?");
                $stmt->execute([$tarfId]);
                $oldTarf = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($oldTarf && $oldTarf['file_path'] && file_exists('../' . $oldTarf['file_path'])) {
                    @unlink('../' . $oldTarf['file_path']);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to upload file']);
                exit();
            }
        } else {
            // Keep existing file path if no new file uploaded
            $stmt = $db->prepare("SELECT file_path FROM tarf WHERE id = ?");
            $stmt->execute([$tarfId]);
            $existingTarf = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($existingTarf) {
                $filePath = $existingTarf['file_path'];
            }
        }
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Update TARF entry
            if ($filePath !== null) {
                $stmt = $db->prepare("UPDATE tarf SET title = ?, description = ?, file_path = ?, date = ? WHERE id = ?");
                if (!$stmt->execute([$title, $description ?: null, $filePath, $date, $tarfId])) {
                    throw new Exception('Failed to update TARF record');
                }
            } else {
                $stmt = $db->prepare("UPDATE tarf SET title = ?, description = ?, date = ? WHERE id = ?");
                if (!$stmt->execute([$title, $description ?: null, $date, $tarfId])) {
                    throw new Exception('Failed to update TARF record');
                }
            }
            
            // Delete existing employees
            $stmt = $db->prepare("DELETE FROM tarf_employees WHERE tarf_id = ?");
            $stmt->execute([$tarfId]);
            
            // Add new employees
            foreach ($employeeIds as $employeeId) {
                if (empty($employeeId)) {
                    continue;
                }
                $stmt = $db->prepare("INSERT INTO tarf_employees (tarf_id, employee_id) VALUES (?, ?)");
                if (!$stmt->execute([$tarfId, $employeeId])) {
                    throw new Exception('Failed to update TARF employee record');
                }
            }
            
            $db->commit();
            
            // Auto-create/update 8-hour attendance log for TARF employee
            createTarfAttendanceLogs([$date], $employeeIds, $db);
            
            logAction('TARF_UPDATE', "Updated TARF ID: $tarfId - $title");
            
            echo json_encode([
                'success' => true,
                'message' => 'TARF updated successfully'
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            error_log("TARF update error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error updating TARF: ' . $e->getMessage()
            ]);
            exit();
        }
        
    } elseif ($method === 'POST' && $action === 'delete_tarf') {
        // Delete TARF (admin only)
        requireAdmin();
        
        $tarfId = (int)($_POST['tarf_id'] ?? 0);
        
        if (!$tarfId) {
            echo json_encode(['success' => false, 'message' => 'TARF ID is required']);
            exit();
        }
        
        try {
            // Get TARF title for logging
            $stmt = $db->prepare("SELECT title FROM tarf WHERE id = ?");
            $stmt->execute([$tarfId]);
            $tarf = $stmt->fetch();
            
            if (!$tarf) {
                echo json_encode(['success' => false, 'message' => 'TARF not found']);
                exit();
            }
            
            // Delete TARF (cascade will delete employees)
            $stmt = $db->prepare("DELETE FROM tarf WHERE id = ?");
            
            if ($stmt->execute([$tarfId])) {
                // Delete associated TARF attendance logs (or remove TARF marking)
                try {
                    // Remove TARF marking from attendance logs (set remarks to NULL and tarf_id to NULL)
                    $stmt = $db->prepare("
                        UPDATE attendance_logs 
                        SET remarks = NULL, tarf_id = NULL 
                        WHERE tarf_id = ?
                    ");
                    $stmt->execute([$tarfId]);
                } catch (Exception $e) {
                    // Ignore errors - columns might not exist
                    error_log("Error removing TARF from attendance logs: " . $e->getMessage());
                }
                
                logAction('TARF_DELETE', "Deleted TARF ID: $tarfId - {$tarf['title']}");
                
                echo json_encode([
                    'success' => true,
                    'message' => 'TARF deleted successfully'
                ]);
            } else {
                throw new Exception('Failed to delete TARF');
            }
            
        } catch (Exception $e) {
            error_log("TARF delete error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error deleting TARF: ' . $e->getMessage()
            ]);
            exit();
        }
        
    // ========== HOLIDAY ENDPOINTS ==========
    
    } elseif ($method === 'POST' && $action === 'create_holiday') {
        // Create Holiday (admin only)
        requireAdmin();
        
        $title = sanitizeInput($_POST['title'] ?? '');
        $isHalfDay = !empty($_POST['is_half_day']) && $_POST['is_half_day'] !== '0';
        $halfDayPeriod = null;
        if ($isHalfDay) {
            $p = strtolower(trim((string)($_POST['half_day_period'] ?? 'morning')));
            $halfDayPeriod = ($p === 'afternoon') ? 'afternoon' : 'morning';
        }
        
        $dates = [];
        $holidayDatesJson = $_POST['holiday_dates'] ?? '';
        if ($holidayDatesJson !== '') {
            $decoded = json_decode($holidayDatesJson, true);
            if (is_array($decoded)) {
                foreach ($decoded as $d) {
                    if (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                        $dates[] = $d;
                    }
                }
            }
            $dates = array_values(array_unique($dates));
            sort($dates);
        }
        // Backward compatibility: consecutive days from start_date + days
        if (empty($dates)) {
            $startDate = $_POST['start_date'] ?? '';
            $days = (int)($_POST['days'] ?? 0);
            if (!empty($title) && !empty($startDate) && $days >= 1 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
                $currentDate = new DateTime($startDate);
                for ($i = 0; $i < $days; $i++) {
                    $dates[] = $currentDate->format('Y-m-d');
                    $currentDate->modify('+1 day');
                }
            }
        }
        
        if (empty($title) || empty($dates)) {
            echo json_encode(['success' => false, 'message' => 'Title and at least one holiday date are required']);
            exit();
        }
        
        try {
            // Track whether we opened a transaction (commit must use this — not the pre-beginTransaction() flag)
            $ownTx = false;
            if (!$db->inTransaction()) {
                $db->beginTransaction();
                $ownTx = true;
            }
            
            $holidayIdMap = [];
            $createdDates = [];
            
            $stmtHolidayExists = $db->prepare("SELECT id FROM holidays WHERE date = ? AND title = ?");
            $stmtHolidayHalfUpdate = $db->prepare("UPDATE holidays SET is_half_day = ?, half_day_period = ? WHERE id = ?");
            $stmtHolidayInsert = $db->prepare("INSERT INTO holidays (title, date, is_half_day, half_day_period) VALUES (?, ?, ?, ?)");
            
            // Insert holiday entries for each date
            foreach ($dates as $date) {
                // Check if holiday already exists for this date
                $stmtHolidayExists->execute([$date, $title]);
                $existingHoliday = $stmtHolidayExists->fetch(PDO::FETCH_ASSOC);
                
                if ($existingHoliday && isset($existingHoliday['id'])) {
                    // Use existing holiday ID; refresh half-day flag so attendance logs stay in sync
                    $hid = (int)$existingHoliday['id'];
                    $holidayIdMap[$date] = $hid;
                    try {
                        $stmtHolidayHalfUpdate->execute([$isHalfDay ? 1 : 0, $halfDayPeriod, $hid]);
                    } catch (Exception $e) {
                        error_log("Holiday is_half_day update: " . $e->getMessage());
                    }
                } else {
                    // Create new holiday entry
                    try {
                        if ($stmtHolidayInsert->execute([$title, $date, $isHalfDay ? 1 : 0, $halfDayPeriod])) {
                            $holidayId = $db->lastInsertId();
                            if ($holidayId) {
                                $holidayIdMap[$date] = (int)$holidayId;
                                $createdDates[] = $date;
                            } else {
                                error_log("Warning: Failed to get lastInsertId for holiday: $title, date: $date");
                            }
                        } else {
                            $errorInfo = $stmtHolidayInsert->errorInfo();
                            error_log("Error inserting holiday: " . print_r($errorInfo, true));
                            throw new Exception("Failed to insert holiday entry for date: $date");
                        }
                    } catch (PDOException $e) {
                        error_log("PDO error inserting holiday: " . $e->getMessage());
                        throw new Exception("Database error inserting holiday: " . $e->getMessage());
                    }
                }
            }
            
            if (empty($holidayIdMap)) {
                if ($ownTx && $db->inTransaction()) {
                    $db->rollBack();
                }
                ob_clean();
                echo json_encode(['success' => false, 'message' => 'Failed to create holiday entries. All dates may already exist.']);
                exit();
            }
            
            // Persist holiday rows before responding so the calendar can refresh immediately.
            if ($ownTx && $db->inTransaction()) {
                $db->commit();
                error_log("Transaction committed successfully for holiday: $title");
            }
            
            $halfMsg = 'Half-day rows: employees with official time for that day get the matching segment credited; others are marked holiday only.';
            $holidayResponse = [
                'success' => true,
                'message' => $isHalfDay
                    ? ('Holiday created successfully. ' . $halfMsg)
                    : 'Holiday created successfully. Employees with official time for that date receive credited hours; others are marked holiday only (no default schedule).',
                'holidayIds' => array_values($holidayIdMap)
            ];
            $jsonOut = json_encode($holidayResponse);
            ob_clean();

            // Release session lock BEFORE heavy work so other pages load instantly
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            header('Connection: close');
            header('Content-Length: ' . strlen($jsonOut));
            echo $jsonOut;
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } else {
                if (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                @flush();
            }
            ignore_user_abort(true);
            @set_time_limit(300);
            
            // Heavy work runs after response + session release so other pages aren't blocked.
            try {
                createHolidayAttendanceLogs($dates, $db, $holidayIdMap);
                error_log("Successfully called createHolidayAttendanceLogs for " . count($dates) . " date(s)");
            } catch (Exception $e) {
                error_log("Holiday attendance log creation error (post-response): " . $e->getMessage());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
            
            try {
                $verifyStmt = $db->prepare("SELECT COUNT(*) as log_count FROM attendance_logs WHERE holiday_id IN (" . implode(',', array_map('intval', array_values($holidayIdMap))) . ")");
                $verifyStmt->execute();
                $verifyResult = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                $logCount = $verifyResult['log_count'] ?? 0;
                error_log("Verified: $logCount holiday attendance logs for holiday: $title");
            } catch (Exception $e) {
                error_log("Error verifying holiday logs: " . $e->getMessage());
            }
            
            try {
                logAction('HOLIDAY_CREATE', "Created holiday: $title for " . count($dates) . " day(s)");
            } catch (Exception $e) {
                error_log("Error logging holiday creation: " . $e->getMessage());
            }
            
            // Send email and in-app notifications to faculty/staff (after response so the UI is not blocked)
            try {
                $mailer = new Mailer();
                $notificationManager = getNotificationManager();
                
                // Get all active faculty and staff members
                $stmt = $db->prepare("SELECT id, email, first_name, last_name FROM users WHERE user_type IN ('faculty', 'staff') AND is_verified = 1 AND is_active = 1");
                $stmt->execute();
                $facultyStaff = $stmt->fetchAll();
                
                $emailCount = 0;
                $notificationCount = 0;
                
                // Format Holiday details for notification
                $dayText = count($dates) === 1 ? 'day' : 'days';
                $dateList = '';
                if (count($dates) == 1) {
                    $dateObj = new DateTime($dates[0]);
                    $dateList = $dateObj->format('F j, Y');
                } elseif (count($dates) <= 5) {
                    $parts = [];
                    foreach ($dates as $d) {
                        $parts[] = (new DateTime($d))->format('M j, Y');
                    }
                    $dateList = implode('; ', $parts);
                } else {
                    $startDateObj = new DateTime($dates[0]);
                    $endDateObj = new DateTime($dates[count($dates) - 1]);
                    $dateList = $startDateObj->format('M j, Y') . ' – ' . $endDateObj->format('M j, Y') . ' (' . count($dates) . ' days)';
                }
                
                $notificationMessage = "New Holiday: {$title} ({$dateList}). This applies to all employees.";
                
                // Email subject and body
                $emailSubject = "New Holiday Notification: " . $title;
                $emailBody = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
</head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2 style="color: #ffc107; margin-top: 0;">🎉 New Holiday Announced</h2>
    <p>A new holiday has been added to the calendar:</p>
    <div style="background-color: #fff8e1; padding: 15px; margin: 20px 0; border-left: 4px solid #ffc107;">
        <p><strong>Holiday:</strong> ' . htmlspecialchars($title) . '</p>
        <p><strong>Duration:</strong> ' . count($dates) . ' ' . $dayText . '</p>
        <p><strong>Date(s):</strong></p>
        <ul>';
        
                foreach ($dates as $date) {
                    $dateObj = new DateTime($date);
                    $dayOfWeek = $dateObj->format('l');
                    $emailBody .= '<li>' . htmlspecialchars($dayOfWeek . ', ' . $dateObj->format('F j, Y')) . '</li>';
                }
                
                $emailBody .= '
        </ul>
    </div>
    <p style="background-color: #e3f2fd; padding: 10px; border-left: 3px solid #2196f3; margin: 20px 0;">
        <strong>Note:</strong> This holiday applies to all employees. Credited hours follow each person’s official time or their logged times for that day; otherwise the day is marked holiday only.
    </p>
    <p>Please login to the faculty portal to view the full calendar.</p>
    <hr style="border: none; border-top: 1px solid #ddd; margin: 20px 0;">
    <p style="color: #666; font-size: 12px;">WPU Faculty and Staff System<br>This is an automated message. Please do not reply.</p>
</body>
</html>';
                
                foreach ($facultyStaff as $member) {
                    $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
                    if (empty($memberName)) {
                        $memberName = 'Faculty/Staff';
                    }
                    
                    // Send email notification
                    try {
                        $mailer->sendMail($member['email'], $memberName, $emailSubject, $emailBody, true);
                        $emailCount++;
                    } catch (Exception $e) {
                        error_log("Error sending Holiday email to user ID {$member['id']} ({$member['email']}): " . $e->getMessage());
                    }
                    
                    // Send in-app notification
                    try {
                        $notificationManager->createNotification(
                            $member['id'],
                            'holiday',
                            '🎉 New Holiday Announced',
                            $notificationMessage,
                            '../admin/calendar.php',
                            'normal'
                        );
                        $notificationCount++;
                    } catch (Exception $e) {
                        error_log("Error sending Holiday in-app notification to user ID {$member['id']}: " . $e->getMessage());
                    }
                }
                
                if ($emailCount > 0 || $notificationCount > 0) {
                    error_log("Holiday notifications sent: {$emailCount} emails, {$notificationCount} in-app notifications");
                }
            } catch (Exception $e) {
                // Log error but don't fail the holiday creation
                error_log("Error sending Holiday notifications: " . $e->getMessage());
            }
            
            exit();
            
        } catch (Exception $e) {
            $inTransaction = false;
            try {
                $inTransaction = $db->inTransaction();
            } catch (Exception $txCheck) {
                // Ignore transaction check errors
            }
            if ($inTransaction) {
                try {
                    $db->rollBack();
                } catch (Exception $rollbackError) {
                    error_log("Error rolling back transaction: " . $rollbackError->getMessage());
                }
            }
            error_log("Holiday creation error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            // Clear any output buffer before sending JSON
            ob_clean();
            echo json_encode([
                'success' => false,
                'message' => 'Error creating holiday: ' . $e->getMessage() . ' (Check error log for details)'
            ]);
            exit();
        }
        
    } elseif ($method === 'POST' && $action === 'sync_holiday_logs') {
        // Backfill holiday_id for existing attendance logs where log_date matches a holiday
        requireAdmin();
        try {
            $stmt = $db->prepare("SELECT id, date, title FROM holidays ORDER BY date");
            $stmt->execute();
            $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $updated = 0;
            foreach ($holidays as $h) {
                $stmtUp = $db->prepare("
                    UPDATE attendance_logs SET holiday_id = ?
                    WHERE log_date = ? AND (holiday_id IS NULL OR holiday_id = 0)
                ");
                $stmtUp->execute([$h['id'], $h['date']]);
                $updated += $stmtUp->rowCount();
            }
            echo json_encode(['success' => true, 'message' => "Synced $updated attendance log(s) with holiday IDs."]);
        } catch (Exception $e) {
            error_log("sync_holiday_logs error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit();
        
    } elseif ($method === 'GET' && $action === 'get_holiday') {
        // Get single holiday (admin only)
        requireAdmin();
        
        $holidayId = (int)($_GET['holiday_id'] ?? 0);
        
        if (!$holidayId) {
            echo json_encode(['success' => false, 'message' => 'Holiday ID is required']);
            exit();
        }
        
        try {
            $stmt = $db->prepare("SELECT id, title, date, COALESCE(is_half_day, 0) AS is_half_day, half_day_period FROM holidays WHERE id = ?");
            $stmt->execute([$holidayId]);
            $holiday = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($holiday) {
                $holiday['is_half_day'] = (int)($holiday['is_half_day'] ?? 0);
                if (!empty($holiday['is_half_day']) && (empty($holiday['half_day_period']) || $holiday['half_day_period'] === '')) {
                    $holiday['half_day_period'] = 'morning';
                }
                if (empty($holiday['is_half_day'])) {
                    $holiday['half_day_period'] = null;
                }
                echo json_encode([
                    'success' => true,
                    'holiday' => $holiday
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Holiday not found']);
            }
        } catch (Exception $e) {
            error_log("Holiday fetch error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error fetching holiday: ' . $e->getMessage()
            ]);
            exit();
        }
        
    } elseif ($method === 'POST' && $action === 'update_holiday') {
        // Update Holiday (admin only)
        requireAdmin();
        
        $holidayId = (int)($_POST['holiday_id'] ?? 0);
        $title = sanitizeInput($_POST['title'] ?? '');
        $date = $_POST['date'] ?? '';
        $isHalfDay = !empty($_POST['is_half_day']) && $_POST['is_half_day'] !== '0';
        $halfDayPeriod = null;
        if ($isHalfDay) {
            $p = strtolower(trim((string)($_POST['half_day_period'] ?? 'morning')));
            $halfDayPeriod = ($p === 'afternoon') ? 'afternoon' : 'morning';
        }
        
        if (!$holidayId || empty($title) || empty($date)) {
            echo json_encode(['success' => false, 'message' => 'Holiday ID, title, and date are required']);
            exit();
        }
        
        // Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date format']);
            exit();
        }
        
        try {
            $db->beginTransaction();
            
            // Get old holiday data
            $stmtOld = $db->prepare("SELECT title, date FROM holidays WHERE id = ?");
            $stmtOld->execute([$holidayId]);
            $oldHoliday = $stmtOld->fetch();
            
            if (!$oldHoliday) {
                $db->rollBack();
                echo json_encode(['success' => false, 'message' => 'Holiday not found']);
                exit();
            }
            
            // Update holiday
            $stmt = $db->prepare("UPDATE holidays SET title = ?, date = ?, is_half_day = ?, half_day_period = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            if (!$stmt->execute([$title, $date, $isHalfDay ? 1 : 0, $halfDayPeriod, $holidayId])) {
                throw new Exception('Failed to update holiday');
            }
            
            // Remove holiday placeholders and unlink holiday_id without deleting real time entries
            try {
                release_holiday_attendance_logs($holidayId, $db);
            } catch (Exception $e) {
                error_log("Error releasing old holiday attendance logs: " . $e->getMessage());
            }
            
            $db->commit();
            
            $updateResponse = [
                'success' => true,
                'message' => 'Holiday updated successfully.'
            ];
            $jsonOut = json_encode($updateResponse);
            ob_clean();

            // Release session lock BEFORE heavy work so other pages load instantly
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            header('Connection: close');
            header('Content-Length: ' . strlen($jsonOut));
            echo $jsonOut;
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } else {
                if (ob_get_level() > 0) {
                    @ob_end_flush();
                }
                @flush();
            }
            ignore_user_abort(true);
            @set_time_limit(300);
            
            try {
                createHolidayAttendanceLogs([$date], $db, [$date => $holidayId]);
            } catch (Exception $e) {
                error_log("Holiday update attendance logs error (post-response): " . $e->getMessage());
            }
            
            try {
                logAction('HOLIDAY_UPDATE', "Updated holiday ID: $holidayId - $title");
            } catch (Exception $e) {
                error_log("Error logging holiday update: " . $e->getMessage());
            }
            
            exit();
            
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Holiday update error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error updating holiday: ' . $e->getMessage()
            ]);
            exit();
        }
        
    } elseif ($method === 'POST' && $action === 'delete_holiday') {
        // Delete Holiday (admin only)
        requireAdmin();
        
        $holidayId = (int)($_POST['holiday_id'] ?? 0);
        
        if (!$holidayId) {
            echo json_encode(['success' => false, 'message' => 'Holiday ID is required']);
            exit();
        }
        
        try {
            // Get holiday title for logging
            $stmt = $db->prepare("SELECT title, date FROM holidays WHERE id = ?");
            $stmt->execute([$holidayId]);
            $holiday = $stmt->fetch();
            
            if (!$holiday) {
                echo json_encode(['success' => false, 'message' => 'Holiday not found']);
                exit();
            }
            
            // Delete holiday
            $stmt = $db->prepare("DELETE FROM holidays WHERE id = ?");
            
            if ($stmt->execute([$holidayId])) {
                // Remove holiday-only rows; keep logs that have time entries (clear holiday_id only)
                try {
                    release_holiday_attendance_logs($holidayId, $db);
                } catch (Exception $e) {
                    error_log("Error releasing holiday attendance logs: " . $e->getMessage());
                }
                
                logAction('HOLIDAY_DELETE', "Deleted holiday ID: $holidayId - {$holiday['title']}");
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Holiday deleted successfully'
                ]);
            } else {
                throw new Exception('Failed to delete holiday');
            }
            
        } catch (Exception $e) {
            error_log("Holiday delete error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Error deleting holiday: ' . $e->getMessage()
            ]);
            exit();
        }
        
    } else {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    error_log("Calendar API error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
} catch (Error $e) {
    http_response_code(500);
    error_log("Calendar API fatal error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Fatal error: ' . $e->getMessage()
    ]);
}
?>

