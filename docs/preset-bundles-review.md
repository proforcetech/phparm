# Preset Bundles Review

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Missing admin UI and CRUD for bundles
- The plugin exposes a WP-Admin submenu with a full create/edit form, bundle item repeater, sort order, active toggle, and list of existing bundles.【F:arm-main/includes/bundles/Controller.php†L13-L197】
- The standalone app only ships a `BundleService` with create/update helpers and no controllers, routes, or frontend to manage bundles or their items.【F:src/Services/Estimate/BundleService.php†L24-L188】

### Tasks
1. **Add admin bundle management UI**
   - Build pages to create/edit bundles with name, optional service type, active toggle, sort order, and item repeater, plus an index list mirroring the plugin UX.
2. **Expose bundle CRUD APIs**
   - Implement REST routes backing the UI for creating, updating, listing, and deleting bundles and their items.

## Ajax retrieval and estimate builder integration gaps
- The plugin provides an authenticated AJAX endpoint to fetch bundle items for insertion into estimates, returning typed, ordered rows for the selected bundle.【F:arm-main/includes/bundles/Controller.php†L63-L82】
- The standalone system lacks an endpoint to pull bundle items into the estimate builder, only offering a service method that returns a structure for internal use without HTTP exposure.【F:src/Services/Estimate/BundleService.php†L109-L134】

### Tasks
1. **Create bundle-item fetch endpoint for estimates**
   - Add a secured route to return bundle item payloads (type, description, quantity, price, taxable, order) for estimate builders.
2. **Wire estimate UI to bundle endpoint**
   - Update the estimate creation flow to request and insert bundle items via the new API, matching the one-click insertion behavior.

## Schema and field parity gaps
- Plugin tables track `is_active` and `sort_order` for bundles plus `sort_order` on bundle items, while constraining item types to LABOR/PART/FEE/DISCOUNT.【F:arm-main/includes/bundles/Controller.php†L31-L127】
- The standalone models store only name, description, service type, default job title, and item quantities/prices, with no active flag, sort order, or type constraints aligned to the plugin enums.【F:src/Models/Bundle.php†L6-L11】【F:src/Models/BundleItem.php†L6-L12】

### Tasks
1. **Add active/sort fields and type validation**
   - Extend bundle and bundle item schemas to include `is_active` and `sort_order` fields and enforce allowed item types consistent with the plugin.
2. **Provide migrations/backfills**
   - Migrate existing bundle data to populate new fields with sensible defaults and preserve item ordering.
