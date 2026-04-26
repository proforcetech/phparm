-- Phase 2.1 of docs/expansion-plan.md: installed-asset model.
--
--   asset_types     — shared catalog of asset categories (HVAC, RTU, pump, etc)
--   site_assets     — a physical/logical asset installed at a site.
--                     Self-referential parent_asset_id lets callers model a
--                     tree (e.g. a chiller contains compressors contains
--                     motors) so inspections/WOs can attach at any level.
--   asset_links     — polymorphic join from an asset to any downstream record
--                     (workorder, inspection, contract, ticket, estimate,
--                     invoice). Lets later phases (3/4/5/8) point at an asset
--                     without schema changes on those tables.

-- 1. asset_types
CREATE TABLE IF NOT EXISTS asset_types (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    division_id INT UNSIGNED NULL,
    parent_id INT UNSIGNED NULL,
    code VARCHAR(80) NOT NULL,
    name VARCHAR(191) NOT NULL,
    description TEXT NULL,
    icon VARCHAR(80) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_asset_types_division (division_id),
    INDEX idx_asset_types_parent (parent_id),
    UNIQUE KEY uq_asset_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. site_assets
CREATE TABLE IF NOT EXISTS site_assets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    division_id INT UNSIGNED NULL,
    asset_type_id INT UNSIGNED NULL,
    parent_asset_id INT UNSIGNED NULL,
    name VARCHAR(191) NOT NULL,
    code VARCHAR(120) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    install_date DATE NULL,
    decommissioned_at DATETIME NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_site_assets_site (site_id),
    INDEX idx_site_assets_division (division_id),
    INDEX idx_site_assets_type (asset_type_id),
    INDEX idx_site_assets_parent (parent_asset_id),
    INDEX idx_site_assets_status (status),
    UNIQUE KEY uq_site_assets_site_code (site_id, code),
    CONSTRAINT fk_site_assets_site FOREIGN KEY (site_id)
        REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_site_assets_parent FOREIGN KEY (parent_asset_id)
        REFERENCES site_assets(id) ON DELETE SET NULL,
    CONSTRAINT fk_site_assets_type FOREIGN KEY (asset_type_id)
        REFERENCES asset_types(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. asset_links (polymorphic downstream references)
--    related_type is constrained at the application layer to the known set
--    ('workorder','inspection','contract','ticket','estimate','invoice') so
--    new phases can add link kinds without schema churn.
CREATE TABLE IF NOT EXISTS asset_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    asset_id INT UNSIGNED NOT NULL,
    related_type VARCHAR(40) NOT NULL,
    related_id INT UNSIGNED NOT NULL,
    relation VARCHAR(40) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by INT UNSIGNED NULL,
    INDEX idx_asset_links_asset (asset_id),
    INDEX idx_asset_links_related (related_type, related_id),
    UNIQUE KEY uq_asset_links (asset_id, related_type, related_id, relation),
    CONSTRAINT fk_asset_links_asset FOREIGN KEY (asset_id)
        REFERENCES site_assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
