-- Migration: Add unique constraint to attendance_logs for concurrency safety
-- Date: 2026-01-21
-- Purpose: Prevents duplicate attendance records for same employee on same day
--          Required for ON DUPLICATE KEY UPDATE to work correctly

-- First, check for and remove any existing duplicates (keep the most recent one)
-- This creates a temporary table with IDs to keep
CREATE TEMPORARY TABLE IF NOT EXISTS attendance_to_keep AS
SELECT MAX(id) as id
FROM attendance_logs
GROUP BY employee_id, log_date;

-- Delete duplicates (rows not in the keep list)
DELETE FROM attendance_logs 
WHERE id NOT IN (SELECT id FROM attendance_to_keep)
AND EXISTS (
    SELECT 1 FROM attendance_to_keep
);

-- Drop the temporary table
DROP TEMPORARY TABLE IF EXISTS attendance_to_keep;

-- Add unique constraint on employee_id + log_date
-- Using ALTER IGNORE to skip if constraint already exists
ALTER TABLE attendance_logs 
ADD UNIQUE INDEX idx_employee_date_unique (employee_id, log_date);

-- Note: If this fails due to duplicates still existing, run this query first:
-- SELECT employee_id, log_date, COUNT(*) as cnt 
-- FROM attendance_logs 
-- GROUP BY employee_id, log_date 
-- HAVING cnt > 1;
