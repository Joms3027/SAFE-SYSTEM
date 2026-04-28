-- Migration: add key_official field to faculty_profiles
-- Run this SQL to add key_official column to faculty_profiles table

ALTER TABLE faculty_profiles 
ADD COLUMN key_official VARCHAR(150) DEFAULT NULL AFTER designation;
