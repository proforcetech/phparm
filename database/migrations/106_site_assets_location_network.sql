-- Phase 2.4 of docs/expansion-plan.md: floorplan + network identity.
--
-- Two related concerns, bundled because they're both physical/logical
-- pointers on the same row:
--   * Floorplan:   building → floor → room → rack → rack_position
--   * Network:     ip_address / mac_address / subnet / vlan
--
-- Indexes are cheap here: techs search by "what's in room 204" or
-- "which asset owns 10.1.2.3" constantly. MAC address is kept as
-- VARCHAR(32) (not binary) so copy-paste from switch tables Just Works.

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND column_name = 'building');
SET @sql := IF(@col_exists = 0,
    "ALTER TABLE site_assets
        ADD COLUMN building VARCHAR(80) NULL AFTER qr_token,
        ADD COLUMN floor VARCHAR(40) NULL AFTER building,
        ADD COLUMN room VARCHAR(80) NULL AFTER floor,
        ADD COLUMN rack VARCHAR(80) NULL AFTER room,
        ADD COLUMN rack_position VARCHAR(40) NULL AFTER rack,
        ADD COLUMN ip_address VARCHAR(45) NULL AFTER rack_position,
        ADD COLUMN mac_address VARCHAR(32) NULL AFTER ip_address,
        ADD COLUMN subnet VARCHAR(64) NULL AFTER mac_address,
        ADD COLUMN vlan VARCHAR(40) NULL AFTER subnet",
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND index_name = 'idx_site_assets_room');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE site_assets ADD INDEX idx_site_assets_room (site_id, building, floor, room)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND index_name = 'idx_site_assets_ip');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE site_assets ADD INDEX idx_site_assets_ip (ip_address)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND index_name = 'idx_site_assets_mac');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE site_assets ADD INDEX idx_site_assets_mac (mac_address)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
