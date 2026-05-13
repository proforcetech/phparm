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

## R-02 — AUD-064: First-class multi-party signing + per-link rate limiting ✅ Shipped 2026-05-12 (all phases)

**Source finding:** AUD-064 (security/high, partially-resolved)

**Phase R-02a — Per-IP + per-link rate limiting (shipped 2026-05-12)**

`src/Support/Security/PublicLinkRateLimiter.php` wires a dual-bucket
limiter (30/min per IP, 10/min per link) onto every `/api/public/contract/*`
endpoint, every `/api/public/estimate/*` endpoint, and the short-code
redirects `/e/{shortCode}`. Buckets are keyed by SHA-256 hash of the
identifier so raw tokens never land on disk in `storage/temp/ratelimits/`.
429 responses carry `Retry-After`, `retry_after` (seconds), and a `reason`
field (`ip`, `link`, or `ip_and_link`) for alert correlation. Audit
logger emits a `public_link.rate_limited` event when either bucket trips.
Coverage: `tests/PublicLinkRateLimiterTest.php` (7 scenarios). Phases
R-02c and R-02d (multi-party flow + admin UI) remain open below.

**Phase R-02b — Per-link signer email binding (shipped 2026-05-12)**

Migration `187_public_link_signer_binding.sql` adds `signer_email
VARCHAR(160) NULL` and `signer_invitation_id INT UNSIGNED NULL` to both
`contract_public_links` and `estimate_public_links` (indexed on
`signer_email` for the future invitation-lookup join in R-02c). NULL
preserves legacy "open link" semantics so in-flight links keep working.

`ContractSigningService::issueLink()` and
`EstimatePublicLinkService::issueLink()` accept the two new optional
parameters and store the email after `trim` + `mb_strtolower` so casing
differences across the invite vs. capture sides cannot bypass the bind.
The matching route handlers (`POST /api/contracts/{id}/links`,
`POST /api/estimates/{id}/share/link`) pass through `signer_email` /
`signer_invitation_id` from the request body.

`captureSignature()` on both services adds an enforcement block that
runs **after** the single-use claim succeeds (so a mismatch never burns
the link — the bound signer can still complete the flow). The block
compares the link's stored `signer_email` against the capture payload's
declared email. Missing or mismatched emails emit a `contract.signer_mismatch`
or `estimate.signer_mismatch` audit event with `expected_signer_email`,
`attempted_signer_email`, `reason`, `link_id`, and `ip_address` in the
context — wired for downstream alerting.

Coverage: `tests/SignerEmailBindingTest.php` (18 scenarios — contract
fakes + estimate sqlite). R-02c (`contract_signers` table + invitation
service) is the next phase below.

**Phase R-02c — Contract signers roster + invitation service (shipped 2026-05-12)**

Migration `188_contract_signers.sql` introduces a first-class
`contract_signers` table keyed by `(contract_id, email)` with a
`display_order` column, `invited_at` / `invited_by_user_id` metadata,
`revoked_at` (so a re-invite leaves an audit trail), and
`signed_signature_id` + `signed_at` for the back-reference once a
signer completes capture. Foreign keys cascade-delete on contract
removal and SET NULL on signature removal. Indexed on
`(contract_id, display_order, id)` for the list view and on `email` for
the dedupe lookup.

`App\Models\ContractSigner` exposes a derived `status()` (`revoked`,
`signed`, or `invited`) that downstream UI binds to so the active
invariant logic stays in the model.
`App\Services\Contracts\ContractSignerRepository` is the standard PDO
repo (full-column SELECT, `findActiveByEmail` for dedupe,
`nextDisplayOrder` via `COALESCE(MAX, -1) + 1`, idempotent `markSigned`
via `WHERE signed_signature_id IS NULL`).

`App\Services\Contracts\ContractSignerService` is the public surface:

- `invite(User, contractId, baseUrl, payload)` — validates the email
  (`FILTER_VALIDATE_EMAIL` after `trim` + `mb_strtolower`), refuses
  active duplicates at the service layer (MySQL can't express a partial
  unique index on `(contract_id, email) WHERE revoked_at IS NULL`
  without generated-column gymnastics), inserts the signer row first so
  the resulting id can be stamped into the bound public link's
  `signer_invitation_id`, then calls
  `ContractSigningService::issueLink()` with both `signer_email` and
  `signer_invitation_id` populated. The email dispatch is best-effort —
  a mailer outage is captured in `email_error` and reported in the
  response payload, but does not abort the invitation. Emits
  `contract.signer_invited` audit.
- `revoke(User, contractId, signerId)` — refuses if the signer has
  already signed (signature is permanent), idempotent if already
  revoked, and cascades to revoke every non-revoked, non-consumed
  public link bound to the signer. Emits `contract.signer_revoked`.
- `markSignerCompleted(signerId, signatureId, signedAt)` — called by
  `ContractSigningService::captureSignature()` via late-bound setter
  injection (resolves the natural circular dep: signer service depends
  on signing service to issue links; signing service needs to call
  back into signer service to stamp the row after capture). Idempotent
  at the repo level. Emits `contract.signer_signed`.

Three new routes wired in `routes/modules/contracts.php`:
`GET /api/contracts/{id}/signers` (list, includeRevoked from query),
`POST /api/contracts/{id}/signers` (invite), `DELETE
/api/contracts/{id}/signers/{signerId}` (revoke). All gated by the
existing `contracts.view` / `contracts.sign` permissions.

Notification template `contract.signer_invitation` added to
`config/notifications.php` — uses plain `{{var}}` substitution (the
project's `TemplateEngine` is not Mustache, so the service
pre-formats `expires_note` into a flat string rather than using
section blocks).

Coverage: `tests/ContractSignerServiceTest.php` (14 scenarios with
sqlite + real repos + a `CapturingMailer` fake extending
`NotificationDispatcher`). Covers the invite happy-path, email
normalization, dedupe, multi-signer ordering, capture stamping the
signer row, status derivation, revoke cascade to the bound link, re-
invite after revoke, the signed-permanence guard, cancelled-contract
rejection, mailer-failure tolerance, invalid-email rejection without
orphan rows, `send_email=false` skip, and that a mismatched capture
does not mark the signer signed.

**Phase R-02d — Admin UI flip + public sign view bound-email display (shipped 2026-05-12)**

`src/services/contracts.service.js` exposes `listSigners`, `inviteSigner`,
and `revokeSigner` against the R-02c routes.

`src/react/views/contracts/ContractDetail.jsx` Signing tab gains a new
top-of-card **Signers** panel (one row per signer with order, name,
email, invited/signed timestamps, status badge, and Revoke for invited
rows). The legacy **Signing Links** panel is renamed *Legacy Signing
Links*, demoted to a ghost button (&ldquo;Issue Open Link&rdquo;), gains a
new *Bound To* column showing the bound `signer_email` or *open*, and
a new *used* status for consumed links. The Invite Signer modal
captures name + email + optional title/expires/notes/send_email and
returns a one-time *Signer Invited* modal exposing both the short and
secure sign URLs for manual share when the email channel is down.

`src/react/views/public/PublicContractSign.jsx` reads the new
`signer_email` field on the link payload (now surfaced by the
`/api/public/contract/view` and `/api/public/contract/by-code/{shortCode}`
routes), pre-fills the Email field with the bound address, locks it
read-only, marks it required, and renders a *This signing link is
bound to &lt;email&gt;* info banner above the signature card. Capture-time
mismatch enforcement was already shipped in R-02b — the UI changes
here close the social-engineering loop so a well-meaning customer
can't accidentally burn an attempt against the bind by retyping a
different address.

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

## R-03 — AUD-065: Replace 10-character short codes for state-changing endpoints ✅ Shipped 2026-05-12

**Source finding:** AUD-065 (security/medium) — **resolved**.

**What shipped (Approach 1 — demote short codes):**

1. The four state-changing public endpoints —
   `POST /api/public/estimate/approve-job`,
   `POST /api/public/estimate/reject-job`,
   `POST /api/public/estimate/signature`, and
   `POST /api/public/contract/sign` — now require the long `token`
   (256 bits via `random_bytes(32)`, sha256-hashed at rest). They
   reject requests that present only a `short_code`. The error
   message ("Token … is required") is identical for missing-token
   and short-code-only cases so that an attacker cannot tell
   whether a short code was previously valid.
2. Read-only paths —
   `GET /api/public/estimate` (token), `GET /api/public/estimate/by-code/{shortCode}`,
   `GET /api/public/contract/view`, `GET /api/public/contract/by-code/{shortCode}`,
   `GET /e/{shortCode}`, and `GET /c/{shortCode}` — continue to accept
   short codes. They do not mutate state and remain useful for the
   "type the URL by hand from a printed estimate" flow that motivated
   short codes originally.
3. Public link rate limiting (R-02a, ≤30 req/min per IP and ≤10
   req/link) already covers the read-only short-code routes, so the
   brute-force surface against the lookup oracle is bounded.
4. Frontend (`PublicEstimateView.jsx`, `PublicContractSign.jsx`) no
   longer attaches `short_code` to the state-change payloads. If a
   short-code-only session attempts to act, the UI surfaces a clear
   message asking the user to open the original signing link from
   their notification.

**Verification:** existing PHP regression tests
(`ContractSigningServiceTest`, `EsignSingleUseTest`,
`SignerEmailBindingTest`, `ContractSignerServiceTest`,
`PublicLinkRateLimiterTest`) still pass. The state-change routes are
HTTP-layer and not unit-tested independently; the sign service tests
cover the underlying capture path with `null` short_code and have
always asserted that flow.

**Why Approach 1 over Approach 2:** field telemetry showed users
overwhelmingly click the email link (long token) rather than typing
short codes by hand — the printed-estimate use case was rare. Removing
the mutation surface is a clean reduction in attack surface with zero
storage cost (no migration to re-issue short codes).

---

## R-04 — AUD-066: Snapshot document hash at link-issue time, not at capture time ✅ Shipped 2026-05-12

**Source finding:** AUD-066 (security/medium) — **resolved**.

**What shipped:**

1. Migration `189_public_link_document_snapshot.sql` adds
   `document_hash_at_issue CHAR(64) NULL` and
   `document_snapshot_json MEDIUMTEXT NULL` to both
   `contract_public_links` and `estimate_public_links`, and adds
   `document_hash_at_issue CHAR(64) NULL` plus
   `document_changed_accepted TINYINT(1) NOT NULL DEFAULT 0` to both
   `contract_signatures` and `estimate_signatures`. Columns are
   nullable so links issued before the migration retain pre-R-04
   behaviour (no enforcement, zero storage overhead).
2. `ContractSigningService::issueLink()` and
   `EstimatePublicLinkService::issueLink()` now load the entity at
   issue time, canonicalize it (strip `updated_at`/`created_at`,
   ksort, JSON encode), hash it, and persist both the hash and the
   full JSON snapshot on the link row. The hash is also stamped onto
   the `contract.link_issued` / `estimate.public_link_created` audit.
3. Both `captureSignature()` paths verify the issue-time hash against
   a freshly computed capture-time hash via `hash_equals()`. On
   mismatch the default behaviour is to **refuse** the capture with a
   clear error message; the refusal is audited as
   `contract.document_changed_refused` /
   `estimate.document_changed_refused` and **does not** consume the
   single-use link, so a re-issued link or override attempt remains
   possible.
4. The override path requires the caller to set
   `accept_document_changes=true` in the payload. When honoured, the
   override is audited as `contract.document_changed_accepted` /
   `estimate.document_changed_accepted` (carrying both hashes), and
   the resulting signature row stamps both
   `document_hash_at_issue` + `document_hash` plus
   `document_changed_accepted=1` for forensic reconstruction.
5. The internal/in-shop signing path (`captureInternalSignature`)
   intentionally bypasses R-04 — it does not flow through a public
   link, so there is no issue-time hash to compare against and the
   actor is already authenticated.
6. Public route handlers (`/api/public/contract/sign`,
   `/api/public/estimate/signature`) thread
   `accept_document_changes` through from the request body.

**Verification:** `php tests/ContractSigningServiceTest.php` adds four
R-04 cases — issue-time stamping, default refusal, refusal-audit
content, override path stamping the row, and clean-sign hash equality.
All 28 cases pass. The pre-existing `EsignSingleUseTest`,
`SignerEmailBindingTest`, `ContractSignerServiceTest`, and
`PublicLinkRateLimiterTest` regressions still pass against the
extended schema.

**UI scope:** displaying a "the document changed since this link was
sent" banner with the override checkbox in the public sign view is a
follow-up; the backend already refuses by default, so the security
property holds even without the UI work.

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

**Source finding:** AUD-071 (security/low, resolved)

**Status:** ✅ Shipped 2026-05-12

**What changed:**

1. `App\Support\Crypto\FieldCipher` was rewritten to support two
   on-disk envelope formats. New writes always emit **v1**:
   `base64( 0x01 || key_id_u32 || nonce[24] || ct )`. The
   primitive is now `crypto_aead_xchacha20poly1305_ietf_*` with the
   domain string ("site_codes" / "integration_credentials") bound as
   AAD, so a v1 ciphertext sealed under one domain cannot be opened
   under another — the AEAD fails authentication. Legacy **v0**
   ciphertext (`base64(nonce || ct)` under `crypto_secretbox`) keeps
   decrypting through a fall-through path so existing rows continue
   working until the rewrap script upgrades them.
2. `INTEGRATION_CREDENTIALS_ENCRYPTION_KEY` is the new per-domain key
   for third-party credentials; `SITE_CODES_ENCRYPTION_KEY` retains
   its existing role for alarm/gate codes. Both are documented in
   `.env.example` with a generation hint. A code-resident registry
   (`FieldCipher::KEY_REGISTRY`) maps the embedded `key_id_u32` to its
   env-var name, so a future rotation can ship a new id alongside the
   old one without touching column shapes.
3. All four `FieldCipher` construction sites now pass an explicit
   domain: `routes/modules/crm.php` and `bin/cron/integration-sync.php`
   for production paths, plus `routes/modules/integrations.php` and
   `tests/IntegrationsTest.php` (which seeds both keys with
   deterministic bytes for reproducible failures).
4. Backward-compat softening: an operator who has only the legacy
   `SITE_CODES_ENCRYPTION_KEY` still gets working integration-credential
   reads (the integrations domain transparently falls back to the legacy
   key for v0 decrypt). New writes still require the per-domain key, so
   the missing key surfaces loudly the first time an integration is
   registered.
5. `bin/crypto/rewrap_secrets.php` is the one-shot migration. It walks
   `sites.alarm_code_encrypted`, `sites.gate_code_encrypted`, and
   `third_party_integrations.credentials`, detects rows already on v1
   under the current key id (leading-byte + key_id check, no decrypt
   needed) and skips them, decrypts everything else through the format-
   detecting `FieldCipher::decrypt()`, then re-encrypts under the v1
   envelope keyed by the row's per-domain key. Defaults to dry-run;
   `--apply` writes the upgrades. Idempotent — safe to re-run after a
   partial failure.

**Verification:** `tests/IntegrationsTest.php` continues to pass end-to-end
(25/25) against the rewritten cipher. `tests/FieldCipherTest.php` is new
and pins the round-trip, legacy v0 fallback (including the ambiguous
0x01-leading-byte case), AAD-bound cross-domain isolation, tamper
rejection, and rewrap idempotency-detector behaviours (12/12).

---

## Indexing back to the register

| ID | Status in register | This doc |
|----|--------------------|----------|
| AUD-063 | resolved | R-01 (shipped 2026-05-12) |
| AUD-064 | partially-resolved | R-02 |
| AUD-065 | resolved | R-03 (shipped 2026-05-12) |
| AUD-066 | resolved | R-04 (shipped 2026-05-12) |
| AUD-067 | resolved | R-05 (shipped 2026-05-11) |
| AUD-071 | resolved | R-06 (shipped 2026-05-12) |

Findings AUD-068 (TOTP code reuse), AUD-069 (step-up session binding),
AUD-070 (silent tamper-swallow), and AUD-072 (leftmost X-Forwarded-For)
were fixed inline in Phase 2 and are not represented here.
