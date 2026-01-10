-- Migration: 083_inventory_search_indexes.sql
-- Description: Add full-text and prefix indexes to accelerate inventory search

ALTER TABLE inventory_items
ADD FULLTEXT INDEX IF NOT EXISTS idx_inventory_search (name, description);

ALTER TABLE inventory_items
ADD INDEX IF NOT EXISTS idx_inventory_sku_prefix (sku(20));
