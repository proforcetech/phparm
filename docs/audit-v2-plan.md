# Audit v2 Plan: Delta + Stale Code + UI Gaps

Plan date: `2026-05-10`
Prior audit closeout: `2026-04-08` (see [audit-closeout.md](/var/www/phparm/docs/audit-closeout.md))

## Why v2

The prior audit closed with 62 confirmed findings, all resolved. In the four
weeks since, the codebase has roughly doubled (~189k insertions across ~1.2k
files), with 86 new migrations and 37 new service domains added. That changes
the audit posture from "review in place" to "audit a moving target" and adds
two new categories the prior pass did not cover:

- Stale code identification and safe removal
- Missing or stubbed UI inventory

## Scope

In scope:

- Security review of code added or substantively changed since 2026-04-08
- Performance review of the same delta (not a re-run of the prior sweep)
- Re-verification of prior findings whose locations have since been touched
- Stale code: dead methods, orphaned routes, unreachable React components
- Missing or stubbed UI: TODO markers, placeholder pages, no-op handlers,
  forms that never POST, API endpoints with no UI consumer

Out of scope:

- Re-running the entire 2026-04-08 audit checklist on unchanged code
- Production traffic profiling (environment-dependent, separate effort)
- Building new UI for missing/stubbed items (Phase 5 produces a backlog only)
- External `cms-php` tree (not present in this workspace)
- WOMS phase docs and `phparm.sql` left untracked by the user (out of scope
  per prior instruction)

## Deliverables per phase

| Phase | Deliverables |
|-------|--------------|
| 0 — Scoping | `audit-v2-plan.md`, `audit-v2-baseline.md` (this commit) |
| 1 — Security delta | New `AUD-063+` entries appended to `audit-findings.md` |
| 2 — Security fixes | Commits tagged with finding IDs; `audit-v2-recommendations.md` for non-fix items |
| 3 — Performance delta | Findings + commits |
| 4 — Stale code | `audit-v2-stale-code.md`, removal commits |
| 5 — UI gap catalog | `audit-v2-ui-gaps.md` (documentation only) |
| 6 — Closeout | `audit-v2-summary.md`, refresh of `audit-summary.md` |

## Findings register conventions

- Continue numbering from `AUD-063`. Do not start a new register.
- Each new finding cites `file:line` exactly as before.
- Re-verification of a prior finding does NOT get a new ID. Update the prior
  entry with a `Re-verified:` line and the date.
- New `Category:` value `stale_code` is allowed (prior audit only used
  security, performance, error_handling).
- New `Category:` value `ui_gap` is allowed but only used in
  `audit-v2-ui-gaps.md`, not in the main register (UI gaps are a backlog,
  not security/correctness defects).

## Decision rules

**Fix vs. suggest** (Phase 2):
- Fix in this audit: security/critical, security/high, error_handling/high,
  any change ≤30 lines and ≤medium severity.
- Suggest in `audit-v2-recommendations.md`: anything requiring a multi-day
  rewrite, anything that depends on infra/env changes, anything with
  ambiguous business impact.

**Stale code removal** (Phase 4):
- Auto-remove: zero-reference internal helpers, dead branches inside live
  methods, commented-out blocks older than 90 days.
- Confirm before removing: anything externally callable (routes, public
  service methods consumed by routes/api.php or routes/cms.php, React routes
  bookmarkable by users), CMS templates, anything in `database/migrations/`.
- Never remove: migrations, seeds, anything under `tests/`.

**UI gap severity**:
- Blocking — feature is registered in routing/menus but cannot be used at all.
- Degraded — feature works but obvious capability missing (e.g. read-only
  list with no detail view, no edit, no empty state).
- Cosmetic — placeholder copy, missing icons, alignment issues.

## Sequencing

Phases run in order. Do not start Phase 2 until the Phase 1 register is
written; do not start Phase 5 until Phase 4 has identified orphaned routes
(many UI gaps will turn out to be the same code as orphaned route handlers).

## Stop points

Each phase is committable on its own. The user can pause after any phase
without leaving the repo in a half-finished state.

## Related documents

- [audit-plan.md](/var/www/phparm/docs/audit-plan.md) — original v1 plan
- [audit-findings.md](/var/www/phparm/docs/audit-findings.md) — register (continues here)
- [audit-summary.md](/var/www/phparm/docs/audit-summary.md) — v1 summary
- [audit-closeout.md](/var/www/phparm/docs/audit-closeout.md) — v1 closeout
- [phase1-baseline.md](/var/www/phparm/docs/phase1-baseline.md) — v1 baseline
- [audit-v2-baseline.md](/var/www/phparm/docs/audit-v2-baseline.md) — v2 baseline (this Phase 0)
