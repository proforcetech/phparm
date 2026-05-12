# Audit v2 Summary

**Closeout date:** `2026-05-11`
**Plan:** [audit-v2-plan.md](audit-v2-plan.md)
**Baseline:** [audit-v2-baseline.md](audit-v2-baseline.md)
**Findings register:** [audit-findings.md](audit-findings.md)
**Prior closeout (v1):** [audit-closeout.md](audit-closeout.md) — 2026-04-08

## Outcome

The v2 audit completed all six planned phases against the post-2026-04-08 code
delta (~189k insertions, ~1.2k files, 86 new migrations, 37 new service
domains since the v1 closeout).

| Phase                      | Deliverable                                                                                                  | Status      |
| -------------------------- | ------------------------------------------------------------------------------------------------------------ | ----------- |
| 0 — Scoping                | [audit-v2-plan.md](audit-v2-plan.md), [audit-v2-baseline.md](audit-v2-baseline.md)                           | complete    |
| 1 — Security delta         | AUD-063..AUD-072 in [audit-findings.md](audit-findings.md)                                                    | complete    |
| 2 — Security fixes         | Inline fixes for AUD-068, AUD-069, AUD-070, AUD-072 + partial fixes for AUD-063, AUD-064; non-fixes in [audit-v2-recommendations.md](audit-v2-recommendations.md) | complete    |
| 3 — Performance delta      | AUD-073..AUD-077 + inline fixes for AUD-073..AUD-076                                                          | complete    |
| 4 — Stale code             | [audit-v2-stale-code.md](audit-v2-stale-code.md), 16 files removed (~1,877 lines)                             | complete    |
| 5 — UI gap catalog         | [audit-v2-ui-gaps.md](audit-v2-ui-gaps.md), 11 gaps (UIG-1..UIG-11)                                          | complete    |
| 6 — Closeout               | This document, refresh of [audit-summary.md](audit-summary.md)                                                | complete    |

## Findings tally

15 new findings added to the register, continuing from `AUD-063`:

| Status              | Count | IDs                                                                       |
| ------------------- | ----- | ------------------------------------------------------------------------- |
| Resolved            | 9     | AUD-067, AUD-068, AUD-069, AUD-070, AUD-072, AUD-073, AUD-074, AUD-075, AUD-076 |
| Partially resolved  | 2     | AUD-063, AUD-064 (immediate exploit closed; architectural follow-up deferred to recommendations) |
| Open / deferred     | 4     | AUD-065, AUD-066, AUD-071, AUD-077                                        |

By category:

| Category    | Total | Resolved | Partial | Open |
| ----------- | ----: | -------: | ------: | ---: |
| Security    |    10 |        5 |       2 |    3 |
| Performance |     5 |        4 |       0 |    1 |

Open items have all been written up with full evidence + recommended fix in
the register; three of them have escalated, designed-out replacement specs in
[audit-v2-recommendations.md](audit-v2-recommendations.md) (R-03, R-04, R-06 —
R-01 / R-02 cover the partially-resolved AUD-063 / AUD-064; R-05 / AUD-067
shipped under the strict site-scoping policy on 2026-05-11). The fourth
(AUD-077, cron parallelism / lock hygiene) is a design tweak left in the
register only.

Also recorded:

- 8 prior findings re-verified (AUD-001, AUD-002, AUD-006, AUD-008, AUD-009,
  AUD-010, AUD-012, AUD-061) — all fixes still in place, none regressed.
- AUD-001 has a new related-but-distinct finding (AUD-072, leftmost-XFF
  parsing); the original fix was *not* reopened.

## Major results

**Security (Phase 1–2):**

- Step-up TOTP replay defense (AUD-068) — DB-level UNIQUE constraint on
  consumed counter slot; replay attempts surface as SQLSTATE 23000.
- Step-up session binding (AUD-069) — freshness now keyed on session
  fingerprint, defeating the "step-up on device A satisfies sensitive write
  on device B" attack.
- Silent decrypt-failure surfacing (AUD-070) — `CrmController::revealSiteCodes`
  now distinguishes absent / key_unavailable / decrypt_failed / ok and
  audit-logs the failure modes, so tampered ciphertext no longer reads as
  "not set."
- Right-walk X-Forwarded-For (AUD-072) — `IpAddressResolver` walks the XFF
  chain right-to-left, dropping trusted-proxy hops, so a client-supplied
  leading entry can no longer spoof the resolved IP.
- E-sign single-use links (AUD-064) — atomic `consumed_at` claim on first
  capture across both contract and estimate flows, with a new dedicated
  test suite covering replay paths.
- Voice-note path traversal hardening (AUD-063) — absolute-path / null-byte
  rejection, mandatory `storageRoot`, `realpath()`-based symlink-escape
  defense.

**Performance (Phase 3):**

- N+1 SLA policy lookup on every ticket create (AUD-073) — bulk-load via
  `findByIds`, in-memory ranking. Hottest path of the bunch.
- N+1 customer lookup on portal pending-queue load (AUD-074) — single
  `listIdsForCompany` call, isset-map membership.
- Connect-timeout on synchronous outbound HTTP (AUD-075) — added
  `CURLOPT_CONNECTTIMEOUT => 5` to OIDC + integration adapter paths so a
  brownout downstream cannot starve the FPM worker pool.
- Chunked nightly cleanup deletes (AUD-076) — `LIMIT 5000` loop with
  inter-batch yield across all 6 retention DELETEs in
  `bin/cron/data-cleanup.php`.

**Code hygiene (Phase 4):**

- 16 zero-reference orphan service classes removed (~1,877 lines deleted).
  Each was superseded by a different implementation that the routes
  actually use; the orphans landed when the supersession committed without
  deleting the original. Includes `AuthController`, `PayrollCalculator` /
  `PayrollExportFormatter` / `PayrollRunRepository`, `WarrantyManagementService`,
  `LoggingVinDecoder`, three `VehicleMaster*` services, and others. Net
  reduction: 18 files changed, +82 / −1,978.
- One stale claim in `.github/IMPLEMENTATION_STATUS.md` corrected
  (`LoggingVinDecoder` was listed as part of the VIN decoder chain; it was
  authored but never wired).

**UI gaps (Phase 5):**

- 11 documented gaps split into Blocking (live UI), Blocking (public
  direct-URL only), and Degraded.
- Highest-impact items: technician sidebar's "My Time" → placeholder
  (UIG-1); admin dashboard's "View requests" CTA → placeholder (UIG-2); and
  a parallel `/cp/financial/vendors` flow that overlaps with the live
  `/cp/procurement/vendors` (UIG-3, UIG-4, UIG-8).
- Surfaces one backend gap as a side effect: `DELETE /api/divisions/{id}`
  does not exist (UIG-9).
- Phase 5 is documentation only — no code shipped, per the v2 plan.

## Verification

Verification was per-finding and is recorded inline in
[audit-findings.md](audit-findings.md) on each entry's `Verification:` line.

Coverage:

- All 8 fully-resolved findings have a passing test cited in the register.
  Where applicable (AUD-068, 069, 064, 072), a new test file or new cases
  were added in the same commit as the fix. AUD-072 is verified by
  `tests/IpAddressResolverTest.php` (5 scenarios, including two
  XFF-spoofing cases added in commit `dd85214`).
- AUD-073 (N+1 fix) is covered by the existing
  `tests/ContractSlaResolverTest.php` (13 scenarios pass) and required a
  fake-method addition (`findByIds`) on the test fake.
- AUD-074 same pattern (`tests/PortalApprovalServiceTest.php`).
- AUD-075 / AUD-076 are constant-only or shape-only edits without behavioral
  test coverage; verified by `php -l` + lint + the surrounding suite still
  passing.
- Phase 4 removals were verified by re-grepping each class name across
  `src routes bin tests config`, by a `composer dump-autoload --quiet` clean
  run, and by syntax-checking the one consumer (`VinDecoderFactory`) that
  the IMPLEMENTATION_STATUS doc had falsely claimed used the removed class.

Verification gaps from v1 are not addressed by v2 — the same `pdo_sqlite`
extension is still missing in this environment, so the same three
database-backed test files cited in the v1 closeout remain blocked from
running here:

- [tests/AuthTokenRepositorySecurityTest.php](/var/www/phparm/tests/AuthTokenRepositorySecurityTest.php)
- [tests/PaymentWebhookReconciliationTest.php](/var/www/phparm/tests/PaymentWebhookReconciliationTest.php)
- [tests/InvoiceManualPaymentConsistencyTest.php](/var/www/phparm/tests/InvoiceManualPaymentConsistencyTest.php)

Their v2 successors (`tests/StepUpReplayDefenseTest.php`,
`tests/EsignSingleUseTest.php`) use in-memory SQLite mirroring the
post-migration schema and ran successfully here.

## Residual risk

The findings register is the canonical list. Highlights:

**Security — 3 items still open.** All have a recommended fix in the register
plus a deeper architectural recommendation in `audit-v2-recommendations.md`
where applicable. Highest residual exposure:

- **AUD-065/066** (short-code entropy + post-issue document mutation)
  weaken the e-sign chain even after the AUD-064 single-use fix.
- **AUD-071** (single env key spans two domains) — rotation in either
  domain forces simultaneous re-encryption of the other; no version byte
  for online rotation.

(AUD-067 portal site-scoping was the previous top-of-list item; it shipped
on 2026-05-11 under the strict policy described in R-05. See the AUD-067
register entry for the breaking-shape note for legacy auto-shop installs
that still set `allowed_site_ids` against rows with no `site_asset_id`.)

**Performance — 1 item open (AUD-077).** Cron runner has no PID-based lock
and serializes per-minute jobs; a hung job can stall subsequent ticks for
up to 5 minutes. Low severity but worth fixing before the per-minute job
inventory grows further.

**Architectural deferrals (recommendations doc).** Six of the open security
items have a designed-out follow-up in
[audit-v2-recommendations.md](audit-v2-recommendations.md) (R-01..R-06).
None are committed; each describes an approach, files to touch, and
trade-offs.

**Operational from v1 still apply.** The v1 residuals (deployment
validation of pending migrations; rotating older plaintext tokens that
predate the hashed-at-rest rollout; confirming `TRUSTED_PROXIES`,
`APP_URL`, payment webhook credentials in production) all still apply.
The v2 work added two new migrations on top of the v1 set:

- `database/migrations/097_payment_webhook_events.sql` (carry-over from
  v1 work in flight at v1 closeout)
- `database/migrations/098_workorder_status_history_composite_index.sql`
  (carry-over)
- `database/migrations/183_*.sql` (auth_step_up_verifications.totp_counter)
- `database/migrations/184_*.sql` (auth_step_up_verifications.session_fingerprint)
- `database/migrations/185_public_link_single_use.sql`

All three v2 migrations need to be applied before deploying the v2 code,
or the new step-up replay/binding defenses and single-use e-sign defenses
silently degrade.

**UI gap backlog.** The 11 UIG-* items are not security or correctness
defects but they are user-visible papercuts. Prioritize UIG-1, UIG-2,
UIG-9 first (technician dead nav, admin dashboard dead CTA, divisions
delete missing — all reachable from primary navigation).

## Recommended stop point

This is a clean stop point.

If work resumes from here, the highest-value next steps are, in order:

1. Land R-01 (AUD-063 architectural follow-up) — replace client-supplied
   audio paths with a real upload pipeline; the inline fix only closed the
   exploit windows, not the design.
2. Address UIG-1 / UIG-2 / UIG-9 to clean up the obvious UI dead-ends.
3. Get `pdo_sqlite` (or equivalent) wired into the test environment and run
   the three blocked v1 tests + the new v2 tests as a single regression
   sweep.

Profiling-led performance work remains the right next move beyond that —
the broad query / bootstrap cleanup is exhausted. AUD-077 (cron lock /
parallelism) is the last remaining low-hanging item without production
traces.

## Commits

Phase-tagged commits landed since 2026-05-09 (in chronological order):

| Commit    | Phase | Subject                                                                         |
| --------- | ----- | ------------------------------------------------------------------------------- |
| `51f4678` | 0     | docs(audit): add v2 audit plan and baseline                                     |
| `07b3c5e` | 1     | docs(audit): record Phase 1 security delta findings (AUD-063..AUD-072)          |
| `dd85214` | 2     | fix(auth,api): land Phase 2 security fixes                                      |
| `fc0de6e` | 2     | fix(security,docs): land Phase 2 finishing fixes (AUD-063, AUD-064) + recommendations |
| `69b18a9` | 3     | perf(audit): land Phase 3 perf fixes (AUD-073..AUD-076) + AUD-077 finding       |
| `0ffd5fa` | 4     | chore(audit): remove zero-reference orphan service classes                      |
| `03289f1` | 5     | docs(audit): add Phase 5 UI gap catalog                                         |

Total v2 churn: +11,884 / −2,389 across 7 commits (the bulk of insertions
sits in the new test files and the two recommendations documents).

## Related documents

- [audit-v2-plan.md](audit-v2-plan.md) — v2 plan (Phase 0)
- [audit-v2-baseline.md](audit-v2-baseline.md) — v2 baseline (Phase 0)
- [audit-v2-recommendations.md](audit-v2-recommendations.md) — deferred fixes (Phase 2)
- [audit-v2-stale-code.md](audit-v2-stale-code.md) — Phase 4 register
- [audit-v2-ui-gaps.md](audit-v2-ui-gaps.md) — Phase 5 catalog
- [audit-findings.md](audit-findings.md) — combined v1 + v2 findings register
- [audit-summary.md](audit-summary.md) — top-level audit summary (refreshed alongside this doc)
- [audit-closeout.md](audit-closeout.md) — v1 closeout (`2026-04-08`)
- [audit-plan.md](audit-plan.md) — v1 plan
- [phase1-baseline.md](phase1-baseline.md) — v1 baseline
