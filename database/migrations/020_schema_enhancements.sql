-- Consolidated schema enhancements
-- This file consolidates multiple small ALTER TABLE migrations for better maintainability

-- Add reminder tracking to appointments
ALTER TABLE appointments 
  ADD COLUMN IF NOT EXISTS reminder_sent_at TIMESTAMP NULL AFTER notes;

CREATE INDEX IF NOT EXISTS IF NOT EXISTS idx_appointments_reminder 
  ON appointments (status, scheduled_at, reminder_sent_at);

-- Add mileage tracking to customer vehicles
ALTER TABLE customer_vehicles
  ADD COLUMN IF NOT EXISTS mileage_in INT UNSIGNED NULL COMMENT 'Mileage when vehicle arrives' AFTER notes,
  ADD COLUMN IF NOT EXISTS mileage_out INT UNSIGNED NULL COMMENT 'Mileage when vehicle leaves' AFTER mileage_in;

-- Add two-factor authentication to users
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER remember_token,
  ADD COLUMN IF NOT EXISTS two_factor_type ENUM('none', 'totp', 'sms', 'email') NOT NULL DEFAULT 'none' AFTER two_factor_enabled,
  ADD COLUMN IF NOT EXISTS two_factor_secret VARCHAR(128) NULL AFTER two_factor_type,
  ADD COLUMN IF NOT EXISTS two_factor_recovery_codes TEXT NULL AFTER two_factor_secret;

-- Update existing 2FA users to TOTP type
UPDATE users SET two_factor_type = 'totp' WHERE two_factor_enabled = 1 AND two_factor_type = 'none';

-- Add mobile service flags
ALTER TABLE estimates
  ADD COLUMN IF NOT EXISTS is_mobile TINYINT(1) NOT NULL DEFAULT 0 AFTER vehicle_id;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS is_mobile TINYINT(1) NOT NULL DEFAULT 0 AFTER vehicle_id;
