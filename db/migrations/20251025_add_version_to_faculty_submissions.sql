ALTER TABLE faculty_submissions 
ADD COLUMN version INT NOT NULL DEFAULT 1,
ADD COLUMN previous_submission_id INT NULL,
ADD FOREIGN KEY (previous_submission_id) REFERENCES faculty_submissions(id) ON DELETE SET NULL;