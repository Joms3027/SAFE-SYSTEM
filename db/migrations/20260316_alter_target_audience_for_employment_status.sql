-- Change target_audience to store Faculty/Staff + employment status combinations
-- Format: 'all' | 'faculty|CONTRACT OF SERVICE' | 'staff|PERMANENT' etc.
ALTER TABLE supervisor_announcements
  MODIFY COLUMN target_audience VARCHAR(255) NOT NULL DEFAULT 'all';
