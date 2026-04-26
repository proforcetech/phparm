-- Phase 6.1 of docs/expansion-plan.md: Customer Portal (isolated).
--
-- Binds a `portal_user` role account to the specific company (and optionally
-- a subset of that company's sites) it may see through the portal. Portal
-- users are strictly admin-provisioned — config/auth.php carries
-- `customer_portal.allow_registration = false`, and there is no self-service
-- signup path that writes here. The table is the durable enforcement point
-- so even if a JWT leaks, a scope check against the row stops cross-tenant
-- access.
--
-- Why a separate table instead of extra columns on `users`:
--   * A user row is authenticated identity; a portal_account is the
--     authorization scope. They move on different timelines: the staff can
--     revoke portal access without deactivating the user (who might still
--     need to log in via a different company's portal in the future).
--   * Future iteration may allow one user to have portal_accounts against
--     multiple companies; modeling scope as its own row avoids a breaking
--     schema migration when that day comes.
--
-- Fields:
--   * allowed_site_ids: NULL means "all sites owned by the company" (the
--     common case for a single-site customer). A JSON array of site IDs
--     means "only these sites" — lets a major account provision a portal
--     user limited to one of their 40 locations.
--   * provisioned_by_user_id: audit trail for the admin who created this
--     binding; required because allow_registration=false (only staff may
--     create portal accounts).
--   * revoked_at + revoked_reason: soft-revoke. We keep the row for audit
--     so a historical login's scope can still be explained.
--   * last_login_at: surfaces idle portal accounts for cleanup; also keeps
--     the users.last_activity_at separate for staff workflows.

CREATE TABLE IF NOT EXISTS portal_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    allowed_site_ids JSON NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    provisioned_by_user_id INT UNSIGNED NULL,
    provisioned_at DATETIME NOT NULL,
    last_login_at DATETIME NULL,
    revoked_at DATETIME NULL,
    revoked_reason VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_portal_user_company (user_id, company_id),
    KEY idx_portal_company (company_id, is_active),
    KEY idx_portal_user (user_id),
    KEY idx_portal_active (is_active, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
