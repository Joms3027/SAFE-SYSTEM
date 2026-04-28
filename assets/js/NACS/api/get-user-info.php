<?php
require_once '../../../../includes/config.php';
require_once '../../../../includes/functions.php';
require_once '../../../../includes/database.php';
// no requireTimekeeper - open access

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['qr_data'])) {
    echo json_encode(['success' => false, 'message' => 'Missing QR code data']);
    exit();
}

$qrData = trim($input['qr_data']);

// Sanitize QR data: remove null bytes and control characters
$qrData = str_replace(["\0", "\r", "\n", "\t"], '', $qrData);
$qrData = trim($qrData);

try {
    $userId = null;
    $employeeId = null;

    // Try to parse QR data as JSON first
    $qrJson = json_decode($qrData, true);
    if ($qrJson && isset($qrJson['user_id'])) {
        $userId = intval($qrJson['user_id']);
    } elseif ($qrJson && isset($qrJson['employee_id'])) {
        $employeeId = trim($qrJson['employee_id']);
        $employeeId = str_replace(["\0", "\r", "\n", "\t"], '', $employeeId);
        $employeeId = trim($employeeId);
    } else {
        // Try to extract user_id from numeric string
        if (is_numeric($qrData)) {
            $userId = intval($qrData);
        } else {
            // Try to find user by employee_id
            // Trim and sanitize employee_id for matching (consistent with QR code generation)
            $searchEmployeeId = trim($qrData);
            $searchEmployeeId = str_replace(["\0", "\r", "\n", "\t"], '', $searchEmployeeId);
            $searchEmployeeId = trim($searchEmployeeId);
            
            if (!empty($searchEmployeeId)) {
                $stmt = $db->prepare("
                    SELECT fp.user_id 
                    FROM faculty_profiles fp 
                    INNER JOIN users u ON fp.user_id = u.id 
                    WHERE TRIM(fp.employee_id) = ?
                ");
                $stmt->execute([$searchEmployeeId]);
                $row = $stmt->fetch();
                if ($row) {
                    $userId = $row['user_id'];
                }
            }
        }
    }

    if (!$userId) {
        if ($employeeId) {
            // Trim and sanitize employee_id for matching
            $searchEmployeeId = trim($employeeId);
            $searchEmployeeId = str_replace(["\0", "\r", "\n", "\t"], '', $searchEmployeeId);
            $searchEmployeeId = trim($searchEmployeeId);
            
            if (!empty($searchEmployeeId)) {
                $stmt = $db->prepare("
                    SELECT fp.user_id 
                    FROM faculty_profiles fp 
                    INNER JOIN users u ON fp.user_id = u.id 
                    WHERE TRIM(fp.employee_id) = ?
                ");
                $stmt->execute([$searchEmployeeId]);
                $row = $stmt->fetch();
                if ($row) {
                    $userId = $row['user_id'];
                }
            }
        }
    }

    if (!$userId) {
        // Try to find by name (handles formats like "Last, First" or "First Last")
        $nameParts = [];
        if (strpos($qrData, ',') !== false) {
            $parts = explode(',', $qrData, 2);
            $nameParts['last'] = trim($parts[0]);
            $nameParts['first'] = isset($parts[1]) ? trim($parts[1]) : '';
        } else {
            $parts = preg_split('/\s+/', trim($qrData), 2);
            $nameParts['first'] = isset($parts[0]) ? trim($parts[0]) : '';
            $nameParts['last'] = isset($parts[1]) ? trim($parts[1]) : '';
        }

        if (!empty($nameParts['first']) || !empty($nameParts['last'])) {
            if (!empty($nameParts['first']) && !empty($nameParts['last'])) {
                $stmt = $db->prepare("
                    SELECT u.id FROM users u 
                    WHERE LOWER(TRIM(u.first_name)) = LOWER(?) AND LOWER(TRIM(u.last_name)) = LOWER(?) LIMIT 1
                ");
                $stmt->execute([$nameParts['first'], $nameParts['last']]);
            } elseif (!empty($nameParts['last'])) {
                $stmt = $db->prepare("SELECT u.id FROM users u WHERE LOWER(TRIM(u.last_name)) = LOWER(?) LIMIT 1");
                $stmt->execute([$nameParts['last']]);
            } else {
                $stmt = $db->prepare("SELECT u.id FROM users u WHERE LOWER(TRIM(u.first_name)) = LOWER(?) LIMIT 1");
                $stmt->execute([$nameParts['first']]);
            }
            $row = $stmt->fetch();
            if ($row) {
                $userId = $row['id'];
            }
        }
    }

    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Employee not found']);
        exit();
    }

    $stmt = $db->prepare("
        SELECT u.id, u.first_name, u.last_name, u.middle_name, u.email,
               fp.employee_id, fp.department, fp.position, fp.employment_status, fp.phone, fp.profile_picture
        FROM users u 
        INNER JOIN faculty_profiles fp ON u.id = fp.user_id 
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Employee not found in faculty_profiles']);
        exit();
    }

    if (empty($row['employee_id'])) {
        echo json_encode(['success' => false, 'message' => 'Safe Employee ID not found in faculty_profiles']);
        exit();
    }

    date_default_timezone_set('Asia/Manila');
    $today = date('Y-m-d');
    
    $stmtHoliday = $db->prepare("SELECT id, title FROM holidays WHERE date = ? LIMIT 1");
    $stmtHoliday->execute([$today]);
    $holidayData = $stmtHoliday->fetch();
    $isHoliday = !empty($holidayData);
    $holidayTitle = $holidayData ? $holidayData['title'] : null;
    
    $stmtTarf = $db->prepare("
        SELECT t.id, t.title FROM tarf t
        INNER JOIN tarf_employees te ON t.id = te.tarf_id
        WHERE te.employee_id = ? AND t.date = ? LIMIT 1
    ");
    $stmtTarf->execute([$row['employee_id'], $today]);
    $tarfData = $stmtTarf->fetch();
    $isInTarf = !empty($tarfData);
    $tarfTitle = $tarfData ? $tarfData['title'] : null;

    $employeeName = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
    
    if ($isInTarf || $isHoliday) {
        if ($isInTarf) {
            $stmt2 = $db->prepare("
                SELECT id, employee_id, log_date, time_in, lunch_out, lunch_in, time_out, ot_in, ot_out, station_id, timekeeper_id, remarks, tarf_id, holiday_id, created_at 
                FROM attendance_logs 
                WHERE employee_id = ? AND log_date = ? AND (remarks LIKE 'TARF:%' OR remarks LIKE 'Holiday:%')
                ORDER BY id DESC LIMIT 1
            ");
        } else {
            $stmt2 = $db->prepare("
                SELECT id, employee_id, log_date, time_in, lunch_out, lunch_in, time_out, ot_in, ot_out, station_id, timekeeper_id, remarks, tarf_id, holiday_id, created_at 
                FROM attendance_logs 
                WHERE employee_id = ? AND log_date = ? AND remarks LIKE 'Holiday:%'
                ORDER BY id DESC LIMIT 1
            ");
        }
        $stmt2->execute([$row['employee_id'], $today]);
    } else {
        $stmt2 = $db->prepare("
            SELECT id, employee_id, log_date, time_in, lunch_out, lunch_in, time_out, ot_in, ot_out, station_id, timekeeper_id, remarks, tarf_id, holiday_id, created_at 
            FROM attendance_logs 
            WHERE employee_id = ? AND log_date = ?
            ORDER BY id DESC LIMIT 1
        ");
        $stmt2->execute([$row['employee_id'], $today]);
    }
    $todayAttendance = $stmt2->fetch();
    
    if ($isInTarf && !$todayAttendance) {
        $tarfId = $tarfData['id'];
        
        // Get official times for this employee on this date
        $dateObj = new DateTime($today);
        $dayOfWeek = $dateObj->format('w'); // 0 (Sunday) to 6 (Saturday)
        $weekdays = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $weekday = $weekdays[$dayOfWeek];
        
        // Find official time for this date range and weekday
        $stmtOfficial = $db->prepare("SELECT * FROM employee_official_times 
                                     WHERE employee_id = ? 
                                     AND weekday = ?
                                     AND start_date <= ?
                                     AND (end_date IS NULL OR end_date >= ?)
                                     ORDER BY start_date DESC 
                                     LIMIT 1");
        $stmtOfficial->execute([$row['employee_id'], $weekday, $today, $today]);
        $officialTime = $stmtOfficial->fetch(PDO::FETCH_ASSOC);
        
        // Use official times if found, otherwise use defaults
        if ($officialTime) {
            // Verify the date is actually within range
            $logDate = new DateTime($today);
            $logDate->setTime(0, 0, 0);
            $startDate = new DateTime($officialTime['start_date']);
            $startDate->setTime(0, 0, 0);
            $endDate = $officialTime['end_date'] ? new DateTime($officialTime['end_date']) : null;
            if ($endDate) {
                $endDate->setTime(0, 0, 0);
            }
            
            $isInRange = ($logDate >= $startDate) && ($endDate === null || $logDate <= $endDate);
            
            if ($isInRange) {
                $timeIn = $officialTime['time_in'];
                $lunchOut = ($officialTime['lunch_out'] && $officialTime['lunch_out'] != '00:00:00') ? $officialTime['lunch_out'] : '12:00:00';
                $lunchIn = ($officialTime['lunch_in'] && $officialTime['lunch_in'] != '00:00:00') ? $officialTime['lunch_in'] : '13:00:00';
                $timeOut = $officialTime['time_out'];
            } else {
                // Default times if date not in range
                $timeIn = '08:00:00';
                $lunchOut = '12:00:00';
                $lunchIn = '13:00:00';
                $timeOut = '17:00:00';
            }
        } else {
            // Default times if no official time found
            $timeIn = '08:00:00';
            $lunchOut = '12:00:00';
            $lunchIn = '13:00:00';
            $timeOut = '17:00:00';
        }
        
        // Create TARF attendance log using official times
        // Use ON DUPLICATE KEY UPDATE to handle race conditions where a record already exists
        $stmtInsert = $db->prepare("
            INSERT INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks, tarf_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                time_in = VALUES(time_in),
                lunch_out = VALUES(lunch_out),
                lunch_in = VALUES(lunch_in),
                time_out = VALUES(time_out),
                remarks = VALUES(remarks),
                tarf_id = VALUES(tarf_id)
        ");
        $stmtInsert->execute([$row['employee_id'], $today, $timeIn, $lunchOut, $lunchIn, $timeOut, "TARF: " . $tarfTitle, $tarfId]);
        $stmt2 = $db->prepare("
            SELECT id, employee_id, log_date, time_in, lunch_out, lunch_in, time_out, ot_in, ot_out, station_id, timekeeper_id, remarks, tarf_id, holiday_id, created_at 
            FROM attendance_logs 
            WHERE employee_id = ? AND log_date = ? AND remarks LIKE 'TARF:%'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt2->execute([$row['employee_id'], $today]);
        $todayAttendance = $stmt2->fetch();
    }
    
    if ($isHoliday && !$todayAttendance) {
        $holidayId = $holidayData['id'];
        try { $db->exec("ALTER TABLE attendance_logs ADD COLUMN holiday_id INT DEFAULT NULL"); } catch (Exception $e) {}
        $stmtInsert = $db->prepare("
            INSERT INTO attendance_logs (employee_id, log_date, time_in, lunch_out, lunch_in, time_out, remarks, holiday_id, created_at)
            VALUES (?, ?, '08:00:00', '12:00:00', '13:00:00', '17:00:00', ?, ?, NOW())
        ");
        $stmtInsert->execute([$row['employee_id'], $today, "Holiday: " . $holidayTitle, $holidayId]);
        $stmt2 = $db->prepare("
            SELECT id, employee_id, log_date, time_in, lunch_out, lunch_in, time_out, ot_in, ot_out, station_id, timekeeper_id, remarks, tarf_id, holiday_id, created_at 
            FROM attendance_logs 
            WHERE employee_id = ? AND log_date = ? AND remarks LIKE 'Holiday:%'
            ORDER BY id DESC LIMIT 1
        ");
        $stmt2->execute([$row['employee_id'], $today]);
        $todayAttendance = $stmt2->fetch();
    }

    echo json_encode([
        'success' => true,
        'user' => [
            'user_id' => $row['id'],
            'employee_id' => $row['employee_id'],
            'name' => $employeeName,
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'middle_name' => $row['middle_name'],
            'email' => $row['email'],
            'department' => $row['department'] ?: 'N/A',
            'position' => $row['position'] ?: 'N/A',
            'employment_status' => $row['employment_status'] ?: 'N/A',
            'phone' => $row['phone'] ?: 'N/A',
            'profile_picture' => $row['profile_picture']
        ],
        'today_attendance' => $todayAttendance,
        'is_in_tarf' => $isInTarf,
        'tarf_title' => $tarfTitle,
        'is_holiday' => $isHoliday,
        'holiday_title' => $holidayTitle
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
