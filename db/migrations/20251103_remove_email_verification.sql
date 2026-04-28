-- Migration: Remove email verification system
-- Date: 2025-11-03
-- Description: Removes email verification columns and marks all existing users as verified

-- Mark all existing users as verified before removing columns
UPDATE users SET is_verified = 1 WHERE is_verified = 0 OR is_verified IS NULL;

-- Remove OTP-related columns from users table
ALTER TABLE users 
DROP COLUMN IF EXISTS otp_code,
DROP COLUMN IF EXISTS otp_expiry;

-- Note: We keep is_verified column but it will always be 1 for all users now
-- This prevents breaking existing queries that reference this column
-- You can optionally remove it later after updating all queries

-- Optional: If you want to completely remove is_verified column, uncomment:
-- ALTER TABLE users DROP COLUMN IF EXISTS is_verified;
