-- Pardon opener assignments: configurable list of persons who can open pardon for their employees
-- Example: Francis Alterdo (Dean CAS) -> CAS faculty; Doc Romeo Lerom (VPAF) -> Deans, NSTP Director
-- Run: mysql -u user -p database < 20260312_create_pardon_opener_assignments_table.sql

CREATE TABLE IF NOT EXISTS pardon_opener_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL COMMENT 'User who can open pardon (faculty, admin, or super_admin)',
    scope_type ENUM('department','designation') NOT NULL COMMENT 'Match employees by department or designation',
    scope_value VARCHAR(255) NOT NULL COMMENT 'Department name or designation name to match',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_scope (user_id, scope_type, scope_value),
    INDEX idx_user_id (user_id),
    INDEX idx_scope (scope_type, scope_value),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
COMMENT='Persons who can open pardon for employees in their scope (department or designation)';
