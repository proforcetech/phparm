-- R-02c — first-class multi-party signing.
--
-- The audit recommendation (AUD-064, audit-v2-recommendations.md) calls for
-- replacing the implicit "issue N open links" multi-party pattern with an
-- explicit invitation roster. This table is that roster: one row per
-- invited signer, identified by email, with its own bound public link
-- (via contract_public_links.signer_invitation_id, added in 187).
--
-- A signer's lifecycle:
--   invited → (signed | revoked)
--
-- Once a signer's bound link captures a signature, the trigger in
-- ContractSigningService::captureSignature stamps signed_signature_id +
-- signed_at on the matching signer row. Revocation is soft — the
-- revoked_at timestamp leaves the historical row in place for audit and
-- lets us short-circuit the active-signer dedupe check against email.
--
-- Why FK signed_signature_id ON DELETE SET NULL: deleting a signature
-- (which we don't expose today, but might for GDPR-style erasure) must
-- not orphan the signer row, since the signer was still genuinely
-- invited. The historical fact survives even if the proof goes away.
--
-- Why no UNIQUE (contract_id, email): a signer can be invited, revoked,
-- and re-invited; uniqueness over the revoked_at-null subset is what we
-- want, which MySQL can't express directly without a generated column.
-- ContractSignerService enforces that invariant at the service layer.

CREATE TABLE contract_signers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    contract_id INT UNSIGNED NOT NULL,
    email VARCHAR(160) NOT NULL,
    name VARCHAR(120) NOT NULL,
    title VARCHAR(120) NULL,
    display_order INT UNSIGNED NOT NULL DEFAULT 0,
    invited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    invited_by_user_id INT UNSIGNED NULL,
    revoked_at TIMESTAMP NULL,
    signed_signature_id INT UNSIGNED NULL,
    signed_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_contract_signers_contract
        FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    CONSTRAINT fk_contract_signers_signature
        FOREIGN KEY (signed_signature_id) REFERENCES contract_signatures(id) ON DELETE SET NULL,
    INDEX idx_contract_signers_contract (contract_id, display_order, id),
    INDEX idx_contract_signers_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
