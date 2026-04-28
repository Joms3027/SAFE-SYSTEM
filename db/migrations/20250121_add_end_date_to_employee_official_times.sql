-- Migration to add start_date and end_date columns to employee_official_times table
-- This migration adds the new columns without referencing columns that might not exist

-- Step 1: Add start_date column
-- Note: If column already exists, you'll get an error - just ignore it and continue
ALTER TABLE employee_official_times 
ADD COLUMN start_date DATE NOT NULL DEFAULT '2025-01-01' AFTER employee_id;

-- Step 2: Add end_date column  
ALTER TABLE employee_official_times 
ADD COLUMN end_date DATE NULL AFTER start_date;

-- Step 3: If your table has week_start_date column, uncomment and run this:
-- UPDATE employee_official_times SET start_date = week_start_date WHERE start_date = '2025-01-01';

-- Step 4: If your table has effective_from column, uncomment and run this:
-- UPDATE employee_official_times SET start_date = effective_from WHERE start_date = '2025-01-01';

-- Step 5: If your table has effective_to column, uncomment and run these:
-- UPDATE employee_official_times SET end_date = NULL WHERE effective_to >= '2099-12-31';
-- UPDATE employee_official_times SET end_date = effective_to WHERE effective_to < '2099-12-31' AND effective_to != '2099-12-31';

-- Step 6: Drop old indexes if they exist (uncomment if needed)
-- Note: These will fail if indexes don't exist - that's okay, just ignore the error
-- ALTER TABLE employee_official_times DROP INDEX unique_employee_week;
-- ALTER TABLE employee_official_times DROP INDEX idx_week_start_date;
-- ALTER TABLE employee_official_times DROP INDEX idx_employee_week;

-- Step 7: Add new unique constraint
ALTER TABLE employee_official_times 
ADD UNIQUE KEY unique_employee_start_date (employee_id, start_date);

-- Step 8: Add new index for date range queries
ALTER TABLE employee_official_times 
ADD INDEX idx_date_range (employee_id, start_date, end_date);
