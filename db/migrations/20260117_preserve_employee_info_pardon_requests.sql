-- Add columns to store employee information in pardon_requests
-- This ensures pardon requests remain visible even after employee accounts are deleted

-- Add employee_first_name column
ALTER TABLE pardon_requests 
ADD COLUMN employee_first_name VARCHAR(100) NULL AFTER employee_id;

-- Add employee_last_name column
ALTER TABLE pardon_requests 
ADD COLUMN employee_last_name VARCHAR(100) NULL AFTER employee_first_name;

-- Add employee_department column
ALTER TABLE pardon_requests 
ADD COLUMN employee_department VARCHAR(100) NULL AFTER employee_last_name;

-- Create index for searching by employee name
CREATE INDEX idx_employee_name ON pardon_requests(employee_first_name, employee_last_name);
