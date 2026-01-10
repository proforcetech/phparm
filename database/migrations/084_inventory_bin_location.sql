-- Migration: 084_inventory_bin_location.sql
-- Description: Add bin location to inventory items

ALTER TABLE inventory_items
ADD COLUMN IF NOT EXISTS bin_location VARCHAR(160) NULL AFTER location;
