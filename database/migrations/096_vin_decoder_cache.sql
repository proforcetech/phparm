-- VIN Decoder Cache and Rate Limiting Tables
-- Migration: 096_vin_decoder_cache.sql
-- Adds persistent caching and rate limiting for VIN decoder

-- Persistent VIN decode cache
CREATE TABLE IF NOT EXISTS vin_decode_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vin VARCHAR(17) NOT NULL,
    decoder_source VARCHAR(30) NOT NULL DEFAULT 'nhtsa',
    decoded_data JSON NOT NULL,
    year INT UNSIGNED NULL,
    make VARCHAR(80) NULL,
    model VARCHAR(80) NULL,
    engine VARCHAR(120) NULL,
    transmission VARCHAR(80) NULL,
    drive_type VARCHAR(40) NULL,
    trim_level VARCHAR(80) NULL,
    body_style VARCHAR(80) NULL,
    fuel_type VARCHAR(60) NULL,
    weight_class VARCHAR(80) NULL,
    decode_success TINYINT(1) NOT NULL DEFAULT 1,
    error_message VARCHAR(255) NULL,
    api_response_time_ms INT UNSIGNED NULL,
    expires_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_vin (vin),
    INDEX idx_expires_at (expires_at),
    INDEX idx_decoder_source (decoder_source),
    INDEX idx_year_make_model (year, make, model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VIN decode audit log for tracking and analytics
CREATE TABLE IF NOT EXISTS vin_decode_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vin VARCHAR(17) NOT NULL,
    user_id INT UNSIGNED NULL,
    decoder_source VARCHAR(30) NULL,
    cache_hit TINYINT(1) NOT NULL DEFAULT 0,
    decode_success TINYINT(1) NOT NULL DEFAULT 1,
    error_message VARCHAR(255) NULL,
    response_time_ms INT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vin (vin),
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at),
    INDEX idx_cache_hit (cache_hit),
    INDEX idx_decode_success (decode_success)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting tracking for VIN decoder
CREATE TABLE IF NOT EXISTS vin_decode_rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rate_key VARCHAR(120) NOT NULL,
    request_count INT UNSIGNED NOT NULL DEFAULT 1,
    window_start DATETIME NOT NULL,
    window_end DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_rate_key_window (rate_key, window_start),
    INDEX idx_window_end (window_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VIN decoder statistics summary (aggregated daily)
CREATE TABLE IF NOT EXISTS vin_decode_stats (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    stat_date DATE NOT NULL,
    decoder_source VARCHAR(30) NOT NULL,
    total_requests INT UNSIGNED NOT NULL DEFAULT 0,
    cache_hits INT UNSIGNED NOT NULL DEFAULT 0,
    successful_decodes INT UNSIGNED NOT NULL DEFAULT 0,
    failed_decodes INT UNSIGNED NOT NULL DEFAULT 0,
    avg_response_time_ms INT UNSIGNED NULL,
    unique_vins INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE INDEX uniq_date_source (stat_date, decoder_source),
    INDEX idx_stat_date (stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleanup old rate limit entries (run via cron)
-- DELETE FROM vin_decode_rate_limits WHERE window_end < NOW() - INTERVAL 1 HOUR;

-- Cleanup expired cache entries (run via cron)
-- DELETE FROM vin_decode_cache WHERE expires_at < NOW();
