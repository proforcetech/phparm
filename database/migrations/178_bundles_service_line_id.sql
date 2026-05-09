-- =============================================================================
-- Bundles ↔ service lines: scope canned jobs to one trade so the bundle
-- picker only shows what's relevant. An auto-repair shop shouldn't see
-- "POS terminal swap" or "office cleaning" in the dropdown when building
-- a brake-job estimate.
--
-- Locked decision: hard FK on bundles, NOT a join table. Bundles are
-- single-trade by nature (a brake-pad bundle isn't reusable across IT
-- support), and the existing bundle picker has nowhere to display
-- multi-trade. Enforce one line per bundle and keep the model boring.
--
-- service_line_id is NULLABLE so existing bundles keep working until an
-- admin assigns them. Picker treats NULL as "shows in every line" rather
-- than hiding them — that way a fresh upgrade doesn't make existing
-- canned jobs disappear from the UI.
--
-- ON DELETE SET NULL: deleting a service line shouldn't drop the bundle
-- definitions. They lose their scope and become global until reassigned.
--
-- Idempotent ALTER pattern from migration 152.
-- =============================================================================

SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'bundles' AND column_name = 'service_line_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE bundles ADD COLUMN service_line_id BIGINT UNSIGNED NULL AFTER service_type_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'bundles' AND index_name = 'idx_bundles_service_line');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE bundles ADD INDEX idx_bundles_service_line (service_line_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'bundles' AND constraint_name = 'fk_bundles_service_line');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE bundles ADD CONSTRAINT fk_bundles_service_line FOREIGN KEY (service_line_id) REFERENCES service_lines(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
