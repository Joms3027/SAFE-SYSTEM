<?php
/**
 * Helper script to check tutorial status for debugging
 * This can be accessed by faculty to see their tutorial completion status
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

$database = Database::getInstance();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Check if column exists
$columnExists = false;
try {
    $stmt = $db->query("SHOW COLUMNS FROM faculty_profiles LIKE 'tutorial_completed'");
    $columnExists = $stmt->rowCount() > 0;
} catch (Exception $e) {
    $columnExists = false;
}

// Get faculty profile
$stmt = $db->prepare("SELECT fp.id, fp.user_id, fp.tutorial_completed, u.email, u.first_name, u.last_name 
                      FROM faculty_profiles fp 
                      JOIN users u ON fp.user_id = u.id 
                      WHERE fp.user_id = ?");
$stmt->execute([$user_id]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json');

$response = [
    'user_id' => $user_id,
    'column_exists' => $columnExists,
    'profile_found' => $profile !== false,
    'tutorial_completed' => null,
    'will_show_tutorial' => false,
    'message' => ''
];

if ($profile) {
    if ($columnExists) {
        $tutorial_completed = isset($profile['tutorial_completed']) ? (int)$profile['tutorial_completed'] : 0;
        $response['tutorial_completed'] = $tutorial_completed;
        $response['will_show_tutorial'] = ($tutorial_completed === 0);
        $response['message'] = $tutorial_completed === 1 
            ? 'Tutorial already completed for this account. Will NOT show again.' 
            : 'Tutorial not completed. Will show on next dashboard visit.';
    } else {
        $response['message'] = 'Tutorial column does not exist. Please run the migration first.';
    }
} else {
    $response['message'] = 'Faculty profile not found.';
}

echo json_encode($response, JSON_PRETTY_PRINT);

