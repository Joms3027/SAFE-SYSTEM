-- Add missing fields to faculty_pds table
-- Date: 2025-11-07

-- Add citizenship field
ALTER TABLE faculty_pds
ADD COLUMN IF NOT EXISTS citizenship VARCHAR(100) DEFAULT 'Filipino' AFTER blood_type;

-- Note: The following fields are already stored in JSON columns but need to be properly handled:
-- For education: from_date, to_date, units_earned, academic_honors (in educational_background JSON)
-- For work_experience: salary_grade, employment_status, appointment_status, gov_service (in work_experience JSON)
-- For learning_development: type, conducted_by, has_certificate, venue, certificate_details (in learning_development JSON)

-- The normalized tables (pds_education, pds_experience, pds_learning) should already have these columns
-- If they don't exist, add them:

-- For pds_education table
ALTER TABLE pds_education
ADD COLUMN IF NOT EXISTS from_date VARCHAR(50) AFTER degree,
ADD COLUMN IF NOT EXISTS to_date VARCHAR(50) AFTER from_date,
ADD COLUMN IF NOT EXISTS units_earned VARCHAR(50) AFTER to_date,
ADD COLUMN IF NOT EXISTS year_graduated VARCHAR(50) AFTER units_earned,
ADD COLUMN IF NOT EXISTS academic_honors VARCHAR(255) AFTER year_graduated;

-- For pds_experience table  
ALTER TABLE pds_experience
ADD COLUMN IF NOT EXISTS salary_grade VARCHAR(50) AFTER salary,
ADD COLUMN IF NOT EXISTS employment_status VARCHAR(50) AFTER salary_grade,
ADD COLUMN IF NOT EXISTS appointment_status VARCHAR(100) AFTER employment_status,
ADD COLUMN IF NOT EXISTS gov_service VARCHAR(10) AFTER appointment_status;a

-- For pds_learning table
ALTER TABLE pds_learning
ADD COLUMN IF NOT EXISTS type VARCHAR(50) AFTER hours,
ADD COLUMN IF NOT EXISTS conducted_by VARCHAR(255) AFTER type,
ADD COLUMN IF NOT EXISTS has_certificate VARCHAR(10) AFTER conducted_by,
ADD COLUMN IF NOT EXISTS venue VARCHAR(255) AFTER has_certificate,
ADD COLUMN IF NOT EXISTS certificate_details TEXT AFTER venue;
