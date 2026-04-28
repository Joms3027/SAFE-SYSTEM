-- Migration: add staff to user_type enum
ALTER TABLE `users`
  MODIFY COLUMN `user_type` enum('admin','faculty','staff') NOT NULL DEFAULT 'faculty';

-- You can run this SQL against the database to apply the change.