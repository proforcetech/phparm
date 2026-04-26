-- Phase 3.6 of docs/expansion-plan.md: Close reasons, resolution codes, and
-- failure codes catalog.  Columns already exist on `tickets` (migration 108)
-- as free-form VARCHARs; this migration adds the lookup tables and a hook
-- for validation, while remaining backwards compatible (the ticket columns
-- stay VARCHAR — we don't FK them, because legacy rows may have arbitrary
-- values and we enforce catalog membership at the application layer, not
-- the DB layer).

CREATE TABLE IF NOT EXISTS ticket_close_reasons (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    requires_detail TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_close_reasons_code (code),
    INDEX idx_ticket_close_reasons_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_resolution_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_resolution_codes_code (code),
    INDEX idx_ticket_resolution_codes_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_failure_codes (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL,
    name VARCHAR(160) NOT NULL,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ticket_failure_codes_code (code),
    INDEX idx_ticket_failure_codes_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the close-reason catalog with the two from the plan (nuisance,
-- duplicate) and a few common siblings.  Idempotent via INSERT IGNORE on
-- the UNIQUE code.
INSERT IGNORE INTO ticket_close_reasons (code, name, description, requires_detail) VALUES
    ('nuisance',        'Nuisance',          'Reporter cancelled or reported in error',     0),
    ('duplicate',       'Duplicate',         'Same issue as another open ticket',             1),
    ('resolved',        'Resolved',          'Issue addressed; see resolution_code for how',  0),
    ('unresolved',      'Unresolved',        'Closed without full resolution (e.g., customer declined work)', 1),
    ('out_of_scope',    'Out of scope',      'Not covered by contract or service area',       1);
