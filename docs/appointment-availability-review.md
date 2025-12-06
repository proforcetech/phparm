# Appointment Availability Review

Reviewed the plugin’s availability management under `arm-main` against the standalone system. The following gaps require follow-up tasks.

## Hours/holiday storage and admin UI
- The plugin installs an `arm_availability` table to store weekly hours and holidays with indexes for day/date and exposes an admin submenu that clears and repopulates hours/holiday rows when saved via nonce-protected form fields.【F:arm-main/includes/appointments/Installer.php†L8-L48】【F:arm-main/includes/appointments/Admin_Availability.php†L18-L125】
- The standalone schema never creates an availability table while `AvailabilityService` still reads and writes to `availability_settings`, meaning availability data cannot persist and there is no UI to manage business hours or closures.【F:database/migrations/001_initial_schema.sql†L203-L216】【F:src/Services/Appointment/AvailabilityService.php†L51-L72】

### Tasks
1. **Add availability persistence**
   - Introduce a migration/model to store weekly hours and holidays (or mirror the plugin schema) and wire `AvailabilityService` to it instead of the non-existent `availability_settings` table.
2. **Build admin configuration screen**
   - Provide a backend + frontend screen to edit weekly hours, holidays/closed dates, and slot/buffer defaults with nonce-protected saves, replacing the placeholder defaults.

## Slot lookup API coverage
- The plugin’s `arm_get_slots` AJAX handler validates dates, blocks holidays with labels, restricts to per-day hours, and prevents duplicate bookings at the same start time when returning slot lists.【F:arm-main/includes/appointments/Ajax.php†L14-L60】
- The standalone controller exposes an `availability` endpoint that calls an unimplemented `getAvailableSlots` method, and `generateSlots` only iterates between global start/end defaults without weekday/holiday logic or input validation.【F:src/Services/Appointment/AppointmentController.php†L118-L132】【F:src/Services/Appointment/AvailabilityService.php†L23-L46】

### Tasks
1. **Implement slot search service**
   - Add `getAvailableSlots` to `AvailabilityService` (and its interface) to validate dates, factor per-day hours/holidays, and filter out conflicting appointments similar to the plugin’s slot handler.
2. **Return closure context in responses**
   - Include a reason/label when a date is closed or holiday so UIs can mirror the plugin’s feedback when no slots are available.

## Customer-facing availability access
- The plugin exposes availability checks to both authenticated and guest users via WordPress AJAX hooks to allow public booking widgets.【F:arm-main/includes/appointments/Ajax.php†L8-L60】
- The standalone controller method accepts a `User` object and relies on router/controller wiring not present in routes, leaving no public endpoint for customers to discover available slots from the portal or pre-auth flows.【F:src/Services/Appointment/AppointmentController.php†L117-L133】【F:routes/api.php†L1-L120】

### Tasks
1. **Expose public availability route**
   - Add a customer/guest-accessible route that proxies to the slot search service with appropriate throttling and captcha/nonce protections.
2. **Integrate with booking widgets**
   - Wire the new route into the customer portal and any embeddable widgets so availability is shown without requiring an authenticated staff session.
