-- Phase 9.2 of docs/expansion-plan.md: Lifecycle scoring model.
--
-- Stores tunable, division-scoped weights + categorization thresholds + an
-- inflation rate used to forward-project replacement estimates. The Phase 2.5
-- AssetLifecycleService becomes parameterized by the active model so each
-- division can express its own "what counts as urgent" without code changes.
--
-- Resolution order at runtime:
--   1) division-specific row, is_default = 1
--   2) division-specific row (any)
--   3) global row (division_id IS NULL), is_default = 1
--   4) global row (any)
--   5) hardcoded fallback (0.5/0.3/0.2 weights, 40/60/80 thresholds, 3.0% infl)
--
-- All DDL idempotent. FK on division_id is guarded on the divisions table
-- existing so this migration is safe to run on a partially-set-up DB.

CREATE TABLE IF NOT EXISTS capital_scoring_models (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    division_id INT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    -- Risk-component weights. Service normalizes to 1.0 at read so a
    -- mis-edited row can't silently inflate scores past 100.
    condition_weight DECIMAL(4,3) NOT NULL DEFAULT 0.500,
    age_weight DECIMAL(4,3) NOT NULL DEFAULT 0.300,
    replace_by_weight DECIMAL(4,3) NOT NULL DEFAULT 0.200,
    -- Lower bounds (inclusive) for each category. urgent overrides whenever
    -- replace_by_date is in the past, regardless of these numbers.
    watch_threshold DECIMAL(5,2) NOT NULL DEFAULT 40.00,
    action_threshold DECIMAL(5,2) NOT NULL DEFAULT 60.00,
    urgent_threshold DECIMAL(5,2) NOT NULL DEFAULT 80.00,
    -- Annual inflation applied to stored replacement_estimate_cents when
    -- forward-projecting capex into a target year. 0.0300 = 3.0%.
    annual_inflation_rate DECIMAL(6,4) NOT NULL DEFAULT 0.0300,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_capital_scoring_models_division (division_id),
    INDEX idx_capital_scoring_models_default (division_id, is_default),
    UNIQUE KEY uq_capital_scoring_models_division_name (division_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: capital_scoring_models.division_id -> divisions(id) SET NULL
SET @div_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'divisions');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'capital_scoring_models'
    AND constraint_name = 'fk_capital_scoring_models_division');
SET @sql := IF(@div_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE capital_scoring_models ADD CONSTRAINT fk_capital_scoring_models_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
