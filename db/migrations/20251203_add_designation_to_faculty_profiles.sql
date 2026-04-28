-- Migration: add designation field to faculty_profiles
-- Run this SQL to add designation column to faculty_profiles table

ALTER TABLE faculty_profiles 
ADD COLUMN IF NOT EXISTS designation VARCHAR(100) DEFAULT NULL AFTER position;

