-- Migration: add super_admin to user_type enum
-- Super admins have exclusive access to: delete/deactivate employees, add/delete admins, weekly pardon limit
-- Ordinary admins cannot see super admin accounts in Admin User Management

ALTER TABLE `users`
  MODIFY COLUMN `user_type` enum('super_admin','admin','faculty','staff') NOT NULL DEFAULT 'faculty';
