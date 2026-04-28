<?php
require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/auth.php';

// Ensure user is admin
requireAdmin();

if (!isset($_GET['requirement_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Requirement ID not provided']);
    exit;
}

$requirementId = (int)$_GET['requirement_id'];

$database = Database::getInstance();
$db = $database->getConnection();

// Get faculty IDs assigned to this requirement
$stmt = $db->prepare("SELECT faculty_id FROM faculty_requirements WHERE requirement_id = ?");
$stmt->execute([$requirementId]);
$assignedFaculty = $stmt->fetchAll(PDO::FETCH_COLUMN);

header('Content-Type: application/json');
echo json_encode($assignedFaculty);