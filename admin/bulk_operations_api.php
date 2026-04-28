<?php
/**
 * Bulk Operations API
 * Handles bulk action requests
 */

require_once '../includes/config.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/notifications.php';
require_once '../includes/bulk_operations.php';

header('Content-Type: application/json');

// Check if user is admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
$bulkOps = getBulkOperationsManager();

switch ($action) {
    case 'approve_submissions':
        $submissionIds = $_POST['submission_ids'] ?? [];
        $adminNotes = $_POST['admin_notes'] ?? '';
        
        if (!is_array($submissionIds)) {
            $submissionIds = json_decode($submissionIds, true);
        }
        
        $result = $bulkOps->bulkApproveSubmissions($submissionIds, $adminNotes);
        echo json_encode($result);
        break;
        
    case 'reject_submissions':
        $submissionIds = $_POST['submission_ids'] ?? [];
        $adminNotes = $_POST['admin_notes'] ?? '';
        
        if (!is_array($submissionIds)) {
            $submissionIds = json_decode($submissionIds, true);
        }
        
        $result = $bulkOps->bulkRejectSubmissions($submissionIds, $adminNotes);
        echo json_encode($result);
        break;
        
    case 'email_faculty':
        $facultyIds = $_POST['faculty_ids'] ?? [];
        $subject = $_POST['subject'] ?? '';
        $message = $_POST['message'] ?? '';
        $priority = $_POST['priority'] ?? 'normal';
        
        if (!is_array($facultyIds)) {
            $facultyIds = json_decode($facultyIds, true);
        }
        
        if (empty($subject) || empty($message)) {
            echo json_encode(['success' => false, 'message' => 'Subject and message are required']);
            exit;
        }
        
        $result = $bulkOps->bulkEmailFaculty($facultyIds, $subject, $message, $priority);
        echo json_encode($result);
        break;
        
    case 'assign_requirement':
        $facultyIds = $_POST['faculty_ids'] ?? [];
        $requirementId = $_POST['requirement_id'] ?? null;
        
        if (!is_array($facultyIds)) {
            $facultyIds = json_decode($facultyIds, true);
        }
        
        if (!$requirementId) {
            echo json_encode(['success' => false, 'message' => 'Requirement ID is required']);
            exit;
        }
        
        $result = $bulkOps->bulkAssignRequirement($facultyIds, $requirementId);
        echo json_encode($result);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?>
