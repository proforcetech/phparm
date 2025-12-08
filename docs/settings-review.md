# Settings Functionality Review

Compared the WordPress plugin implementation under `arm-main` with the standalone system. The following settings gaps were identified and should be tracked as follow-up tasks.

## Missing general shop configuration coverage
- The plugin captures notification email, HTML terms, default tax and labor rates, and full shop identity (name, address, phone, email, logo) as first-class settings fields.【F:arm-main/includes/admin/Settings.php†L11-L108】
- The standalone seed data only provides a shop name, currency, a single tax rate, and a sender email—omitting terms content, labor pricing, and storefront contact/branding details needed by estimates, invoices, and customer views.【F:src/Database/Seeders/DatabaseSeeder.php†L50-L72】

### Tasks
1. [x] **Add missing shop/terms settings**
   - Introduce settings for terms/conditions (HTML), notification email, labor rate, and shop contact details (address, phone, email, logo) in the settings repository and migrations.
   - Expose these settings through the API and seed sensible defaults so estimates/invoices can render correct business metadata.

## Missing payment and integration credential management
- The plugin surfaces Stripe, PayPal, Zoho CRM, and PartsTech credentials (including webhook endpoints and success/cancel URLs) directly in the settings UI for administrators to manage.【F:arm-main/includes/admin/Settings.php†L111-L220】
- The standalone system lacks provider-specific credential fields or UI hooks; frontend architecture still lists “Settings pages” as undone, leaving no way to configure payment gateways or catalog integrations.【F:docs/FRONTEND_ARCHITECTURE.md†L328-L332】

### Tasks
2. [x] **Implement payment/integration settings screens**
   - Add settings definitions and secure storage for Stripe/PayPal/Zoho/PartsTech keys, webhook secrets, and redirect URLs, with masked audit logging for sensitive fields.
   - Provide API endpoints and frontend forms to edit these credentials and validate values (e.g., known providers, URL formats) before saving.

## Missing availability and slot configuration
- The plugin lets administrators define appointment slot length/buffer and manage working hours/holidays via settings, persisting data into an availability table for scheduling logic.【F:arm-main/includes/admin/Settings.php†L24-L75】
- The standalone settings seeding and services do not capture slot length, buffer times, or closed dates, leaving appointment scheduling without configurable working calendars.

### Tasks
3. [x] **Add scheduling settings and storage**
   - Create schema/storage for slot duration, buffers, working hours, and holidays, and expose CRUD endpoints plus UI to manage them.
   - Ensure appointment booking, availability checks, and reminders consume these settings when calculating open times.
