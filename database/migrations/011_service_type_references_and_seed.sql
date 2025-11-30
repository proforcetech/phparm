-- Add service type references to estimate jobs and invoices
ALTER TABLE estimate_jobs
    ADD COLUMN service_type_id INT NULL AFTER estimate_id,
    ADD INDEX idx_estimate_jobs_service_type (service_type_id);

ALTER TABLE invoices
    ADD COLUMN service_type_id INT NULL AFTER customer_id,
    ADD INDEX idx_invoices_service_type (service_type_id);

ALTER TABLE estimate_jobs
    ADD CONSTRAINT fk_estimate_jobs_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id);

ALTER TABLE invoices
    ADD CONSTRAINT fk_invoices_service_type FOREIGN KEY (service_type_id) REFERENCES service_types (id);

-- Seed a general service type for backfilling existing records
INSERT INTO service_types (name, alias, description, active, display_order)
VALUES ('General Service', 'general', 'Default bucket for records without an assigned service type', 1, 1)
ON DUPLICATE KEY UPDATE display_order = VALUES(display_order), active = 1;

-- Backfill existing estimate jobs and invoices to ensure they have service type references
SET @general_service_type_id := (SELECT id FROM service_types WHERE alias = 'general' LIMIT 1);
UPDATE estimate_jobs SET service_type_id = @general_service_type_id WHERE service_type_id IS NULL;
UPDATE invoices SET service_type_id = @general_service_type_id WHERE service_type_id IS NULL;
