-- =============================================================================
-- Phase 11 of docs/woms-expansion-plan.md: Service Line Configurability (M1, M2, M12)
--
-- WHAT THIS MIGRATION DOES
-- -----------------------------------------------------------------------------
-- 1. Creates the `service_lines` lookup table — the trade taxonomy that lets
--    one codebase serve nine distinct verticals (auto repair, building repair,
--    property mgmt, equipment repair, fleet, IT, security, POS, cleaning).
-- 2. Seeds the nine launch service lines with stable slugs, display names,
--    sidebar icons, and a sort order.
-- 3. Adds a NULLABLE `service_line_id` FK + index to the five core entity
--    tables that the WOMS expansion needs to filter/route by trade:
--    `workorders`, `tickets`, `contracts`, `site_assets`, `labor_tasks`.
-- 4. Adds a `type` column to `workorders` so the application can drive the
--    trade-aware WO type vocabulary (corrective / preventive / inspection /
--    install / project / recurring_visit / change_request). Validation of
--    allowed values lives in PHP (Workorder::ALLOWED_TYPES) — no CHECK
--    constraint here so adding new types stays a code change, not a schema
--    migration.
-- 5. Creates the `user_service_lines` join table for many-to-many membership
--    (a single technician can work across multiple lines), and adds a
--    `primary_service_line_id` to `users` for the user's home/default line.
-- 6. Backfills every existing row of those five tables to the `auto_repair`
--    line, and assigns the `auto_repair` primary line + membership to every
--    user whose role is in (admin, manager, technician). Customer/cms/etc.
--    roles intentionally do NOT get a primary line — they don't dispatch WOs.
--
-- WHY service_lines IS A SEPARATE DIMENSION FROM divisions
-- -----------------------------------------------------------------------------
-- `divisions` (migration 099) is an ORG-SHAPE dimension: a tenant/business unit
-- with its own users, billing, and reports. `service_lines` is a TRADE-SHAPE
-- dimension: what kind of work is being performed. A single division (e.g.
-- "North Region Operations") can perform work across multiple service lines
-- (auto repair AND building repair AND IT support), and a single service line
-- (e.g. IT Support) can be performed by multiple divisions. The two are
-- orthogonal and intentionally kept on separate FKs.
--
-- IDEMPOTENCY & SAFETY
-- -----------------------------------------------------------------------------
-- This migration is fully idempotent and additive. Every CREATE uses
-- IF NOT EXISTS; every ALTER is guarded by an information_schema check
-- (mirrors the prepared-statement pattern from migration 099); every seed
-- INSERT uses INSERT IGNORE; every backfill UPDATE has a WHERE NULL guard.
-- A second run is a no-op. There are no DROP statements, no destructive
-- ALTERs, and no data deletions.
--
-- ROLLBACK NOTE (manual; we deliberately do NOT auto-rollback in production)
-- -----------------------------------------------------------------------------
-- 1. Drop FKs:    ALTER TABLE <t> DROP FOREIGN KEY fk_<t>_service_line;
--                 ALTER TABLE users DROP FOREIGN KEY fk_users_primary_service_line;
-- 2. Drop indexes: ALTER TABLE <t> DROP INDEX idx_<t>_service_line;
--                  ALTER TABLE workorders DROP INDEX idx_workorders_type;
--                  ALTER TABLE users DROP INDEX idx_users_primary_service_line;
-- 3. Drop columns: ALTER TABLE <t> DROP COLUMN service_line_id;
--                  ALTER TABLE workorders DROP COLUMN type;
--                  ALTER TABLE users DROP COLUMN primary_service_line_id;
-- 4. Drop join table: DROP TABLE user_service_lines;
-- 5. Drop lookup:     DROP TABLE service_lines;
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PART A: service_lines lookup table
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS service_lines (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    icon VARCHAR(60) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_service_lines_active (is_active),
    INDEX idx_service_lines_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- PART B: seed the nine launch service lines
-- Descriptions paraphrase the "Positioning" section of woms-service-lines.md.
-- Slugs are stable identifiers — never rename in place; deprecate-and-add only.
-- -----------------------------------------------------------------------------
INSERT IGNORE INTO service_lines (slug, name, description, icon, sort_order, is_active) VALUES
    ('auto_repair',          'Auto Repair / Roadside / Towing',          'Independent shops and mobile mechanics serving consumer and small-fleet vehicles; the original product, sold per-shop on monthly subscription.', 'wrench',      10, 1),
    ('building_repair',      'Building & Property Repair',               'General contractor and handyman service for commercial property covering one-off WOs and PM contracts, sold per-property or per-portfolio.',  'building',    20, 1),
    ('property_management',  'Property Management',                      'Day-to-day operation of multi-tenant commercial or residential buildings on the owner''s behalf, sold per door, unit, or square foot.', 'home',        30, 1),
    ('equipment_repair',     'Equipment Repair',                         'On-site or shop repair of any non-vehicle, non-IT equipment such as industrial, kitchen, medical, or laboratory machines, sold mixed T&M plus contract.', 'settings', 40, 1),
    ('fleet_management',     'Customer Fleet Management',                'End-to-end management of a customer''s vehicle fleet including maintenance, leases, telematics, and end-of-lease decisions, sold per-vehicle/month plus per-event.', 'truck', 50, 1),
    ('it_support',           'IT Support',                               'Managed IT services covering helpdesk, endpoint management, software licensing, and change management, sold per-user or per-device monthly retainer.', 'cpu',         60, 1),
    ('security_systems',     'Security / Cameras / Access Controls',     'Install, monitor, and maintain physical security systems including IP cameras, NVRs, access-control panels, and intrusion alarms, sold install plus monthly monitoring.', 'shield', 70, 1),
    ('pos_support',          'Point of Sale',                            'Maintain customer POS systems including terminals, kitchen displays, kiosks, and payment peripherals, sold per-terminal/month and driven by heartbeat monitoring.', 'credit-card', 80, 1),
    ('commercial_cleaning',  'Commercial Cleaning',                      'Recurring cleaning services for commercial properties sold as monthly contracts with defined route and frequency, high visit volume and mobile-first execution.', 'sparkles',     90, 1);

-- -----------------------------------------------------------------------------
-- PART C: add service_line_id to workorders / tickets / contracts /
--         site_assets / labor_tasks (NULLABLE column + index + FK)
--
-- Pattern (mirrors migration 099):
--   1. Check information_schema.columns; ALTER ADD COLUMN if missing.
--   2. Check information_schema.statistics; ALTER ADD INDEX if missing.
--   3. Check information_schema.table_constraints; ALTER ADD FK if missing.
-- -----------------------------------------------------------------------------

-- workorders.service_line_id
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND column_name = 'service_line_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE workorders ADD COLUMN service_line_id BIGINT UNSIGNED NULL AFTER division_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND index_name = 'idx_workorders_service_line');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE workorders ADD INDEX idx_workorders_service_line (service_line_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND constraint_name = 'fk_workorders_service_line');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE workorders ADD CONSTRAINT fk_workorders_service_line FOREIGN KEY (service_line_id) REFERENCES service_lines(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- tickets.service_line_id
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'tickets' AND column_name = 'service_line_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE tickets ADD COLUMN service_line_id BIGINT UNSIGNED NULL AFTER division_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'tickets' AND index_name = 'idx_tickets_service_line');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE tickets ADD INDEX idx_tickets_service_line (service_line_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'tickets' AND constraint_name = 'fk_tickets_service_line');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE tickets ADD CONSTRAINT fk_tickets_service_line FOREIGN KEY (service_line_id) REFERENCES service_lines(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- contracts.service_line_id
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'contracts' AND column_name = 'service_line_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE contracts ADD COLUMN service_line_id BIGINT UNSIGNED NULL AFTER division_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'contracts' AND index_name = 'idx_contracts_service_line');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE contracts ADD INDEX idx_contracts_service_line (service_line_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'contracts' AND constraint_name = 'fk_contracts_service_line');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE contracts ADD CONSTRAINT fk_contracts_service_line FOREIGN KEY (service_line_id) REFERENCES service_lines(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- site_assets.service_line_id
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND column_name = 'service_line_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE site_assets ADD COLUMN service_line_id BIGINT UNSIGNED NULL AFTER division_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND index_name = 'idx_site_assets_service_line');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE site_assets ADD INDEX idx_site_assets_service_line (service_line_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND constraint_name = 'fk_site_assets_service_line');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE site_assets ADD CONSTRAINT fk_site_assets_service_line FOREIGN KEY (service_line_id) REFERENCES service_lines(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- labor_tasks.service_line_id
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'labor_tasks' AND column_name = 'service_line_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE labor_tasks ADD COLUMN service_line_id BIGINT UNSIGNED NULL AFTER service_type_id',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'labor_tasks' AND index_name = 'idx_labor_tasks_service_line');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE labor_tasks ADD INDEX idx_labor_tasks_service_line (service_line_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'labor_tasks' AND constraint_name = 'fk_labor_tasks_service_line');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE labor_tasks ADD CONSTRAINT fk_labor_tasks_service_line FOREIGN KEY (service_line_id) REFERENCES service_lines(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- PART D: add `type` column to workorders (no CHECK constraint; PHP validates
-- against Workorder::ALLOWED_TYPES). Default 'corrective' so legacy rows have
-- a valid value without a separate backfill step.
-- -----------------------------------------------------------------------------
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND column_name = 'type');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE workorders ADD COLUMN type VARCHAR(40) NOT NULL DEFAULT ''corrective'' AFTER status',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'workorders' AND index_name = 'idx_workorders_type');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE workorders ADD INDEX idx_workorders_type (type)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- PART E: user_service_lines join table + users.primary_service_line_id
-- A user can be a member of N service lines (e.g. a tech who covers IT
-- AND Security); their `primary_service_line_id` is their default landing
-- experience and the implicit filter on dispatch boards.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_service_lines (
    user_id INT UNSIGNED NOT NULL,
    service_line_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, service_line_id),
    INDEX idx_user_service_lines_service_line (service_line_id),
    CONSTRAINT fk_user_service_lines_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_service_lines_service_line
        FOREIGN KEY (service_line_id) REFERENCES service_lines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users.primary_service_line_id
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'primary_service_line_id');
SET @sql := IF(@has_col = 0,
    'ALTER TABLE users ADD COLUMN primary_service_line_id BIGINT UNSIGNED NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_idx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'users' AND index_name = 'idx_users_primary_service_line');
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE users ADD INDEX idx_users_primary_service_line (primary_service_line_id)',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_fk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'users' AND constraint_name = 'fk_users_primary_service_line');
SET @sql := IF(@has_fk = 0,
    'ALTER TABLE users ADD CONSTRAINT fk_users_primary_service_line FOREIGN KEY (primary_service_line_id) REFERENCES service_lines(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- -----------------------------------------------------------------------------
-- PART F: backfill existing rows to the auto_repair service line
-- Each UPDATE has a WHERE NULL guard so a re-run is a no-op. The user-side
-- backfill scopes to system roles that actually work auto-repair WOs;
-- customer / cms / cms_editor / cms_publisher / roadside / parts /
-- dispatcher do not get a primary line by default — admins assign as needed.
-- -----------------------------------------------------------------------------
SET @auto_repair_id := (SELECT id FROM service_lines WHERE slug = 'auto_repair' LIMIT 1);

UPDATE workorders   SET service_line_id = @auto_repair_id WHERE service_line_id IS NULL;
UPDATE tickets      SET service_line_id = @auto_repair_id WHERE service_line_id IS NULL;
UPDATE contracts    SET service_line_id = @auto_repair_id WHERE service_line_id IS NULL;
UPDATE site_assets  SET service_line_id = @auto_repair_id WHERE service_line_id IS NULL;
UPDATE labor_tasks  SET service_line_id = @auto_repair_id WHERE service_line_id IS NULL;

-- Users: only system roles that work auto-repair WOs get the default primary line.
UPDATE users
   SET primary_service_line_id = @auto_repair_id
 WHERE primary_service_line_id IS NULL
   AND role IN ('admin', 'manager', 'technician');

-- Mirror the same set into the explicit join table so membership queries work.
INSERT IGNORE INTO user_service_lines (user_id, service_line_id)
SELECT u.id, @auto_repair_id
  FROM users u
 WHERE u.role IN ('admin', 'manager', 'technician');
