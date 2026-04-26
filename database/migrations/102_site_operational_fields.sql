-- Phase 1.2 of docs/expansion-plan.md: operational detail on sites.
--
--   access_instructions   — free-text notes for arriving techs
--   hours_json            — weekly operating hours, structured JSON
--   alarm_code_encrypted  — base64(nonce||cipher) via App\Support\Crypto\FieldCipher
--   gate_code_encrypted   — same encryption scheme as alarm_code
--
-- Plus site_blackout_windows: explicit time ranges when service is blocked
-- (e.g., retail holiday freeze, audit windows, maintenance quiet hours).
-- Recurrence is kept as an RRULE-ish string; the scheduler layer is
-- responsible for expanding it.

-- 1. site columns
SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sites' AND column_name = 'access_instructions');
SET @sql := IF(@col = 0,
    'ALTER TABLE sites ADD COLUMN access_instructions TEXT NULL AFTER phone',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sites' AND column_name = 'hours_json');
SET @sql := IF(@col = 0,
    'ALTER TABLE sites ADD COLUMN hours_json JSON NULL AFTER access_instructions',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sites' AND column_name = 'alarm_code_encrypted');
SET @sql := IF(@col = 0,
    'ALTER TABLE sites ADD COLUMN alarm_code_encrypted TEXT NULL AFTER hours_json',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'sites' AND column_name = 'gate_code_encrypted');
SET @sql := IF(@col = 0,
    'ALTER TABLE sites ADD COLUMN gate_code_encrypted TEXT NULL AFTER alarm_code_encrypted',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. blackout windows
CREATE TABLE IF NOT EXISTS site_blackout_windows (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id INT UNSIGNED NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    reason VARCHAR(191) NULL,
    -- One-off (NULL), or an RRULE-style string like "FREQ=WEEKLY;BYDAY=SA,SU".
    -- The scheduler layer expands the recurrence; DB-side just stores it.
    recurrence VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_blackout_site (site_id, is_active),
    INDEX idx_blackout_window (site_id, starts_at, ends_at),
    CONSTRAINT fk_blackout_site FOREIGN KEY (site_id)
        REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
