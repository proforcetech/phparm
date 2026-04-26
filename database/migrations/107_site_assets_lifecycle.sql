-- Phase 2.5 of docs/expansion-plan.md: asset condition + lifecycle scoring.
--
-- Columns:
--   * condition_score           0-100, higher = healthier. NULL = never scored.
--   * expected_life_years       straight-line estimate from install_date.
--   * replacement_estimate_cents planning input for capex reports.
--   * last_inspected_at         DATETIME, updated when inspections close.
--   * replace_by_date           computed or hand-set; used by lifecycle sort.
--
-- We keep condition_score and replace_by_date separate intentionally: one
-- reflects "how it's doing right now" (from inspections), the other
-- "when we expect to retire it" (business/planning). They may disagree —
-- that disagreement is the whole point of the lifecycle dashboard.

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND column_name = 'condition_score');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE site_assets
        ADD COLUMN condition_score TINYINT UNSIGNED NULL AFTER vlan,
        ADD COLUMN expected_life_years DECIMAL(5,2) UNSIGNED NULL AFTER condition_score,
        ADD COLUMN replacement_estimate_cents INT UNSIGNED NULL AFTER expected_life_years,
        ADD COLUMN last_inspected_at DATETIME NULL AFTER replacement_estimate_cents,
        ADD COLUMN replace_by_date DATE NULL AFTER last_inspected_at',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND index_name = 'idx_site_assets_replace_by');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE site_assets ADD INDEX idx_site_assets_replace_by (replace_by_date)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND index_name = 'idx_site_assets_condition');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE site_assets ADD INDEX idx_site_assets_condition (condition_score)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
