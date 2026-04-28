-- Migration: Create dtr_submissions table for tracking DTR submission to dean/admin
-- Employees must submit DTR by 12th (for period 1st-15th) and by 22nd (for period 16th-25th) of each month.
-- Run: mysql -u user -p database < 20260209_create_dtr_submissions_table.sql

CREATE TABLE IF NOT EXISTS dtr_submissions (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    year SMALLINT NOT NULL,
    month TINYINT NOT NULL,
    period TINYINT NOT NULL COMMENT '1 = 1st-15th (deadline 12th), 2 = 16th-25th (deadline 22nd)',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dtr_user_year_month_period (user_id, year, month, period),
    KEY idx_dtr_year_month (year, month),
    KEY idx_dtr_submitted_at (submitted_at),
    CONSTRAINT fk_dtr_submissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
