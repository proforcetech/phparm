-- Add visual metadata for service types
ALTER TABLE service_types
    ADD COLUMN color VARCHAR(7) NULL AFTER alias,
    ADD COLUMN icon VARCHAR(120) NULL AFTER color;
