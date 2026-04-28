-- Create user_activity table for online status tracking (chat bubble green dots)
-- Used to show employees when admins are online
CREATE TABLE IF NOT EXISTS user_activity (
    user_id INT NOT NULL PRIMARY KEY,
    last_activity INT UNSIGNED NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_last_activity (last_activity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
