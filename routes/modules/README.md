# Route Modules

Per-module route files loaded by `routes/api.php`. Created as part of **Phase 0.1** of the expansion (see `docs/expansion-plan.md`) to break up the ~10k-line monolithic `routes/api.php`.

## Pattern

Each file returns a callable with this signature:

```php
return function (Router $router, RouteContext $ctx): void {
    // register routes on $router, pulling shared deps from $ctx
};
```

`RouteContext` (`src/Support/Http/RouteContext.php`) exposes the dependencies previously captured via `use (...)` in the monolith: `connection`, `config`, `gate`, `rolePermissions`, `auditLogger`, `pushNotifications`, `settingsRepository`, and a `lazy()` helper that wraps factory callables in the same lazy-init pattern used inline.

## Migrating a route group out of `routes/api.php`

1. Identify a self-contained `$router->group(...)` block and the controller construction that feeds it.
2. Create `routes/modules/<name>.php` returning the `function (Router, RouteContext)` callable.
3. Port controller factories to use `$ctx->lazy(...)` and reference `$ctx->connection`, `$ctx->gate`, etc.
4. Delete the original block from `routes/api.php`.
5. Add `(require __DIR__ . '/modules/<name>.php')($router, $ctx);` to the module-loader section near the bottom of `routes/api.php`.

## Currently migrated

`routes/api.php` currently loads 47 route modules from the `$routeModule` array:

- `asset_acquisitions.php`
- `asset_decommissions.php`
- `asset_imports.php`
- `asset_leases.php`
- `assets.php`
- `branch_dashboards.php`
- `capital_plan.php`
- `chain_rollup.php`
- `change_management.php`
- `consolidated_billing.php`
- `contracts.php`
- `crm.php`
- `custom_fields.php`
- `customer_retention.php`
- `dispatch_board.php`
- `divisions.php`
- `eta.php`
- `fleet.php`
- `integrations.php`
- `modules_and_user_groups.php`
- `pm.php`
- `portal.php`
- `pos_terminals.php`
- `procurement.php`
- `property_management.php`
- `reporting.php`
- `retention.php`
- `routing.php`
- `security_credentials.php`
- `security_events.php`
- `service_lines.php`
- `service_routes.php`
- `skills.php`
- `software_inventory.php`
- `sso.php`
- `subcontractor_portal.php`
- `subcontractors.php`
- `ticket_triage.php`
- `tickets.php`
- `trade_kpis.php`
- `trusted_devices.php`
- `vendor_portal.php`
- `voice_notes.php`
- `workorder_change_orders.php`
- `workorder_kit_installs.php`
- `workorder_reassignments.php`
- `workorder_tech_requests.php`
