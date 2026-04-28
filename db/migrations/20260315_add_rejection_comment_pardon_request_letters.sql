-- Add rejection_comment to pardon_request_letters for storing reason when pardon is rejected
ALTER TABLE pardon_request_letters
ADD COLUMN rejection_comment TEXT NULL DEFAULT NULL COMMENT 'Reason/comment when pardon request is rejected'
AFTER status;
