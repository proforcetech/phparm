-- Phase 2.3 of docs/expansion-plan.md: QR code identifiers for site_assets.
--
-- `qr_token` is an opaque 48-char hex identifier (24 random bytes) used by
-- the public /api/qr/scan/{token} endpoint. Stored separately from `id` so
-- scans don't leak internal row numbers and so tokens can be rotated if a
-- sticker is compromised (issue a new token, old one 404s).
--
-- Populated lazily on first QR render; hence nullable. UNIQUE so we can
-- resolve scans in O(1) via the index without leaking info on collisions.

SET @col_exists := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND column_name = 'qr_token');
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE site_assets ADD COLUMN qr_token VARCHAR(64) NULL AFTER custom_fields',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'site_assets' AND index_name = 'uq_site_assets_qr_token');
SET @sql := IF(@idx_exists = 0,
    'ALTER TABLE site_assets ADD UNIQUE INDEX uq_site_assets_qr_token (qr_token)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
