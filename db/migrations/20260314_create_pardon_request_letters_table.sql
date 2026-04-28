-- Pardon Request Letters: employees submit a letter + day to be pardoned; sent to pardon openers
-- Run: mysql -u user -p database < 20260314_create_pardon_request_letters_table.sql

CREATE TABLE IF NOT EXISTS pardon_request_letters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL,
    employee_first_name VARCHAR(100) NULL,
    employee_last_name VARCHAR(100) NULL,
    employee_department VARCHAR(255) NULL,
    employee_designation VARCHAR(255) NULL,
    pardon_date DATE NOT NULL COMMENT 'Day employee wants to be pardoned',
    request_letter TEXT NOT NULL COMMENT 'Letter/justification for the pardon request',
    status ENUM('pending', 'acknowledged', 'opened', 'closed') DEFAULT 'pending' COMMENT 'opened=opener opened pardon for that date; closed=no action or resolved',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_employee_id (employee_id),
    INDEX idx_pardon_date (pardon_date),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Pardon request letters from employees to pardon openers (letter + day to be pardoned)';
