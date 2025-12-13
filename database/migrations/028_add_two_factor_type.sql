-- Add two_factor_type column to users table
ALTER TABLE users
    ADD COLUMN two_factor_type ENUM('none', 'totp', 'sms', 'email') NOT NULL DEFAULT 'none' AFTER two_factor_enabled;

-- Update existing records: if two_factor_enabled is true, set type to 'totp'
UPDATE users SET two_factor_type = 'totp' WHERE two_factor_enabled = 1;
