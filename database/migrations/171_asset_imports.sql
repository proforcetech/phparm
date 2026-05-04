-- =============================================================================
-- Migration 171 — Bulk asset import (Phase 18 / S12)
--
-- Two-step import workflow with explicit dry-run gating:
--
--   1) Operator uploads a CSV → we persist the raw bytes' parse into
--      asset_import_rows with status='pending', and a header row in
--      asset_imports tracking total/valid/error counts.
--   2) Operator reviews mapping (column → SiteAsset field), runs validate
--      → status flips to 'validated' and per-row errors get attached.
--   3) Operator hits Apply → we re-run validation against the DB state,
--      INSERT each valid row into site_assets, and write the new
--      site_assets.id into asset_import_rows.created_asset_id.
--
-- Why two tables: keeps the per-row error trail durable for audit (some of
-- these imports are 5k+ rows from a CMMS export — operators need a forensic
-- view of which rows the system rejected and why) without bloating site_assets
-- with import metadata.
-- =============================================================================

CREATE TABLE IF NOT EXISTS asset_imports (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    -- pending  → just uploaded, mapping not finalized
    -- validated → dry-run complete, no DB writes yet
    -- applying → in progress (rows being inserted)
    -- applied  → all eligible rows inserted (error rows logged but skipped)
    -- failed   → fatal error during apply; partial state may exist (see audit)
    -- cancelled → operator dropped before apply
    original_filename VARCHAR(255) NULL,
    -- mapping: { csv_column_name: site_assets_field, ... }
    -- defaults: site_id, division_id, asset_type_id applied to every row when
    -- the CSV column is blank. status default falls back to 'active'.
    mapping JSON NULL,
    default_site_id INT UNSIGNED NULL,
    default_division_id INT UNSIGNED NULL,
    default_asset_type_id INT UNSIGNED NULL,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows INT UNSIGNED NOT NULL DEFAULT 0,
    error_rows INT UNSIGNED NOT NULL DEFAULT 0,
    created_rows INT UNSIGNED NOT NULL DEFAULT 0,
    started_by_user_id INT UNSIGNED NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    validated_at DATETIME NULL,
    applied_at DATETIME NULL,
    notes TEXT NULL,
    INDEX idx_asset_imports_status (status, started_at),
    INDEX idx_asset_imports_user (started_by_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: asset_imports.started_by_user_id -> users(id) SET NULL
SET @users_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'users');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'asset_imports'
    AND constraint_name = 'fk_asset_imports_user');
SET @sql := IF(@users_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE asset_imports ADD CONSTRAINT fk_asset_imports_user FOREIGN KEY (started_by_user_id) REFERENCES users(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: asset_imports.default_site_id -> sites(id) SET NULL
SET @sites_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'sites');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'asset_imports'
    AND constraint_name = 'fk_asset_imports_site');
SET @sql := IF(@sites_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE asset_imports ADD CONSTRAINT fk_asset_imports_site FOREIGN KEY (default_site_id) REFERENCES sites(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: asset_imports.default_asset_type_id -> asset_types(id) SET NULL
SET @types_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'asset_types');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'asset_imports'
    AND constraint_name = 'fk_asset_imports_type');
SET @sql := IF(@types_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE asset_imports ADD CONSTRAINT fk_asset_imports_type FOREIGN KEY (default_asset_type_id) REFERENCES asset_types(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- asset_import_rows — one row per CSV data line
-- -----------------------------------------------------------------------------
-- status flow: pending → validated|invalid → created|invalid (after apply)
CREATE TABLE IF NOT EXISTS asset_import_rows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_id BIGINT UNSIGNED NOT NULL,
    `row_number` INT UNSIGNED NOT NULL,
    -- raw_data is the as-uploaded {csv_column: value} dict so we can re-validate
    -- after the operator changes the mapping without re-uploading the CSV.
    raw_data JSON NOT NULL,
    -- parsed_data is the post-mapping site_assets-shaped payload (only set
    -- after validate). Stored so the apply step writes EXACTLY what the
    -- operator reviewed in the dry-run preview.
    parsed_data JSON NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    error_message VARCHAR(500) NULL,
    created_asset_id INT UNSIGNED NULL,
    INDEX idx_asset_import_rows_import (import_id, `row_number`),
    INDEX idx_asset_import_rows_status (import_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fk: asset_import_rows.import_id -> asset_imports(id) CASCADE
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'asset_import_rows'
    AND constraint_name = 'fk_asset_import_rows_import');
SET @sql := IF(@fk_exists = 0,
    'ALTER TABLE asset_import_rows ADD CONSTRAINT fk_asset_import_rows_import FOREIGN KEY (import_id) REFERENCES asset_imports(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- fk: asset_import_rows.created_asset_id -> site_assets(id) SET NULL
SET @assets_table_exists := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'site_assets');
SET @fk_exists := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'asset_import_rows'
    AND constraint_name = 'fk_asset_import_rows_asset');
SET @sql := IF(@assets_table_exists > 0 AND @fk_exists = 0,
    'ALTER TABLE asset_import_rows ADD CONSTRAINT fk_asset_import_rows_asset FOREIGN KEY (created_asset_id) REFERENCES site_assets(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
