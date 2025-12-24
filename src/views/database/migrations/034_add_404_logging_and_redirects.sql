-- Migration: Add 404 logging and redirect management

-- Table for tracking 404 errors
CREATE TABLE IF NOT EXISTS not_found_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uri VARCHAR(512) NOT NULL COMMENT 'Requested URI that resulted in 404',
    referrer VARCHAR(512) NULL COMMENT 'HTTP Referer header',
    user_agent VARCHAR(512) NULL COMMENT 'User-Agent string',
    ip_address VARCHAR(45) NULL COMMENT 'Client IP address',
    first_seen DATETIME NOT NULL COMMENT 'First time this URI was requested',
    last_seen DATETIME NOT NULL COMMENT 'Most recent request time',
    hits INT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Total number of 404 hits',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_uri (uri(255)),
    INDEX idx_last_seen (last_seen),
    INDEX idx_hits (hits)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks 404 errors for monitoring and redirect creation';

-- Table for managing redirects
CREATE TABLE IF NOT EXISTS redirects (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_path VARCHAR(512) NOT NULL COMMENT 'Original path to redirect from',
    destination_path VARCHAR(512) NOT NULL COMMENT 'Target path to redirect to',
    redirect_type ENUM('301', '302', '307', '308') NOT NULL DEFAULT '301' COMMENT 'HTTP redirect status code',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether redirect is enabled',
    match_type ENUM('exact', 'prefix', 'regex') NOT NULL DEFAULT 'exact' COMMENT 'How to match source path',
    description VARCHAR(255) NULL COMMENT 'Optional note about this redirect',
    hits INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of times this redirect has been used',
    created_by INT UNSIGNED NULL COMMENT 'User ID who created this redirect',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY unique_source (source_path(255)),
    INDEX idx_source_path (source_path(255)),
    INDEX idx_is_active (is_active),
    INDEX idx_match_type (match_type),

    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='URL redirect rules for SEO and fixing broken links';
