-- Migration: Add alternative email and mobile number fields to faculty_pds table
-- Date: 2025-12-03
-- Description: Adds email_alt and mobile_no_alt columns to store alternative contact information

-- Add alternative email column
ALTER TABLE `faculty_pds` 
ADD COLUMN `email_alt` varchar(255) DEFAULT NULL AFTER `mobile_no`;

-- Add alternative mobile number column
ALTER TABLE `faculty_pds` 
ADD COLUMN `mobile_no_alt` varchar(20) DEFAULT NULL AFTER `email_alt`;

-- Verification query (optional - uncomment to check columns were added)
-- SHOW COLUMNS FROM `faculty_pds` LIKE '%alt%';




























