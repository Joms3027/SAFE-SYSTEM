-- Enhanced Notifications System Migration
-- Date: November 1, 2025

-- Create notifications table if not exists, or alter if exists
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL COMMENT 'submission_status, pds_status, new_requirement, deadline_reminder, announcement, system',
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `priority` enum('low','normal','high') DEFAULT 'normal',
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_is_read` (`is_read`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_type` (`type`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create announcements table
CREATE TABLE IF NOT EXISTS `announcements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `content` text NOT NULL,
  `priority` enum('low','normal','high','urgent') DEFAULT 'normal',
  `target_audience` enum('all','faculty','admin') DEFAULT 'all',
  `is_active` tinyint(1) DEFAULT 1,
  `expires_at` datetime DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_is_active` (`is_active`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_announcements_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create activity_log table for enhanced tracking
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `activity_type` varchar(50) NOT NULL,
  `description` text NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `related_entity` varchar(50) DEFAULT NULL COMMENT 'submission, pds, requirement, etc.',
  `related_id` int(11) DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_activity_type` (`activity_type`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_related` (`related_entity`, `related_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add admin_notes column to faculty_submissions if not exists
ALTER TABLE `faculty_submissions` 
ADD COLUMN IF NOT EXISTS `admin_notes` text DEFAULT NULL AFTER `status`;

-- Add version tracking to submissions
ALTER TABLE `faculty_submissions` 
ADD COLUMN IF NOT EXISTS `version` int(11) DEFAULT 1 AFTER `admin_notes`;

-- Add viewed_by_admin flag
ALTER TABLE `faculty_submissions` 
ADD COLUMN IF NOT EXISTS `viewed_by_admin` tinyint(1) DEFAULT 0 AFTER `version`,
ADD COLUMN IF NOT EXISTS `viewed_at` datetime DEFAULT NULL AFTER `viewed_by_admin`;

-- Create indexes for better performance
CREATE INDEX IF NOT EXISTS `idx_submission_status` ON `faculty_submissions` (`status`);
CREATE INDEX IF NOT EXISTS `idx_submission_faculty` ON `faculty_submissions` (`faculty_id`, `status`);
CREATE INDEX IF NOT EXISTS `idx_pds_status` ON `faculty_pds` (`status`);

-- Add fields to requirements table
ALTER TABLE `requirements` 
ADD COLUMN IF NOT EXISTS `category` varchar(100) DEFAULT NULL AFTER `description`,
ADD COLUMN IF NOT EXISTS `file_types_allowed` varchar(255) DEFAULT 'pdf,doc,docx' AFTER `category`,
ADD COLUMN IF NOT EXISTS `max_file_size` int(11) DEFAULT 5242880 COMMENT 'Size in bytes' AFTER `file_types_allowed`;

-- Create email_templates table
CREATE TABLE IF NOT EXISTS `email_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `variables` json DEFAULT NULL COMMENT 'Available template variables',
  `type` varchar(50) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default email templates
INSERT INTO `email_templates` (`name`, `subject`, `body`, `variables`, `type`) VALUES
('submission_approved', 'Submission Approved - {{requirement_title}}', 
'<p>Dear {{faculty_name}},</p><p>Your submission for <strong>{{requirement_title}}</strong> has been approved.</p><p>Thank you for your timely submission.</p><p>Best regards,<br>WPU Administration</p>', 
'["faculty_name", "requirement_title", "submission_date"]', 'submission'),

('submission_rejected', 'Submission Needs Revision - {{requirement_title}}', 
'<p>Dear {{faculty_name}},</p><p>Your submission for <strong>{{requirement_title}}</strong> needs revision.</p><p><strong>Feedback:</strong><br>{{feedback}}</p><p>Please resubmit after making the necessary corrections.</p><p>Best regards,<br>WPU Administration</p>', 
'["faculty_name", "requirement_title", "feedback", "submission_date"]', 'submission'),

('deadline_reminder', 'Deadline Reminder - {{requirement_title}}', 
'<p>Dear {{faculty_name}},</p><p>This is a reminder that the deadline for <strong>{{requirement_title}}</strong> is approaching.</p><p><strong>Deadline:</strong> {{deadline}}<br><strong>Days Remaining:</strong> {{days_left}}</p><p>Please ensure you submit before the deadline.</p><p>Best regards,<br>WPU Administration</p>', 
'["faculty_name", "requirement_title", "deadline", "days_left"]', 'reminder'),

('pds_approved', 'Personal Data Sheet Approved', 
'<p>Dear {{faculty_name}},</p><p>Your Personal Data Sheet (PDS) has been reviewed and approved.</p><p>Thank you for completing your profile.</p><p>Best regards,<br>WPU Administration</p>', 
'["faculty_name", "approval_date"]', 'pds'),

('new_requirement', 'New Requirement - {{requirement_title}}', 
'<p>Dear {{faculty_name}},</p><p>A new requirement has been added: <strong>{{requirement_title}}</strong></p><p>{{description}}</p><p><strong>Deadline:</strong> {{deadline}}</p><p>Please log in to the system to submit your documents.</p><p>Best regards,<br>WPU Administration</p>', 
'["faculty_name", "requirement_title", "description", "deadline"]', 'requirement')
ON DUPLICATE KEY UPDATE `updated_at` = CURRENT_TIMESTAMP;
