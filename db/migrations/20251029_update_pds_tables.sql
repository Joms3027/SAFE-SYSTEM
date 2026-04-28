-- Migration: Add missing fields to PDS tables
-- Created: 2025-10-29

-- Add missing fields to pds_education
ALTER TABLE pds_education
ADD COLUMN IF NOT EXISTS from_date VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS to_date VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS units_earned VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS year_graduated VARCHAR(50) NULL,
ADD COLUMN IF NOT EXISTS academic_honors TEXT NULL;

-- Add missing fields to pds_experience
ALTER TABLE pds_experience
ADD COLUMN IF NOT EXISTS employment_status VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS salary_grade VARCHAR(50) NULL,
ADD COLUMN IF NOT EXISTS appointment_status VARCHAR(100) NULL,
ADD COLUMN IF NOT EXISTS gov_service BOOLEAN DEFAULT 0;

-- Add missing fields to pds_learning
ALTER TABLE pds_learning
ADD COLUMN IF NOT EXISTS venue VARCHAR(255) NULL,
ADD COLUMN IF NOT EXISTS has_certificate BOOLEAN DEFAULT 0,
ADD COLUMN IF NOT EXISTS certificate_details TEXT NULL;

-- Create civil service eligibility table if it doesn't exist
CREATE TABLE IF NOT EXISTS faculty_civil_service_eligibility (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pds_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    rating VARCHAR(50) NULL,
    date_of_exam VARCHAR(100) NULL,
    place_of_exam VARCHAR(255) NULL,
    license_number VARCHAR(100) NULL,
    date_of_validity VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (pds_id) REFERENCES faculty_pds(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Create index for civil service eligibility
CREATE INDEX IF NOT EXISTS idx_civil_service_pds ON faculty_civil_service_eligibility(pds_id);