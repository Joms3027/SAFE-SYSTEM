-- Migration: change employment_status enum to use employment types
ALTER TABLE `faculty_profiles`
  MODIFY `employment_status` ENUM('Full-time','Part-time','Contract','Adjunct') DEFAULT NULL;

-- Run this SQL against your database to apply the change.
