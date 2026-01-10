-- Migration: 076_inventory_low_stock_cache.sql

-- 1. Cleanup: Remove potential leftovers from previous attempts
DROP TRIGGER IF EXISTS trg_inventory_items_set_low_stock_insert;
DROP TRIGGER IF EXISTS trg_inventory_items_set_low_stock_update;

-- 2. Drop the column if it exists so we can recreate it correctly as a Generated Column
-- (MariaDB 10.11 supports IF EXISTS)
ALTER TABLE inventory_items
DROP COLUMN IF EXISTS is_low_stock;

-- 3. Add the self-calculating column
-- 'STORED' means it saves the result to disk (good for read performance & indexing)
ALTER TABLE inventory_items
ADD COLUMN is_low_stock TINYINT(1)
GENERATED ALWAYS AS (
    CASE
        WHEN COALESCE(is_tracked, 1) = 1 AND COALESCE(stock_quantity, 0) <= COALESCE(low_stock_threshold, 0) THEN 1
        ELSE 0
    END
) STORED AFTER low_stock_threshold;

-- 4. Index the generated column
CREATE INDEX IF NOT EXISTS idx_inventory_is_low_stock ON inventory_items(is_low_stock);
