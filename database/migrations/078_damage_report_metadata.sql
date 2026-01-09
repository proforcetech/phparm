-- Migration: Add time and location metadata to job damage reports

ALTER TABLE job_damage_reports
    ADD COLUMN reported_at DATETIME NULL AFTER reported_by,
    ADD COLUMN latitude DECIMAL(10,7) NULL AFTER reported_at,
    ADD COLUMN longitude DECIMAL(10,7) NULL AFTER latitude,
    ADD COLUMN location_accuracy_meters DECIMAL(8,2) NULL AFTER longitude;
