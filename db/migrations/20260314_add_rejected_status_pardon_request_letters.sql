-- Add 'rejected' status to pardon_request_letters
ALTER TABLE pardon_request_letters 
MODIFY COLUMN status ENUM('pending', 'acknowledged', 'opened', 'closed', 'rejected') DEFAULT 'pending';
