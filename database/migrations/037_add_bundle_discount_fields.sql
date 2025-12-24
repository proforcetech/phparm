ALTER TABLE bundles
    ADD COLUMN internal_notes TEXT NULL AFTER description,
    ADD COLUMN discount_type VARCHAR(20) NULL AFTER internal_notes,
    ADD COLUMN discount_value DECIMAL(12,2) NULL AFTER discount_type;
