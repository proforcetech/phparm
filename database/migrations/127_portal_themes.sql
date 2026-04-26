-- Phase 6.8 of docs/expansion-plan.md — white-label theming per major
-- account.
--
-- One theme row per company (UNIQUE company_id). Major tenants get
-- their own logo, color palette, custom support contact info, and an
-- optional hostname/subdomain so their staff and customers can visit
-- portal.acme.com instead of the default multi-tenant URL. Companies
-- with no theme row render with platform defaults — the service layer
-- returns a "default" payload in that case so the frontend doesn't
-- branch on null.
--
-- Scope / lookup:
--   * company_id UNIQUE: the enforcement point. Staff admin upsert is
--     idempotent by company_id; no dangling "draft" rows.
--   * custom_subdomain UNIQUE NULL: tenant.portal.example.com style
--     slug, validated to [a-z0-9-]{3,63} with no leading/trailing dash
--     so we stay within DNS label rules.
--   * custom_domain UNIQUE NULL: fully-qualified host for vanity URLs
--     like portal.acme.com; tenant is responsible for CNAME and cert.
--     App just uses the Host header to look up which company the
--     request is decorating.
--
-- Asset fields are URLs, not file bytes. Admin uploads via existing
-- upload infra (Phase 6.6's storage, or a CDN the tenant already has)
-- and pastes the resulting URL. Avoids mixing asset bytes into the
-- theme table, which would grow unbounded.
--
-- Email override fields carry per-tenant From/Reply-To so
-- NotificationService can substitute them on portal-originated email
-- (docs/expansion-plan.md future phase — this migration just stores
-- the strings; wiring the mailer to use them is deferred until email
-- template rendering moves through a tenant-aware path).
--
-- No custom_css column by design — color palette + logos covers the
-- 95% UX and avoids the XSS / parser-injection surface that arbitrary
-- CSS introduces. Additional fields can be added later if needed.

CREATE TABLE IF NOT EXISTS portal_themes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    custom_subdomain VARCHAR(63) NULL,
    custom_domain VARCHAR(255) NULL,
    primary_color CHAR(7) NULL,
    secondary_color CHAR(7) NULL,
    accent_color CHAR(7) NULL,
    background_color CHAR(7) NULL,
    text_color CHAR(7) NULL,
    logo_url VARCHAR(512) NULL,
    favicon_url VARCHAR(512) NULL,
    email_logo_url VARCHAR(512) NULL,
    email_from_name VARCHAR(120) NULL,
    email_from_address VARCHAR(255) NULL,
    email_reply_to VARCHAR(255) NULL,
    support_phone VARCHAR(40) NULL,
    support_email VARCHAR(255) NULL,
    support_url VARCHAR(512) NULL,
    footer_text VARCHAR(500) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    updated_by_user_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_portal_themes_company (company_id),
    UNIQUE KEY uq_portal_themes_subdomain (custom_subdomain),
    UNIQUE KEY uq_portal_themes_domain (custom_domain),
    INDEX idx_portal_themes_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotent FK install — company_id must resolve to a real company,
-- ON DELETE CASCADE so purging a company wipes its theme row.
SET @has_fk_company := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'portal_themes'
      AND constraint_name = 'fk_portal_themes_company'
);
SET @companies_exists := (
    SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'companies'
);
SET @fk_company_sql := IF(
    @has_fk_company = 0 AND @companies_exists = 1,
    'ALTER TABLE portal_themes ADD CONSTRAINT fk_portal_themes_company FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE',
    'SELECT 1'
);
PREPARE fk_company_stmt FROM @fk_company_sql;
EXECUTE fk_company_stmt;
DEALLOCATE PREPARE fk_company_stmt;
