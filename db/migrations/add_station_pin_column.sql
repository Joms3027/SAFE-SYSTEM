-- Add PIN column to stations table for station-based authentication
-- Migration: add_station_pin_column
-- Date: 2025-12-04

ALTER TABLE stations 
ADD COLUMN pin VARCHAR(255) NULL COMMENT 'Hashed PIN for station access' 
AFTER department_id;

-- Update existing stations with a default hashed PIN (you should change this after migration)
-- Default PIN: "1234" (hashed)
-- Note: Administrators should update each station's PIN through the UI after this migration
UPDATE stations 
SET pin = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'
WHERE pin IS NULL;

-- Make PIN NOT NULL after setting defaults
ALTER TABLE stations 
MODIFY COLUMN pin VARCHAR(255) NOT NULL;

