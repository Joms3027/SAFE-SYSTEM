-- Migration: Add OT (Overtime) columns to attendance_logs table
-- This allows tracking overtime hours (OT In and OT Out)
-- 
-- IMPORTANT: This migration checks if columns exist before adding them
-- Run this after: 20250123_add_station_to_attendance_logs.sql (if you need station/timekeeper tracking)

-- Check and add ot_in column (after timekeeper_id if exists, otherwise after time_out)
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'attendance_logs' 
    AND COLUMN_NAME = 'ot_in'
);

SET @timekeeper_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'attendance_logs' 
    AND COLUMN_NAME = 'timekeeper_id'
);

SET @sql = IF(@column_exists = 0, 
    IF(@timekeeper_exists > 0,
        'ALTER TABLE attendance_logs ADD COLUMN ot_in time DEFAULT NULL AFTER timekeeper_id;',
        'ALTER TABLE attendance_logs ADD COLUMN ot_in time DEFAULT NULL AFTER time_out;'
    ),
    'SELECT "Column ot_in already exists" AS message;'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check and add ot_out column (after ot_in)
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'attendance_logs' 
    AND COLUMN_NAME = 'ot_out'
);

SET @sql = IF(@column_exists = 0,
    'ALTER TABLE attendance_logs ADD COLUMN ot_out time DEFAULT NULL AFTER ot_in;',
    'SELECT "Column ot_out already exists" AS message;'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

