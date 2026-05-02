-- =============================================================================
-- Phase 12 of docs/woms-expansion-plan.md: Property Management vertical (M6)
--
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
-- 1. Creates `units` — sub-locations within a `sites` row (e.g., suite 200,
--    apartment 3B). A property-mgmt customer's site IS a building; its units
--    are the leasable spaces within it.
-- 2. Creates `tenants` — entity (individual or business) renting one or more
--    units. Optionally linked to a `companies` row when the tenant is itself
--    one of our customer companies.
-- 3. Creates `tenant_leases` — join row carrying lease terms (start/end dates,
--    monthly rent, deposit, billing responsibility, maintenance terms) that
--    connects a tenant to a unit for a defined period.
-- 4. Adds `workorders.unit_id` (NULLABLE) so a maintenance request can be
--    pinned to a specific unit. Other verticals leave this NULL.
-- 5. Adds `workorders.tenant_billable_party` (NULLABLE enum) — when set, the
--    invoicing flow routes the bill to either the tenant, the landlord, or
--    splits per the lease. NULL means non-property-mgmt WO (default behavior).
--
-- WHY UNITS LIVE UNDER SITES (NOT COMPANIES)
-- -----------------------------------------------------------------------------
-- A property-mgmt company manages many buildings (sites); each building has
-- many units; each unit may have a tenant. The hierarchy is:
--   companies (the property mgmt firm or building owner)
--     └─ sites (the buildings they manage)
--         └─ units (the leasable spaces — NEW)
--             └─ tenant_leases (current and historical leases — NEW)
--                 └─ tenants (the lessees — NEW)
-- WOs already FK to sites; the new `unit_id` is the optional next-level pin.
--
-- BILLING ROUTING
-- -----------------------------------------------------------------------------
-- `tenant_leases.billing_responsibility` drives whose invoice the WO becomes:
--   - 'landlord'  → bill the company that owns the building (default)
--   - 'tenant'    → bill the tenant directly (lease passes maintenance to them)
--   - 'split'     → application-level logic decides per category, see
--                   `tenant_leases.maintenance_terms` JSON for the rules
-- The PHP `TenantBillingResolver` (Phase 12 service layer) consumes this
-- column when converting a property-mgmt WO to an invoice. Until that service
-- exists, all WOs default to landlord billing — same as today.
--
-- IDEMPOTENCY & SAFETY
-- -----------------------------------------------------------------------------
-- Fully idempotent and additive. All CREATEs use IF NOT EXISTS; all ALTERs
-- are guarded by information_schema checks (mirrors migration 152). Re-runs
-- are no-ops. No DROPs, no destructive ALTERs, no data deletion.
--
-- ROLLBACK NOTE (manual; we deliberately do NOT auto-rollback in production)
-- -----------------------------------------------------------------------------
-- 1. Drop FKs on workorders:
--      ALTER TABLE workorders DROP FOREIGN KEY fk_workorders_unit;
-- 2. Drop indexes/columns on workorders:
--      ALTER TABLE workorders DROP INDEX idx_workorders_unit;
--      ALTER TABLE workorders DROP COLUMN unit_id;
--      ALTER TABLE workorders DROP COLUMN tenant_billable_party;
-- 3. Drop tables in dependency order:
--      DROP TABLE tenant_leases;
--      DROP TABLE tenants;
--      DROP TABLE units;
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PART A: units — leasable spaces within a site
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    code VARCHAR(40) NOT NULL,
    name VARCHAR(120) NULL,
    unit_type VARCHAR(40) NOT NULL DEFAULT 'commercial',
    floor VARCHAR(20) NULL,
    square_feet INT UNSIGNED NULL,
    bedrooms TINYINT UNSIGNED NULL,
    bathrooms DECIMAL(3,1) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_units_site_code (site_id, code),
    INDEX idx_units_site (site_id),
    INDEX idx_units_status (status),
    INDEX idx_units_type (unit_type),
    CONSTRAINT fk_units_site FOREIGN KEY (site_id)
        REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART B: tenants — lessees (individuals or businesses)
-- `company_id` optionally points to a `companies` row when the tenant is a
-- business we already track as a customer. For individual residential tenants
-- it stays NULL and `display_name` / `primary_email` carry the identity.
-- `portal_user_id` is the link to a `users` row when the tenant has been
-- granted portal access (separate from site_contacts which represent
-- landlord-side staff).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NULL,
    portal_user_id INT UNSIGNED NULL,
    entity_type VARCHAR(20) NOT NULL DEFAULT 'individual',
    display_name VARCHAR(191) NOT NULL,
    primary_email VARCHAR(160) NULL,
    primary_phone VARCHAR(40) NULL,
    secondary_phone VARCHAR(40) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    move_in_date DATE NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenants_company (company_id),
    INDEX idx_tenants_portal_user (portal_user_id),
    INDEX idx_tenants_status (status),
    INDEX idx_tenants_email (primary_email),
    CONSTRAINT fk_tenants_company FOREIGN KEY (company_id)
        REFERENCES companies(id) ON DELETE SET NULL,
    CONSTRAINT fk_tenants_portal_user FOREIGN KEY (portal_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART C: tenant_leases — terms binding a tenant to a unit
-- `billing_responsibility` is the routing key for WO→invoice flow:
--    landlord (default) | tenant | split
-- Application validates against the allowed set (no CHECK constraint so the
-- vocabulary can evolve without a migration). `maintenance_terms` carries
-- per-category rules (e.g., {"plumbing":"landlord","fixtures":"tenant"})
-- that the split case consults.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenant_leases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    unit_id BIGINT UNSIGNED NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    monthly_rent DECIMAL(12,2) NULL,
    deposit_amount DECIMAL(12,2) NULL,
    billing_responsibility VARCHAR(20) NOT NULL DEFAULT 'landlord',
    maintenance_terms JSON NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    terms TEXT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tenant_leases_tenant (tenant_id),
    INDEX idx_tenant_leases_unit (unit_id),
    INDEX idx_tenant_leases_status (status),
    INDEX idx_tenant_leases_dates (start_date, end_date),
    CONSTRAINT fk_tenant_leases_tenant FOREIGN KEY (tenant_id)
        REFERENCES tenants(id) ON DELETE CASCADE,
    CONSTRAINT fk_tenant_leases_unit FOREIGN KEY (unit_id)
        REFERENCES units(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART D: workorders.unit_id — optional FK pinning a WO to a specific unit
-- NULL for every non-property-mgmt WO; populated by the property portal /
-- dispatcher when a tenant submits a unit-specific request.
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND column_name = 'unit_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE workorders ADD COLUMN unit_id BIGINT UNSIGNED NULL AFTER service_line_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND index_name = 'idx_workorders_unit');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE workorders ADD INDEX idx_workorders_unit (unit_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND constraint_name = 'fk_workorders_unit');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE workorders ADD CONSTRAINT fk_workorders_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- PART E: workorders.tenant_billable_party — invoicing routing for property mgmt
-- NULL for non-property-mgmt WOs; one of (landlord, tenant, split) when set.
-- Snapshotted at WO creation time from the active lease's billing_responsibility
-- so a later lease change does not retroactively re-route prior invoices.
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND column_name = 'tenant_billable_party');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE workorders ADD COLUMN tenant_billable_party VARCHAR(20) NULL AFTER unit_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND index_name = 'idx_workorders_tenant_billable');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE workorders ADD INDEX idx_workorders_tenant_billable (tenant_billable_party)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
