ALTER TABLE estimates
    ADD COLUMN is_mobile TINYINT(1) NOT NULL DEFAULT 0 AFTER vehicle_id;

ALTER TABLE invoices
    ADD COLUMN is_mobile TINYINT(1) NOT NULL DEFAULT 0 AFTER vehicle_id;
