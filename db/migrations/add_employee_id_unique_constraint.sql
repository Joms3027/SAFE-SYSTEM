-- Migration: Add unique constraint to employee_id in faculty_profiles
-- Date: 2025-11-03
-- Description: Ensures employee_id is unique across all faculty members

-- First, check if there are any duplicate employee_ids and set them to NULL
UPDATE faculty_profiles 
SET employee_id = NULL 
WHERE employee_id IN (
    SELECT employee_id 
    FROM (
        SELECT employee_id 
        FROM faculty_profiles 
        WHERE employee_id IS NOT NULL 
        GROUP BY employee_id 
        HAVING COUNT(*) > 1
    ) AS duplicates
);

-- Add unique constraint to employee_id column
ALTER TABLE faculty_profiles 
ADD UNIQUE KEY `unique_employee_id` (`employee_id`);

-- Update the schema to make the constraint clear
ALTER TABLE faculty_profiles 
MODIFY COLUMN `employee_id` varchar(50) DEFAULT NULL COMMENT 'Format: WPU-YYYY-#####';
