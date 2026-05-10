-- =============================================================================
-- Phase 2d (Decision C in memory) — sub-user role tiers + JSON scope.
--
-- Today every portal_user is effectively all-powerful within their company:
-- the role's grants in config/auth.php cover every portal.* permission, and
-- the only per-row scope is allowed_site_ids. That model breaks as soon as a
-- customer wants to invite a junior teammate who can SEE invoices but cannot
-- PAY them — or a site lead who can request work but not sign contracts.
--
-- This migration adds two columns to express that:
--
--   * role_tier ENUM — coarse, named tier the UI can render as a badge:
--       viewer    : read-only across all surfaces
--       requester : viewer + create tickets, send messages, upload files
--       approver  : requester + approve estimates, sign contracts, pay
--                   invoices, manage saved cards, decide leases/acquisitions
--       admin     : approver + (future) manage other portal users on the
--                   account
--     Tier baselines are evaluated server-side by PortalPermissionService;
--     the column is the one source of truth so a leaked JWT can be flipped
--     by editing the row, just like is_active/revoked_at.
--
--   * scope JSON — fine-grained overlay so an account doesn't have to fit
--     a tier exactly. Initial supported shape:
--       {
--         "permissions": {
--           "grant": ["pay.invoices"],
--           "deny":  ["approve.estimates"]
--         }
--       }
--     Filter keys (ticket_categories, max_invoice_amount_cents, etc.) will
--     layer in later under their own top-level keys; PortalPermissionService
--     ignores unknown keys to keep that growth backwards-compatible.
--
-- Why both columns instead of just the JSON: the tier is what humans pick
-- in the admin UI ("make Sara a requester"), the JSON is the escape hatch
-- when someone needs one extra capability. Keeping the tier as an enum
-- column also makes per-tier stats / audits trivial (no JSON_EXTRACT in
-- reporting queries).
--
-- BACKFILL: existing portal_accounts get role_tier='admin' so we don't
-- silently strip pay/sign/approve from anyone who was already using those
-- surfaces. New rows default to 'requester' (the "invite a teammate"
-- common case); admin provisioning explicitly sets the tier when creating
-- the first owner account for a company.
--
-- Idempotent ALTER pattern from migration 152/178.
-- =============================================================================

-- Capture whether the column already exists BEFORE we add it. The
-- backfill below only runs when @has_role started at 0 — that's how we
-- distinguish "first time this migration runs" from "re-run on an
-- already-migrated DB" without depending on migration runner internals.
SET @has_role := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'portal_accounts' AND column_name = 'role_tier');
SET @sql := IF(@has_role = 0,
    "ALTER TABLE portal_accounts
       ADD COLUMN role_tier ENUM('viewer','requester','approver','admin')
         NOT NULL DEFAULT 'requester' AFTER allowed_site_ids",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_scope := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'portal_accounts' AND column_name = 'scope');
SET @sql := IF(@has_scope = 0,
    "ALTER TABLE portal_accounts ADD COLUMN scope JSON NULL AFTER role_tier",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Backfill ONLY on the first migration run (column did not exist before
-- this run). Existing rows predate the tier system and were effectively
-- admin; stamping them prevents a behavior regression for legacy
-- portal_users who were already approving estimates and paying invoices.
SET @sql := IF(@has_role = 0,
    "UPDATE portal_accounts SET role_tier = 'admin'",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'portal_accounts' AND index_name = 'idx_portal_role_tier');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE portal_accounts ADD INDEX idx_portal_role_tier (company_id, role_tier)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
