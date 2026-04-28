-- Add dean_verified_by to dtr_daily_submissions to track who verified each DTR.
-- Run: mysql -u user -p database < 20260314_add_dean_verified_by_to_dtr_daily_submissions.sql

ALTER TABLE dtr_daily_submissions
ADD COLUMN dean_verified_by INT(11) NULL DEFAULT NULL COMMENT 'User ID who verified (Dean or Pardon Opener)' AFTER dean_verified_at,
ADD KEY idx_dtr_daily_dean_verified_by (dean_verified_by);
