-- Migration: Add tutorial_completed field to faculty_profiles table
-- This field tracks whether a faculty member has completed the first-use tutorial

ALTER TABLE `faculty_profiles` 
ADD COLUMN `tutorial_completed` tinyint(1) DEFAULT 0 COMMENT 'Whether the faculty member has completed the first-use tutorial' 
AFTER `qr_code`;

-- Set existing faculty members as having completed tutorial (optional - remove if you want all to see it)
-- UPDATE `faculty_profiles` SET `tutorial_completed` = 1;

