-- Migration to add submission_status column to existing submissions table
ALTER TABLE submissions
ADD COLUMN submission_status ENUM('pending', 'approved', 'rejected', 'resubmitted') DEFAULT 'pending';
