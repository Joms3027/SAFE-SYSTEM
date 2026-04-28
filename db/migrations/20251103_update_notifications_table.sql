-- Migration: Update notifications table schema
-- Date: 2025-11-03
-- Description: Add missing columns for enhanced notification system

-- Add title column
ALTER TABLE `notifications` 
ADD COLUMN `title` VARCHAR(255) NOT NULL DEFAULT 'Notification' AFTER `type`;

-- Add link_url column
ALTER TABLE `notifications` 
ADD COLUMN `link_url` VARCHAR(500) DEFAULT NULL AFTER `message`;

-- Add priority column
ALTER TABLE `notifications` 
ADD COLUMN `priority` ENUM('low', 'normal', 'high', 'urgent') DEFAULT 'normal' AFTER `link_url`;

-- Add read_at column
ALTER TABLE `notifications` 
ADD COLUMN `read_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_read`;

-- Update existing rows to set default title based on type
UPDATE `notifications` 
SET `title` = CASE 
    WHEN `type` = 'submission' THEN '📄 Submission Update'
    WHEN `type` = 'announcement' THEN '📢 Announcement'
    WHEN `type` = 'requirement' THEN '📋 New Requirement'
    ELSE 'Notification'
END
WHERE `title` = 'Notification';

-- Add index for better query performance
CREATE INDEX idx_user_read ON `notifications` (`user_id`, `is_read`);
CREATE INDEX idx_created_at ON `notifications` (`created_at` DESC);
