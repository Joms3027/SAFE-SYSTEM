<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();
if (function_exists('closeSessionEarly')) { closeSessionEarly(); }

header('Content-Type: application/json');

$employee_id = $_GET['employee_id'] ?? '';

if (empty($employee_id)) {
    echo json_encode(['success' => false, 'message' => 'Safe Employee ID is required']);
    exit;
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    $stmt = $db->prepare("
        SELECT 
            fp.employee_id,
            u.first_name,
            u.last_name,
            fp.position,
            fp.department,
            fp.employment_type
        FROM faculty_profiles fp
        INNER JOIN users u ON fp.user_id = u.id
        WHERE fp.employee_id = ?
    ");
    $stmt->execute([$employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($employee) {
        echo json_encode([
            'success' => true,
            'employee' => $employee
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Employee not found'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>

