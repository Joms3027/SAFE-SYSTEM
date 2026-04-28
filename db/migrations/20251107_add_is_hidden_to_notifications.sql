-- Migration: Add is_hidden field to notifications table
-- Date: 2025-11-07
-- Description: Add is_hidden field to allow hiding notifications without deleting them

-- Add is_hidden column (check if it exists first)
-- Try to add after read_at if it exists, otherwise add after is_read
SET @column_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND COLUMN_NAME = 'is_hidden'
);

SET @read_at_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND COLUMN_NAME = 'read_at'
);

-- Add is_hidden column only if it doesn't exist
SET @sql = IF(@column_exists = 0,
    IF(@read_at_exists > 0,
        'ALTER TABLE `notifications` ADD COLUMN `is_hidden` TINYINT(1) NOT NULL DEFAULT 0 AFTER `read_at`',
        'ALTER TABLE `notifications` ADD COLUMN `is_hidden` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_read`'
    ),
    'SELECT "Column is_hidden already exists" AS result'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add index for better query performance (only if it doesn't exist)
SET @index_exists = (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.STATISTICS 
    WHERE TABLE_SCHEMA = DATABASE() 
    AND TABLE_NAME = 'notifications' 
    AND INDEX_NAME = 'idx_is_hidden'
);

SET @sql = IF(@index_exists = 0,
    'CREATE INDEX idx_is_hidden ON `notifications` (`is_hidden`)',
    'SELECT "Index idx_is_hidden already exists" AS result'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

