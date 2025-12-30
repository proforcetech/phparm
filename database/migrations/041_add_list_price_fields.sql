-- Add list_price to estimate_items
ALTER TABLE estimate_items ADD COLUMN list_price DECIMAL(12,2) DEFAULT 0 AFTER unit_price;

-- Add list_price to invoice_items
ALTER TABLE invoice_items ADD COLUMN list_price DECIMAL(12,2) DEFAULT 0 AFTER unit_price;

-- Add list_price to bundle_items
ALTER TABLE bundle_items ADD COLUMN list_price DECIMAL(12,2) DEFAULT 0 AFTER unit_price;

-- Add list_price to inventory_items
ALTER TABLE inventory_items ADD COLUMN list_price DECIMAL(12,2) DEFAULT 0 AFTER sale_price;
