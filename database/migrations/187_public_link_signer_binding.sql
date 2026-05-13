-- R-02b — bind public e-sign links to a specific signer identity.
--
-- The audit recommendation (AUD-064, audit-v2-recommendations.md) calls for
-- one link per signer rather than the legacy "any signer can claim any
-- link" model. This migration adds the columns that carry that binding:
--
--   signer_email           VARCHAR(160) NULL — when populated, the
--                          signature capture endpoint refuses any
--                          signer_email payload that doesn't match
--                          (case-insensitive, trimmed).
--   signer_invitation_id   INT UNSIGNED NULL — forward-looking FK to
--                          the contract_signers table introduced in
--                          R-02c. We add the column now so the binding
--                          shape is stable across R-02b → R-02c without
--                          a follow-up migration; the FK constraint and
--                          target table arrive in R-02c.
--
-- Both columns are nullable. NULL means "open link" — the legacy
-- semantics, preserved so existing in-flight links keep working until
-- they're consumed or expire. Once R-02d flips the admin UI to issue
-- per-signer links, NULL becomes the deprecated path.

ALTER TABLE contract_public_links
    ADD COLUMN signer_email VARCHAR(160) NULL AFTER consumed_by_signature_id,
    ADD COLUMN signer_invitation_id INT UNSIGNED NULL AFTER signer_email;

CREATE INDEX idx_contract_public_links_signer_email
    ON contract_public_links (signer_email);

ALTER TABLE estimate_public_links
    ADD COLUMN signer_email VARCHAR(160) NULL AFTER consumed_by_signature_id,
    ADD COLUMN signer_invitation_id INT UNSIGNED NULL AFTER signer_email;

CREATE INDEX idx_estimate_public_links_signer_email
    ON estimate_public_links (signer_email);
