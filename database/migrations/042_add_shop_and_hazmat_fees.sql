-- Add shop fee and hazardous disposal fee to estimates
ALTER TABLE estimates
    ADD COLUMN shop_fee DECIMAL(12,2) DEFAULT 0 AFTER discounts,
    ADD COLUMN hazmat_disposal_fee DECIMAL(12,2) DEFAULT 0 AFTER shop_fee;

-- Add shop fee and hazardous disposal fee to invoices
ALTER TABLE invoices
    ADD COLUMN shop_fee DECIMAL(12,2) DEFAULT 0 AFTER balance_due,
    ADD COLUMN hazmat_disposal_fee DECIMAL(12,2) DEFAULT 0 AFTER shop_fee;
