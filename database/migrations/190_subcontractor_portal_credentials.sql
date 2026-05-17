-- =============================================================================
-- Migration 190 — Subcontractor portal credential login
--
-- Adds email/password login support to the subcontractor portal while keeping
-- existing one-click portal tokens valid. Password hashes stay on the
-- subcontractors table; successful credential login issues a normal
-- subcontractor_portal_tokens row so the existing self-service APIs keep their
-- current bearer-token boundary.
-- =============================================================================

SET @subcontractors_table_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = 'subcontractors'
);

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'subcontractors'
      AND column_name = 'portal_login_enabled'
);
SET @sql := IF(@subcontractors_table_exists > 0 AND @has_col = 0,
    'ALTER TABLE subcontractors ADD COLUMN portal_login_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER notes',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'subcontractors'
      AND column_name = 'portal_password_hash'
);
SET @sql := IF(@subcontractors_table_exists > 0 AND @has_col = 0,
    'ALTER TABLE subcontractors ADD COLUMN portal_password_hash VARCHAR(255) NULL AFTER portal_login_enabled',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'subcontractors'
      AND column_name = 'portal_password_updated_at'
);
SET @sql := IF(@subcontractors_table_exists > 0 AND @has_col = 0,
    'ALTER TABLE subcontractors ADD COLUMN portal_password_updated_at DATETIME NULL AFTER portal_password_hash',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'subcontractors'
      AND column_name = 'portal_last_login_at'
);
SET @sql := IF(@subcontractors_table_exists > 0 AND @has_col = 0,
    'ALTER TABLE subcontractors ADD COLUMN portal_last_login_at DATETIME NULL AFTER portal_password_updated_at',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'subcontractors'
      AND column_name = 'portal_last_login_ip'
);
SET @sql := IF(@subcontractors_table_exists > 0 AND @has_col = 0,
    'ALTER TABLE subcontractors ADD COLUMN portal_last_login_ip VARCHAR(45) NULL AFTER portal_last_login_at',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'subcontractors'
      AND index_name = 'idx_subcontractors_portal_login_email'
);
SET @sql := IF(@subcontractors_table_exists > 0 AND @has_index = 0,
    'CREATE INDEX idx_subcontractors_portal_login_email ON subcontractors (email, portal_login_enabled)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
