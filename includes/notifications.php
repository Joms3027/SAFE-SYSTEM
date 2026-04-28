<?php
/**
 * Notification System
 * Handles creation, retrieval, and management of user notifications
 */

class NotificationManager {
    private static $instance = null;
    public $db; // Made public for API access
    
    /** @var bool|null Cache is_hidden column existence for this request (avoids SHOW COLUMNS on every call). */
    private static $hasIsHiddenColumn = null;
    
    /**
     * Whether notifications table has is_hidden (one SHOW COLUMNS per request max).
     */
    private function hasIsHiddenColumn(): bool {
        if (self::$hasIsHiddenColumn !== null) {
            return self::$hasIsHiddenColumn;
        }
        try {
            $checkColumn = $this->db->query("SHOW COLUMNS FROM notifications LIKE 'is_hidden'");
            self::$hasIsHiddenColumn = ($checkColumn && $checkColumn->rowCount() > 0);
        } catch (PDOException $e) {
            self::$hasIsHiddenColumn = false;
        }
        return self::$hasIsHiddenColumn;
    }
    
    private function __construct() {
        $database = Database::getInstance();
        $this->db = $database->getConnection();
    }
    
    /**
     * Get singleton instance of NotificationManager
     * @return NotificationManager
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Create a new notification
     */
    public function createNotification($userId, $type, $title, $message, $linkUrl = null, $priority = 'normal') {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO notifications (user_id, type, title, message, link_url, priority, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            
            return $stmt->execute([$userId, $type, $title, $message, $linkUrl, $priority]);
        } catch (PDOException $e) {
            error_log("Notification creation error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create multiple notifications in one batch insert (faster than one-by-one).
     * @param array $recipients Array of ['id' => int] (user_id)
     * @param string $type Notification type
     * @param string $title Title
     * @param string $message Message
     * @param string|null $linkUrl Link URL
     * @param string $priority Priority
     * @return int Number of notifications created
     */
    public function createNotificationsBatch(array $recipients, $type, $title, $message, $linkUrl = null, $priority = 'normal') {
        if (empty($recipients)) return 0;
        try {
            $placeholders = [];
            $params = [];
            foreach ($recipients as $r) {
                $placeholders[] = "(?, ?, ?, ?, ?, ?, NOW())";
                $params[] = (int)($r['id'] ?? $r['user_id'] ?? 0);
                $params[] = $type;
                $params[] = $title;
                $params[] = $message;
                $params[] = $linkUrl;
                $params[] = $priority;
            }
            $sql = "INSERT INTO notifications (user_id, type, title, message, link_url, priority, created_at) VALUES " . implode(", ", $placeholders);
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            error_log("Batch notification error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Create notifications for all faculty members
     */
    public function notifyAllFaculty($type, $title, $message, $linkUrl = null, $priority = 'normal') {
        try {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE user_type = 'faculty' AND is_active = 1");
            $stmt->execute();
            $faculty = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($faculty as $facultyId) {
                $this->createNotification($facultyId, $type, $title, $message, $linkUrl, $priority);
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("Bulk notification error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get unread notifications for a user
     */
    public function getUnreadNotifications($userId, $limit = 10) {
        try {
            $columnExists = $this->hasIsHiddenColumn();
            
            if ($columnExists) {
                // Filter out hidden notifications
                $stmt = $this->db->prepare("
                    SELECT id, user_id, title, message, type, link_url, priority, is_read, is_hidden, created_at FROM notifications 
                    WHERE user_id = ? AND is_read = 0 AND (is_hidden = 0 OR is_hidden IS NULL)
                    ORDER BY created_at DESC 
                    LIMIT ?
                ");
            } else {
                $stmt = $this->db->prepare("
                    SELECT id, user_id, title, message, type, link_url, priority, is_read, created_at FROM notifications 
                    WHERE user_id = ? AND is_read = 0
                    ORDER BY created_at DESC 
                    LIMIT ?
                ");
            }
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Double-check: filter out any notifications with is_hidden = 1 (in case query didn't work)
            if ($columnExists) {
                $notifications = array_filter($notifications, function($notif) {
                    return (!isset($notif['is_hidden']) || $notif['is_hidden'] == 0) && $notif['is_read'] == 0;
                });
                // Re-index array
                $notifications = array_values($notifications);
            }
            
            return $notifications;
        } catch (PDOException $e) {
            error_log("Get notifications error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all notifications for a user
     */
    public function getAllNotifications($userId, $limit = 50) {
        try {
            $columnExists = $this->hasIsHiddenColumn();
            
            if ($columnExists) {
                // Filter out hidden notifications
                $stmt = $this->db->prepare("
                    SELECT id, user_id, title, message, type, link_url, priority, is_read, is_hidden, created_at FROM notifications 
                    WHERE user_id = ? AND (is_hidden = 0 OR is_hidden IS NULL)
                    ORDER BY created_at DESC 
                    LIMIT ?
                ");
            } else {
                // No is_hidden column, show all notifications
                $stmt = $this->db->prepare("
                    SELECT id, user_id, title, message, type, link_url, priority, is_read, created_at FROM notifications 
                    WHERE user_id = ?
                    ORDER BY created_at DESC 
                    LIMIT ?
                ");
            }
            $stmt->bindValue(1, $userId, PDO::PARAM_INT);
            $stmt->bindValue(2, (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Double-check: filter out any notifications with is_hidden = 1 (in case query didn't work)
            if ($columnExists) {
                $notifications = array_filter($notifications, function($notif) {
                    return !isset($notif['is_hidden']) || $notif['is_hidden'] == 0;
                });
                // Re-index array
                $notifications = array_values($notifications);
            }
            
            return $notifications;
        } catch (PDOException $e) {
            error_log("Get all notifications error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get unread notification count
     */
    public function getUnreadCount($userId) {
        try {
            $columnExists = $this->hasIsHiddenColumn();
            
            if ($columnExists) {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM notifications 
                    WHERE user_id = ? AND is_read = 0 AND (is_hidden = 0 OR is_hidden IS NULL)
                ");
            } else {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM notifications 
                    WHERE user_id = ? AND is_read = 0
                ");
            }
            $stmt->execute([$userId]);
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Get unread count error: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Mark notification as read
     */
    public function markAsRead($notificationId, $userId) {
        try {
            $stmt = $this->db->prepare("
                UPDATE notifications 
                SET is_read = 1, read_at = NOW() 
                WHERE id = ? AND user_id = ?
            ");
            $stmt->execute([$notificationId, $userId]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Mark as read error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark all notifications as read
     */
    public function markAllAsRead($userId) {
        try {
            $columnExists = $this->hasIsHiddenColumn();
            
            if ($columnExists) {
                // Only mark visible (not hidden) notifications as read
                $stmt = $this->db->prepare("
                    UPDATE notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE user_id = ? AND is_read = 0 AND is_hidden = 0
                ");
            } else {
                // Fallback: mark all unread notifications as read
                $stmt = $this->db->prepare("
                    UPDATE notifications 
                    SET is_read = 1, read_at = NOW() 
                    WHERE user_id = ? AND is_read = 0
                ");
            }
            $stmt->execute([$userId]);
            return true; // Return true even if no rows were affected (user might have no unread notifications)
        } catch (PDOException $e) {
            error_log("Mark all as read error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Remove notification (hide it instead of deleting)
     */
    public function deleteNotification($notificationId, $userId) {
        try {
            error_log("deleteNotification called: notificationId=$notificationId, userId=$userId");
            
            $columnExists = $this->hasIsHiddenColumn();
            
            // If column doesn't exist, try to create it automatically
            if (!$columnExists) {
                error_log("is_hidden column does not exist. Attempting to create it...");
                try {
                    // Check if read_at exists to determine placement
                    $checkReadAt = $this->db->query("SHOW COLUMNS FROM notifications LIKE 'read_at'");
                    $readAtExists = $checkReadAt->rowCount() > 0;
                    
                    if ($readAtExists) {
                        $this->db->exec("ALTER TABLE `notifications` ADD COLUMN `is_hidden` TINYINT(1) NOT NULL DEFAULT 0 AFTER `read_at`");
                    } else {
                        $this->db->exec("ALTER TABLE `notifications` ADD COLUMN `is_hidden` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_read`");
                    }
                    
                    // Try to add index
                    try {
                        $this->db->exec("CREATE INDEX idx_is_hidden ON `notifications` (`is_hidden`)");
                    } catch (PDOException $e) {
                        // Index might already exist, ignore error
                        error_log("Index creation skipped: " . $e->getMessage());
                    }
                    
                    $columnExists = true;
                    self::$hasIsHiddenColumn = true;
                    error_log("✓ is_hidden column created successfully");
                } catch (PDOException $e) {
                    error_log("Failed to create is_hidden column: " . $e->getMessage());
                    // Continue with fallback DELETE
                }
            }
            
            if ($columnExists) {
                // Use is_hidden column to hide the notification
                $stmt = $this->db->prepare("
                    UPDATE notifications 
                    SET is_hidden = 1 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$notificationId, $userId]);
                $rowCount = $stmt->rowCount();
                error_log("UPDATE executed. Rows affected: $rowCount");
                
                if ($rowCount === 0) {
                    // Check if notification exists but user_id doesn't match
                    $checkStmt = $this->db->prepare("SELECT id, user_id, is_hidden FROM notifications WHERE id = ?");
                    $checkStmt->execute([$notificationId]);
                    $notification = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    if ($notification) {
                        error_log("Notification exists but user_id mismatch. Notification user_id: " . ($notification['user_id'] ?? 'NULL') . ", Requested user_id: $userId");
                        error_log("Notification is_hidden value: " . ($notification['is_hidden'] ?? 'N/A'));
                    } else {
                        error_log("Notification with ID $notificationId does not exist");
                    }
                } else {
                    // Verify the update worked
                    $verifyStmt = $this->db->prepare("SELECT is_hidden FROM notifications WHERE id = ?");
                    $verifyStmt->execute([$notificationId]);
                    $verify = $verifyStmt->fetch(PDO::FETCH_ASSOC);
                    error_log("Verification: Notification $notificationId is_hidden = " . ($verify['is_hidden'] ?? 'N/A'));
                }
                
                return $rowCount > 0;
            } else {
                // Fallback: If column doesn't exist and we couldn't create it, delete the notification
                error_log("Warning: is_hidden column not found and could not be created. Falling back to DELETE.");
                $stmt = $this->db->prepare("
                    DELETE FROM notifications 
                    WHERE id = ? AND user_id = ?
                ");
                $stmt->execute([$notificationId, $userId]);
                $rowCount = $stmt->rowCount();
                error_log("DELETE executed. Rows affected: $rowCount");
                return $rowCount > 0;
            }
        } catch (PDOException $e) {
            error_log("Remove notification error: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }
    
    /**
     * Delete old read notifications
     */
    public function cleanupOldNotifications($daysOld = 30) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM notifications 
                WHERE is_read = 1 
                AND read_at < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            return $stmt->execute([$daysOld]);
        } catch (PDOException $e) {
            error_log("Cleanup notifications error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notification shortcuts for common events
     */
    
    public function notifySubmissionStatus($facultyId, $requirementTitle, $status, $submissionId) {
        $titles = [
            'approved' => '✅ Submission Approved',
            'rejected' => '❌ Submission Rejected',
            'pending' => '⏳ Submission Under Review'
        ];
        
        $messages = [
            'approved' => "Your submission for '{$requirementTitle}' has been approved.",
            'rejected' => "Your submission for '{$requirementTitle}' needs revision. Please check the feedback.",
            'pending' => "Your submission for '{$requirementTitle}' is being reviewed."
        ];
        
        return $this->createNotification(
            $facultyId,
            'submission_status',
            $titles[$status] ?? 'Submission Update',
            $messages[$status] ?? "Status update for '{$requirementTitle}'",
            "submissions.php?view={$submissionId}",
            $status === 'rejected' ? 'high' : 'normal'
        );
    }
    
    public function notifyPDSStatus($facultyId, $status) {
        $titles = [
            'approved' => '✅ PDS Approved',
            'rejected' => '❌ PDS Needs Revision',
            'submitted' => '⏳ PDS Under Review'
        ];
        
        $messages = [
            'approved' => 'Your Personal Data Sheet has been approved by the administration.',
            'rejected' => 'Your Personal Data Sheet needs revision. Please check the admin notes.',
            'submitted' => 'Your Personal Data Sheet is being reviewed.'
        ];
        
        return $this->createNotification(
            $facultyId,
            'pds_status',
            $titles[$status] ?? 'PDS Update',
            $messages[$status] ?? 'PDS status has been updated',
            'pds.php',
            $status === 'rejected' ? 'high' : 'normal'
        );
    }
    
    public function notifyNewRequirement($facultyId, $requirementTitle, $requirementId, $deadline = null) {
        $message = "A new requirement '{$requirementTitle}' has been added.";
        if ($deadline) {
            $message .= " Deadline: " . date('M j, Y', strtotime($deadline));
        }
        
        return $this->createNotification(
            $facultyId,
            'new_requirement',
            '📋 New Requirement',
            $message,
            "requirements.php?id={$requirementId}",
            'normal'
        );
    }
    
    public function notifyDeadlineApproaching($facultyId, $requirementTitle, $requirementId, $daysLeft) {
        $priority = $daysLeft <= 3 ? 'high' : 'normal';
        $message = "The deadline for '{$requirementTitle}' is approaching ({$daysLeft} days left).";
        
        return $this->createNotification(
            $facultyId,
            'deadline_reminder',
            '⚠️ Deadline Reminder',
            $message,
            "requirements.php?id={$requirementId}",
            $priority
        );
    }
    
    public function notifyAnnouncement($facultyId, $announcementTitle, $announcementMessage, $priority = 'normal') {
        return $this->createNotification(
            $facultyId,
            'announcement',
            '📢 ' . $announcementTitle,
            $announcementMessage,
            null,
            $priority
        );
    }
    
    /**
     * Notify all admin users about a new submission
     */
    public function notifyAdminsNewSubmission($facultyName, $requirementTitle, $submissionId, $requirementId) {
        try {
            // Get all admin users
            $stmt = $this->db->prepare("SELECT id FROM users WHERE user_type IN ('admin', 'super_admin') AND is_active = 1");
            $stmt->execute();
            $adminIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($adminIds)) {
                error_log('No admin users found to notify about submission');
                return false;
            }
            
            $title = '📄 New Submission Received';
            $message = "{$facultyName} submitted a file for requirement: {$requirementTitle}";
            $linkUrl = "submissions.php?id={$submissionId}";
            
            $successCount = 0;
            foreach ($adminIds as $adminId) {
                if ($this->createNotification(
                    $adminId,
                    'submission',
                    $title,
                    $message,
                    $linkUrl,
                    'normal'
                )) {
                    $successCount++;
                }
            }
            
            error_log("Notified {$successCount} admin(s) about new submission from {$facultyName}");
            return $successCount > 0;
        } catch (PDOException $e) {
            error_log("Error notifying admins about submission: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notify all admin users about a new PDS submission
     */
    public function notifyAdminsPDSSubmission($facultyName, $pdsId) {
        try {
            // Get all admin users
            $stmt = $this->db->prepare("SELECT id FROM users WHERE user_type IN ('admin', 'super_admin') AND is_active = 1");
            $stmt->execute();
            $adminIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($adminIds)) {
                error_log('No admin users found to notify about PDS submission');
                return false;
            }
            
            $title = '📋 New PDS Submission';
            $message = "{$facultyName} submitted their Personal Data Sheet for review";
            $linkUrl = "pds_review.php?id={$pdsId}";
            
            $successCount = 0;
            foreach ($adminIds as $adminId) {
                if ($this->createNotification(
                    $adminId,
                    'pds_status',
                    $title,
                    $message,
                    $linkUrl,
                    'normal'
                )) {
                    $successCount++;
                }
            }
            
            error_log("Notified {$successCount} admin(s) about PDS submission from {$facultyName}");
            return $successCount > 0;
        } catch (PDOException $e) {
            error_log("Error notifying admins about PDS submission: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Notify all admin users (including super_admin) about a new pardon request
     */
    public function notifyAdminsPardonRequest($employeeName, $logDate, $requestId) {
        try {
            $stmt = $this->db->prepare("SELECT id FROM users WHERE user_type IN ('admin', 'super_admin') AND is_active = 1");
            $stmt->execute();
            $adminIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($adminIds)) {
                error_log('No admin users found to notify about pardon request');
                return false;
            }
            
            $formattedDate = date('M j, Y', strtotime($logDate));
            $title = '⏰ New Pardon Request';
            $message = "{$employeeName} submitted a pardon request for {$formattedDate}";
            $linkUrl = "pardon_requests.php" . ($requestId ? "?id={$requestId}" : "");
            
            $successCount = 0;
            foreach ($adminIds as $adminId) {
                if ($this->createNotification(
                    $adminId,
                    'pardon_request',
                    $title,
                    $message,
                    $linkUrl,
                    'normal'
                )) {
                    $successCount++;
                }
            }
            
            error_log("Notified {$successCount} admin(s) about pardon request from {$employeeName}");
            return $successCount > 0;
        } catch (PDOException $e) {
            error_log("Error notifying admins about pardon request: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify the employee when their pardon request has been approved (opened).
     * @param int $employeeUserId User ID of the employee (faculty)
     * @param string $pardonDate Date that was pardoned (Y-m-d)
     */
    public function notifyEmployeePardonApproved($employeeUserId, $pardonDate) {
        try {
            $formattedDate = date('M j, Y', strtotime($pardonDate));
            $title = '✅ Pardon Request Approved';
            $message = "Your pardon request for {$formattedDate} has been approved. You can now submit your pardon from View Logs.";
            $linkUrl = 'view_logs.php';

            $result = $this->createNotification(
                (int) $employeeUserId,
                'pardon_approved',
                $title,
                $message,
                $linkUrl,
                'high'
            );
            if ($result) {
                error_log("Notified employee (user_id={$employeeUserId}) about pardon approval for {$pardonDate}");
            }
            return $result;
        } catch (PDOException $e) {
            error_log("Error notifying employee about pardon approval: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify the employee when their pardon request has been rejected.
     * @param int $employeeUserId User ID of the employee (faculty)
     * @param string $pardonDate Date that was requested (Y-m-d)
     * @param string $rejectionComment Optional reason/comment from the pardon opener
     */
    public function notifyEmployeePardonRejected($employeeUserId, $pardonDate, $rejectionComment = '') {
        try {
            $formattedDate = date('M j, Y', strtotime($pardonDate));
            $title = '❌ Pardon Request Rejected';
            $message = "Your pardon request for {$formattedDate} has been rejected.";
            if (!empty(trim($rejectionComment ?? ''))) {
                $message .= " Reason: " . trim($rejectionComment);
            }
            $message .= " You may submit a new request if needed.";
            $linkUrl = 'request_pardon.php';

            $result = $this->createNotification(
                (int) $employeeUserId,
                'pardon_rejected',
                $title,
                $message,
                $linkUrl,
                'high'
            );
            if ($result) {
                error_log("Notified employee (user_id={$employeeUserId}) about pardon rejection for {$pardonDate}");
            }
            return $result;
        } catch (PDOException $e) {
            error_log("Error notifying employee about pardon rejection: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Notify pardon openers about a new pardon request letter (letter + day)
     * @param int[] $openerUserIds User IDs of pardon openers assigned to the employee's dept/designation
     * @param string $employeeName Employee name
     * @param string $pardonDate Date requested (Y-m-d)
     * @param int $requestId pardon_request_letters.id
     */
    public function notifyPardonOpenersForRequestLetter($openerUserIds, $employeeName, $pardonDate, $requestId) {
        if (empty($openerUserIds)) return false;
        try {
            $formattedDate = date('M j, Y', strtotime($pardonDate));
            $title = '📝 New Pardon Request Letter';
            $message = "{$employeeName} submitted a pardon request for {$formattedDate}";
            $linkUrl = "pardon_request_letters.php" . ($requestId ? "?id={$requestId}" : "");

            $successCount = 0;
            foreach ($openerUserIds as $userId) {
                if ($this->createNotification(
                    (int) $userId,
                    'pardon_request_letter',
                    $title,
                    $message,
                    $linkUrl,
                    'normal'
                )) {
                    $successCount++;
                }
            }
            error_log("Notified {$successCount} pardon opener(s) about request letter from {$employeeName}");
            return $successCount > 0;
        } catch (PDOException $e) {
            error_log("Error notifying pardon openers: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * Helper function to get notification manager instance (singleton)
 */
function getNotificationManager() {
    return NotificationManager::getInstance();
}
?>
