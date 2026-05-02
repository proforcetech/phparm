-- =============================================================================
-- Phase 12 of docs/woms-expansion-plan.md: Property Management vertical (M6)
--
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
-- Creates `tenant_maintenance_requests` — the inbox a tenant submits when
-- something in their unit needs attention. A maintenance request is NOT a
-- workorder; it's the tenant-side intake doc that staff triage and convert to
-- a workorder (which then carries the unit_id + tenant_billable_party
-- snapshot per migrations 157/158).
--
-- WHY A SEPARATE TABLE (not estimate_requests)
-- -----------------------------------------------------------------------------
-- estimate_requests is heavily auto-shaped (vehicle_year/make/model/vin) and
-- the public form lives unauthenticated behind reCAPTCHA. Tenant requests are
-- authenticated (via Tenant.portal_user_id), unit-pinned, and have a different
-- lifecycle (pending → triaged → converted | declined). Forcing both flows
-- through one table would muddy validation on both ends.
--
-- LIFECYCLE
-- -----------------------------------------------------------------------------
--   pending     → tenant just submitted, awaiting triage
--   triaged     → staff has reviewed, planning the WO
--   converted   → a workorder has been created (workorder_id is set)
--   declined    → staff rejected (e.g., not landlord's responsibility)
--   cancelled   → tenant withdrew before conversion
--
-- IDEMPOTENCY
-- -----------------------------------------------------------------------------
-- CREATE TABLE IF NOT EXISTS — fully idempotent. No-op on re-run.
--
-- ROLLBACK NOTE (manual)
-- -----------------------------------------------------------------------------
--   DROP TABLE tenant_maintenance_requests;
-- =============================================================================

CREATE TABLE IF NOT EXISTS tenant_maintenance_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    -- The tenant_lease the request was filed under. Snapshotted because the
    -- lease may end before the request is converted; we still want to be able
    -- to trace which lease term the request belonged to.
    tenant_lease_id BIGINT UNSIGNED NULL,
    -- Free-form category — used by TenantBillingResolver for split leases.
    -- Examples: plumbing, hvac, appliance, structural, pest, electrical.
    category VARCHAR(50) NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'normal',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    -- Set when status = converted. The WO carries the binding snapshot.
    -- INT UNSIGNED to match workorders.id (legacy schema; tenants/units/
    -- leases use BIGINT, but workorders predates that convention).
    workorder_id INT UNSIGNED NULL,
    -- Staff-side audit fields.
    triaged_at DATETIME NULL,
    triaged_by BIGINT UNSIGNED NULL,
    converted_at DATETIME NULL,
    converted_by BIGINT UNSIGNED NULL,
    declined_reason VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tmr_tenant (tenant_id),
    KEY idx_tmr_unit (unit_id),
    KEY idx_tmr_status (status),
    KEY idx_tmr_workorder (workorder_id),
    CONSTRAINT fk_tmr_tenant FOREIGN KEY (tenant_id) REFERENCES tenants(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_tmr_unit FOREIGN KEY (unit_id) REFERENCES units(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_tmr_lease FOREIGN KEY (tenant_lease_id) REFERENCES tenant_leases(id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_tmr_workorder FOREIGN KEY (workorder_id) REFERENCES workorders(id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
