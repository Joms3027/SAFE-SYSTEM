<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();
if (function_exists('closeSessionEarly')) { closeSessionEarly(); }

header('Content-Type: application/json');

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Fetch all active deductions
    $stmt = $db->query("SELECT id, item_name, type FROM deductions WHERE is_active = 1 ORDER BY order_num ASC");
    $deductions = $stmt->fetchAll();
    
    echo json_encode([
        'success' => true,
        'deductions' => $deductions
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching deductions: ' . $e->getMessage()
    ]);
}
?>

