-- Migration: Create login_rate_limits table for database-backed rate limiting
-- This prevents attackers from bypassing rate limits by clearing browser cookies

CREATE TABLE IF NOT EXISTS `login_rate_limits` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `identifier_hash` VARCHAR(32) NOT NULL,
    `ip_address` VARCHAR(45) NOT NULL DEFAULT 'unknown',
    `attempts` INT NOT NULL DEFAULT 0,
    `locked_until` DATETIME NULL DEFAULT NULL,
    `first_attempt` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_identifier` (`identifier_hash`),
    KEY `idx_locked_until` (`locked_until`),
    KEY `idx_first_attempt` (`first_attempt`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
