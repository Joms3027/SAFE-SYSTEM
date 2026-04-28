-- Public feedback about employees / departments (submitted by visitors or staff without targeting a specific user row)
-- department_id matches departments.id (signed INT(11) in this project)
CREATE TABLE IF NOT EXISTS employee_feedback (
    id INT(11) NOT NULL AUTO_INCREMENT,
    submitter_name VARCHAR(255) NULL,
    department_id INT(11) NOT NULL,
    satisfaction_rating TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '1=lowest, 5=highest',
    message TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_employee_feedback_created (created_at),
    KEY idx_employee_feedback_department (department_id),
    CONSTRAINT fk_employee_feedback_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
