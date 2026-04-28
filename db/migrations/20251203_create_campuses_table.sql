-- Create campuses table for master list
-- Date: 2025-12-03

CREATE TABLE IF NOT EXISTS `campuses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL UNIQUE,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default campuses for WPU (adjust these based on actual campuses)
INSERT INTO `campuses` (`name`) VALUES
('Aborlan'),
('Main Campus - Puerto Princesa'),
('Cuyo'),
('Narra'),
('Quezon'),
('Rizal'),
('Roxas'),
('San Vicente'),
('Taytay')
ON DUPLICATE KEY UPDATE name=name;

