-- Add discount_type field to bundle_items for flat-rate vs percentage discounts
ALTER TABLE bundle_items ADD COLUMN IF NOT EXISTS discount_type VARCHAR(20) DEFAULT 'fixed' AFTER taxable;

-- Add discount_type field to estimate_items for flat-rate vs percentage discounts
ALTER TABLE estimate_items ADD COLUMN IF NOT EXISTS discount_type VARCHAR(20) DEFAULT 'fixed' AFTER taxable;

-- Add discount_type field to invoice_items for flat-rate vs percentage discounts
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS discount_type VARCHAR(20) DEFAULT 'fixed' AFTER taxable;
