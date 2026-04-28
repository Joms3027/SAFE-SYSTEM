-- Add target_audience to supervisor_announcements: whom the announcement is displayed to
-- 'all' = both Faculty and Staff; 'faculty' = Faculty only; 'staff' = Staff only (based on employment_type)
ALTER TABLE supervisor_announcements
  ADD COLUMN target_audience ENUM('all','faculty','staff') NOT NULL DEFAULT 'all' AFTER priority;
