<?php
/**
 * JSON: past TARF/NTARF rows the current user endorsed or rejected in a given workflow role.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/tarf_workflow.php';
require_once __DIR__ . '/../includes/tarf_endorser_history.php';

requireAuth();

header('Content-Type: application/json; charset=utf-8');

if (!isFaculty() && !isStaff()) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$role = trim((string) ($_GET['role'] ?? ''));
if (!in_array($role, ['supervisor', 'endorser', 'fund_availability', 'president'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid role.']);
    exit;
}

$database = Database::getInstance();
$db = $database->getConnection();
$uid = (int) $_SESSION['user_id'];

if (!tarf_endorser_history_user_may_access($db, $uid, $role)) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$basePath = getBasePath();
$rows = tarf_endorser_history_fetch($db, $uid, $role, $basePath);

echo json_encode(['success' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE);
