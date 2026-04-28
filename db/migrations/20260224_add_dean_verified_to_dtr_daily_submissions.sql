-- Migration: Add dean_verified_at to dtr_daily_submissions for tracking dean verification
-- When dean views a DTR, it is marked as verified. Batch verify marks all unverified in filter.
-- Run: mysql -u user -p database < 20260224_add_dean_verified_to_dtr_daily_submissions.sql

ALTER TABLE dtr_daily_submissions
ADD COLUMN dean_verified_at DATETIME NULL DEFAULT NULL COMMENT 'When dean verified this submission' AFTER submitted_at,
ADD KEY idx_dtr_daily_dean_verified (dean_verified_at);
