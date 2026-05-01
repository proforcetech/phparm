-- =============================================================================
-- Phase A.2 of the multi-vertical document plan.
--
-- estimates.vehicle_id and workorders.vehicle_id were originally declared
-- NOT NULL because the only product was automotive repair. With the WOMS
-- expansion (migration 152) the same documents now host non-vehicle work —
-- a property repair WO, an IT support estimate, a security install invoice —
-- so the schema must allow vehicle_id to be NULL.
--
-- WHY THIS IS SAFE
-- The FK to customer_vehicles is preserved. The "vehicle is required for the
-- automotive line" rule moves to the service layer (EstimateService /
-- WorkorderService validate that service_line='auto_repair' rows have a
-- vehicle). This mirrors how invoices.vehicle_id has been NULLABLE all along
-- without breaking automotive flows.
--
-- IDEMPOTENCY
-- Each MODIFY is guarded by an information_schema column-nullability check,
-- so a second run is a no-op. We do not touch the FK; MODIFY COLUMN keeps
-- the existing constraint intact as long as the column type stays the same.
-- =============================================================================

-- estimates.vehicle_id → NULLABLE
SET @is_nullable := (SELECT IS_NULLABLE FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'estimates' AND column_name = 'vehicle_id');
SET @sql := IF(@is_nullable = 'NO',
    'ALTER TABLE estimates MODIFY COLUMN vehicle_id INT UNSIGNED NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- workorders.vehicle_id → NULLABLE
SET @is_nullable := (SELECT IS_NULLABLE FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND column_name = 'vehicle_id');
SET @sql := IF(@is_nullable = 'NO',
    'ALTER TABLE workorders MODIFY COLUMN vehicle_id INT UNSIGNED NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
