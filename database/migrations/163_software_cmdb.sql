-- =============================================================================
-- Phase 14 / M9 of docs/woms-expansion-plan.md: Software / license CMDB.
--
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
-- 1. `software_assets`      — software catalog row (publisher + title +
--                              version + edition). One row per SKU we want to
--                              track. Customer-scoped so each MSP customer's
--                              catalog stays isolated; NULL customer_id means
--                              shared catalog (vendor master list).
-- 2. `license_seats`        — license pool per software_asset for one
--                              customer. Carries seats_owned (capacity) and
--                              seats_assigned (denormalized usage counter
--                              maintained by SoftwareInventoryService) so
--                              over-allocation checks are O(1).
-- 3. `license_assignments`  — seat consumption ledger. Each row binds one
--                              seat in a pool to either a user (named-user
--                              license) or a site_asset (machine/device
--                              license). unassigned_at NULL means the seat is
--                              still in use.
-- 4. `installed_software`   — site_asset ↔ software_asset join. Lets us answer
--                              "what's installed on this machine" and "where
--                              is this software running" without scanning
--                              license_assignments. license_assignment_id is
--                              optional — installed copies may be on trial,
--                              illicit, or pending license assignment.
--
-- WHY DENORMALIZED `seats_assigned`
-- -----------------------------------------------------------------------------
-- License compliance is the top-three IT customer concern (per the WOMS plan).
-- The over-allocation guard in SoftwareInventoryService::assign() must run on
-- every assignment without an aggregate join, so we keep the active-seat
-- count on the pool row itself. The service is the only writer; it
-- increments on assign and decrements on unassign within a transaction.
--
-- WHY NO ON-DELETE CASCADES TO ASSIGNMENT TARGETS
-- -----------------------------------------------------------------------------
-- A user or site_asset disappearing must not silently drop license
-- consumption records — we need the audit trail. So assignee_user_id /
-- assignee_site_asset_id use ON DELETE SET NULL; the row remains, with
-- unassigned_at populated by the application when the deletion event fires.
--
-- FK TYPE NOTES
-- -----------------------------------------------------------------------------
-- Mirrors migration 160's split: legacy tables (customers, users,
-- site_assets) are INT UNSIGNED; new tables here use BIGINT UNSIGNED PKs.
--
-- IDEMPOTENCY & SAFETY
-- -----------------------------------------------------------------------------
-- All four tables created via CREATE TABLE IF NOT EXISTS. No ALTERs to
-- existing tables, no DROPs, no destructive ops. Re-runs are no-ops.
--
-- ROLLBACK NOTE (manual; never auto-rollback in production)
-- -----------------------------------------------------------------------------
--   DROP TABLE installed_software;
--   DROP TABLE license_assignments;
--   DROP TABLE license_seats;
--   DROP TABLE software_assets;
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PART A: software_assets — catalog row per SKU
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS software_assets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NULL,
    publisher VARCHAR(160) NOT NULL,
    title VARCHAR(191) NOT NULL,
    version VARCHAR(80) NULL,
    edition VARCHAR(80) NULL,
    category VARCHAR(60) NULL,
    platform VARCHAR(40) NULL,
    sku VARCHAR(120) NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_software_assets_customer (customer_id),
    INDEX idx_software_assets_publisher (publisher),
    INDEX idx_software_assets_title (title),
    INDEX idx_software_assets_active (is_active),
    UNIQUE KEY uq_software_assets_sku (customer_id, publisher, title, version, edition),
    CONSTRAINT fk_software_assets_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART B: license_seats — license pool (one per customer per software_asset
--          per purchase). seats_assigned is denormalized; only the
--          SoftwareInventoryService writes to it within a transaction.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS license_seats (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    software_asset_id BIGINT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    license_type VARCHAR(40) NOT NULL DEFAULT 'subscription',
    license_key_ref VARCHAR(255) NULL,
    vendor_name VARCHAR(160) NULL,
    purchase_order_ref VARCHAR(80) NULL,
    purchased_at DATE NULL,
    expires_at DATE NULL,
    seats_owned INT UNSIGNED NOT NULL DEFAULT 0,
    seats_assigned INT UNSIGNED NOT NULL DEFAULT 0,
    cost_per_seat_cents BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_license_seats_software (software_asset_id),
    INDEX idx_license_seats_customer (customer_id),
    INDEX idx_license_seats_status (status),
    INDEX idx_license_seats_expires (expires_at),
    CONSTRAINT fk_license_seats_software FOREIGN KEY (software_asset_id)
        REFERENCES software_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_license_seats_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART C: license_assignments — seat consumption ledger. unassigned_at IS NULL
--          means the seat is currently in use; the SoftwareInventoryService
--          updates the parent license_seats.seats_assigned counter inside the
--          same transaction so over-allocation checks remain O(1).
--
--          Either assignee_user_id OR assignee_site_asset_id is set
--          (not both). assignee_type makes the discriminator explicit so
--          queries don't have to coalesce.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS license_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    license_seat_id BIGINT UNSIGNED NOT NULL,
    assignee_type VARCHAR(20) NOT NULL,
    assignee_user_id INT UNSIGNED NULL,
    assignee_site_asset_id INT UNSIGNED NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    assigned_by_user_id INT UNSIGNED NULL,
    unassigned_at DATETIME NULL,
    unassigned_by_user_id INT UNSIGNED NULL,
    unassign_reason VARCHAR(160) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_license_assignments_seat (license_seat_id),
    INDEX idx_license_assignments_user (assignee_user_id),
    INDEX idx_license_assignments_site_asset (assignee_site_asset_id),
    INDEX idx_license_assignments_active (license_seat_id, unassigned_at),
    CONSTRAINT fk_license_assignments_seat FOREIGN KEY (license_seat_id)
        REFERENCES license_seats(id) ON DELETE CASCADE,
    CONSTRAINT fk_license_assignments_user FOREIGN KEY (assignee_user_id)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_license_assignments_site_asset FOREIGN KEY (assignee_site_asset_id)
        REFERENCES site_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_license_assignments_assigned_by FOREIGN KEY (assigned_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_license_assignments_unassigned_by FOREIGN KEY (unassigned_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART D: installed_software — site_asset ↔ software_asset join.
--          UNIQUE on (site_asset_id, software_asset_id) — one row per
--          installed copy per host. License linkage is optional so we can
--          register installs we discover before the licensing is sorted out
--          (the over-allocation compliance view depends on this).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS installed_software (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_asset_id INT UNSIGNED NOT NULL,
    software_asset_id BIGINT UNSIGNED NOT NULL,
    installed_version VARCHAR(80) NULL,
    installed_at DATE NULL,
    detected_at TIMESTAMP NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'manual',
    license_assignment_id BIGINT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_installed_software_site_asset (site_asset_id),
    INDEX idx_installed_software_software (software_asset_id),
    INDEX idx_installed_software_assignment (license_assignment_id),
    INDEX idx_installed_software_detected (detected_at),
    UNIQUE KEY uq_installed_software (site_asset_id, software_asset_id),
    CONSTRAINT fk_installed_software_site_asset FOREIGN KEY (site_asset_id)
        REFERENCES site_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_installed_software_software FOREIGN KEY (software_asset_id)
        REFERENCES software_assets(id) ON DELETE CASCADE,
    CONSTRAINT fk_installed_software_assignment FOREIGN KEY (license_assignment_id)
        REFERENCES license_assignments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
