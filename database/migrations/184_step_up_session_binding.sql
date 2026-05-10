-- =============================================================================
-- Step-up session binding (AUD-069).
--
-- Pre-fix, StepUpService::isFresh() considered any recent verify for the
-- user's id sufficient — it had no awareness of WHICH session/JWT performed
-- the verification. An attacker who hijacked a session token within the
-- 5-minute freshness window inherited the legitimate user's step-up without
-- ever holding the TOTP device. That's the exact compromise step-up exists
-- to defeat.
--
-- This migration adds a `session_fingerprint` column. The auth middleware
-- computes a stable hash of the active credential (sha256 of the session id
-- for session-based auth, sha256 of the JWT for cookie/bearer auth) and
-- attaches it to the request; StepUpService records it on verify() and
-- requires an exact match on isFresh()/assertFresh()/remainingSeconds().
--
-- Nullable so legacy rows survive the migration. The freshness queries
-- filter on `session_fingerprint = :fp`, and SQL `NULL = anything` is NULL
-- (i.e. false), so legacy rows automatically stop satisfying freshness
-- post-migration without needing a DELETE — the worst case is a legitimate
-- user re-prompted for TOTP on their first sensitive action after upgrade.
-- =============================================================================

ALTER TABLE auth_step_up_verifications
    ADD COLUMN session_fingerprint VARCHAR(64) NULL AFTER user_agent;

CREATE INDEX idx_step_up_user_session
    ON auth_step_up_verifications (user_id, session_fingerprint, verified_at);
