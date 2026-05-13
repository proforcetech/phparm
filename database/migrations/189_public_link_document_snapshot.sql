-- R-04 — snapshot the document hash + JSON at link-issue time so the
-- forensic record at signature-capture binds the signer to what they
-- were shown when the link was sent, not whatever the current row
-- happens to look like.
--
-- AUD-066 / docs/audit-v2-recommendations.md (R-04). Background:
-- today, ContractSigningService::captureSignature and
-- EstimatePublicLinkService::captureSignature compute document_hash on
-- the row state at the moment of capture. An internal actor with
-- contracts.update / estimates.update can edit between issue and
-- capture; the resulting signature binds to the modified document
-- silently. This migration moves the canonical hash to issue-time.
--
--   document_hash_at_issue    CHAR(64) NULL — sha256 of the document
--                             snapshot as of issueLink(). NULL on
--                             legacy in-flight links so they keep the
--                             pre-R-04 capture-time-only behaviour.
--   document_snapshot_json    MEDIUMTEXT NULL — full normalized JSON
--                             snapshot used to compute the hash.
--                             Forensic reconstruction can replay
--                             "this is what the signer was shown".
--                             Storage cost is ~few KB per link;
--                             nullable so legacy links pay zero.
--
-- The matching enforcement on captureSignature:
--   - default: refuse capture when issue-time hash != current hash
--   - override: accept_document_changes=true, audited with the diff
--     context, signature row stamps document_changed_accepted=1 and
--     carries both hashes for the forensic trail.

ALTER TABLE contract_public_links
    ADD COLUMN document_hash_at_issue CHAR(64) NULL AFTER signer_invitation_id,
    ADD COLUMN document_snapshot_json MEDIUMTEXT NULL AFTER document_hash_at_issue;

ALTER TABLE estimate_public_links
    ADD COLUMN document_hash_at_issue CHAR(64) NULL AFTER signer_invitation_id,
    ADD COLUMN document_snapshot_json MEDIUMTEXT NULL AFTER document_hash_at_issue;

ALTER TABLE contract_signatures
    ADD COLUMN document_hash_at_issue CHAR(64) NULL AFTER document_hash,
    ADD COLUMN document_changed_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER document_hash_at_issue;

ALTER TABLE estimate_signatures
    ADD COLUMN document_hash_at_issue CHAR(64) NULL AFTER document_hash,
    ADD COLUMN document_changed_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER document_hash_at_issue;
