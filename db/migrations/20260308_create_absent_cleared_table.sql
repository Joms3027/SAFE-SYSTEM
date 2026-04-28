-- Migration: Create absent_cleared table to track dates where absents have been cleared
-- Date: 2026-03-08
-- Purpose: Prevents re-inserting absent records for dates that were explicitly cleared

CREATE TABLE IF NOT EXISTS absent_cleared (
  employee_id VARCHAR(50) NOT NULL,
  log_date DATE NOT NULL,
  cleared_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (employee_id, log_date)
);
