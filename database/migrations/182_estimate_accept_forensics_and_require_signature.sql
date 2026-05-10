-- =============================================================================
-- Estimate accept-flow hardening
--
-- 1. estimates.require_signature — per-estimate toggle. When TRUE, the public
--    accept page blocks per-job approve/reject and forces the customer through
--    the e-sign flow. The signature row is the legal proof of acceptance.
--    Backend enforcement matches: approve-job / reject-job endpoints reject
--    when this flag is on and no signature is present.
--
-- 2. estimate_signatures forensics — geolocation, browser + OS parsed from the
--    raw User-Agent. The existing columns already cover ip_address, user_agent
--    (raw), signed_at and document/signature hashes; the new columns give us:
--      - lat/lng with accuracy and the moment the browser fixed the position
--        (separate from signed_at, since the user can take a while to actually
--        draw + submit)
--      - friendly browser/OS strings so reports don't have to re-parse the UA
--    Geolocation is OPTIONAL — browsers can deny it and the UI must still
--    accept the signature in that case (we just don't get the coords). Reason:
--    legal validity of the e-sign doesn't hinge on geo, and forcing it breaks
--    desktop/incognito flows. We persist NULL when the customer declines.
-- =============================================================================

ALTER TABLE estimates
    ADD COLUMN require_signature TINYINT(1) NOT NULL DEFAULT 0 AFTER customer_notes;

ALTER TABLE estimate_signatures
    ADD COLUMN location_lat DECIMAL(10,7) NULL AFTER user_agent,
    ADD COLUMN location_lng DECIMAL(10,7) NULL AFTER location_lat,
    ADD COLUMN location_accuracy_m DECIMAL(10,2) NULL AFTER location_lng,
    ADD COLUMN location_captured_at DATETIME NULL AFTER location_accuracy_m,
    ADD COLUMN browser_name VARCHAR(80) NULL AFTER location_captured_at,
    ADD COLUMN browser_version VARCHAR(40) NULL AFTER browser_name,
    ADD COLUMN os_name VARCHAR(80) NULL AFTER browser_version,
    ADD COLUMN os_version VARCHAR(40) NULL AFTER os_name;
