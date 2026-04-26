-- Phase 9.3 of docs/expansion-plan.md: Multi-year budget planner.
--
-- Three new tables:
--   1) capital_plans: a snapshot scope + horizon ("HVAC division, FY2026,
--      5-year plan"). One plan per save; a planner clones to iterate.
--   2) capital_plan_scenarios: alternative versions of the same plan
--      ("Baseline", "Defer 12mo", "Accelerate urgent"). Each plan auto-mints
--      a Baseline; users add what-ifs. global_options is a JSON blob of
--      cross-cutting transforms (defer_all_months, accelerate_urgent_to_year, …).
--   3) capital_plan_scenario_overrides: per-asset deviations within a
--      scenario (pin_to_year, defer_months, estimate override, exclusion).
--      Composed on top of global_options at compute time.
--
-- All DDL idempotent. FKs to site_assets / divisions / users are guarded on
-- the referenced table existing so this migration runs cleanly on a
-- partially-set-up DB.

CREATE TABLE IF NOT EXISTS capital_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    scope_type VARCHAR(20) NOT NULL,
    scope_id INT UNSIGNED NULL,
    base_year SMALLINT UNSIGNED NOT NULL,
    horizon_years TINYINT UNSIGNED NOT NULL DEFAULT 5,
    scoring_model_id INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'draft',
    notes TEXT NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_capital_plans_scope (scope_type, scope_id),
    INDEX idx_capital_plans_status (status),
    INDEX idx_capital_plans_created_by (created_by_user_id),
    INDEX idx_capital_plans_scoring_model (scoring_model_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: capital_plans.scoring_model_id -> capital_scoring_models(id) SET NULL
SET @csm_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'capital_scoring_models');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'capital_plans'
    AND constraint_name = 'fk_capital_plans_scoring_model');
SET @sql := IF(@csm_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE capital_plans ADD CONSTRAINT fk_capital_plans_scoring_model FOREIGN KEY (scoring_model_id) REFERENCES capital_scoring_models(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: capital_plans.created_by_user_id -> users(id) SET NULL
SET @users_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'capital_plans'
    AND constraint_name = 'fk_capital_plans_created_by');
SET @sql := IF(@users_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE capital_plans ADD CONSTRAINT fk_capital_plans_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

CREATE TABLE IF NOT EXISTS capital_plan_scenarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    capital_plan_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_baseline TINYINT(1) NOT NULL DEFAULT 0,
    global_options TEXT NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_capital_plan_scenarios_plan (capital_plan_id),
    UNIQUE KEY uq_capital_plan_scenarios_plan_name (capital_plan_id, name),
    CONSTRAINT fk_capital_plan_scenarios_plan FOREIGN KEY (capital_plan_id)
        REFERENCES capital_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS capital_plan_scenario_overrides (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scenario_id INT UNSIGNED NOT NULL,
    site_asset_id INT UNSIGNED NOT NULL,
    defer_months INT NULL,
    pin_to_year SMALLINT UNSIGNED NULL,
    replacement_estimate_cents_override INT UNSIGNED NULL,
    excluded TINYINT(1) NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_overrides_scenario_asset (scenario_id, site_asset_id),
    INDEX idx_overrides_scenario (scenario_id),
    INDEX idx_overrides_asset (site_asset_id),
    CONSTRAINT fk_overrides_scenario FOREIGN KEY (scenario_id)
        REFERENCES capital_plan_scenarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: overrides.site_asset_id -> site_assets(id) CASCADE
SET @sa_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'site_assets');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'capital_plan_scenario_overrides'
    AND constraint_name = 'fk_overrides_site_asset');
SET @sql := IF(@sa_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE capital_plan_scenario_overrides ADD CONSTRAINT fk_overrides_site_asset FOREIGN KEY (site_asset_id) REFERENCES site_assets(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
