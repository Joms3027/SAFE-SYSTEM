-- Migration: create stations table for station management (Alternative Version)
-- Use this version if the main migration fails due to foreign key constraint issues
-- This creates the table without foreign key constraint - you can add it manually later

CREATE TABLE IF NOT EXISTS stations (
  id int(11) NOT NULL AUTO_INCREMENT,
  name varchar(150) NOT NULL,
  department_id int(11) NOT NULL,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY name (name),
  KEY idx_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- To add the foreign key constraint later, run this after ensuring departments.id is PRIMARY KEY:
-- ALTER TABLE stations 
--   ADD CONSTRAINT fk_stations_department 
--   FOREIGN KEY (department_id) 
--   REFERENCES departments(id) 
--   ON DELETE RESTRICT 
--   ON UPDATE CASCADE;

