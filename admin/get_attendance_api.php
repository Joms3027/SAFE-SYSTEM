<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/session_optimization.php';

requireAdmin();

header('Content-Type: application/json');

closeSessionEarly(true);

$date = $_GET['date'] ?? date('Y-m-d');
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$employee_id = trim($_GET['employee_id'] ?? '');
$search = trim($_GET['search'] ?? '');
$station_id = isset($_GET['station_id']) ? trim($_GET['station_id']) : '';

try {
    $database = Database::getInstance();
    $db = $database->getConnection();

    date_default_timezone_set('Asia/Manila');

    $whereClause = "1=1";
    $params = [];

    // Date filter: single date or date range
    if (!empty($date_from) && !empty($date_to)) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
            echo json_encode(['success' => false, 'message' => 'Invalid date format']);
            exit;
        }
        $whereClause .= " AND al.log_date >= ? AND al.log_date <= ?";
        $params[] = $date_from;
        $params[] = $date_to;
    } else {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $whereClause .= " AND DATE(al.log_date) = ?";
        $params[] = $date;
    }

    if (!empty($employee_id)) {
        $whereClause .= " AND al.employee_id LIKE ?";
        $params[] = '%' . $employee_id . '%';
    }

    if (!empty($search)) {
        $whereClause .= " AND (al.employee_id LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ?)";
        $searchTerm = '%' . $search . '%';
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }

    if ($station_id !== '') {
        if ($station_id === 'null' || $station_id === 'none') {
            $whereClause .= " AND al.station_id IS NULL";
        } else {
            $stationIdInt = (int) $station_id;
            if ($stationIdInt > 0) {
                $whereClause .= " AND al.station_id = ?";
                $params[] = $stationIdInt;
            }
        }
    }

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
            al.remarks,
            al.station_id,
            al.created_at,
            COALESCE(u.id, NULL) as user_id,
            COALESCE(
                TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.middle_name, ''), ' ', COALESCE(u.last_name, ''))),
                CONCAT('Employee ID: ', al.employee_id)
            ) as name,
            s.name as station_name
        FROM attendance_logs al
        LEFT JOIN faculty_profiles fp ON al.employee_id = fp.employee_id
        LEFT JOIN users u ON fp.user_id = u.id
        LEFT JOIN stations s ON al.station_id = s.id
        WHERE $whereClause
        ORDER BY al.log_date DESC, al.time_in ASC, COALESCE(u.last_name, ''), COALESCE(u.first_name, ''), al.employee_id ASC
    ");
    $stmt->execute($params);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            'remarks' => $record['remarks'] ?? null,
            'station_id' => $record['station_id'],
            'station_name' => $record['station_name'] ?? null,
            'created_at' => $record['created_at']
        ];
    }

    echo json_encode([
        'success' => true,
        'attendance' => $formattedAttendance,
        'count' => count($formattedAttendance),
        'date' => $date,
        'date_from' => $date_from,
        'date_to' => $date_to
    ]);

} catch (Exception $e) {
    error_log('get_attendance_api.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching attendance: ' . $e->getMessage(),
        'attendance' => []
    ]);
}
?>
