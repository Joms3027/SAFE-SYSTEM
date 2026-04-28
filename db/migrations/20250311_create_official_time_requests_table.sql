-- Official Time Requests: employee declares → faculty: dean verifies/endorses → super_admin approves/rejects; staff: super_admin approves/rejects.
-- Once approved, the record is copied to employee_official_times (working time).

CREATE TABLE IF NOT EXISTS `official_time_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `weekday` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `time_in` time NOT NULL DEFAULT '08:00:00',
  `lunch_out` time DEFAULT NULL,
  `lunch_in` time DEFAULT NULL,
  `time_out` time NOT NULL DEFAULT '17:00:00',
  `status` enum('pending_dean','pending_super_admin','approved','rejected') NOT NULL DEFAULT 'pending_dean',
  `submitted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dean_verified_at` datetime DEFAULT NULL,
  `dean_verified_by` int(11) DEFAULT NULL,
  `super_admin_approved_at` datetime DEFAULT NULL,
  `super_admin_approved_by` int(11) DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_otr_employee_id` (`employee_id`),
  KEY `idx_otr_status` (`status`),
  KEY `idx_otr_pending_dean` (`status`, `employee_id`),
  KEY `idx_otr_pending_super_admin` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
