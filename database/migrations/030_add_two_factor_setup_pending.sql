-- Migration: Add two_factor_setup_pending field to users table
-- This field tracks when 2FA has been required by an admin but not yet set up by the user

ALTER TABLE users
  ADD COLUMN two_factor_setup_pending TINYINT(1) NOT NULL DEFAULT 0 AFTER two_factor_recovery_codes;

-- Add index for quick lookup of users who need to complete 2FA setup
CREATE INDEX idx_users_two_factor_setup_pending ON users(two_factor_setup_pending);

COMMENT ON COLUMN users.two_factor_setup_pending IS 'Indicates whether user needs to complete 2FA setup';
