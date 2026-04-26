-- Phase 5.1 of docs/expansion-plan.md: Preventative Maintenance.
--
-- A pm_plan is a named template ("Quarterly HVAC inspection", "Annual roof
-- survey"). A pm_schedule is an instance of that plan applied to a specific
-- (site, asset) target with a cadence. The cron (Phase 5.3) scans schedules
-- whose next_due_at - lead_time_days <= today and spawns a ticket or
-- workorder from the plan template.
--
-- Split rationale:
--   * plans are stable templates edited by managers
--   * schedules change frequently (next_due_at, last_generated_at, status)
--   * keeping them separate avoids churning the template every time the
--     cron advances a schedule
--
-- `contract_id` + `contract_entitlement_id` are forward-looking for Phase
-- 5.5 (contract entitlement linkage). Introducing them here means the 5.5
-- migration only needs an idempotent guard, not a schema change.

CREATE TABLE IF NOT EXISTS pm_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NULL,
    division_id INT UNSIGNED NULL,
    title VARCHAR(191) NOT NULL,
    description TEXT NULL,
    default_priority VARCHAR(30) NOT NULL DEFAULT 'p3_normal',
    estimated_duration_minutes INT UNSIGNED NULL,
    checklist_json JSON NULL,
    default_category_id INT UNSIGNED NULL,
    default_queue_id INT UNSIGNED NULL,
    default_assigned_user_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pm_plans_company (company_id),
    INDEX idx_pm_plans_division (division_id),
    INDEX idx_pm_plans_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pm_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id INT UNSIGNED NOT NULL,
    company_id INT UNSIGNED NOT NULL,
    site_id INT UNSIGNED NULL,
    asset_id INT UNSIGNED NULL,
    -- Frequency engine: kind drives how next_due_at is computed (Phase 5.2).
    --   fixed_interval — starts_at + N days/weeks/months/years
    --   calendar       — nth weekday of month, or specific day-of-month
    --   meter          — increments based on asset hours/miles (reads meter)
    --   condition      — user-supplied trigger (e.g., condition_score < 60)
    frequency_kind VARCHAR(30) NOT NULL DEFAULT 'fixed_interval',
    frequency_config JSON NULL,
    starts_at DATE NOT NULL,
    next_due_at DATE NULL,
    last_generated_at DATETIME NULL,
    lead_time_days INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    -- Forward-looking for Phase 5.5 (contract entitlement linkage).
    contract_id INT UNSIGNED NULL,
    contract_entitlement_id INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pm_schedules_plan (plan_id),
    INDEX idx_pm_schedules_company (company_id),
    INDEX idx_pm_schedules_site (site_id),
    INDEX idx_pm_schedules_asset (asset_id),
    INDEX idx_pm_schedules_due (next_due_at),
    INDEX idx_pm_schedules_status (status),
    INDEX idx_pm_schedules_contract (contract_id),
    CONSTRAINT fk_pm_schedules_plan FOREIGN KEY (plan_id)
        REFERENCES pm_plans(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
