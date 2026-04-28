<?php
require_once '../../includes/config.php';
require_once '../../includes/functions.php';
require_once '../../includes/database.php';

requireTimekeeper();

// Release session lock early to allow concurrent requests
if (session_status() === PHP_SESSION_ACTIVE) {
    $timekeeperStationId = $_SESSION['timekeeper_station_id'] ?? null;
    session_write_close();
} else {
    $timekeeperStationId = null;
}

header('Content-Type: application/json');

$database = Database::getInstance();
$db = $database->getConnection();

try {
    // Get timekeeper's station department (already extracted from session)
    
    if (!$timekeeperStationId) {
        echo json_encode([
            'success' => false,
            'message' => 'Timekeeper station not set. Cannot filter by department.'
        ]);
        exit();
    }
    
    // Get station's department
    $stmtStation = $db->prepare("
        SELECT d.name as department_name, d.id as department_id
        FROM stations s
        INNER JOIN departments d ON s.department_id = d.id
        WHERE s.id = ?
    ");
    $stmtStation->execute([$timekeeperStationId]);
    $stationData = $stmtStation->fetch();
    
    if (!$stationData) {
        echo json_encode([
            'success' => false,
            'message' => 'Station not found. Cannot filter by department.'
        ]);
        exit();
    }
    
    $stationDepartmentName = trim($stationData['department_name']);
    
    // Fetch employees from users and faculty_profiles, filtered by department
    // Only show employees whose department matches the timekeeper's station department
    $sql = "
        SELECT 
            u.id as user_id,
            u.email,
            u.first_name,
            u.last_name,
            u.middle_name,
            u.user_type,
            u.is_active,
            fp.employee_id,
            fp.department,
            fp.position,
            fp.employment_status,
            fp.hire_date,
            fp.phone,
            CONCAT(u.first_name, ' ', COALESCE(u.middle_name, ''), ' ', u.last_name) as full_name
        FROM users u
        LEFT JOIN faculty_profiles fp ON u.id = fp.user_id
        WHERE u.user_type IN ('faculty', 'staff')
        AND fp.department IS NOT NULL
        AND TRIM(fp.department) != ''
        AND LOWER(TRIM(fp.department)) = LOWER(?)
        ORDER BY u.last_name ASC, u.first_name ASC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$stationDepartmentName]);
    $employees = $stmt->fetchAll();
    
    // Format the data
    $formattedEmployees = [];
    foreach ($employees as $row) {
        $formattedEmployees[] = [
            'user_id' => $row['user_id'],
            'employee_id' => $row['employee_id'] ?? 'N/A',
            'name' => trim($row['full_name']),
            'email' => $row['email'],
            'department' => $row['department'] ?? 'N/A',
            'position' => $row['position'] ?? 'N/A',
            'employment_status' => $row['employment_status'] ?? 'N/A',
            'hire_date' => $row['hire_date'],
            'phone' => $row['phone'] ?? 'N/A',
            'status' => $row['is_active'] ? 'Active' : 'Inactive',
            'user_type' => ucfirst($row['user_type'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'employees' => $formattedEmployees,
        'count' => count($formattedEmployees)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>

