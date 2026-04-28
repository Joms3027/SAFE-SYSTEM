-- Migration: create designations master list
-- Run this SQL once to create the designations lookup table

CREATE TABLE IF NOT EXISTS designations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert sample designations
INSERT INTO designations (name) VALUES 
('Dean'),
('Program Chair'),
('Department Head'),
('Coordinator'),
('Faculty Member')
ON DUPLICATE KEY UPDATE name = VALUES(name);

