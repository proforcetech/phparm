# Vehicle Data & Management Review

**STATUS: ✅ COMPLETE**

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Missing vehicle master dataset support in public flows
- The plugin seeds and queries a dedicated `arm_vehicle_data` table for cascading year/make/model/engine/drive/trim selectors on the public estimate form via AJAX endpoints (`arm_get_vehicle_options`).【F:arm-main/arm-repair-estimates.backup†L41-L89】【F:arm-main/includes/public/class-assets.php†L50-L67】
- The standalone API exposes only basic vehicle listing/creation plus VIN helpers; it does not provide the cascaded dropdown endpoints (years/makes/models/etc.) the UI would need to mirror the plugin’s selector behavior.【F:routes/api.php†L303-L358】

### Tasks
1. [x] **Expose cascading vehicle option endpoints**
   - Add authenticated endpoints for years, makes, models, engines, transmissions, drives, and trims using `VehicleMasterController`’s cascade methods.
   - Wire the front-end estimate/request forms to consume these endpoints so users can select a vehicle without manual entry.

2. [x] **Populate vehicle master data**
   - Provide an import or seed path to load the equivalent of the plugin’s `arm_vehicle_data` into `vehicle_master` so cascaded selectors have data.
   - Ensure duplicate guardrails match the plugin’s uniqueness on year/make/model/engine/drive/trim combinations.

## Customer vehicle CRUD parity
- The plugin lets customers list, add, edit, and delete their vehicles (year/make/model/engine/trim) from the customer dashboard tab, persisting full vehicle details per profile.【F:arm-main/includes/public/Customer_Dashboard.php†L100-L147】
- The standalone `CustomerVehicleService` only links a customer to a master vehicle/VIN/plate and omits required year/make/model/engine/transmission/drive fields defined in the schema, so full vehicle profiles and customer self-service are not yet supported.【F:src/Services/Customer/CustomerVehicleService.php†L26-L75】【F:database/migrations/001_initial_schema.sql†L41-L73】

### Tasks
1. [x] **Implement full customer vehicle persistence**
   - Extend `CustomerVehicleService` (and related controllers/routes) to create, update, and delete vehicles with the required year/make/model/engine/transmission/drive/trim fields, enforcing schema constraints.
   - Backfill existing endpoints/UI to capture these fields and associate estimates/invoices with the enriched vehicle records.

2. [x] **Add customer-facing vehicle management**
   - Deliver a customer portal section similar to the plugin’s “My Vehicles” tab to view, add, edit, and delete stored vehicles.
   - Include validation and permission checks to ensure customers can manage only their own vehicles and tie selections into estimate/invoice flows.
