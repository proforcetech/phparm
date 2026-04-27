-- Phase 10.6 of docs/expansion-plan.md — route optimization + geo-fencing.
--
-- Four tables shipped together because they form one logical surface:
--
--   geo_fences         spatial regions (circle or polygon) representing
--                      shop zones, customer sites, service zones, or
--                      restricted zones. Used by mobile clients posting
--                      position updates to determine fence membership.
--
--   geo_fence_events   append-only log of (user, fence, entered/exited,
--                      timestamp) tuples. Drives auto-clock-in workflows,
--                      "tech left site mid-job" alerts, and after-the-fact
--                      "show me where Carol's truck went today" reports.
--
--   route_plans        a planned day of stops for a single technician.
--                      Carries optimization metadata (method, computed
--                      distance/duration, optimized_at) so a re-optimization
--                      can be triggered without losing the prior plan's
--                      audit trail. Lifecycle: draft → active → completed.
--
--   route_plan_stops   ordered stops within a route plan. Each stop ties
--                      to a workorder + appointment when known and carries
--                      planned-vs-actual arrival/departure times for after-
--                      action analysis ("we estimated 3 hours but it took
--                      4.5 — re-tune service_minutes_planned").
--
-- All FKs guarded on referenced-table existence so the migration is safe
-- on partially-set-up databases. Latitudes use DECIMAL(10,7) — 7 decimal
-- places of precision is ~1cm at the equator, plenty for routing.

-- ─────────────────────────────────────────────── geo_fences ────

CREATE TABLE IF NOT EXISTS geo_fences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    shape_type VARCHAR(20) NOT NULL DEFAULT 'circle',
    center_latitude DECIMAL(10,7) NULL,
    center_longitude DECIMAL(10,7) NULL,
    radius_meters INT NULL,
    polygon_geojson TEXT NULL,
    purpose VARCHAR(40) NOT NULL DEFAULT 'service_zone',
    customer_id INT UNSIGNED NULL,
    workorder_id INT UNSIGNED NULL,
    asset_id INT UNSIGNED NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- "Show me all active service-zone fences" — picker for assigning fences
-- to a route plan.
SET @idx_purpose := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'geo_fences'
      AND index_name = 'idx_gf_purpose');
SET @sql := IF(@idx_purpose = 0,
    'CREATE INDEX idx_gf_purpose ON geo_fences (purpose)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_customer := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'geo_fences'
      AND index_name = 'idx_gf_customer');
SET @sql := IF(@idx_customer = 0,
    'CREATE INDEX idx_gf_customer ON geo_fences (customer_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_active := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'geo_fences'
      AND index_name = 'idx_gf_active');
SET @sql := IF(@idx_active = 0,
    'CREATE INDEX idx_gf_active ON geo_fences (active)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to customers — SET NULL because the fence outlives the customer
-- relationship for historical analysis.
SET @cust_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'customers');
SET @fk_gf_cust := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'geo_fences'
      AND constraint_name = 'fk_gf_customer');
SET @sql := IF(@cust_table > 0 AND @fk_gf_cust = 0,
    'ALTER TABLE geo_fences ADD CONSTRAINT fk_gf_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to workorders — SET NULL same reasoning.
SET @wo_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'workorders');
SET @fk_gf_wo := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'geo_fences'
      AND constraint_name = 'fk_gf_workorder');
SET @sql := IF(@wo_table > 0 AND @fk_gf_wo = 0,
    'ALTER TABLE geo_fences ADD CONSTRAINT fk_gf_workorder
        FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ───────────────────────────────────────── geo_fence_events ────

CREATE TABLE IF NOT EXISTS geo_fence_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    geo_fence_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    workorder_id INT UNSIGNED NULL,
    event_type VARCHAR(20) NOT NULL DEFAULT 'entered',
    occurred_at DATETIME NOT NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    accuracy_meters INT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'mobile_gps',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

SET @idx_evt_fence := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'geo_fence_events'
      AND index_name = 'idx_gfe_fence');
SET @sql := IF(@idx_evt_fence = 0,
    'CREATE INDEX idx_gfe_fence ON geo_fence_events (geo_fence_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_evt_user := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'geo_fence_events'
      AND index_name = 'idx_gfe_user');
SET @sql := IF(@idx_evt_user = 0,
    'CREATE INDEX idx_gfe_user ON geo_fence_events (user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_evt_occurred := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'geo_fence_events'
      AND index_name = 'idx_gfe_occurred');
SET @sql := IF(@idx_evt_occurred = 0,
    'CREATE INDEX idx_gfe_occurred ON geo_fence_events (occurred_at)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to geo_fences — CASCADE because deleting a fence sweeps its event log.
SET @fk_evt_fence := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'geo_fence_events'
      AND constraint_name = 'fk_gfe_fence');
SET @sql := IF(@fk_evt_fence = 0,
    'ALTER TABLE geo_fence_events ADD CONSTRAINT fk_gfe_fence
        FOREIGN KEY (geo_fence_id) REFERENCES geo_fences(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users — RESTRICT because the user is the audit trail; deleting
-- them needs to fail loudly.
SET @users_table := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_evt_user := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'geo_fence_events'
      AND constraint_name = 'fk_gfe_user');
SET @sql := IF(@users_table > 0 AND @fk_evt_user = 0,
    'ALTER TABLE geo_fence_events ADD CONSTRAINT fk_gfe_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ────────────────────────────────────────────── route_plans ────

CREATE TABLE IF NOT EXISTS route_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    planned_for_user_id INT UNSIGNED NOT NULL,
    plan_date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    origin_latitude DECIMAL(10,7) NULL,
    origin_longitude DECIMAL(10,7) NULL,
    origin_label VARCHAR(180) NULL,
    return_to_origin TINYINT(1) NOT NULL DEFAULT 0,
    optimization_method VARCHAR(40) NOT NULL DEFAULT 'nearest_neighbor',
    total_distance_meters INT NULL,
    total_duration_minutes INT NULL,
    optimized_at DATETIME NULL,
    activated_at DATETIME NULL,
    completed_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_by_user_id INT UNSIGNED NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

SET @idx_rp_user := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'route_plans'
      AND index_name = 'idx_rp_user');
SET @sql := IF(@idx_rp_user = 0,
    'CREATE INDEX idx_rp_user ON route_plans (planned_for_user_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- "Show me Carol's plan for today" hot read pattern — composite index
-- covers the (user, date) lookup that the dispatch board fires per page.
SET @idx_rp_user_date := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'route_plans'
      AND index_name = 'idx_rp_user_date');
SET @sql := IF(@idx_rp_user_date = 0,
    'CREATE INDEX idx_rp_user_date ON route_plans (planned_for_user_id, plan_date)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_rp_status := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'route_plans'
      AND index_name = 'idx_rp_status');
SET @sql := IF(@idx_rp_status = 0,
    'CREATE INDEX idx_rp_status ON route_plans (status)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to users — RESTRICT on planned_for (deleting a tech mid-day must
-- fail loudly so dispatch can reassign their plan), SET NULL on creator.
SET @fk_rp_user := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'route_plans'
      AND constraint_name = 'fk_rp_user');
SET @sql := IF(@users_table > 0 AND @fk_rp_user = 0,
    'ALTER TABLE route_plans ADD CONSTRAINT fk_rp_user
        FOREIGN KEY (planned_for_user_id) REFERENCES users(id) ON DELETE RESTRICT',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @fk_rp_creator := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'route_plans'
      AND constraint_name = 'fk_rp_creator');
SET @sql := IF(@users_table > 0 AND @fk_rp_creator = 0,
    'ALTER TABLE route_plans ADD CONSTRAINT fk_rp_creator
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- ───────────────────────────────────────── route_plan_stops ────

CREATE TABLE IF NOT EXISTS route_plan_stops (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    route_plan_id INT UNSIGNED NOT NULL,
    sequence_order INT NOT NULL,
    workorder_id INT UNSIGNED NULL,
    appointment_id INT UNSIGNED NULL,
    stop_label VARCHAR(180) NOT NULL,
    latitude DECIMAL(10,7) NOT NULL,
    longitude DECIMAL(10,7) NOT NULL,
    estimated_arrival_at DATETIME NULL,
    estimated_departure_at DATETIME NULL,
    service_minutes_planned INT NULL,
    arrived_at DATETIME NULL,
    departed_at DATETIME NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'planned',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

SET @idx_st_plan := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'route_plan_stops'
      AND index_name = 'idx_st_plan');
SET @sql := IF(@idx_st_plan = 0,
    'CREATE INDEX idx_st_plan ON route_plan_stops (route_plan_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Composite (plan, sequence) covers the "render the plan in order" read
-- pattern that fires for every plan view.
SET @idx_st_plan_seq := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'route_plan_stops'
      AND index_name = 'idx_st_plan_seq');
SET @sql := IF(@idx_st_plan_seq = 0,
    'CREATE INDEX idx_st_plan_seq ON route_plan_stops (route_plan_id, sequence_order)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_st_status := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'route_plan_stops'
      AND index_name = 'idx_st_status');
SET @sql := IF(@idx_st_status = 0,
    'CREATE INDEX idx_st_status ON route_plan_stops (status)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to route_plans — CASCADE so deleting a plan sweeps its stops.
SET @fk_st_plan := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'route_plan_stops'
      AND constraint_name = 'fk_st_plan');
SET @sql := IF(@fk_st_plan = 0,
    'ALTER TABLE route_plan_stops ADD CONSTRAINT fk_st_plan
        FOREIGN KEY (route_plan_id) REFERENCES route_plans(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK to workorders — SET NULL so a deleted WO doesn't blow away the
-- stop's historical record.
SET @fk_st_wo := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'route_plan_stops'
      AND constraint_name = 'fk_st_workorder');
SET @sql := IF(@wo_table > 0 AND @fk_st_wo = 0,
    'ALTER TABLE route_plan_stops ADD CONSTRAINT fk_st_workorder
        FOREIGN KEY (workorder_id) REFERENCES workorders(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
