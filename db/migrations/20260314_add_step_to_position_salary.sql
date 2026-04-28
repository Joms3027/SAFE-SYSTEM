-- Add step column to position_salary table
-- Step is typically 1-8 in government salary standardization
ALTER TABLE position_salary ADD COLUMN step INT DEFAULT 1 AFTER salary_grade;
