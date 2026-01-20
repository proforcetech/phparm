-- Create impersonation_sessions table for audit logging and validation
-- This tracks all impersonation events for security auditing purposes

CREATE TABLE IF NOT EXISTS impersonation_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    impersonator_id INT UNSIGNED NOT NULL,
    impersonated_id INT UNSIGNED NOT NULL,
    session_token VARCHAR(64) NOT NULL UNIQUE,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (impersonator_id) REFERENCES users(id),
    FOREIGN KEY (impersonated_id) REFERENCES users(id),
    INDEX idx_session_token (session_token),
    INDEX idx_active_sessions (impersonator_id, is_active),
    INDEX idx_impersonated_user (impersonated_id),
    INDEX idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
