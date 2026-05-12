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
  findings (AUD-063..AUD-077) and 11 UI gap entries (UIG-1..UIG-11). 10 of 15
  findings resolved or partially-resolved; 5 deferred to the recommendations
  doc or carried open in the register.

The CMS review work that began in v1 remains complete for findings confirmed
in this workspace.

## Findings Summary

Combined register in [audit-findings.md](audit-findings.md):

| Cycle | Total | Resolved | Partial | Open |
| ----- | ----: | -------: | ------: | ---: |
| v1    |    62 |       62 |       0 |    0 |
| v2    |    15 |        8 |       2 |    5 |
| **All** | **77** | **70** | **2** | **5** |

v1 by category — Security 8, Error handling 6, Performance 48.
v2 by category — Security 10 (4 resolved + 2 partial), Performance 5 (4 resolved).

### Open v2 findings (deferred or designed-out)

- **AUD-065** — short-code entropy on public e-sign links (~40 bits) — `R-03`
- **AUD-066** — document hash computed at sign-time, not issue-time — `R-04`
- **AUD-067** — portal services miss site-scope filtering for billing /
  contracts / workorders — `R-05`
- **AUD-071** — single env key spans CRM site-codes + integration credentials —
  `R-06`
- **AUD-077** — cron runner serializes per-minute jobs and uses a stale-prone
  file lock — register only

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
- The three v1-blocked database tests are still blocked here for the same
  reason — the `pdo_sqlite` extension is not available in this environment:
  - [tests/AuthTokenRepositorySecurityTest.php](/var/www/phparm/tests/AuthTokenRepositorySecurityTest.php)
  - [tests/PaymentWebhookReconciliationTest.php](/var/www/phparm/tests/PaymentWebhookReconciliationTest.php)
  - [tests/InvoiceManualPaymentConsistencyTest.php](/var/www/phparm/tests/InvoiceManualPaymentConsistencyTest.php)

## Residual Risks And Follow-Up

**v2 introduces three new migrations that must be applied before deploying
the v2 code** or the new defenses silently degrade:

- `database/migrations/183_*` — `auth_step_up_verifications.totp_counter`
  (UNIQUE for replay defense, AUD-068)
- `database/migrations/184_*` — `auth_step_up_verifications.session_fingerprint`
  (session binding, AUD-069)
- `database/migrations/185_public_link_single_use.sql` — `consumed_at` columns
  on `contract_public_links` + `estimate_public_links` (AUD-064)

Plus the two v1 carry-over migrations (`097_payment_webhook_events.sql`,
`098_workorder_status_history_composite_index.sql`).

Other residuals from v1 still apply:

- Rotate or let expire older plaintext recovery / public tokens that predate
  the hashed-at-rest rollout.
- Confirm `TRUSTED_PROXIES`, `APP_URL`, payment webhook credentials in
  production.
- Operational cleanup outside the repo for retired CMS admin references.

Net new from v2:

- 6 open security findings have a designed-out follow-up in
  `audit-v2-recommendations.md` (R-01..R-06). None are committed.
- The two partially-resolved security findings (AUD-063, AUD-064) closed
  the immediate exploit windows but defer the architectural cleanup to the
  same recommendations doc.
- The cron lock / per-minute parallelism issue (AUD-077) is the last
  low-cost performance item; everything else is profiling-led structural
  work as already noted in v1.

## Recommended Stop Point

This is a clean stop point.

If work resumes, the highest-value next steps are:

1. Implement R-05 (AUD-067, portal site-scoping) — only open finding that
   permits cross-site data exposure.
2. Land R-01 (AUD-063 architectural follow-up) — replace client-supplied
   audio paths with a real upload pipeline.
3. Address UIG-1 / UIG-2 / UIG-9 to clean up the obvious UI dead-ends
   (technician sidebar, admin dashboard CTA, divisions delete).
4. Wire `pdo_sqlite` (or equivalent) into the test environment and run the
   three blocked v1 tests + the new v2 tests as one regression sweep.

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
