-- Migration: create stations table for station management
-- Run this SQL once to create the stations table
-- 
-- IMPORTANT: Run 20250123_fix_departments_table.sql FIRST to ensure departments table is correct
-- Or manually run: ALTER TABLE departments MODIFY id int(11) NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id);

-- Step 1: Create stations table without foreign key first
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

-- Step 2: Add foreign key constraint
-- This will only work if departments.id is PRIMARY KEY
-- If this fails, run: ALTER TABLE departments MODIFY id int(11) NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id);
ALTER TABLE stations 
  ADD CONSTRAINT fk_stations_department 
  FOREIGN KEY (department_id) 
  REFERENCES departments(id) 
  ON DELETE RESTRICT 
  ON UPDATE CASCADE;

