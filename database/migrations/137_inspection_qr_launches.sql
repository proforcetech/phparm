-- Phase 8.5 of docs/expansion-plan.md: QR launch at asset level.
--
-- Two changes in one migration:
--   1) inspection_reports gets a nullable site_asset_id column so an
--      inspection started from an asset's QR sticker carries that
--      anchor through the rest of its lifecycle (and "all inspections
--      for this asset" becomes a single-column index lookup).
--   2) New inspection_qr_launches table acts as a forensic scan/launch
--      audit trail: every QR scan that lands on the launch endpoint
--      (whether or not it produces a report) is persisted with the
--      token, resolved asset, optional template + report linkbacks,
--      actor, source, and a JSON client_meta blob (geo/device/UA).
--
-- All DDL idempotent. FKs guarded on referenced-table existence.

-- 1) inspection_reports.site_asset_id (nullable additive column)
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inspection_reports' AND column_name = 'site_asset_id');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE inspection_reports ADD COLUMN site_asset_id INT UNSIGNED NULL AFTER appointment_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'inspection_reports' AND index_name = 'idx_inspection_report_asset');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE inspection_reports ADD INDEX idx_inspection_report_asset (site_asset_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: inspection_reports.site_asset_id -> site_assets(id) SET NULL
SET @asset_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'site_assets');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_reports'
    AND constraint_name = 'fk_inspection_report_site_asset');
SET @sql := IF(@asset_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_reports ADD CONSTRAINT fk_inspection_report_site_asset FOREIGN KEY (site_asset_id) REFERENCES site_assets(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) inspection_qr_launches: scan/launch forensic audit
CREATE TABLE IF NOT EXISTS inspection_qr_launches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    qr_token VARCHAR(96) NOT NULL,
    site_asset_id INT UNSIGNED NULL,
    inspection_report_id INT UNSIGNED NULL,
    inspection_template_id INT UNSIGNED NULL,
    launched_by_user_id INT UNSIGNED NULL,
    source VARCHAR(24) NOT NULL DEFAULT 'qr',
    status VARCHAR(24) NOT NULL DEFAULT 'preview',
    client_meta TEXT NULL,
    notes VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_insp_qr_launch_asset (site_asset_id, created_at),
    INDEX idx_insp_qr_launch_token (qr_token, created_at),
    INDEX idx_insp_qr_launch_user (launched_by_user_id, created_at),
    INDEX idx_insp_qr_launch_report (inspection_report_id),
    INDEX idx_insp_qr_launch_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: site_asset_id -> site_assets(id) SET NULL
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_qr_launches'
    AND constraint_name = 'fk_insp_qr_launch_asset');
SET @sql := IF(@asset_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_qr_launches ADD CONSTRAINT fk_insp_qr_launch_asset FOREIGN KEY (site_asset_id) REFERENCES site_assets(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: inspection_report_id -> inspection_reports(id) CASCADE
SET @rep_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'inspection_reports');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_qr_launches'
    AND constraint_name = 'fk_insp_qr_launch_report');
SET @sql := IF(@rep_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_qr_launches ADD CONSTRAINT fk_insp_qr_launch_report FOREIGN KEY (inspection_report_id) REFERENCES inspection_reports(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: inspection_template_id -> inspection_templates(id) SET NULL
SET @tpl_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'inspection_templates');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_qr_launches'
    AND constraint_name = 'fk_insp_qr_launch_template');
SET @sql := IF(@tpl_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_qr_launches ADD CONSTRAINT fk_insp_qr_launch_template FOREIGN KEY (inspection_template_id) REFERENCES inspection_templates(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: launched_by_user_id -> users(id) SET NULL
SET @user_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'inspection_qr_launches'
    AND constraint_name = 'fk_insp_qr_launch_user');
SET @sql := IF(@user_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE inspection_qr_launches ADD CONSTRAINT fk_insp_qr_launch_user FOREIGN KEY (launched_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
