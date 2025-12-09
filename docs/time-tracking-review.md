# Time Tracking & Technician Dashboard Review

**STATUS: ✅ COMPLETE**

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Missing technician-facing portal UI
- The plugin exposes a dedicated "My Time" admin page/shortcode that lists assigned jobs, shows summary metrics, and wires up start/stop REST calls for technicians with localized notices and active-timer state handling.【F:arm-main/includes/timelogs/Technician_Page.php†L10-L199】
- The standalone app defines API routes and backend services for time tracking, but there is no Vue view or customer/technician route that renders an equivalent dashboard or start/stop controls for technicians.【F:routes/api.php†L841-L890】

### Tasks
1. [x] **Build technician time portal in the frontend**
   - Add a Vue page/component that surfaces assigned jobs, current timer state, summary totals, and start/stop actions mirroring the plugin UI.
   - Integrate with the existing `/api/time-tracking` endpoints using the technician's session/auth context.
2. [x] **Expose portal entry point**
   - Add a navigation item/route for technicians (and shortcode-equivalent embedding if needed) so they can reach the time portal without admin access.

## Missing adjustment audit trail
- The plugin maintains `arm_time_adjustments` to capture admin edits with before/after timestamps, durations, and reasons tied to each entry.【F:arm-main/includes/timelogs/Controller.php†L20-L70】
- The standalone schema only defines a `time_entries` table with basic start/end coordinates and no adjustment history or admin actor linkage, so edits are not auditable.【F:database/migrations/001_initial_schema.sql†L264-L277】

### Tasks
1. [x] **Add time adjustment table and model**
   - Create a migration/model/service to store adjustment events (prior values, new values, actor, reason) linked to `time_entries`.
   - Emit audit events when admins update or override entries.
2. [x] **Expose adjustment history in APIs**
   - Return adjustment records from admin time-tracking detail responses and allow optional reason input when updating entries.

## Missing rich location capture and normalization
- The plugin normalizes and stores geolocation metadata (accuracy, altitude, speed, recorded_at, source, errors) alongside start and stop payloads for each time entry.【F:arm-main/includes/timelogs/Controller.php†L86-L126】【F:arm-main/includes/timelogs/Rest.php†L60-L108】
- The standalone implementation records only start/end latitude/longitude columns with no accuracy/timestamp metadata, losing context for compliance or verification uses.【F:database/migrations/001_initial_schema.sql†L264-L277】【F:src/Services/TimeTracking/TimeTrackingService.php†L20-L83】

### Tasks
1. [x] **Expand schema for location metadata**
   - Extend `time_entries` to store normalized geolocation details for start and stop events (accuracy, heading, speed, recorded_at, source/error notes) similar to the plugin payload.
2. [x] **Normalize and persist incoming location data**
   - Update start/stop endpoints to accept structured location objects, normalize them, and store both raw coordinates and metadata; ensure responses expose the recorded location data for frontend display.
