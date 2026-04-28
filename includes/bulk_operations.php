<?php
/**
 * Bulk Operations Manager
 * Handles bulk actions for submissions, faculty, and requirements
 */

class BulkOperationsManager {
    private $db;
    
    public function __construct() {
        $database = Database::getInstance();
        $this->db = $database->getConnection();
    }
    
    /**
     * Bulk approve submissions
     */
    public function bulkApproveSubmissions($submissionIds, $adminNotes = '') {
        if (empty($submissionIds) || !is_array($submissionIds)) {
            return ['success' => false, 'message' => 'No submissions selected'];
        }
        
        try {
            $this->db->beginTransaction();
            $notificationManager = getNotificationManager();
            
            $placeholders = str_repeat('?,', count($submissionIds) - 1) . '?';
            $stmt = $this->db->prepare("
                SELECT fs.id, fs.faculty_id, r.title as requirement_title
                FROM faculty_submissions fs
                JOIN requirements r ON fs.requirement_id = r.id
                WHERE fs.id IN ($placeholders)
            ");
            $stmt->execute($submissionIds);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Update submissions
            $updateStmt = $this->db->prepare("
                UPDATE faculty_submissions 
                SET status = 'approved', 
                    admin_notes = ?,
                    reviewed_at = NOW()
                WHERE id = ?
            ");
            
            $successCount = 0;
            foreach ($submissions as $submission) {
                if ($updateStmt->execute([$adminNotes, $submission['id']])) {
                    // Send notification
                    $notificationManager->notifySubmissionStatus(
                        $submission['faculty_id'],
                        $submission['requirement_title'],
                        'approved',
                        $submission['id']
                    );
                    $successCount++;
                }
            }
            
            $this->db->commit();
            logAction('BULK_APPROVE', "Bulk approved $successCount submissions");
            
            return [
                'success' => true,
                'message' => "$successCount submission(s) approved successfully"
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Bulk approve error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error approving submissions'];
        }
    }
    
    /**
     * Bulk reject submissions
     */
    public function bulkRejectSubmissions($submissionIds, $adminNotes = '') {
        if (empty($submissionIds) || !is_array($submissionIds)) {
            return ['success' => false, 'message' => 'No submissions selected'];
        }
        
        try {
            $this->db->beginTransaction();
            $notificationManager = getNotificationManager();
            
            $placeholders = str_repeat('?,', count($submissionIds) - 1) . '?';
            $stmt = $this->db->prepare("
                SELECT fs.id, fs.faculty_id, r.title as requirement_title
                FROM faculty_submissions fs
                JOIN requirements r ON fs.requirement_id = r.id
                WHERE fs.id IN ($placeholders)
            ");
            $stmt->execute($submissionIds);
            $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Update submissions
            $updateStmt = $this->db->prepare("
                UPDATE faculty_submissions 
                SET status = 'rejected', 
                    admin_notes = ?,
                    reviewed_at = NOW()
                WHERE id = ?
            ");
            
            $successCount = 0;
            foreach ($submissions as $submission) {
                if ($updateStmt->execute([$adminNotes, $submission['id']])) {
                    // Send notification
                    $notificationManager->notifySubmissionStatus(
                        $submission['faculty_id'],
                        $submission['requirement_title'],
                        'rejected',
                        $submission['id']
                    );
                    $successCount++;
                }
            }
            
            $this->db->commit();
            logAction('BULK_REJECT', "Bulk rejected $successCount submissions");
            
            return [
                'success' => true,
                'message' => "$successCount submission(s) rejected successfully"
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Bulk reject error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error rejecting submissions'];
        }
    }
    
    /**
     * Bulk send email to faculty
     */
    public function bulkEmailFaculty($facultyIds, $subject, $message, $priority = 'normal') {
        if (empty($facultyIds) || !is_array($facultyIds)) {
            return ['success' => false, 'message' => 'No faculty selected'];
        }
        
        try {
            $notificationManager = getNotificationManager();
            
            $placeholders = str_repeat('?,', count($facultyIds) - 1) . '?';
            $stmt = $this->db->prepare("
                SELECT id, email, first_name, last_name
                FROM users
                WHERE id IN ($placeholders) AND user_type = 'faculty'
            ");
            $stmt->execute($facultyIds);
            $faculty = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $successCount = 0;
            foreach ($faculty as $member) {
                // Create notification
                if ($notificationManager->createNotification(
                    $member['id'],
                    'announcement',
                    $subject,
                    $message,
                    null,
                    $priority
                )) {
                    $successCount++;
                }
            }
            
            logAction('BULK_EMAIL', "Bulk email sent to $successCount faculty members");
            
            return [
                'success' => true,
                'message' => "Message sent to $successCount faculty member(s)"
            ];
            
        } catch (Exception $e) {
            error_log("Bulk email error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error sending messages'];
        }
    }
    
    /**
     * Bulk assign requirement to faculty
     */
    public function bulkAssignRequirement($facultyIds, $requirementId) {
        if (empty($facultyIds) || !is_array($facultyIds)) {
            return ['success' => false, 'message' => 'No faculty selected'];
        }
        
        try {
            $notificationManager = getNotificationManager();
            
            // Get requirement details
            $stmt = $this->db->prepare("SELECT title, deadline FROM requirements WHERE id = ?");
            $stmt->execute([$requirementId]);
            $requirement = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$requirement) {
                return ['success' => false, 'message' => 'Requirement not found'];
            }
            
            // Notify all selected faculty
            $placeholders = str_repeat('?,', count($facultyIds) - 1) . '?';
            $stmt = $this->db->prepare("
                SELECT id FROM users 
                WHERE id IN ($placeholders) AND user_type = 'faculty'
            ");
            $stmt->execute($facultyIds);
            $faculty = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            $successCount = 0;
            foreach ($faculty as $facultyId) {
                if ($notificationManager->notifyNewRequirement(
                    $facultyId,
                    $requirement['title'],
                    $requirementId,
                    $requirement['deadline']
                )) {
                    $successCount++;
                }
            }
            
            logAction('BULK_ASSIGN', "Bulk assigned requirement to $successCount faculty members");
            
            return [
                'success' => true,
                'message' => "Requirement assigned to $successCount faculty member(s)"
            ];
            
        } catch (Exception $e) {
            error_log("Bulk assign error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error assigning requirement'];
        }
    }
    
    /**
     * Bulk delete notifications
     */
    public function bulkDeleteNotifications($notificationIds, $userId) {
        if (empty($notificationIds) || !is_array($notificationIds)) {
            return ['success' => false, 'message' => 'No notifications selected'];
        }
        
        try {
            $placeholders = str_repeat('?,', count($notificationIds) - 1) . '?';
            $params = array_merge($notificationIds, [$userId]);
            
            $stmt = $this->db->prepare("
                DELETE FROM notifications 
                WHERE id IN ($placeholders) AND user_id = ?
            ");
            
            $stmt->execute($params);
            $deletedCount = $stmt->rowCount();
            
            return [
                'success' => true,
                'message' => "$deletedCount notification(s) deleted"
            ];
            
        } catch (Exception $e) {
            error_log("Bulk delete notifications error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error deleting notifications'];
        }
    }
}

/**
 * Helper function to get bulk operations manager instance
 */
function getBulkOperationsManager() {
    static $instance = null;
    if ($instance === null) {
        $instance = new BulkOperationsManager();
    }
    return $instance;
}
?>
