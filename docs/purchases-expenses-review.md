# Purchases, Expenses, and Accounting Review

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Missing admin UI flows and CSV exports for expenses/purchases
- The plugin exposes WP-Admin screens that handle create/edit/delete of purchases and expenses with vendor, category, reference, date, amount, and description fields plus CSV export support and filtered queries by date and category.【F:arm-main/includes/admin/Purchases.php†L12-L104】【F:arm-main/includes/admin/Expenses.php†L12-L103】
- The standalone app only provides a backend `FinancialEntryService` to record generic entries without any admin UI, CSV export endpoints, or page-level filters; it also lacks delete/edit flows beyond receipt attachment.【F:src/Services/Financial/FinancialEntryService.php†L23-L69】

### Tasks
1. [x] **Implement admin purchases/expenses pages**
   - Add UI surfaces for listing, filtering by date/category, exporting CSV, and CRUD actions that mirror the plugin admin pages.
2. [x] **Add API endpoints for CSV export and filtering**
   - Provide REST routes to export filtered purchases/expenses CSVs and to list entries with date/category filters for the new admin UI.
3. [x] **Support delete/edit flows with audit logging**
   - Extend the financial entry APIs to handle updates and deletions with audit trails so UI actions map to backend capabilities.

Completed with paginated listings, vendor/category/date filters, and CSV exports for both entries and monthly reports.

## Missing domain fields and validation parity
- Plugin forms persist vendor names, purchase orders/references, categories, and transaction dates for purchases/expenses, enforcing presence before saving.【F:arm-main/includes/admin/Purchases.php†L25-L67】【F:arm-main/includes/admin/Expenses.php†L25-L67】
- The standalone `record` method only requires `amount` and `date`, storing everything else as optional metadata and not distinguishing vendor/category/reference/purchase_order fields, reducing reporting fidelity.【F:src/Services/Financial/FinancialEntryService.php†L23-L69】

### Tasks
1. [x] **Expand schema and payload validation**
   - Add first-class columns/fields for vendor, category, reference, purchase order, and description with validation rules matching the plugin forms.
2. [x] **Migrate existing entries**
   - Backfill or migrate current `financial_entries` records so newly required fields are populated where possible (e.g., from metadata or receipts).

## Financial reporting parity gaps
- The plugin offers a financial reports screen that summarizes income, expenses, and purchases with date filters, monthly breakdowns, and CSV export of the monthly table.【F:arm-main/includes/admin/FinancialReports.php†L12-L61】
- The standalone reporting service can compute summaries and monthly breakdowns over a date range but lacks category filters, UI exposure, or CSV export endpoints for the same data.【F:src/Services/Financial/FinancialReportService.php†L20-L62】

### Tasks
1. [x] **Expose financial reports UI and API**
   - Build a reporting endpoint and admin page that surfaces total and monthly breakdowns for income/expenses/purchases with date filters.
2. [x] **Add CSV export for reports**
   - Implement CSV export for the summarized monthly data to match plugin capabilities.
3. [x] **Extend filtering options**
   - Include category (and potentially vendor) filters in reporting queries to align with plugin expectations.
