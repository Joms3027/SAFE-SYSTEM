-- Add gender and campus fields to faculty_profiles table
-- Date: 2025-12-03

ALTER TABLE `faculty_profiles`
ADD COLUMN `gender` enum('Male','Female','Other','Prefer not to say') DEFAULT NULL COMMENT 'Employee gender' AFTER `employee_id`,
ADD COLUMN `campus` varchar(100) DEFAULT NULL COMMENT 'Campus location' AFTER `gender`;

