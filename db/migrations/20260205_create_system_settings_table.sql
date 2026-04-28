-- Create system_settings table to store system-wide configuration
CREATE TABLE IF NOT EXISTS system_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_setting_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Insert default pardon limit setting (3 times per week)
INSERT INTO system_settings (setting_key, setting_value, description) 
VALUES ('pardon_weekly_limit', '3', 'Maximum number of pardon requests allowed per employee per week (3 or 5)')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
