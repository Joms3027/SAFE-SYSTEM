<?php
require_once '../../../../includes/config.php';
require_once '../../../../includes/functions.php';
require_once '../../../../includes/database.php';
require_once '../../../../includes/tarf_calendar_kind.php';
// no requireTimekeeper - open access

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['attendance_type'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit();
}

$userId = null;
$qrData = isset($input['user_id']) ? null : (isset($input['qr_data']) ? trim($input['qr_data']) : null);

// Sanitize QR data: remove null bytes and control characters
if ($qrData) {
    $qrData = str_replace(["\0", "\r", "\n", "\t"], '', $qrData);
    $qrData = trim($qrData);
}

if (isset($input['user_id'])) {
    $userId = intval($input['user_id']);
} elseif (!$qrData) {
    echo json_encode(['success' => false, 'message' => 'Missing user_id or qr_data']);
    exit();
}

$attendanceType = $input['attendance_type'];
$stationId = isset($input['station_id']) ? intval($input['station_id']) : 0;
$timekeeperId = null;
$recordedTime = isset($input['recorded_time']) ? $input['recorded_time'] : null;
$logDate = isset($input['log_date']) ? $input['log_date'] : null;

$validTypes = ['time_in', 'lunch_out', 'lunch_in', 'time_out', 'ot_in', 'ot_out'];
if (!in_array($attendanceType, $validTypes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid attendance type']);
    exit();
}

// NACS/allscan2: only use station 37 or 26 (no random DB lookup)
$allowedStations = [37, 26];
if ($stationId === 0 || !in_array($stationId, $allowedStations)) {
    $stationId = (int) $allowedStations[array_rand($allowedStations)];
}

try {
    if (!$userId && $qrData) {
        $employeeId = null;
        $qrJson = json_decode($qrData, true);
        if ($qrJson && isset($qrJson['user_id'])) {
            $userId = intval($qrJson['user_id']);
        } elseif ($qrJson && isset($qrJson['employee_id'])) {
            $employeeId = $qrJson['employee_id'];
        } else {
            if (is_numeric($qrData)) {
                $userId = intval($qrData);
            } else {
                // Try to find user by employee_id
                // Trim and sanitize employee_id for matching (consistent with QR code generation)
                $searchEmployeeId = trim($qrData);
                $searchEmployeeId = str_replace(["\0", "\r", "\n", "\t"], '', $searchEmployeeId);
                $searchEmployeeId = trim($searchEmployeeId);
                
                if (!empty($searchEmployeeId)) {
                    $stmt = $db->prepare("SELECT fp.user_id FROM faculty_profiles fp INNER JOIN users u ON fp.user_id = u.id WHERE TRIM(fp.employee_id) = ?");
                    $stmt->execute([$searchEmployeeId]);
                    $row = $stmt->fetch();
                    if ($row) $userId = $row['user_id'];
                }
            }
        }

        if (!$userId && $employeeId) {
            // Trim and sanitize employee_id for matching
            $searchEmployeeId = trim($employeeId);
            $searchEmployeeId = str_replace(["\0", "\r", "\n", "\t"], '', $searchEmployeeId);
            $searchEmployeeId = trim($searchEmployeeId);
            
            if (!empty($searchEmployeeId)) {
                $stmt = $db->prepare("SELECT fp.user_id FROM faculty_profiles fp INNER JOIN users u ON fp.user_id = u.id WHERE TRIM(fp.employee_id) = ?");
                $stmt->execute([$searchEmployeeId]);
                $row = $stmt->fetch();
                if ($row) $userId = $row['user_id'];
            }
        }

        if (!$userId) {
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
                    $stmt = $db->prepare("SELECT u.id FROM users u WHERE LOWER(TRIM(u.first_name)) = LOWER(?) AND LOWER(TRIM(u.last_name)) = LOWER(?) LIMIT 1");
                    $stmt->execute([$nameParts['first'], $nameParts['last']]);
                } elseif (!empty($nameParts['last'])) {
                    $stmt = $db->prepare("SELECT u.id FROM users u WHERE LOWER(TRIM(u.last_name)) = LOWER(?) LIMIT 1");
                    $stmt->execute([$nameParts['last']]);
                } else {
                    $stmt = $db->prepare("SELECT u.id FROM users u WHERE LOWER(TRIM(u.first_name)) = LOWER(?) LIMIT 1");
                    $stmt->execute([$nameParts['first']]);
                }
                $row = $stmt->fetch();
                if ($row) $userId = $row['id'];
            }
        }

        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'Invalid QR code. Employee not found.']);
            exit();
        }
    }

    $stmt = $db->prepare("SELECT u.id, u.first_name, u.last_name, fp.employee_id FROM users u INNER JOIN faculty_profiles fp ON u.id = fp.user_id WHERE u.id = ?");
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

    $stmtEmp = $db->prepare("SELECT fp.employee_id FROM faculty_profiles fp WHERE fp.user_id = ?");
    $stmtEmp->execute([$userId]);
    $empData = $stmtEmp->fetch();
    
    if (!$empData) {
        echo json_encode(['success' => false, 'message' => 'Employee profile not found']);
        exit();
    }

    $employeeName = trim($row['first_name'] . ' ' . $row['last_name']);
    $employeeId = $empData['employee_id'];

    date_default_timezone_set('Asia/Manila');
    
    if ($recordedTime && preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $recordedTime)) {
        $currentTime = $recordedTime;
    } else {
        $currentTime = date('H:i:s');
    }
    
    if ($logDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) {
        $today = $logDate;
    } else {
        $today = date('Y-m-d');
    }

    $stmtHoliday = $db->prepare("SELECT id, title FROM holidays WHERE date = ? LIMIT 1");
    $stmtHoliday->execute([$today]);
    $holidayData = $stmtHoliday->fetch();
    if (!empty($holidayData)) {
        echo json_encode(['success' => false, 'message' => "Holiday today, no need to log. " . ($holidayData['title'] ?? 'Holiday') . " - Your attendance has been automatically recorded for 8 hours (08:00-17:00)."]);
        exit();
    }
    
    tarf_calendar_kind_ensure_column($db);
    $stmtTarf = $db->prepare("SELECT t.id, t.title FROM tarf t INNER JOIN tarf_employees te ON t.id = te.tarf_id WHERE te.employee_id = ? AND t.date = ? AND t.calendar_kind = 'travel' LIMIT 1");
    $stmtTarf->execute([$employeeId, $today]);
    $tarfData = $stmtTarf->fetch();
    if (!empty($tarfData)) {
        echo json_encode(['success' => false, 'message' => "You are in TARF: " . ($tarfData['title'] ?? 'TARF') . ". Your attendance has been automatically recorded based on your official time."]);
        exit();
    }
    
    $stmt = $db->prepare("SELECT * FROM attendance_logs WHERE employee_id = ? AND log_date = ?");
    $stmt->execute([$employeeId, $today]);
    $existingRecord = $stmt->fetch();

    $columnMap = ['time_in' => 'time_in', 'lunch_out' => 'lunch_out', 'lunch_in' => 'lunch_in', 'time_out' => 'time_out', 'ot_in' => 'ot_in', 'ot_out' => 'ot_out'];
    if (!isset($columnMap[$attendanceType])) {
        echo json_encode(['success' => false, 'message' => 'Invalid attendance type']);
        exit();
    }
    $updateField = $columnMap[$attendanceType];

    if ($existingRecord) {
        $stmt = $db->prepare("UPDATE attendance_logs SET `$updateField` = ?, station_id = ?, timekeeper_id = ? WHERE id = ?");
        if ($stmt->execute([$currentTime, $stationId, $timekeeperId, $existingRecord['id']])) {
            // NACS/allscan2 scans are not logged to audit trail (per requirement)
            echo json_encode(['success' => true, 'message' => ucfirst(str_replace('_', ' ', $attendanceType)) . ' recorded successfully for ' . $employeeName]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update attendance record']);
        }
    } else {
        // Use ON DUPLICATE KEY UPDATE to handle race conditions where another request inserts between SELECT and INSERT
        $stmt = $db->prepare("
            INSERT INTO attendance_logs (employee_id, log_date, `$updateField`, station_id, timekeeper_id, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                `$updateField` = VALUES(`$updateField`),
                station_id = VALUES(station_id),
                timekeeper_id = VALUES(timekeeper_id)
        ");
        if ($stmt->execute([$employeeId, $today, $currentTime, $stationId, $timekeeperId])) {
            // NACS/allscan2 scans are not logged to audit trail (per requirement)
            echo json_encode(['success' => true, 'message' => ucfirst(str_replace('_', ' ', $attendanceType)) . ' recorded successfully for ' . $employeeName]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to create attendance record']);
        }
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
