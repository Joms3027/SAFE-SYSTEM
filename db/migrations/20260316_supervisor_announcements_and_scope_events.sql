-- Supervisor announcements: announcements from pardon openers to their employees in scope
CREATE TABLE IF NOT EXISTS supervisor_announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    supervisor_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    priority ENUM('low','normal','high','urgent') DEFAULT 'normal',
    is_active TINYINT(1) DEFAULT 1,
    expires_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (supervisor_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_supervisor (supervisor_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add scope_supervisor_id to calendar_events for supervisor-scoped events/meetings
-- When set: event is visible only to employees in that supervisor's scope
-- When NULL: university-wide event (existing behavior)
-- Note: Run via PHP migration to check column existence first
