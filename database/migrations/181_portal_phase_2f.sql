-- Phase 2f — CSAT, notification preferences, and portal API tokens.
--
-- Three independent surfaces, one migration so they ship as a unit:
--
--   1. portal_csat_responses — one row per (portal_account, workorder) pair
--      capturing a 1-5 rating + optional comment. No row exists until either
--      the user submits OR a survey link is generated; "never asked" and
--      "asked, never answered" are distinguished by responded_at.
--
--   2. portal_notification_preferences — per-account on/off matrix indexed by
--      (pref_key, channel). Default-on for email + in_app; SMS opt-in. We
--      store explicit rows rather than a JSON blob so individual prefs can
--      be toggled with a single UPSERT and queried with a simple WHERE on
--      the dispatch path.
--
--   3. portal_api_tokens — self-issued personal access tokens. Token format
--      is `pat_<prefix>_<secret>` where <prefix> is 12 url-safe chars stored
--      verbatim (for display/lookup) and <secret> is bcrypt-hashed. Scopes
--      are a JSON array of permission strings; null = full account scope.
--
-- All three FK to portal_accounts(id) ON DELETE CASCADE — portal account
-- revocation should sweep the user's prefs/tokens/CSAT rows in one shot.
-- The CSAT FK to workorders is ON DELETE CASCADE for the same reason: a
-- deleted workorder shouldn't leave orphan CSAT rows pointing at nothing.
--
-- Idempotent via CREATE TABLE IF NOT EXISTS — no ALTER gating needed for
-- brand-new tables.

CREATE TABLE IF NOT EXISTS portal_csat_responses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    portal_account_id INT UNSIGNED NOT NULL,
    workorder_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NULL,
    comment TEXT NULL,
    -- Public token lets us email a one-click survey link to a portal_user
    -- so they can rate without logging in. Null = no public link issued
    -- yet; the dashboard prompt path doesn't need one.
    public_token VARCHAR(64) NULL,
    requested_at TIMESTAMP NULL DEFAULT NULL,
    responded_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_csat_account_workorder (portal_account_id, workorder_id),
    UNIQUE KEY uniq_csat_public_token (public_token),
    KEY idx_csat_responded (responded_at),
    CONSTRAINT fk_csat_portal_account FOREIGN KEY (portal_account_id)
        REFERENCES portal_accounts(id) ON DELETE CASCADE,
    CONSTRAINT fk_csat_workorder FOREIGN KEY (workorder_id)
        REFERENCES workorders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_notification_preferences (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    portal_account_id INT UNSIGNED NOT NULL,
    -- pref_key matches the dispatch path constants in
    -- PortalNotificationPreferenceService::PREF_KEYS — kept as a free-form
    -- string column so adding a new event type doesn't require a schema
    -- migration. Service-level validation is the gate.
    pref_key VARCHAR(64) NOT NULL,
    channel ENUM('email','sms','in_app') NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_notif_pref (portal_account_id, pref_key, channel),
    CONSTRAINT fk_notif_pref_portal_account FOREIGN KEY (portal_account_id)
        REFERENCES portal_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS portal_api_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    portal_account_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    -- token_prefix is the first 12 url-safe chars of the random token,
    -- stored verbatim so the UI can show a fingerprint and the auth
    -- middleware can find the row in O(1) without scanning every row.
    -- The full secret is bcrypt-hashed in token_hash.
    token_prefix VARCHAR(16) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    -- scopes JSON array of permission strings. NULL = "all permissions
    -- the issuing account currently has". Concrete scopes let the user
    -- mint a token narrower than themselves (the only safe direction).
    scopes JSON NULL,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    expires_at TIMESTAMP NULL DEFAULT NULL,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_token_prefix (token_prefix),
    KEY idx_token_account_active (portal_account_id, revoked_at),
    CONSTRAINT fk_api_token_portal_account FOREIGN KEY (portal_account_id)
        REFERENCES portal_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
