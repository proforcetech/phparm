# Reminder System Review

Reviewed the WordPress plugin implementation under `arm-main` against the standalone system. The following gaps were identified and should be tracked as follow-up tasks.

## Missing customer reminder preferences storage and capture
- The plugin provisions `arm_reminder_preferences` with customer linkage, contact info, timezone, channel (email/SMS/both/none), lead days, preferred hour, activation flag, and source tracking, and exposes CRUD helpers to read and upsert rows for customers or arbitrary contact points.【F:arm-main/includes/install/class-activator.php†L153-L212】【F:arm-main/includes/reminders/class-preferences.php†L12-L121】
- The standalone schema has no `reminder_preferences` table, yet the preference service attempts to `REPLACE INTO reminder_preferences` and defaults to enabling reminders when no row exists, leaving no persistence or ability to collect timezone/lead-hour settings from customers.【F:database/migrations/001_initial_schema.sql†L232-L241】【F:src/Services/Reminder/ReminderPreferenceService.php†L17-L46】

### Tasks
1. **Add reminder preferences table and model**
   - Create a migration/model mirroring the plugin columns (customer_id, email, phone, timezone, preferred_channel, lead_days, preferred_hour, is_active, source, timestamps) with uniqueness on customer/email and active/channel indexes.
2. **Expose preference capture/update flows**
   - Add API endpoints and frontend/customer-portal forms to upsert reminder preferences (channel toggles, lead days, hour, timezone, contact info) and honor opt-out states instead of defaulting to enabled when missing.

## Campaign authoring fields and delivery logging
- The plugin’s admin UI allows creating/updating reminder campaigns with description, status (draft/active/paused/archived), channel tri-state (email/SMS/both), frequency unit + interval, next/last run timestamps, and templated email/SMS bodies, persisting all fields to `arm_reminder_campaigns` and rendering logs per campaign.【F:arm-main/includes/admin/Reminders.php†L66-L214】
- The standalone `reminder_campaigns` table only tracks name, single-channel enum (`mail` or `sms`), a single `frequency` string, status, optional service_type_filter, and run timestamps, omitting description, frequency interval/unit, channel “both”, and any message templates; there is also no reminder logs table to audit generated messages.【F:database/migrations/001_initial_schema.sql†L232-L241】

### Tasks
1. **Expand campaign schema and service**
   - Add description, frequency_unit + frequency_interval, channel values including "both", templated email_subject/body and sms_body, and created/updated timestamps to `reminder_campaigns`; update `ReminderCampaignService` validation and CRUD accordingly.
2. **Add reminder logs persistence and APIs**
   - Introduce a `reminder_logs` table (campaign_id, preference_id/customer_id, channel, status, scheduled_for/sent_at, body, error) plus admin endpoints to list per-campaign logs similar to the plugin UI.

## Scheduling, targeting, and content delivery gaps
- The plugin scheduler resolves channels per recipient (campaign vs preference), skips already queued runs, builds templated messages with customer/site merge tags, and advances campaigns using unit + interval logic, writing each attempt to `arm_reminder_logs`.【F:arm-main/includes/reminders/class-scheduler.php†L19-L120】
- The standalone scheduler only checks for active campaigns whose `next_run_at` has passed, filters recipients by a service type or basic opt-in flags, and dispatches via `mail` or `sms` without per-recipient channel negotiation, merge-tag templating, or delivery logging; it also computes next run from a single frequency value rather than unit + interval combinations.【F:src/Services/Reminder/ReminderScheduler.php†L36-L134】

### Tasks
1. **Enhance scheduling logic and templating**
   - Align scheduler to honor both campaign and preference channel combinations (including "both"), apply lead-day/hour/timezone offsets, render message bodies using saved templates with merge tags, and avoid duplicate queued runs.
2. **Record delivery attempts and failures**
   - When dispatching reminders, create log rows for queued/pending/sent/failed/skipped states, expose admin views for recent runs, and advance campaigns using the unit + interval cadence stored on each campaign.
