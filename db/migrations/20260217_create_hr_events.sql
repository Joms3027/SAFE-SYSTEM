-- HR Events module: events that employees can check in to via QR scan
-- Run this migration to create hr_events and hr_event_attendances tables

-- Table: hr_events (events that have QR check-in)
CREATE TABLE IF NOT EXISTS `hr_events` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` date NOT NULL,
  `event_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `qr_token` varchar(64) NOT NULL COMMENT 'Unique token for check-in URL/QR',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_events_qr_token` (`qr_token`),
  KEY `idx_hr_events_event_date` (`event_date`),
  KEY `idx_hr_events_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table: hr_event_attendances (employee check-ins per event)
CREATE TABLE IF NOT EXISTS `hr_event_attendances` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `employee_id` varchar(50) DEFAULT NULL COMMENT 'Denormalized for display',
  `scanned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_hr_event_att_event_user` (`event_id`, `user_id`),
  KEY `idx_hr_event_att_event_id` (`event_id`),
  KEY `idx_hr_event_att_user_id` (`user_id`),
  KEY `idx_hr_event_att_scanned_at` (`scanned_at`),
  CONSTRAINT `fk_hr_event_att_event` FOREIGN KEY (`event_id`) REFERENCES `hr_events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_hr_event_att_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
