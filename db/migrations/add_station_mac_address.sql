-- Add MAC address column to stations table
-- Migration: add_station_mac_address
-- Date: 2025-12-04

ALTER TABLE stations 
ADD COLUMN mac_address VARCHAR(17) NULL COMMENT 'MAC address of device for station verification' 
AFTER pin;

-- Note: Browsers cannot access actual MAC addresses due to security/privacy restrictions
-- This field can be used to store the MAC address for reference, but verification will use IP address

