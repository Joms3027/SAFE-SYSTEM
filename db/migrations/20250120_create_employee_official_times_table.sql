CREATE TABLE IF NOT EXISTS employee_official_times (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id VARCHAR(50) NOT NULL,
    week_start_date DATE NOT NULL COMMENT 'Monday of the week',
    time_in TIME NOT NULL DEFAULT '08:00:00',
    lunch_out TIME NOT NULL DEFAULT '12:00:00',
    lunch_in TIME NOT NULL DEFAULT '13:00:00',
    time_out TIME NOT NULL DEFAULT '17:00:00',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_employee_week (employee_id, week_start_date),
    INDEX idx_employee_id (employee_id),
    INDEX idx_week_start_date (week_start_date),
    INDEX idx_employee_week (employee_id, week_start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

