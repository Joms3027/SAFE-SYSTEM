-- QUICK FIX: Add OT columns to attendance_logs table
-- Run this SQL in phpMyAdmin or MySQL command line
-- Copy and paste the entire content below

USE wpu_faculty_system;

-- Check if ot_in column exists, if not add it
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'wpu_faculty_system' 
    AND TABLE_NAME = 'attendance_logs' 
    AND COLUMN_NAME = 'ot_in'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE attendance_logs ADD COLUMN ot_in time DEFAULT NULL AFTER time_out;',
    'SELECT "Column ot_in already exists" AS message;'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check if ot_out column exists, if not add it
SET @col_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = 'wpu_faculty_system' 
    AND TABLE_NAME = 'attendance_logs' 
    AND COLUMN_NAME = 'ot_out'
);

SET @sql = IF(@col_exists = 0,
    'ALTER TABLE attendance_logs ADD COLUMN ot_out time DEFAULT NULL AFTER ot_in;',
    'SELECT "Column ot_out already exists" AS message;'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify columns were added
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'wpu_faculty_system'
AND TABLE_NAME = 'attendance_logs'
AND COLUMN_NAME IN ('ot_in', 'ot_out')
ORDER BY ORDINAL_POSITION;

