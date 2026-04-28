-- Migration: add employment_type to faculty_profiles
ALTER TABLE `faculty_profiles`
  ADD COLUMN `employment_type` varchar(50) DEFAULT NULL;

-- You can run this SQL against the database to apply the change.
