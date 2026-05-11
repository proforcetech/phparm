-- AUD-064 — make e-sign public links single-use.
--
-- Closes the immediate replay window where a leaked link / short_code could
-- be re-submitted with arbitrary signer identity to attach extra signatures
-- after the legitimate signer had already completed.
--
-- The fix is mechanical: stamp consumed_at on the link the instant a
-- signature is captured, and reject any later capture against the same
-- link. The previous "co-signer reuses the same link" flow becomes
-- explicitly unsupported — the audit recommendation says multi-party
-- signing should issue one link per signer (tracked separately in
-- docs/audit-v2-recommendations.md). Approve/reject and view paths are
-- intentionally NOT gated on consumption (customers must still be able
-- to look at the estimate after signing).
--
-- consumed_by_signature_id is informational, not enforced via FK — the
-- signatures tables (contract_signatures, estimate_signatures) cascade-
-- delete with the parent contract/estimate, but the link row should
-- survive the signature row, so a hard FK would force a NULL-out on
-- cascade and add no real value.

ALTER TABLE contract_public_links
    ADD COLUMN consumed_at DATETIME NULL AFTER revoked_at,
    ADD COLUMN consumed_by_signature_id INT UNSIGNED NULL AFTER consumed_at;

CREATE INDEX idx_contract_public_links_consumed
    ON contract_public_links (consumed_at);

ALTER TABLE estimate_public_links
    ADD COLUMN consumed_at DATETIME NULL AFTER last_accessed_at,
    ADD COLUMN consumed_by_signature_id INT UNSIGNED NULL AFTER consumed_at;

CREATE INDEX idx_estimate_public_links_consumed
    ON estimate_public_links (consumed_at);
