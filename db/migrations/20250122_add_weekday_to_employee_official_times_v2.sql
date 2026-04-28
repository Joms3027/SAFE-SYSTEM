-- Migration to ensure weekday column is properly configured
-- Run this if weekday column already exists

-- Step 1: Update existing records to have a default weekday (Monday) if NULL
UPDATE employee_official_times SET weekday = 'Monday' WHERE weekday IS NULL;

-- Step 2: Ensure weekday is NOT NULL (modify if currently nullable)
ALTER TABLE employee_official_times 
MODIFY COLUMN weekday ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL;

-- Step 3: Drop old unique constraint if it exists (ignore error if it doesn't)
-- ALTER TABLE employee_official_times DROP INDEX unique_employee_start_date;

-- Step 4: Add new unique constraint that includes weekday
-- Note: If constraint already exists, skip this step
-- ALTER TABLE employee_official_times 
-- ADD UNIQUE KEY unique_employee_start_weekday (employee_id, start_date, weekday);

-- Step 5: Add index for weekday queries
-- Note: If index already exists, skip this step
-- ALTER TABLE employee_official_times 
-- ADD INDEX idx_weekday (employee_id, weekday, start_date, end_date);

-- All required indexes and constraints should now be in place
-- The weekday column should be NOT NULL with proper constraints

