-- Add VERIFIED columns to official_time_requests: who verified, date and time.
-- Populated when Super Admin approves a request.
ALTER TABLE official_time_requests
ADD COLUMN verified_at DATETIME NULL DEFAULT NULL COMMENT 'When verified (date and time)' AFTER super_admin_approved_by,
ADD COLUMN verified_by INT(11) NULL DEFAULT NULL COMMENT 'User ID who verified' AFTER verified_at;
