-- =============================================================================
-- Move the SubjectResolver "rules" map from PHP into service_lines columns so
-- admins can declare per-line which subject FK column a document must populate
-- (vehicle_id / site_asset_id / none) and whether the column is required —
-- without a code change.
--
-- The trade-off (versus the original "rules in code" choice documented in
-- SubjectResolver::RULES) is intentional: verticals are now adjustable in the
-- CP, at the cost of giving up the compile-time guarantee that a slug always
-- matches a hand-written rule. Validation of allowed column values lives in
-- ServiceLineRepository on update, so a malformed value can't be persisted via
-- the API even though the schema would tolerate it.
--
-- NULL subject_column means "no subject FK is required for this line" —
-- mirrors the old `'commercial_cleaning' => column: null` rule and is also
-- the default for any line that admins haven't configured (so creating a new
-- service line in the CP doesn't silently start rejecting docs).
--
-- IDEMPOTENCY
-- All ALTERs are guarded by information_schema; the seed UPDATEs are gated
-- on subject_column IS NULL so a re-run won't stomp on admin edits.
-- =============================================================================

-- 1) service_lines.subject_column
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'service_lines'
      AND column_name = 'subject_column');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE service_lines ADD COLUMN subject_column VARCHAR(40) NULL AFTER icon',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) service_lines.subject_required
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'service_lines'
      AND column_name = 'subject_required');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE service_lines ADD COLUMN subject_required TINYINT(1) NOT NULL DEFAULT 0 AFTER subject_column',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) service_lines.subject_label
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'service_lines'
      AND column_name = 'subject_label');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE service_lines ADD COLUMN subject_label VARCHAR(60) NULL AFTER subject_required',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) Backfill existing rows from the SubjectResolver::RULES map. Gated on
--    subject_column IS NULL so admin edits made between migration runs are
--    preserved.
UPDATE service_lines SET subject_column = 'vehicle_id',    subject_required = 1, subject_label = 'Vehicle'
    WHERE slug = 'auto_repair'         AND subject_column IS NULL;
UPDATE service_lines SET subject_column = 'vehicle_id',    subject_required = 1, subject_label = 'Vehicle'
    WHERE slug = 'fleet_management'    AND subject_column IS NULL;
UPDATE service_lines SET subject_column = 'site_asset_id', subject_required = 0, subject_label = 'Building / Asset'
    WHERE slug = 'building_repair'     AND subject_column IS NULL;
UPDATE service_lines SET subject_column = 'site_asset_id', subject_required = 1, subject_label = 'Property / Asset'
    WHERE slug = 'property_management' AND subject_column IS NULL;
UPDATE service_lines SET subject_column = 'site_asset_id', subject_required = 1, subject_label = 'Equipment'
    WHERE slug = 'equipment_repair'    AND subject_column IS NULL;
UPDATE service_lines SET subject_column = 'site_asset_id', subject_required = 0, subject_label = 'Device / Asset'
    WHERE slug = 'it_support'          AND subject_column IS NULL;
UPDATE service_lines SET subject_column = 'site_asset_id', subject_required = 0, subject_label = 'System / Asset'
    WHERE slug = 'security_systems'    AND subject_column IS NULL;
UPDATE service_lines SET subject_column = 'site_asset_id', subject_required = 0, subject_label = 'POS Device'
    WHERE slug = 'pos_support'         AND subject_column IS NULL;
-- commercial_cleaning intentionally keeps subject_column = NULL (route-based,
-- not asset-based). subject_required stays 0 from the column default.
