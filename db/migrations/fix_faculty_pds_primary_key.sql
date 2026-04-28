-- Fix duplicate/zero IDs in faculty_pds table and add PRIMARY KEY
-- This migration fixes the issue where multiple rows have id = 0 or duplicate IDs
-- Run this before adding the PRIMARY KEY constraint

-- Step 1: Check for problematic rows (for reference - uncomment to run)
-- SELECT id, faculty_id, COUNT(*) as count 
-- FROM faculty_pds 
-- GROUP BY id 
-- HAVING count > 1 OR id = 0;

-- Step 2: Fix rows with id = 0 first
-- Get the maximum ID value
SET @max_id = (SELECT COALESCE(MAX(id), 0) FROM faculty_pds WHERE id > 0);

-- Create a temporary table with rows that have id = 0
CREATE TEMPORARY TABLE temp_zero_fix AS
SELECT 
    faculty_id,
    ROW_NUMBER() OVER (ORDER BY faculty_id) as seq_num
FROM faculty_pds
WHERE id = 0;

-- Update rows with id = 0
UPDATE faculty_pds fp
INNER JOIN temp_zero_fix tzf ON fp.faculty_id = tzf.faculty_id AND fp.id = 0
SET fp.id = @max_id + tzf.seq_num;

DROP TEMPORARY TABLE IF EXISTS temp_zero_fix;

-- Step 3: Fix duplicate IDs
-- Create a temporary table to identify duplicates
CREATE TEMPORARY TABLE temp_duplicates AS
SELECT 
    id,
    COUNT(*) as cnt
FROM faculty_pds
GROUP BY id
HAVING COUNT(*) > 1;

-- If duplicates exist, fix them
-- Create a table with duplicate rows and their rank
CREATE TEMPORARY TABLE temp_dup_rows AS
SELECT 
    fp.id,
    fp.faculty_id,
    ROW_NUMBER() OVER (PARTITION BY fp.id ORDER BY fp.faculty_id) as rnk
FROM faculty_pds fp
INNER JOIN temp_duplicates td ON fp.id = td.id;

-- Get new starting point for IDs
SET @new_start = (SELECT COALESCE(MAX(id), 0) FROM faculty_pds);

-- Create mapping for new IDs
CREATE TEMPORARY TABLE temp_new_id_map AS
SELECT 
    tdr.id as old_id,
    tdr.faculty_id,
    @new_start + ROW_NUMBER() OVER (ORDER BY tdr.id, tdr.faculty_id) as new_id
FROM temp_dup_rows tdr
WHERE tdr.rnk > 1;

-- Update the duplicate rows
UPDATE faculty_pds fp
INNER JOIN temp_new_id_map tnim ON fp.id = tnim.old_id AND fp.faculty_id = tnim.faculty_id
SET fp.id = tnim.new_id;

-- Clean up
DROP TEMPORARY TABLE IF EXISTS temp_duplicates;
DROP TEMPORARY TABLE IF EXISTS temp_dup_rows;
DROP TEMPORARY TABLE IF EXISTS temp_new_id_map;

-- Step 4: Verify no duplicates remain (uncomment to check)
-- SELECT id, COUNT(*) as count 
-- FROM faculty_pds 
-- GROUP BY id 
-- HAVING count > 1;

-- Step 5: Add PRIMARY KEY constraint
-- First modify the column to support AUTO_INCREMENT
ALTER TABLE `faculty_pds` 
MODIFY COLUMN `id` int(11) NOT NULL AUTO_INCREMENT;

-- Add the PRIMARY KEY
ALTER TABLE `faculty_pds` 
ADD PRIMARY KEY (`id`);

-- Verification query (uncomment to run)
-- SHOW CREATE TABLE faculty_pds;
