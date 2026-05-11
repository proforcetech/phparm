# Audit v2 — Phase 4: Stale Code Register

**Date:** 2026-05-11
**Plan:** [audit-v2-plan.md](audit-v2-plan.md)
**Findings register:** [audit-findings.md](audit-findings.md)
**Recommendations:** [audit-v2-recommendations.md](audit-v2-recommendations.md)

## Scope

Phase 4 of the v2 audit identifies code that is shipped in the repository but
has no live caller — neither another PHP class, nor a route handler, nor a
test, nor a config / docs reference. Per the v2 plan, the following classes
qualify for auto-removal:

> Auto-remove: zero-reference internal helpers, dead branches inside live
> methods, commented-out blocks older than 90 days.

Each entry below was verified by:
1. `grep -rln "<ClassName>\b" src routes bin tests config` returning only the
   file itself (or an unrelated substring match — noted where applicable).
2. Confirming there is no parallel React or routed entry point that loads it
   dynamically.
3. Confirming there is no test under `tests/` exercising it.
4. `git log --diff-filter=A` confirming the file was added more than 90 days
   ago and has no recent commits introducing new callers.

The classes below were superseded by a different implementation (noted in the
"Replaced by" column) that is the one actually wired into routes / called from
other services. They sat orphan because the supersession landed without
deleting the original.

## Removed in this phase

| File                                                          | Lines | Added      | Replaced by / notes                                                                                                                                  |
| ------------------------------------------------------------- | ----: | ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Services/Auth/AuthController.php`                        |   329 | 2025-12-01 | Auth endpoints in `routes/api.php` are wired inline against `App\Support\Auth\AuthService` directly; this controller was an early scaffold.          |
| `src/Services/Dashboard/KpiSchema.php`                        |    57 | 2025-11-28 | KPI definitions live alongside the dashboard service that consumes them; this standalone schema file was never imported.                              |
| `src/Services/Dashboard/WarrantyDashboardService.php`         |    39 | 2025-11-30 | `WarrantyClaimService` (used by `WarrantyController`) is the live warranty surface; this dashboard variant has no caller.                            |
| `src/Services/Estimate/EstimateMailer.php`                    |    59 | 2025-11-30 | Superseded by `EstimateShareService` for email delivery + public link issuance.                                                                       |
| `src/Services/Health/HealthStatusController.php`              |    30 | 2025-11-30 | The `/health` and `/api/system/health` routes in `routes/api.php` (lines 377 / 4001) construct `HealthStatusService` inline; the controller is dead. |
| `src/Services/Payroll/PayrollCalculator.php`                  |   149 | 2026-01-16 | Replaced by the `PayrollExportService` + `PayrollExportController` flow wired in `routes/api.php:7618`.                                              |
| `src/Services/Payroll/PayrollExportFormatter.php`             |    57 | 2026-01-16 | Same — superseded by the export service's inline formatting.                                                                                          |
| `src/Services/Payroll/PayrollRunRepository.php`               |   266 | 2026-01-16 | Same — payroll persistence flows through the live export service path; this repo was never wired.                                                    |
| `src/Services/QA/TestSuiteService.php`                        |    53 | 2025-11-30 | Test runner abstraction with no caller; tests are invoked directly via `php tests/<Name>Test.php`.                                                   |
| `src/Services/ServiceType/ServiceTypeSeeder.php`              |   115 | 2025-11-29 | Seeding happens via migrations; this seeder class was never invoked from `bin/seed.php` or any cron.                                                  |
| `src/Services/ServiceType/ServiceTypeUiService.php`           |    55 | 2025-11-30 | UI shaping done in `ServiceTypeController`; this helper was never adopted.                                                                            |
| `src/Services/Settings/SettingsService.php`                   |   108 | 2025-11-30 | Settings reads/writes go through `SettingsRepository` + `SettingsController`; this service layer was an unused middle-tier draft.                    |
| `src/Services/Vehicle/LoggingVinDecoder.php`                  |   244 | 2026-01-25 | `VinDecoderFactory` chains `NhtsaVinDecoder` → `CachingVinDecoder`; the logging decorator was authored but never inserted into the chain.            |
| `src/Services/Vehicle/VehicleMasterMergeService.php`          |   136 | 2025-11-29 | Vehicle dedupe / merge surface never reached production; no caller.                                                                                  |
| `src/Services/Vehicle/VehicleMasterUiService.php`             |   114 | 2025-11-30 | Same — vehicle-master UI shaping draft, no caller.                                                                                                    |
| `src/Services/Warranty/WarrantyManagementService.php`         |   166 | 2025-11-30 | Replaced by the `WarrantyClaimService` + `WarrantyController` pair wired in `routes/api.php:6898`.                                                   |

**Total removed:** 16 files, ~1 877 lines.

## Considered but kept

| File                                          | Why kept                                                                                                                                                                                                                                                                                                                                |
| --------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `bin/migrate-financial-entries.php`           | Self-contained one-time data-migration script. Referenced in `docs/phase1-baseline.md`. v2 plan excludes "anything in `database/migrations/`"; while this file lives under `bin/` rather than `database/migrations/`, it serves the same one-shot data-migration role. Retained until a future audit confirms it has run on every prod tenant. |
| `bin/parse_partner_email.php`                 | Same reason — operational utility for email-ingestion debugging. Kept until ownership / status confirmed.                                                                                                                                                                                                                                |
| `src/Services/QA/TestSuiteService.php`        | *(initially considered keeping; removed because it has no caller and no documentation pointing at it.)*                                                                                                                                                                                                                                  |
| All `*Interface.php` files flagged as orphan  | Re-verified: each is implemented via `implements <Interface>` in at least one concrete class within the same namespace. The original heuristic that flagged them (`\\<ShortName>` FQCN match) missed bare-name implementations. Kept.                                                                                                     |
| All "private helper" methods inside live classes | A method-level dead-code sweep is out of scope for this phase. Captured as a Phase-4 follow-up if the next audit cycle wants finer granularity than file-level.                                                                                                                                                                          |

## Not surveyed in this phase

- **React components** — orphan `.jsx` files in `src/react/views/` were not
  surveyed; the v2 plan focuses Phase 4 on the PHP backend. A React stale-code
  pass should be done separately and is captured as future work.
- **Database migrations** — explicitly excluded by the v2 plan.
- **CMS templates** — explicitly require confirmation before removal; none
  were proposed for removal here.
- **Method-level dead code** — `phpcbf`/`phpstan dead code` style sweep is out
  of scope for this phase; captured as future work.

## Future work

- React stale-component sweep (Phase 4 extension).
- Method-level dead-code sweep (private methods with no internal caller).
- Confirm `bin/migrate-financial-entries.php` and `bin/parse_partner_email.php`
  status with an owner review before next audit.
