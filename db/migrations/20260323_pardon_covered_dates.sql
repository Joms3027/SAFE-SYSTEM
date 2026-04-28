-- Optional manual migration: multi-day TARF/NTARF and leave pardons (also auto-added by submit_pardon_request.php)
ALTER TABLE pardon_requests
ADD COLUMN pardon_covered_dates TEXT NULL AFTER pardon_type;
