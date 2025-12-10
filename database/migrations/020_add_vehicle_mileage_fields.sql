-- Migration: Add mileage fields to customer_vehicles table
-- Date: 2025-12-10
-- Description: Adds mileage_in and mileage_out fields to track vehicle mileage when serviced

ALTER TABLE customer_vehicles
ADD COLUMN mileage_in INT UNSIGNED NULL COMMENT 'Mileage when vehicle arrives' AFTER notes,
ADD COLUMN mileage_out INT UNSIGNED NULL COMMENT 'Mileage when vehicle leaves' AFTER mileage_in;
