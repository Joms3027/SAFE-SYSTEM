-- Migration: Add agency_employee_id column to faculty_pds table
-- Date: 2026-03-08
-- Description: Adds agency_employee_id column for the AGENCY EMPLOYEE ID field in Government IDs section

-- Add agency_employee_id column (after agency_employee_no)
ALTER TABLE `faculty_pds` 
ADD COLUMN `agency_employee_id` varchar(50) DEFAULT NULL AFTER `agency_employee_no`;

-- Migrate existing data from other_info JSON to the new column (if any)
UPDATE `faculty_pds` 
SET `agency_employee_id` = JSON_UNQUOTE(JSON_EXTRACT(`other_info`, '$.agency_employee_id'))
WHERE `other_info` IS NOT NULL 
  AND JSON_EXTRACT(`other_info`, '$.agency_employee_id') IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(`other_info`, '$.agency_employee_id')) != '';
