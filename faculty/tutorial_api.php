<?php
/**
 * Tutorial API - Mark tutorial as completed
 */

require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireFaculty();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'complete_tutorial') {
    try {
        $database = Database::getInstance();
        $db = $database->getConnection();
        
        $user_id = $_SESSION['user_id'];
        
        // Get faculty_id from faculty_profiles
        $stmt = $db->prepare("SELECT id FROM faculty_profiles WHERE user_id = ? LIMIT 1");
        $stmt->execute([$user_id]);
        $faculty = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$faculty) {
            echo json_encode(['success' => false, 'message' => 'Faculty profile not found']);
            exit;
        }
        
        $faculty_id = $faculty['id'];
        
        // Check if tutorial_completed column exists
        $stmt = $db->query("SHOW COLUMNS FROM faculty_profiles LIKE 'tutorial_completed'");
        if ($stmt->rowCount() > 0) {
            // Column exists - update tutorial_completed status to 1 for THIS account
            // This ensures the tutorial shows only ONCE per account
            $stmt = $db->prepare("UPDATE faculty_profiles SET tutorial_completed = 1 WHERE id = ? AND user_id = ?");
            $updateResult = $stmt->execute([$faculty_id, $user_id]);
            
            if ($updateResult) {
                // Verify the update was successful by querying the database
                $stmt = $db->prepare("SELECT tutorial_completed, user_id FROM faculty_profiles WHERE id = ? AND user_id = ? LIMIT 1");
                $stmt->execute([$faculty_id, $user_id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result && (int)$result['tutorial_completed'] === 1 && (int)$result['user_id'] === (int)$user_id) {
                    error_log("Tutorial marked as completed for user_id: $user_id, faculty_id: $faculty_id");
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Tutorial marked as completed. This tutorial will not show again for this account.',
                        'user_id' => $user_id,
                        'tutorial_completed' => 1
                    ]);
                } else {
                    error_log("Tutorial completion verification failed for user_id: $user_id");
                    echo json_encode(['success' => false, 'message' => 'Failed to verify tutorial completion status']);
                }
            } else {
                error_log("Tutorial completion update failed for user_id: $user_id");
                echo json_encode(['success' => false, 'message' => 'Failed to update tutorial status']);
            }
        } else {
            // Column doesn't exist - need to run migration first
            error_log("Tutorial completion column does not exist - migration needed for user_id: $user_id");
            echo json_encode([
                'success' => false, 
                'message' => 'Database column not found. Please run the migration: ALTER TABLE faculty_profiles ADD COLUMN tutorial_completed tinyint(1) DEFAULT 0 AFTER qr_code;'
            ]);
        }
    } catch (Exception $e) {
        error_log('Tutorial API Error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error updating tutorial status']);
    }
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

