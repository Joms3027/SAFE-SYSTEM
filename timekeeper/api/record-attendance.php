<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/database.php';

requireTimekeeper();

// Release session lock early to allow concurrent requests from same user
// This prevents session blocking when multiple timekeepers scan simultaneously
if (session_status() === PHP_SESSION_ACTIVE) {
    $sessionData = [
        'timekeeper_id' => $_SESSION['timekeeper_id'] ?? null,
        'timekeeper_station_id' => $_SESSION['timekeeper_station_id'] ?? null,
        'station_id' => $_SESSION['station_id'] ?? null
    ];
    session_write_close();
} else {
    $sessionData = [];
}

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

// Accept either user_id directly or qr_data
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
    $stationId = isset($input['station_id']) ? intval($input['station_id']) : null;
    // Use saved session data since we closed the session early for concurrency
    $timekeeperId = isset($input['timekeeper_id']) ? intval($input['timekeeper_id']) : ($sessionData['timekeeper_id'] ?? null);
    
    // Get original timestamp if provided (for offline sync), otherwise use current time
    $recordedTime = isset($input['recorded_time']) ? $input['recorded_time'] : null;
    $logDate = isset($input['log_date']) ? $input['log_date'] : null;

// Validate attendance type
$validTypes = ['time_in', 'lunch_out', 'lunch_in', 'time_out', 'ot_in', 'ot_out'];
if (!in_array($attendanceType, $validTypes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid attendance type']);
    exit();
}

try {
    // If userId is not already set, extract from QR code data
    if (!$userId && $qrData) {
        $employeeId = null;

        // Try to parse QR data as JSON first
        $qrJson = json_decode($qrData, true);
        if ($qrJson && isset($qrJson['user_id'])) {
            $userId = intval($qrJson['user_id']);
        } elseif ($qrJson && isset($qrJson['employee_id'])) {
            $employeeId = $qrJson['employee_id'];
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
            // Try to find by employee_id if we have it
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
                // Format: "Last, First"
                $parts = explode(',', $qrData, 2);
                $nameParts['last'] = trim($parts[0]);
                $nameParts['first'] = isset($parts[1]) ? trim($parts[1]) : '';
            } else {
                // Format: "First Last" or just a name
                $parts = preg_split('/\s+/', trim($qrData), 2);
                $nameParts['first'] = isset($parts[0]) ? trim($parts[0]) : '';
                $nameParts['last'] = isset($parts[1]) ? trim($parts[1]) : '';
            }

            if (!empty($nameParts['first']) || !empty($nameParts['last'])) {
                if (!empty($nameParts['first']) && !empty($nameParts['last'])) {
                    // Both first and last name provided
                    $stmt = $db->prepare("
                        SELECT u.id 
                        FROM users u 
                        WHERE LOWER(TRIM(u.first_name)) = LOWER(?) 
                        AND LOWER(TRIM(u.last_name)) = LOWER(?)
                        LIMIT 1
                    ");
                    $stmt->execute([$nameParts['first'], $nameParts['last']]);
                } elseif (!empty($nameParts['last'])) {
                    // Only last name provided
                    $stmt = $db->prepare("
                        SELECT u.id 
                        FROM users u 
                        WHERE LOWER(TRIM(u.last_name)) = LOWER(?)
                        LIMIT 1
                    ");
                    $stmt->execute([$nameParts['last']]);
                } else {
                    // Only first name provided
                    $stmt = $db->prepare("
                        SELECT u.id 
                        FROM users u 
                        WHERE LOWER(TRIM(u.first_name)) = LOWER(?)
                        LIMIT 1
                    ");
                    $stmt->execute([$nameParts['first']]);
                }
                
                $row = $stmt->fetch();
                if ($row) {
                    $userId = $row['id'];
                }
            }
        }

        if (!$userId) {
            echo json_encode(['success' => false, 'message' => 'Invalid QR code. Employee not found.']);
            exit();
        }
    }

    // Get employee information from faculty_profiles
    $stmt = $db->prepare("
        SELECT u.id, u.first_name, u.last_name, fp.employee_id 
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

    // Verify employee_id exists in faculty_profiles
    if (empty($row['employee_id'])) {
        echo json_encode(['success' => false, 'message' => 'Safe Employee ID not found in faculty_profiles']);
        exit();
    }

    // Get employee's full profile
    $stmtEmp = $db->prepare("
        SELECT fp.employee_id
        FROM faculty_profiles fp
        WHERE fp.user_id = ?
    ");
    $stmtEmp->execute([$userId]);
    $empData = $stmtEmp->fetch();
    
    if (!$empData) {
        echo json_encode(['success' => false, 'message' => 'Employee profile not found']);
        exit();
    }

    // Get station ID from saved session data
    // Check both session variable names for compatibility
    $timekeeperStationId = $sessionData['timekeeper_station_id'] ?? $sessionData['station_id'] ?? $stationId;
    
    // Station ID is optional now - stations can record for anyone
    // If not set, use 0 or null (will still record attendance)
    if (!$timekeeperStationId) {
        $timekeeperStationId = 0; // Default to 0 if no station
    }

    $employeeName = trim($row['first_name'] . ' ' . $row['last_name']);
    $employeeId = $empData['employee_id']; // Always from faculty_profiles

    // Set timezone to Asia/Manila
    date_default_timezone_set('Asia/Manila');
    
    // Use the recorded time if provided (from offline sync), otherwise use current time
    // This ensures offline records use the original timestamp, not the sync timestamp
    if ($recordedTime) {
        // Validate the recorded time format (HH:MM:SS)
        if (preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $recordedTime)) {
            $currentTime = $recordedTime;
        } else {
            // Invalid format, use current time instead
            $currentTime = date('H:i:s');
        }
    } else {
        $currentTime = date('H:i:s');
    }
    
    // Validate log date format (YYYY-MM-DD)
    if ($logDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $logDate)) {
        $today = $logDate;
    } else {
        $today = date('Y-m-d');
    }
    
    // Use timekeeper's station from session if not provided
    if (!$stationId) {
        $stationId = $timekeeperStationId;
    }

    // Check if today is a holiday (applies to ALL employees)
    $stmtHoliday = $db->prepare("
        SELECT id, title 
        FROM holidays
        WHERE date = ?
        LIMIT 1
    ");
    $stmtHoliday->execute([$today]);
    $holidayData = $stmtHoliday->fetch();
    $isHoliday = !empty($holidayData);
    
    // If it's a holiday, prevent manual attendance recording
    if ($isHoliday) {
        echo json_encode([
            'success' => false,
            'message' => "Holiday today, no need to log. " . ($holidayData['title'] ?? 'Holiday') . " - Your attendance has been automatically recorded for 8 hours (08:00-17:00)."
        ]);
        exit();
    }
    
    // Check if employee is in TARF for today
    $stmtTarf = $db->prepare("
        SELECT t.id, t.title 
        FROM tarf t
        INNER JOIN tarf_employees te ON t.id = te.tarf_id
        WHERE te.employee_id = ? AND t.date = ?
        LIMIT 1
    ");
    $stmtTarf->execute([$employeeId, $today]);
    $tarfData = $stmtTarf->fetch();
    $isInTarf = !empty($tarfData);
    
    // If employee is in TARF, prevent manual attendance recording
    if ($isInTarf) {
        echo json_encode([
            'success' => false,
            'message' => "You are in TARF: " . ($tarfData['title'] ?? 'TARF') . ". Your attendance has been automatically recorded based on your official time."
        ]);
        exit();
    }
    
    // Whitelist for column names to prevent SQL injection
    $columnMap = [
        'time_in' => 'time_in',
        'lunch_out' => 'lunch_out',
        'lunch_in' => 'lunch_in',
        'time_out' => 'time_out',
        'ot_in' => 'ot_in',
        'ot_out' => 'ot_out'
    ];
    
    if (!isset($columnMap[$attendanceType])) {
        echo json_encode(['success' => false, 'message' => 'Invalid attendance type']);
        exit();
    }
    
    $updateField = $columnMap[$attendanceType];

    // Use transactional approach with retry to handle concurrent access
    // This prevents race conditions when multiple users scan at the same time
    $result = $database->transactional(function($db) use ($employeeId, $today, $updateField, $currentTime, $stationId, $timekeeperId) {
        $conn = $db->getConnection();
        
        // Lock the row for update to prevent concurrent modifications
        // Use SELECT FOR UPDATE to get exclusive lock on the row (if it exists)
        $stmt = $conn->prepare("
            SELECT id FROM attendance_logs 
            WHERE employee_id = ? AND log_date = ? 
            FOR UPDATE
        ");
        $stmt->execute([$employeeId, $today]);
        $existingRecord = $stmt->fetch();
        
        if ($existingRecord) {
            // Update existing record (we have the row locked)
            $stmt = $conn->prepare("
                UPDATE attendance_logs 
                SET `$updateField` = ?, station_id = ?, timekeeper_id = ? 
                WHERE id = ?
            ");
            $stmt->execute([$currentTime, $stationId, $timekeeperId, $existingRecord['id']]);
            return 'updated';
        } else {
            // Create new record using INSERT with duplicate key handling
            // This handles the rare case where another request inserts between our SELECT and INSERT
            $stmt = $conn->prepare("
                INSERT INTO attendance_logs (employee_id, log_date, `$updateField`, station_id, timekeeper_id, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                    `$updateField` = VALUES(`$updateField`),
                    station_id = VALUES(station_id),
                    timekeeper_id = VALUES(timekeeper_id)
            ");
            $stmt->execute([$employeeId, $today, $currentTime, $stationId, $timekeeperId]);
            return 'inserted';
        }
    }, 3); // Retry up to 3 times on deadlock
    
    $scanSource = $qrData ? 'via QR scan' : 'manual';
    logAction('TIMEKEEPER_ATTENDANCE', "Recorded $attendanceType for employee: $employeeId ($scanSource)");
    echo json_encode([
        'success' => true,
        'message' => ucfirst(str_replace('_', ' ', $attendanceType)) . ' recorded successfully for ' . $employeeName
    ]);

} catch (PDOException $e) {
    // Log the error for debugging
    error_log("Attendance recording error for employee $employeeId: " . $e->getMessage());
    
    // Check for specific error types and provide helpful messages
    $errorCode = $e->errorInfo[1] ?? 0;
    if (in_array($errorCode, [1213, 1205])) {
        // Deadlock or lock timeout - system is under heavy load
        echo json_encode([
            'success' => false, 
            'message' => 'System is busy. Please try again in a moment.',
            'retry' => true
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    }
} catch (Exception $e) {
    error_log("Attendance recording exception for employee $employeeId: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred. Please try again.']);
}
?>
