-- =============================================================================
-- Migration 168 — Workorder.required_skill_id (Phase 17 / M10)
--
-- Adds an optional required_skill_id column to workorders so the multi-trade
-- dispatch board (Phase 17 / M10) can filter the technician roster down to
-- people who actually hold the matching competency.
--
-- The column is NULLABLE — legacy WOs and any WO that doesn't need a specific
-- skill stay NULL. The dispatch board falls back to "anyone in the WO's
-- service line is a candidate" in that case.
--
-- min_proficiency_level lets a manager say "this WO needs an expert" instead
-- of accepting any holder of the skill. Validated in PHP against
-- App\Models\UserSkill::ALLOWED_PROFICIENCY_LEVELS.
-- =============================================================================

-- workorders.required_skill_id
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND column_name = 'required_skill_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE workorders ADD COLUMN required_skill_id BIGINT UNSIGNED NULL AFTER service_line_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND index_name = 'idx_workorders_required_skill');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE workorders ADD INDEX idx_workorders_required_skill (required_skill_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND constraint_name = 'fk_workorders_required_skill');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE workorders ADD CONSTRAINT fk_workorders_required_skill FOREIGN KEY (required_skill_id) REFERENCES skills(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- workorders.min_proficiency_level
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND column_name = 'min_proficiency_level');
SET @sql := IF(@has_col = 0,
    "ALTER TABLE workorders ADD COLUMN min_proficiency_level VARCHAR(20) NULL AFTER required_skill_id",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
