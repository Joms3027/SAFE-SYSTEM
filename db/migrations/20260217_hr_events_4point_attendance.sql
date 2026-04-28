-- HR Events: 4-point attendance (IN morning, OUT 12PM, IN 1PM, OUT afternoon)
-- Run after 20260217_create_hr_events.sql

-- Add check_type column and update unique constraint
ALTER TABLE `hr_event_attendances`
  ADD COLUMN `check_type` ENUM('in_morning','out_noon','in_afternoon','out_afternoon') NOT NULL DEFAULT 'in_morning' AFTER `employee_id`;

-- Existing rows get check_type = 'in_morning' via DEFAULT

-- Drop old unique constraint (one record per user per event)
ALTER TABLE `hr_event_attendances` DROP INDEX `uq_hr_event_att_event_user`;

-- Add new unique constraint (one record per user per event per check type)
ALTER TABLE `hr_event_attendances` ADD UNIQUE KEY `uq_hr_event_att_event_user_type` (`event_id`, `user_id`, `check_type`);
