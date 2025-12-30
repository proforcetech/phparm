-- Consolidated schema enhancements
-- This file consolidates multiple small ALTER TABLE migrations for better maintainability

-- Add reminder tracking to appointments
SET @has_reminder_sent_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND column_name = 'reminder_sent_at'
);
SET @reminder_sent_at_sql := IF(@has_reminder_sent_at = 0,
    'ALTER TABLE appointments ADD COLUMN reminder_sent_at TIMESTAMP NULL AFTER notes', 'SELECT 1');
PREPARE reminder_sent_at_stmt FROM @reminder_sent_at_sql;
EXECUTE reminder_sent_at_stmt;
DEALLOCATE PREPARE reminder_sent_at_stmt;

-- Create index if it doesn't exist
SET @has_reminder_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'appointments' AND index_name = 'idx_appointments_reminder'
);
SET @reminder_index_sql := IF(@has_reminder_index = 0,
    'CREATE INDEX idx_appointments_reminder ON appointments (status, scheduled_at, reminder_sent_at)', 'SELECT 1');
PREPARE reminder_index_stmt FROM @reminder_index_sql;
EXECUTE reminder_index_stmt;
DEALLOCATE PREPARE reminder_index_stmt;

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
