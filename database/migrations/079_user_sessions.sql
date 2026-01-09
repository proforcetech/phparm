CREATE TABLE IF NOT EXISTS user_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    session_id VARCHAR(128) NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    device_label VARCHAR(191) NULL,
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    revoked_by INT UNSIGNED NULL,
    INDEX idx_user_sessions_user (user_id),
    INDEX idx_user_sessions_revoked (revoked_at),
    UNIQUE KEY uniq_user_sessions_session (session_id),
    CONSTRAINT fk_user_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
