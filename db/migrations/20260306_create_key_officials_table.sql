-- Migration: create key_officials master list
-- Run this SQL once to create the key officials lookup table

CREATE TABLE IF NOT EXISTS key_officials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
