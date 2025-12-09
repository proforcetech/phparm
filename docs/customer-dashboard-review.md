# Customer Dashboard Review

**STATUS: ✅ COMPLETE**

Reviewed the WordPress plugin customer portal (`arm_main/includes/public/Customer_Dashboard.php`) against the standalone customer portal views. The following gaps were identified and should be tracked as follow-up tasks.

## Customer portal shell, access, and assets
- The plugin guards access to authenticated customers, registers the `[arm_customer_dashboard]` shortcode, and conditionally enqueues CSS/JS plus AJAX localization for vehicle CRUD actions.【F:arm-main/includes/public/Customer_Dashboard.php†L12-L150】
- The standalone portal views are static Vue pages without route guards, login enforcement, or any wiring to load customer context or localized AJAX endpoints.【F:src/views/customer-portal/Dashboard.vue†L1-L54】

### Tasks
1. **Protect and bootstrap the customer portal**
   - [x] Add auth/role guards for portal routes and hydrate the customer context on load.
   - [x] Ensure portal assets/localized config expose the API base, auth token, and nonce-equivalent for customer actions.

## Portal tabs and data parity
- The plugin renders vehicle lists with add/edit/delete controls, estimate and invoice lists with deep links, and embeds credit account balances/transactions from dedicated queries and templates.【F:arm-main/includes/public/Customer_Dashboard.php†L80-L244】
- Standalone portal tabs (dashboard cards, vehicles, invoices, profile) only show empty states or static forms with no data fetching, actions, or navigation to estimates/credit history.【F:src/views/customer-portal/Dashboard.vue†L8-L48】【F:src/views/customer-portal/Vehicles.vue†L1-L22】【F:src/views/customer-portal/Invoices.vue†L1-L22】

### Tasks
2. **Implement data-backed portal tabs**
   - [x] Connect vehicles, estimates, invoices, and credit account views to their API endpoints with pagination, links to public estimate/invoice PDFs, and credit transaction summaries.
   - [x] Add vehicle CRUD modals and optimistic updates mirroring the plugin’s AJAX flows and validations (make/model required, soft delete behavior).

## Profile and reminder preferences
- The plugin lets customers update display name, email, phone, and reminder preferences (channel, lead days, hour, timezone), persisting via `Preferences::upsert` and handling nonce validation and redirects.【F:arm-main/includes/public/Customer_Dashboard.php†L247-L390】
- The standalone profile screen renders static inputs and buttons without binding to customer data or saving reminder preferences/email/SMS settings.【F:src/views/customer-portal/Profile.vue†L1-L45】

### Tasks
3. [x] **Wire profile and reminders management**
   - Load customer profile/reminder preferences into the form and persist updates through customer/reminder APIs with success/error handling.
   - Include timezone/hour selection and preference toggles comparable to the plugin, and surface validation/redirect feedback.
