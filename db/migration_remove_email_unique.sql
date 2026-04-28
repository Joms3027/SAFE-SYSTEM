-- Migration: Remove UNIQUE constraint from email column
-- This allows the same email to be used for admin and faculty/staff accounts
-- Date: 2025-11-18

-- Remove the UNIQUE constraint from the email column
ALTER TABLE `users` DROP INDEX `email`;

-- Note: The email index is kept for performance (non-unique index)
-- The existing idx_users_email index will remain for query optimization

