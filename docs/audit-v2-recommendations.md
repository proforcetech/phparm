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

## R-01 — AUD-063: Replace client-supplied `audio_path` with server-managed multipart upload ✅ Shipped 2026-05-12

**Source finding:** AUD-063 (security/medium) — **resolved**.

**What Phase 2 had hardened:** The immediate exploit surface only.
`HeuristicTranscriber` already required a non-empty storage root, refused
`..` / null bytes / absolute paths, and verified containment via
`realpath()`. `VoiceNoteService::record()` already rejected absolute paths
and null bytes. But the route still accepted `audio_path` as a
**client-supplied relative string**, so an authenticated
`voice_notes.create` actor could still pick any sub-path inside the root
and collide with another user's notes — an integrity issue, and the
path-as-input shape was fundamentally the wrong contract.

**What shipped:**

1. **Schema (migration 186):** added `audio_mime VARCHAR(64) NULL` (after
   `audio_format`) and `audio_sha256_hash CHAR(64) NULL` (after
   `audio_size_bytes`), plus a non-unique `idx_vn_audio_sha` for future
   dedupe scans. Both nullable so pre-R-01 rows continue to load. We
   chose to keep the existing `audio_format` column (rather than rename
   to `audio_mime` outright) and reuse `audio_size_bytes` (rather than
   introduce `audio_bytes_size` as the original recommendation
   suggested) — minimal-churn schema that doesn't break the read path.
2. **`App\Support\Ulid`:** 26-char Crockford-base32 ULID generator with
   monotonic-within-millisecond guarantee. Replaces UUIDv4 for the
   filename component because ULIDs sort by creation time (cheap
   directory walks, naturally clustered backlog scans).
3. **`config/voice_notes.php`:** `max_upload_bytes` (default 25 MB,
   override via `VOICE_NOTES_MAX_UPLOAD_BYTES`), `storage_root`
   (override via `VOICE_NOTES_STORAGE_ROOT`), and the
   `allowed_mime_types` map (sniffed-mime → extension).
4. **`VoiceNoteUploadService`:** single ingest pipeline — validates the
   `$_FILES` shape (presence, error code, non-empty, under cap), sniffs
   MIME via `finfo_buffer` on the first 2 KiB (the client's `type`
   field is entirely ignored), rejects anything outside the allowlist,
   computes sha256, generates
   `{yyyy}/{mm}/{user_id}/{ulid}.{ext}` where `{ext}` comes from the
   sniffed MIME (not the uploaded filename), and `move_uploaded_file()`s
   the tmp file into place. A second method, `resolveStoredFile()`,
   resolves a stored relative path back to an absolute one with a
   realpath-containment check so the streaming endpoint can't be
   tricked into serving `/etc/passwd` via a planted symlink.
5. **`VoiceNoteService::record()` signature changed** from
   `record($actor, $payload)` to
   `record($actor, $upload, $payload)`. Two new server-side guards:
   `assertNoClientStoragePayload()` rejects any client payload still
   carrying `audio_path`, `audio_mime`, `audio_size_bytes`,
   `audio_sha256_hash`, or `audio_format` (loud
   `InvalidArgumentException` — no silent bypass);
   `assertUploadShape()` enforces that the upload struct has all six
   required keys and a 64-char hex sha256.
6. **`VoiceNoteController::recordNote()` signature changed** to
   `(User $actor, array $file, array $payload)`. The route layer
   extracts `$_FILES['audio']` and returns 400 if missing.
   `getNote()` now synthesises an `audio_url => /api/voice-notes/{id}/audio`
   field so the React detail modal binds to a stable, auth-gated stream
   URL (paths never leak to the client).
7. **`GET /api/voice-notes/{id}/audio`:** new streaming endpoint behind
   `voice_notes.view`. Returns the bytes with `Cache-Control:
   private, max-age=0, no-store`, `X-Content-Type-Options: nosniff`, and
   a `Content-Disposition: inline; filename="voice-note-{id}.{ext}"`.
   No Range support in this pass — field recordings under 25 MB stream
   fine over a single response; we can add 206 handling when the React
   UI starts needing seek.

**Verification:** `php tests/VoiceNoteServiceTest.php` (61 cases — model
+ repo + tag repo + service + controller + permission gates, all on the
new upload struct) and `php tests/VoiceNoteUploadServiceTest.php`
(22 cases — happy path, MIME rejection across text / junk-octets /
spoofed-content-type, size cap, empty-file, missing-tmp_name,
upload-error code, authorless actor, resolveStoredFile containment +
symlink escape, original-name sanitisation, ULID uniqueness). Both
green.

**Breaking-shape risk:** Any external client that still POSTs JSON with
an `audio_path` field will now get a 400 — that's intentional, the
write-only-by-server contract is the point. The web/mobile recorder UI
was already sending multipart with an `audio` file part, so the
in-tree clients keep working unchanged.

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
| AUD-063 | resolved | R-01 (shipped 2026-05-12) |
| AUD-064 | partially-resolved | R-02 |
| AUD-065 | open | R-03 |
| AUD-066 | open | R-04 |
| AUD-067 | resolved | R-05 (shipped 2026-05-11) |
| AUD-071 | open | R-06 |

Findings AUD-068 (TOTP code reuse), AUD-069 (step-up session binding),
AUD-070 (silent tamper-swallow), and AUD-072 (leftmost X-Forwarded-For)
were fixed inline in Phase 2 and are not represented here.
