-- Migration: Ensure PDS JSON columns exist (idempotent for MySQL 8+)
-- Run this on the wpu_faculty_system database. If your MySQL < 8.0, run equivalent ALTERs manually or use phpMyAdmin.

ALTER TABLE faculty_pds
  ADD COLUMN IF NOT EXISTS children_info JSON,
  ADD COLUMN IF NOT EXISTS educational_background JSON,
  ADD COLUMN IF NOT EXISTS civil_service_eligibility JSON,
  ADD COLUMN IF NOT EXISTS work_experience JSON,
  ADD COLUMN IF NOT EXISTS voluntary_work JSON,
  ADD COLUMN IF NOT EXISTS learning_development JSON,
  ADD COLUMN IF NOT EXISTS other_info JSON,
  ADD COLUMN IF NOT EXISTS additional_questions JSON;

-- Add indexes helpful for queries (optional)
-- Note: JSON columns cannot directly be indexed for their contents without generated columns.
-- Below are example statements to add a simple index on faculty_id if missing.

ALTER TABLE faculty_pds
  ADD INDEX IF NOT EXISTS idx_pds_faculty (faculty_id),
  ADD INDEX IF NOT EXISTS idx_pds_status (status);

-- End of migration
