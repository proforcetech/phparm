-- Track recent password hashes for reuse prevention
CREATE TABLE IF NOT EXISTS user_password_history (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_password_history_user (user_id),
    INDEX idx_user_password_history_created_at (created_at),
    CONSTRAINT fk_user_password_history_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
);
