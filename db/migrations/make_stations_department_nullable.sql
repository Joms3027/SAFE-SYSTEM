-- Migration: Make stations.department_id nullable for open stations
-- Date: 2025-12-04
-- This allows stations to be open (not tied to a specific department)
-- 
-- IMPORTANT: 
-- 1. Select your database first in MySQL Workbench (double-click it in SCHEMAS sidebar)
--    OR uncomment and update the USE statement below with your database name
-- 2. MySQL doesn't support "DROP FOREIGN KEY IF EXISTS"
--    If you get an error that the constraint doesn't exist, skip Step 2 and go to Step 3

-- Step 0: Select database (uncomment and replace 'your_database_name' with your actual database name)
-- USE your_database_name;

-- Step 1: Check if foreign key constraint exists first:
-- SELECT CONSTRAINT_NAME 
-- FROM information_schema.TABLE_CONSTRAINTS 
-- WHERE CONSTRAINT_SCHEMA = DATABASE() 
--   AND TABLE_NAME = 'stations' 
--   AND CONSTRAINT_NAME = 'fk_stations_department';

-- Step 2: Drop foreign key constraint (only run if it exists from Step 1 check)
-- If you get error "Cannot drop foreign key constraint", the constraint doesn't exist - skip to Step 3
ALTER TABLE stations DROP FOREIGN KEY fk_stations_department;

-- Step 3: Make department_id nullable
ALTER TABLE stations 
MODIFY COLUMN department_id int NULL COMMENT 'Department ID (NULL for open stations)';
