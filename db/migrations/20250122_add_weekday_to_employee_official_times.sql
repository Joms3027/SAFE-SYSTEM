-- Add weekday column to employee_official_times table
-- This allows different official times for different weekdays within a date range

-- Step 1: Check if weekday column exists, if not add it
-- Note: If column already exists, skip this step
-- ALTER TABLE employee_official_times 
-- ADD COLUMN weekday ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NULL AFTER end_date;

-- Step 2: Update existing records to have a default weekday (Monday) if NULL
UPDATE employee_official_times SET weekday = 'Monday' WHERE weekday IS NULL;

-- Step 3: Make weekday NOT NULL after setting defaults (only if it's currently nullable)
-- Check current column definition first - if already NOT NULL, skip this
-- ALTER TABLE employee_official_times 
-- MODIFY COLUMN weekday ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL;

-- Step 4: Drop old unique constraint and create new one that includes weekday
ALTER TABLE employee_official_times 
DROP INDEX IF EXISTS unique_employee_start_date;

-- New unique constraint: one entry per employee per start_date per weekday
ALTER TABLE employee_official_times 
ADD UNIQUE KEY unique_employee_start_weekday (employee_id, start_date, weekday);

-- Step 5: Add index for weekday queries
ALTER TABLE employee_official_times 
ADD INDEX idx_weekday (employee_id, weekday, start_date, end_date);

