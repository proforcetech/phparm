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
SET @has_mileage_in := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'customer_vehicles' AND column_name = 'mileage_in'
);
SET @mileage_in_sql := IF(@has_mileage_in = 0,
    'ALTER TABLE customer_vehicles ADD COLUMN mileage_in INT UNSIGNED NULL COMMENT ''Mileage when vehicle arrives'' AFTER notes',
    'SELECT 1');
PREPARE mileage_in_stmt FROM @mileage_in_sql;
EXECUTE mileage_in_stmt;
DEALLOCATE PREPARE mileage_in_stmt;

SET @has_mileage_out := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'customer_vehicles' AND column_name = 'mileage_out'
);
SET @mileage_out_sql := IF(@has_mileage_out = 0,
    'ALTER TABLE customer_vehicles ADD COLUMN mileage_out INT UNSIGNED NULL COMMENT ''Mileage when vehicle leaves'' AFTER mileage_in',
    'SELECT 1');
PREPARE mileage_out_stmt FROM @mileage_out_sql;
EXECUTE mileage_out_stmt;
DEALLOCATE PREPARE mileage_out_stmt;

-- Add two-factor authentication to users
SET @has_two_factor_enabled := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'two_factor_enabled'
);
SET @two_factor_enabled_sql := IF(@has_two_factor_enabled = 0,
    'ALTER TABLE users ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER remember_token',
    'SELECT 1');
PREPARE two_factor_enabled_stmt FROM @two_factor_enabled_sql;
EXECUTE two_factor_enabled_stmt;
DEALLOCATE PREPARE two_factor_enabled_stmt;

SET @has_two_factor_type := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'two_factor_type'
);
SET @two_factor_type_sql := IF(@has_two_factor_type = 0,
    'ALTER TABLE users ADD COLUMN two_factor_type ENUM(''none'', ''totp'', ''sms'', ''email'') NOT NULL DEFAULT ''none'' AFTER two_factor_enabled',
    'SELECT 1');
PREPARE two_factor_type_stmt FROM @two_factor_type_sql;
EXECUTE two_factor_type_stmt;
DEALLOCATE PREPARE two_factor_type_stmt;

SET @has_two_factor_secret := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'two_factor_secret'
);
SET @two_factor_secret_sql := IF(@has_two_factor_secret = 0,
    'ALTER TABLE users ADD COLUMN two_factor_secret VARCHAR(128) NULL AFTER two_factor_type',
    'SELECT 1');
PREPARE two_factor_secret_stmt FROM @two_factor_secret_sql;
EXECUTE two_factor_secret_stmt;
DEALLOCATE PREPARE two_factor_secret_stmt;

SET @has_two_factor_recovery_codes := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'two_factor_recovery_codes'
);
SET @two_factor_recovery_codes_sql := IF(@has_two_factor_recovery_codes = 0,
    'ALTER TABLE users ADD COLUMN two_factor_recovery_codes TEXT NULL AFTER two_factor_secret',
    'SELECT 1');
PREPARE two_factor_recovery_codes_stmt FROM @two_factor_recovery_codes_sql;
EXECUTE two_factor_recovery_codes_stmt;
DEALLOCATE PREPARE two_factor_recovery_codes_stmt;

-- Update existing 2FA users to TOTP type
SET @has_two_factor_columns := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name IN ('two_factor_enabled', 'two_factor_type')
);
SET @two_factor_update_sql := IF(@has_two_factor_columns = 2,
    'UPDATE users SET two_factor_type = ''totp'' WHERE two_factor_enabled = 1 AND two_factor_type = ''none''',
    'SELECT 1');
PREPARE two_factor_update_stmt FROM @two_factor_update_sql;
EXECUTE two_factor_update_stmt;
DEALLOCATE PREPARE two_factor_update_stmt;

-- Add mobile service flags
SET @has_estimate_is_mobile := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND column_name = 'is_mobile'
);
SET @estimate_is_mobile_sql := IF(@has_estimate_is_mobile = 0,
    'ALTER TABLE estimates ADD COLUMN is_mobile TINYINT(1) NOT NULL DEFAULT 0 AFTER vehicle_id',
    'SELECT 1');
PREPARE estimate_is_mobile_stmt FROM @estimate_is_mobile_sql;
EXECUTE estimate_is_mobile_stmt;
DEALLOCATE PREPARE estimate_is_mobile_stmt;

SET @has_invoice_is_mobile := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices' AND column_name = 'is_mobile'
);
SET @invoice_is_mobile_sql := IF(@has_invoice_is_mobile = 0,
    'ALTER TABLE invoices ADD COLUMN is_mobile TINYINT(1) NOT NULL DEFAULT 0 AFTER vehicle_id',
    'SELECT 1');
PREPARE invoice_is_mobile_stmt FROM @invoice_is_mobile_sql;
EXECUTE invoice_is_mobile_stmt;
DEALLOCATE PREPARE invoice_is_mobile_stmt;
