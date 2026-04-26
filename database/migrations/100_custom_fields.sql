-- Phase 0.5 of docs/expansion-plan.md: custom fields engine scoped by
-- (division_id, entity_type) so each service line can extend core entities
-- (customer, site, workorder, ticket, asset, etc.) without schema churn.
--
-- - custom_field_definitions: describes a field (label, type, options)
-- - custom_field_values: stores the captured values keyed by (entity_type,
--   entity_id, definition_id)
--
-- field_type enum covers the primitives we need from expansion.md: text,
-- number, date, single-select, multi-select, boolean, and asset_ref (for
-- linking to site_assets once Phase 2 lands).

CREATE TABLE IF NOT EXISTS custom_field_definitions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    division_id INT UNSIGNED NULL,
    entity_type VARCHAR(64) NOT NULL,
    field_key VARCHAR(128) NOT NULL,
    label VARCHAR(191) NOT NULL,
    field_type ENUM('text','number','date','select','multiselect','boolean','asset_ref') NOT NULL DEFAULT 'text',
    options_json JSON NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_custom_field_defs_scope (division_id, entity_type, field_key),
    INDEX idx_custom_field_defs_entity (entity_type, is_active, sort_order),
    INDEX idx_custom_field_defs_division (division_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS custom_field_values (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    definition_id INT UNSIGNED NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    value_text TEXT NULL,
    value_number DECIMAL(18,4) NULL,
    value_date DATE NULL,
    value_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_custom_field_values (definition_id, entity_type, entity_id),
    INDEX idx_custom_field_values_entity (entity_type, entity_id),
    INDEX idx_custom_field_values_definition (definition_id),
    CONSTRAINT fk_cfv_definition FOREIGN KEY (definition_id)
        REFERENCES custom_field_definitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
