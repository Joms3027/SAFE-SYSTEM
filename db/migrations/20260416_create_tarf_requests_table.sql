-- TARF / NTARF submissions (shared table). Travel (TARF) and non-travel (NTARF) rows differ by JSON form_kind (`tarf` | `ntarf`).
-- Run: php db/migrations/run_tarf_ntarf_migrations.php

CREATE TABLE IF NOT EXISTS `tarf_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `employee_id` varchar(50) DEFAULT NULL,
  `serial_year` smallint(5) UNSIGNED NOT NULL,
  `form_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'JSON object of submitted fields',
  `attachments` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'JSON array of stored file paths',
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_tarf_user_created` (`user_id`,`created_at`),
  KEY `idx_tarf_employee` (`employee_id`),
  KEY `idx_tarf_year` (`serial_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
