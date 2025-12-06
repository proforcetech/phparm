# Preset Bundles Review

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Missing admin UI and routing for bundle CRUD
- The plugin provides a WP-Admin submenu that renders a full CRUD form for preset bundles (name, service type, active toggle, sort order) plus an items table with type/description/qty/unit price/tax columns, saving via POST handlers in the same controller.【F:arm-main/includes/bundles/Controller.php†L11-L195】
- The standalone app only offers a backend `BundleService` with no HTTP routes, controllers, or Vue pages to create/edit/list bundles or their items, leaving preset bundles inaccessible to admins.【F:src/Services/Estimate/BundleService.php†L24-L188】

### Tasks
1. **Add admin bundle pages and routes**
   - Create backend controllers/API endpoints and a frontend admin page to list, create, edit, activate/deactivate, and sort bundles with their items, mirroring the plugin fields and flows.
2. **Wire bundle selection into estimate builder UI**
   - Provide UI affordances to pick a preset bundle and insert its items into an estimate job, calling new endpoints instead of WP AJAX.

## Schema and field parity gaps
- Plugin tables include `is_active`, `sort_order`, and item-level `sort_order` columns to control visibility and ordering; the standalone schema lacks these fields, storing only core bundle/item properties without activation or ordering metadata.【F:arm-main/includes/bundles/Controller.php†L31-L60】【F:database/migrations/001_initial_schema.sql†L243-L262】
- Plugin bundle items store a constrained `item_type` enum across LABOR/PART/FEE/DISCOUNT while the standalone service accepts any `type` string and does not enforce allowable values or item ordering on retrieval.【F:arm-main/includes/bundles/Controller.php†L116-L126】【F:arm-main/includes/bundles/Ajax.php†L6-L14】【F:src/Services/Estimate/BundleService.php†L136-L170】

### Tasks
1. **Add activation and sort columns**
   - Introduce `is_active` and `sort_order` columns to bundles and bundle_items (plus migrations) and ensure queries respect them (e.g., list active bundles ordered by `sort_order`, then name).
2. **Constrain item types and ordering**
   - Validate bundle item types against the supported set (LABOR/PART/FEE/DISCOUNT) and persist/retrieve items using explicit `sort_order` to preserve plugin ordering semantics.

## Estimate builder data retrieval
- Plugin exposes an authenticated AJAX endpoint to fetch ordered bundle items for insertion into estimates, returning item type/description/qty/unit price/taxable flags with nonce validation.【F:arm-main/includes/bundles/Controller.php†L64-L82】【F:arm-main/includes/bundles/Ajax.php†L6-L14】
- The standalone system lacks any HTTP endpoint to retrieve bundle items; `BundleService::buildEstimateJobFromBundle` assembles data from the database but is never exposed via routes or guarded by permissions/nonce checks.【F:src/Services/Estimate/BundleService.php†L91-L134】

### Tasks
1. **Expose secure bundle-item fetch endpoint**
   - Add an authenticated route for fetching bundle items (including taxability and ordering) for estimate builders, with proper permission checks.
2. **Integrate with estimate creation flows**
   - Use the new endpoint in estimate creation/editing APIs/UI so selecting a bundle loads its items consistently with plugin behavior.
