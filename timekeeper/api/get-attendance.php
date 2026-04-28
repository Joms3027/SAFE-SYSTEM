<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/database.php';
require_once '../../includes/session_optimization.php';

requireTimekeeper();

header('Content-Type: application/json');

// Close session early for read-only API call to prevent blocking
// This allows multiple timekeepers to fetch attendance simultaneously
closeSessionEarly(true);

$database = Database::getInstance();
$db = $database->getConnection();

try {
    // Set timezone to Asia/Manila
    date_default_timezone_set('Asia/Manila');
    
    // Get date parameter (default to today)
    $date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format']);
        exit();
    }

    // Filter by timekeeper's station from session
    // Show records for the station OR records with NULL station_id (for backward compatibility)
    $stationId = $_SESSION['timekeeper_station_id'] ?? null;
    $whereClause = "DATE(al.log_date) = :date";
    $params = [':date' => $date];
    
    if ($stationId) {
        // Show records for this station OR records with NULL station_id (legacy records)
        $whereClause .= " AND (al.station_id = :station_id OR al.station_id IS NULL)";
        $params[':station_id'] = $stationId;
    }

    // Fetch attendance records for the specified date
    // Use COALESCE to handle cases where JOIN fails - still show the employee_id
    $stmt = $db->prepare("
        SELECT 
            al.id,
            al.employee_id,
            al.log_date,
            al.time_in,
            al.lunch_out,
            al.lunch_in,
            al.time_out,
            al.ot_in,
            al.ot_out,
            al.station_id,
            al.timekeeper_id,
            al.created_at,
            COALESCE(u.id, NULL) as user_id,
            COALESCE(
                CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name),
                CONCAT('Safe Employee ID: ', al.employee_id)
            ) as name
        FROM attendance_logs al
        LEFT JOIN faculty_profiles fp ON al.employee_id = fp.employee_id
        LEFT JOIN users u ON fp.user_id = u.id
        WHERE $whereClause
        ORDER BY al.time_in ASC, COALESCE(u.last_name, ''), COALESCE(u.first_name, ''), al.employee_id ASC
    ");
    
    $stmt->execute($params);
    $attendance = $stmt->fetchAll();

    // Format the data
    $formattedAttendance = [];
    foreach ($attendance as $record) {
        $formattedAttendance[] = [
            'id' => $record['id'],
            'user_id' => $record['user_id'],
            'employee_id' => $record['employee_id'],
            'name' => trim($record['name'] ?: 'N/A'),
            'attendance_date' => $record['log_date'],
            'log_date' => $record['log_date'],
            'time_in' => $record['time_in'],
            'lunch_out' => $record['lunch_out'],
            'lunch_in' => $record['lunch_in'],
            'time_out' => $record['time_out'],
            'ot_in' => $record['ot_in'] ?? null,
            'ot_out' => $record['ot_out'] ?? null,
            'station_id' => $record['station_id'],
            'timekeeper_id' => $record['timekeeper_id'],
            'created_at' => $record['created_at']
        ];
    }

    echo json_encode([
        'success' => true,
        'attendance' => $formattedAttendance,
        'date' => $date
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
