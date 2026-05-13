# Audit Summary

Current snapshot date: `2026-05-11`
Latest closeout: v2 (`2026-05-11`) — see [audit-v2-summary.md](audit-v2-summary.md)
Prior closeout: v1 (`2026-04-08`) — see [audit-closeout.md](audit-closeout.md)

## Current Status

Two audit cycles have completed against this repository:

- **v1** (closed 2026-04-08): six-phase pass producing 62 confirmed findings,
  all resolved at closeout. Covered the original codebase up to that date.
- **v2** (closed 2026-05-11): six-phase delta pass against the ~189k
  insertions / ~1.2k files added since the v1 closeout, plus two new categories
  (stale code identification, missing/stubbed UI inventory). Produced 15 new
  findings (AUD-063..AUD-077) and 11 UI gap entries (UIG-1..UIG-11). All
  15 findings now resolved or partially-resolved (R-01..R-06 shipped
  2026-05-11/12; AUD-077 cron lock + parallelism shipped 2026-05-13).

The CMS review work that began in v1 remains complete for findings confirmed
in this workspace.

## Findings Summary

Combined register in [audit-findings.md](audit-findings.md):

| Cycle | Total | Resolved | Partial | Open |
| ----- | ----: | -------: | ------: | ---: |
| v1    |    62 |       62 |       0 |    0 |
| v2    |    15 |       14 |       1 |    0 |
| **All** | **77** | **76** | **1** | **0** |

v1 by category — Security 8, Error handling 6, Performance 48.
v2 by category — Security 10 (9 resolved + 1 partial), Performance 5 (5 resolved).

### v2 closeout

All 15 v2 findings are now resolved or partially-resolved. The six R-NN
recommendations from `audit-v2-recommendations.md` shipped between
2026-05-11 and 2026-05-12 (R-01..R-06); AUD-077 (cron lock +
per-minute parallelism) shipped 2026-05-13 with `flock()`-based
locking, `proc_open` parallel dispatch capped at 4 concurrent jobs,
and per-job timeouts derived from each job's cron expression
(`App\Support\Cron\CronDispatcher`).

The single partially-resolved entry remains AUD-064 (multi-party
e-sign) where the immediate exploit window closed but the longer-term
architectural cleanup is tracked under R-02 (now also shipped end-to-end).

(AUD-063 voice-note upload pipeline shipped 2026-05-12 under R-01; AUD-067
portal site-scoping shipped 2026-05-11 under R-05's strict policy. See each
register entry for the corresponding breaking-shape note.)

R-XX recommendations are written up in
[audit-v2-recommendations.md](audit-v2-recommendations.md).

### UI gap backlog

11 user-visible gaps catalogued in [audit-v2-ui-gaps.md](audit-v2-ui-gaps.md):

- 4 Blocking gaps reachable from live UI (UIG-1..UIG-4)
- 3 Blocking gaps on public routes reachable only by direct URL (UIG-5..UIG-7)
- 4 Degraded gaps where partial functionality ships (UIG-8..UIG-11)

UI gaps are documentation only — no code changes shipped in Phase 5.

### Code hygiene

Phase 4 of v2 removed 16 zero-reference orphan service classes (~1,877 lines)
documented in [audit-v2-stale-code.md](audit-v2-stale-code.md).

## Verification Status

Verification is per-finding and recorded inline in the findings register on
each entry's `Verification:` line.

- All v2 fully-resolved findings cite a passing test in the same commit as
  the fix.
- v2 added two new test files: `tests/StepUpReplayDefenseTest.php` and
  `tests/EsignSingleUseTest.php` (both run successfully against in-memory
  SQLite mirroring the post-migration schema).
- The three v1-blocked database tests (2026-05-12 re-investigation + stop-point
  follow-up): `pdo_sqlite` is in fact installed here. The real blocker was
  MySQL-specific SQL functions and placeholder semantics in production
  code paths. `tests/test_bootstrap.php::registerMysqlCompatFunctions()`
  installs shims for `NOW()`, `GREATEST()`, and `LEAST()` as SQLite UDFs.
  `PaymentProcessingService` was rewritten to avoid PDO_MySQL-only behaviour
  (placeholder reuse, `ON DUPLICATE KEY UPDATE`). All three tests now pass:
  - [tests/AuthTokenRepositorySecurityTest.php](/var/www/phparm/tests/AuthTokenRepositorySecurityTest.php) — **passes**.
  - [tests/InvoiceManualPaymentConsistencyTest.php](/var/www/phparm/tests/InvoiceManualPaymentConsistencyTest.php) — **passes** after seeding `customer_id` in the fixture (the `App\Models\Invoice::$customer_id` declaration is `int`, not `?int`).
  - [tests/PaymentWebhookReconciliationTest.php](/var/www/phparm/tests/PaymentWebhookReconciliationTest.php) — **passes** after: (a) distinct placeholder names per occurrence in `recordWebhookEvent()`'s upsert + per-statement filtered bindings, (b) replacing the `ON DUPLICATE KEY UPDATE` in `storeCheckoutSession()` with a SELECT-then-INSERT/UPDATE pattern matching the file's existing convention, (c) test now mirrors the unmatched webhook into `payment_webhook_events` (the store-backed recovery path) in addition to `audit_logs` (legacy fallback).

## Residual Risks And Follow-Up

**v2 introduces three new migrations that must be applied before deploying
the v2 code** or the new defenses silently degrade:

- `database/migrations/183_*` — `auth_step_up_verifications.totp_counter`
  (UNIQUE for replay defense, AUD-068)
- `database/migrations/184_*` — `auth_step_up_verifications.session_fingerprint`
  (session binding, AUD-069)
- `database/migrations/185_public_link_single_use.sql` — `consumed_at` columns
  on `contract_public_links` + `estimate_public_links` (AUD-064)
- `database/migrations/187_public_link_signer_binding.sql` — `signer_email`
  + `signer_invitation_id` columns on `contract_public_links` +
  `estimate_public_links` (R-02b, AUD-064)
- `database/migrations/188_contract_signers.sql` — `contract_signers`
  roster table for the multi-party invitation flow (R-02c, AUD-064)

Plus the two v1 carry-over migrations (`097_payment_webhook_events.sql`,
`098_workorder_status_history_composite_index.sql`).

Other residuals from v1 still apply:

- Rotate or let expire older plaintext recovery / public tokens that predate
  the hashed-at-rest rollout.
- Confirm `TRUSTED_PROXIES`, `APP_URL`, payment webhook credentials in
  production.
- Operational cleanup outside the repo for retired CMS admin references.

Net new from v2:

- 6 security findings opened in v2 had a designed-out follow-up in
  `audit-v2-recommendations.md` (R-01..R-06). All six are now fully
  shipped end-to-end: R-01 / AUD-063 on 2026-05-12; R-05 / AUD-067 on
  2026-05-11; R-02 / AUD-064 on 2026-05-12 across four phases —
  R-02a (per-IP + per-link rate limiting on public sign links), R-02b
  (per-link signer-email binding, migration 187), R-02c
  (`contract_signers` roster + invitation service, migration 188), and
  R-02d (admin UI flip + bound-email display in the public sign view);
  R-03 / AUD-065 on 2026-05-12 (state-changing public endpoints
  require the long token, short codes demoted to read-only);
  R-04 / AUD-066 on 2026-05-12 (migration 189 — issue-time
  document hash + JSON snapshot on both contract and estimate public
  links, capture-time mismatch refusal with audited override path);
  and R-06 / AUD-071 on 2026-05-12 (per-domain encryption keys with a
  versioned `0x01 || key_id_u32 || nonce || ct` envelope under
  `crypto_aead_xchacha20poly1305_ietf` with the domain string bound as
  AAD; one-shot `bin/crypto/rewrap_secrets.php` migration script).
- One partially-resolved security finding (AUD-064) closed the immediate
  exploit window but defers the architectural cleanup (first-class
  multi-party signing + per-link rate limiting) to R-02 in the same
  recommendations doc.
- AUD-077 (cron runner lock + per-minute parallelism) shipped 2026-05-13:
  `flock()` replaces the timestamp-based lock so a crashed runner no
  longer blocks the next tick; due jobs dispatch via `proc_open()` in
  parallel through `App\Support\Cron\CronDispatcher` (default
  concurrency 4); each child gets a per-job timeout derived from its
  cron expression (50 s for `* * * * *`, `N*60-60 s` for `*/N`,
  1800 s for hourly+) with SIGTERM/SIGKILL escalation. Verified by
  `tests/CronDispatcherTest.php` (11/11). With this finding closed
  every remaining performance gap is profiling-led structural work
  as already noted in v1.

## Recommended Stop Point

This is a clean stop point.

If work resumes, the highest-value next steps are:

1. Remaining UI gaps (UIG-3..UIG-8, UIG-10, UIG-11).

UIG-1, UIG-2, UIG-9 closed 2026-05-12 (technician portal real view,
admin dashboard CTA hidden, `DELETE /api/divisions/{id}` shipped). All
three legacy v1 tests pass under PDO_SQLITE as of 2026-05-12. See
`audit-v2-ui-gaps.md` for UI gap status.

Beyond that, performance work should switch to production-traces-led
profiling rather than further static cleanup.

## Related Documents

### v2 (current cycle)

- [audit-v2-plan.md](audit-v2-plan.md)
- [audit-v2-baseline.md](audit-v2-baseline.md)
- [audit-v2-recommendations.md](audit-v2-recommendations.md)
- [audit-v2-stale-code.md](audit-v2-stale-code.md)
- [audit-v2-ui-gaps.md](audit-v2-ui-gaps.md)
- [audit-v2-summary.md](audit-v2-summary.md)

### v1 (prior cycle, 2026-04-08)

- [audit-plan.md](audit-plan.md)
- [phase1-baseline.md](phase1-baseline.md)
- [audit-closeout.md](audit-closeout.md)

### Combined

- [audit-findings.md](audit-findings.md) — register for both cycles
