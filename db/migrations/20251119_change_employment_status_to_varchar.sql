-- Migration: change employment_status from ENUM to VARCHAR to match employment_statuses master table
-- This allows the employment_status column to store any value from the employment_statuses table
-- (e.g., 'CONTRACT', 'PERMANENT', 'TEMPORARY', 'JOB ORDER') instead of being limited to ENUM values

ALTER TABLE `faculty_profiles`
  MODIFY `employment_status` VARCHAR(150) DEFAULT NULL;

-- Run this SQL against your database to apply the change.

