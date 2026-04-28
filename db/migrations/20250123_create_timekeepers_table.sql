-- Migration: create timekeepers table for timekeeper management
-- Run this SQL once to create the timekeepers table
-- Note: Ensure stations table exists before running this migration

-- Step 1: Create timekeepers table without foreign key first
CREATE TABLE IF NOT EXISTS timekeepers (
  id int(11) NOT NULL AUTO_INCREMENT,
  user_id int(11) NOT NULL,
  station_id int(11) NOT NULL,
  password varchar(255) NOT NULL,
  is_active tinyint(1) DEFAULT 1,
  created_at timestamp NOT NULL DEFAULT current_timestamp(),
  updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (id),
  UNIQUE KEY unique_user_timekeeper (user_id),
  KEY idx_station (station_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Step 2: Add foreign key constraints
-- Foreign key to users table
ALTER TABLE timekeepers 
  ADD CONSTRAINT fk_timekeepers_user 
  FOREIGN KEY (user_id) 
  REFERENCES users(id) 
  ON DELETE CASCADE 
  ON UPDATE CASCADE;

-- Foreign key to stations table
ALTER TABLE timekeepers 
  ADD CONSTRAINT fk_timekeepers_station 
  FOREIGN KEY (station_id) 
  REFERENCES stations(id) 
  ON DELETE RESTRICT 
  ON UPDATE CASCADE;

