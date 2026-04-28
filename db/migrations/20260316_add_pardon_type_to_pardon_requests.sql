-- Add pardon_type to pardon_requests for Submit Pardon Request from faculty/view_logs
-- ordinary_pardon, tarf_ntarf: require time entry editing
-- leave types: DTR shows LEAVE only, no time entries
ALTER TABLE pardon_requests ADD COLUMN pardon_type VARCHAR(80) DEFAULT 'ordinary_pardon' AFTER log_date;
