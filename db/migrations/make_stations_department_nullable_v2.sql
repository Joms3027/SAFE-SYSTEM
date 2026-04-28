-- Migration: Make stations.department_id nullable for open stations
-- Date: 2025-12-04
-- This allows stations to be open (not tied to a specific department)
-- 
-- IMPORTANT: Run this SQL in two steps:
-- 1. First, check if the foreign key exists by running the SELECT below
-- 2. If it exists, run the DROP FOREIGN KEY command
-- 3. Then run the MODIFY COLUMN command

-- Check if foreign key constraint exists:
-- SELECT CONSTRAINT_NAME 
-- FROM information_schema.TABLE_CONSTRAINTS 
-- WHERE CONSTRAINT_SCHEMA = DATABASE() 
--   AND TABLE_NAME = 'stations' 
--   AND CONSTRAINT_NAME = 'fk_stations_department';

-- Step 1: Drop foreign key constraint (only if it exists - check first using query above)
-- If the constraint doesn't exist, skip this step and go to Step 2
ALTER TABLE stations DROP FOREIGN KEY fk_stations_department;

-- Step 2: Make department_id nullable
ALTER TABLE stations 
MODIFY COLUMN department_id int(11) NULL COMMENT 'Department ID (NULL for open stations)';
