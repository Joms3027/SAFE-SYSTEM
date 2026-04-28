-- Add supporting_documents column (TEXT to store JSON array) and make reason required
ALTER TABLE pardon_requests 
ADD COLUMN IF NOT EXISTS supporting_documents TEXT NULL AFTER reason;

-- Make reason required (if not already)
ALTER TABLE pardon_requests 
MODIFY COLUMN reason TEXT NOT NULL;

