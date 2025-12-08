# Appointment System Review

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Availability setup and holiday closures
- The plugin provisions an `arm_availability` table to store weekly business hours and holiday closures and exposes an admin UI to manage those rows, clearing and repopulating hours/holidays on save.【F:arm-main/includes/appointments/Installer.php†L8-L48】【F:arm-main/includes/appointments/Admin_Availability.php†L11-L125】
- The standalone code reads and writes availability settings via an `availability_settings` table, but no migration creates that table and there is no UI to define hours or closed dates, leaving slot generation dependent on hardcoded defaults only.【F:src/Services/Appointment/AvailabilityService.php†L23-L85】【F:database/migrations/001_initial_schema.sql†L203-L216】

### Tasks
1. [x] **Add availability settings schema**
   - Introduce a migration/model for `availability_settings` (or port the plugin’s hours/holiday schema) so AvailabilityService has persisted hours/holidays, including indexes by day/date.
2. [x] **Expose admin configuration UI**
   - Build a backend+frontend screen to manage weekly hours, holidays/closed dates, and slot/buffer lengths, persisting to the new availability table and replacing defaults.

## Slot calculation and blackout handling
- The plugin’s slot AJAX endpoint rejects invalid dates, blocks out holiday entries, filters by per-day hours, and prevents double-booking at the same start time while returning an explicit holiday label when closed.【F:arm-main/includes/appointments/Ajax.php†L14-L60】
- The standalone slot generation simply iterates between configured start/end times with an optional buffer and only checks for overlapping appointments, ignoring holiday closures, per-day schedules, and date validation so customers may book outside business rules.【F:src/Services/Appointment/AvailabilityService.php†L23-L85】

### Tasks
1. [x] **Align slot generation with schedules**
   - Incorporate weekday-specific hours, holiday blackouts, and input validation into the slot generator and API, matching the plugin’s refusal of closed days and duplicate start times.
2. [x] **Return user-facing closure context**
   - When a date is blocked (holiday or no hours), include a reason/label in the response for UI display.

## Appointment lifecycle data and webhooks
- The plugin records appointment `created_at`/`updated_at` timestamps and optional notes, triggers lifecycle hooks when appointments are booked/updated/canceled, and relays those events to Make (Integromat) with customer and schedule payloads.【F:arm-main/includes/appointments/Controller.php†L18-L79】【F:arm-main/includes/integrations/Appointments_Make.php†L13-L83】
- The standalone appointments table omits audit timestamps and requires a vehicle link while the service only logs locally, providing no outbound webhooks or customer-facing lifecycle notifications.【F:database/migrations/001_initial_schema.sql†L203-L216】【F:src/Services/Appointment/AppointmentService.php†L19-L90】

### Tasks
1. [x] **Enrich appointment schema and history**
   - Add created/updated timestamps, notes, and nullable customer/vehicle references where applicable to appointments, with audit logging of status transitions.
2. [x] **Emit lifecycle events/webhooks**
   - Publish appointment booked/updated/canceled events (or webhooks) carrying schedule and customer context to support external calendar workflows similar to the plugin’s Make integration.
