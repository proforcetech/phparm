-- =============================================================================
-- Phase 15 / M7 of docs/woms-expansion-plan.md: recurring service routes.
-- Cleaning is the driving use case but the same primitives serve recurring
-- security audits, PM rounds, and any other "same techs, same sites, same
-- cadence" workflow.
--
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
-- 1. `service_routes`        — the route definition (template + cadence).
-- 2. `route_stops`           — ordered stops per route (one per site/asset).
-- 3. `route_visits`          — each materialized occurrence; one auto-created
--                               lightweight WO per visit, QR token for scan-on-
--                               arrival, status tracks the visit lifecycle.
-- 4. `route_visit_photos`    — verification photos uploaded against a visit.
--                               Carries EXIF + perceptual hash so the S8
--                               heuristics (#139) can flag duplicates and
--                               geo/time outliers without re-reading the file.
--
-- VISIT LIFECYCLE (enforced by RouteVisit::TRANSITIONS in the model layer):
--   planned -> en_route -> arrived -> completed
--   planned -> skipped              (tech declined the stop with a reason)
--   planned -> missed               (cron marked it after window expired)
--
-- WHY A SEPARATE PHOTOS TABLE
-- -----------------------------------------------------------------------------
-- The auto-created WO already accepts photo uploads, but verification photos
-- are a per-visit concept (the cleaning customer wants proof THIS visit
-- happened, not just that the WO has SOME photos). Keeping them on the visit
-- row also lets us drop/replace the linked WO without losing the audit chain
-- and lets the S8 heuristics index on a tight, purpose-built table.
--
-- WHY NO ON-DELETE CASCADE FROM workorders
-- -----------------------------------------------------------------------------
-- A visit must outlive its auto-WO so the audit ledger and verification
-- photos survive a WO purge. workorder_id uses ON DELETE SET NULL.
--
-- FK TYPE NOTES
-- -----------------------------------------------------------------------------
-- Mirrors migration 164: legacy tables (customers, sites, site_assets,
-- workorders, users) are INT UNSIGNED; new tables here use BIGINT UNSIGNED PKs.
--
-- IDEMPOTENCY & SAFETY
-- -----------------------------------------------------------------------------
-- All CREATE TABLE IF NOT EXISTS. No ALTERs to existing tables, no DROPs.
-- Re-runs are no-ops.
--
-- ROLLBACK NOTE (manual; never auto-rollback in production)
-- -----------------------------------------------------------------------------
--   DROP TABLE route_visit_photos;
--   DROP TABLE route_visits;
--   DROP TABLE route_stops;
--   DROP TABLE service_routes;
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PART A: service_routes — route template + recurrence rule.
--
-- recurrence_type: 'daily' | 'weekly' | 'monthly' | 'custom'
--   - daily:   every N days (recurrence_interval)
--   - weekly:  every N weeks on recurrence_days_of_week (comma list 0-6, Sun=0)
--   - monthly: every N months on recurrence_day_of_month (1-31, capped to month end)
--   - custom:  service-side rule (extension point — currently treated as inactive)
--
-- generation_horizon_days defaults to 14: the cron rolls visits forward this
-- many days from "now" each tick. last_generated_through is the checkpoint so
-- subsequent generator runs only emit new visits.
--
-- photo_verification_required + min_photos_per_visit are route-level defaults;
-- per-stop override on route_stops.required_photos when non-NULL.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_routes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    service_line_id BIGINT UNSIGNED NULL,
    default_assigned_user_id INT UNSIGNED NULL,
    name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    recurrence_type VARCHAR(20) NOT NULL DEFAULT 'weekly',
    recurrence_interval INT UNSIGNED NOT NULL DEFAULT 1,
    recurrence_days_of_week VARCHAR(20) NULL,
    recurrence_day_of_month TINYINT UNSIGNED NULL,
    recurrence_time_of_day TIME NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    generation_horizon_days SMALLINT UNSIGNED NOT NULL DEFAULT 14,
    last_generated_through DATETIME NULL,
    photo_verification_required TINYINT(1) NOT NULL DEFAULT 0,
    min_photos_per_visit TINYINT UNSIGNED NOT NULL DEFAULT 0,
    estimated_visit_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_service_routes_customer (customer_id),
    INDEX idx_service_routes_status (status),
    INDEX idx_service_routes_assigned (default_assigned_user_id),
    INDEX idx_service_routes_service_line (service_line_id),
    INDEX idx_service_routes_generation (last_generated_through),
    CONSTRAINT fk_service_routes_customer FOREIGN KEY (customer_id)
        REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_service_routes_service_line FOREIGN KEY (service_line_id)
        REFERENCES service_lines(id) ON DELETE SET NULL,
    CONSTRAINT fk_service_routes_assigned FOREIGN KEY (default_assigned_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART B: route_stops — ordered stops within a route.
--
-- sequence is unique per route (UNIQUE (service_route_id, sequence)) so the
-- mobile app can render stops in deterministic order. site_asset_id is
-- optional — most stops are site-level but some (e.g. cooler at unit 4) need
-- pinning.
--
-- required_photos overrides the route-level min when non-NULL; NULL means
-- "use the route default."
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS route_stops (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_route_id BIGINT UNSIGNED NOT NULL,
    sequence SMALLINT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NOT NULL,
    site_asset_id INT UNSIGNED NULL,
    stop_name VARCHAR(191) NULL,
    estimated_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    checklist_template_id INT UNSIGNED NULL,
    required_photos TINYINT UNSIGNED NULL,
    notes TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_route_stop_sequence (service_route_id, sequence),
    INDEX idx_route_stops_route (service_route_id),
    INDEX idx_route_stops_site (site_id),
    INDEX idx_route_stops_asset (site_asset_id),
    CONSTRAINT fk_route_stops_route FOREIGN KEY (service_route_id)
        REFERENCES service_routes(id) ON DELETE CASCADE,
    CONSTRAINT fk_route_stops_site FOREIGN KEY (site_id)
        REFERENCES sites(id) ON DELETE RESTRICT,
    CONSTRAINT fk_route_stops_asset FOREIGN KEY (site_asset_id)
        REFERENCES site_assets(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART C: route_visits — materialized occurrence per stop per scheduled day.
--
-- status: 'planned' | 'en_route' | 'arrived' | 'completed' | 'skipped' | 'missed'
--
-- workorder_id links to the auto-created lightweight WO. Set NULL on WO purge
-- so the visit + verification photos survive.
--
-- qr_token is the value baked into the printable QR sticker at each site.
-- The mobile scan endpoint validates the token, asserts the visit is in
-- 'planned' or 'en_route', and stamps arrived_at + GPS.
--
-- photos_uploaded is a denormalized counter so the required-photo guard runs
-- in O(1) at completion time. The RouteVisitService keeps it in sync inside
-- the same transaction that inserts/deletes route_visit_photos.
--
-- verification_passed is NULL until the S8 heuristics evaluate the photos
-- (NULL = not yet evaluated; 1 = passed; 0 = flagged).
--
-- UNIQUE (route_stop_id, scheduled_for) blocks the generator from emitting
-- duplicate visits for the same stop+slot if it's restarted mid-run.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS route_visits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    service_route_id BIGINT UNSIGNED NOT NULL,
    route_stop_id BIGINT UNSIGNED NOT NULL,
    workorder_id INT UNSIGNED NULL,
    assigned_user_id INT UNSIGNED NULL,
    scheduled_for DATETIME NOT NULL,
    scheduled_window_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    status VARCHAR(20) NOT NULL DEFAULT 'planned',
    qr_token VARCHAR(64) NOT NULL,
    en_route_at DATETIME NULL,
    arrived_at DATETIME NULL,
    arrival_lat DECIMAL(10,7) NULL,
    arrival_lng DECIMAL(10,7) NULL,
    completed_at DATETIME NULL,
    skipped_at DATETIME NULL,
    skip_reason VARCHAR(255) NULL,
    missed_at DATETIME NULL,
    photos_uploaded SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    verification_passed TINYINT(1) NULL,
    verification_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_route_visit_qr_token (qr_token),
    UNIQUE KEY uq_route_visit_slot (route_stop_id, scheduled_for),
    INDEX idx_route_visits_route (service_route_id),
    INDEX idx_route_visits_stop (route_stop_id),
    INDEX idx_route_visits_workorder (workorder_id),
    INDEX idx_route_visits_assigned (assigned_user_id),
    INDEX idx_route_visits_status (status),
    INDEX idx_route_visits_scheduled (scheduled_for),
    INDEX idx_route_visits_status_scheduled (status, scheduled_for),
    CONSTRAINT fk_route_visits_route FOREIGN KEY (service_route_id)
        REFERENCES service_routes(id) ON DELETE CASCADE,
    CONSTRAINT fk_route_visits_stop FOREIGN KEY (route_stop_id)
        REFERENCES route_stops(id) ON DELETE CASCADE,
    CONSTRAINT fk_route_visits_workorder FOREIGN KEY (workorder_id)
        REFERENCES workorders(id) ON DELETE SET NULL,
    CONSTRAINT fk_route_visits_assigned FOREIGN KEY (assigned_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART D: route_visit_photos — verification photos per visit.
--
-- file_path is the storage-relative path the existing FileStorage layer
-- resolves. exif_* are extracted at upload time so the S8 heuristics
-- (perceptual hash duplicate check, geo/time-window check) don't need to
-- re-open the file.
--
-- perceptual_hash is a 16-char hex string from the existing image hash
-- helper (or NULL if hashing failed). Indexed for cross-visit dedupe queries.
--
-- ON DELETE CASCADE from route_visits — if the visit is deleted, the
-- per-visit verification photos go with it. The underlying file is
-- garbage-collected by the existing storage cleanup job.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS route_visit_photos (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    route_visit_id BIGINT UNSIGNED NOT NULL,
    uploaded_by_user_id INT UNSIGNED NULL,
    file_path VARCHAR(255) NOT NULL,
    file_mime VARCHAR(100) NULL,
    file_size_bytes INT UNSIGNED NULL,
    exif_taken_at DATETIME NULL,
    exif_lat DECIMAL(10,7) NULL,
    exif_lng DECIMAL(10,7) NULL,
    perceptual_hash CHAR(16) NULL,
    caption VARCHAR(255) NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_route_visit_photos_visit (route_visit_id),
    INDEX idx_route_visit_photos_uploader (uploaded_by_user_id),
    INDEX idx_route_visit_photos_phash (perceptual_hash),
    CONSTRAINT fk_route_visit_photos_visit FOREIGN KEY (route_visit_id)
        REFERENCES route_visits(id) ON DELETE CASCADE,
    CONSTRAINT fk_route_visit_photos_uploader FOREIGN KEY (uploaded_by_user_id)
        REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
