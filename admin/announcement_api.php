<?php
// Prevent any output before JSON
ob_start();

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

// Clear any output that might have been generated
ob_clean();

// Set JSON header first
header('Content-Type: application/json');

// Check authentication manually (don't use requireAuth/requireAdmin as they redirect)
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Authentication required. Please log in.'
    ]);
    exit();
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Access denied. Admin privileges required.'
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$action = $_POST['action'] ?? '';

if ($action !== 'delete') {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
    exit();
}

$announcementId = (int)($_POST['announcement_id'] ?? 0);

if (!$announcementId) {
    echo json_encode(['success' => false, 'message' => 'Invalid announcement ID']);
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Check if announcement exists
    $stmt = $db->prepare("SELECT id, title FROM announcements WHERE id = ?");
    $stmt->execute([$announcementId]);
    $announcement = $stmt->fetch();
    
    if (!$announcement) {
        echo json_encode(['success' => false, 'message' => 'Announcement not found']);
        exit();
    }
    
    // Delete announcement
    $stmt = $db->prepare("DELETE FROM announcements WHERE id = ?");
    
    if ($stmt->execute([$announcementId])) {
        logAction('ANNOUNCEMENT_DELETE', "Deleted announcement ID: $announcementId - {$announcement['title']}");
        echo json_encode([
            'success' => true,
            'message' => 'Announcement deleted successfully!'
        ]);
    } else {
        throw new Exception('Failed to delete announcement.');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error deleting announcement: ' . $e->getMessage()
    ]);
}
