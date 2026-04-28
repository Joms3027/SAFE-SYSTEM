-- Add description column to fix: SQLSTATE[42S22] Unknown column 'description' in 'field list'
-- Run each statement. If you get "Duplicate column name" the fix is already applied for that table.

-- calendar_events (admin calendar page)
ALTER TABLE calendar_events ADD COLUMN description TEXT;

-- tarf (TARF entries on calendar)
ALTER TABLE tarf ADD COLUMN description TEXT;
