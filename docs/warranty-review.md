# Warranty Claims Review

**STATUS: ✅ COMPLETE**

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Missing customer self-service claim portal
- The plugin exposes a `[arm_warranty_claims]` shortcode that shows the logged-in customer's claims list, detail view, and reply form, verifying ownership by email and updating claim or message tables accordingly.【F:arm-main/includes/customer/WarrantyClaims.php†L13-L177】
- The standalone API only provides authenticated admin/staff listing and detail endpoints guarded by `warranty.view` plus a basic submission endpoint; there is no customer-facing route or controller method to view only the caller's claims or post replies.【F:src/Services/Warranty/WarrantyController.php†L21-L93】

### Tasks
1. [x] **Add customer-facing claim list and detail endpoints**
   - Provide routes that scope results to the authenticated customer (e.g., filter by user->customer_id) mirroring the plugin's "My Warranty Claims" view.
   - Return claim summaries and full detail so the front end can render list and per-claim pages.
2. [x] **Enable customer replies on open claims**
   - Add a reply endpoint that checks claim ownership and appends a customer message, updating timestamps/status as needed.
   - Expose the conversation thread per claim so customers and staff can view message history.

## Missing message thread storage
- The plugin records claim conversations in a dedicated `arm_warranty_claim_messages` table (and falls back to updating `last_message` on the claim if the table is absent).【F:arm-main/includes/customer/WarrantyClaims.php†L30-L176】
- The standalone schema only defines a single `warranty_claims` table without any message history table, so replies and staff updates cannot be persisted as threaded messages.【F:database/migrations/001_initial_schema.sql†L218-L231】

### Tasks
1. [x] **Create warranty claim messages schema**
   - Add a `warranty_claim_messages` table capturing claim_id, actor (customer/staff), message body, and timestamps with appropriate foreign keys.
   - Include migration/backfill to align existing claims with the new message storage.
2. [x] **Persist and expose conversations**
   - Extend warranty services to append messages on customer replies and staff actions, updating claim `updated_at`/status as appropriate.
   - Return ordered message threads from claim detail endpoints for both customer and staff views.
