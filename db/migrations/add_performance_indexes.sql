-- Performance Optimization Indexes
-- Run this migration to add indexes for frequently queried columns
-- This will significantly improve query performance

-- Indexes for users table
CREATE INDEX IF NOT EXISTS idx_users_user_type ON users(user_type);
CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
CREATE INDEX IF NOT EXISTS idx_users_is_active ON users(is_active);
CREATE INDEX IF NOT EXISTS idx_users_is_verified ON users(is_verified);
CREATE INDEX IF NOT EXISTS idx_users_created_at ON users(created_at);

-- Indexes for faculty_profiles table
CREATE INDEX IF NOT EXISTS idx_faculty_profiles_user_id ON faculty_profiles(user_id);
CREATE INDEX IF NOT EXISTS idx_faculty_profiles_employee_id ON faculty_profiles(employee_id);
CREATE INDEX IF NOT EXISTS idx_faculty_profiles_department ON faculty_profiles(department);
CREATE INDEX IF NOT EXISTS idx_faculty_profiles_position ON faculty_profiles(position);

-- Indexes for faculty_pds table
CREATE INDEX IF NOT EXISTS idx_faculty_pds_faculty_id ON faculty_pds(faculty_id);
CREATE INDEX IF NOT EXISTS idx_faculty_pds_status ON faculty_pds(status);
CREATE INDEX IF NOT EXISTS idx_faculty_pds_created_at ON faculty_pds(created_at);
CREATE INDEX IF NOT EXISTS idx_faculty_pds_submitted_at ON faculty_pds(submitted_at);

-- Indexes for requirements table
CREATE INDEX IF NOT EXISTS idx_requirements_is_active ON requirements(is_active);
CREATE INDEX IF NOT EXISTS idx_requirements_deadline ON requirements(deadline);
CREATE INDEX IF NOT EXISTS idx_requirements_created_at ON requirements(created_at);

-- Indexes for faculty_requirements table (junction table)
CREATE INDEX IF NOT EXISTS idx_faculty_requirements_faculty_id ON faculty_requirements(faculty_id);
CREATE INDEX IF NOT EXISTS idx_faculty_requirements_requirement_id ON faculty_requirements(requirement_id);
CREATE INDEX IF NOT EXISTS idx_faculty_requirements_composite ON faculty_requirements(faculty_id, requirement_id);

-- Indexes for faculty_submissions table
CREATE INDEX IF NOT EXISTS idx_faculty_submissions_faculty_id ON faculty_submissions(faculty_id);
CREATE INDEX IF NOT EXISTS idx_faculty_submissions_requirement_id ON faculty_submissions(requirement_id);
CREATE INDEX IF NOT EXISTS idx_faculty_submissions_status ON faculty_submissions(status);
CREATE INDEX IF NOT EXISTS idx_faculty_submissions_submitted_at ON faculty_submissions(submitted_at);
CREATE INDEX IF NOT EXISTS idx_faculty_submissions_version ON faculty_submissions(version);
CREATE INDEX IF NOT EXISTS idx_faculty_submissions_composite ON faculty_submissions(faculty_id, requirement_id, version);

-- Indexes for notifications table
CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications(user_id);
CREATE INDEX IF NOT EXISTS idx_notifications_is_read ON notifications(is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_created_at ON notifications(created_at);
CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read);

-- Indexes for system_logs table
CREATE INDEX IF NOT EXISTS idx_system_logs_user_id ON system_logs(user_id);
CREATE INDEX IF NOT EXISTS idx_system_logs_action ON system_logs(action);
CREATE INDEX IF NOT EXISTS idx_system_logs_created_at ON system_logs(created_at);

-- Indexes for position_salary table
CREATE INDEX IF NOT EXISTS idx_position_salary_position_title ON position_salary(position_title);

-- Indexes for requirement_attachments table
CREATE INDEX IF NOT EXISTS idx_requirement_attachments_faculty_id ON requirement_attachments(faculty_id);
CREATE INDEX IF NOT EXISTS idx_requirement_attachments_requirement_id ON requirement_attachments(requirement_id);

-- Indexes for attendance_logs table (for employee logs and salary calculations)
CREATE INDEX IF NOT EXISTS idx_attendance_logs_employee_id ON attendance_logs(employee_id);
CREATE INDEX IF NOT EXISTS idx_attendance_logs_log_date ON attendance_logs(log_date);
CREATE INDEX IF NOT EXISTS idx_attendance_logs_employee_date ON attendance_logs(employee_id, log_date);

-- Indexes for employee_deductions table
CREATE INDEX IF NOT EXISTS idx_employee_deductions_employee_id ON employee_deductions(employee_id);
CREATE INDEX IF NOT EXISTS idx_employee_deductions_deduction_id ON employee_deductions(deduction_id);
CREATE INDEX IF NOT EXISTS idx_employee_deductions_is_active ON employee_deductions(is_active);
CREATE INDEX IF NOT EXISTS idx_employee_deductions_dates ON employee_deductions(start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_employee_deductions_composite ON employee_deductions(employee_id, is_active, start_date, end_date);

-- Indexes for deductions table
CREATE INDEX IF NOT EXISTS idx_deductions_order_num ON deductions(order_num);
CREATE INDEX IF NOT EXISTS idx_deductions_type ON deductions(type);

-- Indexes for employee_official_times table
CREATE INDEX IF NOT EXISTS idx_employee_official_times_employee_id ON employee_official_times(employee_id);
CREATE INDEX IF NOT EXISTS idx_employee_official_times_weekday ON employee_official_times(weekday);

-- Indexes for positions table (attendance system)
CREATE INDEX IF NOT EXISTS idx_positions_position_name ON positions(position_name);

-- Composite indexes for common query patterns
CREATE INDEX IF NOT EXISTS idx_users_type_active ON users(user_type, is_active);
CREATE INDEX IF NOT EXISTS idx_faculty_submissions_faculty_status ON faculty_submissions(faculty_id, status);
CREATE INDEX IF NOT EXISTS idx_notifications_user_read_hidden ON notifications(user_id, is_read, is_hidden);

-- Critical composite indexes for N+1 query patterns in DTR/attendance
CREATE INDEX IF NOT EXISTS idx_employee_official_times_lookup ON employee_official_times(employee_id, weekday, start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_attendance_logs_holiday ON attendance_logs(log_date, holiday_id);
CREATE INDEX IF NOT EXISTS idx_attendance_logs_remarks ON attendance_logs(employee_id, log_date, remarks(50));

-- Indexes for holidays table (frequently joined)
CREATE INDEX IF NOT EXISTS idx_holidays_date ON holidays(date);

-- Indexes for login rate limiting
CREATE INDEX IF NOT EXISTS idx_login_rate_limits_ip ON login_rate_limits(ip_address, attempt_time);

-- Indexes for chat messages
CREATE INDEX IF NOT EXISTS idx_chat_messages_recipient ON chat_messages(recipient_id, is_read, created_at);
CREATE INDEX IF NOT EXISTS idx_chat_messages_sender ON chat_messages(sender_id, created_at);

-- Indexes for activity log
CREATE INDEX IF NOT EXISTS idx_activity_log_user ON activity_log(user_id, created_at);

-- Indexes for pardon requests
CREATE INDEX IF NOT EXISTS idx_pardon_requests_faculty ON pardon_requests(faculty_id, status);

-- Indexes for DTR submissions
CREATE INDEX IF NOT EXISTS idx_dtr_submissions_employee ON dtr_submissions(employee_id, status);

