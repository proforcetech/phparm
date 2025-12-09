# Inventory Management Review

**STATUS: ✅ COMPLETE**

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Missing admin list and CRUD parity
- The plugin ships a complete admin list with search, pagination, add/edit forms, and delete handling for inventory rows.【F:arm-main/includes/admin/Inventory.php†L30-L205】
- The standalone frontend is a placeholder card that does not render inventory data, filters, or CRUD actions despite the API scaffolding in place.【F:src/views/inventory/InventoryList.vue†L1-L31】

### Tasks
1. [x] **Implement full inventory list UI**
   - Build table, search, pagination, and action buttons in `src/views/inventory/InventoryList.vue` wired to the inventory API and gate checks.
   - Add create/edit forms (new view + route) that cover core fields and validations exposed by `InventoryItemRepository`.

## Low-stock alert experience
- The plugin exposes a dedicated "Inventory Alerts" admin page that lists items at/below threshold with direct edit links.【F:arm-main/includes/admin/InventoryAlerts.php†L7-L96】
- The standalone system only surfaces low-stock counts in dashboard KPIs and lacks an alerts page or navigation entry for actionable triage.【F:src/Services/Dashboard/DashboardService.php†L116-L125】【F:src/components/layout/Sidebar.vue†L79-L79】

### Tasks
2. [x] **Add low-stock alerts view and navigation**
   - Create an alerts page with list view (severity, quantity, threshold, quick edit navigation) and link it from the sidebar/dashboards.
   - Reuse the repository `lowStockAlerts` helper for data loading and align permissions with inventory read access.

## Inventory field coverage
- Plugin schema includes reorder quantity, vendor, and notes fields beyond the base quantity/threshold and pricing columns.【F:arm-main/includes/install/class-activator.php†L130-L149】
- The standalone repository persists name, SKU, category, quantities, thresholds, pricing, location, markup, and notes but omits vendor and reorder quantity support.【F:src/Services/Inventory/InventoryItemRepository.php†L111-L170】

### Tasks
3. [x] **Align stored fields and forms**
   - Extend inventory models, validation, and persistence to support vendor metadata and reorder quantity, and expose them in create/edit UI and CSV import/export flows.
   - Ensure dashboard metrics and alerts reflect reorder_quantity when computing restock recommendations.
