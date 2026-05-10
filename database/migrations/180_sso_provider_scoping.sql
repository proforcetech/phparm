-- Phase 2e (portal SSO, Decision D): per-company providers + side scoping.
--
-- Today, sso_providers serves staff (`users`) only and is implicitly global.
-- Decision D requires SSO that works for both global and per-company providers,
-- on either side (staff or portal). This migration:
--
--   1. Adds `company_id` to sso_providers — NULL = global, set = scoped to one company.
--   2. Adds `portal_enabled` / `staff_enabled` flags so a single provider row can
--      be locked to one side, both, or neither (disabled without deleting).
--   3. Loosens sso_user_links + sso_login_attempts so they can target either
--      a staff `users` row OR a `portal_accounts` row. Existing `user_id` stays
--      NOT NULL on existing rows by virtue of the backfill, but the column is
--      relaxed to allow portal-only rows going forward.
--
-- Backfill: existing providers were staff-only, so we set staff_enabled=1 and
-- portal_enabled=0 for any row where the columns are still defaults (i.e. on
-- the first run only). Subsequent runs leave admin-edited values alone.
--
-- All ALTERs gated via information_schema; safe to re-run.

-- 1) sso_providers.company_id (NULL = global, FK to companies)
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_providers'
      AND column_name = 'company_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE sso_providers ADD COLUMN company_id INT UNSIGNED NULL AFTER slug',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) sso_providers.portal_enabled (defaults off — staff-only behavior preserved)
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_providers'
      AND column_name = 'portal_enabled');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE sso_providers ADD COLUMN portal_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) sso_providers.staff_enabled (defaults on — preserves existing staff SSO)
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_providers'
      AND column_name = 'staff_enabled');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE sso_providers ADD COLUMN staff_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER portal_enabled',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Providers visible to a given company on a given side" — primary read path
-- for /api/portal/auth/sso/providers and the staff login picker.
SET @idx_company_portal := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_providers'
      AND index_name = 'idx_sso_providers_company_portal');
SET @sql := IF(@idx_company_portal = 0,
    'CREATE INDEX idx_sso_providers_company_portal ON sso_providers (company_id, portal_enabled, is_active)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_company_staff := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_providers'
      AND index_name = 'idx_sso_providers_company_staff');
SET @sql := IF(@idx_company_staff = 0,
    'CREATE INDEX idx_sso_providers_company_staff ON sso_providers (company_id, staff_enabled, is_active)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK: sso_providers.company_id → companies(id) ON DELETE CASCADE
-- Cascading: if a company is deleted, its scoped providers go with it.
-- Global providers (company_id NULL) are unaffected.
SET @has_companies := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'companies');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_providers'
      AND constraint_name = 'fk_sso_providers_company');
SET @sql := IF(@has_companies = 1 AND @has_fk = 0,
    'ALTER TABLE sso_providers ADD CONSTRAINT fk_sso_providers_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) sso_user_links: add portal_account_id and relax user_id to allow portal-only rows.
--    A single link row points to either a staff user OR a portal account, never both.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_user_links'
      AND column_name = 'portal_account_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE sso_user_links ADD COLUMN portal_account_id INT UNSIGNED NULL AFTER user_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Relax user_id to NULL only if it's currently NOT NULL (idempotent).
SET @user_id_nullable := (SELECT IS_NULLABLE FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_user_links'
      AND column_name = 'user_id');
SET @sql := IF(@user_id_nullable = 'NO',
    'ALTER TABLE sso_user_links MODIFY COLUMN user_id INT UNSIGNED NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Find all links for a portal account" — settings UI lists linked SSO providers.
SET @idx_portal_acct := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_user_links'
      AND index_name = 'idx_sso_user_links_portal_account');
SET @sql := IF(@idx_portal_acct = 0,
    'CREATE INDEX idx_sso_user_links_portal_account ON sso_user_links (portal_account_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK: sso_user_links.portal_account_id → portal_accounts(id) ON DELETE CASCADE
SET @has_portal_accts := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'portal_accounts');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_user_links'
      AND constraint_name = 'fk_sso_link_portal_account');
SET @sql := IF(@has_portal_accts = 1 AND @has_fk = 0,
    'ALTER TABLE sso_user_links ADD CONSTRAINT fk_sso_link_portal_account FOREIGN KEY (portal_account_id) REFERENCES portal_accounts(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5) sso_login_attempts: same treatment — add portal_account_id, relax user_id.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_login_attempts'
      AND column_name = 'portal_account_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE sso_login_attempts ADD COLUMN portal_account_id INT UNSIGNED NULL AFTER user_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Side discriminator so attempts queries can filter staff vs portal cleanly.
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_login_attempts'
      AND column_name = 'side');
SET @sql := IF(@has_col = 0,
    "ALTER TABLE sso_login_attempts ADD COLUMN side VARCHAR(10) NOT NULL DEFAULT 'staff' AFTER provider_id",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_login_attempts'
      AND constraint_name = 'fk_sso_attempts_portal_account');
SET @sql := IF(@has_portal_accts = 1 AND @has_fk = 0,
    'ALTER TABLE sso_login_attempts ADD CONSTRAINT fk_sso_attempts_portal_account FOREIGN KEY (portal_account_id) REFERENCES portal_accounts(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- intended_company_id: portal flow needs to remember "which tenant am I logging
-- the user into" across the IdP roundtrip. For company-scoped providers it's
-- redundant with provider.company_id, but for global providers it's the only
-- way to disambiguate which portal_account to land on (one user can hold
-- portal_accounts in multiple companies).
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_login_attempts'
      AND column_name = 'intended_company_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE sso_login_attempts ADD COLUMN intended_company_id INT UNSIGNED NULL AFTER portal_account_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_login_attempts'
      AND constraint_name = 'fk_sso_attempts_intended_company');
SET @sql := IF(@has_companies = 1 AND @has_fk = 0,
    'ALTER TABLE sso_login_attempts ADD CONSTRAINT fk_sso_attempts_intended_company FOREIGN KEY (intended_company_id) REFERENCES companies(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 6) Backfill existing rows. We only flip rows that still look like the original
--    schema defaults (portal_enabled=0 AND staff_enabled=1) — that's a no-op,
--    so this is safe. Real intent: future-proof if defaults ever change.
--    Existing providers stay staff-only by default, which preserves behavior.
UPDATE sso_providers
   SET staff_enabled = 1
 WHERE staff_enabled IS NULL;
