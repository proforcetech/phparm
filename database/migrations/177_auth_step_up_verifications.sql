-- =============================================================================
-- Step-up authentication: short-lived TOTP re-verification stamps for
-- accessing sensitive surfaces (credentials vault, ticket category delete,
-- estimate void, etc.) without forcing a full re-login.
--
-- A row here means: this user proved possession of their TOTP secret at
-- `verified_at`. Routes that require step-up call StepUpService::assertFresh()
-- which checks the most recent row is within the freshness window (default
-- 5 minutes — see StepUpService::FRESHNESS_SECONDS). Outside that window,
-- routes throw StepUpRequiredException and the frontend prompts for TOTP.
--
-- Old rows aren't load-bearing — they're audit history at most. We index on
-- (user_id, verified_at DESC) so the freshness check is O(log n).
-- =============================================================================

CREATE TABLE IF NOT EXISTS auth_step_up_verifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    verified_at DATETIME NOT NULL,
    ip_address VARCHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    PRIMARY KEY (id),
    INDEX idx_step_up_user_recent (user_id, verified_at),
    CONSTRAINT fk_step_up_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
