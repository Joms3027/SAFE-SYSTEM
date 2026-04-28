-- Migration: Add OT (Overtime) columns to attendance_logs table
-- This allows tracking overtime hours (OT In and OT Out)
-- 
-- IMPORTANT: Run this migration to add OT columns
-- If columns already exist, you'll get an error - that's okay, just skip this migration

-- Add ot_in column (after timekeeper_id if it exists, otherwise after time_out)
-- If you get an error that the column already exists, skip to the next statement
ALTER TABLE attendance_logs 
  ADD COLUMN ot_in time DEFAULT NULL;

-- Add ot_out column (after ot_in)
-- If you get an error that the column already exists, that's okay
ALTER TABLE attendance_logs 
  ADD COLUMN ot_out time DEFAULT NULL;

-- Note: If you need to specify column position, you can use:
-- ALTER TABLE attendance_logs ADD COLUMN ot_in time DEFAULT NULL AFTER timekeeper_id;
-- ALTER TABLE attendance_logs ADD COLUMN ot_out time DEFAULT NULL AFTER ot_in;
-- But MySQL will place them at the end if the AFTER column doesn't exist

