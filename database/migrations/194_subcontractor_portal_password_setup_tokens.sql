-- =============================================================================
-- Migration 194 — Subcontractor portal password setup tokens
--
-- Staff should not need to know or type a subcontractor's portal password.
-- When portal access is enabled, the system issues a short-lived tokenized
-- setup link to the subcontractor's email address. Tokens are stored hashed
-- and can be used once.
-- =============================================================================

CREATE TABLE IF NOT EXISTS subcontractor_portal_password_setup_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subcontractor_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_sub_portal_password_setup_hash (token_hash),
    INDEX idx_sub_portal_password_setup_sub (subcontractor_id, used_at, cancelled_at, expires_at),
    INDEX idx_sub_portal_password_setup_created_by (created_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: subcontractor_portal_password_setup_tokens.subcontractor_id -> subcontractors(id) CASCADE
SET @sub_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'subcontractors');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'subcontractor_portal_password_setup_tokens'
    AND constraint_name = 'fk_sub_portal_password_setup_sub');
SET @sql := IF(@sub_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE subcontractor_portal_password_setup_tokens ADD CONSTRAINT fk_sub_portal_password_setup_sub FOREIGN KEY (subcontractor_id) REFERENCES subcontractors(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: subcontractor_portal_password_setup_tokens.created_by_user_id -> users(id) SET NULL
SET @users_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'subcontractor_portal_password_setup_tokens'
    AND constraint_name = 'fk_sub_portal_password_setup_user');
SET @sql := IF(@users_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE subcontractor_portal_password_setup_tokens ADD CONSTRAINT fk_sub_portal_password_setup_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
