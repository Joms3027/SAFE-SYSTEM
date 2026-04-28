<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

requireAdmin();
if (!isSuperAdmin()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Access denied. Only super admins can delete employee accounts.']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$facultyId = (int)($_POST['faculty_id'] ?? 0);

if (!$facultyId) {
    echo json_encode(['success' => false, 'message' => 'Invalid faculty ID']);
    exit();
}

try {
    $database = Database::getInstance();
    $db = $database->getConnection();
    
    // Verify faculty/staff exists and is not an admin
    $stmt = $db->prepare("SELECT id, first_name, last_name, user_type FROM users WHERE id = ? AND user_type IN ('faculty', 'staff')");
    $stmt->execute([$facultyId]);
    $faculty = $stmt->fetch();
    
    if (!$faculty) {
        echo json_encode(['success' => false, 'message' => 'Faculty member not found or cannot be deleted']);
        exit();
    }
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        // Get faculty profile to delete associated files
        $stmt = $db->prepare("SELECT profile_picture, employee_id FROM faculty_profiles WHERE user_id = ?");
        $stmt->execute([$facultyId]);
        $profile = $stmt->fetch();
        
        // Delete profile picture if exists
        if ($profile && !empty($profile['profile_picture'])) {
            $profilePicturePath = '../uploads/profiles/' . $profile['profile_picture'];
            if (file_exists($profilePicturePath)) {
                @unlink($profilePicturePath);
            }
        }
        
        // PRESERVE pardon requests for historical records
        // Update pardon requests to store employee information before account deletion
        if ($profile && !empty($profile['employee_id'])) {
            $employeeId = $profile['employee_id'];
            
            // Store employee information in pardon requests for historical preservation
            $stmt = $db->prepare("
                UPDATE pardon_requests pr
                SET pr.employee_first_name = ?,
                    pr.employee_last_name = ?,
                    pr.employee_department = (SELECT department FROM faculty_profiles WHERE employee_id = ? LIMIT 1)
                WHERE pr.employee_id = ?
                  AND (pr.employee_first_name IS NULL OR pr.employee_last_name IS NULL)
            ");
            $stmt->execute([
                $faculty['first_name'],
                $faculty['last_name'],
                $employeeId,
                $employeeId
            ]);
            
            // Note: Pardon requests are NOT deleted to maintain historical records
            // Supporting documents are kept for compliance and audit purposes
        }
        
        // Delete related records (cascading delete)
        // Note: Foreign key constraints will handle most cascading deletes
        
        // Delete faculty PDS records (will cascade to related PDS tables)
        $stmt = $db->prepare("DELETE FROM faculty_pds WHERE faculty_id = ?");
        $stmt->execute([$facultyId]);
        
        // Delete faculty submissions and files
        $stmt = $db->prepare("SELECT file_path FROM faculty_submissions WHERE faculty_id = ?");
        $stmt->execute([$facultyId]);
        $submissions = $stmt->fetchAll();
        foreach ($submissions as $submission) {
            if (!empty($submission['file_path'])) {
                $submissionPath = '../uploads/' . $submission['file_path'];
                if (file_exists($submissionPath)) {
                    @unlink($submissionPath);
                }
            }
        }
        $stmt = $db->prepare("DELETE FROM faculty_submissions WHERE faculty_id = ?");
        $stmt->execute([$facultyId]);
        
        // Delete faculty requirement assignments
        $stmt = $db->prepare("DELETE FROM faculty_requirements WHERE faculty_id = ?");
        $stmt->execute([$facultyId]);
        
        // Delete notifications
        $stmt = $db->prepare("DELETE FROM notifications WHERE user_id = ?");
        $stmt->execute([$facultyId]);
        
        // Delete activity logs
        $stmt = $db->prepare("DELETE FROM activity_log WHERE user_id = ?");
        $stmt->execute([$facultyId]);
        
        // Delete system logs
        $stmt = $db->prepare("DELETE FROM system_logs WHERE user_id = ?");
        $stmt->execute([$facultyId]);
        
        // Delete faculty profile
        $stmt = $db->prepare("DELETE FROM faculty_profiles WHERE user_id = ?");
        $stmt->execute([$facultyId]);
        
        // Finally, delete the user account
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$facultyId]);
        
        // Commit transaction
        $db->commit();
        
        // Log the action
        $userTypeLabel = $faculty['user_type'] === 'staff' ? 'staff' : 'faculty';
        logAction('FACULTY_DELETED', "Deleted {$userTypeLabel} account: {$faculty['first_name']} {$faculty['last_name']} (ID: $facultyId)");
        
        echo json_encode([
            'success' => true, 
            'message' => ucfirst($userTypeLabel) . ' account and all associated data have been permanently deleted.'
        ]);
        
    } catch (Exception $e) {
        // Rollback transaction on error
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to delete faculty account: ' . $e->getMessage()
    ]);
}
?>
