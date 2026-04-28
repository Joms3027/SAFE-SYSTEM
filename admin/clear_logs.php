<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

if ($action !== 'clear_old') {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Clear logs older than 30 days
    $stmt = $db->prepare("DELETE FROM system_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $stmt->execute();
    $deletedCount = $stmt->rowCount();
    
    logAction('LOGS_CLEARED', "Cleared $deletedCount old log entries");
    
    echo json_encode(['success' => true, 'count' => $deletedCount]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>






