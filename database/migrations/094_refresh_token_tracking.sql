-- Create refresh_tokens table for tracking and rotation
-- This enables detection of refresh token reuse (potential theft indicator)

CREATE TABLE IF NOT EXISTS refresh_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE COMMENT 'SHA-256 hash of the token',
    family_id VARCHAR(36) NOT NULL COMMENT 'Token family for rotation tracking',
    is_revoked BOOLEAN DEFAULT FALSE,
    revoked_at DATETIME NULL,
    revoked_reason VARCHAR(50) NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token_hash (token_hash),
    INDEX idx_user_active (user_id, is_revoked, expires_at),
    INDEX idx_family (family_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add index for cleanup of expired tokens
CREATE EVENT IF NOT EXISTS cleanup_expired_refresh_tokens
ON SCHEDULE EVERY 1 DAY
DO DELETE FROM refresh_tokens WHERE expires_at < NOW() - INTERVAL 7 DAY;
