<?php
/**
 * HTML fragment: DISAPP-style TARF card (for modals / AJAX). Same access rules as tarf_request_view.php.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/tarf_disapp_view_render.php';
require_once __DIR__ . '/../includes/tarf_workflow.php';

requireFaculty();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid request.';
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();

$stmt = $db->prepare('SELECT * FROM tarf_requests WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$viewerId = (int) $_SESSION['user_id'];
if (!$row || !tarf_user_can_view_request($row, $viewerId, $db)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Access denied.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
echo tarf_render_disapp_card_html($db, $row, $viewerId);
