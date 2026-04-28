-- Migration: Create dtr_daily_submissions table for daily DTR submission
-- Employees submit each day's DTR the next day or after (e.g., Monday's logs submitted Tuesday or later).
-- Attendance data is only visible to dean/admin for dates that have been submitted.
-- Run: mysql -u user -p database < 20260224_create_dtr_daily_submissions.sql

CREATE TABLE IF NOT EXISTS dtr_daily_submissions (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    log_date DATE NOT NULL COMMENT 'The attendance date being submitted',
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_dtr_daily_user_date (user_id, log_date),
    KEY idx_dtr_daily_log_date (log_date),
    KEY idx_dtr_daily_submitted_at (submitted_at),
    CONSTRAINT fk_dtr_daily_submissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
