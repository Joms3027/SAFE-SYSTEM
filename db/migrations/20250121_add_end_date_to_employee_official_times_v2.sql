-- Migration to update employee_official_times table to use start_date and end_date
-- This migration handles the case where the table might have different structures

-- Step 1: Add start_date column if it doesn't exist
-- Note: This will fail if column already exists, but that's okay - just continue
ALTER TABLE employee_official_times 
ADD COLUMN start_date DATE NOT NULL AFTER employee_id;

-- Step 2: Add end_date column if it doesn't exist  
ALTER TABLE employee_official_times 
ADD COLUMN end_date DATE NULL AFTER start_date;

-- Step 3: Migrate data from old structure to new structure
-- If week_start_date exists, copy it to start_date
UPDATE employee_official_times 
SET start_date = week_start_date 
WHERE (start_date IS NULL OR start_date = '0000-00-00') 
AND EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'employee_official_times' 
            AND COLUMN_NAME = 'week_start_date');

-- If effective_from exists, copy it to start_date
UPDATE employee_official_times 
SET start_date = effective_from 
WHERE (start_date IS NULL OR start_date = '0000-00-00') 
AND EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'employee_official_times' 
            AND COLUMN_NAME = 'effective_from');

-- If effective_to exists and is not the default end date, copy it to end_date
UPDATE employee_official_times 
SET end_date = NULL 
WHERE EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
              WHERE TABLE_SCHEMA = DATABASE() 
              AND TABLE_NAME = 'employee_official_times' 
              AND COLUMN_NAME = 'effective_to')
AND effective_to >= '2099-12-31';

UPDATE employee_official_times 
SET end_date = effective_to 
WHERE end_date IS NULL 
AND EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = 'employee_official_times' 
            AND COLUMN_NAME = 'effective_to')
AND effective_to != '2099-12-31' 
AND effective_to < '2099-12-31';

-- Step 4: Drop old indexes/constraints if they exist
-- Note: These will fail if they don't exist, but that's okay
ALTER TABLE employee_official_times DROP INDEX IF EXISTS unique_employee_week;
ALTER TABLE employee_official_times DROP INDEX IF EXISTS idx_week_start_date;
ALTER TABLE employee_official_times DROP INDEX IF EXISTS idx_employee_week;

-- Step 5: Add new indexes/constraints
-- Note: These will fail if they already exist, but that's okay
ALTER TABLE employee_official_times 
ADD UNIQUE KEY unique_employee_start_date (employee_id, start_date);

ALTER TABLE employee_official_times 
ADD INDEX idx_date_range (employee_id, start_date, end_date);

