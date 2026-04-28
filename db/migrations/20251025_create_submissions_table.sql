CREATE TABLE submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    faculty_id INT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    submission_status ENUM('pending', 'approved', 'rejected', 'resubmitted') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Add any necessary foreign key constraints here
