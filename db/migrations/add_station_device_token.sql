-- Add device token column to stations table for strict device locking
-- Migration: add_station_device_token
-- Date: 2025-12-04

ALTER TABLE stations 
ADD COLUMN device_token VARCHAR(64) NULL COMMENT 'Unique token for device binding' 
AFTER mac_address;

ALTER TABLE stations 
ADD COLUMN device_fingerprint TEXT NULL COMMENT 'Device fingerprint data' 
AFTER device_token;

ALTER TABLE stations 
ADD COLUMN last_device_ip VARCHAR(45) NULL COMMENT 'Last known device IP' 
AFTER device_fingerprint;

ALTER TABLE stations 
ADD COLUMN device_registered_at DATETIME NULL COMMENT 'When device was first registered' 
AFTER last_device_ip;

