-- 1–5 satisfaction rating for employee feedback (legacy rows default to 3)
ALTER TABLE employee_feedback
    ADD COLUMN satisfaction_rating TINYINT UNSIGNED NOT NULL DEFAULT 3
    COMMENT '1=lowest, 5=highest'
    AFTER department_id;
