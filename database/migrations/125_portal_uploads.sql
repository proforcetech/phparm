-- Phase 6.6 of docs/expansion-plan.md — portal document/photo upload.
--
-- One table for portal-originated attachments across all entity types the
-- portal can upload against (ticket / workorder / message_thread). A
-- single polymorphic table keeps the repository surface small and lets
-- `portal.upload.*` audit events share one code path regardless of what
-- the upload is attached to.
--
-- Scope filters:
--   * portal_account_id — FK CASCADE so purging a portal account also
--     drops its upload rows (caller is still responsible for unlinking
--     the files on disk before deletion);
--   * company_id — denormalized from portal_accounts.company_id so the
--     hot-path "list uploads for this entity in this company" read is a
--     single indexed lookup without a portal_accounts join;
--   * (entity_type, entity_id, deleted_at) composite — the canonical
--     portal read pattern: "all uploads still alive on ticket 42".
--
-- File storage:
--   * stored_path is a webroot-relative path (e.g. /uploads/portal/10/
--     202604/abc123.png) so the portal download endpoint can resolve to
--     an absolute path under public/ without joining any other tables.
--     Random filenames prevent enumeration even if the webroot served
--     the directory directly.
--   * sha256 is captured at upload time so we can detect dupes and
--     detect silent corruption at download time (belt+suspenders;
--     download endpoint can optionally verify).
--
-- Soft delete: deleted_at lets the portal UI keep referring to an
-- upload after it's been removed (e.g. in a message thread timeline)
-- without the physical file actually being accessible — a background
-- cleanup job reads deleted_at IS NOT NULL and unlinks the files.

CREATE TABLE IF NOT EXISTS portal_uploads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    portal_account_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(512) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    uploaded_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_portal_uploads_entity (entity_type, entity_id, deleted_at),
    INDEX idx_portal_uploads_account (portal_account_id, deleted_at),
    INDEX idx_portal_uploads_company (company_id, deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent FK install so the migration can be re-run after schema
-- drift without erroring on duplicate constraint name.
SET @has_fk_portal_uploads_account := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'portal_uploads'
      AND constraint_name = 'fk_portal_uploads_account'
);
SET @portal_accounts_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'portal_accounts'
);
SET @fk_portal_uploads_account_sql := IF(
    @has_fk_portal_uploads_account = 0 AND @portal_accounts_exists = 1,
    'ALTER TABLE portal_uploads ADD CONSTRAINT fk_portal_uploads_account FOREIGN KEY (portal_account_id) REFERENCES portal_accounts (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE fk_portal_uploads_account_stmt FROM @fk_portal_uploads_account_sql;
EXECUTE fk_portal_uploads_account_stmt;
DEALLOCATE PREPARE fk_portal_uploads_account_stmt;
