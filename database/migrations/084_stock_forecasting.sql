-- Add inventory stock forecasting overrides and history

SET @has_reorder_point_override := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND column_name = 'reorder_point_override'
);
SET @reorder_point_override_sql := IF(@has_reorder_point_override = 0,
    'ALTER TABLE inventory_items ADD COLUMN reorder_point_override INT NULL AFTER reorder_quantity',
    'SELECT 1'
);
PREPARE reorder_point_override_stmt FROM @reorder_point_override_sql;
EXECUTE reorder_point_override_stmt;
DEALLOCATE PREPARE reorder_point_override_stmt;

SET @has_reorder_point_override_reason := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND column_name = 'reorder_point_override_reason'
);
SET @reorder_point_override_reason_sql := IF(@has_reorder_point_override_reason = 0,
    'ALTER TABLE inventory_items ADD COLUMN reorder_point_override_reason VARCHAR(255) NULL AFTER reorder_point_override',
    'SELECT 1'
);
PREPARE reorder_point_override_reason_stmt FROM @reorder_point_override_reason_sql;
EXECUTE reorder_point_override_reason_stmt;
DEALLOCATE PREPARE reorder_point_override_reason_stmt;

SET @has_reorder_point_override_updated_at := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND column_name = 'reorder_point_override_updated_at'
);
SET @reorder_point_override_updated_at_sql := IF(@has_reorder_point_override_updated_at = 0,
    'ALTER TABLE inventory_items ADD COLUMN reorder_point_override_updated_at TIMESTAMP NULL AFTER reorder_point_override_reason',
    'SELECT 1'
);
PREPARE reorder_point_override_updated_at_stmt FROM @reorder_point_override_updated_at_sql;
EXECUTE reorder_point_override_updated_at_stmt;
DEALLOCATE PREPARE reorder_point_override_updated_at_stmt;

SET @has_reorder_point_override_updated_by := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND column_name = 'reorder_point_override_updated_by'
);
SET @reorder_point_override_updated_by_sql := IF(@has_reorder_point_override_updated_by = 0,
    'ALTER TABLE inventory_items ADD COLUMN reorder_point_override_updated_by INT UNSIGNED NULL AFTER reorder_point_override_updated_at',
    'SELECT 1'
);
PREPARE reorder_point_override_updated_by_stmt FROM @reorder_point_override_updated_by_sql;
EXECUTE reorder_point_override_updated_by_stmt;
DEALLOCATE PREPARE reorder_point_override_updated_by_stmt;

SET @has_reorder_point_override_updated_by_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'inventory_items' AND index_name = 'idx_inventory_reorder_override_user'
);
SET @reorder_point_override_updated_by_index_sql := IF(@has_reorder_point_override_updated_by_index = 0,
    'CREATE INDEX idx_inventory_reorder_override_user ON inventory_items(reorder_point_override_updated_by)',
    'SELECT 1'
);
PREPARE reorder_point_override_updated_by_index_stmt FROM @reorder_point_override_updated_by_index_sql;
EXECUTE reorder_point_override_updated_by_index_stmt;
DEALLOCATE PREPARE reorder_point_override_updated_by_index_stmt;

CREATE TABLE IF NOT EXISTS inventory_reorder_point_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    previous_override INT NULL,
    new_override INT NULL,
    reason VARCHAR(255) NULL,
    changed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_reorder_history_item (inventory_item_id),
    INDEX idx_inventory_reorder_history_user (changed_by),
    CONSTRAINT fk_inventory_reorder_history_item FOREIGN KEY (inventory_item_id)
        REFERENCES inventory_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_reorder_history_user FOREIGN KEY (changed_by)
        REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
