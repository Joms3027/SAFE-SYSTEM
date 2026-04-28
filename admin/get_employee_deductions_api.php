<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();
if (function_exists('closeSessionEarly')) { closeSessionEarly(); }

header('Content-Type: application/json');

if (!isset($_GET['employee_id'])) {
    echo json_encode(['success' => false, 'message' => 'Safe Employee ID not provided']);
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    $employee_id = $_GET['employee_id'];
    
    // Fetch employee deductions with deduction details
    $stmt = $db->prepare("
        SELECT 
            ed.id as ed_id,
            ed.employee_id,
            ed.deduction_id,
            ed.amount,
            ed.start_date,
            ed.end_date,
            ed.remarks,
            ed.is_active,
            d.item_name,
            d.type,
            d.dr_cr
        FROM employee_deductions ed
        JOIN deductions d ON ed.deduction_id = d.id
        WHERE ed.employee_id = ?
        ORDER BY ed.start_date DESC
    ");
    $stmt->execute([$employee_id]);
    $deductions = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'deductions' => $deductions,
        'count' => count($deductions)
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching employee deductions: ' . $e->getMessage()
    ]);
}
?>

