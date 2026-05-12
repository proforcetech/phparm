# Audit v2 Recommendations — Deferred Items

Plan date: `2026-05-10`
Companion: [audit-v2-plan.md](/var/www/phparm/docs/audit-v2-plan.md), [audit-findings.md](/var/www/phparm/docs/audit-findings.md)

This document captures the Phase 2 items that were **not** fixed in the audit
because they would require a multi-day rewrite, depend on infra/env changes,
or have ambiguous business impact (per the decision rules in the v2 plan).
Each entry below is keyed to its `AUD-NNN` finding so the link back to the
register is one-step.

For items where Phase 2 fixed the immediately exploitable surface but a
broader architectural change is still recommended, the finding's `Status:`
in the register is `partially-resolved` and the residual scope is captured
here.

The recommendations below are ordered roughly by user-visible risk × cost.
Nothing here is load-bearing for the v2 audit closeout — they are inputs
to a future planning round.

---

## R-01 — AUD-063: Replace client-supplied `audio_path` with server-managed multipart upload

**Source finding:** AUD-063 (security/medium, partially-resolved)

**What Phase 2 did:** Hardened the immediate exploit surface.
`HeuristicTranscriber` now requires a non-empty storage root, refuses
`..` / null bytes / absolute paths, and verifies via `realpath()` that
the resolved file lives inside the configured root.
`VoiceNoteService::record()` rejects absolute paths and null bytes.
The route wires a per-deployment `storage/private/voice_notes/` root and
creates it `0750`.

**What's still broken architecturally:** The voice-notes API still accepts
`audio_path` as a **client-supplied relative string**. An authenticated
`voice_notes.create` actor can choose any sub-path inside the root, which
permits collisions with another user's notes (an integrity issue: actor A
records over actor B's path) and leaks path patterns into transcripts and
the UI. The path-as-input shape is fundamentally the wrong contract.

**Recommended rewrite:**

1. Replace the JSON `audio_path` field with a `multipart/form-data` upload
   field (`audio` file part) on `POST /api/voice-notes`.
2. Server generates the storage path: `voice_notes/{yyyy}/{mm}/{user_id}/{ulid}.{ext}`
   where `ulid` is `Ulid::generate()` (sortable by time, no collision
   surface).
3. MIME-sniff the upload (PHP `finfo` on the first 2 KiB), reject anything
   not in `audio/{mp3, mpeg, wav, ogg, webm, m4a, aac}`, and clamp the
   file size at the route layer (proposed: 25 MB, configurable via
   `voice_notes.max_upload_bytes` setting).
4. Persist `audio_path`, `audio_mime`, `audio_bytes_size`, and
   `audio_sha256_hash` (already in the model) atomically in the same
   transaction.
5. Reject any payload that still includes a string `audio_path` field;
   the field becomes write-only-by-the-server.

**Cost:** ~2 days. Touches the React recorder component (currently uploads
the file via a separate `/api/voice-notes/audio` step that doesn't exist
yet — see also UI gap survey), the service signature, and the test
fixture set. Backwards compatibility is not a concern: the feature is
behind a permission and the on-disk path scheme can include both styles
during cutover.

---

## R-02 — AUD-064: First-class multi-party signing + per-link rate limiting

**Source finding:** AUD-064 (security/high, partially-resolved)

**What Phase 2 did:** Made every public e-sign link single-use via
`consumed_at` (migration 185). `ContractSigningService::captureSignature()`
and `EstimatePublicLinkService::captureSignature()` atomically claim the
link before INSERTing the signature. Replay against a consumed link is
rejected with "already been used".

**What's still broken architecturally:**

- **Multi-party signing has no first-class flow.** Today, a contract that
  needs three signatures requires the admin to issue three separate
  links. The links are not bound to a specific signer email at issue
  time, so any signer can claim any of the three links. The audit's
  recommendation explicitly calls for one-link-per-signer with
  `signer_email` binding.
- **No per-link / per-IP rate limiting on `/c/...`, `/e/...`, or the
  capture endpoints.** A leaked-but-unconsumed link is still vulnerable
  to a single race-of-one capture by whoever gets there first. Combined
  with AUD-065's 40-bit short-code, an attacker can enumerate.

**Recommended rewrite:**

1. Add `signer_email VARCHAR(160) NULL` and `signer_invitation_id` to
   `contract_public_links` and `estimate_public_links`. When NULL, the
   link is "open" (legacy, deprecated). When populated, capture must
   match (case-insensitive, trimmed).
2. Add a `contract_signers` table: `id`, `contract_id`, `email`,
   `name`, `display_order`, `requested_at`, `signed_signature_id NULL`.
   Each row gets its own `contract_public_links` row at issue time. The
   admin UI flips from "issue 1 link" to "invite N signers".
3. Rate limit `/c/{token}/sign`, `/e/{token}/sign`, `/c/{shortCode}`,
   `/e/{shortCode}` at 30/min per IP via the existing `LoginRateLimiter`
   pattern (rebrand to `PublicLinkRateLimiter`).
4. Refuse capture when the bound `signer_email` doesn't match the
   payload's `signer_email`. Log the mismatch as a security event for
   alerting.

**Cost:** ~3-4 days. Cross-cuts the contract admin UI, the estimate
public view, and the signer invitation email template. Migration is
additive (the `signer_email` column is nullable, legacy links continue
to work as "open").

---

## R-03 — AUD-065: Replace 10-character short codes for state-changing endpoints

**Source finding:** AUD-065 (security/medium, open)

**Recommended fix:** Two acceptable approaches:

1. **Simpler — deprecate short codes for state changes.** Keep
   `/c/{shortCode}` and `/e/{shortCode}` as **read-only redirect**
   endpoints that look up the link and redirect to
   `/contract/view?token={token}` / `/estimate/view?token={token}`.
   Capture, comment, approve, and reject endpoints accept only the long
   `token` (256 bits). Short codes remain for "type this URL by hand
   from a printed estimate" but cannot mutate state.
2. **Stricter — re-issue short codes from a higher-entropy alphabet.**
   Replace the 10-char hex prefix (`substr(hash, 0, 10)`) with 12
   characters from the full base64url alphabet (`64^12 ≈ 2^72`), still
   compact enough for hand-typing, far above the brute-force threshold.
   Requires a migration to back-fill short codes for existing rows that
   are still active.

In either case, add per-IP rate limiting on `/c/{shortCode}` and
`/e/{shortCode}` (≤30 req/min) since they are the lookup oracles for
the brute-force scenario.

**Cost:** Approach 1 = ~1 day (route changes + UI link copy). Approach 2
= ~2 days (migration + UI cutover for printed estimates).

**Recommendation between them:** **Approach 1.** Short codes were added
for the printed-estimate hand-typing flow but in practice users always
click the email link instead. Removing the mutation surface is a clean
reduction in attack surface.

---

## R-04 — AUD-066: Snapshot document hash at link-issue time, not at capture time

**Source finding:** AUD-066 (security/medium, open)

**Why this matters:** The signed `document_hash` today reflects the
document state **at signature capture**, not the state the signer was
shown when the link was issued. An internal actor with `contracts.update`
or `estimates.update` permission can edit the document between the time
the customer opens the link and the time they click "Sign", and the
resulting forensic hash will bind the signer to the modified document.

**Recommended fix:**

1. Add `document_hash_at_issue VARCHAR(64) NOT NULL` and
   `document_snapshot_json MEDIUMTEXT` to `contract_public_links` and
   `estimate_public_links`. Compute and store both at link-issue time
   in `ContractSigningService::issueLink()` and
   `EstimatePublicLinkService::issueLink()`.
2. On capture, compare `document_hash_at_issue` against a freshly
   computed `hashContractSnapshot($contract)`. If they differ:
   - **Default behavior:** refuse capture with a clear error
     ("the contract was modified after this link was sent — please
     request a new link").
   - **Override path:** if the request includes
     `accept_document_changes=true` and the override is logged with the
     diff to the audit trail, allow the capture but stamp **both**
     hashes on the signature row (`document_hash_at_issue` +
     `document_hash_at_sign`).
3. Display the issue-time snapshot to the signer in the public view
   (the customer sees what was issued, not what's current). This is the
   actual UX win — the security property follows.

**Cost:** ~2 days. Migration is additive but the snapshot column is
chunky (MEDIUMTEXT for the full document JSON), so the storage cost
should be measured before rolling out for older contracts.

---

## R-05 — AUD-067: Portal site-scoping for billing, contracts, workorders ✅ Shipped 2026-05-11

**Source finding:** AUD-067 (security/medium) — **resolved**.

**Policy decided:** **Strict.** A portal account narrowed to
`allowed_site_ids = [N]` sees only rows that resolve to one of those
site ids. Rows with no resolvable site (invoices/workorders with
`site_asset_id = NULL`, contracts with no `contract_sites` linking rows)
are **excluded** — not silently passed through. Multi-site contracts
match via **ANY** of their linked sites. No legacy passthrough config
flag — silent passthrough was the original bug and re-introducing it
would have re-opened the same hole.

**Schema reality vs. original recommendation:** the original recommendation
proposed filtering via `customers.site_id` and `workorders.site_id`, but
neither column exists in the post-migration-156 schema. The real path
is:

- **Invoices / workorders:** `transactional_doc.site_asset_id →
  site_assets.site_id` (nullable; legacy auto-shop installs run with
  this universally NULL).
- **Contracts:** `contract_sites` linking table (multi-site, ANY-match).

**What shipped:**

1. `PortalAccount::allowsRowWithSite(array|int|null $siteIds)` — single
   strict-policy gate. Unscoped (`allowed_site_ids === null`) returns
   true; scoped + null/empty returns false.
2. `SiteAssetRepository::resolveSiteIdsForAssetIds(int[]): array<int,int>` —
   bulk lookup so list views stay O(1) extra query rather than N+1.
3. `ContractRepository::listSiteIdsForContractIds(int[]): array<int,int[]>` —
   same shape for the contract path.
4. `ContractRepository::search()` got an `allowed_site_ids` filter that
   pushes `EXISTS (SELECT 1 FROM contract_sites cs WHERE cs.contract_id
   = contracts.id AND cs.site_id IN (…))` into the SQL, so paginated
   counts/results stay correct under narrowing (no post-filter drift).
5. `PortalBillingService` and `PortalWorkorderService` got an optional
   positional `?SiteAssetRepository $siteAssets = null` constructor arg
   (added at the **end** of the parameter list — backward-compatible for
   the existing positional callsite in `routes/modules/portal.php`).
   `loadScoped*` and `list*ForPortal()` now apply
   `allowsRowWithSite()` after the company check.
6. `PortalContractService::listForPortal()` forwards `allowed_site_ids`
   into `ContractRepository::search()`; `getForPortal()` re-checks via
   `listSiteIdsForContractIds([$contract->id])`.
7. Cross-site rejections re-use the existing `"…belongs to a different
   company"` message so a narrowed account cannot enumerate row IDs by
   diffing error responses.
8. `routes/modules/portal.php` now passes `$siteAssetRepo` as the 8th
   arg to `PortalBillingService` and the 3rd arg to
   `PortalWorkorderService`.

**Verification:** `php tests/PortalSiteScopingTest.php` — 11 cases. All
pass. Covers unscoped/scoped × list/get for all three services, the
NULL-site strict-drop, the multi-site ANY-match contract case, and that
unscoped accounts skip the `site_assets` lookup entirely.

**Breaking-shape risk:** A portal account with `allowed_site_ids` set
against a legacy auto-shop install (where `site_asset_id` is universally
NULL) will now see an empty list. Practical impact is zero — legacy
installs that have not adopted the multi-site schema run with
`allowed_site_ids = NULL` — but operators who flip a narrowing on
without backfilling `site_asset_id` will need to either backfill or
revert to unscoped. This is intentional; the alternative (silent
passthrough flag) was the original bug.

---

## R-06 — AUD-071: Per-domain encryption keys + versioned ciphertext envelope

**Source finding:** AUD-071 (security/low, open)

**Why deferred:** Pure refactor with no immediate exploit, but the cost
of a future key-rotation event is paid here. Today, `FieldCipher` has
**one** key (`SITE_CODES_ENCRYPTION_KEY`) covering two unrelated
domains (CRM site codes; integration credentials). A leak of the env
var compromises both.

**Recommended fix:**

1. Add `INTEGRATION_CREDENTIALS_ENCRYPTION_KEY` to `.env.example` and
   `config/auth.php` (or a new `config/crypto.php`). Update
   `IntegrationService` to construct `FieldCipher` with this key
   instead of the default.
2. Change the on-disk ciphertext envelope from `base64(nonce || ct)`
   to `base64(version_byte || key_id_u32 || nonce || ct)`. Version
   byte = 0x01; key_id = 4 bytes resolving to the env-key name via a
   tiny config map. Decrypt walks the version byte first to pick the
   parser.
3. Switch the AEAD primitive from `crypto_secretbox` (XSalsa20-Poly1305,
   no AAD support) to `crypto_aead_xchacha20poly1305_ietf_*` so a
   domain string ("site_codes" / "integration_credentials") can be
   bound as AAD. This makes cross-domain ciphertext substitution
   detectable on decrypt.
4. Add a one-shot migration `bin/crypto/rewrap_secrets.php` that walks
   all rows in `crm_site_security_codes`, `integration_credentials`,
   and any other table that uses the cipher, decrypts with the v0
   format using the legacy key, and re-encrypts with the v1 envelope
   using the appropriate per-domain key.

**Cost:** ~3 days, including the rewrap script and tests. The new
envelope is forward-compatible (v0 keeps decoding via the legacy path
for any rows the rewrap missed).

---

## Indexing back to the register

| ID | Status in register | This doc |
|----|--------------------|----------|
| AUD-063 | partially-resolved | R-01 |
| AUD-064 | partially-resolved | R-02 |
| AUD-065 | open | R-03 |
| AUD-066 | open | R-04 |
| AUD-067 | resolved | R-05 (shipped 2026-05-11) |
| AUD-071 | open | R-06 |

Findings AUD-068 (TOTP code reuse), AUD-069 (step-up session binding),
AUD-070 (silent tamper-swallow), and AUD-072 (leftmost X-Forwarded-For)
were fixed inline in Phase 2 and are not represented here.
