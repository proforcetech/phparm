-- Migration: 076_inventory_low_stock_cache.sql
-- Description: Add cached low stock flag and triggers for inventory alerts

ALTER TABLE inventory_items
ADD COLUMN IF NOT EXISTS is_low_stock TINYINT(1) NOT NULL DEFAULT 0 AFTER low_stock_threshold;

CREATE INDEX IF NOT EXISTS idx_inventory_is_low_stock ON inventory_items(is_low_stock);

UPDATE inventory_items
SET is_low_stock = CASE
    WHEN COALESCE(is_tracked, 1) = 1 AND COALESCE(stock_quantity, 0) <= COALESCE(low_stock_threshold, 0) THEN 1
    ELSE 0
END;

DROP TRIGGER IF EXISTS trg_inventory_items_set_low_stock_insert;
DROP TRIGGER IF EXISTS trg_inventory_items_set_low_stock_update;

CREATE TRIGGER trg_inventory_items_set_low_stock_insert
BEFORE INSERT ON inventory_items
FOR EACH ROW
BEGIN
    SET NEW.is_low_stock = CASE
        WHEN COALESCE(NEW.is_tracked, 1) = 1 AND COALESCE(NEW.stock_quantity, 0) <= COALESCE(NEW.low_stock_threshold, 0) THEN 1
        ELSE 0
    END;
END;

CREATE TRIGGER trg_inventory_items_set_low_stock_update
BEFORE UPDATE ON inventory_items
FOR EACH ROW
BEGIN
    SET NEW.is_low_stock = CASE
        WHEN COALESCE(NEW.is_tracked, 1) = 1 AND COALESCE(NEW.stock_quantity, 0) <= COALESCE(NEW.low_stock_threshold, 0) THEN 1
        ELSE 0
    END;
END;
