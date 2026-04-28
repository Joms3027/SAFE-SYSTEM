-- Migration: Add station_id and timekeeper_id to attendance_logs table
-- This allows tracking which station and timekeeper recorded each attendance
-- 
-- IMPORTANT: Run these migrations first:
--   1. 20250123_fix_departments_table.sql
--   2. 20250123_create_stations_table.sql
--   3. 20250123_create_timekeepers_table.sql
--
-- If columns already exist, you can skip this migration or manually remove them first

-- Add station_id column
ALTER TABLE attendance_logs 
  ADD COLUMN station_id int(11) DEFAULT NULL AFTER time_out;

-- Add timekeeper_id column
ALTER TABLE attendance_logs 
  ADD COLUMN timekeeper_id int(11) DEFAULT NULL AFTER station_id;

-- Add indexes
ALTER TABLE attendance_logs 
  ADD INDEX idx_station (station_id),
  ADD INDEX idx_timekeeper (timekeeper_id);

-- Add foreign keys
-- Note: These will fail if stations or timekeepers tables don't exist
ALTER TABLE attendance_logs 
  ADD CONSTRAINT fk_attendance_logs_station 
  FOREIGN KEY (station_id) 
  REFERENCES stations(id) 
  ON DELETE SET NULL 
  ON UPDATE CASCADE;

ALTER TABLE attendance_logs 
  ADD CONSTRAINT fk_attendance_logs_timekeeper 
  FOREIGN KEY (timekeeper_id) 
  REFERENCES timekeepers(id) 
  ON DELETE SET NULL 
  ON UPDATE CASCADE;

