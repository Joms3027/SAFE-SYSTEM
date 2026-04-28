-- Quick reference SQL to manually update any orphaned pardon requests
-- Run this if you have pardon requests from already-deleted accounts that show as "Unknown Employee"

-- Example: Update a specific pardon request with known employee information
UPDATE pardon_requests 
SET 
    employee_first_name = 'John',
    employee_last_name = 'Doe',
    employee_department = 'Computer Science'
WHERE 
    employee_id = 'EMP-12345'
    AND (employee_first_name IS NULL OR employee_last_name IS NULL);

-- Find all pardon requests missing employee information
SELECT 
    id,
    employee_id,
    log_date,
    status,
    created_at
FROM pardon_requests
WHERE employee_first_name IS NULL OR employee_last_name IS NULL
ORDER BY created_at DESC;
