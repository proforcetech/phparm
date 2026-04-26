-- Phase 8.1 of docs/expansion-plan.md: compliance-tagged inspection
-- templates per-division.
--
-- Adds two new tables (compliance tag vocabulary + template<->tag
-- junction) and extends inspection_items with per-item compliance
-- metadata (severity, compliance_tag_id, regulatory reference,
-- photo/measurement obligations, pass condition) so a template can be
-- tagged "DOT annual inspection" at the template level and individual
-- line-items can carry their own regulatory citation for deficiency
-- routing (Phase 8.2) and risk scoring (Phase 8.4).
--
-- All DDL is idempotent: information_schema gates ADD COLUMN and
-- CREATE INDEX so re-running the migration is a no-op. FK installs are
-- guarded on referenced-table existence so pre-0.2 divisions or older
-- inspection schemas don't fail.

-- -----------------------------------------------------------------------------
-- 1. inspection_compliance_tags — tag vocabulary
-- division_id NULL means the tag is global (e.g. "OSHA general industry")
-- and visible to all divisions; non-null means the tag is scoped to a
-- single division (e.g. "auto_repair: state safety inspection")
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_compliance_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL,
    label VARCHAR(160) NOT NULL,
    description TEXT NULL,
    regulatory_body VARCHAR(40) NOT NULL DEFAULT 'other',
    division_id INT UNSIGNED NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_inspection_compliance_tag_code_division (code, division_id),
    INDEX idx_inspection_compliance_tag_division (division_id, is_active),
    INDEX idx_inspection_compliance_tag_regbody (regulatory_body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 2. inspection_template_compliance_tags — many-to-many junction
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inspection_template_compliance_tags (
    template_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (template_id, tag_id),
    INDEX idx_insp_template_compliance_tag_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- 3. extend inspection_items with compliance metadata
-- -----------------------------------------------------------------------------

-- severity (advisory/minor/major/critical) — drives risk scoring in 8.4
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inspection_items' AND column_name = 'severity');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE inspection_items ADD COLUMN severity VARCHAR(16) NULL AFTER display_order',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'inspection_items' AND index_name = 'idx_inspection_items_severity');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE inspection_items ADD INDEX idx_inspection_items_severity (severity)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- compliance_tag_id — optional binding from item to tag (drives
-- per-item regulatory filtering / escalation in 8.3)
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inspection_items' AND column_name = 'compliance_tag_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE inspection_items ADD COLUMN compliance_tag_id INT UNSIGNED NULL AFTER severity',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'inspection_items' AND index_name = 'idx_inspection_items_compliance_tag');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE inspection_items ADD INDEX idx_inspection_items_compliance_tag (compliance_tag_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- compliance_reference — free-form regulatory citation ("49 CFR 396.3")
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inspection_items' AND column_name = 'compliance_reference');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE inspection_items ADD COLUMN compliance_reference VARCHAR(120) NULL AFTER compliance_tag_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- requires_photo — inspector must attach a photo when item fails
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inspection_items' AND column_name = 'requires_photo');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE inspection_items ADD COLUMN requires_photo TINYINT(1) NOT NULL DEFAULT 0 AFTER compliance_reference',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- requires_measurement — inspector must record a numeric measurement
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inspection_items' AND column_name = 'requires_measurement');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE inspection_items ADD COLUMN requires_measurement TINYINT(1) NOT NULL DEFAULT 0 AFTER requires_photo',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- measurement_unit — unit string for required measurements
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inspection_items' AND column_name = 'measurement_unit');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE inspection_items ADD COLUMN measurement_unit VARCHAR(24) NULL AFTER requires_measurement',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- pass_condition — human-readable pass criterion
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inspection_items' AND column_name = 'pass_condition');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE inspection_items ADD COLUMN pass_condition VARCHAR(240) NULL AFTER measurement_unit',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- 4. foreign keys (idempotent, guarded on referenced-table existence)
-- -----------------------------------------------------------------------------

-- fk: inspection_compliance_tags.division_id -> divisions.id SET NULL
SET @div_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'divisions');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_compliance_tags'
    AND constraint_name = 'fk_insp_compliance_tag_division');
SET @sql := IF(@div_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_compliance_tags ADD CONSTRAINT fk_insp_compliance_tag_division FOREIGN KEY (division_id) REFERENCES divisions(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: inspection_template_compliance_tags.template_id -> inspection_templates.id CASCADE
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_template_compliance_tags'
    AND constraint_name = 'fk_insp_template_compliance_tag_template');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE inspection_template_compliance_tags ADD CONSTRAINT fk_insp_template_compliance_tag_template FOREIGN KEY (template_id) REFERENCES inspection_templates(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: inspection_template_compliance_tags.tag_id -> inspection_compliance_tags.id CASCADE
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_template_compliance_tags'
    AND constraint_name = 'fk_insp_template_compliance_tag_tag');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE inspection_template_compliance_tags ADD CONSTRAINT fk_insp_template_compliance_tag_tag FOREIGN KEY (tag_id) REFERENCES inspection_compliance_tags(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: inspection_items.compliance_tag_id -> inspection_compliance_tags.id SET NULL
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_items'
    AND constraint_name = 'fk_insp_item_compliance_tag');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE inspection_items ADD CONSTRAINT fk_insp_item_compliance_tag FOREIGN KEY (compliance_tag_id) REFERENCES inspection_compliance_tags(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
