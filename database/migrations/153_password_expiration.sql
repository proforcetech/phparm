-- Password expiration / forced rotation.
-- password_changed_at:    when the user last set their password. Drives age-based expiration.
-- must_change_password:   admin-flipped flag forcing rotation on next login regardless of age
--                         (e.g., suspected credential leak, post-onboarding handoff).
ALTER TABLE users
    ADD COLUMN password_changed_at TIMESTAMP NULL DEFAULT NULL AFTER password,
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_changed_at,
    ADD INDEX idx_users_password_changed_at (password_changed_at),
    ADD INDEX idx_users_must_change_password (must_change_password);

-- Backfill so existing users don't immediately appear "expired" with NULL.
-- Falling back to created_at gives them a real anchor; if created_at is also NULL
-- we use NOW() so the user gets a fresh max_age window from this migration.
UPDATE users
   SET password_changed_at = COALESCE(created_at, NOW())
 WHERE password_changed_at IS NULL;
