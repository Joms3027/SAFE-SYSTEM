-- SIMPLER VERSION: Fix duplicate/zero IDs in faculty_pds table
-- Use this if the main migration script has issues

-- Step 1: Fix all rows with id = 0
-- First, find the max ID
SET @max_id = (SELECT COALESCE(MAX(id), 0) FROM faculty_pds WHERE id > 0);

-- Update each row with id = 0 individually using a stored procedure approach
-- Or use this simpler method: update all zeros to negative values first, then reassign

-- Method: Set all zeros to a temporary negative value to avoid conflicts
UPDATE faculty_pds SET id = -1 WHERE id = 0;

-- Now assign new IDs starting from max_id + 1
-- Note: This requires running multiple UPDATE statements or using a loop
-- For a quick fix, you can manually run this for each row, or use:

-- Alternative: Use a single UPDATE with a subquery (may need adjustment based on your MySQL version)
UPDATE faculty_pds fp1
SET fp1.id = (
    SELECT @max_id := @max_id + 1
)
WHERE fp1.id = -1
ORDER BY fp1.faculty_id
LIMIT 1;

-- Repeat the above UPDATE statement until no more rows have id = -1
-- Or use a stored procedure (see below)

-- Step 2: After fixing zeros, check for duplicates
-- SELECT id, COUNT(*) FROM faculty_pds GROUP BY id HAVING COUNT(*) > 1;

-- Step 3: Fix duplicates manually or use the main migration script

-- Step 4: Add PRIMARY KEY
ALTER TABLE `faculty_pds` 
MODIFY COLUMN `id` int(11) NOT NULL AUTO_INCREMENT,
ADD PRIMARY KEY (`id`);

