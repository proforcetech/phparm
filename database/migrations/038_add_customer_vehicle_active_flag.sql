ALTER TABLE customer_vehicles
  ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER notes;
