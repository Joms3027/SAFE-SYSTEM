-- Migration: Add QR code column to faculty_profiles table
-- Created: 2025-11-17
-- Description: Adds qr_code column to store QR code file path for attendance tracking

ALTER TABLE faculty_profiles
ADD COLUMN IF NOT EXISTS qr_code VARCHAR(255) NULL COMMENT 'Path to QR code image file for attendance';

-- End of migration

