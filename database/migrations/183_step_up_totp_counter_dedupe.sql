-- =============================================================================
-- Step-up TOTP code-reuse defense (AUD-068).
--
-- TotpService::verifyCode accepts any code that hashes to the current counter
-- ± window, but never records *which* counter was matched. That means the
-- same captured 6-digit code can be replayed multiple times within the ~90s
-- validity window, producing multiple step-up stamps from a single code.
--
-- This migration adds a `totp_counter` column to auth_step_up_verifications
-- and a UNIQUE (user_id, totp_counter) index so step-up replay attempts are
-- rejected at the database layer.
--
-- Nullable so legacy rows (recorded before this migration) keep working —
-- MySQL allows multiple NULLs in a UNIQUE index. New step-ups always set
-- the counter, so post-migration replay is blocked.
-- =============================================================================

ALTER TABLE auth_step_up_verifications
    ADD COLUMN totp_counter BIGINT NULL AFTER user_agent;

CREATE UNIQUE INDEX uniq_step_up_user_totp_counter
    ON auth_step_up_verifications (user_id, totp_counter);
