-- Migration: Fix departments table to ensure id is PRIMARY KEY
-- Run this BEFORE running the stations table migration
-- This ensures departments.id has PRIMARY KEY and AUTO_INCREMENT

-- Check and fix departments table structure
-- If id is not PRIMARY KEY, this will add it
-- If id is not AUTO_INCREMENT, this will add it

ALTER TABLE departments 
  MODIFY id int(11) NOT NULL AUTO_INCREMENT,
  ADD PRIMARY KEY (id);

