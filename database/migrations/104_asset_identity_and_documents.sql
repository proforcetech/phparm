-- Phase 2.2 of docs/expansion-plan.md: asset identity + documents.
--
-- Adds manufacturer/model/serial/vendor/warranty/purchase fields directly to
-- site_assets (they're universal enough that a dedicated "identity" table
-- would just double every read). Freeform metadata goes into custom_fields
-- JSON so divisions can tag assets without schema churn.
--
-- Documents attach polymorphically-via-asset (not polymorphic to other
-- record types — assets already link outward via asset_links).

-- 1. site_assets additive columns
SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND column_name = 'manufacturer');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE site_assets
        ADD COLUMN manufacturer VARCHAR(120) NULL AFTER notes,
        ADD COLUMN model_number VARCHAR(120) NULL AFTER manufacturer,
        ADD COLUMN serial_number VARCHAR(160) NULL AFTER model_number,
        ADD COLUMN vendor VARCHAR(120) NULL AFTER serial_number,
        ADD COLUMN warranty_start DATE NULL AFTER vendor,
        ADD COLUMN warranty_end DATE NULL AFTER warranty_start,
        ADD COLUMN purchase_cents INT UNSIGNED NULL AFTER warranty_end,
        ADD COLUMN custom_fields JSON NULL AFTER purchase_cents',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND index_name = 'idx_site_assets_serial');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE site_assets ADD INDEX idx_site_assets_serial (serial_number)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND index_name = 'idx_site_assets_warranty_end');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE site_assets ADD INDEX idx_site_assets_warranty_end (warranty_end)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. asset_documents table
CREATE TABLE IF NOT EXISTS asset_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    doc_type VARCHAR(40) NOT NULL DEFAULT 'other',
    filename VARCHAR(255) NOT NULL,
    original_filename VARCHAR(255) NULL,
    mime_type VARCHAR(120) NULL,
    size_bytes INT UNSIGNED NULL,
    storage_path VARCHAR(500) NOT NULL,
    storage_disk VARCHAR(40) NULL,
    notes TEXT NULL,
    uploaded_by INT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_asset_documents_asset (asset_id),
    INDEX idx_asset_documents_type (doc_type),
    CONSTRAINT fk_asset_documents_asset FOREIGN KEY (asset_id)
        REFERENCES site_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
