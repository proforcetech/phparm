# Inventory Notifications/Alerts Review

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Dedicated low-stock alerts UI and navigation
- The plugin registers an "Inventory Alerts" admin submenu and renders a dedicated table of low-stock items with back navigation to inventory management for quick edits.【F:arm-main/includes/admin/InventoryAlerts.php†L13-L96】【F:arm-main/includes/admin/Inventory.php†L90-L118】
- The standalone frontend has no route or view for low-stock alerts; only high-level KPI counts are exposed via the dashboard service response.

### Tasks
1. **Add alerts page and menu entry**
   - [x] Create a low-stock alerts view with filtering, pagination, and edit navigation, and link it from the sidebar/dashboard tiles.
   - [x] Implement an API route/controller handler for low-stock listings using `InventoryItemController::lowStock` and gate checks.

## Dashboard surfacing and deep links
- The plugin adds a WordPress dashboard widget that shows the low-stock count and links directly to the alerts page for action.【F:arm-main/includes/admin/DashboardWidget.php†L7-L60】
- The standalone dashboard service only returns aggregated counts without providing item previews or links, and the frontend does not render a widget/tile for low-stock navigation.【F:src/Services/Dashboard/DashboardService.php†L116-L126】

### Tasks
2. **Expose actionable dashboard tile**
   - [x] Render a dashboard widget/tile that shows low/out-of-stock counts with a CTA to the alerts page and optional preview of top items.
   - [x] Reuse `InventoryLowStockService::tile` data to keep counts consistent across widgets and alerts pages.

## Notification dispatch and templates
- The plugin relies on the admin UI for alerts but does not send proactive emails; the standalone system includes `InventoryLowStockService::sendEmailAlert` yet it is not wired to any scheduler, notification dispatcher, or mail template configuration.【F:src/Services/Inventory/InventoryLowStockService.php†L13-L69】
- There is no trigger in cron/queue to call the email dispatcher, leaving inventory notifications inert.

### Tasks
3. **Schedule and template email alerts**
   - [x] Configure a scheduled job or queue worker to invoke `sendEmailAlert` with tenant-specific recipients and subject lines.
   - [x] Add notification template entries and translations for `inventory.low_stock_alert`, ensuring payload matches email markup expectations.

   A cron-friendly runner now exists at `bin/cron/inventory-low-stock.php`; it loads notification settings from `.env` or the
   `notifications.inventory.*` keys to deliver the low-stock summary via `InventoryLowStockService::sendEmailAlert`.
