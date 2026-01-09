-- Add job category + equipment requirements to dispatch requirements

ALTER TABLE dispatch_requirements
    ADD COLUMN job_category VARCHAR(60) NULL AFTER dispatch_reference,
    ADD COLUMN equipment_requirements JSON NULL AFTER required_equipment_class;
