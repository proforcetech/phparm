# Preset Bundles Review

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Missing admin UI and CRUD for bundles
- The plugin exposes a WP-Admin submenu with a full create/edit form, bundle item repeater, sort order, active toggle, and list of existing bundles.【F:arm-main/includes/bundles/Controller.php†L13-L197】
- The standalone app only ships a `BundleService` with create/update helpers and no controllers, routes, or frontend to manage bundles or their items.【F:src/Services/Estimate/BundleService.php†L24-L188】

### Tasks
1. [x] **Add admin bundle management UI**
   - Built `/bundles` list plus create/edit pages with active toggle, service type selection, sort ordering, and item repeater.
2. [x] **Expose bundle CRUD APIs**
   - Added bundle CRUD and detail endpoints wired to AccessGate permissions for listing, creating, updating, and deleting bundles and their items.

## Ajax retrieval and estimate builder integration gaps
- The plugin provides an authenticated AJAX endpoint to fetch bundle items for insertion into estimates, returning typed, ordered rows for the selected bundle.【F:arm-main/includes/bundles/Controller.php†L63-L82】
- The standalone system lacks an endpoint to pull bundle items into the estimate builder, only offering a service method that returns a structure for internal use without HTTP exposure.【F:src/Services/Estimate/BundleService.php†L109-L134】

### Tasks
1. [x] **Create bundle-item fetch endpoint for estimates**
   - Added `/api/estimates/bundles/{id}/items` to return typed, ordered bundle items for estimate builders.
2. [x] **Wire estimate UI to bundle endpoint**
   - Bundle list/form pages call the new endpoints; estimate builders can insert returned payloads for one-click job creation.

## Schema and field parity gaps
- Plugin tables track `is_active` and `sort_order` for bundles plus `sort_order` on bundle items, while constraining item types to LABOR/PART/FEE/DISCOUNT.【F:arm-main/includes/bundles/Controller.php†L31-L127】
- The standalone models store only name, description, service type, default job title, and item quantities/prices, with no active flag, sort order, or type constraints aligned to the plugin enums.【F:src/Models/Bundle.php†L6-L11】【F:src/Models/BundleItem.php†L6-L12】

### Tasks
1. [x] **Add active/sort fields and type validation**
   - Added `is_active` and `sort_order` columns plus enum validation for LABOR/PART/FEE/DISCOUNT items.
2. [x] **Provide migrations/backfills**
   - Created a migration to backfill defaults and sort orders for existing bundles and items.
