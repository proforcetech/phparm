-- Repair workorder item SKU/inventory columns on databases where migration 045
-- did not add them. The service writes these fields for estimate conversions
-- and direct workorders, so keep this migration idempotent.

SET @has_workorder_item_sku := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_items'
      AND column_name = 'sku'
);
SET @workorder_item_sku_sql := IF(@has_workorder_item_sku = 0,
    'ALTER TABLE workorder_items ADD COLUMN sku VARCHAR(120) NULL AFTER type',
    'SELECT 1');
PREPARE stmt FROM @workorder_item_sku_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_workorder_item_inventory_item_id := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_items'
      AND column_name = 'inventory_item_id'
);
SET @workorder_item_inventory_item_id_sql := IF(@has_workorder_item_inventory_item_id = 0,
    'ALTER TABLE workorder_items ADD COLUMN inventory_item_id INT UNSIGNED NULL AFTER sku',
    'SELECT 1');
PREPARE stmt FROM @workorder_item_inventory_item_id_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_workorder_item_sku_index := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_items'
      AND index_name = 'idx_workorder_item_sku'
);
SET @workorder_item_sku_index_sql := IF(@has_workorder_item_sku_index = 0,
    'ALTER TABLE workorder_items ADD INDEX idx_workorder_item_sku (sku)',
    'SELECT 1');
PREPARE stmt FROM @workorder_item_sku_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @has_workorder_item_inventory_index := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'workorder_items'
      AND index_name = 'idx_workorder_item_inventory'
);
SET @workorder_item_inventory_index_sql := IF(@has_workorder_item_inventory_index = 0,
    'ALTER TABLE workorder_items ADD INDEX idx_workorder_item_inventory (inventory_item_id)',
    'SELECT 1');
PREPARE stmt FROM @workorder_item_inventory_index_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
