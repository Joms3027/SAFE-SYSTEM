-- Pardon open by supervisor: employee can submit pardon only when dean has opened pardon for that date
-- Run: mysql -u user -p database < 20260227_create_pardon_open_table.sql

CREATE TABLE IF NOT EXISTS pardon_open (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL,
    log_date DATE NOT NULL,
    opened_by_user_id INT NOT NULL,
    opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_employee_log_date (employee_id, log_date),
    INDEX idx_employee_id (employee_id),
    INDEX idx_log_date (log_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Dean opens pardon for a date so employee can submit pardon request';
