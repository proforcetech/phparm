-- Consolidated schema enhancements
-- This file consolidates multiple small ALTER TABLE migrations for better maintainability

-- Add reminder tracking to appointments
ALTER TABLE appointments
  ADD COLUMN reminder_sent_at TIMESTAMP NULL AFTER notes;

CREATE INDEX idx_appointments_reminder
  ON appointments (status, scheduled_at, reminder_sent_at);

-- Add mileage tracking to customer vehicles
ALTER TABLE customer_vehicles
  ADD COLUMN mileage_in INT UNSIGNED NULL COMMENT 'Mileage when vehicle arrives' AFTER notes,
  ADD COLUMN mileage_out INT UNSIGNED NULL COMMENT 'Mileage when vehicle leaves' AFTER mileage_in;

-- Add two-factor authentication to users
ALTER TABLE users
  ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER remember_token,
  ADD COLUMN two_factor_type ENUM('none', 'totp', 'sms', 'email') NOT NULL DEFAULT 'none' AFTER two_factor_enabled,
  ADD COLUMN two_factor_secret VARCHAR(128) NULL AFTER two_factor_type,
  ADD COLUMN two_factor_recovery_codes TEXT NULL AFTER two_factor_secret;

-- Update existing 2FA users to TOTP type
UPDATE users SET two_factor_type = 'totp' WHERE two_factor_enabled = 1 AND two_factor_type = 'none';

-- Add mobile service flags
ALTER TABLE estimates
  ADD COLUMN is_mobile TINYINT(1) NOT NULL DEFAULT 0 AFTER vehicle_id;

ALTER TABLE invoices
  ADD COLUMN is_mobile TINYINT(1) NOT NULL DEFAULT 0 AFTER vehicle_id;
