-- Cross-cutting: SSO providers + MFA hardening (trusted devices).
--
-- Two parallel surfaces:
--
--   sso_providers
--     Configured SSO providers (OIDC-shaped — works for Google,
--     Microsoft Entra/Azure AD, Okta, Auth0, generic OIDC, plus
--     SAML in a later phase via the same provider table). One
--     row per provider; admins enable/disable + tweak config
--     without code changes.
--
--   sso_user_links
--     Maps a local user to an external provider subject (sub
--     claim from OIDC, NameID from SAML). One user can have
--     multiple links across providers (work account + personal
--     account both linked to the same shop user). Login
--     verifies the link, refreshes the user's email/name from
--     the IdP if configured to.
--
--   trusted_devices
--     Per-user device trust tokens. After a successful 2FA
--     challenge, an opaque token is stored hashed and dropped
--     in a long-lived cookie; subsequent logins from the same
--     device skip the 2FA prompt for the configured TTL. This
--     is the "remember this device for 30 days" UX standard.
--     Hashed at rest so the cookie value alone is the
--     credential — DB compromise doesn't grant device trust.
--
--   sso_login_attempts
--     Per-attempt audit (provider, state, nonce, redirect_uri,
--     created_at, completed_at, status, error). Lets ops
--     debug stuck SSO loops + correlate against
--     security_events.
--
-- All FKs guarded on referenced-table existence. Migration is
-- idempotent: tables are CREATE TABLE IF NOT EXISTS, indexes
-- and FKs are gated on information_schema lookups.

CREATE TABLE IF NOT EXISTS sso_providers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(60) NOT NULL,
    name VARCHAR(120) NOT NULL,
    type VARCHAR(20) NOT NULL DEFAULT 'oidc',
    issuer_url VARCHAR(255) NULL,
    client_id VARCHAR(255) NULL,
    client_secret VARCHAR(500) NULL,
    redirect_uri VARCHAR(255) NULL,
    authorize_endpoint VARCHAR(255) NULL,
    token_endpoint VARCHAR(255) NULL,
    userinfo_endpoint VARCHAR(255) NULL,
    jwks_uri VARCHAR(255) NULL,
    scopes VARCHAR(255) NOT NULL DEFAULT 'openid email profile',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    auto_provision TINYINT(1) NOT NULL DEFAULT 0,
    default_role VARCHAR(60) NULL,
    sync_profile_on_login TINYINT(1) NOT NULL DEFAULT 1,
    metadata JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- "Look up provider by slug" — primary read path from /api/auth/sso/{slug}.
SET @idx_slug := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_providers'
      AND index_name = 'uniq_sso_providers_slug');
SET @sql := IF(@idx_slug = 0,
    'CREATE UNIQUE INDEX uniq_sso_providers_slug ON sso_providers (slug)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_active := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_providers'
      AND index_name = 'idx_sso_providers_active');
SET @sql := IF(@idx_active = 0,
    'CREATE INDEX idx_sso_providers_active ON sso_providers (is_active)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


CREATE TABLE IF NOT EXISTS sso_user_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    provider_id INT UNSIGNED NOT NULL,
    subject VARCHAR(255) NOT NULL,
    email VARCHAR(255) NULL,
    display_name VARCHAR(160) NULL,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- "Find a link by (provider, subject)" — the IdP callback's
-- primary lookup ("does this external identity already point
-- at a local user?").
SET @idx_link := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_user_links'
      AND index_name = 'uniq_sso_user_links_provider_subject');
SET @sql := IF(@idx_link = 0,
    'CREATE UNIQUE INDEX uniq_sso_user_links_provider_subject ON sso_user_links (provider_id, subject)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "All providers linked to user X" — the user settings page.
SET @idx_user := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_user_links'
      AND index_name = 'idx_sso_user_links_user');
SET @sql := IF(@idx_user = 0,
    'CREATE INDEX idx_sso_user_links_user ON sso_user_links (user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FKs for sso_user_links
SET @has_users := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_user_links'
      AND constraint_name = 'fk_sso_link_user');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE sso_user_links ADD CONSTRAINT fk_sso_link_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_providers := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sso_providers');
SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_user_links'
      AND constraint_name = 'fk_sso_link_provider');
SET @sql := IF(@has_providers = 1 AND @has_fk = 0,
    'ALTER TABLE sso_user_links ADD CONSTRAINT fk_sso_link_provider FOREIGN KEY (provider_id) REFERENCES sso_providers(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


CREATE TABLE IF NOT EXISTS trusted_devices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    label VARCHAR(160) NULL,
    user_agent VARCHAR(500) NULL,
    ip_address VARCHAR(45) NULL,
    last_used_at DATETIME NULL,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- "Look up trust by hashed token" — the auth middleware's
-- per-request lookup when the trust cookie is presented.
SET @idx_hash := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'trusted_devices'
      AND index_name = 'uniq_trusted_devices_hash');
SET @sql := IF(@idx_hash = 0,
    'CREATE UNIQUE INDEX uniq_trusted_devices_hash ON trusted_devices (token_hash)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "All trusted devices for user X" — the user settings page
-- shows them, so they can revoke a stolen laptop.
SET @idx_td_user := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'trusted_devices'
      AND index_name = 'idx_trusted_devices_user');
SET @sql := IF(@idx_td_user = 0,
    'CREATE INDEX idx_trusted_devices_user ON trusted_devices (user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Sweep expired trust tokens" — cron job, indexed for the
-- range scan.
SET @idx_td_exp := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'trusted_devices'
      AND index_name = 'idx_trusted_devices_expires');
SET @sql := IF(@idx_td_exp = 0,
    'CREATE INDEX idx_trusted_devices_expires ON trusted_devices (expires_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'trusted_devices'
      AND constraint_name = 'fk_trusted_devices_user');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE trusted_devices ADD CONSTRAINT fk_trusted_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


CREATE TABLE IF NOT EXISTS sso_login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    state VARCHAR(120) NOT NULL,
    nonce VARCHAR(120) NULL,
    redirect_uri VARCHAR(255) NULL,
    user_id INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- "Look up attempt by state" — the IdP callback validates
-- the state param against this column.
SET @idx_state := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_login_attempts'
      AND index_name = 'uniq_sso_attempts_state');
SET @sql := IF(@idx_state = 0,
    'CREATE UNIQUE INDEX uniq_sso_attempts_state ON sso_login_attempts (state)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Recent attempts" for the SOC dashboard.
SET @idx_att_created := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_login_attempts'
      AND index_name = 'idx_sso_attempts_created');
SET @sql := IF(@idx_att_created = 0,
    'CREATE INDEX idx_sso_attempts_created ON sso_login_attempts (created_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_login_attempts'
      AND constraint_name = 'fk_sso_attempts_provider');
SET @sql := IF(@has_providers = 1 AND @has_fk = 0,
    'ALTER TABLE sso_login_attempts ADD CONSTRAINT fk_sso_attempts_provider FOREIGN KEY (provider_id) REFERENCES sso_providers(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'sso_login_attempts'
      AND constraint_name = 'fk_sso_attempts_user');
SET @sql := IF(@has_users = 1 AND @has_fk = 0,
    'ALTER TABLE sso_login_attempts ADD CONSTRAINT fk_sso_attempts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
