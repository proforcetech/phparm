ALTER TABLE users
    ADD COLUMN two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN two_factor_secret VARCHAR(128) NULL,
    ADD COLUMN two_factor_recovery_codes TEXT NULL;
